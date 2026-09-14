<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use ElgiborSolution\AdvancedReports\Models\Concerns\BelongsToTenant;
use ElgiborSolution\AdvancedReports\Models\Concerns\HasUuid;
use ElgiborSolution\AdvancedReports\Models\Concerns\ResolvesConfiguredUserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property int $report_id
 * @property ?int $report_run_id
 * @property int|string|null $user_id
 * @property string $format // html|pdf|xlsx|csv|json
 * @property string $status  // pending|processing|completed|failed
 * @property ?string $file_path
 * @property ?string $disk
 * @property ?array $options
 * @property ?string $completed_at
 * @property ?string $error_message
 */
class ReportExport extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;

    protected $table = 'advanced_report_exports';
    use ResolvesConfiguredUserModel;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'completed_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'user_id'
        );
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->save();

        return $this;
    }

    public function markCompleted(string $path, string $disk): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->file_path = $path;
        $this->disk = $disk;
        $this->completed_at = now();
        $this->save();

        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $error;
        $this->save();

        return $this;
    }
}
