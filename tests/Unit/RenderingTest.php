<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer;
use ElgiborSolution\AdvancedReports\Renderers\JsonRenderer;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);

    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Sales',
        'code' => 'sales_json',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Sales',
            'data_source' => 'sales_orders',
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'currency'],
            ],
            'aggregates' => [
                ['field' => 'total_amount', 'function' => 'sum', 'label' => 'Total'],
            ],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);
});

it('renders JSON output with required keys', function () {
    $rendered = AdvancedReports::render('sales_json', 'json');

    expect($rendered)
        ->toHaveKeys(['metadata', 'columns', 'rows', 'groups', 'aggregates', 'drilldowns'])
        ->and($rendered['rows'])->not->toBeEmpty()
        ->and($rendered['aggregates']['Total'])->toBe(8000.0);
});

it('renders HTML output as a string containing a table', function () {
    $html = AdvancedReports::render('sales_json', 'html');

    expect($html)->toBeString()
        ->and($html)->toContain('<table')
        ->and($html)->toContain('Sales')
        ->and($html)->toContain('SO-001');
});

it('excludes hidden fields from rendered output', function () {
    $rendered = AdvancedReports::render('sales_json', 'json');

    foreach ($rendered['columns'] as $col) {
        expect($col['field'])->not->toBe('internal_cost');
    }
});
