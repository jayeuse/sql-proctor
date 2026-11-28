<?php

namespace App\Enums;

enum QueryRunStatus: string
{
    case Succeeded = 'succeeded';
    case SqlError = 'sql_error';
    case Timeout = 'timeout';
    case PolicyBlocked = 'policy_blocked';
    case SystemError = 'system_error';
}
