<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Export;

use ElgiborSolution\AdvancedReports\Engine\ReportEngine;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Events\ReportExportCompleted;
use ElgiborSolution\AdvancedReports\Events\ReportExportFailed;
use ElgiborSolution\AdvancedReports\Events\ReportExportStarted;
use ElgiborSolution\AdvancedReports\Export\DTO\ExportOptions;
use ElgiborSolution\AdvancedReports\Export\Jobs\ExportReportJob;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Queue;

/**
 * Manages synchronous and queued exports. The ExportManager wraps the
 * renderers and handles file persistence for queued exports.
 */
final class ExportManager
{
    public function __construct(
        protected ReportEngine $engine,
    ) {}

    /**
     * Synchronous export — renders and returns a Response (download/stream).
     */
    public function export(
        ReportResult $result,
        string $format,
        array $options = [],
        ?Authenticatable $user = null,
    ): mixed {
        $opts = ExportOptions::fromArray(array_merge($options, ['format' => $format]));
        $renderOptions = $opts->toArray();

        // ExportOptions fills page settings from global config. Preserve
        // whether the caller actually supplied an override so PDF renderers
        // can prefer the report's saved layout when it did not.
        if (! array_key_exists('paper', $options)) {
            unset($renderOptions['paper']);
        }
        if (! array_key_exists('orientation', $options)) {
            unset($renderOptions['orientation']);
        }

        return $this->engine->render($result, $format, $renderOptions);
    }

    /**
     * Queue an export for background processing.
     * Creates a ReportExport row and dispatches the job.
     */
    public function queue(
        Report $report,
        string $format,
        array $parameters = [],
        array $options = [],
        ?Authenticatable $user = null,
    ): ReportExport {
        $opts = ExportOptions::fromArray(array_merge($options, ['format' => $format, 'queued' => true]));

        $export = ReportExport::create([
            'report_id' => $report->id,
            'user_id' => $user?->getAuthIdentifier(),
            'format' => $format,
            'status' => ReportExport::STATUS_PENDING,
            'disk' => $opts->disk,
            'options' => $opts->toArray(),
            'tenant_id' => $report->tenant_id ?? null,
        ]);

        $job = new ExportReportJob($export, $parameters, $opts->toArray());

        Queue::connection($opts->queueConnection ?? config('queue.default'))
            ->onQueue($opts->queueName ?? 'default')
            ->push($job);

        return $export;
    }
}
