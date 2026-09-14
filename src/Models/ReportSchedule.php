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
 * @property int|string|null $user_id
 * @property string $name
 * @property string $cron_expression
 * @property ?array $parameters
 * @property string $format
 * @property ?array $recipients
 * @property bool $is_active
 * @property ?string $last_run_at
 * @property ?string $next_run_at
 */
class ReportSchedule extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;

    protected $table = 'advanced_report_schedules';
    use ResolvesConfiguredUserModel;

    protected $guarded = [];

    protected $casts = [
        'parameters' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'user_id'
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function markRan(): self
    {
        $this->last_run_at = now();
        $this->next_run_at = \Cron\CronExpression::factory($this->cron_expression)
            ->getNextRunDate();
        $this->save();

        return $this;
    }
}
