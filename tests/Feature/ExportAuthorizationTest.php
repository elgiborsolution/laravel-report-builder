<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Foundation\Auth\User;

uses(TestCase::class);

beforeEach(function () {
    config(['advanced-reports.security.enforce_permissions' => true]);
    AdvancedReports::registerSource(SalesOrderReportSource::class);
    $this->report = Report::create([
        'name' => 'Owner export',
        'code' => 'owner-export',
        'data_source' => 'sales_orders',
        'created_by' => '947',
        'definition' => [
            'name' => 'Owner export',
            'data_source' => 'sales_orders',
            'columns' => [['field' => 'order_number']],
            'parameters' => [['name' => 'id', 'type' => 'string', 'required' => false]],
        ],
    ]);
    $this->actingAs((new User())->forceFill(['id' => 947]));
    $this->exportUrl = '/api/advanced-reports/reports/'.$this->report->uuid.'/export';
    $this->parameters = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'id' => null];
});

it('lets the report creator export without additional permission rows and keeps optional null parameters valid', function () {
    $this->postJson($this->exportUrl, ['format' => 'csv', 'parameters' => $this->parameters])->assertOk();
});

it('returns 403 for a different user without export access', function () {
    $this->actingAs((new User())->forceFill(['id' => 948]));
    $this->postJson($this->exportUrl, ['format' => 'csv', 'parameters' => $this->parameters])
        ->assertForbidden()
        ->assertJsonPath('message', 'User is not authorized to [export] report [owner-export].');
});

it('returns actionable 422 errors for missing required or invalid typed parameters before execution', function () {
    foreach ([
        [['date_from' => null], 'Parameter [date_from] is required.'],
        [['date_from' => 'not-a-date'], 'Parameter [date_from] must be of type [date].'],
        [['id' => ['invalid']], 'Parameter [id] must be of type [string].'],
    ] as [$invalid, $message]) {
        $this->postJson($this->exportUrl, [
            'format' => 'csv',
            'parameters' => array_replace($this->parameters, $invalid),
        ])->assertUnprocessable()->assertJsonPath('errors.0', $message);
    }
});

it('rejects unsupported xls while accepting the xlsx format contract', function () {
    $this->postJson($this->exportUrl, ['format' => 'xls', 'parameters' => $this->parameters])
        ->assertUnprocessable()->assertJsonValidationErrors('format');
    $request = new \ElgiborSolution\AdvancedReports\Http\Requests\ExportReportRequest();
    expect(validator(['format' => 'xlsx', 'parameters' => $this->parameters], $request->rules())->passes())->toBeTrue();
});

it('validates parameters before a queued export is dispatched', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $this->postJson($this->exportUrl, [
        'format' => 'pdf', 'queued' => true,
        'parameters' => array_replace($this->parameters, ['date_from' => null]),
    ])->assertUnprocessable()->assertJsonPath('errors.0', 'Parameter [date_from] is required.');
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});
