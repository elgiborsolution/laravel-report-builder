<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Bridge;

use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\DatabaseConnection;
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
        $dataSource = $this->metadataDataSources()->findOrFail($dataSourceId);
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
     * Ensure that a persisted dynamic source is present in this process's
     * registry.
     *
     * SourceRegistry is an in-memory singleton. A connection can therefore
     * exist in central metadata while a fresh/long-lived process has not yet
     * loaded it. Rehydrate only persisted `dynamic:{id}` keys; ordinary
     * developer-registered source keys remain the registry's responsibility.
     */
    public function ensureRegistered(string $key): bool
    {
        if ($this->registry->has($key)) {
            return true;
        }

        if (! str_starts_with($key, 'dynamic:')) {
            return false;
        }

        $connection = ConnectedSource::with('dataSource.parameters')
            ->where('source_key', $key)
            ->first();

        if ($connection?->dataSource === null) {
            return false;
        }

        $adapter = $this->createAdapter($connection->dataSource);
        $this->registry->register($adapter, $adapter->key());

        return true;
    }

    /**
     * Return DataSource metadata from Builder's configured central connection.
     *
     * Do not use DataSource::query() here: tenant initialization can change
     * Laravel's default connection for the request. The Data Sources package's
     * DatabaseConnection is its established metadata/central connection
     * mechanism and deliberately leaves the global default untouched.
     */
    public function metadataDataSources(): \Illuminate\Database\Eloquent\Builder
    {
        return DataSource::on(DatabaseConnection::configuredName());
    }

    /**
     * Check central Builder metadata for a DataSource ID.
     *
     * This is intentionally owned by the bridge so HTTP validation cannot
     * accidentally fall back to Laravel's tenant-default validation query.
     */
    public function hasMetadataDataSource(int $dataSourceId): bool
    {
        return $this->metadataDataSources()->whereKey($dataSourceId)->exists();
    }

    /**
     * Return central DataSource metadata not yet registered with this bridge.
     *
     * Kept here so every bridge consumer shares the same connection rule.
     */
    public function listAvailable(): Collection
    {
        $connectedIds = ConnectedSource::query()->pluck('data_source_id')->all();

        return $this->metadataDataSources()
            ->when($connectedIds !== [], fn ($query) => $query->whereNotIn('id', $connectedIds))
            ->get();
    }

    /**
     * Boot all persisted connections into the live source registry.
     * Called once during application bootstrapping.
     */
    public function bootConnected(): void
    {
        $connections = ConnectedSource::with('dataSource.parameters')->get();

        foreach ($connections as $connection) {
            if ($connection->dataSource === null) {
                // DataSource was deleted externally; clean up stale record.
                $connection->delete();

                continue;
            }

            $this->ensureRegistered($connection->source_key);
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
