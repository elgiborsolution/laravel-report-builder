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
