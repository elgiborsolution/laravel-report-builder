<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use ElgiborSolution\AdvancedReports\Models\Concerns\BelongsToTenant;
use ElgiborSolution\AdvancedReports\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property int $report_id
 * @property ?int $user_id
 * @property ?array $parameters
 * @property string $status // pending|running|completed|failed
 * @property int $row_count
 * @property ?array $summary
 * @property ?string $started_at
 * @property ?string $finished_at
 * @property ?string $error_message
 */
class ReportRun extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'parameters' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            config('auth.providers.users.model', \App\Models\User::class),
            'user_id'
        );
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function markStarted(): self
    {
        $this->status = self::STATUS_RUNNING;
        $this->started_at = now();
        $this->save();

        return $this;
    }

    public function markCompleted(int $rows, ?array $summary = null): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->row_count = $rows;
        $this->summary = $summary;
        $this->finished_at = now();
        $this->save();

        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $error;
        $this->finished_at = now();
        $this->save();

        return $this;
    }
}
