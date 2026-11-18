<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Jnbrnplbr\WorkflowPipelines\Console\DoctorCommand;
use Jnbrnplbr\WorkflowPipelines\Console\InstallCommand;
use Jnbrnplbr\WorkflowPipelines\Models\Workflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowPipeline;
use Jnbrnplbr\WorkflowPipelines\Policies\WorkflowPipelinePolicy;
use Jnbrnplbr\WorkflowPipelines\Policies\WorkflowPolicy;
use Jnbrnplbr\WorkflowPipelines\Services\ApprovableModelRegistry;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowAuthorizer;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowEngine;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowNotifier;
use Jnbrnplbr\WorkflowPipelines\Services\WorkflowResolver;

final class WorkflowPipelinesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow-pipelines.php', 'workflow-pipelines');

        $this->app->singleton(ApprovableModelRegistry::class);
        $this->app->singleton(WorkflowAuthorizer::class);
        $this->app->singleton(WorkflowEngine::class);
        $this->app->singleton(WorkflowNotifier::class);
        $this->app->singleton(WorkflowResolver::class);
    }

    public function boot(): void
    {
        Gate::policy(Workflow::class, WorkflowPolicy::class);
        Gate::policy(WorkflowPipeline::class, WorkflowPipelinePolicy::class);

        $configPath = __DIR__.'/../config/workflow-pipelines.php';
        $translationPath = __DIR__.'/../resources/lang';
        $viewPath = __DIR__.'/../resources/views';
        $routePath = __DIR__.'/../routes/web.php';
        $frontendPath = __DIR__.'/../resources/js/Pages/Vendor/WorkflowPipelines';
        $wayfinderPath = __DIR__.'/../resources/js/wayfinder/workflow-pipelines.ts';

        $this->publishes([
            $configPath => config_path('workflow-pipelines.php'),
        ], 'workflow-pipelines-config');

        if (is_dir($frontendPath)) {
            $this->publishes([
                $frontendPath => resource_path('js/Pages/'.trim((string) config('workflow-pipelines.inertia.pages_path', 'Vendor/WorkflowPipelines'), '/')),
            ], 'workflow-pipelines-views');
        }

        if (is_file($wayfinderPath)) {
            $this->publishes([
                $wayfinderPath => resource_path('js/wayfinder/workflow-pipelines.ts'),
            ], 'workflow-pipelines-views');
        }

        if (is_dir($translationPath)) {
            $this->loadTranslationsFrom($translationPath, 'workflow-pipelines');
            $this->publishes([
                $translationPath => lang_path('vendor/workflow-pipelines'),
            ], 'workflow-pipelines-translations');
        }

        if (is_dir($viewPath)) {
            $this->loadViewsFrom($viewPath, 'workflow-pipelines');
            $this->publishes([
                $viewPath => resource_path('views/vendor/workflow-pipelines'),
            ], 'workflow-pipelines-views');
        }

        if ((bool) config('workflow-pipelines.routes.enabled', true) && is_file($routePath)) {
            $this->loadRoutesFrom($routePath);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DoctorCommand::class]);
        }
    }
}
