<?php

namespace App\Enums;

enum AttemptGradingStatus: string
{
    case Pending = 'pending';
    case AutoGraded = 'auto_graded';
    case NeedsReview = 'needs_review';
    case Finalized = 'finalized';
}
