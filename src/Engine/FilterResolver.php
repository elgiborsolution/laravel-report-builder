<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use ElgiborSolution\AdvancedReports\Support\Operators;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Applies declared filters to the base query. Operator mapping is defined
 * in {@see Operators}; values are resolved through {@see ValueResolver} so
 * that {{param.x}} tokens are substituted.
 */
final class FilterResolver
{
    public function __construct(protected ValueResolver $resolver) {}

    /**
     * @param  ReportSource  $source
     * @param  Builder  $query
     * @param  array<int,array>  $filters
     * @param  array<string,mixed>  $parameters
     */
    public function apply(ReportSource $source, Builder $query, array $filters, array $parameters): Builder
    {
        foreach ($filters as $f) {
            $field = $f['field'] ?? null;
            $operator = strtolower((string) ($f['operator'] ?? '='));
            $value = $f['value'] ?? null;

            if (! $field || ! $source->fields()->has($field)) {
                continue; // Defensive: validator should have caught this.
            }

            $qualified = $source->field($field)->selectExpr ?? $field;
            $resolved = $this->resolver->setParams($parameters)->resolve($value);
            $this->applyOperator($query, $qualified, $operator, $resolved);
        }

        return $query;
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        $normalized = Operators::MAPPING[$operator] ?? $operator;

        // Null operators ignore $value
        if (in_array($normalized, ['Null', 'NotNull'], true)) {
            match ($normalized) {
                'Null' => $query->whereNull($column),
                'NotNull' => $query->whereNotNull($column),
            };

            return;
        }

        // Array-style operators expect a list value
        if (in_array($normalized, ['In', 'NotIn', 'Between', 'NotBetween'], true)) {
            $list = is_array($value) ? array_values($value) : [$value];

            match ($normalized) {
                'In' => $query->whereIn($column, $list),
                'NotIn' => $query->whereNotIn($column, $list),
                'Between' => $query->whereBetween($column, [ $list[0] ?? null, $list[1] ?? null ]),
                'NotBetween' => $query->whereNotBetween($column, [ $list[0] ?? null, $list[1] ?? null ]),
            };

            return;
        }

        // Special date operators
        if (in_array($normalized, ['Date', 'Month', 'Year'], true)) {
            match ($normalized) {
                'Date' => $query->whereDate($column, $value),
                'Month' => $query->whereMonth($column, $value),
                'Year' => $query->whereYear($column, $value),
            };

            return;
        }

        // LIKE / NOT LIKE / ILIKE
        if (in_array($normalized, ['LIKE', 'NOT LIKE', 'ILIKE'], true)) {
            $query->where($column, $normalized, $value);

            return;
        }

        // Standard comparison
        $query->where($column, $normalized, $value);
    }
}
