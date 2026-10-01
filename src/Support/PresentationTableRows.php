<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

use Illuminate\Support\Collection;

/** Converts the shared presentation contract into column-aligned table rows. */
final class PresentationTableRows
{
    /**
     * @param  array<string,mixed>  $presentationRow
     * @param  Collection<int,array<string,mixed>>  $detailRows
     * @param  array<int,array<string,mixed>>  $columns
     * @param  array<int,array<string,mixed>>  $aggregateDefinitions  Needed for per-aggregate target columns.
     * @param  array<string,mixed>|null  $layout  definition.layout (summary settings).
     * @return array<int,mixed>
     */
    public static function cells(
        array $presentationRow,
        Collection $detailRows,
        array $columns,
        Formatter $formatter,
        array $aggregateDefinitions = [],
        ?array $layout = null,
    ): array {
        $type = $presentationRow['type'] ?? null;
        $cells = array_fill(0, count($columns), '');

        if ($type === 'detail') {
            $row = $detailRows->get((int) ($presentationRow['row_index'] ?? -1), []);
            return self::detailCells(is_array($row) ? $row : [], $columns, $formatter);
        }

        if ($type === 'group_header') {
            if ($cells !== []) {
                $indent = str_repeat('  ', max(0, (int) ($presentationRow['level'] ?? 0)));
                $cells[0] = $indent.($presentationRow['label'] ?? 'Group').': '.($presentationRow['display_value'] ?? '(blank)');
            }

            return $cells;
        }

        if (! in_array($type, ['group_subtotal', 'grand_total'], true)) {
            return $cells;
        }

        // Merged label cells are written to their first column only; spanned
        // columns stay empty so values remain aligned in flat formats (CSV).
        foreach (self::summary($presentationRow, $columns, $formatter, $aggregateDefinitions, $layout)['segments'] as $segment) {
            $cells[$segment['start']] = $segment['text'];
        }

        return $cells;
    }

    /**
     * Resolved summary layout for a subtotal/grand-total row.
     *
     * @return array{label_row:?array<string,mixed>,segments:array<int,array<string,mixed>>}
     */
    public static function summary(
        array $presentationRow,
        array $columns,
        Formatter $formatter,
        array $aggregateDefinitions = [],
        ?array $layout = null,
    ): array {
        return SummaryRowLayout::resolve(
            $presentationRow,
            $columns,
            $aggregateDefinitions,
            $layout,
            static fn (array $aggregate, ?string $format) => $formatter->format($aggregate['value'] ?? null, $format),
        );
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<int,array<string,mixed>>  $columns
     * @return array<int,mixed>
     */
    public static function detailCells(array $row, array $columns, Formatter $formatter): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $field = $column['field'] ?? $column['name'] ?? null;
            $format = $column['format'] ?? $column['type'] ?? null;
            $cells[] = $formatter->format($field !== null ? data_get($row, $field) : null, $format);
        }

        return $cells;
    }

    /**
     * False when a label has no free column left, in which case it is
     * emitted on its own row above the values (see labelCells()).
     *
     * @param  array<int,array<string,mixed>>  $columns
     */
    public static function hasLabelCell(array $presentationRow, array $columns, array $aggregateDefinitions = [], ?array $layout = null): bool
    {
        return self::rawSummary($presentationRow, $columns, $aggregateDefinitions, $layout)['label_row'] === null;
    }

    /** @param array<int,array<string,mixed>> $columns @return array<int,string> */
    public static function labelCells(array $presentationRow, array $columns, array $aggregateDefinitions = [], ?array $layout = null): array
    {
        $cells = array_fill(0, count($columns), '');
        if ($cells !== []) {
            $cells[0] = self::rawSummary($presentationRow, $columns, $aggregateDefinitions, $layout)['label_row']['text']
                ?? SummaryRowLayout::LABELS[$presentationRow['type'] ?? ''] ?? 'Subtotal';
        }

        return $cells;
    }

    /** Keep count and average presentation semantics independent of source type. */
    public static function aggregateFormat(array $aggregate, ?string $columnFormat): ?string
    {
        return SummaryRowLayout::aggregateFormat($aggregate, $columnFormat);
    }

    private static function rawSummary(array $presentationRow, array $columns, array $aggregateDefinitions, ?array $layout): array
    {
        return SummaryRowLayout::resolve(
            $presentationRow,
            $columns,
            $aggregateDefinitions,
            $layout,
            static fn (array $aggregate) => $aggregate['value'] ?? null,
        );
    }
}
