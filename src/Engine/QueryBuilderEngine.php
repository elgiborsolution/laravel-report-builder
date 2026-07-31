<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Builds and executes the underlying query for a report. Composes the source
 * base query with column selection, filters, and sorts. Then materializes
 * the result set as a Collection (small reports) or LazyCollection (large
 * exports) depending on configuration.
 */
final class QueryBuilderEngine
{
    public function __construct(
        protected FilterResolver $filters,
        protected SortResolver $sorts,
    ) {}

    /**
     * @return array{query:Builder, columns:array<int,array>}
     */
    public function build(ReportSource $source, ReportDefinition $definition, array $parameters): array
    {
        $query = $source->query($parameters);

        // SELECT: only declared columns; ensure we never dump hidden fields.
        $columns = $this->resolveColumns($source, $definition);

        // Apply filters and sorts via resolvers (validated upstream).
        $query = $this->filters->apply($source, $query, $definition->filters, $parameters);
        $query = $this->sorts->apply($source, $query, $definition->sorts);

        return ['query' => $query, 'columns' => $columns];
    }

    /**
     * Materialize the result rows. Honors lazy-collection + row-limit config.
     */
    public function fetch(Builder $query): Collection|LazyCollection
    {
        $useLazy = (bool) config('advanced-reports.performance.use_lazy_collections', true);
        $limit = (int) config('advanced-reports.performance.row_limit', 0);

        if ($limit > 0) {
            $query->limit($limit);
        }

        if ($useLazy) {
            return LazyCollection::make(fn () => yield from $query->cursor());
        }

        return $query->get();
    }

    /**
     * Count rows via a COUNT query without consuming the row cursor.
     * Required for LazyCollection results so the row stream stays intact
     * for renderers/exporters.
     */
    public function count(Builder $query): int
    {
        $limit = (int) config('advanced-reports.performance.row_limit', 0);

        // Clone so the original query's cursor isn't consumed.
        return (clone $query)->when($limit > 0, fn ($q) => $q->limit($limit))->count();
    }

    /**
     * Resolve which columns to SELECT and which to display. Hidden fields
     * are excluded from display but may still be in the SELECT if referenced
     * by an aggregate/formula/filter.
     *
     * @return array<int,array> Display columns (label/field/format).
     */
    protected function resolveColumns(ReportSource $source, ReportDefinition $definition): array
    {
        $declared = $definition->columns;

        // Reject hidden fields from display.
        return array_values(array_filter($declared, function ($col) use ($source) {
            $field = $col['field'] ?? null;

            return $field
                && $source->fields()->has($field)
                && ! $source->field($field)?->hidden;
        }));
    }
}
