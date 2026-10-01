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
            $this->manager->validateDefinition($definition, $parameters);
            $result = $this->manager->engine()->run(
                report: $report,
                definition: $definition,
                parameters: $parameters,
                user: $request->user(),
            );

            $rows = $result->rows->take(self::PREVIEW_LIMIT)->values()->all();

            return response()->json([
                'columns' => $result->columns,
                'rows' => $rows,
                'aggregates' => $result->aggregates,
                'groups' => $result->groups ?? [],
                'presentation_rows' => $this->limitPresentationRows(
                    $result->presentationRows,
                    self::PREVIEW_LIMIT,
                    count($rows),
                    $result->definition->groups !== [],
                ),
                'metadata' => [
                    'row_count' => $result->metadata['row_count'] ?? $result->rows->count(),
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

        // Nested validation only returns the validated data_source key; retain
        // the full definition (groups, aggregates, columns, formulas, etc.).
        $definitionData = (array) $request->input('definition', []);
        if (trim((string) ($definitionData['name'] ?? '')) === '') {
            $definitionData['name'] = 'Inline Preview';
        }
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
            $this->manager->validateDefinition($definition, $parameters);
            $result = $this->manager->engine()->run(
                report: $tempReport,
                definition: $definition,
                parameters: $parameters,
                user: $request->user(),
            );

            $rows = $result->rows->take(self::PREVIEW_LIMIT)->values()->all();

            return response()->json([
                'columns' => $result->columns,
                'rows' => $rows,
                'aggregates' => $result->aggregates,
                'groups' => $result->groups ?? [],
                'presentation_rows' => $this->limitPresentationRows(
                    $result->presentationRows,
                    self::PREVIEW_LIMIT,
                    count($rows),
                    $result->definition->groups !== [],
                ),
                'metadata' => [
                    'row_count' => $result->metadata['row_count'] ?? $result->rows->count(),
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

    /**
     * Preview detail rows are capped, while group summaries and grand totals
     * still describe the full filtered result. Keep only presentation events
     * whose group begins in the visible detail window, plus the grand total.
     *
     * @param  array<int,array<string,mixed>>  $presentationRows
     * @return array<int,array<string,mixed>>
     */
    private function limitPresentationRows(
        array $presentationRows,
        int $limit,
        int $visibleDetailCount,
        bool $hasGroups,
    ): array
    {
        if (! $hasGroups) {
            $details = [];
            for ($rowIndex = 0; $rowIndex < min($visibleDetailCount, $limit); $rowIndex++) {
                $details[] = ['type' => 'detail', 'row_index' => $rowIndex];
            }

            return array_merge(
                $details,
                array_values(array_filter(
                    $presentationRows,
                    static fn (array $row) => in_array($row['type'] ?? null, ['group_subtotal', 'grand_total'], true),
                )),
            );
        }

        return array_values(array_filter($presentationRows, static function (array $row) use ($limit): bool {
            return match ($row['type'] ?? null) {
                'detail' => ($row['row_index'] ?? PHP_INT_MAX) < $limit,
                'group_header', 'group_subtotal' => ($row['detail_start'] ?? PHP_INT_MAX) < $limit,
                'grand_total' => true,
                default => false,
            };
        }));
    }
}
