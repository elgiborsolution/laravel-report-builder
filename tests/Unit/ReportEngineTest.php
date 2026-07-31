<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Engine\ReportEngine;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);
});

function makeReport(string $name, array $definition): Report
{
    return Report::create([
        'name' => $name,
        'code' => strtolower(str_replace(' ', '_', $name)),
        'data_source' => $definition['data_source'] ?? 'sales_orders',
        'definition' => $definition,
        'is_active' => true,
        'is_public' => true,
    ]);
}

it('runs a report with date-range filter', function () {
    $report = makeReport('Sales By Customer', [
        'name' => 'Sales By Customer',
        'data_source' => 'sales_orders',
        'parameters' => [
            ['name' => 'date_from', 'type' => 'date', 'required' => true],
            ['name' => 'date_to', 'type' => 'date', 'required' => true],
        ],
        'columns' => [
            ['field' => 'order_number', 'label' => 'Order No'],
            ['field' => 'total_amount', 'label' => 'Amount'],
        ],
        'filters' => [
            ['field' => 'order_date', 'operator' => 'between', 'value' => ['{{date_from}}', '{{date_to}}']],
        ],
        'sorts' => [['field' => 'order_number', 'direction' => 'asc']],
    ]);

    $result = AdvancedReports::run('sales_by_customer', [
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);

    expect($result->rows)->toHaveCount(2)
        ->and($result->metadata['row_count'])->toBe(2)
        ->and($result->rows->first()['order_number'])->toBe('SO-001');
});

it('applies aggregate computation', function () {
    $report = makeReport('Sales With Total', [
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'order_number', 'label' => 'Order']],
        'aggregates' => [['field' => 'total_amount', 'function' => 'sum', 'label' => 'Total Amount']],
        'filters' => [
            ['field' => 'order_date', 'operator' => 'between', 'value' => ['2026-01-01', '2026-12-31']],
        ],
    ]);

    $result = AdvancedReports::run('sales_with_total', []);

    expect($result->aggregates['Total Amount'])->toBe(8000.0);
});

it('evaluates formulas row-by-row', function () {
    $report = makeReport('Sales With Tax', [
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [
            ['field' => 'order_number', 'label' => 'Order'],
            ['field' => 'total_amount', 'label' => 'Amount'],
        ],
        'formulas' => [
            ['name' => 'tax_amount', 'expression' => 'total_amount * 0.11', 'type' => 'decimal'],
        ],
        'filters' => [
            ['field' => 'order_number', 'operator' => '=', 'value' => 'SO-001'],
        ],
    ]);

    $result = AdvancedReports::run('sales_with_tax', []);

    expect($result->rows->first()['tax_amount'])->toBe(110.0);
});

it('sorts results in descending order', function () {
    $report = makeReport('Sorted', [
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'order_number', 'label' => 'Order']],
        'sorts' => [['field' => 'total_amount', 'direction' => 'desc']],
    ]);

    $result = AdvancedReports::run('sorted', []);

    $amounts = $result->rows->pluck('total_amount')->all();
    expect($amounts[0])->toBeGreaterThan($amounts[count($amounts) - 1]);
});
