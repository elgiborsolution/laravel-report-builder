<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
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
    public function build(ReportSourceContract $source, ReportDefinition $definition, array $parameters): array
    {
        $query = $source->query($parameters);

        // Grouping and aggregate inputs are execution-only fields: they must
        // be fetched even when omitted from the user-visible Columns list.
        $this->ensureCalculationFieldsSelected($source, $query, $definition);

        // Resolve the user-visible columns separately from the source query.
        $columns = $this->resolveColumns($source, $definition);

        // Apply filters and sorts via resolvers (validated upstream).
        $query = $this->filters->apply($source, $query, $definition->filters, $parameters);
        $query = $this->sorts->apply($source, $query, $definition->sorts);

        return ['query' => $query, 'columns' => $columns];
    }

    /**
     * A source may intentionally return a partial SELECT. Add simple source
     * fields used by grouping/aggregates in that case; default wildcard
     * queries already carry them, while dotted relation paths remain the
     * source's responsibility (and are read with data_get after hydration).
     */
    protected function ensureCalculationFieldsSelected(
        ReportSourceContract $source,
        Builder $query,
        ReportDefinition $definition,
    ): void {
        $selected = $query->columns ?? null;
        if ($selected === null) {
            return;
        }

        $selected = is_array($selected) ? $selected : [$selected];
        if (in_array('*', $selected, true)) {
            return;
        }

        $formulaNames = collect($definition->formulas)->pluck('name')->filter()->all();
        $fields = array_unique(array_merge(
            array_map(static fn (array $group) => $group['field'] ?? '', $definition->groups),
            array_map(static fn (array $aggregate) => $aggregate['field'] ?? '', $definition->aggregates),
        ));

        foreach ($fields as $field) {
            if (! is_string($field)
                || $field === ''
                || in_array($field, $formulaNames, true)
                || str_contains($field, '.')
                || $this->selectionContainsField($selected, $field)) {
                continue;
            }

            $sourceField = $source->field($field);
            if (! $sourceField) {
                continue;
            }

            $expression = $sourceField->selectExpr ?? $field;
            if ($expression !== $field) {
                // selectExpr belongs to the PHP source definition, not the
                // report definition. Alias it back to the public field key.
                $expression = preg_replace('/\\s+as\\s+.+$/i', '', $expression) ?: $expression;
                $expression = new \Illuminate\Database\Query\Expression(
                    $expression.' as '.$query->getGrammar()->wrap($field),
                );
            }

            $query->addSelect($expression);
        }
    }

    /** @param array<int,mixed> $selected */
    protected function selectionContainsField(array $selected, string $field): bool
    {
        foreach ($selected as $expression) {
            if (! is_string($expression)) {
                continue;
            }

            $expression = trim(str_replace(['`', '"', '[', ']'], '', $expression));
            if ($expression === $field || str_ends_with($expression, '.'.$field)) {
                return true;
            }

            if (preg_match('/\\bas\\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i', $expression, $matches)
                && $matches[1] === $field) {
                return true;
            }
        }

        return false;
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
    protected function resolveColumns(ReportSourceContract $source, ReportDefinition $definition): array
    {
        $formulae = collect($definition->formulas)->keyBy('name');

        return array_values(array_filter(array_map(function (array $column) use ($source, $formulae) {
            $field = $column['field'] ?? null;
            if (! $field) {
                return null;
            }

            $sourceField = $source->field($field);
            if ($sourceField) {
                if ($sourceField->hidden) {
                    return null;
                }

                return $column;
            }

            $formula = $formulae->get($field);
            if (! $formula) {
                return null;
            }

            // Formula identifiers are virtual row keys. Never use them as
            // SQL identifiers; FormulaResolver injects them after fetching.
            $column['label'] = trim((string) ($column['label'] ?? '')) !== ''
                ? $column['label']
                : ($formula['label'] ?? $field);
            $column['type'] = $column['type'] ?? $formula['type'] ?? 'string';
            $column['format'] = ($column['format'] ?? '') !== ''
                ? $column['format']
                : ($formula['format'] ?? null);

            return $column;
        }, $definition->columns), static fn ($column) => $column !== null));
    }
}
