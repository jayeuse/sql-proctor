<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateUsersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_28_000001_update_users_table_for_username_auth.php');
    }

    /** PR-3 Finding 1 | invalid/long input + collision | up() on populated legacy users */
    public function test_up_backfills_unique_lowercase_usernames_for_existing_legacy_users(): void
    {
        $migration = $this->migration();
        $migration->down();

        $password = Hash::make('LegacyPass123!');
        $now = now();

        $id1 = DB::table('users')->insertGetId([
            'name' => 'Alice One',
            'email' => 'Alice@Example.com',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id2 = DB::table('users')->insertGetId([
            'name' => 'Collision Holder',
            'email' => 'placeholder@example.org',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id3 = DB::table('users')->insertGetId([
            'name' => 'Alice Collision',
            'email' => 'ALICE@other.org',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->where('id', $id2)->update([
            'email' => "alice_{$id3}@example.org",
        ]);

        $id4 = DB::table('users')->insertGetId([
            'name' => '!!!',
            'email' => '___@example.com',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id5 = DB::table('users')->insertGetId([
            'name' => 'Very Long Local Part',
            'email' => str_repeat('verylongusernamepart', 3).'@example.com',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumns('users', ['username', 'role', 'is_active', 'last_login_at']));

        $users = DB::table('users')->orderBy('id')->get();
        $this->assertCount(5, $users);

        $usernames = $users->pluck('username')->all();
        $this->assertCount(5, array_unique($usernames));

        foreach ($users as $user) {
            $this->assertNotNull($user->username);
            $this->assertSame(Str::lower($user->username), $user->username);
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]{2,31}$/', $user->username);
            $this->assertSame('examiner', $user->role);
            $this->assertTrue((bool) $user->is_active);
        }

        $this->assertSame('alice', $users->firstWhere('id', $id1)->username);
        $this->assertSame("alice_{$id3}", $users->firstWhere('id', $id2)->username);
        $this->assertNotSame("alice_{$id3}", $users->firstWhere('id', $id3)->username);
        $this->assertStringStartsWith('alice_', $users->firstWhere('id', $id3)->username);
        $this->assertSame("user_{$id4}", $users->firstWhere('id', $id4)->username);
        $this->assertLessThanOrEqual(32, strlen($users->firstWhere('id', $id5)->username));
    }

    /** PR-3 Finding 2 | duplicate/null rollback safety | down() with null name and null emails */
    public function test_down_backfills_null_name_and_distinct_emails_before_restoring_not_null_constraints(): void
    {
        $password = Hash::make('StaffPass123!');
        $now = now();

        $id1 = DB::table('users')->insertGetId([
            'username' => 'admin_one',
            'name' => null,
            'email' => null,
            'password' => $password,
            'role' => 'admin',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id2 = DB::table('users')->insertGetId([
            'username' => 'examiner_two',
            'name' => null,
            'email' => null,
            'password' => $password,
            'role' => 'examiner',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id3 = DB::table('users')->insertGetId([
            'username' => 'examiner_three',
            'name' => 'Existing Name',
            'email' => "admin_one-{$id1}@restored.invalid",
            'password' => $password,
            'role' => 'examiner',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'is_active'));
        $this->assertFalse(Schema::hasColumn('users', 'last_login_at'));

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'users')
            ->whereIn('column_name', ['name', 'email'])
            ->pluck('is_nullable', 'column_name');

        $this->assertSame('NO', $columns['name']);
        $this->assertSame('NO', $columns['email']);

        $users = DB::table('users')->orderBy('id')->get();
        $this->assertCount(3, $users);
        $this->assertSame('admin_one', $users->firstWhere('id', $id1)->name);
        $this->assertSame('examiner_two', $users->firstWhere('id', $id2)->name);
        $this->assertSame('Existing Name', $users->firstWhere('id', $id3)->name);

        $emails = $users->pluck('email')->all();
        $this->assertCount(3, array_unique($emails));
        foreach ($emails as $email) {
            $this->assertNotNull($email);
            $this->assertNotSame('', $email);
        }

        // Re-applying up() after rollback must also succeed with restored rows intact.
        $migration->up();
        $this->assertSame(3, DB::table('users')->whereNotNull('username')->count());
    }

    /** Data integrity check | constraints stay enforced after populated up() backfill */
    public function test_up_retains_username_constraints_and_nullable_name_email_after_backfill(): void
    {
        $migration = $this->migration();
        $migration->down();

        $password = Hash::make('LegacyPass123!');
        $now = now();

        DB::table('users')->insert([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration->up();

        // Nullable name and email are allowed after upgrade.
        $newId = DB::table('users')->insertGetId([
            'username' => 'new_staff',
            'name' => null,
            'email' => null,
            'password' => $password,
            'role' => 'examiner',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertGreaterThan(0, $newId);

        // Null username must fail.
        try {
            DB::transaction(function () use ($password, $now): void {
                DB::table('users')->insert([
                    'username' => null,
                    'name' => 'No Username',
                    'email' => 'nouser@example.com',
                    'password' => $password,
                    'role' => 'examiner',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
            $this->fail('Expected QueryException for NULL username.');
        } catch (QueryException) {
            // Expected NOT NULL rejection.
        }

        // Duplicate username must fail.
        try {
            DB::transaction(function () use ($password, $now): void {
                DB::table('users')->insert([
                    'username' => 'legacy',
                    'name' => 'Dup Username',
                    'email' => 'dup@example.com',
                    'password' => $password,
                    'role' => 'examiner',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
            $this->fail('Expected QueryException for duplicate username.');
        } catch (QueryException) {
            // Expected UNIQUE rejection.
        }

        // Uppercase username must fail lowercase CHECK constraint.
        try {
            DB::transaction(function () use ($password, $now): void {
                DB::table('users')->insert([
                    'username' => 'UpperCaseUser',
                    'name' => 'Upper',
                    'email' => 'upper@example.com',
                    'password' => $password,
                    'role' => 'examiner',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
            $this->fail('Expected QueryException for uppercase username CHECK violation.');
        } catch (QueryException) {
            // Expected CHECK violation.
        }
    }
}
