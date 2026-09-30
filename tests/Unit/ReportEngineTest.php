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

it('computes all supported aggregates for a grouped decimal-field report', function () {
    $report = makeReport('Grouped aggregate functions', [
        'name' => 'Grouped aggregate functions',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'customer_name', 'label' => 'Customer']],
        'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
        'aggregates' => [
            ['field' => 'customer_name', 'function' => 'count', 'label' => 'Rows'],
            ['field' => 'total_amount', 'function' => 'sum', 'label' => 'Sum'],
            ['field' => 'total_amount', 'function' => 'avg', 'label' => 'Average'],
            ['field' => 'total_amount', 'function' => 'min', 'label' => 'Minimum'],
            ['field' => 'total_amount', 'function' => 'max', 'label' => 'Maximum'],
        ],
    ]);

    $result = AdvancedReports::run('grouped_aggregate_functions', []);

    expect($result->groups)->toHaveKey('customer_name')
        ->and($result->aggregates)->toBe([
            'Rows' => 3,
            'Sum' => 8000.0,
            'Average' => 8000 / 3,
            'Minimum' => 1000.0,
            'Maximum' => 5000.0,
        ]);
});

it('evaluates formulas row-by-row', function () {
    $report = makeReport('Sales With Tax', [
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [
            ['field' => 'order_number', 'label' => 'Order'],
            ['field' => 'total_amount', 'label' => 'Amount'],
            ['field' => 'tax_amount', 'label' => 'Tax', 'format' => 'currency'],
        ],
        'formulas' => [
            ['name' => 'tax_amount', 'label' => 'Tax', 'expression' => 'total_amount * 0.11', 'type' => 'decimal', 'format' => 'currency'],
        ],
        'filters' => [
            ['field' => 'order_number', 'operator' => '=', 'value' => 'SO-001'],
        ],
    ]);

    $result = AdvancedReports::run('sales_with_tax', []);

    expect($result->rows->first()['tax_amount'])->toBe(110.0);
    expect($result->columns[2])->toBe([
        'field' => 'tax_amount',
        'label' => 'Tax',
        'format' => 'currency',
        'type' => 'decimal',
    ]);
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
