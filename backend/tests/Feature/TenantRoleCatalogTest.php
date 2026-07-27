<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TenantRoleCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Level-2 (Tenant / Company) departmental RBAC — project-stack.md.
 */
class TenantRoleCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Global tenant roles/permissions live at team_id = NULL.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->seed(RolePermissionSeeder::class);
    }

    private function role(string $name): Role
    {
        return Role::where('guard_name', 'web')->where('name', $name)->firstOrFail();
    }

    private function permsOf(string $role): array
    {
        return $this->role($role)->permissions->pluck('name')->all();
    }

    public function test_every_department_role_is_seeded(): void
    {
        foreach (TenantRoleCatalog::departments() as $department => $slugs) {
            foreach ($slugs as $slug) {
                $this->assertDatabaseHas('roles', ['name' => $slug, 'guard_name' => 'web'], 'sqlite');
            }
        }
    }

    public function test_the_five_pre_existing_roles_are_reused_not_duplicated(): void
    {
        foreach (['sales-manager', 'warehouse-manager', 'purchase-manager', 'accountant', 'auditor'] as $slug) {
            $this->assertSame(1, Role::where('guard_name', 'web')->where('name', $slug)->count());
        }
    }

    public function test_owner_md_ceo_hold_every_permission(): void
    {
        $total = Permission::count();
        foreach (['company-owner', 'managing-director', 'ceo'] as $slug) {
            $this->assertSame($total, $this->role($slug)->permissions()->count(), "$slug should hold all permissions");
        }
    }

    public function test_manager_approves_but_executive_only_contributes(): void
    {
        // CRM manager approves sales; sales executive can create but not approve.
        $this->assertContains('sales.approve', $this->permsOf('crm-manager'));
        $this->assertContains('sales.create', $this->permsOf('sales-executive'));
        $this->assertNotContains('sales.approve', $this->permsOf('sales-executive'));
    }

    public function test_operators_get_view_and_record_but_not_delete(): void
    {
        $machine = $this->permsOf('machine-operator');
        $this->assertContains('job-works.create', $machine);
        $this->assertNotContains('manufacturing-orders.delete', $machine);
        $this->assertNotContains('manufacturing-orders.approve', $machine);
    }

    public function test_hr_manager_can_approve_leave_and_payroll_area(): void
    {
        $hr = $this->permsOf('hr-manager');
        $this->assertContains('leave-applications.approve', $hr);
        $this->assertContains('payroll.create', $hr);
        $this->assertContains('salary-advances.approve', $hr);
    }

    public function test_view_only_modules_never_gain_write_permissions(): void
    {
        // 'accounts' is view-only; finance-manager references it but must only view.
        $fin = $this->permsOf('finance-manager');
        $this->assertContains('accounts.view', $fin);
        $this->assertNotContains('accounts.edit', $fin);
        $this->assertNotContains('accounts.create', $fin);
    }

    public function test_import_export_module_roles_all_reach_shipments(): void
    {
        // The seven roles the Import & Export Logistics module names. Five sit
        // in the Import & Export department; warehouse-manager and
        // transport-coordinator serve it from Inventory / Transport.
        foreach ([
            'logistics-manager', 'import-executive', 'export-executive',
            'documentation-executive', 'cha-executive',
            'warehouse-manager', 'transport-coordinator',
        ] as $slug) {
            $this->assertContains('shipments.view', $this->permsOf($slug), "$slug should see shipments");
        }
    }

    public function test_only_the_cha_desk_files_customs(): void
    {
        $this->assertContains('shipment-customs.edit', $this->permsOf('cha-executive'));
        $this->assertNotContains('shipment-customs.edit', $this->permsOf('documentation-executive'));
        $this->assertNotContains('shipment-customs.view', $this->permsOf('transport-coordinator'));
    }

    public function test_department_lookup(): void
    {
        $this->assertSame('Manufacturing', TenantRoleCatalog::departmentOf('tailor'));
        $this->assertSame('Finance', TenantRoleCatalog::departmentOf('cashier'));
        $this->assertSame('System', TenantRoleCatalog::departmentOf('super-admin'));
        $this->assertNull(TenantRoleCatalog::departmentOf('some-custom-role'));
    }

    public function test_expand_skips_unsupported_actions(): void
    {
        // brokers is not approvable; 'BROKER:a' must not yield brokers.approve.
        $expanded = TenantRoleCatalog::expand(['brokers:a']);
        $this->assertContains('brokers.delete', $expanded);
        $this->assertNotContains('brokers.approve', $expanded);
    }
}
