<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AnswerGradingStatus;
use App\Enums\AuditEvent;
use App\Enums\GradingMode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\GradeAttemptAnswerRequest;
use App\Http\Requests\Examiner\ListExamAttemptsRequest;
use App\Http\Resources\AttemptAnswerResource;
use App\Http\Resources\ExamAttemptSummaryResource;
use App\Http\Resources\ExaminerAttemptDetailResource;
use App\Http\Support\ApiResponse;
use App\Models\AttemptAnswer;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Grading\ExamGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExamAttemptGradingController extends Controller
{
    public function index(ListExamAttemptsRequest $request, Exam $exam): JsonResponse
    {
        Gate::authorize('view', $exam);

        $classroomId = $request->validated('classroom_id');
        $status = $request->validated('status');
        $gradingStatus = $request->validated('grading_status');

        $query = ExamAttempt::query()
            ->whereHas('classroomExam', function ($q) use ($exam, $classroomId): void {
                $q->where('exam_id', $exam->id);
                if (is_string($classroomId) && $classroomId !== '') {
                    $q->where('classroom_id', $classroomId);
                }
            })
            ->with(['studentSession:id,display_name', 'classroomExam.classroom:id,name']);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($gradingStatus) && $gradingStatus !== '') {
            $query->where('grading_status', $gradingStatus);
        }

        $attempts = $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(ExamAttemptSummaryResource::collection($attempts));
    }

    public function show(ExamAttempt $attempt): JsonResponse
    {
        Gate::authorize('view', $attempt);

        $attempt->load([
            'examVersion',
            'studentSession:id,display_name',
            'classroomExam.classroom:id,name',
            'answers',
            'queryRuns',
        ]);

        return ApiResponse::resource(new ExaminerAttemptDetailResource($attempt));
    }

    public function grade(
        GradeAttemptAnswerRequest $request,
        ExamAttempt $attempt,
        AttemptAnswer $answer,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('grade', $attempt);

        if ($answer->attempt_id !== $attempt->id) {
            throw ApiException::notFound();
        }

        $attempt->loadMissing('examVersion');
        $snapshotQuestion = collect($attempt->examVersion?->snapshot['questions'] ?? [])
            ->firstWhere('id', $answer->question_id);

        if (! is_array($snapshotQuestion)) {
            throw ApiException::notFound();
        }

        $validated = $request->validated();
        $manualScore = round((float) $validated['manual_score'], 2);
        $maxPoints = round((float) $answer->max_points, 2);

        if ($manualScore > $maxPoints) {
            throw ValidationException::withMessages([
                'manual_score' => ["Score cannot exceed question maximum of {$maxPoints}."],
            ]);
        }

        $questionMode = GradingMode::from((string) $snapshotQuestion['grading_mode']);
        $isOverride = $questionMode === GradingMode::Auto;
        $overrideReason = trim((string) ($validated['override_reason'] ?? ''));

        if ($isOverride && $overrideReason === '') {
            throw ValidationException::withMessages([
                'override_reason' => ['An override_reason is required when overriding an auto-graded question.'],
            ]);
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use (
            $request,
            $attempt,
            $answer,
            $examiner,
            $validated,
            $manualScore,
            $isOverride,
            $overrideReason,
            $gradingService,
            $auditLogger
        ): void {
            $previousFinal = $answer->final_score;

            // Preserve auto_score untouched; store examiner score in manual_score and final_score.
            $answer->forceFill([
                'manual_score' => number_format($manualScore, 2, '.', ''),
                'final_score' => number_format($manualScore, 2, '.', ''),
                'rubric_scores' => array_key_exists('rubric_scores', $validated)
                    ? $validated['rubric_scores']
                    : $answer->rubric_scores,
                'examiner_feedback' => array_key_exists('examiner_feedback', $validated)
                    ? $validated['examiner_feedback']
                    : $answer->examiner_feedback,
                'override_reason' => $isOverride ? $overrideReason : ($validated['override_reason'] ?? $answer->override_reason),
                'grading_status' => AnswerGradingStatus::Graded,
                'graded_by' => $examiner->id,
                'graded_at' => now(),
            ])->save();

            $gradingService->recalculateAttemptTotals($attempt, $examiner->id, finalize: false);

            $auditLogger->log(
                eventType: $isOverride ? AuditEvent::GradingScoreOverridden : AuditEvent::GradingAnswerGraded,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'attempt_answer',
                targetId: $answer->id,
                metadata: [
                    'attempt_id' => $attempt->id,
                    'question_id' => $answer->question_id,
                    'previous_final_score' => $previousFinal,
                    'new_final_score' => $answer->final_score,
                    'override_reason' => $isOverride ? $overrideReason : null,
                ],
                request: $request,
            );
        });

        return ApiResponse::resource(new AttemptAnswerResource($answer->fresh()));
    }

    public function finalize(
        Request $request,
        ExamAttempt $attempt,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('grade', $attempt);

        if ($attempt->isInProgress()) {
            throw ApiException::conflict('Cannot finalize an attempt that is still in progress.', 'attempt_still_in_progress');
        }

        $attempt->load(['answers', 'examVersion']);
        $hasPending = $attempt->answers->contains(
            fn ($ans): bool => $ans->grading_status === AnswerGradingStatus::NeedsReview,
        );

        if ($hasPending) {
            throw ValidationException::withMessages([
                'attempt' => ['All questions marked needs_review must be graded before finalizing this attempt.'],
            ]);
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $attempt, $examiner, $gradingService, $auditLogger): void {
            $updated = $gradingService->recalculateAttemptTotals($attempt, $examiner->id, finalize: true);

            $auditLogger->log(
                eventType: AuditEvent::AttemptFinalized,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam_attempt',
                targetId: $attempt->id,
                metadata: [
                    'final_score' => $updated->final_score,
                    'max_score' => $updated->max_score,
                    'passed' => $updated->passed,
                ],
                request: $request,
            );
        });

        $attempt->refresh()->load([
            'examVersion',
            'studentSession:id,display_name',
            'classroomExam.classroom:id,name',
            'answers',
            'queryRuns',
        ]);

        return ApiResponse::resource(new ExaminerAttemptDetailResource($attempt));
    }
}
