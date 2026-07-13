<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingProgress extends Model
{
    protected $connection = 'central';

    protected $table = 'onboarding_progress';

    protected $fillable = ['tenant_id', 'steps', 'completed_at'];

    protected $casts = [
        'steps' => 'array',
        'completed_at' => 'datetime',
    ];

    // The wizard steps a tenant must complete, in order.
    public const STEPS = ['welcome', 'warehouse', 'products', 'invites'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markStep(string $step): void
    {
        $steps = $this->steps ?? [];
        $steps[$step] = true;
        $this->steps = $steps;

        if (empty(array_diff(self::STEPS, array_keys(array_filter($steps))))) {
            $this->completed_at = now();
        }

        $this->save();
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
