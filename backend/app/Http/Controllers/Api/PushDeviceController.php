<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use App\Services\PushService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Handset registration for push notifications (retail base §10).
 *
 * Registering and forgetting a device carries no permission on purpose: it is the
 * phone in the caller's own pocket, exactly like the avatar on /profile. Nothing
 * here can reach another user's devices — every query is bound to
 * `$request->user()`. The operator-facing view of *everybody's* devices is the
 * `devices.view` route at the bottom.
 */
class PushDeviceController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    /** The caller's own handsets — "your devices". */
    public function index(Request $request)
    {
        $devices = PushDevice::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $devices->map(fn (PushDevice $d) => $this->payload($d)),
            'driver' => $this->push->driver(),
            'configured' => $this->push->configured(),
        ]);
    }

    /**
     * Register (or refresh) a token.
     *
     * Called on every app start, not once at sign-in: FCM rotates tokens on
     * reinstall, on restore to a new handset, and occasionally unprompted. So this
     * is an upsert keyed on the token hash, and it re-activates a row that was
     * previously switched off — the same phone coming back after a reinstall is
     * the commonest reason a dead token becomes live again.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        $hash = PushDevice::hashFor($data['token']);

        /**
         * updateOrCreate on the hash alone, without the tenant or user in the
         * match: the hash is globally unique, and a handset that moved to another
         * workspace or signed in as somebody else must be *moved*, not duplicated.
         * Otherwise the previous owner keeps receiving this shop's notifications.
         */
        $device = PushDevice::withoutGlobalScopes()->updateOrCreate(
            ['token_hash' => $hash],
            [
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $request->user()->id,
                'token' => $data['token'],
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
                'last_failed_at' => null,
                'last_error' => null,
            ],
        );

        return response()->json(['message' => 'Device registered.', 'data' => $this->payload($device)], 201);
    }

    /** Forget one of the caller's own handsets — signing out on a phone. */
    public function destroy(Request $request, PushDevice $pushDevice)
    {
        if ($pushDevice->user_id !== $request->user()->id) {
            // Not 403: the caller has no business knowing this row exists.
            return response()->json(['message' => 'Device not found.'], 404);
        }

        $pushDevice->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    /**
     * Send a test push to the caller's own devices.
     *
     * Reports what happened per device rather than a bare "sent": with the `log`
     * driver nothing leaves the building, and a green tick that means "written to
     * a log file" is how somebody ships to production believing push works.
     */
    public function test(Request $request)
    {
        try {
            $result = $this->push->toUser(
                $request->user(),
                'Test notification',
                'If you can read this on your phone, push is working.',
                ['type' => 'test'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['devices'] === 0) {
            return response()->json([
                'message' => 'No handset is registered to your account yet — open the mobile app and sign in once.',
            ], 422);
        }

        $driver = $this->push->driver();
        $message = $driver === 'log'
            ? "Recorded {$result['sent']} push(es) in the application log — switch the driver to Firebase to send for real."
            : "Sent to {$result['sent']} of {$result['devices']} device(s)".
                ($result['failed'] > 0 ? ", {$result['failed']} failed." : '.');

        return response()->json(['message' => $message, 'data' => $result]);
    }

    /**
     * Every registered handset in the workspace. The operator's view — who can be
     * reached, and which phones have gone dead — behind `devices.view`.
     */
    public function all(Request $request)
    {
        $devices = PushDevice::query()
            ->with('user:id,name')
            ->orderByDesc('is_active')
            ->orderByDesc('last_used_at')
            ->get();

        return response()->json([
            'data' => $devices->map(fn (PushDevice $d) => $this->payload($d) + ['user' => $d->user?->name]),
            'driver' => $this->push->driver(),
            'configured' => $this->push->configured(),
        ]);
    }

    private function payload(PushDevice $d): array
    {
        return [
            'id' => $d->id,
            'label' => $d->label(),
            'platform' => $d->platform,
            'app_version' => $d->app_version,
            'is_active' => $d->is_active,
            'last_used_at' => $d->last_used_at,
            'last_failed_at' => $d->last_failed_at,
            'last_error' => $d->last_error,
            'created_at' => $d->created_at,
            // The token itself is never returned: it is hidden on the model, and a
            // list endpoint that echoed it would hand every device's push
            // credential to anything that could read one response.
        ];
    }
}
