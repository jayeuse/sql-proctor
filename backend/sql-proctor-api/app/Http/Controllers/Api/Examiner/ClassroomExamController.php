<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Enums\ExamStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\AssignClassroomExamRequest;
use App\Http\Requests\Examiner\UpdateClassroomExamRequest;
use App\Http\Resources\ClassroomExamResource;
use App\Http\Support\ApiResponse;
use App\Models\Classroom;
use App\Models\ClassroomExam;
use App\Models\Exam;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClassroomExamController extends Controller
{
    public function index(Exam $exam): JsonResponse
    {
        Gate::authorize('view', $exam);

        $assignments = $exam->classroomExams()
            ->with(['classroom', 'examVersion'])
            ->withCount('attempts')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::collection(ClassroomExamResource::collection($assignments));
    }

    public function store(AssignClassroomExamRequest $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        $classroom = Classroom::findOrFail($request->validated('classroom_id'));
        Gate::authorize('update', $classroom);

        if ($exam->status !== ExamStatus::Published) {
            throw ValidationException::withMessages([
                'exam' => ['Only published exams can be assigned to classrooms.'],
            ]);
        }

        $latestVersion = $exam->latestVersion();
        if ($latestVersion === null) {
            throw ValidationException::withMessages([
                'exam' => ['Exam has no published version snapshot.'],
            ]);
        }

        if (ClassroomExam::query()->where('classroom_id', $classroom->id)->where('exam_id', $exam->id)->exists()) {
            throw ApiException::conflict('This exam is already assigned to that classroom.', 'classroom_already_assigned');
        }

        /** @var User $examiner */
        $examiner = $request->user();
        $validated = $request->validated();

        $assignment = DB::transaction(function () use ($request, $classroom, $exam, $latestVersion, $examiner, $validated, $auditLogger): ClassroomExam {
            $assignment = ClassroomExam::create([
                'classroom_id' => $classroom->id,
                'exam_id' => $exam->id,
                'exam_version_id' => $latestVersion->id,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'available_from' => $validated['available_from'] ?? null,
                'available_until' => $validated['available_until'] ?? null,
                'assigned_by' => $examiner->id,
            ]);

            $auditLogger->log(
                eventType: AuditEvent::ExamClassroomAssigned,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom_exam',
                targetId: $assignment->id,
                metadata: [
                    'exam_id' => $exam->id,
                    'classroom_id' => $classroom->id,
                    'exam_version_id' => $latestVersion->id,
                ],
                request: $request,
            );

            return $assignment;
        });

        $assignment->load(['classroom', 'examVersion'])->loadCount('attempts');

        return ApiResponse::resource(new ClassroomExamResource($assignment), 201);
    }

    public function update(
        UpdateClassroomExamRequest $request,
        Exam $exam,
        Classroom $classroom,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $exam);
        Gate::authorize('update', $classroom);

        $assignment = ClassroomExam::query()
            ->where('exam_id', $exam->id)
            ->where('classroom_id', $classroom->id)
            ->firstOrFail();

        $validated = $request->validated();
        $from = array_key_exists('available_from', $validated) ? $validated['available_from'] : $assignment->available_from;
        $until = array_key_exists('available_until', $validated) ? $validated['available_until'] : $assignment->available_until;

        if ($from !== null && $until !== null && strtotime((string) $until) <= strtotime((string) $from)) {
            throw ValidationException::withMessages([
                'available_until' => ['available_until must be after available_from.'],
            ]);
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $assignment, $exam, $classroom, $examiner, $validated, $auditLogger): void {
            $updates = [];
            foreach (['is_active', 'available_from', 'available_until'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            $releasedNow = false;
            if (($validated['release_results'] ?? false) === true && $assignment->results_released_at === null) {
                $updates['results_released_at'] = now();
                $releasedNow = true;
            }

            if ($updates !== []) {
                $assignment->update($updates);
            }

            if ($releasedNow) {
                $auditLogger->log(
                    eventType: AuditEvent::ExamResultsReleased,
                    actorType: ActorType::Examiner,
                    actorId: (string) $examiner->id,
                    actorLabel: $examiner->username,
                    targetType: 'classroom_exam',
                    targetId: $assignment->id,
                    metadata: ['exam_id' => $exam->id, 'classroom_id' => $classroom->id],
                    request: $request,
                );
            } elseif ($updates !== []) {
                $auditLogger->log(
                    eventType: AuditEvent::ExamClassroomUpdated,
                    actorType: ActorType::Examiner,
                    actorId: (string) $examiner->id,
                    actorLabel: $examiner->username,
                    targetType: 'classroom_exam',
                    targetId: $assignment->id,
                    metadata: ['fields' => array_keys($updates)],
                    request: $request,
                );
            }
        });

        $assignment->refresh()->load(['classroom', 'examVersion'])->loadCount('attempts');

        return ApiResponse::resource(new ClassroomExamResource($assignment));
    }

    public function destroy(
        Request $request,
        Exam $exam,
        Classroom $classroom,
        AuditLogger $auditLogger,
    ): Response {
        Gate::authorize('update', $exam);
        Gate::authorize('update', $classroom);

        $assignment = ClassroomExam::query()
            ->where('exam_id', $exam->id)
            ->where('classroom_id', $classroom->id)
            ->firstOrFail();

        if ($assignment->attempts()->exists()) {
            throw ApiException::conflict(
                'Cannot remove a classroom assignment that has existing attempts. Deactivate it instead.',
                'assignment_has_attempts',
            );
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $assignment, $exam, $classroom, $examiner, $auditLogger): void {
            $assignmentId = $assignment->id;
            $assignment->delete();

            $auditLogger->log(
                eventType: AuditEvent::ExamClassroomUnassigned,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom_exam',
                targetId: $assignmentId,
                metadata: ['exam_id' => $exam->id, 'classroom_id' => $classroom->id],
                request: $request,
            );
        });

        return ApiResponse::noContent();
    }
}
