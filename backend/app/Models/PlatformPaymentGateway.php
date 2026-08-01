<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PlatformGateways;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's own merchant configuration for one gateway (central DB).
 *
 * Credentials are encrypted at rest by the cast, so a database dump does not leak
 * live keys. They are read by {@see \App\Services\RazorpayService}, which prefers a
 * row here over config('saas.razorpay') — meaning key rotation is a panel edit
 * rather than a deploy, while an unconfigured install still falls back to .env.
 *
 * Exactly one row may be the default; `markDefault()` is the only way to set it
 * and clears the flag everywhere else in the same breath.
 */
class PlatformPaymentGateway extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    /** Cache key for the resolved active/default gateway lookups. */
    private const CACHE_KEY = 'platform_payment_gateways';

    private const CACHE_TTL = 600;

    protected $fillable = [
        'gateway', 'label', 'mode', 'credentials', 'is_active', 'is_default',
        'notes', 'last_verified_at', 'created_by',
    ];

    protected $casts = [
        // Encrypted JSON — never expose this array wholesale over the API.
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** One credential value, or null when unset. */
    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** True when every required credential for this gateway is present. */
    public function isConfigured(): bool
    {
        foreach (PlatformGateways::fields($this->gateway) as $name => $field) {
            if ($field['required'] && $this->credential($name) === null) {
                return false;
            }
        }

        return true;
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    public function displayLabel(): string
    {
        return $this->label ?: PlatformGateways::label($this->gateway);
    }

    /**
     * The active, fully-configured row for one gateway — the resolution
     * RazorpayService uses, cached because it sits on the checkout path.
     */
    public static function activeFor(string $gateway): ?self
    {
        return self::activeMap()[$gateway] ?? null;
    }

    /** The gateway new checkouts should start at, if any is switched on. */
    public static function default(): ?self
    {
        $map = self::activeMap();

        foreach ($map as $row) {
            if ($row->is_default) {
                return $row;
            }
        }

        return reset($map) ?: null;
    }

    /**
     * Every active + configured gateway, keyed by gateway.
     *
     * @return array<string, self>
     */
    public static function activeMap(): array
    {
        /** @var array<string, self> $map */
        $map = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => self::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (self $row) => $row->isConfigured())
            ->keyBy('gateway')
            ->all());

        return $map;
    }

    /** Make this the default gateway, demoting whichever row held the flag. */
    public function markDefault(): void
    {
        static::query()->where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
