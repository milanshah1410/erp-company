<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Outgoing-mail abstraction (SRS §19). Mirrors the SMS module's log-first
 * philosophy: with `mail_driver = log` every message is written to the Laravel
 * log so the whole email UI and audit trail work with no mail server, and
 * switching the tenant's `mail_driver` setting to `smtp` turns on a real sender
 * using the SMTP host/credentials configured under Settings → Email.
 *
 * SMTP config is read from Settings at send time (inside the tenant context of
 * the queued job), so each tenant sends through their own account.
 */
class MailService
{
    /** Whether the tenant has enough SMTP config to actually send real mail. */
    public function configured(): bool
    {
        return Setting::mailConfigured();
    }

    /** The From address/name for this tenant, falling back to the app default. */
    public function from(): array
    {
        $c = Setting::mailConfig();

        return [
            'address' => $c['mail_from_address'] ?: (config('mail.from.address') ?: 'no-reply@localhost'),
            'name' => $c['mail_from_name'] ?: (config('mail.from.name') ?: config('app.name')),
        ];
    }

    /**
     * The name of the mailer this tenant sends through, having configured it.
     *
     * Notifications route by mailer *name* rather than by instance — Laravel's
     * MailChannel resolves `MailMessage::$mailer` out of the factory — so a
     * notification that must go out on the tenant's own SMTP account needs this
     * rather than mailer() below. Calling it has the same configuring
     * side-effect, so the name it returns is always a mailer that exists.
     */
    public function mailerName(): string
    {
        $this->mailer();

        $c = Setting::mailConfig();

        return (($c['mail_driver'] ?? 'log') === 'smtp' && ! empty($c['mail_host']))
            ? 'tenant_smtp'
            : (string) config('mail.default');
    }

    /**
     * A Mailer configured from the tenant's settings. When SMTP is not configured
     * the app default mailer (the `log` driver out of the box) is returned so the
     * flow still succeeds and is captured in the log for inspection.
     */
    public function mailer(): Mailer
    {
        $c = Setting::mailConfig();

        if (($c['mail_driver'] ?? 'log') !== 'smtp' || empty($c['mail_host'])) {
            return Mail::mailer(config('mail.default'));
        }

        // Rebuild the tenant SMTP transport from scratch on every resolve — a
        // single worker process serves many tenants, so cached mailers would
        // otherwise leak one tenant's SMTP account into another's send.
        config([
            'mail.mailers.tenant_smtp' => [
                'transport' => 'smtp',
                'host' => $c['mail_host'],
                'port' => (int) ($c['mail_port'] ?: 587),
                'encryption' => $c['mail_encryption'] === 'none' ? null : $c['mail_encryption'],
                'username' => $c['mail_username'] ?: null,
                'password' => $c['mail_password'] ?: null,
                'timeout' => 30,
            ],
        ]);

        Mail::forgetMailers();

        return Mail::mailer('tenant_smtp');
    }
}
