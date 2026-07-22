<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform feature flag (central table, managed from the SaaS-owner Ops panel).
 *
 * Check a flag anywhere with FeatureFlag::isEnabled('key'). Unknown keys are
 * treated as disabled so shipping a flag check before seeding the flag is safe.
 */
class FeatureFlag extends Model
{
    protected $fillable = ['key', 'label', 'description', 'enabled', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function isEnabled(string $key): bool
    {
        return (bool) static::query()->where('key', $key)->value('enabled');
    }
}
