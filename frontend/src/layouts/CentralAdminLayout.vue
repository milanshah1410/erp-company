<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, RouterLink, RouterView } from 'vue-router'
import Button from 'primevue/button'
import Toast from 'primevue/toast'
import { useCentralAuthStore } from '../stores/centralAuth'

const router = useRouter()
const auth = useCentralAuthStore()

interface NavItem {
  label: string
  icon: string
  to: string
  permission?: string
}

// The platform panel navigation. Items are hidden unless the operator holds the
// gating permission (super-admin implicitly holds all).
const adminNav: NavItem[] = [
  { label: 'Dashboard', icon: 'pi pi-th-large', to: '/platform' },
  { label: 'Tenants', icon: 'pi pi-building', to: '/platform/tenants', permission: 'tenants.view' },
  { label: 'Billing', icon: 'pi pi-credit-card', to: '/platform/billing', permission: 'plans.view' },
  { label: 'Support', icon: 'pi pi-comments', to: '/platform/support', permission: 'support.view' },
  { label: 'Operators', icon: 'pi pi-users', to: '/platform/operators', permission: 'operators.view' },
  { label: 'Roles & Permissions', icon: 'pi pi-shield', to: '/platform/roles', permission: 'roles.view' },
  { label: 'Ops', icon: 'pi pi-server', to: '/platform/ops', permission: 'ops-health.view' },
]

const visibleAdminNav = computed(() => adminNav.filter((i) => !i.permission || auth.can(i.permission)))

const initials = computed(() => {
  const name = auth.operator?.name ?? '?'
  return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()
})

async function logout() {
  await auth.logout()
  router.push({ name: 'central-login' })
}
</script>

<template>
  <div class="shell">
    <aside class="sidebar">
      <div class="brand">
        <i class="pi pi-sitemap" />
        <div class="brand-text">
          <span class="brand-title">Platform</span>
          <span class="brand-sub">SaaS Operations</span>
        </div>
      </div>

      <nav class="nav">
        <RouterLink
          v-for="item in visibleAdminNav"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ 'exact-active': $route.path === item.to }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </aside>

    <div class="main">
      <header class="topbar">
        <div class="spacer" />
        <div class="account">
          <div class="who">
            <span class="who-name">{{ auth.operator?.name }}</span>
            <span class="who-roles">{{ auth.operator?.roles.join(', ') }}</span>
          </div>
          <div class="avatar">{{ initials }}</div>
          <Button icon="pi pi-sign-out" text rounded severity="secondary" title="Log out" @click="logout" />
        </div>
      </header>

      <main class="content">
        <RouterView />
      </main>
    </div>

    <Toast />
  </div>
</template>

<style scoped>
.shell { min-height: 100vh; display: flex; background: #f6f7f9; }
.sidebar {
  width: 244px; flex-shrink: 0; background: #1f2937; color: #e5e7eb;
  display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh;
}
.brand { display: flex; align-items: center; gap: 0.65rem; padding: 1.15rem 1.25rem; border-bottom: 1px solid #374151; }
.brand i { font-size: 1.5rem; color: #e8a33d; }
.brand-text { display: flex; flex-direction: column; line-height: 1.15; }
.brand-title { font-weight: 700; font-size: 1.05rem; }
.brand-sub { font-size: 0.72rem; color: #9ca3af; }
.nav { display: flex; flex-direction: column; gap: 0.15rem; padding: 0.75rem; overflow-y: auto; }
.nav-item {
  display: flex; align-items: center; gap: 0.7rem; padding: 0.6rem 0.75rem; border-radius: 8px;
  color: #d1d5db; text-decoration: none; font-size: 0.92rem; font-weight: 500;
}
.nav-item i { width: 1.1rem; text-align: center; }
.nav-item:hover { background: #374151; color: #fff; }
.nav-item.exact-active { background: #e8a33d; color: #1f2937; }
.main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar {
  display: flex; align-items: center; gap: 1rem; padding: 0.75rem 1.5rem;
  background: #fff; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 10;
}
.spacer { flex: 1; }
.account { display: flex; align-items: center; gap: 0.75rem; }
.who { display: flex; flex-direction: column; text-align: right; line-height: 1.2; }
.who-name { font-weight: 600; font-size: 0.9rem; }
.who-roles { font-size: 0.72rem; color: #6b7280; text-transform: capitalize; }
.avatar {
  width: 36px; height: 36px; border-radius: 50%; background: #2c4a5e; color: #fff;
  display: grid; place-items: center; font-size: 0.8rem; font-weight: 700;
}
.content { padding: 1.5rem; flex: 1; max-width: 1400px; width: 100%; }
</style>
