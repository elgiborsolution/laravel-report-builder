<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Http\Controllers\ReportController;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);
});

it('resolves the report controller without a circular dependency', function () {
    expect(app(ReportController::class))->toBeInstanceOf(ReportController::class);
});

it('uses the package report table when listing reports', function () {
    $this->getJson('/api/advanced-reports/reports')->assertOk();
});

it('lists registered sources via the sources endpoint', function () {
    $this->getJson('/api/advanced-reports/sources')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'sales_orders');
});

it('returns a full source schema', function () {
    $this->getJson('/api/advanced-reports/sources/sales_orders/schema')
        ->assertOk()
        ->assertJsonPath('source.key', 'sales_orders')
        ->assertJsonStructure([
            'source' => ['key', 'label', 'description'],
            'fields' => [['key', 'label', 'type', 'sortable', 'filterable', 'aggregatable', 'hidden']],
            'parameters',
            'operators',
            'aggregate_functions',
            'formats',
        ]);
});

it('returns 404 for unregistered source schema', function () {
    $this->getJson('/api/advanced-reports/sources/nope/schema')->assertNotFound();
});

it('runs a report through the API and returns metadata', function () {
    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Sales',
        'code' => 'api_sales',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Sales',
            'data_source' => 'sales_orders',
            'columns' => [['field' => 'order_number', 'label' => 'Order']],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);

    $this->postJson('/api/advanced-reports/reports/api_sales/run', [
        'format' => 'json',
    ])
        ->assertOk()
        ->assertJsonStructure(['metadata', 'columns', 'rows']);
});
