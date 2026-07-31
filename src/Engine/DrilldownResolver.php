<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Support\ReportUrlGenerator;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;

/**
 * Produces drilldown metadata (URLs) for each row by resolving {{row.x}}
 * and {{param.x}} tokens declared in the definition.
 *
 * The result is attached to the ReportResult so renderers can decide how
 * to expose drilldowns (links, data attributes, etc.).
 */
final class DrilldownResolver
{
    public function __construct(
        protected ValueResolver $resolver,
        protected ReportUrlGenerator $urls,
    ) {}

    /**
     * @param  array<int,array>  $drilldowns
     * @param  array<string,mixed>  $parameters
     * @return array<int,array>
     */
    public function resolveForParameters(array $drilldowns, array $parameters): array
    {
        return array_map(fn ($dd) => array_merge(
            $dd,
            ['_meta' => $this->urls->for($dd, $parameters)],
        ), $drilldowns);
    }

    /**
     * Resolve a drilldown for a specific row. Returns a URL string (or null).
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $parameters
     */
    public function resolveForRow(array $drilldown, array $row, array $parameters): ?string
    {
        $resolvedParams = [];
        $rawParams = $drilldown['parameters'] ?? [];

        foreach ($rawParams as $name => $template) {
            $resolvedParams[$name] = $this->resolver
                ->setParams($parameters)
                ->setRow($row)
                ->resolve($template);
        }

        return $this->urls->for($drilldown, $resolvedParams)['url'];
    }
}
