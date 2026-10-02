<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\AdvancedReportsManager;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Http\Requests\StoreReportRequest;
use ElgiborSolution\AdvancedReports\Http\Requests\UpdateReportRequest;
use ElgiborSolution\AdvancedReports\Http\Resources\ReportResource;
use ElgiborSolution\AdvancedReports\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController
{
    public function __construct(protected AdvancedReportsManager $manager) {}

    /**
     * List reports visible to the current user.
     */
    public function index(): AnonymousResourceCollection
    {
        $reports = Report::query()
            ->active()
            ->forUser(auth()->id())
            ->latest()
            ->paginate(20);

        return ReportResource::collection($reports);
    }

    /**
     * Store a new report.
     */
    public function store(StoreReportRequest $request): ReportResource
    {
        $data = $request->validated();

        $report = Report::create([
            ...$data,
            'definition' => $data['definition'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return new ReportResource($report->load('runs'));
    }

    /**
     * Show a single report.
     */
    public function show(Report $report): ReportResource
    {
        $this->manager->authorize('view', $report, auth()->user());

        return new ReportResource($report->load(['runs', 'exports']));
    }

    /**
     * Update a report.
     */
    public function update(UpdateReportRequest $request, Report $report): ReportResource
    {
        $this->manager->authorize('edit', $report, auth()->user());

        $report->update($request->reportAttributes($report));

        return new ReportResource($report->fresh());
    }

    /**
     * Delete a report.
     */
    public function destroy(Report $report): JsonResponse
    {
        $this->manager->authorize('delete', $report, auth()->user());

        $report->delete();

        return response()->json(null, 204);
    }
}
