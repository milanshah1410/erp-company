<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the industry plugins (app/Modules/Industry).
 *
 * Only migrations are registered here. Route files are deliberately *not*
 * loaded by this provider: a vertical's endpoints have to sit inside the
 * middleware group in routes/tenant.php to inherit tenancy, auth, permissions
 * and module gating, so that file requires them itself (and routes/api.php
 * requires the public ones). A provider loading routes would hand every plugin a
 * bare, unprotected route group — the one mistake here that would not be
 * obvious from reading the plugin.
 */
class IndustryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (IndustryRegistry::migrationPaths() as $path) {
            $this->loadMigrationsFrom($path);
        }
    }
}
