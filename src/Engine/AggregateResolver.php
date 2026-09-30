<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Computes aggregate values (sum/avg/min/max/count) over the result rows.
 * Also computes per-group aggregates when a group structure is present.
 *
 * Aggregates are computed in PHP (not SQL) for uniformity across sources
 * and to remain correct after formula evaluation.
 */
final class AggregateResolver
{
    /**
     * @param  Collection<int,array>|LazyCollection  $rows
     * @param  array<int,array>  $aggregates  Each: [{field, function, label}]
     * @return array<string,mixed> Map of "field:function" => computed value.
     */
    public function apply(Collection|LazyCollection $rows, array $aggregates): array
    {
        $results = [];

        foreach ($aggregates as $agg) {
            $field = $agg['field'] ?? null;
            $function = strtolower((string) ($agg['function'] ?? 'sum'));
            $label = $agg['label'] ?? "{$field} ({$function})";

            if (! $field) {
                continue;
            }

            $values = $rows->map(fn ($r) => data_get($r, $field))->filter(fn ($v) => $v !== null);

            $computed = match ($function) {
                'sum' => $values->sum(),
                'avg' => $values->count() ? $values->sum() / $values->count() : 0,
                'min' => $values->min(),
                'max' => $values->max(),
                'count' => $values->count(),
                default => null,
            };

            $results[$label] = $computed;
        }

        return $results;
    }

    /**
     * Compute per-group aggregates. Returns a nested map: group_key => [aggregate_label => value].
     *
     * @param  array<string, array<int, array{key: string, label: string, value: mixed}>>  $groupStructure
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function perGroup(Collection|LazyCollection $rows, array $groupStructure, array $aggregates): array
    {
        $perGroup = [];

        foreach ($groupStructure as $groupField => $entries) {
            foreach ($entries as $entry) {
                $key = $this->groupKey($groupField, $entry['value']);
                $grouped = $rows->filter(fn ($r) => $this->groupKey($groupField, data_get($r, $groupField)) === $key);
                $perGroup[$groupField][$key] = $this->apply($grouped, $aggregates);
            }
        }

        return $perGroup;
    }

    /**
     * Compute aggregates for every complete group path produced by
     * GroupResolver. Each entry's row indexes refer to the sorted detail rows;
     * this keeps repeated child values beneath different parents distinct.
     *
     * @param  array<string,array<int,int>>  $groupRowIndexes
     * @return array<string,array<string,mixed>>
     */
    public function perGroupPaths(Collection|LazyCollection $rows, array $groupRowIndexes, array $aggregates): array
    {
        if ($aggregates === [] || $groupRowIndexes === []) {
            return [];
        }

        $details = ($rows instanceof LazyCollection ? $rows->collect() : $rows)->values();
        $results = [];

        foreach ($groupRowIndexes as $pathKey => $indexes) {
            $groupRows = collect($indexes)
                ->map(fn (int $index) => $details->get($index))
                ->filter(fn ($row) => $row !== null)
                ->values();

            $results[$pathKey] = $this->apply($groupRows, $aggregates);
        }

        return $results;
    }

    protected function groupKey(string $field, mixed $value): string
    {
        return $field.'::'.(is_scalar($value) ? (string) $value : md5(serialize($value)));
    }
}
