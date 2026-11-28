<?php

namespace App\Enums;

enum AnswerGradingStatus: string
{
    case Unanswered = 'unanswered';
    case AutoGraded = 'auto_graded';
    case NeedsReview = 'needs_review';
    case Graded = 'graded';
}
