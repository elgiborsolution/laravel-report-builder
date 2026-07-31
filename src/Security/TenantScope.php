<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Security;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Expression;

/**
 * Global scope that automatically filters queries to the current tenant.
 * Applied to all package models that use the BelongsToTenant trait.
 *
 * The tenant ID is resolved from:
 *  1. The container binding `advanced-reports.tenant`
 *  2. The model attribute itself (if already set)
 *  3. The config-defined resolver closure
 */
class TenantScope implements Scope
{
    public function __construct(protected Container $container) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (! config('advanced-reports.security.enforce_tenant_scope', false)) {
            return;
        }

        $column = config('advanced-reports.security.tenant_column', 'tenant_id');
        $tenantId = $this->resolveTenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn($column), $tenantId);
        }
    }

    protected function resolveTenantId(): mixed
    {
        // 1) Explicit container binding.
        if ($this->container->bound('advanced-reports.tenant')) {
            return $this->container->make('advanced-reports.tenant');
        }

        // 2) Config-defined resolver closure.
        $resolver = config('advanced-reports.security.tenant_resolver');
        if (is_callable($resolver)) {
            return $resolver();
        }

        // 3) Authenticated user attribute.
        $user = $this->container->make('auth')->user();
        if ($user && isset($user->{config('advanced-reports.security.tenant_column', 'tenant_id')})) {
            return $user->{config('advanced-reports.security.tenant_column', 'tenant_id')};
        }

        return null;
    }

    public function remove(Builder $builder, Model $model): void
    {
        $column = config('advanced-reports.security.tenant_column', 'tenant_id');
        $qualified = $model->qualifyColumn($column);
        $query = $builder->getQuery();

        // Remove the where clause added by apply().
        collect($query->wheres)
            ->reject(fn ($where) => ($where['column'] ?? null) === $qualified)
            ->values()
            ->each(fn ($where) => $query->wheres[] = $where);
    }
}
