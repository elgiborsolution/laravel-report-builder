<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ElgiborSolution\AdvancedReports\Http\Middleware\RegisterConnectedDynamicSources;

/**
 * API routes for ElgiborSolution Advanced Reports.
 *
 * Loaded by AdvancedReportsServiceProvider when
 * config('advanced-reports.routes.api.enabled') is true.
 */

$middleware = config('advanced-reports.routes.api.middleware', ['api']);
$prefix = config('advanced-reports.routes.api.prefix', 'api/advanced-reports');

Route::prefix($prefix)
    // Run after the host application's configured middleware (including
    // tenancy initialization) so every request receives a rehydrated
    // process-local SourceRegistry before a controller resolves a key.
    ->middleware([...$middleware, RegisterConnectedDynamicSources::class])
    ->namespace('ElgiborSolution\\AdvancedReports\\Http\\Controllers')
    ->group(function () {

        // Sources (read-only)
        Route::get('sources', 'ReportSourceController@index')->name('advanced-reports.sources.index');

        // Dynamic data source bridge (static paths BEFORE wildcard {source})
        Route::get('sources/dynamic', 'DataSourceBridgeController@listDynamic')->name('advanced-reports.sources.dynamic');
        Route::get('sources/available', 'DataSourceBridgeController@listAvailable')->name('advanced-reports.sources.available');
        Route::post('sources/connect', 'DataSourceBridgeController@connect')->name('advanced-reports.sources.connect');
        Route::delete('sources/{key}/disconnect', 'DataSourceBridgeController@disconnect')->name('advanced-reports.sources.disconnect');
        Route::post('sources/{source}/formula/validate', 'FormulaController@validate')->name('advanced-reports.sources.formula.validate');

        // Source schema (wildcard routes AFTER static paths)
        Route::get('sources/{source}/schema', 'ReportSourceController@schema')->name('advanced-reports.sources.schema');
        Route::get('sources/{source}/designer-schema', 'ReportSourceController@designerSchema')->name('advanced-reports.sources.designer-schema');

        // Reports CRUD
        Route::get('reports', 'ReportController@index')->name('advanced-reports.reports.index');
        Route::post('reports', 'ReportController@store')->name('advanced-reports.reports.store');
        Route::get('reports/{report}', 'ReportController@show')->name('advanced-reports.reports.show');
        Route::put('reports/{report}', 'ReportController@update')->name('advanced-reports.reports.update');
        Route::patch('reports/{report}', 'ReportController@update');
        Route::delete('reports/{report}', 'ReportController@destroy')->name('advanced-reports.reports.destroy');

        // Preview endpoints
        Route::post('reports/preview-inline', 'ReportPreviewController@inlinePreview')->name('advanced-reports.reports.preview-inline');
        Route::post('reports/{report}/preview', 'ReportPreviewController@preview')->name('advanced-reports.reports.preview');

        // Run / export endpoints
        Route::get('reports/{report}/runs', 'ReportRunController@index')->name('advanced-reports.runs.index');
        Route::post('reports/{report}/run', 'ReportRunController@run')->name('advanced-reports.reports.run');
        Route::post('reports/{report}/export', 'ReportExportController@export')->name('advanced-reports.reports.export');

        // Standalone run / export lookup (UUID route-binding)
        Route::get('runs/{run}', 'ReportRunController@show')->name('advanced-reports.runs.show');
        Route::get('exports/{export}', 'ReportExportController@show')->name('advanced-reports.exports.show');
        Route::get('exports/{export}/download', 'ReportExportController@download')->name('advanced-reports.exports.download');
    });
