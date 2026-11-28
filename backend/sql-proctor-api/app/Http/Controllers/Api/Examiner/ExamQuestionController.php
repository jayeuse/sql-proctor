<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\ReorderQuestionsRequest;
use App\Http\Requests\Examiner\SaveExamQuestionRequest;
use App\Http\Resources\ExamQuestionResource;
use App\Http\Support\ApiResponse;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExamQuestionController extends Controller
{
    public function store(SaveExamQuestionRequest $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        if (! $exam->isEditable()) {
            throw ApiException::conflict('This exam is locked and cannot be edited.', 'exam_locked');
        }

        /** @var User $examiner */
        $examiner = $request->user();
        $validated = $request->validated();

        $question = DB::transaction(function () use ($request, $exam, $examiner, $validated, $auditLogger): ExamQuestion {
            $nextPos = ((int) $exam->questions()->max('position')) + 1;

            $question = $exam->questions()->create([
                'position' => $nextPos,
                'question_type' => $validated['question_type'],
                'grading_mode' => $validated['grading_mode'],
                'title' => $validated['title'],
                'prompt' => $validated['prompt'],
                'points' => $validated['points'],
                'starter_sql' => $validated['starter_sql'] ?? null,
                'reference_sql' => $validated['reference_sql'] ?? null,
                'comparison_config' => $validated['comparison_config'] ?? [],
                'rubric' => $validated['rubric'] ?? [],
            ]);

            if (! empty($validated['test_cases'])) {
                $this->syncTestCases($question, $validated['test_cases']);
            }

            if (! empty($validated['options'])) {
                $this->syncOptions($question, $validated['options']);
            }

            $exam->recomputeTotalPoints();

            $auditLogger->log(
                eventType: AuditEvent::QuestionCreated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam_question',
                targetId: $question->id,
                metadata: ['exam_id' => $exam->id, 'question_type' => $question->question_type->value],
                request: $request,
            );

            return $question;
        });

        $question->load(['testCases', 'options']);

        return ApiResponse::resource(new ExamQuestionResource($question), 201);
    }

    public function update(
        SaveExamQuestionRequest $request,
        Exam $exam,
        ExamQuestion $question,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $exam);
        $this->ensureQuestionBelongsToExam($exam, $question);

        if (! $exam->isEditable()) {
            throw ApiException::conflict('This exam is locked and cannot be edited.', 'exam_locked');
        }

        /** @var User $examiner */
        $examiner = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($request, $exam, $question, $examiner, $validated, $auditLogger): void {
            $updates = [];
            foreach ([
                'question_type',
                'grading_mode',
                'title',
                'prompt',
                'points',
                'starter_sql',
                'reference_sql',
                'comparison_config',
                'rubric',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if ($updates !== []) {
                $question->update($updates);
            }

            if (array_key_exists('test_cases', $validated)) {
                $question->testCases()->delete();
                $this->syncTestCases($question, $validated['test_cases'] ?? []);
            }

            if (array_key_exists('options', $validated)) {
                $question->options()->delete();
                $this->syncOptions($question, $validated['options'] ?? []);
            }

            $exam->recomputeTotalPoints();

            $auditLogger->log(
                eventType: AuditEvent::QuestionUpdated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam_question',
                targetId: $question->id,
                metadata: ['exam_id' => $exam->id],
                request: $request,
            );
        });

        $question->refresh()->load(['testCases', 'options']);

        return ApiResponse::resource(new ExamQuestionResource($question));
    }

    public function destroy(
        Request $request,
        Exam $exam,
        ExamQuestion $question,
        AuditLogger $auditLogger,
    ): Response {
        Gate::authorize('update', $exam);
        $this->ensureQuestionBelongsToExam($exam, $question);

        if (! $exam->isEditable()) {
            throw ApiException::conflict('This exam is locked and cannot be edited.', 'exam_locked');
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $exam, $question, $examiner, $auditLogger): void {
            $questionId = $question->id;
            $question->delete();

            // Re-compact remaining positions sequentially without unique-key collision.
            $remaining = $exam->questions()->orderBy('position')->get();
            foreach ($remaining as $q) {
                $q->update(['position' => $q->position + 100000]);
            }
            foreach ($remaining->values() as $idx => $q) {
                $q->update(['position' => $idx + 1]);
            }

            $exam->recomputeTotalPoints();

            $auditLogger->log(
                eventType: AuditEvent::QuestionDeleted,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam_question',
                targetId: $questionId,
                metadata: ['exam_id' => $exam->id],
                request: $request,
            );
        });

        return ApiResponse::noContent();
    }

    public function reorder(ReorderQuestionsRequest $request, Exam $exam, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $exam);

        if (! $exam->isEditable()) {
            throw ApiException::conflict('This exam is locked and cannot be edited.', 'exam_locked');
        }

        $requestedIds = $request->validated('question_ids');
        $existingIds = $exam->questions()->orderBy('position')->pluck('id')->all();

        $sortedRequested = $requestedIds;
        $sortedExisting = $existingIds;
        sort($sortedRequested);
        sort($sortedExisting);

        if ($sortedRequested !== $sortedExisting) {
            throw ValidationException::withMessages([
                'question_ids' => ['question_ids must match the complete set of questions on this exam.'],
            ]);
        }

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $exam, $examiner, $requestedIds, $auditLogger): void {
            // Two-step shift guarantees unique (exam_id, position) and CHECK (position >= 1) never collide.
            DB::table('exam_questions')
                ->where('exam_id', $exam->id)
                ->update(['position' => DB::raw('position + 100000')]);

            foreach ($requestedIds as $index => $questionId) {
                DB::table('exam_questions')
                    ->where('exam_id', $exam->id)
                    ->where('id', $questionId)
                    ->update(['position' => $index + 1, 'updated_at' => now()]);
            }

            $auditLogger->log(
                eventType: AuditEvent::QuestionReordered,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'exam',
                targetId: $exam->id,
                metadata: ['question_ids' => $requestedIds],
                request: $request,
            );
        });

        $questions = $exam->questions()->with(['testCases', 'options'])->orderBy('position')->get();

        return ApiResponse::collection(ExamQuestionResource::collection($questions));
    }

    private function ensureQuestionBelongsToExam(Exam $exam, ExamQuestion $question): void
    {
        if ($question->exam_id !== $exam->id) {
            throw ApiException::notFound();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $testCases
     */
    private function syncTestCases(ExamQuestion $question, array $testCases): void
    {
        foreach (array_values($testCases) as $idx => $tc) {
            $question->testCases()->create([
                'name' => $tc['name'],
                'position' => $idx + 1,
                'weight_points' => $tc['weight_points'],
                'is_hidden' => (bool) ($tc['is_hidden'] ?? false),
                'override_seed_sql' => $tc['override_seed_sql'] ?? null,
                'assertion_sql' => $tc['assertion_sql'] ?? null,
                'expected_behavior' => $tc['expected_behavior'] ?? 'match_reference',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(ExamQuestion $question, array $options): void
    {
        foreach (array_values($options) as $idx => $opt) {
            $question->options()->create([
                'position' => $idx + 1,
                'label' => $opt['label'],
                'is_correct' => (bool) ($opt['is_correct'] ?? false),
            ]);
        }
    }
}
