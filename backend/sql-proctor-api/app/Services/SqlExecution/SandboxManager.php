<?php

namespace App\Services\SqlExecution;

use App\Enums\QuestionType;
use App\Exceptions\SandboxUnavailableException;
use PDO;
use Pdo\Mysql;
use PDOException;
use Throwable;

class SandboxManager
{
    public function __construct(
        private readonly SqlGuard $guard,
    ) {}

    /**
     * @template T
     *
     * @param  callable(PDO, string): T  $callback
     * @return array{status: string, result?: T, errorMessage?: string, executionMs: int}
     */
    public function runInSandbox(
        string $schemaSql,
        ?string $seedSql,
        QuestionType|string $questionType,
        callable $callback,
    ): array {
        $type = $questionType instanceof QuestionType ? $questionType : QuestionType::from($questionType);
        $schema = $this->generateSchemaName();
        $privileges = $this->privilegesFor($type);
        $runnerConfig = config('database.connections.mariadb_runner');
        $runnerUser = (string) ($runnerConfig['username'] ?? 'sql_runner');
        $runnerHost = (string) config('sql_proctor.sandbox.runner_host', '%');
        $timeoutSeconds = (float) config('sql_proctor.sandbox.statement_timeout_seconds', 2);

        $provPdo = $this->connectProvisioner();
        $runnerPdo = null;
        $granted = false;
        $startedAt = hrtime(true);

        try {
            $provPdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");
            $provPdo->exec("USE `{$schema}`");

            $this->applyProvisionerScript($provPdo, $schemaSql, $schema);
            if ($seedSql !== null && trim($seedSql) !== '') {
                $this->applyProvisionerScript($provPdo, $seedSql, $schema);
            }

            $provPdo->exec(sprintf(
                "GRANT %s ON `%s`.* TO '%s'@'%s'",
                $privileges,
                $schema,
                $this->escapeSqlString($runnerUser),
                $this->escapeSqlString($runnerHost),
            ));
            $granted = true;

            $runnerPdo = $this->connectRunner($schema);
            $runnerPdo->exec("SET SESSION max_statement_time = {$timeoutSeconds}");

            $startedAt = hrtime(true);
            $result = $callback($runnerPdo, $schema);
            $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return [
                'status' => 'succeeded',
                'result' => $result,
                'executionMs' => $elapsedMs,
            ];
        } catch (SandboxUnavailableException $e) {
            throw $e;
        } catch (PDOException $e) {
            $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            if ($this->isConnectionFailure($e)) {
                throw new SandboxUnavailableException;
            }

            if ($this->isTimeoutException($e)) {
                return [
                    'status' => 'timeout',
                    'errorMessage' => 'Query timed out after '.rtrim(rtrim(number_format($timeoutSeconds, 2, '.', ''), '0'), '.').' seconds.',
                    'executionMs' => $elapsedMs,
                ];
            }

            return [
                'status' => 'sql_error',
                'errorMessage' => $this->sanitizeError($e, $schema),
                'executionMs' => $elapsedMs,
            ];
        } catch (Throwable) {
            $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return [
                'status' => 'system_error',
                'errorMessage' => 'SQL execution engine encountered an unexpected internal error.',
                'executionMs' => $elapsedMs,
            ];
        } finally {
            $runnerPdo = null;

            if ($granted) {
                try {
                    $provPdo->exec(sprintf(
                        "REVOKE %s ON `%s`.* FROM '%s'@'%s'",
                        $privileges,
                        $schema,
                        $this->escapeSqlString($runnerUser),
                        $this->escapeSqlString($runnerHost),
                    ));
                } catch (Throwable) {
                    // Best-effort revoke before dropping schema; orphan sweeper cleans any remainder.
                }
            }

            try {
                $provPdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
            } catch (Throwable) {
                // Best-effort drop; orphan sweeper catches any failure.
            }

            $provPdo = null;
        }
    }

    /**
     * Execute DDL + seed inside an ephemeral sandbox and return an introspector callback result.
     *
     * @template T
     *
     * @param  callable(PDO, string): T  $callback
     * @return T
     */
    public function introspectInSandbox(string $schemaSql, ?string $seedSql, callable $callback): mixed
    {
        $schema = $this->generateSchemaName();
        $provPdo = $this->connectProvisioner();

        try {
            $provPdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");
            $provPdo->exec("USE `{$schema}`");

            $this->applyProvisionerScript($provPdo, $schemaSql, $schema);
            if ($seedSql !== null && trim($seedSql) !== '') {
                $this->applyProvisionerScript($provPdo, $seedSql, $schema);
            }

            return $callback($provPdo, $schema);
        } catch (SandboxUnavailableException $e) {
            throw $e;
        } catch (PDOException $e) {
            if ($this->isConnectionFailure($e)) {
                throw new SandboxUnavailableException;
            }

            throw new \RuntimeException($this->sanitizeError($e, $schema), (int) $e->getCode());
        } finally {
            try {
                $provPdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
            } catch (Throwable) {
                // Best-effort cleanup.
            }
            $provPdo = null;
        }
    }

    public function connectProvisioner(): PDO
    {
        $cfg = config('database.connections.mariadb_provisioner');

        return $this->createPdo($cfg, '', true);
    }

    /**
     * Inspect schema-level grants held by the execution runner (queries its own grants only).
     *
     * @return array<string, list<string>>
     */
    public function listRunnerSchemaPrivileges(): array
    {
        $cfg = config('database.connections.mariadb_runner');
        $runnerPdo = $this->createPdo($cfg, '', true);

        try {
            $rows = $runnerPdo
                ->query('SELECT TABLE_SCHEMA, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES')
                ->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($rows as $row) {
                $schema = (string) ($row['TABLE_SCHEMA'] ?? '');
                $priv = (string) ($row['PRIVILEGE_TYPE'] ?? '');
                if ($schema !== '' && $priv !== '') {
                    $grouped[$schema][] = $priv;
                }
            }

            foreach ($grouped as $schema => $privs) {
                $grouped[$schema] = array_values(array_unique($privs));
            }

            return $grouped;
        } finally {
            $runnerPdo = null;
        }
    }

    private function connectRunner(string $schema): PDO
    {
        $cfg = config('database.connections.mariadb_runner');

        return $this->createPdo($cfg, $schema, false);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function createPdo(array $cfg, string $database, bool $buffered): PDO
    {
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '3306');
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');
        $timeout = (int) config('sql_proctor.sandbox.connect_timeout_seconds', 5);

        $dsn = $database !== ''
            ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database)
            : sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_EMULATE_PREPARES => false,
            Mysql::ATTR_LOCAL_INFILE => false,
            Mysql::ATTR_MULTI_STATEMENTS => false,
            Mysql::ATTR_USE_BUFFERED_QUERY => $buffered,
        ];

        $sslCa = env('MYSQL_ATTR_SSL_CA');
        if (is_string($sslCa) && $sslCa !== '') {
            $options[Mysql::ATTR_SSL_CA] = $sslCa;
        }

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException) {
            throw new SandboxUnavailableException;
        }
    }

    private function applyProvisionerScript(PDO $provPdo, string $sql, string $schema): void
    {
        $checked = $this->guard->validate($sql, QuestionType::SqlDdl, true);
        if (! $checked['ok']) {
            throw new \RuntimeException((string) $checked['reason']);
        }

        try {
            foreach ($checked['statements'] as $statement) {
                $provPdo->exec($statement);
            }
        } catch (PDOException $e) {
            if ($this->isConnectionFailure($e)) {
                throw new SandboxUnavailableException;
            }

            throw new \RuntimeException($this->sanitizeError($e, $schema));
        }
    }

    private function privilegesFor(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::SqlSelect, QuestionType::SqlDebug => 'SELECT',
            QuestionType::SqlDml => 'SELECT, INSERT, UPDATE, DELETE',
            QuestionType::SqlDdl => 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, CREATE VIEW',
            QuestionType::ShortAnswer, QuestionType::Mcq => 'SELECT',
        };
    }

    private function generateSchemaName(): string
    {
        return config('sql_proctor.sandbox.prefix', 'sbx_').bin2hex(random_bytes(16));
    }

    private function isConnectionFailure(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if (in_array($driverCode, [2002, 2003, 2006, 2013, 1045], true)) {
            return true;
        }

        if (str_starts_with($sqlState, '08')) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'connection refused')
            || str_contains($msg, 'no such file or directory')
            || str_contains($msg, 'server has gone away');
    }

    private function isTimeoutException(PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, [1969, 3024, 1317], true)) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'max_statement_time')
            || str_contains($msg, 'query execution was interrupted');
    }

    public function sanitizeError(Throwable $e, string $schema): string
    {
        $message = $e->getMessage();
        $runnerUser = (string) config('database.connections.mariadb_runner.username', 'sql_runner');
        $provUser = (string) config('database.connections.mariadb_provisioner.username', 'sql_provisioner');
        $host = (string) config('database.connections.mariadb_runner.host', '127.0.0.1');
        $port = (string) config('database.connections.mariadb_runner.port', '3306');

        $replacements = [
            "`{$schema}`." => '',
            "`{$schema}`" => 'sandbox',
            "{$schema}." => '',
            $schema => 'sandbox',
            $runnerUser => 'runner',
            $provUser => 'provisioner',
            $host => 'db-host',
            ":{$port}" => '',
        ];

        $sanitized = str_replace(array_keys($replacements), array_values($replacements), $message);
        $sanitized = preg_replace("/@'[^']*'/", "@'sandbox-client'", $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/sbx_[a-f0-9]+/i', 'sandbox', $sanitized) ?? $sanitized;

        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 497).'...';
        }

        return $sanitized;
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
