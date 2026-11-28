<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case AutoSubmitted = 'auto_submitted';
    case ForceClosed = 'force_closed';
}
