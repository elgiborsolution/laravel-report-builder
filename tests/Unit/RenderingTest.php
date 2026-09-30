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
                ['field' => 'amount_with_tax', 'label' => 'Amount with tax', 'format' => 'currency'],
            ],
            'formulas' => [[
                'name' => 'amount_with_tax',
                'label' => 'Amount with tax',
                'expression' => 'total_amount * 1.11',
                'type' => 'decimal',
                'format' => 'currency',
            ]],
            'aggregates' => [
                ['field' => 'customer_name', 'function' => 'count', 'label' => 'Rows'],
                ['field' => 'total_amount', 'function' => 'sum', 'label' => 'Sum'],
                ['field' => 'total_amount', 'function' => 'avg', 'label' => 'Average'],
                ['field' => 'total_amount', 'function' => 'min', 'label' => 'Minimum'],
                ['field' => 'total_amount', 'function' => 'max', 'label' => 'Maximum'],
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
        ->and($rendered['columns'][2])->toBe([
            'field' => 'amount_with_tax',
            'label' => 'Amount with tax',
            'type' => 'decimal',
            'format' => 'currency',
        ])
        ->and($rendered['rows'][0]['amount_with_tax'])->toContain('1,110')
        ->and($rendered['rows'])->not->toBeEmpty()
        ->and($rendered['aggregates'])->toBe([
            'Rows' => 3,
            'Sum' => 8000.0,
            'Average' => 8000 / 3,
            'Minimum' => 1000.0,
            'Maximum' => 5000.0,
        ]);
});

it('renders HTML output as a string containing a table', function () {
    $html = AdvancedReports::render('sales_json', 'html');

    expect($html)->toBeString()
        ->and($html)->toContain('<table')
        ->and($html)->toContain('Sales')
        ->and($html)->toContain('Amount with tax')
        ->and($html)->toContain('1,110')
        ->and($html)->toContain('SO-001');
});

it('exports selected formula columns to CSV and XLSX in matching order', function () {
    $csvResponse = AdvancedReports::render('sales_json', 'csv');
    ob_start();
    $csvResponse->sendContent();
    $csv = ob_get_clean();

    expect($csv)
        ->toContain('Amount with tax')
        ->toContain('1,110');

    $result = AdvancedReports::run('sales_json');
    $excel = app(\ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class);
    $property = new ReflectionProperty($excel, 'result');
    $property->setAccessible(true);
    $property->setValue($excel, $result);

    expect($excel->headings())->toBe(['Order', 'Amount', 'Amount with tax'])
        ->and($excel->collection()->first()[2])->toContain('1,110');
});

it('excludes hidden fields from rendered output', function () {
    $rendered = AdvancedReports::render('sales_json', 'json');

    foreach ($rendered['columns'] as $col) {
        expect($col['field'])->not->toBe('internal_cost');
    }
});
