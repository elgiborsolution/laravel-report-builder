<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'advanced-reports:install
                            {--force : Overwrite existing published files}';

    protected $description = 'Publish Advanced Reports config, migrations, and views.';

    public function handle(): int
    {
        $this->info('Installing ElgiborSolution Advanced Reports...');

        $params = $this->option('force') ? ['--provider' => 'ElgiborSolution\AdvancedReports\AdvancedReportsServiceProvider', '--force' => true] : ['--provider' => 'ElgiborSolution\AdvancedReports\AdvancedReportsServiceProvider'];

        $this->call('vendor:publish', array_merge($params, ['--tag' => 'advanced-reports-config']));
        $this->call('vendor:publish', array_merge($params, ['--tag' => 'advanced-reports-migrations']));
        $this->call('vendor:publish', array_merge($params, ['--tag' => 'advanced-reports-views']));

        if ($this->confirm('Run the migrations now?', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('✓ Advanced Reports installed successfully.');
        $this->line('Next steps:');
        $this->line('  1. Register your first source in a ServiceProvider:');
        $this->line('     AdvancedReports::registerSource(SalesOrderReportSource::class);');
        $this->line('  2. Generate a source skeleton with:');
        $this->line('     php artisan make:report-source SalesOrderReportSource');
        $this->line('  3. Review config/advanced-reports.php for security/performance options.');

        return self::SUCCESS;
    }
}
