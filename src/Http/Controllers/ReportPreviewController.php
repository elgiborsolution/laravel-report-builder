<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\AdvancedReportsManager;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preview endpoints for the report designer.
 *
 * Runs reports with a hard cap of 50 rows so the frontend can show
 * a quick preview without executing full (potentially expensive) queries.
 */
class ReportPreviewController
{
    private const PREVIEW_LIMIT = 50;

    public function __construct(
        protected AdvancedReportsManager $manager,
    ) {}

    /**
     * Preview an existing (persisted) report.
     */
    public function preview(Request $request, Report $report): JsonResponse
    {
        $parameters = (array) $request->input('parameters', []);

        $definition = $this->buildLimitedDefinition($report->definition ?? [], self::PREVIEW_LIMIT);

        try {
            $result = $this->manager->engine()->run(
                report: $report,
                definition: $definition,
                parameters: $parameters,
                user: $request->user(),
            );

            return response()->json([
                'columns' => $result->columns,
                'rows' => $result->rows instanceof \Illuminate\Support\LazyCollection
                    ? $result->rows->take(self::PREVIEW_LIMIT)->values()->all()
                    : $result->rows->take(self::PREVIEW_LIMIT)->values()->all(),
                'aggregates' => $result->aggregates,
                'groups' => $result->groups ?? [],
                'metadata' => [
                    'row_count' => min($result->metadata['row_count'] ?? 0, self::PREVIEW_LIMIT),
                    'truncated' => ($result->metadata['row_count'] ?? 0) > self::PREVIEW_LIMIT,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Preview failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Preview from an inline definition (not yet persisted).
     *
     * Allows the designer to validate the report output before saving.
     */
    public function inlinePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'definition' => ['required', 'array'],
            'definition.data_source' => ['required', 'string'],
            'parameters' => ['sometimes', 'array'],
        ]);

        $definitionData = (array) $validated['definition'];
        $parameters = (array) ($validated['parameters'] ?? []);

        // Build a temporary Report model (not persisted) for the engine.
        $tempReport = new Report([
            'name' => $definitionData['name'] ?? 'Inline Preview',
            'code' => 'preview_' . uniqid(),
            'data_source' => $definitionData['data_source'] ?? $definitionData['dataSource'] ?? '',
            'definition' => $definitionData,
            'is_active' => true,
            'is_public' => false,
        ]);

        $definition = $this->buildLimitedDefinition($definitionData, self::PREVIEW_LIMIT);

        try {
            $result = $this->manager->engine()->run(
                report: $tempReport,
                definition: $definition,
                parameters: $parameters,
                user: $request->user(),
            );

            return response()->json([
                'columns' => $result->columns,
                'rows' => $result->rows instanceof \Illuminate\Support\LazyCollection
                    ? $result->rows->take(self::PREVIEW_LIMIT)->values()->all()
                    : $result->rows->take(self::PREVIEW_LIMIT)->values()->all(),
                'aggregates' => $result->aggregates,
                'groups' => $result->groups ?? [],
                'metadata' => [
                    'row_count' => min($result->metadata['row_count'] ?? 0, self::PREVIEW_LIMIT),
                    'truncated' => ($result->metadata['row_count'] ?? 0) > self::PREVIEW_LIMIT,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Preview failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Build a ReportDefinition with a forced row limit for preview.
     */
    private function buildLimitedDefinition(array $definitionData, int $limit): ReportDefinition
    {
        // Inject a meta limit so the QueryBuilderEngine caps rows.
        $definitionData['meta'] = array_merge(
            $definitionData['meta'] ?? [],
            ['row_limit' => $limit]
        );

        return ReportDefinition::fromArray($definitionData);
    }
}
