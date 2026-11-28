<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActorType;
use App\Enums\AttemptStatus;
use App\Enums\AuditEvent;
use App\Enums\ExamStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForceUnpublishExamRequest;
use App\Http\Requests\Examiner\ListExamsRequest;
use App\Http\Resources\ExamResource;
use App\Http\Support\ApiResponse;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Grading\ExamGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdminExamController extends Controller
{
    public function index(ListExamsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Exam::class);

        $status = $request->validated('status');
        $examinerId = $request->validated('examiner_id');
        $search = trim((string) $request->validated('search', ''));

        $query = Exam::query()
            ->with(['examiner:id,username,name', 'dataset:id,name,validated_at'])
            ->withCount(['questions', 'attempts']);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if ($examinerId !== null) {
            $query->where('examiner_id', (int) $examinerId);
        }

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where('title', 'ilike', "%{$escaped}%");
        }

        $exams = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(ExamResource::collection($exams));
    }

    public function show(Exam $exam): JsonResponse
    {
        Gate::authorize('view', $exam);

        $exam->load([
            'examiner:id,username,name',
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function forceUnpublish(
        ForceUnpublishExamRequest $request,
        Exam $exam,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('forceUnpublish', $exam);

        if ($exam->status !== ExamStatus::Published) {
            throw ApiException::conflict('Only published exams can be force-unpublished.', 'exam_not_published');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = (string) $request->validated('reason');
        $forceClose = $request->boolean('force_close_active', true);

        DB::transaction(function () use ($request, $admin, $exam, $reason, $forceClose, $gradingService, $auditLogger): void {
            $exam->forceFill([
                'status' => ExamStatus::Unpublished,
                'unpublished_at' => now(),
            ])->save();

            $closedCount = 0;

            if ($forceClose) {
                $activeAttempts = ExamAttempt::query()
                    ->whereHas('classroomExam', fn ($q) => $q->where('exam_id', $exam->id))
                    ->where('status', AttemptStatus::InProgress)
                    ->lockForUpdate()
                    ->get();

                foreach ($activeAttempts as $attempt) {
                    $attempt->forceFill([
                        'status' => AttemptStatus::ForceClosed,
                        'submitted_at' => now(),
                    ])->save();

                    $gradingService->gradeAttempt($attempt);
                    $closedCount++;

                    $auditLogger->log(
                        eventType: AuditEvent::StudentExamForceClosed,
                        actorType: ActorType::Admin,
                        actorId: (string) $admin->id,
                        actorLabel: $admin->username,
                        targetType: 'exam_attempt',
                        targetId: $attempt->id,
                        metadata: ['exam_id' => $exam->id, 'reason' => $reason],
                        request: $request,
                    );
                }
            }

            $auditLogger->log(
                eventType: AuditEvent::ExamForceUnpublished,
                actorType: ActorType::Admin,
                actorId: (string) $admin->id,
                actorLabel: $admin->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: [
                    'reason' => $reason,
                    'force_close_active' => $forceClose,
                    'force_closed_count' => $closedCount,
                ],
                request: $request,
            );
        });

        $exam->refresh()->load(['examiner:id,username,name', 'dataset'])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }
}
