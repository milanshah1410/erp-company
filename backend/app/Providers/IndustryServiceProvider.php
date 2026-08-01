<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the industry plugins (app/Modules/Industry).
 *
 * Migrations and each plugin's own boot() are registered here. Route files are
 * deliberately *not* loaded by this provider: a vertical's endpoints have to sit
 * inside the middleware group in routes/tenant.php to inherit tenancy, auth,
 * permissions and module gating, so that file requires them itself (and
 * routes/api.php requires the public ones). A provider loading routes would hand
 * every plugin a bare, unprotected route group — the one mistake here that would
 * not be obvious from reading the plugin.
 */
class IndustryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (IndustryRegistry::migrationPaths() as $path) {
            $this->loadMigrationsFrom($path);
        }

        /**
         * Runtime wiring a declarative plugin cannot express: relations and
         * observers a vertical attaches to *core* models it has extended (see
         * the note in IndustryPlugin).
         *
         * `boot()` is an optional convention rather than an interface method —
         * most verticals need nothing here, and requiring it would make every
         * one of them carry an empty implementation — so the method is checked
         * for rather than assumed.
         *
         * Deliberately unconditional, not gated on the tenant's module set: this
         * runs before a tenant is even resolved, and a relation that exists but
         * is never used costs nothing, while one that is missing when a query
         * asks for it is a 500.
         */
        foreach (IndustryRegistry::plugins() as $plugin) {
            if (method_exists($plugin, 'boot')) {
                $plugin->boot();
            }
        }
    }
}
