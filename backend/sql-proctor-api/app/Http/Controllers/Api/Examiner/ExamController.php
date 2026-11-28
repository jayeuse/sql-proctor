<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AttemptStatus;
use App\Enums\AuditEvent;
use App\Enums\ExamStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\ListExamsRequest;
use App\Http\Requests\Examiner\StoreExamRequest;
use App\Http\Requests\Examiner\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Http\Support\ApiResponse;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\SqlDataset;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Exams\ExamValidationService;
use App\Services\Exams\ExamVersioningService;
use App\Services\Grading\ExamGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExamController extends Controller
{
    public function index(ListExamsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Exam::class);

        /** @var User $examiner */
        $examiner = $request->user();
        $status = $request->validated('status');
        $search = trim((string) $request->validated('search', ''));

        $query = Exam::query()
            ->where('examiner_id', $examiner->id)
            ->with(['dataset:id,name,validated_at'])
            ->withCount(['questions', 'attempts']);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
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

    public function store(StoreExamRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('create', Exam::class);

        /** @var User $examiner */
        $examiner = $request->user();
        $validated = $request->validated();

        if (! empty($validated['dataset_id'])) {
            $dataset = SqlDataset::findOrFail($validated['dataset_id']);
            Gate::authorize('view', $dataset);
        }

        $exam = DB::transaction(function () use ($request, $examiner, $validated, $auditLogger): Exam {
            $exam = Exam::create([
                'examiner_id' => $examiner->id,
                'dataset_id' => $validated['dataset_id'] ?? null,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'instructions' => $validated['instructions'] ?? null,
                'status' => ExamStatus::Draft,
                'duration_minutes' => (int) ($validated['duration_minutes'] ?? 60),
                'passing_score_percent' => $validated['passing_score_percent'] ?? 60.00,
                'total_points' => 0.00,
                'settings' => array_merge([
                    'allow_query_preview' => true,
                    'shuffle_questions' => false,
                    'show_points' => true,
                    'immediate_auto_results' => false,
                    'max_query_runs_per_question' => null,
                ], $validated['settings'] ?? []),
            ]);

            $auditLogger->log(
                eventType: AuditEvent::ExamCreated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: ['title' => $exam->title],
                request: $request,
            );

            return $exam;
        });

        $exam->load(['dataset', 'questions.testCases', 'questions.options', 'classroomExams.classroom', 'classroomExams.examVersion'])
            ->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam), 201);
    }

    public function show(Exam $exam): JsonResponse
    {
        Gate::authorize('view', $exam);

        $exam->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function update(UpdateExamRequest $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        if (! $exam->isEditable()) {
            throw ApiException::conflict(
                'This exam is locked and cannot be edited. Clone it to create a new editable draft.',
                'exam_locked',
            );
        }

        $validated = $request->validated();
        if (! empty($validated['dataset_id'])) {
            $dataset = SqlDataset::findOrFail($validated['dataset_id']);
            Gate::authorize('view', $dataset);
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $examiner, $exam, $validated, $auditLogger): void {
            $updates = [];
            foreach (['title', 'description', 'instructions', 'dataset_id', 'duration_minutes', 'passing_score_percent'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if (array_key_exists('settings', $validated)) {
                $updates['settings'] = array_merge($exam->resolvedSettings(), $validated['settings']);
            }

            $exam->update($updates);

            $auditLogger->log(
                eventType: AuditEvent::ExamUpdated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: ['fields' => array_keys($updates)],
                request: $request,
            );
        });

        $exam->refresh()->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function destroy(Request $request, Exam $exam, AuditLogger $auditLogger): Response
    {
        Gate::authorize('delete', $exam);

        if (! $exam->isDeletable()) {
            throw ApiException::conflict(
                'Only draft exams with zero attempts can be deleted.',
                'exam_locked',
            );
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $examiner, $exam, $auditLogger): void {
            $examId = $exam->id;
            $title = $exam->title;
            $exam->delete();

            $auditLogger->log(
                eventType: AuditEvent::ExamDeleted,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $examId,
                metadata: ['title' => $title],
                request: $request,
            );
        });

        return ApiResponse::noContent();
    }

    public function validateExam(Exam $exam, ExamValidationService $validator): JsonResponse
    {
        Gate::authorize('view', $exam);

        $validator->validateOrFail($exam);

        return response()->json([
            'data' => [
                'valid' => true,
                'issues' => [],
            ],
        ]);
    }

    public function publish(
        Request $request,
        Exam $exam,
        ExamValidationService $validator,
        ExamVersioningService $versioning,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $exam);

        if ($exam->status === ExamStatus::Archived) {
            throw ApiException::conflict('Archived exams cannot be published. Clone it to create a new draft.', 'exam_locked');
        }

        $validator->validateOrFail($exam);

        /** @var User $examiner */
        $examiner = $request->user();

        $version = DB::transaction(function () use ($request, $exam, $examiner, $versioning, $auditLogger) {
            $version = $versioning->publish($exam, $examiner);

            $auditLogger->log(
                eventType: AuditEvent::ExamPublished,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: [
                    'version_number' => $version->version_number,
                    'exam_version_id' => $version->id,
                ],
                request: $request,
            );

            return $version;
        });

        $exam->refresh()->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function unpublish(
        Request $request,
        Exam $exam,
        ExamGradingService $gradingService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $exam);

        if ($exam->status !== ExamStatus::Published) {
            throw ApiException::conflict('Only published exams can be unpublished.', 'exam_not_published');
        }

        $validated = $request->validate([
            'force_close_active' => ['sometimes', 'boolean'],
        ]);
        $forceClose = (bool) ($validated['force_close_active'] ?? false);

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $exam, $examiner, $forceClose, $gradingService, $auditLogger): void {
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
                        actorType: ActorType::Examiner,
                        actorId: (string) $examiner->id,
                        actorLabel: $examiner->username,
                        targetType: 'exam_attempt',
                        targetId: $attempt->id,
                        metadata: ['exam_id' => $exam->id],
                        request: $request,
                    );
                }
            }

            $auditLogger->log(
                eventType: AuditEvent::ExamUnpublished,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: [
                    'force_close_active' => $forceClose,
                    'force_closed_count' => $closedCount,
                ],
                request: $request,
            );
        });

        $exam->refresh()->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function archive(Request $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $exam, $examiner, $auditLogger): void {
            $exam->forceFill([
                'status' => ExamStatus::Archived,
                'archived_at' => now(),
            ])->save();

            $auditLogger->log(
                eventType: AuditEvent::ExamArchived,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                request: $request,
            );
        });

        $exam->refresh()->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams' => fn ($q) => $q->with(['classroom', 'examVersion'])->withCount('attempts'),
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($exam));
    }

    public function cloneExam(Request $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        /** @var User $examiner */
        $examiner = $request->user();

        $clone = DB::transaction(function () use ($request, $exam, $examiner, $auditLogger): Exam {
            $exam->load(['questions.testCases', 'questions.options']);

            $clonedExam = Exam::create([
                'examiner_id' => $examiner->id,
                'dataset_id' => $exam->dataset_id,
                'title' => mb_substr($exam->title.' (Copy)', 0, 160),
                'description' => $exam->description,
                'instructions' => $exam->instructions,
                'status' => ExamStatus::Draft,
                'duration_minutes' => $exam->duration_minutes,
                'passing_score_percent' => $exam->passing_score_percent,
                'total_points' => $exam->total_points,
                'settings' => $exam->resolvedSettings(),
                'current_version_number' => 0,
            ]);

            foreach ($exam->questions as $question) {
                $clonedQuestion = $clonedExam->questions()->create([
                    'position' => $question->position,
                    'question_type' => $question->question_type,
                    'grading_mode' => $question->grading_mode,
                    'title' => $question->title,
                    'prompt' => $question->prompt,
                    'points' => $question->points,
                    'starter_sql' => $question->starter_sql,
                    'reference_sql' => $question->reference_sql,
                    'comparison_config' => $question->comparison_config ?? [],
                    'rubric' => $question->rubric ?? [],
                ]);

                foreach ($question->testCases as $tc) {
                    $clonedQuestion->testCases()->create([
                        'name' => $tc->name,
                        'position' => $tc->position,
                        'weight_points' => $tc->weight_points,
                        'is_hidden' => $tc->is_hidden,
                        'override_seed_sql' => $tc->override_seed_sql,
                        'assertion_sql' => $tc->assertion_sql,
                        'expected_behavior' => $tc->expected_behavior,
                    ]);
                }

                foreach ($question->options as $opt) {
                    $clonedQuestion->options()->create([
                        'position' => $opt->position,
                        'label' => $opt->label,
                        'is_correct' => $opt->is_correct,
                    ]);
                }
            }

            $clonedExam->recomputeTotalPoints();

            $auditLogger->log(
                eventType: AuditEvent::ExamCloned,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $clonedExam->id,
                metadata: ['source_exam_id' => $exam->id],
                request: $request,
            );

            return $clonedExam;
        });

        $clone->load([
            'dataset',
            'questions.testCases',
            'questions.options',
            'classroomExams',
        ])->loadCount(['questions', 'attempts']);

        return ApiResponse::resource(new ExamResource($clone), 201);
    }
}
