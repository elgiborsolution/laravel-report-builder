<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use ElgiborSolution\AdvancedReports\Models\Concerns\BelongsToTenant;
use ElgiborSolution\AdvancedReports\Models\Concerns\HasUuid;
use ElgiborSolution\AdvancedReports\Models\Concerns\ResolvesConfiguredUserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $uuid
 * @property string $name
 * @property string $code
 * @property ?string $description
 * @property string $data_source
 * @property ?array $definition
 * @property int|string|null $created_by
 * @property ?int $tenant_id
 * @property bool $is_public
 * @property bool $is_active
 */
class Report extends BaseModel
{
    use HasUuid;
    use BelongsToTenant;
    use ResolvesConfiguredUserModel;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'definition' => 'array',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeForUser(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === null) {
            return $query->where('is_public', true);
        }

        return $query->where(function (Builder $q) use ($userId) {
            $q->where('is_public', true)
                ->orWhere('created_by', $userId)
                ->orWhereHas('permissions', function (Builder $p) use ($userId) {
                    $p->where('user_id', $userId);
                });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'created_by'
        );
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'report_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class, 'report_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class, 'report_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class, 'report_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ReportPermission::class, 'report_id');
    }
}
