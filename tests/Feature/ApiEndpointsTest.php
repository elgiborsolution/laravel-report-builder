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

it('returns separate detail rows and grouping presentation events for designer preview', function () {
    $response = $this->postJson('/api/advanced-reports/reports/preview-inline', [
        'definition' => [
            'name' => 'Grouped Preview',
            'data_source' => 'sales_orders',
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'decimal'],
            ],
            'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
            'aggregates' => [['field' => 'total_amount', 'function' => 'sum', 'label' => 'Amount total']],
        ],
        'parameters' => [],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['rows', 'groups', 'aggregates', 'presentation_rows', 'metadata'])
        ->assertJsonPath('metadata.row_count', 3);

    $presentationRows = $response->json('presentation_rows');
    $types = array_column($presentationRows, 'type');
    $detailRows = array_values(array_filter($presentationRows, static fn ($row) => ($row['type'] ?? null) === 'detail'));

    expect($response->json('rows'))->toHaveCount(3)
        ->and($detailRows)->toHaveCount(3)
        ->and($detailRows[0])->toHaveKey('row_index', 0)
        ->and($types)->toContain('group_header', 'group_subtotal', 'grand_total');
});
