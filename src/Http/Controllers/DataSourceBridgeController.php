<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\Bridge\ConnectedSource;
use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP endpoints for managing the bridge between laravel-data-sources
 * and the advanced-reports engine.
 */
class DataSourceBridgeController
{
    public function __construct(
        protected DataSourceBridge $bridge,
    ) {}

    /**
     * Connect a DataSource to the report engine.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data_source_id' => ['required', 'integer', 'exists:data_sources,id'],
        ]);

        $dataSourceId = (int) $validated['data_source_id'];

        // Prevent duplicate connections.
        $existing = ConnectedSource::where('data_source_id', $dataSourceId)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Data source is already connected.',
                'source_key' => $existing->source_key,
            ], 409);
        }

        $key = $this->bridge->connect(
            dataSourceId: $dataSourceId,
            tenantId: $request->input('tenant_id') ? (int) $request->input('tenant_id') : null,
            userId: $request->user()?->getAuthIdentifier() ? (int) $request->user()->getAuthIdentifier() : null,
        );

        return response()->json([
            'message' => 'Data source connected successfully.',
            'source_key' => $key,
        ], 201);
    }

    /**
     * Disconnect a dynamic source from the report engine.
     */
    public function disconnect(string $key): JsonResponse
    {
        $connection = ConnectedSource::where('source_key', $key)->first();

        if (! $connection) {
            return response()->json([
                'message' => "Connected source [{$key}] not found.",
            ], 404);
        }

        $this->bridge->disconnect($key);

        return response()->json([
            'message' => 'Data source disconnected successfully.',
        ]);
    }

    /**
     * List all connected dynamic sources.
     */
    public function listDynamic(): JsonResponse
    {
        $connected = $this->bridge->listConnected();

        $data = $connected->map(function (ConnectedSource $connection) {
            return [
                'id' => $connection->id,
                'source_key' => $connection->source_key,
                'data_source_id' => $connection->data_source_id,
                'data_source_name' => $connection->dataSource?->name,
                'table_name' => $connection->dataSource?->table_name,
                'use_custom_query' => (bool) ($connection->dataSource?->use_custom_query ?? false),
                'tenant_id' => $connection->tenant_id,
                'created_by' => $connection->created_by,
                'connected_at' => $connection->created_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data->values()->all()]);
    }

    /**
     * List DataSources that are NOT yet connected to the report engine.
     */
    public function listAvailable(): JsonResponse
    {
        $connectedIds = ConnectedSource::pluck('data_source_id')->all();

        $available = DataSource::query()
            ->when(count($connectedIds) > 0, fn ($q) => $q->whereNotIn('id', $connectedIds))
            ->get()
            ->map(function (DataSource $ds) {
                return [
                    'id' => $ds->id,
                    'name' => $ds->name,
                    'table_name' => $ds->table_name,
                    'use_custom_query' => (bool) $ds->use_custom_query,
                    'column_count' => is_array($ds->columns) ? count($ds->columns) : 0,
                    'parameter_count' => $ds->parameters()->count(),
                ];
            });

        return response()->json(['data' => $available->values()->all()]);
    }
}
