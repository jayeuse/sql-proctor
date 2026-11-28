<?php

namespace App\Services\SqlExecution;

use PDO;

class SchemaIntrospector
{
    public function __construct(
        private readonly SandboxManager $sandboxManager,
    ) {}

    /**
     * Execute schema + seed SQL in an ephemeral sandbox and return parsed table/column/FK metadata.
     *
     * @return array{tables: array<int, array<string, mixed>>}
     */
    public function introspect(string $schemaSql, ?string $seedSql = null): array
    {
        return $this->sandboxManager->introspectInSandbox(
            $schemaSql,
            $seedSql,
            function (PDO $pdo, string $schema): array {
                $tablesStmt = $pdo->prepare(
                    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME ASC',
                );
                $tablesStmt->execute([$schema]);
                $tableRows = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

                $colStmt = $pdo->prepare(
                    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ?
                     ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC',
                );
                $colStmt->execute([$schema]);
                $colRows = $colStmt->fetchAll(PDO::FETCH_ASSOC);

                $fkStmt = $pdo->prepare(
                    'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                     ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC',
                );
                $fkStmt->execute([$schema]);
                $fkRows = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

                $columnsByTable = [];
                foreach ($colRows as $col) {
                    $columnsByTable[$col['TABLE_NAME']][] = [
                        'name' => $col['COLUMN_NAME'],
                        'type' => $col['COLUMN_TYPE'],
                        'nullable' => strtoupper((string) $col['IS_NULLABLE']) === 'YES',
                        'key' => (string) ($col['COLUMN_KEY'] ?? ''),
                        'default' => $col['COLUMN_DEFAULT'],
                    ];
                }

                $fksByTable = [];
                foreach ($fkRows as $fk) {
                    $fksByTable[$fk['TABLE_NAME']][] = [
                        'constraint' => $fk['CONSTRAINT_NAME'],
                        'column' => $fk['COLUMN_NAME'],
                        'referenced_table' => $fk['REFERENCED_TABLE_NAME'],
                        'referenced_column' => $fk['REFERENCED_COLUMN_NAME'],
                    ];
                }

                $tables = [];
                foreach ($tableRows as $tbl) {
                    $tableName = (string) $tbl['TABLE_NAME'];
                    $safeTable = str_replace('`', '``', $tableName);
                    $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$safeTable}`")->fetchColumn();

                    $tables[] = [
                        'name' => $tableName,
                        'type' => $tbl['TABLE_TYPE'],
                        'row_count' => $rowCount,
                        'columns' => $columnsByTable[$tableName] ?? [],
                        'foreign_keys' => $fksByTable[$tableName] ?? [],
                    ];
                }

                return ['tables' => $tables];
            },
        );
    }
}
