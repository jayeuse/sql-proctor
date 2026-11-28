<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Domain HTTP exception carrying a stable machine-readable error code.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        int $statusCode,
        string $message,
        private readonly ?string $errorCode = null,
        array $headers = [],
        ?Throwable $previous = null,
        private readonly array $extra = [],
    ) {
        parent::__construct($statusCode, $message, $previous, $headers);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    public static function forbidden(string $message, ?string $errorCode = null, array $headers = []): self
    {
        return new self(403, $message, $errorCode, $headers);
    }

    public static function notFound(string $message = 'Resource not found.', ?string $errorCode = null): self
    {
        return new self(404, $message, $errorCode);
    }

    public static function conflict(string $message, string $errorCode, array $headers = []): self
    {
        return new self(409, $message, $errorCode, $headers);
    }

    public static function locked(string $message, string $errorCode, array $headers = []): self
    {
        return new self(423, $message, $errorCode, $headers);
    }

    public static function tooManyRequests(string $message, string $errorCode = 'too_many_attempts', array $headers = []): self
    {
        return new self(429, $message, $errorCode, $headers);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function unprocessable(string $message, string $errorCode, array $extra = []): self
    {
        return new self(422, $message, $errorCode, [], null, $extra);
    }
}
