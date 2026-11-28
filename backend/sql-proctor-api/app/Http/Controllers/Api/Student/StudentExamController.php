<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\ActorType;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptGradingStatus;
use App\Enums\AttemptStatus;
use App\Enums\AuditEvent;
use App\Enums\ExamStatus;
use App\Enums\QueryRunStatus;
use App\Enums\QuestionType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStudentSession;
use App\Http\Requests\Student\RunQuestionQueryRequest;
use App\Http\Requests\Student\SaveAnswerRequest;
use App\Http\Resources\StudentAttemptResource;
use App\Http\Resources\StudentExamResource;
use App\Http\Resources\StudentResultResource;
use App\Http\Support\ApiResponse;
use App\Models\AttemptAnswer;
use App\Models\AttemptQueryRun;
use App\Models\ClassroomExam;
use App\Models\ExamAttempt;
use App\Models\StudentSession;
use App\Services\AuditLogger;
use App\Services\Grading\ExamGradingService;
use App\Services\SqlExecution\SqlExecutionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StudentExamController extends Controller
{
    public function exams(Request $request): JsonResponse
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get(VerifyStudentSession::ATTRIBUTE);
        $now = now();

        $classroomExams = ClassroomExam::query()
            ->where('classroom_id', $session->classroom_id)
            ->with([
                'exam:id,title,description,instructions,status,duration_minutes',
                'examVersion',
                'attempts' => fn ($q) => $q->where('student_session_id', $session->id),
            ])
            ->where(function ($query) use ($session, $now): void {
                $query->whereHas('attempts', fn ($aq) => $aq->where('student_session_id', $session->id))
                    ->orWhere(function ($active) use ($now): void {
                        $active->where('is_active', true)
                            ->whereHas('exam', fn ($eq) => $eq->where('status', ExamStatus::Published->value))
                            ->where(fn ($w) => $w->whereNull('available_from')->orWhere('available_from', '<=', $now))
                            ->where(fn ($w) => $w->whereNull('available_until')->orWhere('available_until', '>=', $now));
                    });
            })
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::collection(StudentExamResource::collection($classroomExams));
    }

    public function start(
        Request $request,
        string $classroomExamId,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        /** @var StudentSession $session */
        $session = $request->attributes->get(VerifyStudentSession::ATTRIBUTE);
        $classroomExam = ClassroomExam::findOrFail($classroomExamId);

        if ($classroomExam->classroom_id !== $session->classroom_id) {
            throw ApiException::forbidden('You do not have access to this exam.', 'exam_forbidden');
        }

        $existing = ExamAttempt::query()
            ->where('classroom_exam_id', $classroomExam->id)
            ->where('student_session_id', $session->id)
            ->first();

        if ($existing instanceof ExamAttempt) {
            if ($existing->isInProgress()) {
                if ($existing->hasExpired()) {
                    $this->autoSubmitExpiredAttempt($existing, $session, $gradingService, $auditLogger, $request);

                    throw ApiException::conflict('This attempt has already been submitted.', 'attempt_already_submitted');
                }

                $existing->load(['examVersion', 'answers']);

                return ApiResponse::resource(new StudentAttemptResource($existing), 200);
            }

            throw ApiException::conflict('This attempt has already been submitted.', 'attempt_already_submitted');
        }

        $classroomExam->load(['exam', 'examVersion']);

        if (! $classroomExam->is_active
            || $classroomExam->exam?->status !== ExamStatus::Published
            || ! $classroomExam->isWithinAvailabilityWindow()) {
            throw ApiException::conflict('This exam is not currently available to start.', 'exam_not_available');
        }

        $version = $classroomExam->examVersion;
        $snapshot = $version->snapshot;
        $durationMinutes = max(1, (int) ($snapshot['duration_minutes'] ?? $classroomExam->exam->duration_minutes));
        $startedAt = now();
        $deadline = $startedAt->copy()->addMinutes($durationMinutes);

        if ($classroomExam->available_until !== null && $classroomExam->available_until->lt($deadline)) {
            $deadline = $classroomExam->available_until->copy();
        }

        $questionIds = collect($snapshot['questions'] ?? [])->pluck('id')->values()->all();
        if ((bool) ($snapshot['settings']['shuffle_questions'] ?? false) && count($questionIds) > 1) {
            shuffle($questionIds);
        }

        try {
            $attempt = DB::transaction(function () use (
                $request,
                $classroomExam,
                $version,
                $session,
                $startedAt,
                $deadline,
                $questionIds,
                $snapshot,
                $auditLogger
            ): ExamAttempt {
                $attempt = ExamAttempt::create([
                    'classroom_exam_id' => $classroomExam->id,
                    'exam_version_id' => $version->id,
                    'student_session_id' => $session->id,
                    'status' => AttemptStatus::InProgress,
                    'grading_status' => AttemptGradingStatus::Pending,
                    'started_at' => $startedAt,
                    'expires_at' => $deadline,
                    'question_order' => $questionIds,
                    'auto_score' => 0.00,
                    'final_score' => 0.00,
                    'max_score' => number_format((float) ($snapshot['total_points'] ?? 0), 2, '.', ''),
                ]);

                $auditLogger->log(
                    eventType: AuditEvent::StudentExamStarted,
                    actorType: ActorType::Student,
                    actorId: $session->id,
                    actorLabel: $session->display_name,
                    targetType: 'exam_attempt',
                    targetId: $attempt->id,
                    metadata: [
                        'classroom_exam_id' => $classroomExam->id,
                        'exam_version_id' => $version->id,
                    ],
                    request: $request,
                );

                return $attempt;
            });
        } catch (UniqueConstraintViolationException) {
            $raceAttempt = ExamAttempt::query()
                ->where('classroom_exam_id', $classroomExam->id)
                ->where('student_session_id', $session->id)
                ->with(['examVersion', 'answers'])
                ->firstOrFail();

            if (! $raceAttempt->isInProgress()) {
                throw ApiException::conflict('This attempt has already been submitted.', 'attempt_already_submitted');
            }

            return ApiResponse::resource(new StudentAttemptResource($raceAttempt), 200);
        }

        $attempt->load(['examVersion', 'answers']);

        return ApiResponse::resource(new StudentAttemptResource($attempt), 201);
    }

    public function show(
        Request $request,
        string $attemptId,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attempt = ExamAttempt::findOrFail($attemptId);
        $session = $this->authorizeAttemptOwner($request, $attempt);

        if ($attempt->isInProgress() && $attempt->hasExpired()) {
            $attempt = $this->autoSubmitExpiredAttempt($attempt, $session, $gradingService, $auditLogger, $request);
        }

        $attempt->load(['examVersion', 'answers']);

        return ApiResponse::resource(new StudentAttemptResource($attempt));
    }

    public function saveAnswer(
        SaveAnswerRequest $request,
        string $attemptId,
        string $questionId,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attempt = ExamAttempt::findOrFail($attemptId);
        $session = $this->authorizeAttemptOwner($request, $attempt);
        $attempt->loadMissing('examVersion');
        $question = $this->findSnapshotQuestion($attempt, $questionId);
        $validated = $request->validated();
        $submitNow = $request->boolean('submit_answer');

        $outcome = DB::transaction(function () use (
            $request,
            $attempt,
            $session,
            $questionId,
            $validated,
            $question,
            $submitNow,
            $gradingService,
            $auditLogger,
        ): array {
            $lockedAttempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if (! $lockedAttempt->isInProgress()) {
                return ['status' => 'attempt_not_active'];
            }

            if ($lockedAttempt->hasExpired()) {
                $lockedAttempt->forceFill([
                    'status' => AttemptStatus::AutoSubmitted,
                    'submitted_at' => now(),
                ])->save();

                $gradingService->gradeAttempt($lockedAttempt);

                $auditLogger->log(
                    eventType: AuditEvent::StudentExamAutoSubmitted,
                    actorType: ActorType::Student,
                    actorId: $session->id,
                    actorLabel: $session->display_name,
                    targetType: 'exam_attempt',
                    targetId: $lockedAttempt->id,
                    metadata: ['classroom_exam_id' => $lockedAttempt->classroom_exam_id],
                    request: $request,
                );

                return ['status' => 'attempt_expired'];
            }

            $existing = AttemptAnswer::query()
                ->where('attempt_id', $lockedAttempt->id)
                ->where('question_id', $questionId)
                ->lockForUpdate()
                ->first();

            if ($existing?->isSubmitted()) {
                return ['status' => 'answer_locked'];
            }

            $hasContent = array_key_exists('submitted_sql', $validated)
                || array_key_exists('submitted_text', $validated)
                || array_key_exists('selected_option_ids', $validated);

            $attributes = $hasContent || $existing === null
                ? [
                    'submitted_sql' => array_key_exists('submitted_sql', $validated) ? $validated['submitted_sql'] : null,
                    'submitted_text' => array_key_exists('submitted_text', $validated) ? $validated['submitted_text'] : null,
                    'selected_option_ids' => array_key_exists('selected_option_ids', $validated)
                        ? array_values(array_unique(array_map('strval', $validated['selected_option_ids'] ?? [])))
                        : null,
                    'max_points' => number_format((float) $question['points'], 2, '.', ''),
                    'grading_status' => AnswerGradingStatus::Unanswered,
                    'final_score' => 0.00,
                    'test_results' => [],
                ]
                : [
                    'max_points' => number_format((float) $question['points'], 2, '.', ''),
                ];

            if ($submitNow) {
                $attributes['submitted_at'] = now();
            }

            $saved = AttemptAnswer::updateOrCreate(
                [
                    'attempt_id' => $lockedAttempt->id,
                    'question_id' => $questionId,
                ],
                $attributes,
            );

            return ['status' => 'saved', 'answer' => $saved];
        });

        if ($outcome['status'] === 'attempt_not_active') {
            throw ApiException::conflict('This attempt is no longer active.', 'attempt_not_active');
        }

        if ($outcome['status'] === 'attempt_expired') {
            throw ApiException::conflict('Attempt time has expired and was automatically submitted.', 'attempt_expired');
        }

        if ($outcome['status'] === 'answer_locked') {
            throw ApiException::conflict('This answer has already been submitted and locked.', 'answer_locked');
        }

        /** @var AttemptAnswer $answer */
        $answer = $outcome['answer'];

        if ($submitNow) {
            $auditLogger->log(
                eventType: AuditEvent::StudentAnswerSubmitted,
                actorType: ActorType::Student,
                actorId: $session->id,
                actorLabel: $session->display_name,
                targetType: 'exam_attempt',
                targetId: $attempt->id,
                metadata: [
                    'question_id' => $questionId,
                    'question_type' => $question['question_type'] ?? null,
                ],
                request: $request,
            );
        }

        return response()->json([
            'data' => [
                'question_id' => $answer->question_id,
                'submitted_sql' => $answer->submitted_sql,
                'submitted_text' => $answer->submitted_text,
                'selected_option_ids' => $answer->selected_option_ids ?? [],
                'submitted_at' => $answer->submitted_at?->toIso8601ZuluString(),
                'is_submitted' => $answer->isSubmitted(),
                'updated_at' => $answer->updated_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    public function runQuery(
        RunQuestionQueryRequest $request,
        string $attemptId,
        string $questionId,
        SqlExecutionService $sqlExecutor,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attempt = ExamAttempt::findOrFail($attemptId);
        $session = $this->authorizeAttemptOwner($request, $attempt);

        if (! $attempt->isInProgress()) {
            throw ApiException::conflict('This attempt is no longer active.', 'attempt_not_active');
        }

        if ($attempt->hasExpired()) {
            $this->autoSubmitExpiredAttempt($attempt, $session, $gradingService, $auditLogger, $request);

            throw ApiException::conflict('Attempt time has expired and was automatically submitted.', 'attempt_expired');
        }

        $limitPerMin = (int) config('sql_proctor.sandbox.student_run_rate_limit_per_minute', 20);
        $throttleKey = 'student:sql-run:'.$session->id;

        if (RateLimiter::tooManyAttempts($throttleKey, $limitPerMin)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            throw ApiException::tooManyRequests(
                'Too many query executions. Try again shortly.',
                'too_many_attempts',
                ['Retry-After' => (string) max(1, $retryAfter)],
            );
        }

        $attempt->loadMissing('examVersion');
        $question = $this->findSnapshotQuestion($attempt, $questionId);

        $existingAnswer = AttemptAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $questionId)
            ->first();

        if ($existingAnswer?->isSubmitted()) {
            throw ApiException::conflict(
                'This answer has already been submitted and cannot be executed.',
                'answer_locked',
            );
        }

        $questionType = QuestionType::from((string) $question['question_type']);

        if (! $questionType->isSql()) {
            throw ValidationException::withMessages([
                'question' => ['Live SQL execution is only supported on SQL questions.'],
            ]);
        }

        $snapshot = $attempt->examVersion->snapshot;
        $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];

        if (($settings['allow_query_preview'] ?? true) === false) {
            throw ApiException::forbidden('Query preview is disabled for this exam.', 'query_preview_disabled');
        }

        $maxRuns = $settings['max_query_runs_per_question'] ?? null;
        if ($maxRuns !== null) {
            $usedRuns = AttemptQueryRun::query()
                ->where('attempt_id', $attempt->id)
                ->where('question_id', $questionId)
                ->count();
            if ($usedRuns >= (int) $maxRuns) {
                throw ApiException::tooManyRequests(
                    "Maximum of {$maxRuns} query runs reached for this question.",
                    'query_run_limit_reached',
                );
            }
        }

        $lock = Cache::lock("sandbox:attempt:{$attempt->id}", 10);
        if (! $lock->get()) {
            throw ApiException::conflict(
                'Another query is already running on this attempt. Please wait.',
                'execution_in_progress',
            );
        }

        RateLimiter::hit($throttleKey, 60);

        $sql = (string) $request->validated('sql');
        $dataset = is_array($snapshot['dataset'] ?? null) ? $snapshot['dataset'] : null;
        $schemaSql = (string) ($dataset['schema_sql'] ?? '');
        $seedSql = (string) ($dataset['seed_sql'] ?? '');

        try {
            $result = $sqlExecutor->execute($schemaSql, $seedSql, $sql, $questionType);
        } finally {
            $lock->release();
        }

        $runStatus = QueryRunStatus::tryFrom($result->status) ?? QueryRunStatus::SystemError;

        AttemptQueryRun::create([
            'attempt_id' => $attempt->id,
            'question_id' => $questionId,
            'sql_text' => $sql,
            'status' => $runStatus,
            'row_count' => $result->rowCount,
            'execution_ms' => $result->executionMs,
            'error_message' => $result->errorMessage,
            'created_at' => now(),
        ]);

        if ($runStatus === QueryRunStatus::PolicyBlocked) {
            $auditLogger->log(
                eventType: AuditEvent::StudentQueryBlocked,
                actorType: ActorType::Student,
                actorId: $session->id,
                actorLabel: $session->display_name,
                targetType: 'exam_attempt',
                targetId: $attempt->id,
                metadata: [
                    'question_id' => $questionId,
                    'reason' => $result->errorMessage,
                ],
                request: $request,
            );
        }

        return response()->json(['data' => $result->toStudentArray()]);
    }

    public function submit(
        Request $request,
        string $attemptId,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attempt = ExamAttempt::findOrFail($attemptId);
        $session = $this->authorizeAttemptOwner($request, $attempt);

        $outcome = DB::transaction(function () use ($request, $attempt, $session, $gradingService, $auditLogger): string {
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if (! $locked->isInProgress()) {
                return 'not_active';
            }

            if ($locked->hasExpired()) {
                $locked->forceFill([
                    'status' => AttemptStatus::AutoSubmitted,
                    'submitted_at' => now(),
                ])->save();

                $gradingService->gradeAttempt($locked);

                $auditLogger->log(
                    eventType: AuditEvent::StudentExamAutoSubmitted,
                    actorType: ActorType::Student,
                    actorId: $session->id,
                    actorLabel: $session->display_name,
                    targetType: 'exam_attempt',
                    targetId: $locked->id,
                    metadata: ['classroom_exam_id' => $locked->classroom_exam_id],
                    request: $request,
                );

                return 'expired';
            }

            $locked->forceFill([
                'status' => AttemptStatus::Submitted,
                'submitted_at' => now(),
            ])->save();

            $gradingService->gradeAttempt($locked);

            $auditLogger->log(
                eventType: AuditEvent::StudentExamSubmitted,
                actorType: ActorType::Student,
                actorId: $session->id,
                actorLabel: $session->display_name,
                targetType: 'exam_attempt',
                targetId: $locked->id,
                metadata: ['classroom_exam_id' => $locked->classroom_exam_id],
                request: $request,
            );

            return 'submitted';
        });

        if ($outcome === 'not_active') {
            throw ApiException::conflict('This attempt has already been submitted or closed.', 'attempt_not_active');
        }

        if ($outcome === 'expired') {
            throw ApiException::conflict('Attempt time expired and was automatically submitted.', 'attempt_expired');
        }

        $attempt->refresh()->load(['examVersion', 'answers']);

        return ApiResponse::resource(new StudentAttemptResource($attempt));
    }

    public function result(
        Request $request,
        string $attemptId,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $attempt = ExamAttempt::findOrFail($attemptId);
        $session = $this->authorizeAttemptOwner($request, $attempt);

        if ($attempt->isInProgress()) {
            if ($attempt->hasExpired()) {
                $attempt = $this->autoSubmitExpiredAttempt($attempt, $session, $gradingService, $auditLogger, $request);
            } else {
                throw ApiException::conflict('Results are unavailable while the attempt is still in progress.', 'attempt_still_in_progress');
            }
        }

        $attempt->load(['classroomExam', 'examVersion', 'answers']);

        return ApiResponse::resource(new StudentResultResource($attempt));
    }

    private function authorizeAttemptOwner(Request $request, ExamAttempt $attempt): StudentSession
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get(VerifyStudentSession::ATTRIBUTE);

        if ($attempt->student_session_id !== $session->id) {
            throw ApiException::forbidden('You do not have access to this exam attempt.', 'attempt_forbidden');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function findSnapshotQuestion(ExamAttempt $attempt, string $questionId): array
    {
        $question = collect($attempt->examVersion?->snapshot['questions'] ?? [])
            ->firstWhere('id', $questionId);

        if (! is_array($question)) {
            throw ApiException::notFound();
        }

        return $question;
    }

    private function autoSubmitExpiredAttempt(
        ExamAttempt $attempt,
        StudentSession $session,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
        Request $request,
    ): ExamAttempt {
        return DB::transaction(function () use ($attempt, $session, $gradingService, $auditLogger, $request): ExamAttempt {
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->isInProgress()) {
                $locked->forceFill([
                    'status' => AttemptStatus::AutoSubmitted,
                    'submitted_at' => now(),
                ])->save();

                $gradingService->gradeAttempt($locked);

                $auditLogger->log(
                    eventType: AuditEvent::StudentExamAutoSubmitted,
                    actorType: ActorType::Student,
                    actorId: $session->id,
                    actorLabel: $session->display_name,
                    targetType: 'exam_attempt',
                    targetId: $locked->id,
                    metadata: ['classroom_exam_id' => $locked->classroom_exam_id],
                    request: $request,
                );
            }

            return $locked->fresh();
        });
    }
}
