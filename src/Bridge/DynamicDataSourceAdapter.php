<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Bridge;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Sources\ReportField;
use ElgiborSolution\AdvancedReports\Sources\ReportParameter;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adapts a DataSource (from laravel-data-sources) into a ReportSource
 * consumable by the report engine. This is the bridge layer that allows
 * dynamically-registered data sources to participate in report definitions.
 */
final class DynamicDataSourceAdapter implements ReportSourceContract
{
    /** @var Collection<string, ReportField>|null */
    private ?Collection $resolvedFields = null;

    /** @var Collection<string, ReportParameter>|null */
    private ?Collection $resolvedParameters = null;

    public function __construct(
        private readonly DataSource $dataSource,
    ) {}

    public function key(): string
    {
        return "dynamic:{$this->dataSource->id}";
    }

    public function label(): string
    {
        return $this->dataSource->name;
    }

    public function description(): string
    {
        if ($this->dataSource->use_custom_query) {
            return "Dynamic source from custom query ({$this->dataSource->name})";
        }

        return "Dynamic source from table `{$this->dataSource->table_name}` ({$this->dataSource->name})";
    }

    /**
     * @return Collection<string, ReportField>
     */
    public function fields(): Collection
    {
        if ($this->resolvedFields !== null) {
            return $this->resolvedFields;
        }

        $columns = (array) ($this->dataSource->columns ?? []);

        $this->resolvedFields = collect($columns)
            ->mapWithKeys(function (string $column) {
                $type = $this->inferColumnType($column);

                $field = new ReportField(
                    key: $column,
                    label: $this->humanizeColumn($column),
                    type: $type,
                    sortable: true,
                    filterable: true,
                    aggregatable: in_array($type, ['integer', 'decimal'], true),
                    hidden: false,
                    selectExpr: null,
                    format: null,
                    description: null,
                );

                return [$column => $field];
            });

        return $this->resolvedFields;
    }

    public function field(string $key): ?ReportField
    {
        return $this->fields()->get($key);
    }

    /**
     * @return Collection<string, ReportParameter>
     */
    public function parameters(): Collection
    {
        if ($this->resolvedParameters !== null) {
            return $this->resolvedParameters;
        }

        $this->resolvedParameters = $this->dataSource->parameters()
            ->get()
            ->mapWithKeys(function ($param) {
                $parameter = new ReportParameter(
                    name: $param->param_name,
                    type: $this->mapParameterType($param->param_type),
                    required: (bool) $param->is_required,
                    default: $param->param_default_value,
                    allowedValues: null,
                    description: null,
                );

                return [$param->param_name => $parameter];
            });

        return $this->resolvedParameters;
    }

    /**
     * Build the base query builder for this data source.
     *
     * @param  array<string,mixed>  $parameters
     */
    public function query(array $parameters = []): Builder
    {
        $connection = $this->executionConnectionName();

        if ($this->dataSource->use_custom_query && $this->dataSource->custom_query) {
            $query = $this->substituteParameters($this->dataSource->custom_query, $parameters);

            return DB::connection($connection)->query()->fromSub($query, 'dynamic_source');
        }

        return DB::connection($connection)->table($this->resolvedTableName());
    }

    /**
     * Get the underlying DataSource model.
     */
    public function getDataSource(): DataSource
    {
        return $this->dataSource;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Attempt to infer the column type from database schema metadata.
     * Falls back to 'string' if the table or column doesn't exist or schema
     * introspection fails (e.g., custom queries).
     */
    private function inferColumnType(string $column): string
    {
        if ($this->dataSource->use_custom_query || ! $this->dataSource->table_name) {
            return 'string';
        }

        try {
            $tableName = $this->resolvedTableName();

            if (! Schema::connection($this->executionConnectionName())->hasTable($tableName)) {
                return 'string';
            }

            $columnType = Schema::connection($this->executionConnectionName())
                ->getColumnType($tableName, $column);

            return $this->mapDatabaseType($columnType);
        } catch (\Throwable) {
            return 'string';
        }
    }

    /**
     * Convert a physical table name stored by Data Sources into the logical
     * name expected by Laravel's connection-aware Schema and query builders.
     *
     * Data Sources may persist a table name obtained from database metadata,
     * including the connection's table prefix. Laravel applies that prefix
     * itself, so passing the physical name to Schema::connection() or table()
     * would apply it a second time (for example, es_es_direct_invoice).
     */
    private function resolvedTableName(): string
    {
        $tableName = trim((string) $this->dataSource->table_name);
        $prefix = DB::connection($this->executionConnectionName())->getTablePrefix();

        if ($prefix === '' || ! str_starts_with($tableName, $prefix)) {
            return $tableName;
        }

        return substr($tableName, strlen($prefix));
    }

    /**
     * Map a database column type to a ReportField type.
     */
    private function mapDatabaseType(string $dbType): string
    {
        return match (true) {
            in_array($dbType, ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => 'integer',
            in_array($dbType, ['decimal', 'float', 'double', 'numeric', 'real'], true) => 'decimal',
            in_array($dbType, ['boolean', 'bool'], true) => 'boolean',
            in_array($dbType, ['date'], true) => 'date',
            in_array($dbType, ['datetime', 'timestamp', 'datetimetz'], true) => 'datetime',
            in_array($dbType, ['json', 'jsonb'], true) => 'json',
            default => 'string',
        };
    }

    /**
     * Map DataSourceParameter type to ReportParameter type.
     */
    private function mapParameterType(?string $paramType): string
    {
        if ($paramType === null) {
            return 'string';
        }

        return match (strtolower($paramType)) {
            'int', 'integer' => 'integer',
            'float', 'double', 'decimal' => 'decimal',
            'bool', 'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'datetime',
            default => 'string',
        };
    }

    /**
     * Create a human-readable label from a column name.
     */
    private function humanizeColumn(string $column): string
    {
        return Str::of($column)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    /**
     * Substitute parameter placeholders in a custom SQL query.
     * Parameters are referenced as :param_name in the query.
     */
    private function substituteParameters(string $sql, array $parameters): string
    {
        foreach ($parameters as $name => $value) {
            $escapedValue = is_numeric($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
            $sql = str_replace(":{$name}", $escapedValue, $sql);
        }

        return $sql;
    }

    /**
     * Resolve the connection on which source *data* runs, independently from
     * the central connection used to load DataSource metadata.
     *
     * A central source must remain central after X-Tenant has initialized the
     * tenant. A tenant source intentionally follows the connection made
     * current by tenancy initialization; no default connection is changed.
     */
    private function executionConnectionName(): string
    {
        $scope = strtolower(trim((string) ($this->dataSource->database_scope ?? 'central')));

        if ($scope === 'tenant') {
            return DB::getDefaultConnection();
        }

        return DatabaseConnection::configuredName();
    }
}
