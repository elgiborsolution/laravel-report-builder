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
        /**
         * Ordered display events referencing detail rows by row_index. Kept
         * separate from rows so headers/subtotals never affect counts/data.
         * @var array<int,array<string,mixed>>
         */
        public readonly array $presentationRows = [],
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
            'presentation_rows' => $this->presentationRowsWithDetails(),
        ];
    }

    /**
     * Expand implicit ungrouped detail events for consumers that serialize or
     * render all rows. Grouped reports already carry an ordered interleaving.
     *
     * @return array<int,array<string,mixed>>
     */
    public function presentationRowsWithDetails(): array
    {
        if ($this->definition->groups !== []) {
            return $this->presentationRows;
        }

        $detailCount = (int) ($this->metadata['row_count'] ?? $this->rows->count());
        $details = [];
        for ($index = 0; $index < $detailCount; $index++) {
            $details[] = ['type' => 'detail', 'row_index' => $index];
        }

        return array_merge($details, $this->presentationRows);
    }
}
