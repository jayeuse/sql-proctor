<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Exceptions\ImmutableAuditLogException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'actor_type',
    'actor_id',
    'actor_label',
    'event_type',
    'target_type',
    'target_id',
    'metadata',
    'ip_address',
    'user_agent',
    'request_id',
    'created_at',
])]
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new ImmutableAuditLogException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new ImmutableAuditLogException('Audit logs are append-only and cannot be deleted.');
        });
    }
}
