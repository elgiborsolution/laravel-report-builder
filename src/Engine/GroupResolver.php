<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Groups the result rows by one or more keys. Grouping is performed in PHP
 * rather than SQL to support nested/related field keys (e.g. "customer.name")
 * uniformly across sources.
 */
final class GroupResolver
{
    /**
     * @param  Collection<int,array>|LazyCollection  $rows
     * @param  array<int,array>  $groups  Each: [{field, label}]
     * @return array{
     *     rows: Collection<int,array>,
     *     groups: array<string, array<int, array{key: string, label: string, value: mixed}>>
     * }
     */
    public function apply(Collection|LazyCollection $rows, array $groups): array
    {
        if (empty($groups)) {
            return ['rows' => $rows, 'groups' => []];
        }

        $keys = array_map(fn ($g) => $g['field'], $groups);

        $sorted = $rows->sortBy(function ($row) use ($keys) {
            return array_map(fn ($k) => data_get($row, $k), $keys);
        });

        // Build a structure that maps each group field to its distinct values,
        // so renderers can render group headers/footers.
        $groupStructure = [];
        foreach ($groups as $g) {
            $field = $g['field'];
            $groupStructure[$field] = [];
            foreach ($rows as $row) {
                $value = data_get($row, $field);
                $serialized = is_scalar($value) ? (string) $value : md5(serialize($value));
                if (! isset($groupStructure[$field][$serialized])) {
                    $groupStructure[$field][$serialized] = [
                        'key' => $field,
                        'label' => $g['label'] ?? ucfirst($field),
                        'value' => $value,
                    ];
                }
            }
            $groupStructure[$field] = array_values($groupStructure[$field]);
        }

        return [
            'rows' => $sorted instanceof LazyCollection
                ? $sorted
                : $sorted->values(),
            'groups' => $groupStructure,
        ];
    }
}
