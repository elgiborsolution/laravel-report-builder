<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Orders detail rows by an ordered, nested group path and creates the shared
 * presentation-row contract consumed by preview and output renderers.
 * Grouping intentionally happens in PHP; source rows are never collapsed.
 */
final class GroupResolver
{
    /**
     * @param  Collection<int,array>|LazyCollection<int,array>  $rows
     * @param  array<int,array{field:string,label?:string}>  $groups
     * @return array{
     *     rows:Collection<int,array>,
     *     groups:array<string,array<int,array{key:string,label:string,value:mixed}>>,
     *     group_tree:array<string,array>,
     *     group_row_indexes:array<string,array<int,int>>,
     *     has_groups:bool
     * }
     */
    public function apply(Collection|LazyCollection $rows, array $groups): array
    {
        $groups = array_values(array_filter($groups, static fn ($group) => is_array($group) && ! empty($group['field'])));

        if ($groups === []) {
            return [
                'rows' => $rows,
                'groups' => [],
                'group_tree' => [],
                'group_row_indexes' => [],
                'has_groups' => false,
            ];
        }

        $detailRows = ($rows instanceof LazyCollection ? $rows->collect() : $rows)->values();

        $entries = $detailRows->map(fn ($row, $index) => ['row' => $row, 'original_index' => $index])->all();
        usort($entries, function (array $left, array $right) use ($groups): int {
            foreach ($groups as $group) {
                $field = (string) $group['field'];
                $comparison = $this->compareValues(
                    data_get($left['row'], $field),
                    data_get($right['row'], $field),
                );

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['original_index'] <=> $right['original_index'];
        });

        $sortedRows = collect(array_map(static fn (array $entry) => $entry['row'], $entries))->values();
        $tree = [];
        $groupRowIndexes = [];
        $legacyGroups = [];

        foreach ($entries as $rowIndex => $entry) {
            $row = $entry['row'];
            $nodes = &$tree;
            $path = [];

            foreach ($groups as $level => $group) {
                $field = (string) $group['field'];
                $label = trim((string) ($group['label'] ?? '')) ?: ucfirst($field);
                $value = data_get($row, $field);
                $segment = [
                    'field' => $field,
                    'label' => $label,
                    'value' => $value,
                    'display_value' => $this->displayValue($value),
                ];
                $path[] = $segment;
                $pathKey = $this->pathKey($path);

                if (! isset($nodes[$pathKey])) {
                    $nodes[$pathKey] = [
                        'key' => $pathKey,
                        'field' => $field,
                        'label' => $label,
                        'value' => $value,
                        'display_value' => $segment['display_value'],
                        'level' => $level,
                        'path' => $path,
                        'detail_start' => $rowIndex,
                        'detail_end' => $rowIndex,
                        'detail_indexes' => [],
                        'children' => [],
                    ];
                }

                $nodes[$pathKey]['detail_end'] = $rowIndex;
                $nodes[$pathKey]['detail_indexes'][] = $rowIndex;
                $groupRowIndexes[$pathKey][] = $rowIndex;

                $legacyGroups[$field] ??= [];
                $valueKey = $this->valueKey($value);
                $legacyGroups[$field][$valueKey] ??= [
                    'key' => $field,
                    'label' => $label,
                    'value' => $value,
                ];

                $nodes = &$nodes[$pathKey]['children'];
            }

            unset($nodes);
        }

        foreach ($legacyGroups as $field => $values) {
            $legacyGroups[$field] = array_values($values);
        }

        return [
            'rows' => $sortedRows,
            // Keep the existing flat groups shape for API compatibility.
            'groups' => $legacyGroups,
            'group_tree' => $tree,
            'group_row_indexes' => $groupRowIndexes,
            'has_groups' => true,
        ];
    }

    /**
     * Build a sequence of group headers, detail references, subtotals, and a
     * grand total. Detail rows remain exclusively in ReportResult::rows.
     *
     * @param  array<string,mixed>  $grouping  Result returned by apply().
     * @param  array<string,array<string,mixed>>  $groupAggregates
     * @param  array<string,mixed>  $grandTotals
     * @param  array<int,array{field:string,function?:string,label?:string}>  $aggregateDefinitions
     * @return array<int,array<string,mixed>>
     */
    public function presentationRows(
        array $grouping,
        array $groupAggregates,
        array $grandTotals,
        array $aggregateDefinitions,
        int $detailCount,
    ): array {
        $presentationRows = [];
        $hasAggregates = $aggregateDefinitions !== [];
        $hasGroups = (bool) ($grouping['has_groups'] ?? (($grouping['group_tree'] ?? []) !== []));

        $appendNodes = function (array $nodes) use (&$appendNodes, &$presentationRows, $groupAggregates, $aggregateDefinitions, $hasAggregates): void {
            foreach ($nodes as $node) {
                $presentationRows[] = [
                    'type' => 'group_header',
                    'key' => $node['key'],
                    'field' => $node['field'],
                    'label' => $node['label'],
                    'value' => $node['value'],
                    'display_value' => $node['display_value'],
                    'level' => $node['level'],
                    'path' => $node['path'],
                    'detail_start' => $node['detail_start'],
                    'detail_end' => $node['detail_end'],
                ];

                if ($node['children'] !== []) {
                    $appendNodes($node['children']);
                } else {
                    foreach ($node['detail_indexes'] as $rowIndex) {
                        $presentationRows[] = [
                            'type' => 'detail',
                            'row_index' => $rowIndex,
                        ];
                    }
                }

                if ($hasAggregates) {
                    $values = $groupAggregates[$node['key']] ?? [];
                    $presentationRows[] = [
                        'type' => 'group_subtotal',
                        'key' => $node['key'],
                        'field' => $node['field'],
                        'label' => 'Subtotal: '.$node['label'].' — '.$node['display_value'],
                        'group_label' => $node['label'],
                        'group_value' => $node['value'],
                        'display_value' => $node['display_value'],
                        'level' => $node['level'],
                        'path' => $node['path'],
                        'detail_start' => $node['detail_start'],
                        'detail_end' => $node['detail_end'],
                        'aggregate_values' => $values,
                        'aggregate_cells' => $this->aggregateCells($values, $aggregateDefinitions),
                    ];
                }
            }
        };

        if (! $hasGroups) {
            // Ungrouped details remain implicit so lazy exports stay streamed.
            // Renderers/API adapters can expand these references when they
            // already materialize detail rows for a table response.
        } elseif (($grouping['group_tree'] ?? []) !== []) {
            $appendNodes($grouping['group_tree']);
        } else {
            for ($rowIndex = 0; $rowIndex < $detailCount; $rowIndex++) {
                $presentationRows[] = [
                    'type' => 'detail',
                    'row_index' => $rowIndex,
                ];
            }
        }

        if ($hasAggregates) {
            $presentationRows[] = [
                'type' => 'grand_total',
                'label' => 'Grand total',
                'detail_start' => $detailCount > 0 ? 0 : null,
                'detail_end' => $detailCount > 0 ? $detailCount - 1 : null,
                'aggregate_values' => $grandTotals,
                'aggregate_cells' => $this->aggregateCells($grandTotals, $aggregateDefinitions),
            ];
        }

        return $presentationRows;
    }

    /** @return array<string,array<int,array{label:string,function:string,value:mixed}>> */
    private function aggregateCells(array $values, array $definitions): array
    {
        $cells = [];

        foreach ($definitions as $aggregate) {
            $field = $aggregate['field'] ?? null;
            if (! $field) {
                continue;
            }

            $function = strtolower((string) ($aggregate['function'] ?? 'sum'));
            $label = trim((string) ($aggregate['label'] ?? '')) ?: "{$field} ({$function})";
            if (! array_key_exists($label, $values)) {
                continue;
            }

            $cells[$field][] = [
                'label' => $label,
                'function' => $function,
                'value' => $values[$label],
            ];
        }

        return $cells;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? -1 : 1);
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        $leftValue = is_scalar($left) || $left instanceof \Stringable ? (string) $left : serialize($left);
        $rightValue = is_scalar($right) || $right instanceof \Stringable ? (string) $right : serialize($right);
        $comparison = strcmp($leftValue, $rightValue);

        return $comparison !== 0 ? $comparison : strcmp($this->valueKey($left), $this->valueKey($right));
    }

    private function pathKey(array $path): string
    {
        // Include each ancestor, field, value type, and null explicitly so
        // identical child values below different parents never collide.
        return hash('sha256', serialize($path));
    }

    private function valueKey(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '(blank)';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '(blank)';
    }
}
