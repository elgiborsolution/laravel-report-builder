<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Console;

use ElgiborSolution\AdvancedReports\Models\ReportExport;
use ElgiborSolution\AdvancedReports\Models\ReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupReportRunsCommand extends Command
{
    protected $signature = 'advanced-reports:cleanup-runs
                            {--days= : Override retention days}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete old report runs and expired exports.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('advanced-reports.history.cleanup.keep_days', 30));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up records older than {$days} days".($dryRun ? ' (dry run)' : '').'...');

        // 1. Report runs
        $runsQuery = ReportRun::query()->where('created_at', '<', $cutoff);
        $runsCount = $runsQuery->count();

        if ($dryRun) {
            $this->line("Would delete {$runsCount} report runs.");
        } else {
            $runsQuery->delete();
            $this->line("Deleted {$runsCount} report runs.");
        }

        // 2. Report exports
        $retentionDays = (int) config('advanced-reports.export.retention_days', 7);
        $exportCutoff = now()->subDays($retentionDays);
        $exportsQuery = ReportExport::query()->where('created_at', '<', $exportCutoff);
        $exports = (clone $exportsQuery)->get();

        if ($dryRun) {
            $this->line("Would delete {$exports->count()} report exports.");
        } else {
            // Delete files from disk first.
            foreach ($exports as $export) {
                if ($export->file_path && $export->disk && Storage::disk($export->disk)->exists($export->file_path)) {
                    Storage::disk($export->disk)->delete($export->file_path);
                }
            }
            $exportsQuery->delete();
            $this->line("Deleted {$exports->count()} report exports (retention: {$retentionDays} days).");
        }

        $this->info('Cleanup complete.');

        return self::SUCCESS;
    }
}
