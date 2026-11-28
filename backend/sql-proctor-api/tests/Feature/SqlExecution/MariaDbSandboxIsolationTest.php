<?php

namespace Tests\Feature\SqlExecution;

use App\Enums\QuestionType;
use App\Exceptions\SandboxUnavailableException;
use App\Services\SqlExecution\SandboxManager;
use App\Services\SqlExecution\SchemaIntrospector;
use App\Services\SqlExecution\SqlExecutionService;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class MariaDbSandboxIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->assertZeroLeakedSandboxes();
        parent::tearDown();
    }

    /** TM-2 | FR-5, NFR-2, NFR-4 | security violation */
    public function test_static_guard_and_sandbox_block_escape_directives_without_leaking_internals(): void
    {
        $service = app(SqlExecutionService::class);
        $schemaSql = 'CREATE TABLE students (id INT PRIMARY KEY, name VARCHAR(50));';
        $seedSql = "INSERT INTO students VALUES (1, 'Ada');";

        $hostileQueries = [
            'SHOW DATABASES',
            'SELECT * FROM mysql.user',
            'SELECT * FROM performance_schema.threads',
            'SELECT * FROM sys.schema_table_statistics',
            "SELECT * INTO OUTFILE '/tmp/pwn.txt' FROM students",
            "SELECT * INTO DUMPFILE '/tmp/pwn.bin' FROM students",
            "SELECT LOAD_FILE('/etc/passwd')",
            "LOAD DATA INFILE '/etc/passwd' INTO TABLE students",
            "LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE students",
            'DROP DATABASE sql_exec',
            'CREATE DATABASE evil_db',
            "CREATE USER 'hacker'@'%' IDENTIFIED BY 'pw'",
            'SET GLOBAL max_connections = 999',
            'USE mysql',
            'SELECT * FROM sbx_00000000000000000000000000000000.students',
            'SELECT 1; DROP TABLE students',
            'SELECT BENCHMARK(10000000, SHA1("a"))',
        ];

        foreach ($hostileQueries as $sql) {
            $result = $service->execute(
                schemaSql: $schemaSql,
                seedSql: $seedSql,
                sql: $sql,
                questionType: QuestionType::SqlSelect,
            );

            $this->assertContains($result->status, ['policy_blocked', 'sql_error'], "Expected blocked/error for: {$sql}");
            $this->assertSame([], $result->rows);
            $this->assertNotNull($result->errorMessage);
            $this->assertStringNotContainsString('sbx_', (string) $result->errorMessage);
            $this->assertStringNotContainsString('sql_provisioner', (string) $result->errorMessage);
            $this->assertStringNotContainsString('sql_runner', (string) $result->errorMessage);
            $this->assertStringNotContainsString('127.0.0.1', (string) $result->errorMessage);
        }

        // F-1: MariaDB executable conditional comments /*M!...*/ must be unwrapped and blocked by SqlGuard
        foreach ([
            'SELECT /*M!100300 BENCHMARK(10000000, SHA1("a")) */ 1',
            'SELECT /*M! SLEEP(5) */ 1',
            '/*M! DROP DATABASE sql_exec */',
        ] as $conditionalHostileSql) {
            $blocked = $service->execute(
                schemaSql: $schemaSql,
                seedSql: $seedSql,
                sql: $conditionalHostileSql,
                questionType: QuestionType::SqlSelect,
            );

            $this->assertSame('policy_blocked', $blocked->status, "Expected policy_blocked for: {$conditionalHostileSql}");
            $this->assertSame([], $blocked->rows);
        }

        // F-6: Privilege errors with runtime Docker bridge hostnames are scrubbed cleanly
        $sanitizedPriv = app(SandboxManager::class)->sanitizeError(
            new \RuntimeException("Access denied for user 'sql_runner'@'172.18.0.1' to database 'sbx_deadbeef'"),
            'sbx_deadbeef',
        );
        $this->assertStringNotContainsString('172.18.0.1', $sanitizedPriv);
        $this->assertStringNotContainsString('sql_runner', $sanitizedPriv);
        $this->assertStringNotContainsString('sbx_', $sanitizedPriv);

        $this->assertZeroLeakedSandboxes();
    }

    /** TM-3 | FR-5, NFR-2 | security violation */
    public function test_mariadb_runner_grants_enforce_least_privilege_and_cross_schema_denial(): void
    {
        $provisioner = DB::connection('mariadb_provisioner')->getPdo();
        $runnerConfig = config('database.connections.mariadb_runner');

        $suffixA = bin2hex(random_bytes(8));
        $suffixB = bin2hex(random_bytes(8));
        $schemaA = "sbx_{$suffixA}";
        $schemaB = "sbx_{$suffixB}";

        try {
            $provisioner->exec("CREATE DATABASE `{$schemaA}`");
            $provisioner->exec("CREATE TABLE `{$schemaA}`.items (id INT PRIMARY KEY, label VARCHAR(30))");
            $provisioner->exec("INSERT INTO `{$schemaA}`.items VALUES (1, 'alpha')");

            $provisioner->exec("CREATE DATABASE `{$schemaB}`");
            $provisioner->exec("CREATE TABLE `{$schemaB}`.secrets (id INT PRIMARY KEY, secret VARCHAR(50))");
            $provisioner->exec("INSERT INTO `{$schemaB}`.secrets VALUES (1, 'top-secret-b')");

            $provisioner->exec("GRANT SELECT ON `{$schemaA}`.* TO '{$runnerConfig['username']}'@'%'");

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $runnerConfig['host'],
                $runnerConfig['port'],
                $schemaA,
            );
            $runnerPdo = new PDO($dsn, $runnerConfig['username'], $runnerConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                Mysql::ATTR_LOCAL_INFILE => false,
                Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);

            // Own scope works
            $ownRows = $runnerPdo->query('SELECT label FROM items')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([['label' => 'alpha']], $ownRows);

            // Cross-schema read & write denied at MariaDB engine grant layer
            foreach ([
                "SELECT * FROM `{$schemaB}`.secrets",
                "INSERT INTO `{$schemaB}`.secrets VALUES (2, 'hacked')",
                "UPDATE `{$schemaB}`.secrets SET secret = 'x'",
                "DELETE FROM `{$schemaB}`.secrets",
                "DROP TABLE `{$schemaB}`.secrets",
                'SELECT User, Host FROM mysql.user',
            ] as $crossSql) {
                try {
                    $runnerPdo->query($crossSql);
                    $this->fail("Expected MariaDB access denial for: {$crossSql}");
                } catch (PDOException $e) {
                    $this->assertStringNotContainsString('top-secret-b', $e->getMessage());
                }
            }

            // Verify B remained completely untouched
            $bRows = $provisioner->query("SELECT secret FROM `{$schemaB}`.secrets")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([['secret' => 'top-secret-b']], $bRows);

            // Verify runner cannot see schemaB in information_schema
            $visibleCount = (int) $runnerPdo
                ->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$schemaB}'")
                ->fetchColumn();
            $this->assertSame(0, $visibleCount);
        } finally {
            $runnerPdo = null;
            $provisioner->exec("REVOKE SELECT ON `{$schemaA}`.* FROM '{$runnerConfig['username']}'@'%'");
            $provisioner->exec("DROP DATABASE IF EXISTS `{$schemaA}`");
            $provisioner->exec("DROP DATABASE IF EXISTS `{$schemaB}`");
        }
    }

    /** TM-10, TM-14 | FR-5, NFR-3 | boundary & resource exhaustion */
    public function test_execution_bounds_enforce_payload_limit_row_cap_and_cell_cap(): void
    {
        $service = app(SqlExecutionService::class);
        $schemaSql = 'CREATE TABLE nums (n INT PRIMARY KEY, payload TEXT);';

        // Oversized SQL > 16 KB rejected prior to DB execution
        $oversizedSql = 'SELECT 1 /* '.str_repeat('x', 17000).' */';
        $blocked = $service->execute($schemaSql, '', $oversizedSql, QuestionType::SqlSelect);
        $this->assertSame('policy_blocked', $blocked->status);

        // Row cap (500 rows) + cell cap (4096 bytes) using recursive CTE
        $manyRowsSql = <<<'SQL'
WITH RECURSIVE seq AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 650
)
SELECT n, IF(n = 1, REPEAT('a', 5000), 'ok') AS long_text FROM seq
SQL;
        $capped = $service->execute($schemaSql, '', $manyRowsSql, QuestionType::SqlSelect);

        $this->assertSame('succeeded', $capped->status);
        $this->assertCount(500, $capped->rows);
        $this->assertSame(500, $capped->rowCount);
        $this->assertTrue($capped->truncated);
        $this->assertLessThanOrEqual(4120, strlen((string) $capped->rows[0]['long_text']));
    }

    /** TM-13 | FR-5, NFR-3 | timeout */
    public function test_runaway_query_times_out_at_bound_cleans_up_and_recovers(): void
    {
        $manager = app(SandboxManager::class);
        $service = app(SqlExecutionService::class);

        $started = microtime(true);
        $timedOut = $manager->runInSandbox(
            schemaSql: 'CREATE TABLE t (id INT PRIMARY KEY);',
            seedSql: 'INSERT INTO t VALUES (1), (2), (3);',
            questionType: QuestionType::SqlSelect,
            callback: function (PDO $runnerPdo): void {
                // Directly invoke SLEEP bypassing SqlGuard and fetch unbuffered cursor to prove real MariaDB timeout works.
                $runnerPdo->exec('SET SESSION max_statement_time = 1.5');
                $stmt = $runnerPdo->query('SELECT SLEEP(10)');
                $stmt->fetchAll(PDO::FETCH_NUM);
            },
        );
        $elapsed = microtime(true) - $started;

        $this->assertSame('timeout', $timedOut['status']);
        $this->assertLessThan(6.0, $elapsed);
        $this->assertZeroLeakedSandboxes();

        // Subsequent query executes cleanly
        $next = $service->execute('CREATE TABLE t (id INT PRIMARY KEY);', 'INSERT INTO t VALUES (1);', 'SELECT id FROM t', QuestionType::SqlSelect);
        $this->assertSame('succeeded', $next->status);
        $this->assertSame([['id' => 1]], $next->rows);
    }

    /** TM-12 | NFR-4 | infrastructure failure */
    public function test_unreachable_mariadb_port_throws_controlled_unavailable_exception_without_leaking_credentials(): void
    {
        config(['database.connections.mariadb_provisioner.port' => '39999']);
        config(['database.connections.mariadb_runner.port' => '39999']);
        DB::purge('mariadb_provisioner');
        DB::purge('mariadb_runner');

        $service = app(SqlExecutionService::class);

        try {
            $service->execute('CREATE TABLE t (id INT);', '', 'SELECT 1', QuestionType::SqlSelect);
            $this->fail('Expected SandboxUnavailableException when MariaDB port is closed.');
        } catch (SandboxUnavailableException $e) {
            $this->assertStringNotContainsString('39999', $e->getMessage());
            $this->assertStringNotContainsString('sql_provisioner', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        } finally {
            DB::purge('mariadb_provisioner');
            DB::purge('mariadb_runner');
        }
    }

    /** TM-15 | FR-5 | data integrity & orphan cleanup */
    public function test_cleanup_orphans_command_revokes_and_drops_stale_sandboxes(): void
    {
        $provisioner = DB::connection('mariadb_provisioner')->getPdo();
        $runnerUser = config('database.connections.mariadb_runner.username');
        $orphanSchema = 'sbx_orphan_'.bin2hex(random_bytes(6));

        $provisioner->exec("CREATE DATABASE `{$orphanSchema}`");
        $provisioner->exec("CREATE TABLE `{$orphanSchema}`.x (id INT PRIMARY KEY)");
        $provisioner->exec("GRANT SELECT ON `{$orphanSchema}`.* TO '{$runnerUser}'@'%'");

        $this->artisan('sandbox:cleanup-orphans', ['--older-than-minutes' => 0])
            ->assertExitCode(0);

        $remaining = $provisioner->query("SHOW DATABASES LIKE 'sbx_%'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains($orphanSchema, $remaining);
        $this->assertArrayNotHasKey($orphanSchema, app(SandboxManager::class)->listRunnerSchemaPrivileges());
    }

    /** TM-16 | FR-1 | normal behavior */
    public function test_schema_introspector_parses_tables_columns_keys_and_foreign_keys(): void
    {
        $introspector = app(SchemaIntrospector::class);

        $schemaSql = <<<'SQL'
CREATE TABLE departments (
    id INT PRIMARY KEY,
    name VARCHAR(80) NOT NULL
);
CREATE TABLE employees (
    id INT PRIMARY KEY,
    department_id INT NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    salary DECIMAL(10, 2) DEFAULT 0.00,
    CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments(id)
);
SQL;
        $seedSql = <<<'SQL'
INSERT INTO departments VALUES (1, 'Engineering'), (2, 'Design');
INSERT INTO employees VALUES (10, 1, 'Ada Lovelace', 95000.00);
SQL;

        $summary = $introspector->introspect($schemaSql, $seedSql);

        $this->assertArrayHasKey('tables', $summary);
        $tables = collect($summary['tables'])->keyBy('name');
        $this->assertTrue($tables->has('departments'));
        $this->assertTrue($tables->has('employees'));
        $this->assertSame(2, $tables['departments']['row_count']);
        $this->assertSame(1, $tables['employees']['row_count']);
        $this->assertNotEmpty($tables['employees']['foreign_keys']);
        $this->assertSame('departments', $tables['employees']['foreign_keys'][0]['referenced_table']);
    }

    private function assertZeroLeakedSandboxes(): void
    {
        $port = config('database.connections.mariadb_provisioner.port');
        if ((string) $port === '39999') {
            return;
        }

        $provisioner = DB::connection('mariadb_provisioner')->getPdo();
        $databases = $provisioner->query("SHOW DATABASES LIKE 'sbx_%'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], $databases, 'Leaked MariaDB sbx_* schemas detected: '.implode(', ', $databases));
        $grants = app(SandboxManager::class)->listRunnerSchemaPrivileges();
        $leakedGrants = array_filter(array_keys($grants), fn (string $s): bool => str_starts_with($s, 'sbx_'));
        $this->assertSame([], array_values($leakedGrants), 'Leaked MariaDB sbx_* runner grants detected: '.implode(', ', $leakedGrants));
    }
}
