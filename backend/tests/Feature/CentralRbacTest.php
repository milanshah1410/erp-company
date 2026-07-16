<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CentralUser;
use Database\Seeders\CentralRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SaaS-owner RBAC — Level 1 (platform operators on the `central` guard).
 */
class CentralRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Central roles/permissions live at team_id = NULL.
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);
        $this->seed(CentralRolePermissionSeeder::class);
    }

    private function operator(string $role): CentralUser
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);
        $operator = CentralUser::factory()->create();
        $operator->assignRole($role);

        return $operator;
    }

    public function test_the_eleven_level_one_roles_are_seeded(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);

        $roles = \Spatie\Permission\Models\Role::where('guard_name', 'central')->pluck('name')->all();

        foreach ([
            'super-admin', 'saas-admin', 'support-executive', 'billing-executive',
            'sales-executive', 'customer-success', 'devops', 'developer',
            'finance', 'marketing', 'auditor',
        ] as $expected) {
            $this->assertContains($expected, $roles);
        }
    }

    public function test_bootstrap_super_admin_can_log_in_and_receives_permissions(): void
    {
        $response = $this->postJson('/api/central/login', [
            'email' => 'owner@saas.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']])
            ->assertJsonPath('user.roles', ['super-admin']);

        $this->assertContains('tenants.impersonate', $response->json('user.permissions'));
        $this->assertContains('roles.edit', $response->json('user.permissions'));
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->postJson('/api/central/login', [
            'email' => 'owner@saas.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_inactive_operator_is_blocked_by_context_middleware(): void
    {
        $operator = CentralUser::factory()->inactive()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);
        $operator->assignRole('super-admin');

        Sanctum::actingAs($operator, ['*'], 'central');

        $this->getJson('/api/central/me')->assertStatus(403)
            ->assertJsonPath('error', 'operator_inactive');
    }

    public function test_super_admin_can_list_operators(): void
    {
        Sanctum::actingAs($this->operator('super-admin'), ['*'], 'central');

        $this->getJson('/api/central/operators')->assertOk();
    }

    public function test_billing_executive_cannot_view_operators(): void
    {
        Sanctum::actingAs($this->operator('billing-executive'), ['*'], 'central');

        $this->getJson('/api/central/operators')->assertStatus(403);
    }

    public function test_finance_role_is_read_only_on_billing(): void
    {
        $finance = $this->operator('finance');
        $this->assertTrue($finance->hasPermissionTo('invoices.view'));
        $this->assertFalse($finance->hasPermissionTo('invoices.refund'));
    }

    public function test_super_admin_creates_operator_with_role(): void
    {
        Sanctum::actingAs($this->operator('super-admin'), ['*'], 'central');

        $this->postJson('/api/central/operators', [
            'name' => 'Support Person',
            'email' => 'support@saas.test',
            'password' => 'secret123',
            'roles' => ['support-executive'],
        ])->assertCreated()->assertJsonPath('data.roles', ['support-executive']);
    }

    public function test_non_super_admin_cannot_grant_super_admin(): void
    {
        // saas-admin holds operators.create but must not be able to escalate.
        Sanctum::actingAs($this->operator('saas-admin'), ['*'], 'central');

        $this->postJson('/api/central/operators', [
            'name' => 'Sneaky',
            'email' => 'sneaky@saas.test',
            'password' => 'secret123',
            'roles' => ['super-admin'],
        ])->assertStatus(403);
    }

    public function test_super_admin_can_delete_another_operator_but_not_self(): void
    {
        $actor = $this->operator('super-admin');
        $victim = $this->operator('support-executive');
        Sanctum::actingAs($actor, ['*'], 'central');

        $this->deleteJson("/api/central/operators/{$victim->id}")->assertOk();
        $this->assertDatabaseMissing('central_users', ['id' => $victim->id]);

        // Self-deletion is refused so an operator can't lock themselves out.
        $this->deleteJson("/api/central/operators/{$actor->id}")->assertStatus(422);
    }

    public function test_saas_admin_cannot_delete_a_super_admin(): void
    {
        // saas-admin holds operators.delete but must not touch a super-admin.
        Sanctum::actingAs($this->operator('saas-admin'), ['*'], 'central');
        $bootstrap = CentralUser::where('email', 'owner@saas.test')->firstOrFail();

        $this->deleteJson("/api/central/operators/{$bootstrap->id}")->assertStatus(403);
        $this->assertDatabaseHas('central_users', ['id' => $bootstrap->id]);
    }

    public function test_only_super_admin_can_retune_role_permissions(): void
    {
        Sanctum::actingAs($this->operator('saas-admin'), ['*'], 'central');
        $role = \Spatie\Permission\Models\Role::where('guard_name', 'central')->where('name', 'marketing')->firstOrFail();

        // saas-admin lacks roles.edit.
        $this->patchJson("/api/central/roles/{$role->id}", ['permissions' => ['marketing.view']])
            ->assertStatus(403);
    }

    public function test_tenant_context_leak_does_not_expose_central_permissions(): void
    {
        // Simulate a stale tenant team id from a prior request: the context
        // middleware must reset it to NULL so the check still resolves.
        Sanctum::actingAs($this->operator('super-admin'), ['*'], 'central');
        app(PermissionRegistrar::class)->setPermissionsTeamId(999);

        $this->getJson('/api/central/operators')->assertOk();
    }
}
