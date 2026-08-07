# Laravel Advanced Reports

`elgibor-solution/laravel-report-builder` is a metadata-driven reporting engine for Laravel. It runs registered report sources through definitions containing columns, parameters, filters, sorting, grouping, aggregates, formulas, drilldowns, subreports, and conditional formatting. Results can be rendered as HTML, JSON, CSV, PDF, or XLSX.

## Requirements

- PHP 8.2 or newer.
- Laravel components 10, 11, 12, or 13.
- Laravel 13 requires PHP 8.3 or newer.
- SQLite, MySQL, PostgreSQL, or another database supported by the configured Laravel connection.

Optional render dependencies:

- `barryvdh/laravel-dompdf` for the default PDF renderer.
- `maatwebsite/excel` for XLSX rendering.
- `spatie/browsershot` can be used as an alternative PDF driver.

The dynamic data-source bridge also expects the `ESolution\\DataSources\\Models\\DataSource` and `DataSourceParameter` models supplied by the related data-sources package when that integration is used.

## Installation

```bash
composer require elgibor-solution/laravel-report-builder
php artisan advanced-reports:install
```

The service provider is auto-discovered. The installer publishes the configuration, migrations, and views and can run the migrations. They can also be published individually:

```bash
php artisan vendor:publish --tag=advanced-reports-config
php artisan vendor:publish --tag=advanced-reports-migrations
php artisan vendor:publish --tag=advanced-reports-views
```

Run migrations manually when preferred:

```bash
php artisan migrate
```

The migrations create report definitions, runs, exports, snapshots, schedules, permissions, and connected-source records. The reports migration has an optional foreign key to `users.created_by`; applications using that migration should have their users table available first.

## Configuration

The package uses `config/advanced-reports.php`. It controls the database connection and table prefix, permission and tenant settings, renderer classes, PDF options, synchronous/queued export behavior, cache and row limits, run history, route prefixes/middleware, and designer settings.

The default API prefix is `api/advanced-reports`. API routes are enabled by default; web preview/download routes are disabled by default. Environment variables documented in the configuration file can override these settings, including `ADVANCED_REPORTS_API_PREFIX`, `ADVANCED_REPORTS_WEB_PREFIX`, `ADVANCED_REPORTS_PDF_DRIVER`, `ADVANCED_REPORTS_QUEUE_EXPORTS`, and the security/performance variables.

## Report sources

Create a source by using the included generator:

```bash
php artisan make:report-source SalesOrderReportSource
```

A source extends `ReportSource`, declares a key, label, fields, optional parameters, and a query:

```php
namespace App\Reports\Sources;

use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use Illuminate\Contracts\Database\Query\Builder;

class SalesOrderReportSource extends ReportSource
{
    public function key(): string { return 'sales_orders'; }
    public function label(): string { return 'Sales Orders'; }

    protected function defineFields(): array
    {
        return [
            $this->field('order_number', 'Order No'),
            $this->field('order_date', 'Order Date', type: 'date'),
            $this->field('total_amount', 'Amount', type: 'decimal', aggregatable: true),
        ];
    }

    public function query(array $parameters = []): Builder
    {
        return \DB::table('sales_orders');
    }
}
```

Register the source from an application service provider:

```php
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;

AdvancedReports::registerSource(\App\Reports\Sources\SalesOrderReportSource::class);
```

Fields support the existing types `string`, `integer`, `decimal`, `boolean`, `date`, `datetime`, and `json`; they can be sortable, filterable, aggregatable, hidden, and formatted. Parameters support `string`, `integer`, `decimal`, `boolean`, `date`, `datetime`, and `array`.

## Report definitions and execution

Definitions are arrays or `ReportDefinition` objects. The persisted `Report` model stores the definition as JSON:

```php
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;

Report::create([
    'name' => 'Sales',
    'code' => 'sales',
    'data_source' => 'sales_orders',
    'definition' => [
        'name' => 'Sales',
        'data_source' => 'sales_orders',
        'parameters' => [
            ['name' => 'date_from', 'type' => 'date', 'required' => true],
        ],
        'columns' => [
            ['field' => 'order_number', 'label' => 'Order'],
            ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'decimal'],
        ],
        'filters' => [
            ['field' => 'total_amount', 'operator' => 'gte', 'value' => 1000],
        ],
        'sorts' => [
            ['field' => 'total_amount', 'direction' => 'desc'],
        ],
        'aggregates' => [
            ['field' => 'total_amount', 'function' => 'sum'],
        ],
    ],
    'is_active' => true,
    'is_public' => true,
]);

$result = AdvancedReports::run('sales', ['date_from' => '2026-01-01']);
$html = AdvancedReports::render('sales', 'html');
$json = AdvancedReports::render('sales', 'json');
$download = AdvancedReports::export('sales', 'csv');
$export = AdvancedReports::queueExport('sales', 'pdf');
```

The manager also exposes `definition`, `resolveReport`, `authorize`, `sources`, `source`, `registerSource`, `fields`, and `listRegisteredSources`. The facade exposes the corresponding report operations shown above.

## HTTP routes

When enabled, the API provides source listing/schema and dynamic-source endpoints, report CRUD, inline and persisted previews, report runs, synchronous exports, export lookup, and export downloads. Routes use the configured prefix and middleware.

Optional web routes provide:

- `GET /advanced-reports/reports/{report}/preview`
- `GET /advanced-reports/reports/{report}/download`

Enable them with `ADVANCED_REPORTS_WEB_ROUTES=true` or the equivalent configuration value. Route authentication and authorization should be supplied by the application middleware and policy configuration.

## Customization

The service provider publishes `resources/views/vendor/advanced-reports` for customized HTML/PDF views. Renderer classes are configurable under `renderers`; custom implementations can implement `ReportRenderer` or `ReportExporter` and be registered in application configuration/container bindings. PDF supports `dompdf`, `browsershot`, and a custom tagged engine binding as described in the configuration file.

## Console commands

```bash
php artisan advanced-reports:install
php artisan make:report-source SalesOrderReportSource
php artisan advanced-reports:cleanup-runs --dry-run
```

`advanced-reports:cleanup-runs` removes old runs and expired export records/files according to configuration, or reports what it would remove with `--dry-run`.

## Testing

```bash
composer validate
composer test
```

The test suite uses Orchestra Testbench and an in-memory SQLite database. PHPUnit is configured to run both `tests/Unit` and `tests/Feature`.

## License

This package is released under the MIT license.
