<?php

namespace App\Console\Commands;

use App\Services\SqlExecution\SandboxManager;
use Illuminate\Console\Command;
use PDO;

class CleanupOrphanSandboxesCommand extends Command
{
    protected $signature = 'sandbox:cleanup-orphans {--older-than-minutes= : Minimum age in minutes before dropping a sandbox schema}';

    protected $description = 'Revoke lingering runner privileges and drop orphaned MariaDB sbx_* schemas.';

    public function handle(SandboxManager $sandboxManager): int
    {
        $olderThanOption = $this->option('older-than-minutes');
        $olderThanMinutes = $olderThanOption !== null
            ? max(0, (int) $olderThanOption)
            : (int) config('sql_proctor.sandbox.orphan_max_age_minutes', 5);

        $prefix = (string) config('sql_proctor.sandbox.prefix', 'sbx_');
        $runnerUser = (string) config('database.connections.mariadb_runner.username', 'sql_runner');
        $runnerHost = (string) config('sql_proctor.sandbox.runner_host', '%');

        $provPdo = $sandboxManager->connectProvisioner();
        $runnerGrants = $sandboxManager->listRunnerSchemaPrivileges();

        $likePattern = str_replace('_', '\\_', $prefix).'%';
        $stmt = $provPdo->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME',
        );
        $stmt->execute([$likePattern]);
        $schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $existingMap = [];
        foreach ($schemas as $schema) {
            if (is_string($schema) && str_starts_with($schema, $prefix)) {
                $existingMap[$schema] = true;
            }
        }

        $dropped = 0;
        $revokedSchemas = 0;

        foreach (array_keys($existingMap) as $schema) {
            if ($olderThanMinutes > 0) {
                $ageStmt = $provPdo->prepare(
                    'SELECT TIMESTAMPDIFF(MINUTE, MAX(CREATE_TIME), UTC_TIMESTAMP()) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                );
                $ageStmt->execute([$schema]);
                $ageMinutes = $ageStmt->fetchColumn();

                if ($ageMinutes !== null && $ageMinutes !== false) {
                    if ((int) $ageMinutes < $olderThanMinutes) {
                        continue;
                    }
                } elseif (preg_match('/^'.preg_quote($prefix, '/').'([0-9a-f]{8})[0-9a-f]{24}$/i', $schema, $matches) === 1) {
                    $createdTs = (int) hexdec($matches[1]);
                    if ((time() - $createdTs) < ($olderThanMinutes * 60)) {
                        continue;
                    }
                } else {
                    // Empty schema without timestamp metadata: preserve when age cutoff > 0 unless explicitly swept with --older-than-minutes=0.
                    continue;
                }
            }

            if (isset($runnerGrants[$schema]) && $runnerGrants[$schema] !== []) {
                $this->revokeSchema($provPdo, $schema, $runnerGrants[$schema], $runnerUser, $runnerHost);
                unset($runnerGrants[$schema]);
                $revokedSchemas++;
            }

            $safeSchema = str_replace('`', '``', $schema);
            $provPdo->exec("DROP DATABASE IF EXISTS `{$safeSchema}`");
            $dropped++;
        }

        // Sweep any stale grants whose backing sbx_* schema is already dropped.
        foreach ($runnerGrants as $grantSchema => $privs) {
            if (! str_starts_with($grantSchema, $prefix) || isset($existingMap[$grantSchema]) || $privs === []) {
                continue;
            }

            $this->revokeSchema($provPdo, $grantSchema, $privs, $runnerUser, $runnerHost);
            $revokedSchemas++;
        }

        $this->info("Cleaned {$dropped} orphan MariaDB sandbox schema(s) and revoked {$revokedSchemas} grant(s).");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $privs
     */
    private function revokeSchema(PDO $provPdo, string $schema, array $privs, string $runnerUser, string $runnerHost): void
    {
        $safeSchema = str_replace('`', '``', $schema);
        $provPdo->exec(sprintf(
            "REVOKE %s ON `%s`.* FROM '%s'@'%s'",
            implode(', ', $privs),
            $safeSchema,
            str_replace("'", "\\'", $runnerUser),
            str_replace("'", "\\'", $runnerHost),
        ));
    }
}
