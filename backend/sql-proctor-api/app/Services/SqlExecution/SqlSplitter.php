<?php

namespace App\Services\SqlExecution;

class SqlSplitter
{
    /**
     * Split a SQL script on top-level semicolons, ignoring quoted strings, identifiers, and comments.
     *
     * @return array<int, string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $current .= $ch;
                if ($ch === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                $current .= $ch;
                if ($ch === '*' && $next === '/') {
                    $current .= '/';
                    $i++;
                    $inBlockComment = false;
                }

                continue;
            }

            if ($inSingle) {
                $current .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($ch === "'" && $next === "'") {
                    $current .= "'";
                    $i++;
                } elseif ($ch === "'") {
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                $current .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($ch === '"' && $next === '"') {
                    $current .= '"';
                    $i++;
                } elseif ($ch === '"') {
                    $inDouble = false;
                }

                continue;
            }

            if ($inBacktick) {
                $current .= $ch;
                if ($ch === '`' && $next === '`') {
                    $current .= '`';
                    $i++;
                } elseif ($ch === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            if ($ch === '-' && $next === '-') {
                $third = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($third === ' ' || $third === "\t" || $third === "\r" || $third === "\n" || $third === '') {
                    $inLineComment = true;
                    $current .= '--';
                    $i++;

                    continue;
                }
            }

            if ($ch === '#') {
                $inLineComment = true;
                $current .= $ch;

                continue;
            }

            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $current .= '/*';
                $i++;

                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;

                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                $current .= $ch;

                continue;
            }

            if ($ch === '`') {
                $inBacktick = true;
                $current .= $ch;

                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '' && self::hasExecutableContent($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        $trailing = trim($current);
        if ($trailing !== '' && self::hasExecutableContent($trailing)) {
            $statements[] = $trailing;
        }

        return $statements;
    }

    /**
     * Strip comments (exposing conditional MariaDB comments /*! or /*M! ... * /) and blank string literals for static keyword checks.
     */
    public static function sanitizeForInspection(string $sql): string
    {
        $out = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $out .= "\n";
                }

                continue;
            }

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $i++;
                    $inBlockComment = false;
                    $out .= ' ';
                }

                continue;
            }

            if ($inSingle) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                } elseif ($ch === "'" && $next === "'") {
                    $i++;
                } elseif ($ch === "'") {
                    $inSingle = false;
                    $out .= "'";
                }

                continue;
            }

            if ($inDouble) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                } elseif ($ch === '"' && $next === '"') {
                    $i++;
                } elseif ($ch === '"') {
                    $inDouble = false;
                    $out .= '"';
                }

                continue;
            }

            if ($inBacktick) {
                $out .= $ch;
                if ($ch === '`' && $next === '`') {
                    $out .= '`';
                    $i++;
                } elseif ($ch === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            if ($ch === '-' && $next === '-') {
                $third = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($third === ' ' || $third === "\t" || $third === "\r" || $third === "\n" || $third === '') {
                    $inLineComment = true;
                    $i++;

                    continue;
                }
            }

            if ($ch === '#') {
                $inLineComment = true;

                continue;
            }

            if ($ch === '/' && $next === '*') {
                $third = $i + 2 < $length ? $sql[$i + 2] : '';
                $fourth = $i + 3 < $length ? $sql[$i + 3] : '';
                if ($third === '!' || ($third === 'M' && $fourth === '!')) {
                    // Unwrap executable MariaDB conditional comments (/*! or /*M!) so guardrails inspect inner tokens.
                    $i += $third === '!' ? 2 : 3;
                    while ($i + 1 < $length && ctype_digit($sql[$i + 1])) {
                        $i++;
                    }
                    $out .= ' ';

                    continue;
                }

                $inBlockComment = true;
                $i++;

                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $out .= "'";

                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                $out .= '"';

                continue;
            }

            if ($ch === '`') {
                $inBacktick = true;
                $out .= $ch;

                continue;
            }

            $out .= $ch;
        }

        // Remove trailing close-comment marker if a /*! ... */ comment was unwrapped.
        return str_replace('*/', ' ', $out);
    }

    private static function hasExecutableContent(string $statement): bool
    {
        return trim(self::sanitizeForInspection($statement)) !== '';
    }
}
