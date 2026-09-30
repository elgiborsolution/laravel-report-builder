<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\DrilldownResolver;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;

/**
 * HTML renderer. Produces a Blade-powered table with:
 *  - Grouped row headers/footers
 *  - Aggregate footer row
 *  - Drilldown links
 *  - Conditional formatting
 */
final class HtmlRenderer implements ReportRenderer
{
    public function __construct(
        protected Formatter $formatter,
        protected DrilldownResolver $drilldowns,
        protected ValueResolver $resolver,
    ) {}

    public function format(): string
    {
        return 'html';
    }

    public function render(ReportResult $result, array $options = []): string
    {
        $rows = $result->rows->all();
        $columns = $this->prepareColumns($result);
        $formattedRows = $this->formatRows($result);
        $drilldownMap = $this->buildDrilldownMap($result);
        $conditionalStyles = $this->buildConditionalStyles($result);

        return view('advanced-reports::reports.html', [
            'report' => $result->report,
            'definition' => $result->definition,
            'columns' => $columns,
            'rows' => $formattedRows,
            'rawRows' => $rows,
            'groups' => $result->groups,
            'aggregates' => $result->aggregates,
            'drilldowns' => $result->drilldowns,
            'drilldownMap' => $drilldownMap,
            'conditionalStyles' => $conditionalStyles,
            'parameters' => $result->parameters,
            'metadata' => $result->metadata,
            'layout' => $result->layout,
        ])->render();
    }

    /**
     * @return array<int, array{field:string, label:string, format:?string}>
     */
    protected function prepareColumns(ReportResult $result): array
    {
        return array_map(fn ($col) => [
            'field' => $col['field'] ?? $col['name'] ?? '',
            'label' => $col['label'] ?? ucfirst($col['field'] ?? $col['name'] ?? ''),
            'format' => $col['format'] ?? $col['type'] ?? 'string',
        ], $result->columns);
    }

    /**
     * Format every cell value using the column's declared format.
     *
     * @return array<int, array<string,mixed>>
     */
    protected function formatRows(ReportResult $result): array
    {
        $rows = $result->rows->all();
        $columns = $result->columns;

        return array_map(function (array $row) use ($columns) {
            foreach ($columns as $col) {
                $field = $col['field'] ?? $col['name'] ?? null;
                $format = $col['format'] ?? $col['type'] ?? null;
                if ($field !== null && array_key_exists($field, $row)) {
                    $row[$field] = $this->formatter->format($row[$field], $format);
                }
            }

            return $row;
        }, $rows);
    }

    /**
     * Build a map of field => drilldown metadata per column for fast lookup.
     *
     * @return array<string, array{type:string, target:string, parameters:array}>
     */
    protected function buildDrilldownMap(ReportResult $result): array
    {
        $map = [];
        foreach ($result->drilldowns as $dd) {
            $trigger = $dd['trigger'] ?? null;
            if ($trigger) {
                $map[$trigger] = $dd['_meta'] ?? $dd;
            }
        }

        return $map;
    }

    /**
     * Build per-field conditional formatting rules.
     *
     * @return array<string, array<int, array{operator:string, value:mixed, style:array}>>
     */
    protected function buildConditionalStyles(ReportResult $result): array
    {
        $styles = [];
        foreach ($result->conditionalFormatting as $cf) {
            $field = $cf['field'] ?? null;
            if ($field) {
                $styles[$field][] = [
                    'operator' => $cf['operator'] ?? '=',
                    'value' => $cf['value'] ?? null,
                    'style' => $cf['style'] ?? [],
                ];
            }
        }

        return $styles;
    }
}
