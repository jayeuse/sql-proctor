<?php

namespace Tests\Feature\Student;

use App\Enums\QuestionType;
use App\Models\Classroom;
use App\Models\ClassroomExam;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\QuestionOption;
use App\Models\QuestionTestCase;
use App\Models\SqlDataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class StudentExamFlowTest extends TestCase
{
    use RefreshDatabase;

    /** TM-4 | FR-6 | security violation (strict anti-leakage) */
    public function test_student_payloads_never_expose_reference_sql_hidden_tests_or_mcq_keys(): void
    {
        ['cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql, 'qMcq' => $qMcq, 'optCorrect' => $optCorrect] =
            $this->seedAssignedExamScenario(immediateAutoResults: true);

        $examsRes = $this->withStudentCookie($cookie)->getJson('/api/student/exams')->assertOk();
        $this->assertNoSecretLeaks($examsRes->getContent());

        $startRes = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201);
        $this->assertNoSecretLeaks($startRes->getContent());

        $attemptId = $startRes->json('data.id');

        $attemptRes = $this->withStudentCookie($cookie)->getJson("/api/student/attempts/{$attemptId}")->assertOk();
        $this->assertNoSecretLeaks($attemptRes->getContent());

        $runRes = $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", [
                'sql' => 'SELECT name FROM employees',
            ])
            ->assertOk();
        $this->assertNoSecretLeaks($runRes->getContent());

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qMcq->id}", [
                'selected_option_ids' => [$optCorrect->id],
            ])
            ->assertOk();

        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk();

        $resultRes = $this->withStudentCookie($cookie)
            ->getJson("/api/student/attempts/{$attemptId}/result")
            ->assertOk();
        $this->assertNoSecretLeaks($resultRes->getContent());
    }

    /** TM-5 | FR-6 | unauthorized access */
    public function test_unauthenticated_requests_to_student_exam_routes_return_401(): void
    {
        $uuid = (string) Str::uuid();
        $this->getJson('/api/student/exams')->assertStatus(401);
        $this->postJson("/api/student/exams/{$uuid}/start")->assertStatus(401);
        $this->getJson("/api/student/attempts/{$uuid}")->assertStatus(401);
        $this->putJson("/api/student/attempts/{$uuid}/answers/{$uuid}", [])->assertStatus(401);
        $this->postJson("/api/student/attempts/{$uuid}/questions/{$uuid}/run", ['sql' => 'SELECT 1'])->assertStatus(401);
        $this->postJson("/api/student/attempts/{$uuid}/submit")->assertStatus(401);
        $this->getJson("/api/student/attempts/{$uuid}/result")->assertStatus(401);
    }

    /** TM-6 | FR-6 | forbidden access */
    public function test_student_b_cannot_read_save_run_submit_or_view_results_for_student_a_attempt(): void
    {
        ['cookie' => $cookieA, 'classroom' => $classroom, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $attemptId = $this->withStudentCookie($cookieA)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        $joinB = $this->postJson('/api/student/join', [
            'display_name' => 'Student B',
            'class_code' => $classroom->join_code,
        ])->assertStatus(201);
        $cookieB = $joinB->getCookie('student_session_id', false)->getValue();

        $this->withStudentCookie($cookieB)->getJson("/api/student/attempts/{$attemptId}")->assertStatus(403);
        $this->withStudentCookie($cookieB)->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", ['submitted_sql' => 'SELECT 1'])->assertStatus(403);
        $this->withStudentCookie($cookieB)->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", ['sql' => 'SELECT 1'])->assertStatus(403);
        $this->withStudentCookie($cookieB)->postJson("/api/student/attempts/{$attemptId}/submit")->assertStatus(403);
        $this->withStudentCookie($cookieB)->getJson("/api/student/attempts/{$attemptId}/result")->assertStatus(403);
    }

    /** TM-7, TM-9 | FR-6 | expired auto-submit, post-submit lock, and idempotent start */
    public function test_expired_attempt_auto_submits_and_blocks_subsequent_edits_or_runs(): void
    {
        ['cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $firstStart = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201);
        $attemptId = $firstStart->json('data.id');

        // Idempotent resume on second start call returns 200 with same ID
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertOk()
            ->assertJsonPath('data.id', $attemptId);

        // Save an answer before expiration
        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        // Simulate server-side expiry
        ExamAttempt::whereKey($attemptId)->update(['expires_at' => now()->subMinute()]);

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT 1',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'attempt_expired');

        $attempt = ExamAttempt::findOrFail($attemptId);
        $this->assertSame('auto_submitted', $attempt->status->value);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.exam_auto_submitted',
            'target_id' => $attemptId,
        ]);

        // Running query or starting again after submission returns 409
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", ['sql' => 'SELECT 1'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'attempt_not_active');

        $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('code', 'attempt_already_submitted');
    }

    /** TM-8 | FR-6, FR-7, FR-8 | per-question draft save vs confirm submit lock and result labels */
    public function test_student_can_submit_individual_answer_and_subsequent_edits_or_runs_are_locked(): void
    {
        ['cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql, 'qMcq' => $qMcq, 'optCorrect' => $optCorrect] =
            $this->seedAssignedExamScenario(immediateAutoResults: true);

        $attemptId = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        // Draft save keeps question unlocked
        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT 1',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', false)
            ->assertJsonPath('data.submitted_at', null);

        // Explicit individual submit locks Q1 and writes audit event
        $submitAnswerRes = $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
                'submit_answer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', true)
            ->assertJsonPath('data.submitted_sql', 'SELECT name FROM employees ORDER BY id');

        $this->assertNotNull($submitAnswerRes->json('data.submitted_at'));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.answer_submitted',
            'target_id' => $attemptId,
        ]);

        // Reload attempt preserves submitted answer and lock state while sibling question stays unlocked
        $attemptRes = $this->withStudentCookie($cookie)
            ->getJson("/api/student/attempts/{$attemptId}")
            ->assertOk();

        $questions = collect($attemptRes->json('data.questions'));
        $q1Payload = $questions->firstWhere('id', $qSql->id);
        $this->assertTrue($q1Payload['saved_answer']['is_submitted']);
        $this->assertNotNull($q1Payload['saved_answer']['submitted_at']);
        $this->assertSame('SELECT name FROM employees ORDER BY id', $q1Payload['saved_answer']['submitted_sql']);

        // Subsequent draft save / overwrite on submitted question returns 409 answer_locked without mutating stored answer
        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT 999',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'answer_locked');

        // Subsequent SQL execution on submitted question returns 409 answer_locked
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", [
                'sql' => 'SELECT 1',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'answer_locked');

        $this->assertDatabaseHas('attempt_answers', [
            'attempt_id' => $attemptId,
            'question_id' => $qSql->id,
            'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
        ]);

        // Unsubmitted sibling MCQ can still be drafted then submitted independently
        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qMcq->id}", [
                'selected_option_ids' => [$optCorrect->id],
                'submit_answer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', false);

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qMcq->id}", [
                'selected_option_ids' => [$optCorrect->id],
                'submit_answer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_submitted', true);

        // Final exam submit grades locked answers properly and result includes selected option labels cleanly
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $resultRes = $this->withStudentCookie($cookie)
            ->getJson("/api/student/attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonPath('data.final_score', '15.00');

        $mcqResult = collect($resultRes->json('data.questions'))->firstWhere('question_id', $qMcq->id);
        $this->assertSame($optCorrect->label, $mcqResult['selected_options'][0]['label'] ?? null);
        $this->assertNoSecretLeaks($resultRes->getContent());
    }

    /** TM-11 | FR-6 | saveAnswer locks ExamAttempt row atomically to prevent race overwrites against submit */
    public function test_save_answer_acquires_attempt_row_lock_inside_transaction(): void
    {
        ['cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $attemptId = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->withStudentCookie($cookie)
                ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                    'submitted_sql' => 'SELECT name FROM employees',
                ])
                ->assertOk();
            $queries = array_map(
                static fn (array $entry): string => strtolower((string) ($entry['query'] ?? '')),
                DB::getQueryLog(),
            );
        } finally {
            DB::disableQueryLog();
        }

        $hasAttemptLock = false;
        foreach ($queries as $sql) {
            if (str_contains($sql, 'exam_attempts') && str_contains($sql, 'for update')) {
                $hasAttemptLock = true;
                break;
            }
        }

        $this->assertTrue($hasAttemptLock, 'Expected saveAnswer() to lock exam_attempts FOR UPDATE inside transaction.');
    }

    /** TM-10 | FR-5, FR-6 | rate limit & concurrent intra-attempt run lock */
    public function test_student_query_run_rate_limit_and_concurrent_attempt_lock(): void
    {
        ['cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $attemptId = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        // Pre-acquired attempt lock simulates concurrent query execution
        $lock = Cache::lock("sandbox:attempt:{$attemptId}", 10);
        $this->assertTrue($lock->get());
        try {
            $this->withStudentCookie($cookie)
                ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", ['sql' => 'SELECT 1'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'execution_in_progress');
        } finally {
            $lock->release();
        }

        // Exceed 20 runs/minute limit
        for ($i = 0; $i < 20; $i++) {
            $this->withStudentCookie($cookie)
                ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", ['sql' => 'SELECT 1'])
                ->assertOk();
        }

        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", ['sql' => 'SELECT 1'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_attempts');
    }

    /** TM-11 | FR-4 | graceful vs force-close unpublish */
    public function test_graceful_unpublish_allows_active_submit_while_force_close_locks_attempt(): void
    {
        ['examiner' => $examiner, 'exam' => $exam, 'cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $attemptId = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        // Graceful unpublish allows existing attempt to save & submit
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/unpublish", ['force_close_active' => false])
            ->assertOk();

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        // Active student 1 can still submit even after graceful unpublish
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk();

        // Republish exam and have a second student start before force-close
        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertOk();

        $join2 = $this->withUnencryptedCookie('student_session_id', '')
            ->postJson('/api/student/join', [
                'display_name' => 'Student Two',
                'class_code' => $classroomExam->classroom->join_code,
            ])->assertStatus(201);
        $cookie2 = $join2->getCookie('student_session_id', false)->getValue();
        $attempt2Id = $this->withStudentCookie($cookie2)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        $this->withStudentCookie($cookie2)
            ->putJson("/api/student/attempts/{$attempt2Id}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/unpublish", ['force_close_active' => true])
            ->assertOk();

        $this->assertSame('force_closed', ExamAttempt::findOrFail($attempt2Id)->status->value);
        $this->withStudentCookie($cookie2)
            ->postJson("/api/student/attempts/{$attempt2Id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('code', 'attempt_not_active');
    }

    /** TM-18 | FR-6, FR-8 | normal behavior */
    public function test_student_discovers_runs_saves_submits_and_views_pending_then_released_results(): void
    {
        ['examiner' => $examiner, 'exam' => $exam, 'classroom' => $classroom, 'cookie' => $cookie, 'classroomExam' => $classroomExam, 'qSql' => $qSql, 'qMcq' => $qMcq, 'optCorrect' => $optCorrect] =
            $this->seedAssignedExamScenario(immediateAutoResults: false);

        $this->withStudentCookie($cookie)
            ->getJson('/api/student/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $classroomExam->id);

        $start = $this->withStudentCookie($cookie)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201);
        $attemptId = $start->json('data.id');
        $this->assertNotEmpty($start->json('data.schema_summary.tables'));

        // Run valid query against live MariaDB
        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/questions/{$qSql->id}/run", [
                'sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.rows.0.name', 'Ada');

        // Save and submit
        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])->assertOk();

        $this->withStudentCookie($cookie)
            ->putJson("/api/student/attempts/{$attemptId}/answers/{$qMcq->id}", [
                'selected_option_ids' => [$optCorrect->id],
            ])->assertOk();

        $this->withStudentCookie($cookie)
            ->postJson("/api/student/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        // Before results_released_at, scores remain hidden
        $this->withStudentCookie($cookie)
            ->getJson("/api/student/attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonPath('data.released', false)
            ->assertJsonMissingPath('data.final_score');

        // Examiner releases results
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/exams/{$exam->id}/classrooms/{$classroom->id}", [
                'release_results' => true,
            ])
            ->assertOk();

        $this->withStudentCookie($cookie)
            ->getJson("/api/student/attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonPath('data.released', true)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.final_score', '15.00');
    }

    /**
     * @return array<string, mixed>
     */
    private function seedAssignedExamScenario(bool $immediateAutoResults = false): array
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE employees (id INT PRIMARY KEY, name VARCHAR(80), role VARCHAR(40));',
            'seed_sql' => "INSERT INTO employees VALUES (1, 'Ada', 'eng'), (2, 'Grace', 'eng');",
            'schema_summary' => [
                'tables' => [['name' => 'employees', 'row_count' => 2, 'columns' => [['name' => 'id', 'type' => 'int', 'nullable' => false, 'key' => 'PRI', 'default' => null]], 'foreign_keys' => []]],
            ],
            'validated_at' => now(),
        ]);

        $exam = Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create([
            'status' => 'draft',
            'duration_minutes' => 30,
            'passing_score_percent' => 60,
            'settings' => [
                'allow_query_preview' => true,
                'shuffle_questions' => false,
                'show_points' => true,
                'immediate_auto_results' => $immediateAutoResults,
            ],
        ]);

        $qSql = ExamQuestion::factory()->for($exam)->create([
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => 'auto',
            'points' => 10,
            'reference_sql' => 'SELECT name FROM employees ORDER BY id',
        ]);
        QuestionTestCase::factory()->for($qSql, 'question')->create([
            'position' => 1,
            'name' => 'Visible base case',
            'weight_points' => 5,
            'is_hidden' => false,
        ]);
        QuestionTestCase::factory()->for($qSql, 'question')->create([
            'position' => 2,
            'name' => 'Secret anti-hardcode check',
            'weight_points' => 5,
            'is_hidden' => true,
            'override_seed_sql' => "INSERT INTO employees VALUES (1, 'Alan', 'eng'), (2, 'Katherine', 'eng');",
        ]);

        $qMcq = ExamQuestion::factory()->for($exam)->create([
            'position' => 2,
            'question_type' => QuestionType::Mcq,
            'grading_mode' => 'auto',
            'points' => 5,
            'reference_sql' => null,
        ]);
        $optCorrect = QuestionOption::factory()->for($qMcq, 'question')->create([
            'position' => 1,
            'label' => 'SECRET_TRUE_OPTION',
            'is_correct' => true,
        ]);
        QuestionOption::factory()->for($qMcq, 'question')->create([
            'position' => 2,
            'label' => 'Wrong option',
            'is_correct' => false,
        ]);

        $exam->update(['total_points' => 15]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/publish")
            ->assertOk();

        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['is_open' => true, 'join_code' => 'EXAM2345']);
        $assign = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/exams/{$exam->id}/classrooms", ['classroom_id' => $classroom->id])
            ->assertStatus(201);

        $classroomExam = ClassroomExam::findOrFail($assign->json('data.id'));

        $join = $this->postJson('/api/student/join', [
            'display_name' => 'Student One',
            'class_code' => 'EXAM2345',
        ])->assertStatus(201);

        return [
            'examiner' => $examiner,
            'dataset' => $dataset,
            'exam' => $exam->fresh(),
            'classroom' => $classroom,
            'classroomExam' => $classroomExam,
            'qSql' => $qSql,
            'qMcq' => $qMcq,
            'optCorrect' => $optCorrect,
            'cookie' => $join->getCookie('student_session_id', false)->getValue(),
        ];
    }

    /** PR#4 Blocking #3 | Two-phase commit durably persists submitted/force_closed state even when MariaDB is down */
    public function test_submit_and_force_unpublish_commit_durably_when_mariadb_is_unavailable(): void
    {
        ['exam' => $exam, 'cookie' => $cookie1, 'classroom' => $classroom, 'classroomExam' => $classroomExam, 'qSql' => $qSql] =
            $this->seedAssignedExamScenario();

        $attempt1Id = $this->withStudentCookie($cookie1)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        $this->withStudentCookie($cookie1)
            ->putJson("/api/student/attempts/{$attempt1Id}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        $join2 = $this->withUnencryptedCookie('student_session_id', '')
            ->postJson('/api/student/join', [
                'display_name' => 'Student Outage Two',
                'class_code' => $classroom->join_code,
            ])->assertStatus(201);
        $cookie2 = $join2->getCookie('student_session_id', false)->getValue();
        $attempt2Id = $this->withStudentCookie($cookie2)
            ->postJson("/api/student/exams/{$classroomExam->id}/start")
            ->assertStatus(201)
            ->json('data.id');

        $this->withStudentCookie($cookie2)
            ->putJson("/api/student/attempts/{$attempt2Id}/answers/{$qSql->id}", [
                'submitted_sql' => 'SELECT name FROM employees ORDER BY id',
            ])
            ->assertOk();

        config(['database.connections.mariadb_provisioner.port' => '39999']);
        config(['database.connections.mariadb_runner.port' => '39999']);
        DB::purge('mariadb_provisioner');
        DB::purge('mariadb_runner');

        try {
            $this->withStudentCookie($cookie1)
                ->postJson("/api/student/attempts/{$attempt1Id}/submit")
                ->assertOk()
                ->assertJsonPath('data.status', 'submitted')
                ->assertJsonPath('data.grading_status', 'pending');

            $admin = User::factory()->admin()->create();
            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/exams/{$exam->id}/force-unpublish", [
                    'reason' => 'Emergency outage test',
                    'force_close_active' => true,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', 'unpublished');

            $attempt2 = ExamAttempt::findOrFail($attempt2Id);
            $this->assertSame('force_closed', $attempt2->status->value);
            $this->assertSame('pending', $attempt2->grading_status->value);
        } finally {
            DB::purge('mariadb_provisioner');
            DB::purge('mariadb_runner');
        }
    }

    private function withStudentCookie(string $cookieValue): self
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookieValue);
    }

    private function assertNoSecretLeaks(?string $rawJson): void
    {
        $raw = (string) $rawJson;
        $this->assertStringNotContainsString('reference_sql', $raw);
        $this->assertStringNotContainsString('override_seed_sql', $raw);
        $this->assertStringNotContainsString('assertion_sql', $raw);
        $this->assertStringNotContainsString('is_correct', $raw);
        $this->assertStringNotContainsString('Alan', $raw);
        $this->assertStringNotContainsString('Katherine', $raw);
        $this->assertStringNotContainsString('Secret anti-hardcode check', $raw);
    }
}
