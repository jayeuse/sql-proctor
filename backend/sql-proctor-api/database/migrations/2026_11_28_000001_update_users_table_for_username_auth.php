<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('id');
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('role', 32)->default('examiner')->after('password')->index();
            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        $this->backfillUsernames();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable(false)->change();
            $table->unique('username');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'examiner'))");
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_username_lowercase_check CHECK (username = lower(username))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_lowercase_check');
        }

        $this->backfillNullableNamesAndEmails();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['username', 'role', 'is_active', 'last_login_at']);
            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }

    /**
     * Backfill deterministic unique lowercase usernames for pre-existing rows before setting NOT NULL.
     */
    private function backfillUsernames(): void
    {
        if (! DB::table('users')->whereNull('username')->exists()) {
            return;
        }

        $usedUsernames = [];
        foreach (DB::table('users')->whereNotNull('username')->pluck('username') as $existing) {
            $usedUsernames[Str::lower((string) $existing)] = true;
        }

        DB::table('users')
            ->whereNull('username')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$usedUsernames): void {
                foreach ($rows as $row) {
                    $username = $this->uniqueUsernameForRow($row, $usedUsernames);
                    $usedUsernames[$username] = true;

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['username' => $username]);
                }
            });
    }

    /**
     * Derive a collision-free lowercase username matching app validation bounds (3-32 chars).
     *
     * @param  array<string, true>  $usedUsernames
     */
    private function uniqueUsernameForRow(object $row, array $usedUsernames): string
    {
        $emailLocal = Str::before((string) ($row->email ?? ''), '@');
        $seed = trim($emailLocal) !== '' ? $emailLocal : (string) ($row->name ?? '');

        $sanitized = preg_replace('/[^a-z0-9._-]+/', '_', Str::lower(Str::ascii($seed))) ?? '';
        $sanitized = trim($sanitized, '._-');
        $sanitized = preg_replace('/^[^a-z0-9]+/', '', $sanitized) ?? '';

        $hasUsableSeed = $sanitized !== '';
        $base = $hasUsableSeed ? $sanitized : 'user';

        if ($hasUsableSeed && strlen($base) >= 3) {
            $primary = substr($base, 0, 32);
            if (! isset($usedUsernames[$primary])) {
                return $primary;
            }
        }

        $idSuffix = "_{$row->id}";
        $prefix = $this->boundedUsernamePrefix($base, strlen($idSuffix));
        $candidate = "{$prefix}{$idSuffix}";

        if (! isset($usedUsernames[$candidate])) {
            return $candidate;
        }

        $attempt = 2;
        while (true) {
            $attemptSuffix = "_{$row->id}_{$attempt}";
            $attemptPrefix = $this->boundedUsernamePrefix($base, strlen($attemptSuffix));
            $candidate = "{$attemptPrefix}{$attemptSuffix}";

            if (! isset($usedUsernames[$candidate])) {
                return $candidate;
            }

            $attempt++;
        }
    }

    private function boundedUsernamePrefix(string $base, int $suffixLength): string
    {
        $maxPrefixLength = max(1, 32 - $suffixLength);
        $sliced = substr($base, 0, $maxPrefixLength);
        $trimmed = rtrim($sliced, '._-');

        return $trimmed !== '' ? $trimmed : substr($base, 0, 1);
    }

    /**
     * Restore non-null names and unique emails before enforcing NOT NULL during rollback.
     */
    private function backfillNullableNamesAndEmails(): void
    {
        $needsBackfill = DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('name')->orWhereNull('email');
            })
            ->exists();

        if (! $needsBackfill) {
            return;
        }

        $usedEmails = [];
        foreach (DB::table('users')->whereNotNull('email')->pluck('email') as $existing) {
            $usedEmails[Str::lower((string) $existing)] = true;
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('name')->orWhereNull('email');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$usedEmails): void {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($row->name === null) {
                        $username = trim((string) ($row->username ?? ''));
                        $updates['name'] = Str::limit($username !== '' ? $username : "User {$row->id}", 255, '');
                    }

                    if ($row->email === null) {
                        $email = $this->uniqueRestoredEmailForRow($row, $usedEmails);
                        $usedEmails[Str::lower($email)] = true;
                        $updates['email'] = $email;
                    }

                    if ($updates !== []) {
                        DB::table('users')
                            ->where('id', $row->id)
                            ->update($updates);
                    }
                }
            });
    }

    /**
     * Generate a non-null unique placeholder email under RFC 2606 .invalid for rollback safety.
     *
     * @param  array<string, true>  $usedEmails
     */
    private function uniqueRestoredEmailForRow(object $row, array $usedEmails): string
    {
        $rawLocal = Str::lower(Str::ascii((string) ($row->username ?? 'user')));
        $local = preg_replace('/[^a-z0-9._-]+/', '-', $rawLocal) ?? '';
        $local = trim($local, '.-');
        if ($local === '') {
            $local = 'user';
        }

        $domain = '@restored.invalid';
        $maxLocalLen = max(1, 255 - strlen($domain) - strlen("-{$row->id}"));
        $boundedLocal = substr($local, 0, $maxLocalLen);
        $candidate = "{$boundedLocal}-{$row->id}{$domain}";

        if (! isset($usedEmails[Str::lower($candidate)])) {
            return $candidate;
        }

        $attempt = 2;
        while (true) {
            $suffix = "-{$row->id}-{$attempt}";
            $attemptMaxLen = max(1, 255 - strlen($domain) - strlen($suffix));
            $attemptLocal = substr($local, 0, $attemptMaxLen);
            $candidate = "{$attemptLocal}{$suffix}{$domain}";

            if (! isset($usedEmails[Str::lower($candidate)])) {
                return $candidate;
            }

            $attempt++;
        }
    }
};
