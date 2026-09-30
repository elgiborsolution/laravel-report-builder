<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Middleware;

use Closure;
use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rehydrates persisted dynamic sources for every Report Builder API request.
 *
 * SourceRegistry is process-local state.  The connection itself is persisted
 * centrally in advanced_report_connected_sources, so a request must restore
 * those adapters before its controller resolves a source key.  This
 * middleware is appended after the application's configured route middleware
 * (including tenancy middleware), while DataSourceBridge still explicitly
 * reads metadata from Builder's configured central connection.
 */
final class RegisterConnectedDynamicSources
{
    public function __construct(private readonly DataSourceBridge $bridge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->bridge->bootConnected();

        return $next($request);
    }
}
