<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class NotificationController extends Controller
{
    /** The authenticated user's notifications, newest first. */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // The notification payload lives in the JSON `data` column; a LIKE over
            // its raw text matches on the title/message we render to the user.
            $query->where('data', 'like', "%{$search}%");
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }

    /** Manually trigger the due-invoice scan (also runs daily via the scheduler). */
    public function checkDueInvoices()
    {
        Artisan::call('notifications:check-due-invoices');

        return response()->json(['message' => 'Due-invoice check completed.']);
    }
}
