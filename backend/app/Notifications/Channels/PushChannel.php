<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\PushService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification to a user's registered handsets (retail base §10).
 *
 * Registered as the `push` channel, so any notification can opt in by listing
 * `'push'` in via() and providing `toPush()`. A notification without `toPush()`
 * is skipped rather than guessed at: deriving a lock-screen line from a mail
 * body produces a message nobody would have written.
 *
 * Swallows every failure. A push is the least important copy of any message — the
 * same notification is already in the bell and usually in an inbox — so a
 * Firebase outage must not break a Notification::send() that is otherwise
 * succeeding, or take a scheduled command down with it.
 */
class PushChannel
{
    public function __construct(private readonly PushService $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        try {
            $payload = $notification->toPush($notifiable);
            if (empty($payload['title'])) {
                return;
            }

            $this->push->toUser(
                $notifiable,
                (string) $payload['title'],
                (string) ($payload['body'] ?? ''),
                // Whatever the app should do when it is tapped. Strings only —
                // FCM rejects a data map holding anything else.
                array_filter([
                    'url' => $payload['url'] ?? null,
                    'type' => $payload['type'] ?? null,
                ], fn ($v) => $v !== null),
            );
        } catch (Throwable $e) {
            Log::warning('Push notification skipped: '.$e->getMessage(), [
                'notifiable_id' => $notifiable->id,
                'notification' => $notification::class,
            ]);
        }
    }
}
