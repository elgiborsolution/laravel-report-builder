<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

it('registers and resolves a source class-string', function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);

    /** @var SourceRegistry $registry */
    $registry = app(SourceRegistry::class);

    expect($registry->has('sales_orders'))->toBeTrue()
        ->and($registry->get('sales_orders'))->toBeInstanceOf(SalesOrderReportSource::class)
        ->and($registry->get('sales_orders')->key())->toBe('sales_orders');
});

it('registers an instance under an override key', function () {
    $instance = new SalesOrderReportSource();
    AdvancedReports::registerSource($instance, 'custom_key');

    expect(app(SourceRegistry::class)->get('custom_key'))->toBe($instance);
});

it('throws when getting an unregistered source', function () {
    app(SourceRegistry::class)->get('nope');
})->throws(\ElgiborSolution\AdvancedReports\Exceptions\SourceNotRegisteredException::class);

it('resolves report definitions saved with a bare Data Source ID to the dynamic source key', function () {
    $legacy = \ElgiborSolution\AdvancedReports\Definitions\ReportDefinition::fromArray(['name' => 'Legacy', 'data_source' => '12']);
    $current = \ElgiborSolution\AdvancedReports\Definitions\ReportDefinition::fromArray(['name' => 'Current', 'data_source' => 'dynamic:12']);
    $builtin = \ElgiborSolution\AdvancedReports\Definitions\ReportDefinition::fromArray(['name' => 'Builtin', 'data_source' => 'sales_orders']);

    expect($legacy->dataSource)->toBe('dynamic:12')
        ->and($legacy->toArray()['data_source'])->toBe('dynamic:12')
        ->and($current->dataSource)->toBe('dynamic:12')
        ->and($builtin->dataSource)->toBe('sales_orders');
});
