<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinitionValidator;
use ElgiborSolution\AdvancedReports\Exceptions\DefinitionInvalidException;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);
});

it('passes a valid definition', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Sales',
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
            ['field' => 'order_date', 'operator' => 'between', 'value' => ['2026-01-01', '2026-01-31']],
        ],
    ]);

    app(ReportDefinitionValidator::class)
        ->validate($def, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

    expect(true)->toBeTrue();
});

it('allows count on any valid field and numeric aggregates on decimal fields', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Grouped totals',
        'data_source' => 'sales_orders',
        'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
        'aggregates' => [
            ['field' => 'customer_name', 'function' => 'count', 'label' => 'Rows'],
            ...array_map(fn (string $function) => [
                'field' => 'total_amount', 'function' => $function, 'label' => ucfirst($function),
            ], ['sum', 'avg', 'min', 'max']),
        ],
    ]);

    app(ReportDefinitionValidator::class)->validate($def);

    expect(true)->toBeTrue();
});

it('rejects numeric aggregate functions on non-numeric fields', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Invalid total',
        'data_source' => 'sales_orders',
        'aggregates' => [['field' => 'customer_name', 'function' => 'sum']],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect(collect($e->errors)->implode('; '))->toContain('requires an aggregatable numeric field');
    }
});

it('rejects an unknown source', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'X',
        'data_source' => 'not_registered',
    ]);

    app(ReportDefinitionValidator::class)->validate($def, []);
})->throws(DefinitionInvalidException::class);

it('rejects columns referencing unknown fields', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'does_not_exist', 'label' => 'X']],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def, []);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect($e->errors)->toHaveKey(0)
            ->and($e->errors[0])->toContain('unknown field');
    }
});

it('accepts a formula as a report column', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Calculated sales',
        'data_source' => 'sales_orders',
        'columns' => [
            ['field' => 'order_number', 'label' => 'Order'],
            ['field' => 'total_with_tax', 'label' => 'Total with tax', 'type' => 'decimal', 'format' => 'currency'],
        ],
        'formulas' => [[
            'name' => 'total_with_tax',
            'label' => 'Total with tax',
            'expression' => 'total_amount * 1.11',
            'type' => 'decimal',
            'format' => 'currency',
        ]],
    ]);

    app(ReportDefinitionValidator::class)->validate($def);

    expect(true)->toBeTrue();
});

it('rejects duplicate formula names and source-field collisions', function () {
    foreach ([
        [
            ['name' => 'computed_total', 'expression' => 'total_amount + 1'],
            ['name' => 'COMPUTED_TOTAL', 'expression' => 'total_amount + 2'],
        ],
        [['name' => 'total_amount', 'expression' => 'total_amount + 1']],
    ] as $formulas) {
        $def = ReportDefinition::fromArray([
            'name' => 'Invalid formulas',
            'data_source' => 'sales_orders',
            'formulas' => $formulas,
        ]);

        try {
            app(ReportDefinitionValidator::class)->validate($def);
            $this->fail('Expected DefinitionInvalidException');
        } catch (DefinitionInvalidException $e) {
            expect(collect($e->errors)->implode('; '))
                ->toMatch('/duplicated|conflicts with a source field/i');
        }
    }
});

it('rejects hidden fields used as columns', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'X',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'internal_cost', 'label' => 'Cost']],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def, []);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect(collect($e->errors)->implode('; '))->toContain('hidden');
    }
});

it('rejects unsupported filter operators', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'X',
        'data_source' => 'sales_orders',
        'filters' => [['field' => 'total_amount', 'operator' => 'looks_like', 'value' => 'x']],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def, []);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect(collect($e->errors)->implode('; '))->toContain('unsupported operator');
    }
});

it('rejects missing required parameters', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'X',
        'data_source' => 'sales_orders',
        'parameters' => [['name' => 'date_from', 'type' => 'date', 'required' => true]],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def, []);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect(collect($e->errors)->implode('; '))->toContain('date_from');
    }
});

it('validates required parameters declared by the source even when not copied into the report definition', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Source-defined parameters',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'order_number', 'label' => 'Order']],
    ]);

    try {
        app(ReportDefinitionValidator::class)->validate($def, ['date_from' => '2026-01-01']);
        $this->fail('Expected DefinitionInvalidException');
    } catch (DefinitionInvalidException $e) {
        expect(collect($e->errors)->implode('; '))->toContain('date_to');
    }
});

it('accepts false and zero as provided required parameter values', function () {
    $def = ReportDefinition::fromArray([
        'name' => 'Falsy runtime parameters',
        'data_source' => 'sales_orders',
        'parameters' => [
            ['name' => 'disabled', 'type' => 'boolean', 'required' => true],
            ['name' => 'minimum', 'type' => 'decimal', 'required' => true],
        ],
    ]);

    app(ReportDefinitionValidator::class)->validate($def, [
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
        'disabled' => false,
        'minimum' => 0,
    ]);

    expect(true)->toBeTrue();
});
