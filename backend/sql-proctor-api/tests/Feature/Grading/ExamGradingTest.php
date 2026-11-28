<?php

namespace Tests\Feature\Grading;

use App\Enums\QuestionType;
use App\Models\AttemptAnswer;
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
use App\Services\Grading\ExamGradingService;
use App\Services\Grading\ResultSetComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class ExamGradingTest extends TestCase
{
    use RefreshDatabase;

    /** TM-19 | FR-7 | comparator unit & edge cases */
    public function test_result_set_comparator_respects_order_column_names_case_float_tolerance_and_null_vs_empty(): void
    {
        $comparator = app(ResultSetComparator::class);

        // Order insensitive vs sensitive
        $ref = ['columns' => ['id', 'score'], 'rows' => [['id' => 1, 'score' => '10.000'], ['id' => 2, 'score' => '20.000']]];
        $subUnordered = ['columns' => ['emp_id', 'pts'], 'rows' => [['emp_id' => 2, 'pts' => '20.0004'], ['emp_id' => 1, 'pts' => '10.0001']]];

        $this->assertTrue($comparator->matches($ref, $subUnordered, ['order_sensitive' => false, 'column_name_sensitive' => false, 'float_tolerance' => 0.001])['matched']);
        $this->assertFalse($comparator->matches($ref, $subUnordered, ['order_sensitive' => true])['matched']);
        $this->assertFalse($comparator->matches($ref, $subUnordered, ['column_name_sensitive' => true])['matched']);

        // NULL vs empty string distinction
        $refNull = ['columns' => ['v'], 'rows' => [['v' => null]]];
        $subEmpty = ['columns' => ['v'], 'rows' => [['v' => '']]];
        $this->assertFalse($comparator->matches($refNull, $subEmpty, [])['matched']);
    }

    /** TM-7 | FR-7 | override without reason rejected 422 */
    public function test_overriding_auto_graded_answer_requires_override_reason(): void
    {
        ['examiner' => $examiner, 'attempt' => $attempt, 'qSql' => $qSql] = $this->submitMixedAttempt();
        $answer = $attempt->answers()->where('question_id', $qSql->id)->firstOrFail();

        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/attempts/{$attempt->id}/answers/{$answer->id}/grade", [
                'manual_score' => 9.5,
                'examiner_feedback' => 'Partial credit override',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['override_reason']]);
    }

    /** TM-19, TM-20 | FR-7, FR-8 | full grading accuracy across SQL SELECT/DML/DDL, partial credit, hybrid/manual, override, finalize */
    public function test_outcome_based_sql_grading_partial_credit_manual_rubric_override_and_finalize(): void
    {
        ['examiner' => $examiner, 'exam' => $exam, 'attempt' => $attempt, 'qSql' => $qSql, 'qDml' => $qDml, 'qDdl' => $qDdl, 'qShort' => $qShort] =
            $this->submitMixedAttempt();

        $attempt->refresh();
        $this->assertSame('needs_review', $attempt->grading_status->value);

        // qSql: student used equivalent CTE instead of plain SELECT, passed base (6 pts) AND hidden override_seed_sql (4 pts) = 10 pts
        $sqlAns = $attempt->answers()->where('question_id', $qSql->id)->firstOrFail();
        $this->assertSame('10.00', $sqlAns->auto_score);

        // qDml: post-state assertion_sql passed = 10 pts
        $dmlAns = $attempt->answers()->where('question_id', $qDml->id)->firstOrFail();
        $this->assertSame('10.00', $dmlAns->auto_score);

        // qDdl: expect_error test case + assertion_sql passed = 10 pts
        $ddlAns = $attempt->answers()->where('question_id', $qDdl->id)->firstOrFail();
        $this->assertSame('10.00', $ddlAns->auto_score);

        // Finalizing before grading short_answer fails 422
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/attempts/{$attempt->id}/finalize")
            ->assertStatus(422);

        // Grade short_answer via rubric
        $shortAns = $attempt->answers()->where('question_id', $qShort->id)->firstOrFail();
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/attempts/{$attempt->id}/answers/{$shortAns->id}/grade", [
                'manual_score' => 8,
                'rubric_scores' => [['id' => 'r1', 'points' => 8]],
                'examiner_feedback' => 'Clear explanation of normalization.',
            ])
            ->assertOk()
            ->assertJsonPath('data.final_score', '8.00')
            ->assertJsonPath('data.grading_status', 'graded');

        // Override qSql auto-graded score with mandatory override_reason, preserving auto_score = 10.00
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/attempts/{$attempt->id}/answers/{$sqlAns->id}/grade", [
                'manual_score' => 9,
                'override_reason' => 'Minor style deduction per syllabus note',
                'examiner_feedback' => 'Correct output; avoid unnecessary CTE.',
            ])
            ->assertOk()
            ->assertJsonPath('data.auto_score', '10.00')
            ->assertJsonPath('data.manual_score', '9.00')
            ->assertJsonPath('data.final_score', '9.00');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'grading.score_overridden',
            'target_id' => $sqlAns->id,
        ]);

        // Finalize attempt
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/attempts/{$attempt->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'finalized')
            ->assertJsonPath('data.final_score', '37.00')
            ->assertJsonPath('data.passed', true);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'attempt.finalized',
            'target_id' => $attempt->id,
        ]);

        // F-3: Post-finalization grade edit below passing threshold must recompute `passed` -> false
        foreach ([$qSql, $qDml, $qDdl] as $autoQ) {
            $ans = $attempt->answers()->where('question_id', $autoQ->id)->firstOrFail();
            $this->actingAs($examiner, 'sanctum')
                ->patchJson("/api/examiner/attempts/{$attempt->id}/answers/{$ans->id}/grade", [
                    'manual_score' => 0,
                    'override_reason' => 'Post-finalization review zeroed',
                ])
                ->assertOk();
        }

        $attempt->refresh();
        $this->assertSame('8.00', $attempt->final_score);
        $this->assertFalse($attempt->passed);
    }

    /** TM-19 | FR-7 | hardcoded answer fails hidden test case and earns partial credit only */
    public function test_hardcoded_sql_answer_earns_partial_credit_only_when_hidden_seed_differs(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE items (id INT PRIMARY KEY, price DECIMAL(8,2));',
            'seed_sql' => 'INSERT INTO items VALUES (1, 10.00), (2, 20.00);',
        ]);
        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create(['status' => 'draft']);
        $q = ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'SELECT SUM(price) AS total FROM items',
        ]);
        QuestionTestCase::factory()->for($q, 'question')->create([
            'position' => 1,
            'name' => 'Base seed',
            'weight_points' => 4,
            'is_hidden' => false,
        ]);
        QuestionTestCase::factory()->for($q, 'question')->create([
            'position' => 2,
            'name' => 'Alternate seed',
            'weight_points' => 6,
            'is_hidden' => true,
            'override_seed_sql' => 'INSERT INTO items VALUES (1, 50.00), (2, 50.00);',
        ]);
        $exam->update(['total_points' => 10]);

        $this->actingAs($examiner, 'sanctum')->postJson("/api/examiner/exams/{$exam->id}/publish")->assertOk();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'PART2345']);
        $assign = $this->actingAs($examiner, 'sanctum')->postJson("/api/examiner/exams/{$exam->id}/classrooms", ['classroom_id' => $classroom->id])->assertStatus(201);
        $ce = ClassroomExam::findOrFail($assign->json('data.id'));

        $join = $this->postJson('/api/student/join', ['display_name' => 'Cheater', 'class_code' => 'PART2345'])->assertStatus(201);
        $cookie = $join->getCookie('student_session_id', false)->getValue();
        $attemptId = $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->postJson("/api/student/exams/{$ce->id}/start")->assertStatus(201)->json('data.id');

        // Hardcoded 30.00 passes Base seed (4 pts) and fails Alternate seed (0 pts)
        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$q->id}", ['submitted_sql' => 'SELECT 30.00 AS total'])
            ->assertOk();

        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'finalized')
            ->assertJsonMissingPath('data.final_score');

        $this->assertSame('4.00', ExamAttempt::findOrFail($attemptId)->final_score);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitMixedAttempt(): array
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE employees (id INT PRIMARY KEY, name VARCHAR(50), dept VARCHAR(30));',
            'seed_sql' => "INSERT INTO employees VALUES (1, 'Ada', 'Eng'), (2, 'Bob', 'Sales');",
        ]);
        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'passing_score_percent' => 60,
        ]);

        $qSql = ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => "SELECT name FROM employees WHERE dept = 'Eng'",
        ]);
        QuestionTestCase::factory()->for($qSql, 'question')->create([
            'position' => 1,
            'name' => 'Visible',
            'weight_points' => 6,
            'is_hidden' => false,
        ]);
        QuestionTestCase::factory()->for($qSql, 'question')->create([
            'position' => 2,
            'name' => 'Hidden override',
            'weight_points' => 4,
            'is_hidden' => true,
            'override_seed_sql' => "INSERT INTO employees VALUES (10, 'Linus', 'Eng');",
        ]);

        $qDml = ExamQuestion::factory()->for($exam)->create([
            'position' => 2,
            'question_type' => QuestionType::SqlDml,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => "UPDATE employees SET dept = 'Platform' WHERE id = 1;",
        ]);
        QuestionTestCase::factory()->for($qDml, 'question')->create([
            'position' => 1,
            'name' => 'Post-update check',
            'weight_points' => 10,
            'assertion_sql' => 'SELECT id, dept FROM employees ORDER BY id',
            'expected_behavior' => 'match_reference',
        ]);

        $qDdl = ExamQuestion::factory()->for($exam)->create([
            'position' => 3,
            'question_type' => QuestionType::SqlDdl,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'CREATE TABLE tags (id INT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE);',
        ]);
        QuestionTestCase::factory()->for($qDdl, 'question')->create([
            'position' => 1,
            'name' => 'Table exists',
            'weight_points' => 5,
            'assertion_sql' => "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tags'",
            'expected_behavior' => 'match_reference',
        ]);
        QuestionTestCase::factory()->for($qDdl, 'question')->create([
            'position' => 2,
            'name' => 'Reject duplicate tag',
            'weight_points' => 5,
            'assertion_sql' => "INSERT INTO tags VALUES (1, 'sql'), (2, 'sql');",
            'expected_behavior' => 'expect_error',
        ]);

        $qShort = ExamQuestion::factory()->for($exam)->create([
            'position' => 4,
            'question_type' => QuestionType::ShortAnswer,
            'grading_mode' => 'manual',
            'points' => 10,
            'reference_sql' => null,
            'rubric' => [['id' => 'r1', 'label' => 'Clarity', 'max_points' => 10, 'description' => 'Clear explanation']],
        ]);

        $exam->update(['total_points' => 40]);

        $this->actingAs($examiner, 'sanctum')->postJson("/api/examiner/exams/{$exam->id}/publish")->assertOk();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'GRAD2345']);
        $assign = $this->actingAs($examiner, 'sanctum')->postJson("/api/examiner/exams/{$exam->id}/classrooms", ['classroom_id' => $classroom->id])->assertStatus(201);
        $ce = ClassroomExam::findOrFail($assign->json('data.id'));

        $join = $this->postJson('/api/student/join', ['display_name' => 'Graded Student', 'class_code' => 'GRAD2345'])->assertStatus(201);
        $cookie = $join->getCookie('student_session_id', false)->getValue();
        $attemptId = $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->postJson("/api/student/exams/{$ce->id}/start")->assertStatus(201)->json('data.id');

        // Equivalent CTE query
        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => "WITH eng AS (SELECT name FROM employees WHERE dept = 'Eng') SELECT name FROM eng",
            ])->assertOk();

        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qDml->id}", [
                'submitted_sql' => "UPDATE employees SET dept = 'Platform' WHERE name = 'Ada';",
            ])->assertOk();

        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qDdl->id}", [
                'submitted_sql' => 'CREATE TABLE tags (id INT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE);',
            ])->assertOk();

        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qShort->id}", [
                'submitted_text' => '3NF removes transitive dependencies.',
            ])->assertOk();

        $this->withCredentials()->withUnencryptedCookie('student_session_id', $cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")->assertOk();

        return [
            'examiner' => $examiner,
            'exam' => $exam,
            'attempt' => ExamAttempt::findOrFail($attemptId),
            'qSql' => $qSql,
            'qDml' => $qDml,
            'qDdl' => $qDdl,
            'qShort' => $qShort,
        ];
    }

    /** PR#4 Blocking #1, Codex P1, & Medium | defense-in-depth DML assertion guard, truncated result fail-closed, in_progress grade guard */
    public function test_grading_fails_closed_for_dml_missing_assertion_and_truncated_results_and_blocks_in_progress_grade(): void
    {
        $examiner = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($examiner, 'examiner')->create(['status' => 'published', 'current_version_number' => 1]);
        $qDmlId = (string) Str::uuid();
        $qTruncId = (string) Str::uuid();

        $seqSql = <<<'SQL'
WITH RECURSIVE seq AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 550
)
SELECT n FROM seq
SQL;
        $studentBigSql = <<<'SQL'
WITH RECURSIVE seq AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 600
)
SELECT n FROM seq
SQL;

        $version = ExamVersion::create([
            'exam_id' => $exam->id,
            'version_number' => 1,
            'published_by' => $examiner->id,
            'published_at' => now(),
            'snapshot' => [
                'exam_id' => $exam->id,
                'title' => 'Defense Check Exam',
                'duration_minutes' => 60,
                'passing_score_percent' => 60,
                'total_points' => 20,
                'settings' => ['allow_query_preview' => true],
                'dataset' => [
                    'schema_sql' => 'CREATE TABLE employees (id INT PRIMARY KEY, dept VARCHAR(40));',
                    'seed_sql' => "INSERT INTO employees VALUES (1, 'Eng');",
                ],
                'questions' => [
                    [
                        'id' => $qDmlId,
                        'position' => 1,
                        'question_type' => 'sql_dml',
                        'grading_mode' => 'auto',
                        'title' => 'DML missing assertion',
                        'prompt' => 'Update row 1',
                        'points' => 10,
                        'reference_sql' => "UPDATE employees SET dept = 'Ops' WHERE id = 1;",
                        'comparison_config' => [],
                        'test_cases' => [
                            [
                                'id' => (string) Str::uuid(),
                                'name' => 'No assertion',
                                'weight_points' => 10,
                                'is_hidden' => false,
                                'override_seed_sql' => null,
                                'assertion_sql' => null,
                                'expected_behavior' => 'match_reference',
                            ],
                        ],
                    ],
                    [
                        'id' => $qTruncId,
                        'position' => 2,
                        'question_type' => 'sql_select',
                        'grading_mode' => 'auto',
                        'title' => 'Truncated prefix check',
                        'prompt' => 'Return sequence',
                        'points' => 10,
                        'reference_sql' => $seqSql,
                        'comparison_config' => [],
                        'test_cases' => [],
                    ],
                ],
            ],
        ]);

        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['is_open' => true]);
        $ce = ClassroomExam::factory()->for($classroom)->for($exam)->for($version, 'examVersion')->for($examiner, 'assigner')->create();
        $session = StudentSession::factory()->for($classroom)->create();

        $attempt = ExamAttempt::create([
            'classroom_exam_id' => $ce->id,
            'exam_version_id' => $version->id,
            'student_session_id' => $session->id,
            'status' => 'in_progress',
            'grading_status' => 'pending',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'question_order' => [$qDmlId, $qTruncId],
            'max_score' => 20,
        ]);

        $ansDml = AttemptAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $qDmlId,
            'submitted_sql' => 'UPDATE employees SET dept = dept WHERE 1 = 0;',
            'max_points' => 10,
            'grading_status' => 'unanswered',
            'final_score' => 0,
            'test_results' => [],
        ]);
        AttemptAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $qTruncId,
            'submitted_sql' => $studentBigSql,
            'max_points' => 10,
            'grading_status' => 'unanswered',
            'final_score' => 0,
            'test_results' => [],
        ]);

        // Grading in_progress attempt via API must return 409 attempt_still_in_progress
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/attempts/{$attempt->id}/answers/{$ansDml->id}/grade", [
                'manual_score' => 5,
                'override_reason' => 'Too early',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'attempt_still_in_progress');

        $attempt->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        $graded = app(ExamGradingService::class)->gradeAttempt($attempt);

        $this->assertSame('0.00', $graded->answers->firstWhere('question_id', $qDmlId)->auto_score);
        $truncAns = $graded->answers->firstWhere('question_id', $qTruncId);
        $this->assertSame('0.00', $truncAns->auto_score);
        $this->assertStringContainsString('truncated', strtolower((string) ($truncAns->test_results[0]['message'] ?? '')));
    }
}
