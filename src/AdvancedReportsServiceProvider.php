<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports;

use ElgiborSolution\AdvancedReports\Console\CleanupReportRunsCommand;
use ElgiborSolution\AdvancedReports\Console\InstallCommand;
use ElgiborSolution\AdvancedReports\Console\MakeReportSourceCommand;
use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Export\ExportManager;
use ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer;
use ElgiborSolution\AdvancedReports\Renderers\JsonRenderer;
use ElgiborSolution\AdvancedReports\Security\ReportPolicy;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\SafeExpressionEvaluator;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AdvancedReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/advanced-reports.php', 'advanced-reports');

        // Core singletons
        $this->app->singleton(SourceRegistry::class, fn ($app) => new SourceRegistry($app));
        $this->app->singleton(AdvancedReportsManager::class);

        // Bindings for contracts
        $this->app->bind(ExpressionEvaluator::class, SafeExpressionEvaluator::class);

        $this->app->bind(Formatter::class, fn ($app) => new Formatter(
            (array) ($app['config']->get('advanced-reports.formats', [])),
        ));

        // Support classes (resolvable from the container).
        $this->app->bind(\ElgiborSolution\AdvancedReports\Support\ValueResolver::class);
        $this->app->bind(\ElgiborSolution\AdvancedReports\Support\ReportUrlGenerator::class);

        // Resolvers (each tiny and stateless enough to share).
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\ParameterResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\FilterResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\SortResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\GroupResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\AggregateResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\FormulaResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\DrilldownResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\SubreportResolver::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\QueryBuilderEngine::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Engine\ReportEngine::class);

        // Definition services
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Definitions\ReportDefinitionValidator::class);
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Definitions\ReportSchemaGenerator::class);

        // Security
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Security\FieldPermissionChecker::class);

        // Bridge (dynamic data source integration)
        $this->app->singleton(\ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge::class);

        // Export manager
        $this->app->singleton(ExportManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerConsoleCommands();
            $this->offerPublishing();
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'advanced-reports');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPolicies();
        $this->registerRoutes();

        // Boot persisted dynamic data source connections into the registry.
        $this->bootDynamicSources();

        if ($this->app->bound(HttpKernel::class)) {
            // hook reserved for future middleware (tenant resolution, etc.)
        }
    }

    protected function registerConsoleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            MakeReportSourceCommand::class,
            CleanupReportRunsCommand::class,
        ]);
    }

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/advanced-reports.php' => config_path('advanced-reports.php'),
        ], 'advanced-reports-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'advanced-reports-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/advanced-reports'),
        ], 'advanced-reports-views');

        $this->publishes([
            __DIR__.'/../config/advanced-reports.php',
            __DIR__.'/../database/migrations',
            __DIR__.'/../resources/views',
        ], 'advanced-reports');
    }

    protected function registerPolicies(): void
    {
        Gate::policy(
            \ElgiborSolution\AdvancedReports\Models\Report::class,
            ReportPolicy::class
        );
    }

    protected function registerRoutes(): void
    {
        $config = $this->app['config']->get('advanced-reports.routes', []);

        if (($config['api']['enabled'] ?? true) && ($config['enabled'] ?? true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if (($config['web']['enabled'] ?? false) && ($config['enabled'] ?? true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    /**
     * Boot all persisted dynamic data source connections into the source registry.
     * Wrapped in a try-catch to prevent migration failures from crashing the app.
     */
    protected function bootDynamicSources(): void
    {
        try {
            /** @var \ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge $bridge */
            $bridge = $this->app->make(\ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge::class);
            $bridge->bootConnected();
        } catch (\Throwable) {
            // Silently ignore — table may not exist yet (pre-migration),
            // or the data-sources package is not installed.
        }
    }
}
