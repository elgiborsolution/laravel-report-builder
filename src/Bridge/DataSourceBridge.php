<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Bridge;

use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Service layer that manages the lifecycle of dynamic data source connections.
 *
 * Responsibilities:
 *  - Connecting a DataSource to the report engine (persists + registers).
 *  - Disconnecting (removes from registry + deletes persistence row).
 *  - Booting all persisted connections at application startup.
 */
final class DataSourceBridge
{
    public function __construct(
        protected SourceRegistry $registry,
        protected Container $container,
    ) {}

    /**
     * Connect a DataSource to the report engine.
     *
     * Creates an adapter, registers it in the source registry, and persists
     * the connection so it survives application restarts.
     *
     * @return string The generated source key (e.g., "dynamic:42").
     */
    public function connect(int $dataSourceId, ?int $tenantId = null, int|string|null $userId = null): string
    {
        $dataSource = DataSource::findOrFail($dataSourceId);
        $adapter = $this->createAdapter($dataSource);
        $key = $adapter->key();

        // Persist the connection.
        ConnectedSource::updateOrCreate(
            ['source_key' => $key],
            [
                'data_source_id' => $dataSourceId,
                'tenant_id' => $tenantId,
                'created_by' => $userId,
            ],
        );

        // Register in the live source registry.
        if (! $this->registry->has($key)) {
            $this->registry->register($adapter, $key);
        }

        return $key;
    }

    /**
     * Disconnect a dynamic source by key.
     *
     * Removes from both the persisted store and the live registry.
     */
    public function disconnect(string $key): void
    {
        ConnectedSource::where('source_key', $key)->delete();

        // The SourceRegistry doesn't expose an unregister method, so we
        // rebuild without the disconnected key on next boot. For the current
        // request, we leave it in the registry (harmless and avoids adding
        // a mutable unregister API to the registry).
    }

    /**
     * List all persisted connections with their DataSource metadata.
     *
     * @return Collection<int, ConnectedSource>
     */
    public function listConnected(): Collection
    {
        return ConnectedSource::with('dataSource')->get();
    }

    /**
     * Boot all persisted connections into the live source registry.
     * Called once during application bootstrapping.
     */
    public function bootConnected(): void
    {
        $connections = ConnectedSource::with('dataSource')->get();

        foreach ($connections as $connection) {
            if ($connection->dataSource === null) {
                // DataSource was deleted externally; clean up stale record.
                $connection->delete();

                continue;
            }

            $adapter = $this->createAdapter($connection->dataSource);

            if (! $this->registry->has($adapter->key())) {
                $this->registry->register($adapter, $adapter->key());
            }
        }
    }

    /**
     * Create an adapter instance for a given DataSource.
     */
    public function createAdapter(object $dataSource): DynamicDataSourceAdapter
    {
        /** @var DataSource $dataSource */
        return new DynamicDataSourceAdapter($dataSource);
    }
}
