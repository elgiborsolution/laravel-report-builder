<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Support\Formatter;

/**
 * JSON renderer. Returns a structured array with metadata, columns, rows,
 * groups, aggregates, and drilldowns. Ideal for API endpoints and SPAs.
 */
final class JsonRenderer implements ReportRenderer
{
    public function __construct(protected Formatter $formatter) {}

    public function format(): string
    {
        return 'json';
    }

    public function render(ReportResult $result, array $options = []): array
    {
        $columns = $this->prepareColumns($result);
        $rows = $this->formatRows($result);

        return [
            'metadata' => array_merge($result->metadata, [
                'report' => [
                    'uuid' => $result->report->uuid,
                    'code' => $result->report->code,
                    'name' => $result->report->name,
                    'data_source' => $result->report->data_source,
                ],
                'generated_at' => now()->toIso8601String(),
            ]),
            'parameters' => $result->parameters,
            'columns' => $columns,
            'rows' => $rows,
            'groups' => $result->groups,
            'aggregates' => $result->aggregates,
            'formulas' => array_map(fn ($f) => [
                'name' => $f['name'] ?? null,
                'type' => $f['type'] ?? 'string',
                'format' => $f['format'] ?? null,
            ], $result->formulas),
            'drilldowns' => array_map(fn ($dd) => [
                'trigger' => $dd['trigger'] ?? null,
                'type' => $dd['type'] ?? 'report',
                'target' => $dd['target'] ?? null,
                '_meta' => $dd['_meta'] ?? null,
            ], $result->drilldowns),
            'conditional_formatting' => $result->conditionalFormatting,
            'layout' => $result->layout,
        ];
    }

    /**
     * @return array<int, array{field:string, label:string, type:string, format:?string}>
     */
    protected function prepareColumns(ReportResult $result): array
    {
        return array_map(fn ($col) => [
            'field' => $col['field'] ?? $col['name'] ?? '',
            'label' => $col['label'] ?? ucfirst($col['field'] ?? ''),
            'type' => $col['type'] ?? 'string',
            'format' => $col['format'] ?? null,
        ], $result->columns);
    }

    /**
     * Format row values while preserving the associative structure.
     *
     * @return array<int, array<string,mixed>>
     */
    protected function formatRows(ReportResult $result): array
    {
        $columns = $result->columns;
        $formulas = $result->formulas;

        return $result->rows->map(function (array $row) use ($columns, $formulas) {
            foreach ($columns as $col) {
                $field = $col['field'] ?? null;
                $format = $col['format'] ?? $col['type'] ?? null;
                if ($field !== null && array_key_exists($field, $row)) {
                    $row[$field] = $this->formatter->format($row[$field], $format);
                }
            }

            foreach ($formulas as $f) {
                $name = $f['name'] ?? null;
                $format = $f['format'] ?? $f['type'] ?? null;
                if ($name !== null && array_key_exists($name, $row)) {
                    $row[$name] = $this->formatter->format($row[$name], $format);
                }
            }

            return $row;
        })->values()->all();
    }
}
