<?php

namespace App\Console\Commands;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:bootstrap-admin {--username=admin : Username for the initial system administrator}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently create the initial active system administrator account.';

    public function handle(AuditLogger $auditLogger): int
    {
        $hasActiveAdmin = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->exists();

        if ($hasActiveAdmin) {
            $this->info('An active administrator account already exists. No changes were made.');

            return self::SUCCESS;
        }

        $username = Str::lower(trim((string) $this->option('username')));

        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username) !== 1) {
            $this->error('Invalid username. Must be 3-32 lowercase alphanumeric, dot, underscore, or hyphen characters.');

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->error("Cannot bootstrap administrator: username '{$username}' is already taken.");

            return self::FAILURE;
        }

        $plainPassword = Str::password(20);

        $admin = DB::transaction(function () use ($username, $plainPassword, $auditLogger): User {
            $admin = User::create([
                'username' => $username,
                'name' => 'System Administrator',
                'password' => $plainPassword,
                'role' => UserRole::Admin,
                'is_active' => true,
            ]);

            $auditLogger->log(
                eventType: AuditEvent::AdminBootstrapCompleted,
                actorType: ActorType::System,
                actorId: null,
                actorLabel: 'system',
                targetType: 'user',
                targetId: (string) $admin->id,
                metadata: [
                    'username' => $admin->username,
                    'role' => UserRole::Admin->value,
                ],
            );

            return $admin;
        });

        $this->info('System administrator account created.');
        $this->line("Username: {$admin->username}");
        $this->line("Password: {$plainPassword}");
        $this->warn('Store this credential securely. It will not be printed again.');

        return self::SUCCESS;
    }
}
