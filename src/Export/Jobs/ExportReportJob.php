<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Export\Jobs;

use ElgiborSolution\AdvancedReports\AdvancedReportsManager;
use ElgiborSolution\AdvancedReports\Events\ReportExportCompleted;
use ElgiborSolution\AdvancedReports\Events\ReportExportFailed;
use ElgiborSolution\AdvancedReports\Events\ReportExportStarted;
use ElgiborSolution\AdvancedReports\Exceptions\ExportFailedException;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Queued job that runs a report and writes the rendered output to disk.
 */
class ExportReportJob implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 3;
    public int $backoff = 30;

    /**
     * @param  array<string,mixed>  $parameters
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public ReportExport $export,
        public array $parameters,
        public array $options,
    ) {}

    public function handle(Container $container): void
    {
        $report = $this->export->report()->first();
        if (! $report) {
            throw new ExportFailedException('Report not found for export.');
        }

        event(new ReportExportStarted($report, $this->export));
        $this->export->markProcessing();

        try {
            $manager = $container->make(AdvancedReportsManager::class);
            $result = $manager->run($report, $this->parameters);

            $format = $this->export->format;
            $disk = $this->options['disk'] ?? config('advanced-reports.export.disk');
            $path = $this->options['path'] ?? config('advanced-reports.export.path');
            $filename = $this->buildFilename($report, $format);

            $fullPath = rtrim($path, '/').'/'.$filename;
            $rendered = $manager->render($report, $format, $this->parameters);

            $storage = Storage::disk($disk);

            // Handle different rendered outputs
            $this->writeToFile($storage, $fullPath, $rendered, $format, $filename);

            $this->export->markCompleted($fullPath, $disk);
            event(new ReportExportCompleted($report, $this->export));
        } catch (\Throwable $e) {
            $this->export->markFailed($e->getMessage());
            event(new ReportExportFailed($report, $this->export, $e));

            throw $e;
        }
    }

    protected function buildFilename(Report $report, string $format): string
    {
        $slug = Str::slug($report->name);
        $ext = match ($format) {
            'pdf' => 'pdf',
            'xlsx' => 'xlsx',
            'csv' => 'csv',
            'json' => 'json',
            default => $format,
        };

        return $slug.'_'.now()->format('Y-m-d_His').'.'.$ext;
    }

    /**
     * Write the rendered output to the configured disk.
     */
    protected function writeToFile(
        Filesystem $storage,
        string $path,
        mixed $rendered,
        string $format,
        string $filename,
    ): void {
        // For streamed responses (CSV/PDF), we need to capture the content.
        if ($rendered instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            ob_start();
            $rendered->sendContent();
            $content = ob_get_clean();
            $storage->put($path, $content);
        } elseif ($rendered instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $storage->put($path, $rendered->getFile()->getContent());
        } elseif ($rendered instanceof \Symfony\Component\HttpFoundation\Response) {
            $storage->put($path, $rendered->getContent());
        } elseif (is_string($rendered)) {
            $storage->put($path, $rendered);
        } elseif (is_array($rendered)) {
            $storage->put($path, json_encode($rendered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            // Browsershot returns raw PDF binary
            $storage->put($path, $rendered);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $report = $this->export->report()->first();
        $this->export->markFailed($exception->getMessage());
        if ($report) {
            event(new ReportExportFailed($report, $this->export, $exception));
        }
    }
}
