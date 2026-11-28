<?php

namespace App\Services\SqlExecution;

use App\Enums\QuestionType;

class SqlGuard
{
    /**
     * Regexes evaluated against comment-unwrapped, string-literal-blanked SQL.
     *
     * @var array<int, string>
     */
    private const array BLOCKED_PATTERNS = [
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bLOAD\s+DATA\b/i',
        '/\bBENCHMARK\s*\(/i',
        '/\bSLEEP\s*\(/i',
        '/\bGET_LOCK\s*\(/i',
        '/\bRELEASE_LOCK\s*\(/i',
        '/\bSET\s+(GLOBAL|PERSIST|SESSION|@@)/i',
        '/\b(GRANT|REVOKE)\b/i',
        '/\b(CREATE|ALTER|DROP|RENAME)\s+USER\b/i',
        '/\b(CREATE|ALTER|DROP)\s+(DATABASE|SCHEMA)\b/i',
        '/\bSHOW\s+(DATABASES|SCHEMAS|GRANTS|PROCESSLIST|VARIABLES|GLOBAL|ENGINE|PLUGINS|MASTER|REPLICA|SLAVE|BINARY)\b/i',
        '/\bUSE\s+/i',
        '/\bKILL\b/i',
        '/\bSHUTDOWN\b/i',
        '/\bFLUSH\b/i',
        '/\b(INSTALL|UNINSTALL)\s+PLUGIN\b/i',
        '/\bHANDLER\b/i',
        '/\b(CREATE|ALTER|DROP)\s+(FUNCTION|PROCEDURE|TRIGGER|EVENT|PACKAGE)\b/i',
        '/`?(mysql|performance_schema|sys|sql_exec)`?\s*\./i',
        '/`?information_schema`?\s*\.\s*`?(user_privileges|schema_privileges|table_privileges|processlist)`?/i',
        '/`?sbx_[a-z0-9_]*`?/i',
    ];

    /**
     * @return array{ok: bool, statements: array<int, string>, reason: ?string}
     */
    public function validate(string $sql, QuestionType|string $questionType, bool $isProvisioner = false): array
    {
        if (! mb_check_encoding($sql, 'UTF-8')) {
            return ['ok' => false, 'statements' => [], 'reason' => 'SQL input must be valid UTF-8 text.'];
        }

        $maxBytes = (int) config('sql_proctor.sandbox.max_payload_bytes', 16384);
        if (! $isProvisioner && strlen($sql) > $maxBytes) {
            return ['ok' => false, 'statements' => [], 'reason' => "SQL payload exceeds the {$maxBytes}-byte limit."];
        }

        $statements = SqlSplitter::split($sql);
        if ($statements === []) {
            return ['ok' => false, 'statements' => [], 'reason' => 'SQL input contains no executable statements.'];
        }

        $type = $questionType instanceof QuestionType ? $questionType : QuestionType::from($questionType);

        if (! $isProvisioner) {
            if (in_array($type, [QuestionType::SqlSelect, QuestionType::SqlDebug], true) && count($statements) > 1) {
                return ['ok' => false, 'statements' => [], 'reason' => 'Only a single SQL statement is permitted for this question type.'];
            }

            $maxStatements = (int) config('sql_proctor.sandbox.max_statements', 20);
            if (count($statements) > $maxStatements) {
                return ['ok' => false, 'statements' => [], 'reason' => "Script exceeds the maximum of {$maxStatements} statements."];
            }
        }

        foreach ($statements as $statement) {
            $inspected = SqlSplitter::sanitizeForInspection($statement);

            foreach (self::BLOCKED_PATTERNS as $pattern) {
                if (preg_match($pattern, $inspected) === 1) {
                    return ['ok' => false, 'statements' => [], 'reason' => 'Statement contains a disallowed SQL directive or restricted schema reference.'];
                }
            }

            if (! $isProvisioner && ! $this->isKeywordAllowed($inspected, $type)) {
                return ['ok' => false, 'statements' => [], 'reason' => "Statement type is not permitted for {$type->value} questions."];
            }
        }

        return ['ok' => true, 'statements' => $statements, 'reason' => null];
    }

    private function isKeywordAllowed(string $inspectedStatement, QuestionType $type): bool
    {
        $trimmed = ltrim($inspectedStatement, " \t\r\n(");
        if (! preg_match('/^([A-Za-z_]+)/', $trimmed, $matches)) {
            return false;
        }

        $keyword = strtoupper($matches[1]);

        return match ($type) {
            QuestionType::SqlSelect, QuestionType::SqlDebug => in_array($keyword, [
                'SELECT',
                'WITH',
                'EXPLAIN',
                'SHOW',
                'DESCRIBE',
                'DESC',
            ], true),
            QuestionType::SqlDml => in_array($keyword, [
                'SELECT',
                'WITH',
                'INSERT',
                'UPDATE',
                'DELETE',
                'REPLACE',
                'EXPLAIN',
                'SHOW',
                'DESCRIBE',
                'DESC',
            ], true),
            QuestionType::SqlDdl => in_array($keyword, [
                'SELECT',
                'WITH',
                'INSERT',
                'UPDATE',
                'DELETE',
                'REPLACE',
                'EXPLAIN',
                'SHOW',
                'DESCRIBE',
                'DESC',
                'CREATE',
                'ALTER',
                'DROP',
                'TRUNCATE',
                'RENAME',
            ], true),
            QuestionType::ShortAnswer, QuestionType::Mcq => false,
        };
    }
}
