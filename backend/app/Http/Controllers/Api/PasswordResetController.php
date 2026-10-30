<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\MailService;
use App\Support\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Forgot / reset password (mobile app §4).
 *
 * Both routes are public — they run before any session exists — and both look
 * the user up across tenants exactly the way login does, because at this point
 * there is no tenant context to scope to. Email is globally unique, so the
 * lookup reaches at most one row.
 *
 * Two things here are deliberate and easy to get wrong:
 *
 *  1. **Neither endpoint reveals whether an address is registered.** `request()`
 *     answers the same way for a known and an unknown address. A password-reset
 *     form that says "no such user" is a free membership check against every
 *     workspace on the platform.
 *
 *  2. **The mail goes out on the tenant's own SMTP account.** Once the user is
 *     found we set that user's tenant context before sending, so MailService
 *     builds the transport from *their* workspace's white-label settings and the
 *     message arrives from the brand the user recognises rather than ours.
 *
 * Token storage, hashing, expiry and throttling are Laravel's password broker —
 * this controller does not reimplement any of it. What it adds is the tenant
 * context, the tenant mailer, and revoking every API token on a successful
 * reset: a reset is what somebody does when they believe their account is
 * compromised, and leaving the attacker's phone signed in would defeat it.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly MailService $mail) {}

    /**
     * Send a reset token to the address, if it belongs to an account.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The reply every caller gets, whatever we find. Built once so the two
        // return paths below can never drift apart and start leaking the
        // difference between them.
        $generic = response()->json([
            'message' => 'If that email address belongs to an account, a reset link is on its way.',
        ]);

        $user = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $data['email'])
            ->first();

        // A disabled account, or one whose workspace the operator has locked,
        // is not a route back in. Same silent answer.
        if (! $user || ! $user->is_active || ! $user->tenant_id) {
            return $generic;
        }

        $tenant = Tenant::find($user->tenant_id);
        if ($tenant && $tenant->isOperatorSuspended()) {
            return $generic;
        }

        // From here on we are acting inside the user's workspace: the mailer,
        // the branding and the settings all resolve from it.
        app(TenantContext::class)->set($user->tenant_id);

        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        /**
         * The throttle. `sendResetLink()` applies this itself, but we mint the
         * token by hand (so the mail can go out on the tenant's transport), and
         * `createToken()` on its own has no such guard — without this, anyone who
         * knows an address can have us mail its owner as fast as they can post.
         *
         * Still the generic reply: "you already asked a minute ago" is the same
         * membership check the rest of this method refuses to answer.
         */
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return $generic;
        }

        $token = $broker->createToken($user);

        Notification::route('mail', $user->email)->notify(new ResetPasswordNotification(
            token: $token,
            email: $user->email,
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
            mailerName: $this->mail->mailerName(),
        ));

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->event('password_reset_requested')
            ->log('Password reset requested');

        return $generic;
    }

    /**
     * Exchange a valid token for a new password.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password): void {
                // Tenant context first: saving the user and revoking its tokens
                // both touch tenant-scoped tables.
                app(TenantContext::class)->set((int) $user->tenant_id);

                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Every signed-in device is signed out. See the class docblock.
                $user->tokens()->delete();

                activity('auth')
                    ->causedBy($user)
                    ->withProperties(['ip' => request()->ip()])
                    ->event('password_reset')
                    ->log('Password reset completed');

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One field, whatever went wrong: an expired token, a wrong token
            // and a token for another address are the same event to the person
            // holding it, and distinguishing them tells an attacker which half
            // of the pair they guessed right.
            throw ValidationException::withMessages([
                'token' => ['This reset link is invalid or has expired. Please request a new one.'],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. Please sign in with your new password.',
        ]);
    }
}
