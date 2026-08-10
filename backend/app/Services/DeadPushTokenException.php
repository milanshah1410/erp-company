<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * FCM says this registration token will never work again — the app was
 * uninstalled, restored to another handset, or the token was rotated.
 *
 * Its own class purely so PushService can tell "switch this device off" apart
 * from "try again tonight". Without the distinction the device table fills with
 * handsets that fail on every send for ever, and every digest run pays for them.
 */
class DeadPushTokenException extends RuntimeException {}
