<?php

namespace App\Models;

use App\Exceptions\ImmutableExamVersionException;
use Database\Factories\ExamVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exam_id',
    'version_number',
    'snapshot',
    'published_by',
    'published_at',
])]
class ExamVersion extends Model
{
    /** @use HasFactory<ExamVersionFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new ImmutableExamVersionException;
        });

        static::deleting(static function (): void {
            throw new ImmutableExamVersionException;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
