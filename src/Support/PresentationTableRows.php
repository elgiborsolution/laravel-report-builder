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
     * @return array<int,mixed>
     */
    public static function cells(
        array $presentationRow,
        Collection $detailRows,
        array $columns,
        Formatter $formatter,
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

        $aggregateCells = $presentationRow['aggregate_cells'] ?? [];
        $aggregateFields = array_keys($aggregateCells);
        $labelIndex = null;

        foreach ($columns as $index => $column) {
            $field = $column['field'] ?? $column['name'] ?? '';
            if (! in_array($field, $aggregateFields, true)) {
                $labelIndex = $index;
                break;
            }
        }

        foreach ($columns as $index => $column) {
            $field = $column['field'] ?? $column['name'] ?? '';
            $format = $column['format'] ?? $column['type'] ?? null;
            $values = $aggregateCells[$field] ?? [];

            if ($values !== []) {
                $formatted = array_map(function (array $aggregate) use ($formatter, $format, $values): mixed {
                    $aggregateFormat = self::aggregateFormat($aggregate, $format);
                    $value = $formatter->format($aggregate['value'] ?? null, $aggregateFormat);
                    return count($values) > 1
                        ? (($aggregate['label'] ?? $aggregate['function'] ?? 'Aggregate').': '.(string) $value)
                        : $value;
                }, $values);
                $cells[$index] = count($formatted) === 1 ? $formatted[0] : implode(' | ', $formatted);
            } elseif ($index === $labelIndex) {
                $cells[$index] = $presentationRow['label'] ?? ($type === 'grand_total' ? 'Grand total' : 'Subtotal');
            }
        }

        return $cells;
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

    /** @param array<int,array<string,mixed>> $columns */
    public static function hasLabelCell(array $presentationRow, array $columns): bool
    {
        $aggregateFields = array_keys($presentationRow['aggregate_cells'] ?? []);

        foreach ($columns as $column) {
            $field = $column['field'] ?? $column['name'] ?? '';
            if (! in_array($field, $aggregateFields, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $columns @return array<int,string> */
    public static function labelCells(array $presentationRow, array $columns): array
    {
        $cells = array_fill(0, count($columns), '');
        if ($cells !== []) {
            $cells[0] = $presentationRow['label'] ?? 'Subtotal';
        }

        return $cells;
    }

    /** Keep count and average presentation semantics independent of source type. */
    public static function aggregateFormat(array $aggregate, ?string $columnFormat): ?string
    {
        $function = strtolower((string) ($aggregate['function'] ?? ''));

        if ($function === 'count') {
            return 'integer';
        }

        if ($function === 'avg' && $columnFormat === 'integer') {
            return 'decimal';
        }

        return $columnFormat;
    }
}
