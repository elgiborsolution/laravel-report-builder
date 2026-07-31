<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Definitions;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;

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
