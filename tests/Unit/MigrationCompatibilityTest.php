<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

it('runs all package migrations without application identity tables', function () {
    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('roles'))->toBeFalse();

    foreach ([
        'advanced_reports',
        'advanced_report_runs',
        'advanced_report_exports',
        'advanced_report_snapshots',
        'advanced_report_schedules',
        'advanced_report_permissions',
        'advanced_report_connected_sources',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::getColumnType('advanced_reports', 'created_by'))->toBe('string')
        ->and(Schema::getColumnType('advanced_report_runs', 'user_id'))->toBe('string')
        ->and(Schema::getColumnType('advanced_report_permissions', 'role_id'))->toBe('string');
});
