<?php

namespace App\Exceptions;

use RuntimeException;

class SandboxUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'SQL execution service is temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
