<?php

namespace Tests\Feature\Examiner;

use App\Models\Exam;
use App\Models\SqlDataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class DatasetAuthoringTest extends TestCase
{
    use RefreshDatabase;

    /** TM-1 | FR-1 | invalid input */
    public function test_dataset_creation_validates_required_fields(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/examiner/datasets', [
                'name' => '',
                'schema_sql' => '',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name', 'schema_sql']]);
    }

    /** TM-5 | FR-1 | unauthorized access */
    public function test_unauthenticated_requests_to_datasets_return_401(): void
    {
        $this->getJson('/api/examiner/datasets')->assertStatus(401);
        $this->postJson('/api/examiner/datasets', [])->assertStatus(401);
    }

    /** TM-6 | FR-1 | forbidden access */
    public function test_examiner_cannot_mutate_private_or_shared_datasets_they_do_not_own(): void
    {
        $owner = User::factory()->examiner()->create();
        $other = User::factory()->examiner()->create();

        $privateDataset = SqlDataset::factory()->for($owner, 'owner')->create(['is_shared' => false]);
        $sharedDataset = SqlDataset::factory()->for($owner, 'owner')->create(['is_shared' => true]);

        // Private dataset denied to non-owner
        $this->actingAs($other, 'sanctum')->getJson("/api/examiner/datasets/{$privateDataset->id}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->patchJson("/api/examiner/datasets/{$privateDataset->id}", ['name' => 'Hack'])->assertStatus(403);
        $this->actingAs($other, 'sanctum')->deleteJson("/api/examiner/datasets/{$privateDataset->id}")->assertStatus(403);

        // Shared dataset readable by other examiner, not mutable/deletable
        $this->actingAs($other, 'sanctum')->getJson("/api/examiner/datasets/{$sharedDataset->id}")->assertOk();
        $this->actingAs($other, 'sanctum')->patchJson("/api/examiner/datasets/{$sharedDataset->id}", ['name' => 'Hack'])->assertStatus(403);
        $this->actingAs($other, 'sanctum')->deleteJson("/api/examiner/datasets/{$sharedDataset->id}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->postJson("/api/examiner/datasets/{$sharedDataset->id}/validate")->assertStatus(403);
    }

    /** TM-7 | FR-1 | invalid state transition */
    public function test_deleting_dataset_referenced_by_exam_returns_409_conflict(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create();
        Exam::factory()->for($examiner, 'examiner')->for($dataset, 'dataset')->create();

        $this->actingAs($examiner, 'sanctum')
            ->deleteJson("/api/examiner/datasets/{$dataset->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'dataset_in_use');
    }

    /** TM-8 | FR-1 | missing resource */
    public function test_unknown_dataset_returns_generic_404(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/examiner/datasets/'.Str::uuid())
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /** TM-1 | FR-1 | live MariaDB validation failure */
    public function test_validating_broken_dataset_sql_returns_422_without_setting_validated_at(): void
    {
        $examiner = User::factory()->examiner()->create();
        $dataset = SqlDataset::factory()->for($examiner, 'owner')->create([
            'schema_sql' => 'CREATE TABLE broken_tbl (id INT INVALID_SYNTAX);',
            'seed_sql' => null,
            'validated_at' => null,
        ]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/datasets/{$dataset->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'dataset_validation_failed')
            ->assertJsonStructure(['message', 'code', 'errors']);

        $this->assertNull($dataset->fresh()->validated_at);
    }

    /** TM-16 | FR-1, FR-8 | normal behavior */
    public function test_examiner_dataset_crud_scope_filters_validation_and_audits(): void
    {
        $examiner = User::factory()->examiner()->create();
        $other = User::factory()->examiner()->create();
        SqlDataset::factory()->for($other, 'owner')->create(['name' => 'Shared HR Schema', 'is_shared' => true]);

        $created = $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/examiner/datasets', [
                'name' => 'University Schema',
                'description' => 'Courses and enrollments',
                'schema_sql' => 'CREATE TABLE courses (id INT PRIMARY KEY, code VARCHAR(16) NOT NULL);',
                'seed_sql' => "INSERT INTO courses VALUES (1, 'CS101'), (2, 'CS102');",
            ]);

        $created->assertStatus(201)
            ->assertJsonPath('data.name', 'University Schema');
        $datasetId = $created->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'dataset.created',
            'target_type' => 'sql_dataset',
            'target_id' => $datasetId,
        ]);

        // Validate against live MariaDB and inspect auto-parsed schema_summary
        $validated = $this->actingAs($examiner, 'sanctum')
            ->postJson("/api/examiner/datasets/{$datasetId}/validate")
            ->assertOk()
            ->assertJsonPath('data.schema_summary.tables.0.name', 'courses')
            ->assertJsonPath('data.schema_summary.tables.0.row_count', 2);

        $this->assertNotNull($validated->json('data.validated_at'));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'dataset.validated',
            'target_id' => $datasetId,
        ]);

        // Updating SQL clears validated_at
        $this->actingAs($examiner, 'sanctum')
            ->patchJson("/api/examiner/datasets/{$datasetId}", [
                'seed_sql' => "INSERT INTO courses VALUES (1, 'CS101');",
            ])
            ->assertOk()
            ->assertJsonPath('data.validated_at', null);

        // Filter scope=shared vs mine vs all
        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/examiner/datasets?scope=shared')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Shared HR Schema');

        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/examiner/datasets?scope=mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $datasetId);

        // Delete unreferenced owned dataset
        $this->actingAs($examiner, 'sanctum')
            ->deleteJson("/api/examiner/datasets/{$datasetId}")
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'dataset.deleted',
            'target_id' => $datasetId,
        ]);
    }
}
