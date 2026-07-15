<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded sync run against an e-commerce channel (connection test, order
 * pull, inventory push, or listing pull) with its outcome counts.
 */
class ChannelSyncLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'channel_integration_id', 'type', 'direction', 'status',
        'processed', 'succeeded', 'failed', 'message',
    ];

    protected $casts = [
        'processed' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ChannelIntegration::class, 'channel_integration_id');
    }
}
