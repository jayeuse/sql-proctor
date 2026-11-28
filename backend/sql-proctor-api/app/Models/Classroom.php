<?php

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['examiner_id', 'name', 'join_code', 'is_open', 'code_expires_at'])]
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'code_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    /**
     * @return HasMany<StudentSession, $this>
     */
    public function studentSessions(): HasMany
    {
        return $this->hasMany(StudentSession::class);
    }

    /**
     * @return HasMany<StudentSession, $this>
     */
    public function activeStudentSessions(): HasMany
    {
        return $this->studentSessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->code_expires_at !== null && $this->code_expires_at->isPast();
    }
}
