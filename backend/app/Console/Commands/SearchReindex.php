<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Laravel\Scout\Searchable;

/**
 * Rebuilds the Meilisearch indexes for every searchable model, across all
 * tenants in one pass.
 *
 * A bare `php artisan scout:import` cannot be used here: the searchable models
 * carry the row-level TenantScope, which fails closed (throws) when no tenant
 * context is set — as is the case on the CLI. We therefore run the whole
 * import inside TenantContext::runWithoutScope(), so the chunked query sees
 * every tenant's rows; each document still carries its own `tenant_id` (emitted
 * by the model's toSearchableArray), which is what keeps search results
 * tenant-isolated at query time.
 */
class SearchReindex extends Command
{
    /** @var class-string[] Models using the Searchable trait. */
    private const MODELS = [
        Product::class,
        Customer::class,
        Invoice::class,
    ];

    protected $signature = 'search:reindex {--fresh : Flush each index before importing}';

    protected $description = 'Push tenant index settings and (re)build the Meilisearch indexes for all tenants';

    public function handle(TenantContext $tenant): int
    {
        // Sync filterableAttributes/searchableAttributes from config/scout.php,
        // otherwise ->where('tenant_id', ...) would be rejected by Meilisearch.
        $this->info('Syncing index settings...');
        $this->call('scout:sync-index-settings');

        $tenant->runWithoutScope(function () {
            foreach (self::MODELS as $model) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model&Searchable> $model */
                if ($this->option('fresh')) {
                    $this->warn("Flushing {$model} index...");
                    $model::removeAllFromSearch();
                }

                $this->info("Indexing {$model}...");
                $model::makeAllSearchable();
            }
        });

        $this->info('Search reindex complete.');

        return self::SUCCESS;
    }
}
