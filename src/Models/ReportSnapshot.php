<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use ElgiborSolution\AdvancedReports\Models\Concerns\BelongsToTenant;
use ElgiborSolution\AdvancedReports\Models\Concerns\HasUuid;
use ElgiborSolution\AdvancedReports\Models\Concerns\ResolvesConfiguredUserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property int $report_id
 * @property ?int $report_run_id
 * @property int|string|null $created_by
 * @property ?array $parameters
 * @property array $data
 * @property ?array $metadata
 */
class ReportSnapshot extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;
    use ResolvesConfiguredUserModel;

    protected $guarded = [];

    protected $casts = [
        'parameters' => 'array',
        'data' => 'array',
        'metadata' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'created_by'
        );
    }
}
