<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\ActivityLog as Activity;
use Illuminate\Http\Request;

/**
 * Read-only view over the immutable activity_log table (SRS §4.10).
 * No store/update/destroy — entries are written automatically by
 * LogsAuditActivity-equipped models and auth event logging.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::query()->with('causer')->latest('id');

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->query('log_name'));
        }
        if ($request->filled('event')) {
            $query->where('event', $request->query('event'));
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->query('causer_id'));
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', 'like', '%'.$request->query('subject_type'));
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%'.$request->query('search').'%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 20), 200);

        return AuditLogResource::collection($query->paginate($perPage));
    }

    public function show(Activity $activity)
    {
        return new AuditLogResource($activity->load('causer'));
    }

    /** Distinct filter values for building dropdowns on the frontend. */
    public function meta()
    {
        return [
            'log_names' => Activity::query()->select('log_name')->distinct()->orderBy('log_name')->pluck('log_name'),
            'events' => Activity::query()->select('event')->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
            'subject_types' => Activity::query()->select('subject_type')->whereNotNull('subject_type')->distinct()->get()
                ->pluck('subject_type')->map(fn ($t) => class_basename($t))->unique()->sort()->values(),
        ];
    }
}
