<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinitionValidator;
use ElgiborSolution\AdvancedReports\Engine\ReportEngine;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\DefinitionInvalidException;
use ElgiborSolution\AdvancedReports\Exceptions\PermissionDeniedException;
use ElgiborSolution\AdvancedReports\Exceptions\ReportNotFoundException;
use ElgiborSolution\AdvancedReports\Exceptions\SourceNotRegisteredException;
use ElgiborSolution\AdvancedReports\Export\ExportManager;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;
use ElgiborSolution\AdvancedReports\Security\FieldPermissionChecker;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class AdvancedReportsManager
{
    public function __construct(
        protected Container $container,
        protected SourceRegistry $sources,
        protected ReportEngine $engine,
        protected ExportManager $exports,
        protected ReportDefinitionValidator $validator,
        protected FieldPermissionChecker $fields,
        protected DataSourceBridge $dynamicSources,
    ) {}

    /**
     * Expose the engine for use by ExportManager and tests.
     */
    public function engine(): ReportEngine
    {
        return $this->engine;
    }

    /**
     * Access the source registry directly.
     */
    public function sources(): SourceRegistry
    {
        return $this->sources;
    }

    /**
     * Register a report source (fluent alias of sources()->register()).
     *
     * @param  class-string<ReportSourceContract>|ReportSourceContract  $source
     */
    public function registerSource(string|ReportSourceContract $source, ?string $key = null): SourceRegistry
    {
        return $this->sources->register($source, $key);
    }

    public function source(string $key): ?ReportSourceContract
    {
        return $this->sources->get($key);
    }

    /** Validate a definition before execution, including inline previews. */
    public function validateDefinition(ReportDefinition $definition, array $parameters = []): void
    {
        $this->ensureSourceRegistered($definition->dataSource);
        $this->validator->validate($definition, $parameters);
    }

    /**
     * Build a ReportDefinition DTO from array data.
     */
    public function definition(array|ReportDefinition $definition): ReportDefinition
    {
        return $definition instanceof ReportDefinition
            ? $definition
            : ReportDefinition::fromArray($definition);
    }

    /**
     * Resolve a report by code (string) or model instance, applying scopes.
     */
    public function resolveReport(string|Report $report): Report
    {
        if ($report instanceof Report) {
            return $report;
        }

        /** @var Report|null $model */
        $model = Report::query()->where('code', $report)->first();

        if (! $model) {
            throw ReportNotFoundException::forCode($report);
        }

        return $model;
    }

    /**
     * Run a report and return a ReportResult.
     *
     * @throws PermissionDeniedException
     * @throws DefinitionInvalidException
     * @throws SourceNotRegisteredException
     */
    public function run(string|Report $report, array $parameters = [], ?Authenticatable $user = null): ReportResult
    {
        $report = $this->resolveReport($report);
        $user ??= Auth::user();

        $this->authorize('view', $report, $user);

        $definition = $this->definition($report->definition);

        $this->validateDefinition($definition, $parameters);

        return $this->engine->run($report, $definition, $parameters, $user);
    }

    /**
     * Render a report in the given format (html, json, csv, pdf, xlsx).
     */
    public function render(string|Report $report, string $format = 'html', array $parameters = [], ?Authenticatable $user = null): mixed
    {
        $result = $this->run($report, $parameters, $user);

        return $this->engine->render($result, $format);
    }

    /**
     * Synchronously export a report (returns a Response).
     */
    public function export(string|Report $report, string $format = 'pdf', array $parameters = [], array $options = [], ?Authenticatable $user = null): mixed
    {
        $report = $this->resolveReport($report);
        $user ??= Auth::user();

        $this->authorize('export', $report, $user);

        $result = $this->run($report, $parameters, $user);

        return $this->exports->export($result, $format, $options, $user);
    }

    /**
     * Queue an export for background processing.
     */
    public function queueExport(string|Report $report, string $format = 'pdf', array $parameters = [], array $options = [], ?Authenticatable $user = null): ReportExport
    {
        $report = $this->resolveReport($report);
        $user ??= Auth::user();

        $this->authorize('export', $report, $user);

        return $this->exports->queue($report, $format, $parameters, $options, $user);
    }

    /**
     * Field permission helper.
     */
    public function fields(): FieldPermissionChecker
    {
        return $this->fields;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    protected function ensureSourceRegistered(string $sourceKey): void
    {
        if (! $this->sources->has($sourceKey) && ! $this->dynamicSources->ensureRegistered($sourceKey)) {
            throw SourceNotRegisteredException::forKey($sourceKey);
        }
    }

    /**
     * Authorize a user against a report. Throws on denial.
     * Public so controllers can use it for route-level checks too.
     */
    public function authorize(string $ability, Report $report, ?Authenticatable $user): void
    {
        if (! $this->shouldEnforcePermissions()) {
            return;
        }

        if (Gate::forUser($user)->denies($ability, $report)) {
            throw new PermissionDeniedException($ability, $report);
        }
    }

    protected function shouldEnforcePermissions(): bool
    {
        return (bool) config('advanced-reports.security.enforce_permissions', true);
    }

    /**
     * Apply global query scope used by models (e.g. tenant scoping).
     */
    public function scopeQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @return Collection<int, array-key>
     */
    public function listRegisteredSources(): Collection
    {
        return $this->sources->keys();
    }
}
