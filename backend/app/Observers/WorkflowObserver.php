<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;

/**
 * Bridges Eloquent `created` events to the WorkflowEngine (project-stack.md
 * §11). Registered in AppServiceProvider for every model that backs a
 * "*.created" trigger.
 */
class WorkflowObserver
{
    public function __construct(private WorkflowEngine $engine) {}

    public function created(Model $model): void
    {
        $trigger = $this->triggerFor($model);
        if ($trigger !== null) {
            $this->engine->handle($trigger, $model);
        }
    }

    /** Reverse-map a model instance to its "created" trigger key. */
    private function triggerFor(Model $model): ?string
    {
        foreach (WorkflowEngine::TRIGGERS as $key => $meta) {
            if ($model instanceof $meta['model'] && str_ends_with($key, '.created')) {
                return $key;
            }
        }

        return null;
    }
}
