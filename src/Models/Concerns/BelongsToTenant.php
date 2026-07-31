<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Opt-in tenant scoping. Models use this trait when multi-tenant isolation
 * is required. The tenant resolver is configurable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (! config('advanced-reports.security.enforce_tenant_scope', false)) {
                return;
            }

            $column = config('advanced-reports.security.tenant_column', 'tenant_id');
            $tenantId = app()->bound('advanced-reports.tenant')
                ? app('advanced-reports.tenant')
                : null;

            if ($tenantId !== null) {
                $builder->where($builder->qualifyColumn($column), $tenantId);
            }
        });
    }
}
