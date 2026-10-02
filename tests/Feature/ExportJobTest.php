<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Export\Jobs\ExportReportJob;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);

    $this->report = Report::create([
        'name' => 'Exportable',
        'code' => 'exportable',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Exportable',
            'data_source' => 'sales_orders',
            'parameters' => [
                ['name' => 'date_from', 'default' => '2026-01-01'],
                ['name' => 'date_to', 'default' => '2026-12-31'],
            ],
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount'],
            ],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);
});

it('queues an export and creates a pending ReportExport row', function () {
    Queue::fake();

    $export = AdvancedReports::queueExport('exportable', 'csv', []);

    expect($export)->toBeInstanceOf(ReportExport::class)
        ->and($export->status)->toBe(ReportExport::STATUS_PENDING)
        ->and($export->format)->toBe('csv');

    Queue::assertPushed(ExportReportJob::class);
});

it('synchronous export returns a streamed CSV response', function () {
    $response = AdvancedReports::export('exportable', 'csv');

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toStartWith('text/csv');
});

it('records an export row in the database when queued', function () {
    Queue::fake();

    $countBefore = ReportExport::count();

    AdvancedReports::queueExport('exportable', 'pdf');

    expect(ReportExport::count())->toBe($countBefore + 1);
});
