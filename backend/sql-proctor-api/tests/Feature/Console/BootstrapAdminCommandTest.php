<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /** TM-23 | FR-2 | normal behavior */
    public function test_bootstrap_admin_command_creates_admin_prints_credentials_once_and_audits(): void
    {
        $this->artisan('app:bootstrap-admin')
            ->expectsOutputToContain('admin')
            ->expectsOutputToContain('Password:')
            ->assertExitCode(0);

        $admin = DB::table('users')->where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue((bool) $admin->is_active);

        $audit = DB::table('audit_logs')
            ->where('event_type', 'admin.bootstrap_completed')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame((string) $admin->id, $audit->target_id);
        $this->assertStringNotContainsString('password', strtolower((string) $audit->metadata));
    }

    /** TM-14 | FR-2 | duplicate operation */
    public function test_bootstrap_admin_command_is_idempotent_on_repeat_runs(): void
    {
        $this->artisan('app:bootstrap-admin')->assertExitCode(0);

        $firstAdmin = User::query()->where('role', 'admin')->firstOrFail();
        $originalHash = $firstAdmin->password;

        $this->artisan('app:bootstrap-admin')
            ->expectsOutputToContain('already exists')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('users')->where('role', 'admin')->count());
        $firstAdmin->refresh();
        $this->assertSame($originalHash, $firstAdmin->password);
        $this->assertTrue(Hash::check('wrong-password', $firstAdmin->password) === false);
        $this->assertSame(1, DB::table('audit_logs')->where('event_type', 'admin.bootstrap_completed')->count());
    }

    /** TM-21 | FR-2 | data integrity failure */
    public function test_bootstrap_admin_refuses_to_overwrite_existing_examiner_with_matching_username(): void
    {
        User::factory()->examiner()->create([
            'username' => 'admin',
        ]);

        $this->artisan('app:bootstrap-admin')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', [
            'username' => 'admin',
            'role' => 'examiner',
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('event_type', 'admin.bootstrap_completed')->count());
    }
}
