<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\Definitions\ReportSchemaGenerator;
use ElgiborSolution\AdvancedReports\Http\Resources\ReportSourceResource;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportSourceController
{
    public function __construct(
        protected SourceRegistry $sources,
        protected ReportSchemaGenerator $schema,
    ) {}

    /**
     * List all registered sources (lightweight metadata).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->schema->allSources(),
        ]);
    }

    /**
     * Full schema for one source (fields, parameters, operators, formats).
     * This is what the frontend designer consumes.
     */
    public function schema(string $source): JsonResponse
    {
        if (! $this->sources->has($source)) {
            return response()->json(['message' => "Source [{$source}] is not registered."], 404);
        }

        return response()->json($this->schema->forSource($source));
    }
}
