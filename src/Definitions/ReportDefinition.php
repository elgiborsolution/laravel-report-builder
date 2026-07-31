<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Definitions;

/**
 * Typed, immutable DTO for a report definition (the JSON spec).
 *
 * Mirrors the documented JSON shape. All collections are plain arrays
 * of associative arrays to keep the DTO lightweight and round-trippable.
 */
final class ReportDefinition
{
    /**
     * @param  string  $name
     * @param  string  $dataSource  Source key (must be registered).
     * @param  array<int,array>  $parameters
     * @param  array<int,array>  $columns
     * @param  array<int,array>  $filters
     * @param  array<int,array>  $groups
     * @param  array<int,array>  $aggregates
     * @param  array<int,array>  $sorts
     * @param  array<int,array>  $formulas
     * @param  array<int,array>  $drilldowns
     * @param  array<int,array>  $subreports
     * @param  array<int,array>  $conditionalFormatting
     * @param  array<string,mixed>|null  $layout
     * @param  array<string,mixed>  $meta  Free-form extras.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dataSource,
        public readonly array $parameters = [],
        public readonly array $columns = [],
        public readonly array $filters = [],
        public readonly array $groups = [],
        public readonly array $aggregates = [],
        public readonly array $sorts = [],
        public readonly array $formulas = [],
        public readonly array $drilldowns = [],
        public readonly array $subreports = [],
        public readonly array $conditionalFormatting = [],
        public readonly ?array $layout = null,
        public readonly array $meta = [],
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            dataSource: (string) ($data['data_source'] ?? $data['dataSource'] ?? ''),
            parameters: $data['parameters'] ?? [],
            columns: $data['columns'] ?? [],
            filters: $data['filters'] ?? [],
            groups: $data['groups'] ?? [],
            aggregates: $data['aggregates'] ?? [],
            sorts: $data['sorts'] ?? [],
            formulas: $data['formulas'] ?? [],
            drilldowns: $data['drilldowns'] ?? [],
            subreports: $data['subreports'] ?? [],
            conditionalFormatting: $data['conditional_formatting'] ?? $data['conditionalFormatting'] ?? [],
            layout: $data['layout'] ?? null,
            meta: $data['meta'] ?? [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'data_source' => $this->dataSource,
            'parameters' => $this->parameters,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'groups' => $this->groups,
            'aggregates' => $this->aggregates,
            'sorts' => $this->sorts,
            'formulas' => $this->formulas,
            'drilldowns' => $this->drilldowns,
            'subreports' => $this->subreports,
            'conditional_formatting' => $this->conditionalFormatting,
            'layout' => $this->layout,
            'meta' => $this->meta,
        ];
    }

    /**
     * All field names referenced across columns/filters/groups/aggregates/sorts.
     * Used to validate formula references and detect hidden-field usage.
     *
     * @return array<int,string>
     */
    public function referencedFields(): array
    {
        $pluck = static fn (array $items, string $key) => array_values(array_filter(
            array_map(fn ($i) => is_array($i) ? ($i[$key] ?? null) : null, $items)
        ));

        return array_values(array_unique(array_merge(
            $pluck($this->columns, 'field'),
            $pluck($this->filters, 'field'),
            $pluck($this->groups, 'field'),
            $pluck($this->aggregates, 'field'),
            $pluck($this->sorts, 'field'),
        )));
    }
}
