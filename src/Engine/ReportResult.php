<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Models\Report;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Immutable result of executing a report. Renderers and exporters consume
 * this — they never touch the underlying query/source directly.
 */
final class ReportResult implements Arrayable
{
    public function __construct(
        public readonly Report $report,
        public readonly ReportDefinition $definition,
        public readonly array $parameters,
        public Collection|LazyCollection $rows,
        public readonly array $columns,
        public readonly array $groups = [],
        public readonly array $aggregates = [],
        public readonly array $formulas = [],
        public readonly array $drilldowns = [],
        public readonly array $conditionalFormatting = [],
        public readonly ?array $layout = null,
        public readonly array $metadata = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'metadata' => array_merge($this->metadata, [
                'report' => $this->report->only(['uuid', 'code', 'name', 'data_source']),
                'generated_at' => now()->toIso8601String(),
            ]),
            'parameters' => $this->parameters,
            'columns' => $this->columns,
            'groups' => $this->groups,
            'aggregates' => $this->aggregates,
            'formulas' => $this->formulas,
            'drilldowns' => $this->drilldowns,
            'conditional_formatting' => $this->conditionalFormatting,
            'layout' => $this->layout,
            'rows' => $this->rows->all(),
        ];
    }
}
