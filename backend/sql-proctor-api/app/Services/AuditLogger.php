<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Http\Middleware\AssignRequestId;
use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonSerializable;
use UnitEnum;

/**
 * Centralized audit log writer enforcing sensitive-key redaction and request correlation.
 */
class AuditLogger
{
    /**
     * Keys containing any of these lowercase tokens are redacted recursively before persistence.
     *
     * @var array<int, string>
     */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'cookie',
        'authorization',
        'xsrf',
        'secret',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        AuditEvent|string $eventType,
        ActorType $actorType,
        ?string $actorId = null,
        ?string $actorLabel = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $resolvedRequest = $request ?? (app()->bound('request') ? app('request') : null);
        $hasHttpContext = $resolvedRequest instanceof Request
            && $resolvedRequest->attributes->has(AssignRequestId::ATTRIBUTE);

        $sanitizedMetadata = $this->redact($metadata);
        $requestId = null;
        $ipAddress = null;
        $userAgent = null;

        if ($hasHttpContext) {
            $rawRequestId = (string) $resolvedRequest->attributes->get(AssignRequestId::ATTRIBUTE);

            if (Str::isUuid($rawRequestId)) {
                $requestId = $rawRequestId;
            } else {
                $requestId = (string) Str::uuid();
                $sanitizedMetadata['client_request_id'] = $rawRequestId;
            }

            $ipAddress = $resolvedRequest->ip();
            $rawAgent = $resolvedRequest->userAgent();
            $userAgent = is_string($rawAgent) && $rawAgent !== ''
                ? Str::limit($rawAgent, 512, '')
                : null;
        }

        $eventName = $eventType instanceof AuditEvent ? $eventType->value : $eventType;

        return AuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel !== null ? Str::limit($actorLabel, 120, '') : null,
            'event_type' => $eventName,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $sanitizedMetadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $sanitized[$key] = $this->redactValue($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function redactValue(object $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $this->redact($serialized) : $serialized;
        }

        return method_exists($value, 'toArray')
            ? $this->redact($value->toArray())
            : $this->redact(get_object_vars($value));
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = Str::lower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
