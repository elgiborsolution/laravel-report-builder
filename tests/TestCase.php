<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests;

use ElgiborSolution\AdvancedReports\AdvancedReportsServiceProvider;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdvancedReportsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['AdvancedReports' => AdvancedReports::class];
    }

    /**
     * Define environment for the test suite.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Keep compiled Blade output on a writable test path. The package
        // checkout may be read-only when tests run through the host app's vendor tree.
        $compiledViews = sys_get_temp_dir().DIRECTORY_SEPARATOR.'advanced-reports-test-views';
        if (! is_dir($compiledViews)) {
            mkdir($compiledViews, 0777, true);
        }
        $app['config']->set('view.compiled', $compiledViews);
        $excelTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'advanced-reports-excel';
        if (! is_dir($excelTemp)) {
            mkdir($excelTemp, 0777, true);
        }
        $app['config']->set('excel.temporary_files.local_path', $excelTemp);

        // Disable permission enforcement by default so unit tests can run without auth.
        $app['config']->set('advanced-reports.security.enforce_permissions', false);
        $app['config']->set('advanced-reports.routes.api.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Create a small sales_orders test table that the sample source reads from.
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function ($table) {
                $table->id();
                $table->string('order_number');
                $table->date('order_date');
                $table->decimal('total_amount', 12, 2);
                $table->decimal('internal_cost', 12, 2)->default(0); // hidden field
                $table->string('customer_name');
                $table->timestamps();
            });
        }

        // Seed sample data used across tests.
        \DB::table('sales_orders')->insert([
            ['order_number' => 'SO-001', 'order_date' => '2026-01-10', 'total_amount' => 1000, 'customer_name' => 'Acme', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'SO-002', 'order_date' => '2026-01-20', 'total_amount' => 5000, 'customer_name' => 'Acme', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'SO-003', 'order_date' => '2026-02-05', 'total_amount' => 2000, 'customer_name' => 'Globex', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
