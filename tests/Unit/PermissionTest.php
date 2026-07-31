<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportPermission;
use ElgiborSolution\AdvancedReports\Security\ReportPolicy;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

it('allows viewing public reports for anonymous users', function () {
    $report = Report::create([
        'name' => 'Public',
        'code' => 'public',
        'data_source' => 'sales_orders',
        'is_public' => true,
    ]);

    $policy = new ReportPolicy();

    expect($policy->view(null, $report))->toBeTrue();
});

it('denies private reports without permission', function () {
    $report = Report::create([
        'name' => 'Private',
        'code' => 'private',
        'data_source' => 'sales_orders',
        'is_public' => false,
    ]);

    $policy = new ReportPolicy();
    $guest = new class { public function getAuthIdentifier() { return 999; } };

    expect($policy->view($guest, $report))->toBeFalse();
});

it('allows access when an explicit permission row exists for the user', function () {
    $report = Report::create([
        'name' => 'Private',
        'code' => 'private2',
        'data_source' => 'sales_orders',
        'is_public' => false,
    ]);

    ReportPermission::create([
        'report_id' => $report->id,
        'user_id' => 5,
        'permission' => ReportPermission::PERMISSION_VIEW,
    ]);

    $policy = new ReportPolicy();
    $user = new class { public function getAuthIdentifier() { return 5; } };

    expect($policy->view($user, $report))->toBeTrue();
});
