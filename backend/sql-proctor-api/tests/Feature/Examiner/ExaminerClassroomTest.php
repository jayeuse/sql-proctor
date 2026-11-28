<?php

namespace Tests\Feature\Examiner;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExaminerClassroomTest extends TestCase
{
    use RefreshDatabase;

    /** TM-5 | FR-7 | unauthorized access */
    public function test_unauthenticated_requests_to_examiner_classes_return_401(): void
    {
        $this->getJson('/api/examiner/classes')->assertStatus(401);
        $this->postJson('/api/examiner/classes', ['name' => 'CS 101'])->assertStatus(401);
    }

    /** TM-7 | FR-7 | forbidden access */
    public function test_admin_cannot_access_examiner_classes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/examiner/classes')
            ->assertStatus(403);
    }

    /** TM-1 | FR-7 | invalid input */
    public function test_create_classroom_validates_name_and_expiry(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/examiner/classes', [
                'name' => '',
                'code_expires_at' => '2020-01-01T00:00:00Z',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name', 'code_expires_at']]);
    }

    /** TM-9 | FR-7 | forbidden access */
    public function test_examiner_cannot_view_or_mutate_another_examiners_classroom(): void
    {
        $owner = User::factory()->examiner()->create();
        $other = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($owner, 'examiner')->create();

        $this->actingAs($other, 'sanctum')->getJson("/api/examiner/classes/{$classroom->id}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->postJson("/api/examiner/classes/{$classroom->id}/close")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->postJson("/api/examiner/classes/{$classroom->id}/open")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->postJson("/api/examiner/classes/{$classroom->id}/regenerate-code")->assertStatus(403);
    }

    /** TM-12 | FR-7 | missing resource */
    public function test_unknown_classroom_returns_generic_404(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/examiner/classes/'.Str::uuid())
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /** TM-26 | FR-7 | normal behavior */
    public function test_examiner_can_create_list_close_open_and_regenerate_classroom_codes(): void
    {
        $examiner = User::factory()->examiner()->create(['username' => 'prof_sql']);

        $created = $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/examiner/classes', [
                'name' => 'Database Systems Section A',
            ]);

        $created->assertStatus(201)
            ->assertJsonPath('data.name', 'Database Systems Section A')
            ->assertJsonPath('data.is_open', true);

        $classId = $created->json('data.id');
        $joinCode = (string) $created->json('data.join_code');
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6,8}$/', $joinCode);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'class.created',
            'target_type' => 'classroom',
            'target_id' => $classId,
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/examiner/classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $classId);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/classes/{$classId}/close")
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'class.closed',
            'target_id' => $classId,
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/classes/{$classId}/open")
            ->assertOk()
            ->assertJsonPath('data.is_open', true);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'class.opened',
            'target_id' => $classId,
        ]);

        $regen = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/classes/{$classId}/regenerate-code");
        $regen->assertOk();

        $newCode = (string) $regen->json('data.join_code');
        $this->assertNotSame($joinCode, $newCode);
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6,8}$/', $newCode);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'class.code_regenerated',
            'target_id' => $classId,
        ]);
    }
}
