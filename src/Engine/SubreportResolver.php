<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Exceptions\ReportNotFoundException;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves and executes subreports. Subreports are referenced by report code
 * within a parent definition; their parameters are resolved from the parent
 * row/parameters just like drilldowns.
 *
 * For performance and to avoid recursion bombs, subreport depth is capped.
 */
final class SubreportResolver
{
    public const MAX_DEPTH = 3;

    public function __construct(
        protected Container $container,
        protected ValueResolver $resolver,
    ) {}

    /**
     * Execute all subreports for the given row.
     *
     * @param  array<int,array>  $subreports
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $parentParameters
     * @param  int  $depth
     * @return array<string,\ElgiborSolution\AdvancedReports\Engine\ReportResult>
     */
    public function execute(array $subreports, array $row, array $parentParameters, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $results = [];

        foreach ($subreports as $sr) {
            $code = $sr['report'] ?? $sr['target'] ?? null;
            if (! $code) {
                continue;
            }

            $resolved = [];
            foreach ($sr['parameters'] ?? [] as $name => $template) {
                $resolved[$name] = $this->resolver
                    ->setParams($parentParameters)
                    ->setRow($row)
                    ->resolve($template);
            }

            $results[$code] = $this->runSubreport($code, $resolved, $depth);
        }

        return $results;
    }

    protected function runSubreport(string $code, array $parameters, int $depth): ReportResult
    {
        /** @var Report $report */
        $report = Report::query()->where('code', $code)->first();
        if (! $report) {
            throw ReportNotFoundException::forCode($code);
        }

        // Use the main manager so security/validation still apply, but pass depth via container binding.
        $this->container->instance('advanced-reports.subreport.depth', $depth + 1);

        return $this->container
            ->make(\ElgiborSolution\AdvancedReports\AdvancedReportsManager::class)
            ->run($report, $parameters);
    }
}
