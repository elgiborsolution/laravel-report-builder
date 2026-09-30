<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\AdvancedReportsManager;
use ElgiborSolution\AdvancedReports\Http\Requests\RunReportRequest;
use ElgiborSolution\AdvancedReports\Http\Resources\ReportRunResource;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportRun;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportRunController
{
    public function __construct(protected AdvancedReportsManager $manager) {}

    /**
     * List runs for a report.
     */
    public function index(Report $report): JsonResponse
    {
        $this->manager->authorize('view', $report, auth()->user());

        $runs = $report->runs()->latest()->limit(50)->get();

        return response()->json(ReportRunResource::collection($runs));
    }

    /**
     * Execute a report run.
     *
     * Response shape depends on `format`:
     *  - html  -> Response with HTML body
     *  - json  -> array (rendered by JsonRenderer)
     *  - default -> ReportResult metadata + run record
     */
    public function run(RunReportRequest $request, Report $report): Response
    {
        $parameters = (array) $request->validated('parameters', []);
        $format = $request->validated('format');

        if ($format) {
            // Render directly (also creates a run record internally).
            $rendered = $this->manager->render($report, $format, $parameters);

            if ($format === 'json') {
                return response()->json($rendered);
            }

            return response($rendered);
        }

        // No format requested -> run and return metadata.
        $result = $this->manager->run($report, $parameters);
        $runId = $result->metadata['run_id'] ?? null;
        $run = $runId ? ReportRun::find($runId) : null;

        return response()->json([
            'run' => $run ? (new ReportRunResource($run))->toArray(request()) : null,
            'metadata' => $result->metadata,
            'columns' => $result->columns,
            'rows' => $result->rows->all(),
            'row_count' => $result->metadata['row_count'] ?? 0,
            'aggregates' => $result->aggregates,
            'groups' => $result->groups,
            'presentation_rows' => $result->presentationRowsWithDetails(),
            'drilldowns' => array_map(fn ($dd) => [
                'trigger' => $dd['trigger'] ?? null,
                'type' => $dd['type'] ?? null,
                'target' => $dd['target'] ?? null,
                '_meta' => $dd['_meta'] ?? null,
            ], $result->drilldowns),
        ]);
    }

    /**
     * Show a single run record.
     */
    public function show(ReportRun $run): ReportRunResource
    {
        $this->manager->authorize('view', $run->report, auth()->user());

        return new ReportRunResource($run);
    }
}
