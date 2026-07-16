<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionTenant;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\PendingRegistration;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant Onboarding Wizard — the 7-step signup captures a config blob that
 * ProvisionTenant turns into a fully-configured tenant, and Step-3 module
 * selection gates the dynamic menu.
 */
class TenantOnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(PlanSeeder::class);
    }

    private function wizardConfig(): array
    {
        return [
            'industry' => 'Garment',
            'company' => [
                'gstin' => '24ABCDE1234F1Z5', 'pan' => 'ABCDE1234F',
                'address' => '12 Mill Road', 'city' => 'Surat', 'state' => 'Gujarat',
                'country' => 'India', 'pincode' => '395003',
            ],
            'modules' => ['crm', 'sales', 'accounts'],
            'users' => [
                ['name' => 'Sam Sales', 'email' => 'sam@acme.test', 'role' => 'sales-executive'],
            ],
            'branch' => ['name' => 'Surat HQ'],
            'warehouse' => ['name' => 'Ring Road Godown'],
            'financial_year' => ['name' => '2026-2027', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'],
            'fy_start_month' => 4,
            'currency' => ['code' => 'INR', 'symbol' => '₹'],
            'number_prefixes' => ['invoice_number_prefix' => 'BILL'],
            'tax' => ['gst_enabled' => true, 'default_gst_rate' => 12, 'gstin' => '24ABCDE1234F1Z5'],
            'load_demo' => false,
        ];
    }

    private function provisionWithConfig(array $config): Tenant
    {
        $reg = PendingRegistration::create([
            'company_name' => 'Acme Garments',
            'admin_name' => 'Jane Owner',
            'email' => 'owner@acme.test',
            'phone' => '+919000000000',
            'password' => 'password123',
            'plan_slug' => 'starter',
            'config' => $config,
            'status' => 'provisioning',
            'verified_at' => now(),
        ]);

        (new ProvisionTenant($reg->id))->handle();

        return Tenant::where('email', 'owner@acme.test')->firstOrFail();
    }

    public function test_provisioning_applies_the_wizard_company_profile(): void
    {
        $tenant = $this->provisionWithConfig($this->wizardConfig());

        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            $company = Company::where('code', 'MAIN')->firstOrFail();
            $this->assertSame('24ABCDE1234F1Z5', $company->gstin);
            $this->assertSame('ABCDE1234F', $company->pan);
            $this->assertSame('Surat', $company->city);
            $this->assertSame('INR', $company->currency_code);
            $this->assertSame(4, (int) $company->financial_year_start_month);

            $this->assertSame('Surat HQ', Branch::where('code', 'HO')->value('name'));
            $this->assertSame('Ring Road Godown', Warehouse::where('code', 'WH-MAIN')->value('name'));

            $this->assertTrue(FinancialYear::where('name', '2026-2027')->where('is_current', true)->exists());
        });
    }

    public function test_provisioning_writes_wizard_settings(): void
    {
        $tenant = $this->provisionWithConfig($this->wizardConfig());

        app(TenantContext::class)->runWith($tenant->id, function () {
            $get = fn (string $k) => Setting::where('key', $k)->value('value');

            $this->assertSame('Garment', $get('industry'));
            $this->assertSame('BILL', $get('invoice_number_prefix'));
            $this->assertSame('1', $get('gst_enabled'));
            $this->assertSame('12', $get('default_tax_rate'));
            $this->assertEqualsCanonicalizing(['crm', 'sales', 'accounts'], json_decode((string) $get('enabled_modules'), true));
        });
    }

    public function test_provisioning_creates_owner_and_invited_team(): void
    {
        $tenant = $this->provisionWithConfig($this->wizardConfig());

        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            $owner = User::where('email', 'owner@acme.test')->firstOrFail();
            $this->assertTrue($owner->hasRole('super-admin'));

            $sam = User::where('email', 'sam@acme.test')->firstOrFail();
            $this->assertTrue($sam->hasRole('sales-executive'));
        });
    }

    public function test_a_bare_signup_without_config_still_provisions(): void
    {
        $tenant = $this->provisionWithConfig([]);

        app(TenantContext::class)->runWith($tenant->id, function () {
            // Baseline seed untouched.
            $this->assertSame('Head Office', Branch::where('code', 'HO')->value('name'));
            $this->assertNull(Setting::where('key', 'enabled_modules')->value('value'));
        });
    }

    public function test_module_selection_gates_the_menu(): void
    {
        $tenant = Tenant::create(['company_name' => 'Menu Co', 'status' => 'active']);

        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            (new RolePermissionSeeder)->run();
            (new MenuSeeder)->run();

            // Only CRM enabled → Sales/Purchase/etc. hidden, untagged groups shown.
            Setting::updateOrCreate(['key' => 'enabled_modules'], ['value' => json_encode(['crm'])]);

            $owner = User::forceCreate([
                'name' => 'Owner', 'email' => 'menu-owner@acme.test',
                'password' => bcrypt('password'), 'is_active' => true, 'tenant_id' => $tenant->id,
            ]);
            $owner->assignRole('super-admin');

            Sanctum::actingAs($owner, ['*']);

            $menu = $this->getJson('/api/menu')->assertOk()->json('menu');
            $topLabels = collect($menu)->pluck('label');

            // Untagged umbrella groups always show. 'Sales' is now an untagged
            // umbrella and survives because it still has a visible child (CRM);
            // 'Purchase' carries the purchase module itself, so it disappears.
            $this->assertContains('Masters', $topLabels);
            $this->assertContains('Sales', $topLabels);
            $this->assertNotContains('Purchase', $topLabels);

            // Inside Sales only the CRM sub-group survives — the sales-module
            // sub-groups (Sales, Field Sales) are gated away.
            $salesChildren = collect(collect($menu)->firstWhere('label', 'Sales')['children'] ?? []);
            $this->assertContains('CRM', $salesChildren->pluck('label'));
            $this->assertNotContains('Sales', $salesChildren->pluck('label'));
            $this->assertNotContains('Field Sales', $salesChildren->pluck('label'));
        });
    }
}
