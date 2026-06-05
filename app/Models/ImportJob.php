<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'import_jobs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'account_id',
        'user_id',
        'vault_id',
        'filename',
        'original_file_path',
        'file_hash',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'status',
        'errors',
        'started_at',
        'completed_at',
        'cancelled_at',
        'batch_size',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'failed_rows' => 'integer',
        'batch_size' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPct(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) round(($this->processed_rows / $this->total_rows) * 100);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function scopeByAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
