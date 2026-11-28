<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminExaminerManagementTest extends TestCase
{
    use RefreshDatabase;

    /** TM-5 | FR-6 | unauthorized access */
    public function test_unauthenticated_requests_to_admin_examiner_endpoints_return_401(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->getJson('/api/admin/examiners')->assertStatus(401);
        $this->postJson('/api/admin/examiners', ['username' => 'ex1'])->assertStatus(401);
        $this->patchJson("/api/admin/examiners/{$examiner->id}", ['is_active' => false])->assertStatus(401);
        $this->postJson("/api/admin/examiners/{$examiner->id}/reset-password")->assertStatus(401);
    }

    /** TM-7 | FR-6 | forbidden access */
    public function test_examiner_is_denied_all_admin_examiner_endpoints(): void
    {
        $examiner = User::factory()->examiner()->create();
        $otherExaminer = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')->getJson('/api/admin/examiners')->assertStatus(403);
        $this->actingAs($examiner, 'sanctum')->postJson('/api/admin/examiners', ['username' => 'new_ex'])->assertStatus(403);
        $this->actingAs($examiner, 'sanctum')->patchJson("/api/admin/examiners/{$otherExaminer->id}", ['is_active' => false])->assertStatus(403);
        $this->actingAs($examiner, 'sanctum')->postJson("/api/admin/examiners/{$otherExaminer->id}/reset-password")->assertStatus(403);
    }

    /** TM-1, TM-13 | FR-6 | invalid input & duplicate */
    public function test_admin_examiner_creation_and_update_validate_inputs(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->examiner()->create(['username' => 'taken_examiner']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/examiners', [
                'username' => 'bad username!',
                'password' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['username', 'password']]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/examiners', [
                'username' => 'TAKEN_EXAMINER',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['username']]);
    }

    /** TM-8 | FR-6 | forbidden access */
    public function test_admin_cannot_deactivate_or_reset_password_of_self_or_other_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/examiners/{$admin->id}", ['is_active' => false])
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_modify_admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/examiners/{$admin->id}/reset-password")
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_modify_admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/examiners/{$otherAdmin->id}", ['is_active' => false])
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_modify_admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/examiners/{$otherAdmin->id}/reset-password")
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_modify_admin');

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($otherAdmin->fresh()->is_active);
    }

    /** TM-12 | FR-6 | missing resource */
    public function test_unknown_examiner_returns_generic_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/examiners/999999', ['is_active' => false])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/examiners/999999999999999999999', ['is_active' => false])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /** TM-1, TM-25 | FR-6 | boundary & filter normalization */
    public function test_empty_examiner_filter_query_params_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->examiner()->create(['username' => 'active_one', 'is_active' => true]);
        User::factory()->examiner()->create(['username' => 'inactive_one', 'is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/examiners?is_active=&username=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** TM-25 | FR-6 | normal behavior */
    public function test_admin_examiner_management_full_lifecycle_and_audits(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'sys_admin']);

        // Create examiner with generated password
        $createGenerated = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/examiners', [
                'username' => 'prof_alice',
                'name' => 'Prof. Alice',
            ]);

        $createGenerated->assertStatus(201)
            ->assertJsonPath('data.username', 'prof_alice')
            ->assertJsonPath('data.role', 'examiner')
            ->assertJsonPath('data.is_active', true);

        $generatedPassword = $createGenerated->json('data.generated_password');
        $this->assertIsString($generatedPassword);
        $this->assertGreaterThanOrEqual(16, strlen($generatedPassword));

        $alice = User::where('username', 'prof_alice')->firstOrFail();
        $this->assertTrue(Hash::check($generatedPassword, $alice->password));

        $createAudit = DB::table('audit_logs')
            ->where('event_type', 'examiner.created')
            ->where('target_id', (string) $alice->id)
            ->first();
        $this->assertNotNull($createAudit);
        $this->assertStringNotContainsString($generatedPassword, (string) $createAudit->metadata);

        // Create examiner with explicit password
        $createSupplied = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/examiners', [
                'username' => 'prof_bob',
                'password' => 'SuppliedStrongPass123!',
            ]);
        $createSupplied->assertStatus(201)
            ->assertJsonMissingPath('data.generated_password');

        // List & filter examiners
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/examiners?username=alice&is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'prof_alice');

        // Give Alice a live session, then deactivate and verify session invalidated
        $this->app['auth']->forgetGuards();
        $aliceLogin = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'prof_alice',
                'password' => $generatedPassword,
            ]);
        $aliceLogin->assertOk();
        $aliceCookie = $aliceLogin->getCookie(config('session.cookie'), false);
        $this->assertNotNull($aliceCookie);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $alice->id)->count());

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/examiners/{$alice->id}", [
                'is_active' => false,
                'name' => 'Prof. Alice Updated',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'Prof. Alice Updated');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $alice->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'examiner.deactivated',
            'target_id' => (string) $alice->id,
        ]);

        // Reactivate Alice and log her in, then reset password and verify old session fails
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/examiners/{$alice->id}", ['is_active' => true])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $reLogin = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'prof_alice',
                'password' => $generatedPassword,
            ]);
        $reLogin->assertOk();
        $reCookie = $reLogin->getCookie(config('session.cookie'), false);
        $this->assertNotNull($reCookie);

        $resetResponse = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/examiners/{$alice->id}/reset-password");
        $resetResponse->assertOk();
        $newPassword = $resetResponse->json('data.generated_password');
        $this->assertIsString($newPassword);
        $this->assertNotSame($generatedPassword, $newPassword);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $alice->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'examiner.password_reset',
            'target_id' => (string) $alice->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $reCookie->getValue())
            ->getJson('/api/user')
            ->assertStatus(401);
    }
}
