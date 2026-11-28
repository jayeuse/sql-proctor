<?php

namespace Tests\Feature\Examiner;

use App\Enums\QuestionType;
use App\Exceptions\ImmutableExamVersionException;
use App\Models\Classroom;
use App\Models\ClassroomExam;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\ExamVersion;
use App\Models\QuestionTestCase;
use App\Models\SqlDataset;
use App\Models\StudentSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class ExamAuthoringAndPublishingTest extends TestCase
{
    use RefreshDatabase;

    /** TM-1 | FR-2, FR-3 | invalid input */
    public function test_question_validation_rejects_bad_types_points_weight_sums_and_mcq_options(): void
    {
        $examiner = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($examiner, 'examiner')->create(['status' => 'draft']);

        // Invalid question_type & <=0 points
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/questions", [
                'question_type' => 'invalid_sql',
                'grading_mode' => 'auto',
                'title' => 'Bad question',
                'prompt' => 'Prompt',
                'points' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['question_type', 'points']]);

        // Test case weight_points not summing to question points
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/questions", [
                'question_type' => 'sql_select',
                'grading_mode' => 'auto',
                'title' => 'Mismatch weights',
                'prompt' => 'Select all rows',
                'points' => 10,
                'reference_sql' => 'SELECT 1',
                'test_cases' => [
                    ['name' => 'TC1', 'weight_points' => 4, 'expected_behavior' => 'match_reference'],
                    ['name' => 'TC2', 'weight_points' => 4, 'expected_behavior' => 'match_reference'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['test_cases']]);

        // MCQ with < 2 options or 0 correct options
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/questions", [
                'question_type' => 'mcq',
                'grading_mode' => 'auto',
                'title' => 'Bad MCQ',
                'prompt' => 'Pick one',
                'points' => 5,
                'options' => [
                    ['label' => 'Only one', 'is_correct' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['options']]);
    }

    /** TM-5 | FR-2..FR-4 | unauthorized access */
    public function test_unauthenticated_requests_to_examiner_exams_return_401(): void
    {
        $this->getJson('/api/examiner/exams')->assertStatus(401);
        $this->postJson('/api/examiner/exams', [])->assertStatus(401);
    }

    /** TM-6 | FR-2, FR-4 | forbidden access */
    public function test_examiner_cannot_access_another_examiners_exam_or_assign_to_other_examiners_classroom(): void
    {
        $owner = User::factory()->examiner()->create();
        $other = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($owner, 'examiner')->create(['status' => 'published']);
        $otherExam = Exam::factory()->for($other, 'examiner')->create(['status' => 'published']);
        $version = ExamVersion::factory()->for($otherExam)->for($other, 'publisher')->create();
        $otherExam->update(['current_version_number' => $version->version_number]);
        $ownersClassroom = Classroom::factory()->for($owner, 'examiner')->create();

        $this->actingAs($other, 'sanctum')->getJson("/api/examiner/exams/{$exam->id}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->patchJson("/api/examiner/exams/{$exam->id}", ['title' => 'Steal'])->assertStatus(403);
        $this->actingAs($other, 'sanctum')->postJson("/api/examiner/exams/{$exam->id}/publish")->assertStatus(403);

        // Cannot assign own exam into another examiner's classroom
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/examiner/exams/{$otherExam->id}/classrooms", [
                'classroom_id' => $ownersClassroom->id,
            ])
            ->assertStatus(403);

        // F-8: Foreign question UUID under own draft exam must return 404 before question validation (422)
        $draftA = Exam::factory()->for($owner, 'examiner')->create(['status' => 'draft']);
        $questionA = ExamQuestion::factory()->for($draftA)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
        ]);
        $draftB = Exam::factory()->for($other, 'examiner')->create(['status' => 'draft']);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/examiner/exams/{$draftB->id}/questions/{$questionA->id}", [
                'points' => 10,
                'test_cases' => [['name' => 'Probe', 'weight_points' => 1]],
            ])
            ->assertStatus(404);
    }

    /** FR-2 | Performance | Exam listing avoids per-row exam_attempts exists queries on unpublished exams */
    public function test_exam_index_reuses_attempts_count_without_n_plus_one_exists_queries(): void
    {
        $examiner = User::factory()->examiner()->create();
        Exam::factory()->count(4)->for($examiner, 'examiner')->create(['status' => 'unpublished']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->actingAs($examiner, 'sanctum')
                ->getJson('/api/examiner/exams')
                ->assertOk();
            $queries = array_map(
                static fn (array $entry): string => strtolower((string) ($entry['query'] ?? '')),
                DB::getQueryLog(),
            );
        } finally {
            DB::disableQueryLog();
        }

        $existsQueries = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'exists') && str_contains($sql, 'exam_attempts'),
        );

        $this->assertCount(0, $existsQueries);
    }

    /** TM-7 | FR-2, FR-3, FR-4 | invalid state transitions & locks */
    public function test_published_archived_and_attempted_exams_lock_edits_and_preserve_immutable_versions(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE items (id INT PRIMARY KEY, name VARCHAR(50));',
            'seed_sql' => "INSERT INTO items VALUES (1, 'Pen');",
        ]);

        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'total_points' => 10,
        ]);
        $q = ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'SELECT id, name FROM items',
        ]);

        // Publish fails when validation fails (0 questions check tested below; here valid)
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version_number', 1);

        // Editing or deleting published exam or its questions returns 409 exam_locked
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/exams/{$exam->id}", ['title' => 'Edited'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exam_locked');

        $this->actingAs($examiner, 'sanctum')
            ->deleteJson("/api/examiner/exams/{$exam->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'exam_locked');

        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/exams/{$exam->id}/questions/{$q->id}", ['title' => 'Edited Q'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exam_locked');

        // Assign to classroom and record an attempt; assignment deletion then returns 409
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create();
        $assignmentRes = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/classrooms", ['classroom_id' => $classroom->id])
            ->assertStatus(201);

        $session = StudentSession::factory()->for($classroom)->create();
        $version = ExamVersion::where('exam_id', $exam->id)->firstOrFail();
        $classroomExam = ClassroomExam::findOrFail($assignmentRes->json('data.id'));

        ExamAttempt::factory()->create([
            'classroom_exam_id' => $classroomExam->id,
            'exam_version_id' => $version->id,
            'student_session_id' => $session->id,
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->deleteJson("/api/examiner/exams/{$exam->id}/classrooms/{$classroom->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_has_attempts');

        // Unpublish with attempts existing still locks editing
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/unpublish", ['force_close_active' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished');

        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/exams/{$exam->id}", ['title' => 'Edited after attempt'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exam_locked');

        // Immutable ExamVersion blocks model update/delete and dataset edit does not alter snapshot
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/datasets/{$dataset->id}", [
                'seed_sql' => "INSERT INTO items VALUES (1, 'Changed Later');",
            ])
            ->assertOk();

        $this->assertStringContainsString('Pen', (string) ($version->fresh()->snapshot['dataset']['seed_sql'] ?? ''));

        $this->expectException(ImmutableExamVersionException::class);
        $version->update(['version_number' => 99]);
    }

    /** TM-1 | FR-3 | pre-publish live MariaDB validation gate */
    public function test_validate_and_publish_reject_empty_exam_and_broken_reference_sql_with_structured_issues(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE users_tbl (id INT PRIMARY KEY, name VARCHAR(40));',
            'seed_sql' => "INSERT INTO users_tbl VALUES (1, 'Ada');",
        ]);
        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'total_points' => 10,
        ]);

        // 0 questions
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_validation_failed')
            ->assertJsonStructure(['message', 'code', 'issues', 'errors']);

        // Question with broken reference SQL fails live MariaDB dry-run
        ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'SELECT nonexistent_col FROM missing_table',
        ]);
        $exam->update(['total_points' => 10]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_validation_failed');

        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    /** TM-15 | NFR-1 | PostgreSQL CHECK constraints */
    public function test_postgresql_check_constraints_reject_invalid_exam_status_and_duration(): void
    {
        $examiner = User::factory()->examiner()->create();

        try {
            DB::transaction(function () use ($examiner): void {
                DB::table('exams')->insert([
                    'id' => (string) Str::uuid(),
                    'examiner_id' => $examiner->id,
                    'title' => 'Bad Status Exam',
                    'status' => 'invalid_status',
                    'duration_minutes' => 60,
                    'passing_score_percent' => 60,
                    'total_points' => 0,
                    'settings' => '{}',
                    'current_version_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('Expected PostgreSQL CHECK constraint failure on exams.status.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($examiner): void {
            DB::table('exams')->insert([
                'id' => (string) Str::uuid(),
                'examiner_id' => $examiner->id,
                'title' => 'Bad Duration Exam',
                'status' => 'draft',
                'duration_minutes' => 0,
                'passing_score_percent' => 60,
                'total_points' => 0,
                'settings' => '{}',
                'current_version_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** TM-17 | FR-2, FR-3, FR-4, FR-8 | normal behavior */
    public function test_full_authoring_reorder_publish_assign_archive_and_clone_lifecycle(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE employees (id INT PRIMARY KEY, name VARCHAR(80), dept VARCHAR(40));',
            'seed_sql' => "INSERT INTO employees VALUES (1, 'Ada', 'Eng'), (2, 'Grace', 'Ops');",
        ]);
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['is_open' => true]);

        $examRes = $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/examiner/exams', [
                'title' => 'Midterm Practical',
                'description' => 'SQL fundamentals',
                'instructions' => 'Write clean MariaDB queries.',
                'dataset_id' => $dataset->id,
                'duration_minutes' => 45,
                'passing_score_percent' => 70,
                'settings' => ['allow_query_preview' => true, 'shuffle_questions' => false],
            ])
            ->assertStatus(201);

        $examId = $examRes->json('data.id');

        $q1 = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/questions", [
                'question_type' => 'sql_select',
                'grading_mode' => 'auto',
                'title' => 'List engineers',
                'prompt' => 'Select names in Eng.',
                'points' => 10,
                'reference_sql' => "SELECT name FROM employees WHERE dept = 'Eng'",
                'test_cases' => [
                    ['name' => 'Base', 'weight_points' => 5, 'is_hidden' => false, 'expected_behavior' => 'match_reference'],
                    ['name' => 'Hidden', 'weight_points' => 5, 'is_hidden' => true, 'override_seed_sql' => "INSERT INTO employees VALUES (9, 'Linus', 'Eng');", 'expected_behavior' => 'match_reference'],
                ],
            ])
            ->assertStatus(201);

        $q2 = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/questions", [
                'question_type' => 'mcq',
                'grading_mode' => 'auto',
                'title' => 'Primary key rule',
                'prompt' => 'Which is true?',
                'points' => 5,
                'options' => [
                    ['label' => 'Unique and NOT NULL', 'is_correct' => true],
                    ['label' => 'Allows duplicates', 'is_correct' => false],
                ],
            ])
            ->assertStatus(201);

        $this->actingAs($examiner, 'sanctum')
            ->getJson("/api/examiner/exams/{$examId}")
            ->assertOk()
            ->assertJsonPath('data.total_points', '15.00');

        // Reorder without unique position collision
        $this->actingAs($examiner, 'sanctum')
            ->putJson("/api/examiner/exams/{$examId}/questions/reorder", [
                'question_ids' => [$q2->json('data.id'), $q1->json('data.id')],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $q2->json('data.id'))
            ->assertJsonPath('data.0.position', 1);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/validate")
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version_number', 1);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/classrooms", [
                'classroom_id' => $classroom->id,
                'is_active' => true,
            ])
            ->assertStatus(201);

        // Clone creates editable draft with copied questions, test cases, and options
        $cloneRes = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/clone")
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_points', '15.00');

        $this->assertNotSame($examId, $cloneRes->json('data.id'));

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$examId}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    /** PR#4 Blocking #1 & Non-Blocking High | auto/hybrid DML/DDL assertion requirement & seed error surfacing */
    public function test_dml_and_ddl_auto_questions_require_assertion_sql_and_surface_override_seed_errors(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE employees (id INT PRIMARY KEY, dept VARCHAR(40));',
            'seed_sql' => "INSERT INTO employees VALUES (1, 'Eng');",
        ]);
        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'total_points' => 10,
        ]);

        // Auto SqlDml with 0 test cases fails validation
        $qDml = ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlDml,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => "UPDATE employees SET dept = 'Ops' WHERE id = 1;",
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_validation_failed');

        // Auto SqlDml with match_reference test case lacking assertion_sql fails validation
        $tc = QuestionTestCase::factory()->for($qDml, 'question')->create([
            'position' => 1,
            'name' => 'Missing assert',
            'weight_points' => 10,
            'expected_behavior' => 'match_reference',
            'assertion_sql' => null,
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_validation_failed');

        // Adding assertion_sql + broken override_seed_sql surfaces real sanitized MariaDB error
        $tc->update([
            'assertion_sql' => 'SELECT id, dept FROM employees ORDER BY id',
            'override_seed_sql' => 'INSERT INTO missing_seed_tbl VALUES (1);',
        ]);

        $res = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_validation_failed');

        $this->assertStringContainsString('missing_seed_tbl', (string) $res->json('issues.0'));
    }

    /** PR#4 Blocking #5 & Non-Blocking | auto schema_summary on publish, available_until-only window, double-publish block */
    public function test_publishing_populates_unvalidated_dataset_schema_summary_allows_until_only_window_and_blocks_republish(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE inventory (sku INT PRIMARY KEY, stock INT NOT NULL);',
            'seed_sql' => 'INSERT INTO inventory VALUES (101, 5);',
            'schema_summary' => null,
            'validated_at' => null,
        ]);
        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'total_points' => 10,
        ]);
        ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'SELECT sku, stock FROM inventory ORDER BY sku',
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotEmpty($dataset->fresh()->schema_summary['tables'] ?? []);
        $version = ExamVersion::where('exam_id', $exam->id)->firstOrFail();
        $this->assertSame('inventory', $version->snapshot['dataset']['schema_summary']['tables'][0]['name'] ?? null);

        // Republish while already published returns 409 exam_locked
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('code', 'exam_locked');

        // Assign with available_until set while available_from is null succeeds (201)
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['is_open' => true]);
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/classrooms", [
                'classroom_id' => $classroom->id,
                'available_from' => null,
                'available_until' => now()->addDays(2)->toIso8601ZuluString(),
            ])
            ->assertStatus(201);
    }
}
