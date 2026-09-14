<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Definitions;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Sources\ReportField;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\FieldTypeOperatorMap;
use ElgiborSolution\AdvancedReports\Support\FormulaCapabilities;

/**
 * Produces the JSON schema a frontend drag-and-drop designer consumes to
 * know what fields/parameters/operations a source supports.
 */
final class ReportSchemaGenerator
{
    public function __construct(protected SourceRegistry $sources) {}

    /** @return array<string,mixed> */
    public function forSource(string $key): array
    {
        $source = $this->sources->get($key);

        return [
            'source' => [
                'key' => $source->key(),
                'label' => $source->label(),
                'description' => $source->description(),
            ],
            'fields' => $source->fields()
                ->reject(fn ($f) => $f->hidden)
                ->map(fn ($f) => $f->toArray())
                ->values()
                ->all(),
            'parameters' => $source->parameters()
                ->map(fn ($p) => $p->toArray())
                ->values()
                ->all(),
            'operators' => \ElgiborSolution\AdvancedReports\Support\Operators::all(),
            'aggregate_functions' => \ElgiborSolution\AdvancedReports\Support\Operators::aggregateFunctions(),
            'formats' => [
                'string', 'integer', 'decimal', 'currency', 'percentage',
                'date', 'datetime', 'boolean',
            ],
        ];
    }

    /**
     * Enhanced schema for the visual report designer.
     *
     * Returns all data from forSource() plus:
     *  - field_categories: fields grouped by dot-notation prefix
     *  - compatible_operators: per-field operator list based on type
     *  - suggested_aggregates: for numeric/aggregatable fields
     *  - available_formats: per-field format options based on type
     *
     * @return array<string,mixed>
     */
    public function forDesigner(string $key): array
    {
        $base = $this->forSource($key);
        $source = $this->sources->get($key);

        $visibleFields = $source->fields()->reject(fn (ReportField $f) => $f->hidden);

        // Group fields by dot-notation prefix (e.g., "customer.name" -> "customer").
        $fieldCategories = $visibleFields
            ->groupBy(function (ReportField $field) {
                $parts = explode('.', $field->key);

                return count($parts) > 1 ? $parts[0] : '_root';
            })
            ->map(fn ($fields) => $fields->map(fn (ReportField $f) => $f->key)->values()->all())
            ->all();

        // Per-field compatible operators.
        $compatibleOperators = $visibleFields
            ->mapWithKeys(fn (ReportField $field) => [
                $field->key => FieldTypeOperatorMap::operatorsFor($field->type),
            ])
            ->all();

        // Suggested aggregates for aggregatable fields.
        $suggestedAggregates = $visibleFields
            ->filter(fn (ReportField $field) => $field->aggregatable)
            ->mapWithKeys(fn (ReportField $field) => [
                $field->key => FieldTypeOperatorMap::aggregatesFor($field->type),
            ])
            ->all();

        // Available formats per field type.
        $availableFormats = $visibleFields
            ->mapWithKeys(fn (ReportField $field) => [
                $field->key => FieldTypeOperatorMap::formatsFor($field->type),
            ])
            ->all();

        return array_merge($base, [
            'field_categories' => $fieldCategories,
            'compatible_operators' => $compatibleOperators,
            'suggested_aggregates' => $suggestedAggregates,
            'available_formats' => $availableFormats,
            'formula_capabilities' => FormulaCapabilities::schema(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function allSources(): array
    {
        return $this->sources->all()
            ->map(fn (ReportSourceContract $s) => [
                'key' => $s->key(),
                'label' => $s->label(),
                'description' => $s->description(),
                'field_count' => $s->fields()->reject(fn ($f) => $f->hidden)->count(),
                'parameter_count' => $s->parameters()->count(),
            ])
            ->values()
            ->all();
    }
}
