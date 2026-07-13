<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Shared audit-log defaults for §4.10: log only dirty attributes on
 * create/update/delete, skip no-op saves, tag entries with the model's
 * own log name so the audit log can filter by module.
 */
trait LogsAuditActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->logExcept(['created_at', 'updated_at'])
            ->useLogName(str($this->getTable())->replace('_', '-'));
    }
}
