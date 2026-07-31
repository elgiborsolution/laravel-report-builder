<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use ElgiborSolution\AdvancedReports\Models\Concerns\BelongsToTenant;
use ElgiborSolution\AdvancedReports\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property int $report_id
 * @property ?int $report_run_id
 * @property ?int $created_by
 * @property ?array $parameters
 * @property array $data
 * @property ?array $metadata
 */
class ReportSnapshot extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;

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
            config('auth.providers.users.model', \App\Models\User::class),
            'created_by'
        );
    }
}
