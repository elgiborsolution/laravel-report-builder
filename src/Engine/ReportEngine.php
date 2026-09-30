<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Events\ReportCompleted;
use ElgiborSolution\AdvancedReports\Events\ReportFailed;
use ElgiborSolution\AdvancedReports\Events\ReportRunning;
use ElgiborSolution\AdvancedReports\Exceptions\RendererNotFoundException;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportRun;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;

/**
 * The orchestrator. Builds and executes a report through the resolver
 * pipeline, persists run history, dispatches events, and exposes the
 * renderer registry.
 *
 * Pure orchestration — no SQL/PHP eval lives here.
 */
final class ReportEngine
{
    public function __construct(
        protected Container $container,
        protected SourceRegistry $sources,
        protected DataSourceBridge $dynamicSources,
        protected QueryBuilderEngine $queryBuilder,
        protected ParameterResolver $parameters,
        protected FormulaResolver $formulas,
        protected GroupResolver $groups,
        protected AggregateResolver $aggregates,
        protected DrilldownResolver $drilldowns,
        protected ValueResolver $resolver,
        protected EventDispatcher $events,
    ) {}

    /**
     * Execute a report and return a ReportResult. Persists a ReportRun row
     * and dispatches ReportRunning / ReportCompleted / ReportFailed events.
     */
    public function run(Report $report, ReportDefinition $definition, array $parameters, ?Authenticatable $user = null): ReportResult
    {
        $this->dynamicSources->ensureRegistered($definition->dataSource);
        $source = $this->sources->get($definition->dataSource);

        // Persist a run record up-front so failures are still auditable.
        $run = $this->createRun($report, $parameters, $user);
        $this->events->dispatch(new ReportRunning($report, $run, $parameters));
        $run->markStarted();

        try {
            // 1. Resolve parameters (defaults + token substitution + type coercion).
            $resolvedParameters = $this->parameters->resolve($definition->parameters, $parameters);

            // 2. Build the base query (columns/filters/sorts already applied).
            $built = $this->queryBuilder->build($source, $definition, $resolvedParameters);

            // 3. Materialize rows (lazy where possible).
            $rows = $this->queryBuilder->fetch($built['query']);

            // 4. Normalize each row to an array (Eloquent models -> toArray).
            $rows = $this->normalizeRows($rows);

            // 5. Evaluate formulas (injects computed fields into each row).
            $rows = $this->formulas->apply($rows, $definition->formulas);

            // 6. Apply ordered, nested grouping to detail rows. Grouping never
            // collapses rows or delegates aggregation to SQL.
            $grouped = $this->groups->apply($rows, $definition->groups);
            $rows = $grouped['rows'];
            $groupStructure = $grouped['groups'];

            // 7. Keep grand totals over all filtered detail rows and compute
            // per-group subtotals over each complete parent/child path.
            $aggregates = $this->aggregates->apply($rows, $definition->aggregates);
            $groupAggregates = $this->aggregates->perGroupPaths(
                $rows,
                $grouped['group_row_indexes'],
                $definition->aggregates,
            );
            $rowCount = $rows instanceof \Illuminate\Support\LazyCollection
                ? $this->queryBuilder->count($built['query'])
                : $rows->count();
            $presentationRows = $this->groups->presentationRows(
                $grouped,
                $groupAggregates,
                $aggregates,
                $definition->aggregates,
                $rowCount,
            );

            // 8. Resolve drilldown metadata.
            $drilldowns = $this->drilldowns->resolveForParameters($definition->drilldowns, $resolvedParameters);

            $result = new ReportResult(
                report: $report,
                definition: $definition,
                parameters: $resolvedParameters,
                rows: $rows,
                columns: $built['columns'],
                groups: $groupStructure,
                aggregates: $aggregates,
                formulas: $definition->formulas,
                drilldowns: $drilldowns,
                conditionalFormatting: $definition->conditionalFormatting,
                layout: $definition->layout,
                metadata: [
                    'run_id' => $run->id,
                    'row_count' => $rowCount,
                ],
                presentationRows: $presentationRows,
            );

            $run->markCompleted($rowCount, ['aggregates' => $aggregates]);

            if (config('advanced-reports.history.auto_snapshot', false)) {
                $this->persistSnapshot($report, $run, $result);
            }

            $this->events->dispatch(new ReportCompleted($report, $run, $result));

            return $result;
        } catch (\Throwable $e) {
            $run->markFailed($e->getMessage());
            $this->events->dispatch(new ReportFailed($report, $run, $e));

            throw $e;
        }
    }

    /**
     * Render a ReportResult with the registered renderer for the format.
     */
    public function render(ReportResult $result, string $format, array $options = []): mixed
    {
        $renderer = $this->resolveRenderer($format);

        return $renderer->render($result, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    protected function resolveRenderer(string $format): ReportRenderer
    {
        $map = (array) config('advanced-reports.renderers', []);
        $class = $map[$format] ?? null;

        if (! $class) {
            throw RendererNotFoundException::forFormat($format);
        }

        return $this->container->make($class);
    }

    protected function createRun(Report $report, array $parameters, ?Authenticatable $user): ReportRun
    {
        return ReportRun::create([
            'report_id' => $report->id,
            'user_id' => $user?->getAuthIdentifier(),
            'parameters' => $parameters,
            'tenant_id' => $report->tenant_id ?? null,
            'status' => ReportRun::STATUS_PENDING,
        ]);
    }

    protected function persistSnapshot(Report $report, ReportRun $run, ReportResult $result): void
    {
        \ElgiborSolution\AdvancedReports\Models\ReportSnapshot::create([
            'report_id' => $report->id,
            'report_run_id' => $run->id,
            'parameters' => $result->parameters,
            'data' => [
                'columns' => $result->columns,
                'aggregates' => $result->aggregates,
                'rows' => $this->safeSnapshotRows($result),
            ],
            'metadata' => ['row_count' => $result->metadata['row_count'] ?? 0],
            'created_by' => $run->user_id,
        ]);
    }

    protected function safeSnapshotRows(ReportResult $result): array
    {
        // Cap snapshot size to avoid blowing up storage; exports don't rely on snapshots.
        $max = 1000;
        $rows = $result->rows->take($max + 1);
        if ($rows->count() > $max) {
            return ['_truncated' => true, 'rows' => $rows->take($max)->all()];
        }

        return $rows->all();
    }

    protected function normalizeRows(\Illuminate\Support\Collection|\Illuminate\Support\LazyCollection $rows): \Illuminate\Support\Collection|\Illuminate\Support\LazyCollection
    {
        $normalize = static function ($row) {
            if (is_array($row)) {
                return $row;
            }
            if ($row instanceof \Illuminate\Contracts\Support\Arrayable) {
                return $row->toArray();
            }
            if (is_object($row)) {
                return json_decode(json_encode($row) ?: '{}', true) ?: [];
            }

            return ['value' => $row];
        };

        return $rows instanceof \Illuminate\Support\LazyCollection
            ? $rows->map($normalize)->values()
            : $rows->map($normalize)->values();
    }
}
