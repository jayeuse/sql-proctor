<?php

namespace Tests\Feature\Admin;

use App\Models\Classroom;
use App\Models\ClassroomExam;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamVersion;
use App\Models\StudentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class AdminExamOversightTest extends TestCase
{
    use RefreshDatabase;

    /** TM-6 | FR-4 | forbidden access */
    public function test_examiner_cannot_access_admin_exams_or_force_unpublish(): void
    {
        $examiner = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($examiner, 'examiner')->create(['status' => 'published']);

        $this->actingAs($examiner, 'sanctum')->getJson('/api/admin/exams')->assertStatus(403);
        $this->actingAs($examiner, 'sanctum')->postJson("/api/admin/exams/{$exam->id}/force-unpublish", ['reason' => 'Test'])->assertStatus(403);
    }

    /** TM-1 | FR-4 | invalid input */
    public function test_admin_force_unpublish_requires_audit_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $examiner = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($examiner, 'examiner')->create(['status' => 'published']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/exams/{$exam->id}/force-unpublish", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['reason']]);
    }

    /** TM-21 | FR-4, FR-8 | normal behavior */
    public function test_admin_can_list_inspect_and_force_unpublish_exams_and_manage_shared_datasets(): void
    {
        $admin = User::factory()->admin()->create();
        $examiner = User::factory()->examiner()->create();
        $exam = Exam::factory()->for($examiner, 'examiner')->create(['status' => 'published', 'current_version_number' => 1]);
        $version = ExamVersion::factory()->for($exam)->for($examiner, 'publisher')->create(['version_number' => 1]);
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create();
        $ce = ClassroomExam::factory()->for($classroom)->for($exam)->for($version, 'examVersion')->for($examiner, 'assigner')->create();
        $session = StudentSession::factory()->for($classroom)->create();
        $attempt = ExamAttempt::factory()->create([
            'classroom_exam_id' => $ce->id,
            'exam_version_id' => $version->id,
            'student_session_id' => $session->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exam->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $exam->id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/exams/{$exam->id}/force-unpublish", [
                'reason' => 'Question leak reported during live cohort.',
                'force_close_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished');

        $this->assertSame('force_closed', $attempt->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'exam.force_unpublished',
            'target_id' => $exam->id,
        ]);

        // Admin creates shared dataset readable by examiner
        $ds = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/datasets', [
                'name' => 'Institutional Store Dataset',
                'schema_sql' => 'CREATE TABLE orders (id INT PRIMARY KEY);',
                'seed_sql' => 'INSERT INTO orders VALUES (1);',
                'is_shared' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.is_shared', true);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/datasets/'.$ds->json('data.id').'/validate')
            ->assertOk()
            ->assertJsonPath('data.schema_summary.tables.0.name', 'orders');
    }
}
