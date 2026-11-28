<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableExamVersionException extends RuntimeException
{
    public function __construct(string $message = 'Published exam versions are immutable and cannot be modified or deleted.')
    {
        parent::__construct($message);
    }
}
