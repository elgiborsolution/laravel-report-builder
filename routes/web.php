<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Web routes for ElgiborSolution Advanced Reports (optional).
 *
 * Loaded by AdvancedReportsServiceProvider when
 * config('advanced-reports.routes.web.enabled') is true (default: false).
 * Useful for the HTML preview UI.
 */

$middleware = config('advanced-reports.routes.web.middleware', ['web']);
$prefix = config('advanced-reports.routes.web.prefix', 'advanced-reports');

Route::prefix($prefix)
    ->middleware($middleware)
    ->namespace('ElgiborSolution\\AdvancedReports\\Http\\Controllers')
    ->group(function () {

        Route::get('reports/{report}/preview', function (\ElgiborSolution\AdvancedReports\Models\Report $report, \Illuminate\Http\Request $request) {
            $parameters = $request->all();

            return \ElgiborSolution\AdvancedReports\Facades\AdvancedReports::render($report, 'html', $parameters);
        })->name('advanced-reports.reports.preview');

        Route::get('reports/{report}/download', function (\ElgiborSolution\AdvancedReports\Models\Report $report, \Illuminate\Http\Request $request) {
            $parameters = $request->input('parameters', []);
            $format = $request->input('format', 'pdf');

            return \ElgiborSolution\AdvancedReports\Facades\AdvancedReports::export($report, $format, $parameters);
        })->name('advanced-reports.reports.download');
    });
