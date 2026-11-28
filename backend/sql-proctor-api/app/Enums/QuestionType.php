<?php

namespace App\Enums;

enum QuestionType: string
{
    case SqlSelect = 'sql_select';
    case SqlDml = 'sql_dml';
    case SqlDdl = 'sql_ddl';
    case SqlDebug = 'sql_debug';
    case ShortAnswer = 'short_answer';
    case Mcq = 'mcq';

    public function isSql(): bool
    {
        return match ($this) {
            self::SqlSelect, self::SqlDml, self::SqlDdl, self::SqlDebug => true,
            self::ShortAnswer, self::Mcq => false,
        };
    }
}
