<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    // Central-DB model: pin to the landlord connection so it keeps resolving
    // to the central database even while a tenant connection is the default.
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'slug', 'name', 'price', 'billing_cycle', 'currency',
        'is_custom', 'is_active', 'sort_order', 'feature_limits',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'feature_limits' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Read a single feature limit. Returns null when the plan places no limit
     * on that feature (treat null as "unlimited").
     */
    public function limit(string $key): mixed
    {
        return $this->feature_limits[$key] ?? null;
    }
}
