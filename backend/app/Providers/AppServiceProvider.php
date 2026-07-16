<?php

namespace App\Providers;

use App\Observers\WorkflowObserver;
use App\Services\WorkflowEngine;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request / queue job. `scoped` (not `singleton`)
        // so queue workers and Octane reset it between jobs/requests.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fire user-defined workflows (project-stack.md §11) on the `created`
        // event of every model that backs a "*.created" trigger.
        foreach (WorkflowEngine::TRIGGERS as $meta) {
            $meta['model']::observe(WorkflowObserver::class);
        }
    }
}
