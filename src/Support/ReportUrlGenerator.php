<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Generates URLs/metadata for drilldown triggers. Frontend can consume
 * the metadata to build its own navigation if it prefers.
 */
final class ReportUrlGenerator
{
    public function __construct(protected ?string $baseUrl = null) {}

    /**
     * @param  array<string,mixed>  $parameters  Resolved parameter values.
     * @return array{url:?string,type:string,target:string,parameters:array<string,mixed>}
     */
    public function for(array $drilldown, array $parameters): array
    {
        $type = $drilldown['type'] ?? 'report';
        $target = (string) ($drilldown['target'] ?? '');

        $url = match ($type) {
            'report' => $this->reportUrl($target, $parameters),
            'route' => $this->routeUrl($target, $parameters),
            'url' => $this->absoluteUrl($target, $parameters),
            default => null,
        };

        return [
            'url' => $url,
            'type' => $type,
            'target' => $target,
            'parameters' => $parameters,
        ];
    }

    protected function reportUrl(string $code, array $parameters): ?string
    {
        $prefix = $this->baseUrl ?: config('advanced-reports.routes.api.prefix', 'api/advanced-reports');
        $query = http_build_query(['parameters' => $parameters]);

        return rtrim((string) url('/'), '/').'/'.trim($prefix, '/').'/reports/'.$code.'/run?'.$query;
    }

    protected function routeUrl(string $routeName, array $parameters): ?string
    {
        if (! function_exists('route')) {
            return null;
        }

        try {
            return route($routeName, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function absoluteUrl(string $template, array $parameters): string
    {
        $resolved = $template;
        foreach ($parameters as $key => $value) {
            $resolved = str_replace('{'.$key.'}', (string) $value, $resolved);
        }

        return $resolved;
    }
}
