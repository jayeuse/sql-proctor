<?php

namespace App\Services\Exams;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExamVersioningService
{
    public function publish(Exam $exam, User $publisher): ExamVersion
    {
        return DB::transaction(function () use ($exam, $publisher): ExamVersion {
            $lockedExam = Exam::query()
                ->with(['dataset', 'questions.testCases', 'questions.options'])
                ->lockForUpdate()
                ->findOrFail($exam->id);

            $nextVersion = ((int) $lockedExam->current_version_number) + 1;
            $now = now();

            $snapshot = [
                'exam_id' => $lockedExam->id,
                'title' => $lockedExam->title,
                'description' => $lockedExam->description,
                'instructions' => $lockedExam->instructions,
                'duration_minutes' => (int) $lockedExam->duration_minutes,
                'passing_score_percent' => (float) $lockedExam->passing_score_percent,
                'total_points' => (float) $lockedExam->total_points,
                'settings' => $lockedExam->resolvedSettings(),
                'dataset' => $lockedExam->dataset ? [
                    'id' => $lockedExam->dataset->id,
                    'name' => $lockedExam->dataset->name,
                    'schema_sql' => $lockedExam->dataset->schema_sql,
                    'seed_sql' => $lockedExam->dataset->seed_sql,
                    'schema_summary' => $lockedExam->dataset->schema_summary,
                ] : null,
                'questions' => $lockedExam->questions->map(static function ($question): array {
                    return [
                        'id' => $question->id,
                        'position' => (int) $question->position,
                        'question_type' => $question->question_type->value,
                        'grading_mode' => $question->grading_mode->value,
                        'title' => $question->title,
                        'prompt' => $question->prompt,
                        'points' => (float) $question->points,
                        'starter_sql' => $question->starter_sql,
                        'reference_sql' => $question->reference_sql,
                        'comparison_config' => $question->comparison_config ?? [],
                        'rubric' => $question->rubric ?? [],
                        'test_cases' => $question->testCases->map(static fn ($tc): array => [
                            'id' => $tc->id,
                            'name' => $tc->name,
                            'position' => (int) $tc->position,
                            'weight_points' => (float) $tc->weight_points,
                            'is_hidden' => (bool) $tc->is_hidden,
                            'override_seed_sql' => $tc->override_seed_sql,
                            'assertion_sql' => $tc->assertion_sql,
                            'expected_behavior' => $tc->expected_behavior->value,
                        ])->values()->all(),
                        'options' => $question->options->map(static fn ($opt): array => [
                            'id' => $opt->id,
                            'position' => (int) $opt->position,
                            'label' => $opt->label,
                            'is_correct' => (bool) $opt->is_correct,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];

            $version = ExamVersion::create([
                'exam_id' => $lockedExam->id,
                'version_number' => $nextVersion,
                'snapshot' => $snapshot,
                'published_by' => $publisher->id,
                'published_at' => $now,
            ]);

            $lockedExam->forceFill([
                'status' => ExamStatus::Published,
                'current_version_number' => $nextVersion,
                'published_at' => $now,
                'unpublished_at' => null,
            ])->save();

            return $version;
        });
    }
}
