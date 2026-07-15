<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelSyncLogResource;
use App\Models\ChannelSyncLog;
use Illuminate\Http\Request;

/** Read-only history of e-commerce channel sync runs (SRS §15). */
class ChannelSyncLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ChannelSyncLog::query()
            ->with('integration:id,name,platform')
            ->orderByDesc('id');

        if ($request->filled('channel_integration_id')) {
            $query->where('channel_integration_id', $request->query('channel_integration_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return ChannelSyncLogResource::collection($query->paginate($perPage));
    }
}
