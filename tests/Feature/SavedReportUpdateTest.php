<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);
    AdvancedReports::registerSource(new class extends SalesOrderReportSource {
        public function key(): string { return 'alternate_sales'; }
    });
    $this->definition = [
        'name' => 'Saved sales', 'data_source' => 'sales_orders',
        'parameters' => [
            ['name' => 'date_from', 'default' => '2026-01-01'],
            ['name' => 'date_to', 'default' => '2026-12-31'],
            ['name' => 'id', 'type' => 'string', 'required' => false, 'default' => null],
        ],
        'columns' => [['field' => 'order_number', 'label' => 'Order'], ['field' => 'total_amount', 'format' => 'decimal']],
        'groups' => [['field' => 'customer_name']],
        'aggregates' => [['field' => 'total_amount', 'function' => 'sum']],
        'sorts' => [['field' => 'order_number', 'direction' => 'asc']],
        'filters' => [], 'formulas' => [], 'drilldowns' => [], 'subreports' => [],
        'conditional_formatting' => [['field' => 'total_amount', 'operator' => '>', 'value' => 0, 'style' => ['color' => '#123456']]],
        'layout' => [
            'title' => 'Saved layout',
            'headerText' => 'Quarterly overview',
            'footerText' => 'Internal use only',
            'pageSize' => 'a4',
            'orientation' => 'landscape',
            'showPageNumbers' => true,
        ],
        'meta' => ['application_tag' => 'keep'],
    ];
    $this->report = Report::create([
        'name' => 'Saved sales', 'code' => 'saved-sales', 'data_source' => 'sales_orders',
        'definition' => $this->definition, 'is_public' => true, 'is_active' => true,
    ]);
    $this->url = '/api/advanced-reports/reports/'.$this->report->uuid;
});

it('persists the full frontend update definition and reloads it for saved preview and run', function () {
    $this->putJson($this->url, ['name' => 'Saved sales', 'data_source' => 'sales_orders', 'definition' => $this->definition])
        ->assertOk()->assertJsonPath('data.definition', $this->definition);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.definition.data_source', 'sales_orders')
        ->assertJsonPath('data.definition.layout.headerText', 'Quarterly overview')
        ->assertJsonPath('data.definition.layout.footerText', 'Internal use only')
        ->assertJsonPath('data.definition.layout.orientation', 'landscape');
    $this->postJson($this->url.'/preview', ['parameters' => ['id' => null]])
        ->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('presentation_rows.0.type', 'group_header');
    $this->postJson($this->url.'/run', ['format' => 'json', 'parameters' => ['id' => null]])
        ->assertOk()->assertJsonCount(3, 'rows');
});

it('merges definition patches by section and preserves omitted settings and source references', function () {
    $this->patchJson($this->url, ['definition' => ['sorts' => [], 'groups' => []]])->assertOk();
    $expected = array_replace($this->definition, ['sorts' => [], 'groups' => []]);
    expect($this->report->fresh()->definition)->toBe($expected);
    $this->patchJson($this->url, ['description' => 'Metadata only'])->assertOk();
    expect($this->report->fresh()->definition)->toBe($expected);
});

it('synchronizes a valid explicit source change from either location', function () {
    foreach ([['data_source' => 'alternate_sales'], ['definition' => ['data_source' => 'sales_orders']]] as $patch) {
        $key = $patch['data_source'] ?? $patch['definition']['data_source'];
        $this->patchJson($this->url, $patch)->assertOk()
            ->assertJsonPath('data.data_source', $key)->assertJsonPath('data.definition.data_source', $key);
    }
});

it('rejects empty unregistered conflicting or malformed source updates without altering persistence', function () {
    foreach ([
        ['data_source' => ''], ['data_source' => null], ['data_source' => '   '],
        ['data_source' => 'missing_source'],
        ['definition' => ['data_source' => '']], ['definition' => ['data_source' => null]],
        ['definition' => ['data_source' => []]], ['definition' => ['data_source' => 'missing_source']],
        ['data_source' => 'sales_orders', 'definition' => ['data_source' => 'alternate_sales']],
        ['definition' => null],
    ] as $patch) {
        $this->patchJson($this->url, $patch)->assertUnprocessable();
        expect($this->report->fresh()->definition)->toBe($this->definition)
            ->and($this->report->fresh()->data_source)->toBe('sales_orders');
    }
});

it('repairs the previous stripped definition through a normal update using its persisted source', function () {
    $stripped = $this->definition;
    unset($stripped['name'], $stripped['data_source']);
    $this->report->update(['definition' => $stripped]);
    $this->patchJson($this->url, ['description' => 'Retained'])->assertOk()
        ->assertJsonPath('data.definition.data_source', 'sales_orders')->assertJsonPath('data.definition.name', 'Saved sales');
    expect($this->report->fresh()->definition)->toEqual($this->definition);
    $this->postJson($this->url.'/preview', ['parameters' => ['id' => null]])->assertOk();
});
