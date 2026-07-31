<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ElgiborSolution\AdvancedReports\Sources\SourceRegistry sources()
 * @method static \ElgiborSolution\AdvancedReports\Sources\SourceRegistry registerSource(string|\ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract $source, ?string $key = null)
 * @method static ?\ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract source(string $key)
 * @method static \ElgiborSolution\AdvancedReports\Engine\ReportResult run(string|\ElgiborSolution\AdvancedReports\Models\Report $report, array $parameters = [])
 * @method static mixed render(string|\ElgiborSolution\AdvancedReports\Models\Report $report, string $format = 'html', array $parameters = [])
 * @method static \Symfony\Component\HttpFoundation\Response export(string|\ElgiborSolution\AdvancedReports\Models\Report $report, string $format = 'pdf', array $parameters = [], array $options = [])
 * @method static \ElgiborSolution\AdvancedReports\Models\ReportExport queueExport(string|\ElgiborSolution\AdvancedReports\Models\Report $report, string $format = 'pdf', array $parameters = [], array $options = [])
 * @method static \ElgiborSolution\AdvancedReports\Definitions\ReportDefinition definition(array|\ElgiborSolution\AdvancedReports\Definitions\ReportDefinition $definition)
 *
 * @see \ElgiborSolution\AdvancedReports\AdvancedReportsManager
 */
final class AdvancedReports extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AdvancedReportsManager::class;
    }
}
