<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Engine\ReportEngine;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\GroupingRowsReportSource;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\PartialSelectionGroupingRowsReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

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

it('resolves source-declared runtime parameters even when they are not copied into the saved definition', function () {
    makeReport('Runtime Source Parameters', [
        'name' => 'Runtime Source Parameters',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'order_number', 'label' => 'Order']],
    ]);

    $result = AdvancedReports::run('runtime_source_parameters', [
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);

    expect($result->parameters['date_from']->toDateString())->toBe('2026-01-01')
        ->and($result->parameters['date_to']->toDateString())->toBe('2026-01-31');
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

    expect($result->aggregates['Total Amount'])->toBe(8000.0)
        ->and($result->rows)->toBeInstanceOf(\Illuminate\Support\LazyCollection::class)
        ->and($result->presentationRows)->toHaveCount(1)
        ->and($result->presentationRows[0]['type'])->toBe('grand_total');
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
    $acmeSubtotal = collect($result->presentationRows)->first(
        static fn ($row) => ($row['type'] ?? null) === 'group_subtotal' && ($row['value'] ?? null) === 'Acme',
    );

    expect($result->groups)->toHaveKey('customer_name')
        ->and($result->aggregates)->toBe([
            'Rows' => 3,
            'Sum' => 8000.0,
            'Average' => 8000 / 3,
            'Minimum' => 1000.0,
            'Maximum' => 5000.0,
        ])
        ->and($acmeSubtotal['aggregate_cells']['total_amount'][0]['value'])->toBe(6000.0)
        ->and(collect($result->presentationRows)->last()['type'])->toBe('grand_total');
});

it('builds nested path-aware groups, detail references, subtotals, and accurate grand totals', function () {
    Schema::dropIfExists('grouping_rows');
    Schema::create('grouping_rows', function ($table) {
        $table->id();
        $table->string('category')->nullable();
        $table->string('subcategory')->nullable();
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 12, 2)->nullable();
    });
    \DB::table('grouping_rows')->insert([
        ['category' => 'Electronics', 'subcategory' => 'Cable', 'quantity' => 2, 'amount' => 20],
        ['category' => 'Electronics', 'subcategory' => 'Cable', 'quantity' => 3, 'amount' => 30],
        ['category' => 'Furniture', 'subcategory' => 'Cable', 'quantity' => 4, 'amount' => 40],
        ['category' => 'Furniture', 'subcategory' => null, 'quantity' => 1, 'amount' => 10],
        ['category' => null, 'subcategory' => null, 'quantity' => 5, 'amount' => 50],
        ['category' => null, 'subcategory' => null, 'quantity' => null, 'amount' => null],
    ]);
    AdvancedReports::registerSource(GroupingRowsReportSource::class);

    makeReport('Nested Grouping Accuracy', [
        'name' => 'Nested Grouping Accuracy',
        'data_source' => 'grouping_rows',
        // Group fields are deliberately absent from visible Columns.
        'columns' => [
            ['field' => 'quantity', 'label' => 'Quantity'],
            ['field' => 'amount', 'label' => 'Amount'],
        ],
        'groups' => [
            ['field' => 'category', 'label' => 'Category'],
            ['field' => 'subcategory', 'label' => 'Subcategory'],
        ],
        'aggregates' => [
            ['field' => 'quantity', 'function' => 'sum', 'label' => 'Quantity total'],
            ['field' => 'amount', 'function' => 'sum', 'label' => 'Amount total'],
            ['field' => 'amount', 'function' => 'count', 'label' => 'Amount count'],
        ],
    ]);

    $result = AdvancedReports::run('nested_grouping_accuracy', []);
    $presentationRows = collect($result->presentationRows);
    $headers = $presentationRows->where('type', 'group_header')->values();
    $subtotals = $presentationRows->where('type', 'group_subtotal')->values();
    $findSubtotal = static fn (callable $predicate) => $subtotals->first($predicate);

    $electronicsCable = $findSubtotal(static fn ($row) => $row['level'] === 1
        && $row['path'][0]['value'] === 'Electronics'
        && $row['path'][1]['value'] === 'Cable');
    $furnitureCable = $findSubtotal(static fn ($row) => $row['level'] === 1
        && $row['path'][0]['value'] === 'Furniture'
        && $row['path'][1]['value'] === 'Cable');
    $blankCategory = $findSubtotal(static fn ($row) => $row['level'] === 0 && $row['value'] === null);
    $grandTotal = $presentationRows->last();

    expect($result->rows)->toHaveCount(6)
        ->and($result->metadata['row_count'])->toBe(6)
        ->and($headers)->toHaveCount(7)
        ->and($subtotals)->toHaveCount(7)
        ->and($electronicsCable['key'])->not->toBe($furnitureCable['key'])
        ->and($electronicsCable['aggregate_cells']['amount'][0]['value'])->toBe(50.0)
        ->and($furnitureCable['aggregate_cells']['amount'][0]['value'])->toBe(40.0)
        ->and($blankCategory['display_value'])->toBe('(blank)')
        ->and($blankCategory['aggregate_cells']['quantity'][0]['value'])->toBe(5)
        ->and($blankCategory['aggregate_cells']['amount'][0]['value'])->toBe(50.0)
        ->and($blankCategory['aggregate_cells']['amount'][1]['value'])->toBe(1)
        ->and($result->aggregates)->toBe([
            'Quantity total' => 15,
            'Amount total' => 150.0,
            'Amount count' => 5,
        ])
        ->and($grandTotal['type'])->toBe('grand_total')
        ->and($grandTotal['aggregate_cells']['quantity'][0]['value'])->toBe(15)
        ->and($grandTotal['aggregate_cells']['amount'][0]['value'])->toBe(150.0);
});

it('keeps group totals scoped to filtered details and handles empty results', function () {
    Schema::dropIfExists('grouping_rows');
    Schema::create('grouping_rows', function ($table) {
        $table->id();
        $table->string('category')->nullable();
        $table->string('subcategory')->nullable();
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 12, 2)->nullable();
    });
    \DB::table('grouping_rows')->insert([
        ['category' => 'Furniture', 'subcategory' => 'Chair', 'quantity' => 2, 'amount' => 80],
        ['category' => 'Furniture', 'subcategory' => 'Desk', 'quantity' => 1, 'amount' => 120],
        ['category' => 'Books', 'subcategory' => 'Guide', 'quantity' => 3, 'amount' => 30],
    ]);
    AdvancedReports::registerSource(GroupingRowsReportSource::class);

    makeReport('Filtered Group Totals', [
        'name' => 'Filtered Group Totals',
        'data_source' => 'grouping_rows',
        'columns' => [['field' => 'amount', 'label' => 'Amount']],
        'groups' => [['field' => 'category', 'label' => 'Category']],
        'aggregates' => [['field' => 'amount', 'function' => 'sum', 'label' => 'Amount total']],
        'filters' => [['field' => 'category', 'operator' => '=', 'value' => 'Furniture']],
    ]);

    makeReport('Empty Group Totals', [
        'name' => 'Empty Group Totals',
        'data_source' => 'grouping_rows',
        'columns' => [['field' => 'amount', 'label' => 'Amount']],
        'groups' => [['field' => 'category', 'label' => 'Category']],
        'aggregates' => [
            ['field' => 'amount', 'function' => 'sum', 'label' => 'Amount total'],
            ['field' => 'amount', 'function' => 'count', 'label' => 'Amount count'],
        ],
        'filters' => [['field' => 'category', 'operator' => '=', 'value' => 'Missing']],
    ]);

    $filtered = AdvancedReports::run('filtered_group_totals', []);
    $filteredSubtotal = collect($filtered->presentationRows)->firstWhere('type', 'group_subtotal');
    $empty = AdvancedReports::run('empty_group_totals', []);

    expect($filtered->rows)->toHaveCount(2)
        ->and($filtered->aggregates['Amount total'])->toBe(200.0)
        ->and($filteredSubtotal['aggregate_cells']['amount'][0]['value'])->toBe(200.0)
        ->and($empty->rows)->toBeEmpty()
        ->and($empty->groups)->toBe([])
        ->and($empty->presentationRows)->toHaveCount(1)
        ->and($empty->presentationRows[0]['type'])->toBe('grand_total')
        ->and($empty->aggregates)->toBe(['Amount total' => 0, 'Amount count' => 0]);
});

it('selects grouping and aggregate fields omitted by a source projection', function () {
    Schema::dropIfExists('grouping_rows');
    Schema::create('grouping_rows', function ($table) {
        $table->id();
        $table->string('category')->nullable();
        $table->string('subcategory')->nullable();
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 12, 2)->nullable();
    });
    \DB::table('grouping_rows')->insert([
        ['category' => 'Books', 'subcategory' => 'Guide', 'quantity' => 2, 'amount' => 30],
    ]);
    AdvancedReports::registerSource(PartialSelectionGroupingRowsReportSource::class);

    makeReport('Hidden Group Field Selection', [
        'name' => 'Hidden Group Field Selection',
        'data_source' => 'grouping_rows_partial',
        'columns' => [['field' => 'amount', 'label' => 'Amount']],
        'groups' => [['field' => 'category', 'label' => 'Category']],
        'aggregates' => [['field' => 'amount', 'function' => 'sum', 'label' => 'Amount total']],
    ]);

    $result = AdvancedReports::run('hidden_group_field_selection', []);

    expect($result->rows->first())->toHaveKey('category', 'Books')
        ->and($result->presentationRows[0]['type'])->toBe('group_header')
        ->and($result->presentationRows[0]['display_value'])->toBe('Books')
        ->and($result->aggregates['Amount total'])->toBe(30.0);
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
