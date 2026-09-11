<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Bridge;

use ElgiborSolution\AdvancedReports\Models\BaseModel;
use ElgiborSolution\AdvancedReports\Models\Concerns\ResolvesConfiguredUserModel;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persists a connection between a DataSource and the report engine's
 * source registry. Each row represents one dynamic source that should
 * be auto-registered at application boot.
 *
 * @property int $id
 * @property int $data_source_id
 * @property string $source_key
 * @property ?int $tenant_id
 * @property int|string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ConnectedSource extends BaseModel
{
    use ResolvesConfiguredUserModel;

    protected $table = 'advanced_report_connected_sources';

    protected $guarded = [];

    protected $casts = [
        'data_source_id' => 'integer',
        'tenant_id' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'created_by'
        );
    }
}
