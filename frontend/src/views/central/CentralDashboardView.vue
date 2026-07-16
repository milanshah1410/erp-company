<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import Tag from 'primevue/tag'
import { useCentralAuthStore } from '../../stores/centralAuth'

const router = useRouter()
const auth = useCentralAuthStore()

const operator = computed(() => auth.operator)

// The four Level-1 control surfaces. Each shows whether this operator has
// access (via a representative permission). The admin surfaces below link to
// live pages; the business surfaces are gated placeholders until their APIs ship.
const surfaces = [
  { key: 'tenants', label: 'Tenant Management', icon: 'pi pi-building', permission: 'tenants.view',
    desc: 'Create, suspend & impersonate tenant workspaces.' },
  { key: 'billing', label: 'Billing & Subscriptions', icon: 'pi pi-credit-card', permission: 'plans.view',
    desc: 'Plans, subscriptions, invoices, refunds & revenue.' },
  { key: 'support', label: 'Support & Customer Success', icon: 'pi pi-comments', permission: 'support.view',
    desc: 'Tenant health, tickets & troubleshooting.' },
  { key: 'ops', label: 'Ops (DevOps / Developer)', icon: 'pi pi-server', permission: 'ops-health.view',
    desc: 'System health, queues, backups, logs & flags.' },
]

const adminCards = computed(() => [
  { label: 'Operators', icon: 'pi pi-users', to: '/platform/operators', permission: 'operators.view',
    desc: 'Manage platform operators and their roles.' },
  { label: 'Roles & Permissions', icon: 'pi pi-shield', to: '/platform/roles', permission: 'roles.view',
    desc: 'Review the 11 platform roles and tune permissions.' },
].filter((c) => auth.can(c.permission)))

function go(to: string) {
  router.push(to)
}
</script>

<template>
  <div>
    <div class="hero">
      <div>
        <h1>Welcome, {{ operator?.name }}</h1>
        <p class="sub">Platform operations console</p>
      </div>
      <div class="roles">
        <Tag v-for="role in operator?.roles" :key="role" :value="role" severity="warn" class="role-tag" />
      </div>
    </div>

    <h2 class="section-title">Control surfaces</h2>
    <div class="grid">
      <div v-for="s in surfaces" :key="s.key" class="card surface" :class="{ locked: !auth.can(s.permission) }">
        <div class="card-icon"><i :class="s.icon" /></div>
        <div class="card-body">
          <div class="card-head">
            <h3>{{ s.label }}</h3>
            <Tag :value="auth.can(s.permission) ? 'Enabled' : 'No access'"
              :severity="auth.can(s.permission) ? 'success' : 'secondary'" class="access-tag" />
          </div>
          <p>{{ s.desc }}</p>
          <span class="soon-note">Panel coming soon</span>
        </div>
      </div>
    </div>

    <template v-if="adminCards.length">
      <h2 class="section-title">Platform administration</h2>
      <div class="grid">
        <button v-for="c in adminCards" :key="c.label" class="card admin" @click="go(c.to)">
          <div class="card-icon accent"><i :class="c.icon" /></div>
          <div class="card-body">
            <div class="card-head"><h3>{{ c.label }}</h3><i class="pi pi-arrow-right go" /></div>
            <p>{{ c.desc }}</p>
          </div>
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.hero {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
}
.hero h1 { font-size: 1.5rem; font-weight: 700; }
.sub { color: #6b7280; font-size: 0.9rem; margin-top: 0.15rem; }
.roles { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.role-tag { text-transform: capitalize; }
.section-title { font-size: 1.05rem; font-weight: 600; margin: 0.5rem 0 0.85rem; color: #374151; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
.card {
  display: flex; gap: 1rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.1rem;
  text-align: left; width: 100%;
}
.card.surface.locked { opacity: 0.6; }
.card.admin { cursor: pointer; transition: box-shadow 0.15s, transform 0.15s; font: inherit; color: inherit; }
.card.admin:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); border-color: #e8a33d; }
.card-icon {
  width: 44px; height: 44px; flex-shrink: 0; border-radius: 10px; background: #f3f4f6; color: #2c4a5e;
  display: grid; place-items: center; font-size: 1.25rem;
}
.card-icon.accent { background: #fdf7ec; color: #c98730; }
.card-body { min-width: 0; flex: 1; }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head h3 { font-size: 0.98rem; font-weight: 600; }
.card-body p { color: #6b7280; font-size: 0.83rem; margin-top: 0.25rem; }
.access-tag :deep(.p-tag-label), .access-tag { font-size: 0.68rem; }
.soon-note { display: inline-block; margin-top: 0.55rem; font-size: 0.7rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; }
.go { color: #c98730; }
</style>
