<?php

namespace App\Enums;

enum ExpectedBehavior: string
{
    case MatchReference = 'match_reference';
    case ExpectError = 'expect_error';
    case NonEmpty = 'non_empty';
}
