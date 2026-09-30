<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Applies ORDER BY clauses. Direction is validated upstream by the validator.
 */
final class SortResolver
{
    /**
     * @param  array<int,array>  $sorts
     */
    public function apply(ReportSourceContract $source, Builder $query, array $sorts): Builder
    {
        foreach ($sorts as $s) {
            $field = $s['field'] ?? null;
            if (! $field || ! $source->fields()->has($field)) {
                continue;
            }

            $qualified = $source->field($field)->selectExpr ?? $field;
            $direction = strtolower((string) ($s['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $query->orderBy($qualified, $direction);
        }

        return $query;
    }
}
