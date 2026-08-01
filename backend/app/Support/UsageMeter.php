<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What each workspace is actually consuming (Volume 11 §"Tenant Usage").
 *
 * Five metrics of two shapes, which is the whole design:
 *
 *  - counters (API calls, AI calls) accumulate through a day and only ever go
 *    up. They are buffered in the cache and flushed to `tenant_usage_daily`
 *    periodically, because writing a row per API request would double the
 *    database traffic of every cheap GET in the product.
 *
 *  - snapshots (storage, products, customers) are a reading taken at a moment.
 *    They are recomputed on demand for "now" and written once a day so the trend
 *    chart has history. Overwriting the day's row rather than adding to it is
 *    what makes them snapshots.
 *
 * The buffering trades a little accuracy for a lot of throughput: a crash
 * between two flushes loses at most the counts since the last one, and the
 * read-then-clear in flush() has a narrow window where a concurrent increment
 * can be dropped. That is the right trade for an observability surface. If these
 * numbers ever become billable, the counters need to move to an append-only
 * write per event — the storage shape here would not change, only the path in.
 */
final class UsageMeter
{
    public const API_CALLS = 'api_calls';

    public const AI_CALLS = 'ai_calls';

    public const STORAGE_BYTES = 'storage_bytes';

    public const PRODUCTS = 'products';

    public const CUSTOMERS = 'customers';

    /**
     * metric => [label, kind, unit]
     *
     * `kind` decides both how flush/snapshot treat the row and how the panel
     * aggregates a date range: counters sum, snapshots take the latest.
     */
    public const METRICS = [
        self::API_CALLS => ['label' => 'API calls', 'kind' => 'counter', 'unit' => 'count', 'icon' => 'pi pi-bolt'],
        self::STORAGE_BYTES => ['label' => 'Storage', 'kind' => 'snapshot', 'unit' => 'bytes', 'icon' => 'pi pi-database'],
        self::PRODUCTS => ['label' => 'Products', 'kind' => 'snapshot', 'unit' => 'count', 'icon' => 'pi pi-box'],
        self::CUSTOMERS => ['label' => 'Customers', 'kind' => 'snapshot', 'unit' => 'count', 'icon' => 'pi pi-users'],
        self::AI_CALLS => ['label' => 'AI usage', 'kind' => 'counter', 'unit' => 'count', 'icon' => 'pi pi-sparkles'],
    ];

    private const TABLE = 'tenant_usage_daily';

    /**
     * Buffer lifetime. Comfortably longer than the flush interval so a worker
     * outage of a few hours costs nothing.
     */
    private const BUFFER_TTL = 259200; // 3 days

    /** Recomputing a tenant's storage walks the whole folder; once an hour is plenty. */
    private const STORAGE_TTL = 3600;

    /** @return string[] */
    public static function counters(): array
    {
        return array_keys(array_filter(self::METRICS, fn (array $m) => $m['kind'] === 'counter'));
    }

    /** @return string[] */
    public static function snapshots(): array
    {
        return array_keys(array_filter(self::METRICS, fn (array $m) => $m['kind'] === 'snapshot'));
    }

    // -----------------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------------

    /**
     * Record consumption of a counter metric.
     *
     * Never throws: metering must not be able to fail a request it is only
     * observing. A cache outage costs us a data point, not a customer's invoice.
     */
    public static function hit(string $metric, int $by = 1, ?int $tenantId = null): void
    {
        if ($by <= 0 || ! in_array($metric, self::counters(), true)) {
            return;
        }

        $tenantId ??= app(TenantContext::class)->id();
        if ($tenantId === null) {
            return;
        }

        try {
            $key = self::bufferKey($tenantId, self::today(), $metric);

            // add() seeds the key (and its TTL) exactly once; every later hit in
            // the window takes the increment path. Both orderings of a race
            // between two processes land on the same total.
            if (! Cache::add($key, $by, self::BUFFER_TTL)) {
                Cache::increment($key, $by);
            }
        } catch (Throwable) {
            // Metering is best-effort by design — see the class docblock.
        }
    }

    /**
     * Move buffered counters into `tenant_usage_daily`.
     *
     * Walks tenants rather than scanning cache keys: the cache store has no key
     * enumeration we can rely on across drivers, and the tenant list is small,
     * enumerable and authoritative. Yesterday is swept alongside today so a
     * flush that straddles midnight does not strand the previous day's tail.
     *
     * @return int rows written
     */
    public static function flush(): int
    {
        $dates = [self::today(), Carbon::yesterday()->toDateString()];
        $written = 0;

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            foreach ($dates as $date) {
                foreach (self::counters() as $metric) {
                    $delta = (int) (Cache::pull(self::bufferKey((int) $tenantId, $date, $metric)) ?? 0);

                    if ($delta > 0) {
                        self::addTo((int) $tenantId, $date, $metric, $delta);
                        $written++;
                    }
                }
            }
        }

        return $written;
    }

    /**
     * Write today's reading for every snapshot metric, for every tenant.
     *
     * @return int rows written
     */
    public static function snapshot(?CarbonInterface $date = null): int
    {
        $day = ($date ?? Carbon::today())->toDateString();
        $written = 0;

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            foreach (self::snapshotValues((int) $tenantId) as $metric => $value) {
                self::setTo((int) $tenantId, $day, $metric, $value);
                $written++;
            }
        }

        return $written;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Every metric for one tenant, right now: counters as today's persisted
     * total plus whatever is still buffered, snapshots recomputed.
     *
     * @return array<string, int>
     */
    public static function current(int $tenantId): array
    {
        $persisted = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('date', self::today())
            ->pluck('value', 'metric');

        $out = [];

        foreach (self::counters() as $metric) {
            $buffered = (int) (Cache::get(self::bufferKey($tenantId, self::today(), $metric)) ?? 0);
            $out[$metric] = (int) ($persisted[$metric] ?? 0) + $buffered;
        }

        return $out + self::snapshotValues($tenantId);
    }

    /**
     * The same, for every tenant at once — the marketplace-scale version used by
     * the usage table, which must not run five queries per row.
     *
     * Counters come from a single grouped query over the window; snapshots from
     * a single grouped count each. Buffered counter deltas are folded in for the
     * tenants that have any.
     *
     * @param  int  $days  window for counter totals (snapshots are always "now")
     * @return array<int, array<string, int>>  tenant id => metric => value
     */
    public static function forAllTenants(int $days = 30): array
    {
        $tenantIds = Tenant::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $since = Carbon::today()->subDays(max(0, $days - 1))->toDateString();

        $out = [];
        foreach ($tenantIds as $id) {
            $out[$id] = array_fill_keys(array_keys(self::METRICS), 0);
        }

        // Counters: summed across the window.
        $rows = DB::table(self::TABLE)
            ->select('tenant_id', 'metric', DB::raw('SUM(value) as aggregate'))
            ->whereIn('metric', self::counters())
            ->where('date', '>=', $since)
            ->groupBy('tenant_id', 'metric')
            ->get();

        foreach ($rows as $row) {
            if (isset($out[(int) $row->tenant_id])) {
                $out[(int) $row->tenant_id][$row->metric] = (int) $row->aggregate;
            }
        }

        // …plus anything still sitting in today's buffer, so the table never
        // reads as stale by up to a flush interval.
        foreach ($tenantIds as $id) {
            foreach (self::counters() as $metric) {
                $buffered = (int) (Cache::get(self::bufferKey($id, self::today(), $metric)) ?? 0);
                if ($buffered > 0) {
                    $out[$id][$metric] += $buffered;
                }
            }
        }

        // Snapshots: two grouped counts, and the last recorded storage reading
        // (walking every tenant's folder on a list render would be absurd).
        foreach ([self::PRODUCTS => Product::class, self::CUSTOMERS => Customer::class] as $metric => $model) {
            $counts = $model::allTenants()
                ->select('tenant_id', DB::raw('count(*) as aggregate'))
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');

            foreach ($counts as $tenantId => $count) {
                if (isset($out[(int) $tenantId])) {
                    $out[(int) $tenantId][$metric] = (int) $count;
                }
            }
        }

        foreach (self::latestSnapshot(self::STORAGE_BYTES) as $tenantId => $value) {
            if (isset($out[$tenantId])) {
                $out[$tenantId][self::STORAGE_BYTES] = $value;
            }
        }

        return $out;
    }

    /**
     * Daily history for one tenant: date => metric => value.
     *
     * Counter days with no row are zero; snapshot days with no row are left out
     * rather than zeroed, because "we did not measure" and "it was empty" are
     * different claims and a chart that conflates them lies.
     *
     * @return list<array<string, mixed>>
     */
    public static function series(int $tenantId, int $days = 30): array
    {
        $since = Carbon::today()->subDays(max(0, $days - 1));

        $rows = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $since->toDateString())
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row->date][$row->metric] = (int) $row->value;
        }

        $out = [];
        for ($day = $since->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $date = $day->toDateString();
            $found = $byDate[$date] ?? [];

            $point = ['date' => $date];
            foreach (self::METRICS as $metric => $meta) {
                $point[$metric] = $meta['kind'] === 'counter'
                    ? (int) ($found[$metric] ?? 0)
                    : ($found[$metric] ?? null);
            }

            $out[] = $point;
        }

        return $out;
    }

    /**
     * Platform-wide totals: counters summed over the window, snapshots summed
     * across the latest reading per tenant.
     *
     * @return array<string, int>
     */
    public static function platformTotals(int $days = 30): array
    {
        $totals = array_fill_keys(array_keys(self::METRICS), 0);

        foreach (self::forAllTenants($days) as $metrics) {
            foreach ($metrics as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return $totals;
    }

    /**
     * Bytes stored under this tenant's folder. Cached — the S3/local listing is
     * the expensive part and the number moves slowly.
     */
    public static function storageBytes(int $tenantId, bool $fresh = false): int
    {
        $key = "usage:storage:{$tenantId}";

        if ($fresh) {
            Cache::forget($key);
        }

        return (int) Cache::remember($key, self::STORAGE_TTL, function () use ($tenantId): int {
            try {
                $disk = TenantStorage::diskForTenant($tenantId);
                $bytes = 0;

                foreach ($disk->allFiles() as $file) {
                    $bytes += $disk->size($file);
                }

                return $bytes;
            } catch (Throwable) {
                // A tenant that has never uploaded has no folder on the local
                // driver; that is zero bytes, not an error.
                return 0;
            }
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string, int> */
    private static function snapshotValues(int $tenantId): array
    {
        return [
            self::PRODUCTS => TenantLimits::count(Product::class, $tenantId),
            self::CUSTOMERS => TenantLimits::count(Customer::class, $tenantId),
            self::STORAGE_BYTES => self::storageBytes($tenantId),
        ];
    }

    /**
     * Latest recorded value of a snapshot metric per tenant.
     *
     * @return array<int, int>
     */
    private static function latestSnapshot(string $metric): array
    {
        $rows = DB::table(self::TABLE.' as u')
            ->select('u.tenant_id', 'u.value')
            ->where('u.metric', $metric)
            ->whereRaw('u.date = (select max(date) from '.self::TABLE.' where tenant_id = u.tenant_id and metric = u.metric)')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->tenant_id] = (int) $row->value;
        }

        return $out;
    }

    /** Add to a counter row, creating it first if today is its first hit. */
    private static function addTo(int $tenantId, string $date, string $metric, int $delta): void
    {
        self::ensureRow($tenantId, $date, $metric);

        DB::table(self::TABLE)
            ->where(['tenant_id' => $tenantId, 'date' => $date, 'metric' => $metric])
            ->increment('value', $delta, ['updated_at' => now()]);
    }

    /** Overwrite a snapshot row with the day's latest reading. */
    private static function setTo(int $tenantId, string $date, string $metric, int $value): void
    {
        self::ensureRow($tenantId, $date, $metric);

        DB::table(self::TABLE)
            ->where(['tenant_id' => $tenantId, 'date' => $date, 'metric' => $metric])
            ->update(['value' => $value, 'updated_at' => now()]);
    }

    /**
     * insertOrIgnore leans on the (tenant, date, metric) unique index, so two
     * concurrent flushes cannot create a duplicate — one loses the insert and
     * both then increment the same row.
     */
    private static function ensureRow(int $tenantId, string $date, string $metric): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'tenant_id' => $tenantId,
            'date' => $date,
            'metric' => $metric,
            'value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function bufferKey(int $tenantId, string $date, string $metric): string
    {
        return "usage:buf:{$tenantId}:{$date}:{$metric}";
    }

    private static function today(): string
    {
        return Carbon::today()->toDateString();
    }
}
