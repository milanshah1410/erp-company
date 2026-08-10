<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handset registered to receive push (retail base §10).
 *
 * No LogsAuditActivity: a phone re-registering its token on every app start
 * would fill the audit log with noise that tells nobody anything.
 */
class PushDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'token', 'token_hash', 'platform', 'device_name', 'app_version',
        'is_active', 'last_used_at', 'last_failed_at', 'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    protected $hidden = ['token', 'token_hash'];

    /** Fixed-width key for the unique index. Not a secret — just not a TEXT index. */
    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Mark a token FCM has rejected. Switched off rather than deleted so the row
     * still says the phone was once registered and why it stopped.
     */
    public function markDead(string $error): void
    {
        $this->update([
            'is_active' => false,
            'last_failed_at' => now(),
            'last_error' => mb_substr($error, 0, 250),
        ]);
    }

    /** A short label for the "your devices" list. */
    public function label(): string
    {
        return $this->device_name ?: ucfirst($this->platform).' device';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
