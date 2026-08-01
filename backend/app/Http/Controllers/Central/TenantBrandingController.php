<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\MailService;
use App\Support\Branding;
use App\Support\Subdomain;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * White label for the SaaS-owner panel (Volume 11 §"White Label"):
 * /platform/tenants → White Label. Four surfaces, one screen:
 *
 *   Logo   — the workspace's mark. Deliberately the same image the invoices
 *            print (`company_logo_url`) rather than a second "app logo", so a
 *            workspace can never wear two different marks.
 *   Domain — the hosts that belong to this tenant. The `{sub}.{root}` address
 *            claimed at signup is the primary row and is not editable here;
 *            additional rows are the customer's own domains.
 *   Theme  — brand colours, display name, tagline, dark-by-default.
 *   SMTP   — the account this tenant's outgoing mail is sent through, which is
 *            the other half of white labelling: branded screens sending mail
 *            from `noreply@ourplatform.com` gives the game away.
 *
 * Everything is stored in the tenant's own `settings` rows (and the shared
 * `domains` table), so the tenant's Settings → Email screen and this panel are
 * reading and writing one source of truth. See App\Support\Branding for how the
 * values are resolved back out for an unauthenticated page.
 */
class TenantBrandingController extends Controller
{
    public function __construct(private readonly MailService $mail) {}

    /** Everything the White Label screen renders, in one round-trip. */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'subdomain' => $tenant->subdomain,
                'workspace_url' => $tenant->workspaceUrl(),
            ],
            // The resolved brand (what the workspace actually renders) and the
            // platform fallback behind it…
            'branding' => Branding::forTenant($tenant),
            'platform' => Branding::platform(),
            // …plus the raw overrides. The panel needs these separately: a field
            // showing an inherited value must stay blank in the form, or saving
            // it would freeze today's platform brand into the tenant.
            'overrides' => $this->overrides($tenant),
            'domains' => $this->domains($tenant),
            'smtp' => $this->smtp($tenant),
        ]);
    }

    /**
     * This tenant's brand settings exactly as stored — '' where nothing was set,
     * i.e. "follow the platform".
     *
     * @return array<string, string>
     */
    private function overrides(Tenant $tenant): array
    {
        return app(TenantContext::class)->runWith($tenant->id, function (): array {
            $stored = Setting::query()
                ->whereIn('key', Setting::BRAND_KEYS)
                ->pluck('value', 'key')
                ->all();

            $out = [];
            foreach (Setting::BRAND_KEYS as $key) {
                $out[$key] = (string) ($stored[$key] ?? '');
            }

            return $out;
        });
    }

    /**
     * Save the theme block. A blank value clears the override and hands that
     * field back to the platform brand — which is how a white label is undone,
     * so blanks are meaningful and not filtered out.
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'brand_app_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'brand_tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'brand_primary_color' => ['sometimes', 'nullable', 'string', Branding::COLOR_RULE],
            'brand_accent_color' => ['sometimes', 'nullable', 'string', Branding::COLOR_RULE],
            'brand_dark_default' => ['sometimes', 'boolean'],
            'brand_powered_by' => ['sometimes', 'boolean'],
        ], [
            'brand_primary_color.regex' => 'Use a hex colour such as #e8a33d.',
            'brand_accent_color.regex' => 'Use a hex colour such as #2c4a5e.',
        ]);

        // The two switches are stored as '1'/'0'; filter_var rather than a bool
        // cast because the `boolean` rule passes 1/'1'/true through unchanged.
        $flags = ['brand_dark_default', 'brand_powered_by'];

        app(TenantContext::class)->runWith($tenant->id, function () use ($data, $flags): void {
            foreach ($data as $key => $value) {
                Setting::set($key, in_array($key, $flags, true)
                    ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                    : ($value === null ? '' : trim((string) $value)));
            }
        });

        $this->log($request, $tenant, 'theme-updated', array_keys($data));

        return $this->show($tenant);
    }

    /**
     * Replace the workspace's logo.
     *
     * Stored on the `public` disk rather than the tenant's private folder
     * (App\Support\TenantStorage) on purpose: this image has to render on the
     * sign-in page, before anyone is authenticated and without a signed URL
     * that expires. It is a brand asset, not business data.
     */
    public function uploadLogo(Request $request, Tenant $tenant): JsonResponse
    {
        // Raster only. SVG would be the nicer format for a logo, but it is
        // served from the app's own origin and can carry script — and this
        // image is rendered to every user of the workspace, so the upload path
        // is not the place to accept executable markup.
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $disk = Storage::disk('public');
        $folder = "branding/tenant-{$tenant->id}";

        // Clear the folder first so replacing a PNG with an SVG does not leave
        // the old file behind — one tenant, one logo.
        $disk->deleteDirectory($folder);

        $path = $request->file('logo')->storeAs(
            $folder,
            'logo.'.Str::lower($request->file('logo')->getClientOriginalExtension() ?: 'png'),
            'public',
        );

        // Cache-buster: the filename is deliberately stable, so without it a
        // replaced logo keeps showing the previous image.
        $url = $disk->url($path).'?v='.now()->timestamp;

        app(TenantContext::class)->runWith($tenant->id, fn () => Setting::set('company_logo_url', $url));

        $this->log($request, $tenant, 'logo-updated', ['path' => $path]);

        return response()->json(['message' => 'Logo updated.', 'logo_url' => $url]);
    }

    /** Drop the logo and fall back to the platform mark. */
    public function removeLogo(Request $request, Tenant $tenant): JsonResponse
    {
        Storage::disk('public')->deleteDirectory("branding/tenant-{$tenant->id}");

        app(TenantContext::class)->runWith($tenant->id, fn () => Setting::set('company_logo_url', ''));

        $this->log($request, $tenant, 'logo-removed', []);

        return response()->json(['message' => 'Logo removed.']);
    }

    /**
     * Attach a customer-owned domain to this tenant.
     *
     * Recording the host is identity, not routing — the same distinction the
     * workspace subdomain carries (App\Support\Subdomain): the app still
     * resolves tenancy from the authenticated user, and pointing the domain's
     * DNS at the app plus issuing its certificate are infra steps. What this
     * buys today is that an unauthenticated page served on that host wears the
     * tenant's brand (App\Support\Branding::forHost).
     */
    public function addDomain(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $host = $this->normalizeDomain($data['domain']);

        if ($reason = $this->domainError($host)) {
            return response()->json([
                'message' => 'That domain cannot be used.',
                'errors' => ['domain' => [$reason]],
            ], 422);
        }

        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $host,
            'is_primary' => false,
        ]);

        $this->log($request, $tenant, 'domain-added', ['domain' => $host]);

        return response()->json([
            'message' => "{$host} is now mapped to this workspace.",
            'domains' => $this->domains($tenant),
        ], 201);
    }

    /**
     * Detach a custom domain. The primary `{sub}.{root}` row is the workspace's
     * identity and is refused: removing it would orphan the address the tenant
     * signed up on and free it for someone else to claim.
     */
    public function removeDomain(Request $request, Tenant $tenant, Domain $domain): JsonResponse
    {
        if ($domain->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'That domain belongs to another workspace.'], 404);
        }

        if ($domain->is_primary) {
            return response()->json(['message' => 'The workspace address cannot be removed.'], 422);
        }

        $host = $domain->domain;
        $domain->delete();

        $this->log($request, $tenant, 'domain-removed', ['domain' => $host]);

        return response()->json([
            'message' => "{$host} is no longer mapped to this workspace.",
            'domains' => $this->domains($tenant),
        ]);
    }

    /**
     * Save the tenant's outgoing-mail account. The password is write-only: it is
     * never returned by show(), and an omitted/blank value leaves the stored one
     * alone so saving the form does not wipe a working account.
     */
    public function updateSmtp(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'mail_driver' => ['required', Rule::in(['log', 'smtp'])],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        // Turning SMTP on without a host and a From address would leave the
        // workspace unable to send at all — the log driver at least keeps a
        // record. Caught here rather than at send time.
        if ($data['mail_driver'] === 'smtp' && (empty($data['mail_host']) || empty($data['mail_from_address']))) {
            return response()->json([
                'message' => 'SMTP needs a host and a From address.',
                'errors' => [
                    'mail_host' => empty($data['mail_host']) ? ['Required to send over SMTP.'] : [],
                    'mail_from_address' => empty($data['mail_from_address']) ? ['Required to send over SMTP.'] : [],
                ],
            ], 422);
        }

        app(TenantContext::class)->runWith($tenant->id, function () use ($data): void {
            foreach ($data as $key => $value) {
                if ($key === 'mail_password' && ($value === null || $value === '')) {
                    continue; // keep the stored password
                }
                Setting::set($key, $value === null ? '' : (string) $value);
            }
        });

        $this->log($request, $tenant, 'smtp-updated', ['driver' => $data['mail_driver'], 'host' => $data['mail_host'] ?? null]);

        return response()->json([
            'message' => 'Email settings saved.',
            'smtp' => $this->smtp($tenant),
        ]);
    }

    /**
     * Send a test message through the tenant's own account, from inside its
     * context so MailService builds the transport from that tenant's settings.
     */
    public function testSmtp(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email']]);

        $result = app(TenantContext::class)->runWith($tenant->id, function () use ($data, $tenant): array {
            $from = $this->mail->from();
            $brand = Branding::forTenant($tenant)['app_name'];

            try {
                $this->mail->mailer()->raw(
                    "This is a test email from the {$brand} workspace, sent by the platform operator. "
                    .'If you received it, the outgoing-mail settings for this workspace are working.',
                    function ($message) use ($data, $from, $brand) {
                        $message->to($data['to'])
                            ->from($from['address'], $from['name'])
                            ->subject("{$brand} — Test Email");
                    },
                );
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => 'Test email failed: '.$e->getMessage()];
            }

            $driver = Setting::mailConfig()['mail_driver'] ?? 'log';

            return [
                'ok' => true,
                'message' => $driver === 'smtp'
                    ? "Test email sent to {$data['to']}."
                    : "Test email written to the application log (this workspace's mail driver is 'log').",
            ];
        });

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 422);
    }

    /**
     * The hosts mapped to this tenant, primary first.
     *
     * @return list<array<string, mixed>>
     */
    private function domains(Tenant $tenant): array
    {
        return Domain::where('tenant_id', $tenant->id)
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get()
            ->map(fn (Domain $d) => [
                'id' => $d->id,
                'domain' => $d->domain,
                'is_primary' => (bool) $d->is_primary,
                'url' => (config('saas.workspace_scheme', 'https')).'://'.$d->domain,
                'created_at' => $d->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * The tenant's mail settings for display — password replaced by a flag, so
     * the panel can show "configured" without ever shipping the credential.
     *
     * @return array<string, mixed>
     */
    private function smtp(Tenant $tenant): array
    {
        return app(TenantContext::class)->runWith($tenant->id, function (): array {
            $c = Setting::mailConfig();

            return [
                'mail_driver' => $c['mail_driver'] ?? 'log',
                'mail_host' => $c['mail_host'] ?? '',
                'mail_port' => $c['mail_port'] ?? '587',
                'mail_username' => $c['mail_username'] ?? '',
                'mail_encryption' => $c['mail_encryption'] ?? 'tls',
                'mail_from_address' => $c['mail_from_address'] ?? '',
                'mail_from_name' => $c['mail_from_name'] ?? '',
                'has_password' => ! empty($c['mail_password']),
                'configured' => Setting::mailConfigured(),
            ];
        });
    }

    /** Lowercase, strip scheme/path/port and a leading `www.`. */
    private function normalizeDomain(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = (string) preg_replace('#[/?].*$#', '', $value);
        $value = (string) preg_replace('/:\d+$/', '', $value);
        $value = trim($value, '.');

        return Str::startsWith($value, 'www.') ? substr($value, 4) : $value;
    }

    /** Why this host can't be mapped, or null when it is free. */
    private function domainError(string $host): ?string
    {
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return 'Enter a domain such as erp.acme.com.';
        }

        // The platform's own host and anything under the workspace root are
        // handed out by signup, not mapped by hand: allowing either here would
        // let one tenant claim another's address.
        $root = Str::lower(Subdomain::rootDomain());
        if ($host === $root || ($root !== '' && str_ends_with($host, '.'.$root))) {
            return "Addresses under {$root} are issued at signup, not mapped here.";
        }

        if (Domain::where('domain', $host)->exists()) {
            return 'That domain is already mapped to a workspace.';
        }

        return null;
    }

    /** @param array<string, mixed>|list<string> $properties */
    private function log(Request $request, Tenant $tenant, string $event, array $properties): void
    {
        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id, 'detail' => $properties])
            ->event($event)
            ->log("Operator changed white-label settings for tenant \"{$tenant->company_name}\"");
    }
}
