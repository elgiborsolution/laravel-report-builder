<?php

declare(strict_types=1);

/**
 * Configuration for ElgiborSolution Advanced Reports.
 *
 * Publish with: php artisan vendor:publish --tag=advanced-reports-config
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Optional user model
    |--------------------------------------------------------------------------
    | User references in this package are unconstrained strings. Set this only
    | when the user/creator Eloquent relationships should target a specific
    | model. When omitted, the model from Laravel's default auth guard is used.
    */
    'user_model' => env('ADVANCED_REPORTS_USER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'database' => [
        // Leave null to use Laravel's current default connection. This lets
        // tenancy bootstrappers switch package queries to the tenant database.
        'connection' => env('ADVANCED_REPORTS_DB_CONNECTION'),
        'table_prefix' => 'advanced_report_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | Sources are the trust boundary. A definition may only reference sources
    | that have been explicitly registered by a developer. End-users never
    | pass raw SQL or table names directly.
    */
    'security' => [
        'require_source_registration' => true,
        'enforce_permissions' => (bool) env('ADVANCED_REPORTS_ENFORCE_PERMISSIONS', true),
        'enforce_tenant_scope' => (bool) env('ADVANCED_REPORTS_ENFORCE_TENANT_SCOPE', false),
        'tenant_column' => env('ADVANCED_REPORTS_TENANT_COLUMN', 'tenant_id'),
        'tenant_resolver' => env('ADVANCED_REPORTS_TENANT_RESOLVER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderers
    |--------------------------------------------------------------------------
    */
    'renderers' => [
        'html' => \ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer::class,
        'json' => \ElgiborSolution\AdvancedReports\Renderers\JsonRenderer::class,
        'csv' => \ElgiborSolution\AdvancedReports\Renderers\CsvRenderer::class,
        'pdf' => \ElgiborSolution\AdvancedReports\Renderers\PdfRenderer::class,
        'xlsx' => \ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Engine
    |--------------------------------------------------------------------------
    | Supported drivers: dompdf, browsershot, custom
    | For "custom", register a binding tagged `advanced-reports.pdf.engine`.
    */
    'pdf' => [
        'driver' => env('ADVANCED_REPORTS_PDF_DRIVER', 'dompdf'),
        'options' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'default_format' => 'pdf',
        'queue' => [
            'enabled' => (bool) env('ADVANCED_REPORTS_QUEUE_EXPORTS', false),
            'connection' => env('ADVANCED_REPORTS_QUEUE_CONNECTION', config('queue.default')),
            'queue' => env('ADVANCED_REPORTS_QUEUE_NAME', 'default'),
        ],
        'chunk_size' => (int) env('ADVANCED_REPORTS_EXPORT_CHUNK_SIZE', 1000),
        'disk' => env('ADVANCED_REPORTS_EXPORT_DISK', config('filesystems.default')),
        'path' => env('ADVANCED_REPORTS_EXPORT_PATH', 'advanced-reports/exports'),
        'retention_days' => (int) env('ADVANCED_REPORTS_EXPORT_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'use_lazy_collections' => (bool) env('ADVANCED_REPORTS_LAZY_COLLECTIONS', true),
        'max_rows_sync' => (int) env('ADVANCED_REPORTS_MAX_ROWS_SYNC', 10000),
        'row_limit' => (int) env('ADVANCED_REPORTS_ROW_LIMIT', 0), // 0 = no limit
        'cache' => [
            'enabled' => (bool) env('ADVANCED_REPORTS_CACHE', false),
            'store' => env('ADVANCED_REPORTS_CACHE_STORE', config('cache.default')),
            'prefix' => 'advanced-reports:',
            'ttl' => (int) env('ADVANCED_REPORTS_CACHE_TTL', 300), // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Run history / snapshots
    |--------------------------------------------------------------------------
    */
    'history' => [
        'auto_snapshot' => (bool) env('ADVANCED_REPORTS_AUTO_SNAPSHOT', false),
        'cleanup' => [
            'enabled' => (bool) env('ADVANCED_REPORTS_CLEANUP_RUNS', true),
            'keep_days' => (int) env('ADVANCED_REPORTS_KEEP_RUN_DAYS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => (bool) env('ADVANCED_REPORTS_ROUTES_ENABLED', true),
        'api' => [
            'enabled' => (bool) env('ADVANCED_REPORTS_API_ROUTES', true),
            'prefix' => env('ADVANCED_REPORTS_API_PREFIX', 'api/advanced-reports'),
            'middleware' => ['api'],
        ],
        'web' => [
            'enabled' => (bool) env('ADVANCED_REPORTS_WEB_ROUTES', false),
            'prefix' => env('ADVANCED_REPORTS_WEB_PREFIX', 'advanced-reports'),
            'middleware' => ['web'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI / Designer
    |--------------------------------------------------------------------------
    */
    'designer' => [
        'enabled' => (bool) env('ADVANCED_REPORTS_DESIGNER_ENABLED', true),
    ],

];
