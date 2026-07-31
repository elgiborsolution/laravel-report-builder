<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\AdvancedReportsManager;
use ElgiborSolution\AdvancedReports\Http\Requests\ExportReportRequest;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController
{
    public function __construct(protected AdvancedReportsManager $manager) {}

    /**
     * Export synchronously or queue the export.
     */
    public function export(ExportReportRequest $request, Report $report): Response
    {
        $data = $request->validated();
        $format = (string) $data['format'];
        $parameters = (array) ($data['parameters'] ?? []);
        $queued = (bool) ($data['queued'] ?? config('advanced-reports.export.queue.enabled', false));

        $options = collect($data)
            ->except(['format', 'parameters', 'queued'])
            ->toArray();

        if ($queued) {
            $export = $this->manager->queueExport($report, $format, $parameters, $options);

            return response()->json([
                'message' => 'Export queued.',
                'export_uuid' => $export->uuid,
                'status' => $export->status,
            ], 202);
        }

        return $this->manager->export($report, $format, $parameters, $options);
    }

    /**
     * Show a single export record (polling endpoint for queued exports).
     */
    public function show(ReportExport $export): JsonResponse
    {
        $this->manager->authorize('view', $export->report, auth()->user());

        return response()->json([
            'uuid' => $export->uuid,
            'status' => $export->status,
            'format' => $export->format,
            'file_path' => $export->file_path,
            'disk' => $export->disk,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'error_message' => $export->error_message,
        ]);
    }

    /**
     * Download a completed export's file.
     */
    public function download(ReportExport $export): Response
    {
        $this->manager->authorize('view', $export->report, auth()->user());

        if ($export->status !== ReportExport::STATUS_COMPLETED || ! $export->file_path) {
            return response()->json(['message' => 'Export file is not ready.'], 409);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($export->disk);

        if (! $disk->exists($export->file_path)) {
            return response()->json(['message' => 'Export file not found on disk.'], 404);
        }

        return $disk->download($export->file_path);
    }
}
