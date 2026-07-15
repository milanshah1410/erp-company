<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, RouterLink, RouterView } from 'vue-router'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import { usePortalAuthStore } from '../stores/portalAuth'
import { usePortalCartStore } from '../stores/portalCart'

const router = useRouter()
const auth = usePortalAuthStore()
const cart = usePortalCartStore()

const businessName = computed(() => auth.customer?.business_name || 'B2B Portal')

async function logout() {
  await auth.logout()
  cart.clear()
  router.push({ name: 'portal-login' })
}
</script>

<template>
  <div class="portal">
    <header class="topbar">
      <div class="brand">
        <i class="pi pi-shopping-bag" />
        <span>{{ businessName }}</span>
      </div>

      <nav class="links">
        <RouterLink :to="{ name: 'portal-catalogue' }" class="link">Catalogue</RouterLink>
        <RouterLink :to="{ name: 'portal-price-list' }" class="link">Price List</RouterLink>
        <RouterLink :to="{ name: 'portal-orders' }" class="link">My Orders</RouterLink>
        <RouterLink :to="{ name: 'portal-cart' }" class="link cart-link">
          <i class="pi pi-shopping-cart" />
          <Badge v-if="cart.count" :value="cart.count" severity="contrast" />
        </RouterLink>
      </nav>

      <div class="account">
        <div class="who">
          <span class="who-name">{{ auth.customer?.name }}</span>
          <span class="who-type">{{ auth.customer?.type }}</span>
        </div>
        <Button icon="pi pi-sign-out" text rounded severity="secondary" title="Log out" @click="logout" />
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.portal { min-height: 100vh; background: #f6f7f9; }
.topbar {
  display: flex; align-items: center; gap: 1.5rem;
  padding: 0.75rem 1.5rem; background: #fff; border-bottom: 1px solid #e5e7eb;
  position: sticky; top: 0; z-index: 10;
}
.brand { display: flex; align-items: center; gap: 0.5rem; font-weight: 700; font-size: 1.1rem; color: #b45309; }
.brand i { font-size: 1.3rem; }
.links { display: flex; gap: 0.5rem; margin-left: 1rem; }
.link {
  padding: 0.5rem 0.85rem; border-radius: 8px; color: #374151; text-decoration: none;
  font-weight: 500; font-size: 0.92rem; display: inline-flex; align-items: center; gap: 0.35rem;
}
.link:hover { background: #f3f4f6; }
.link.router-link-exact-active { background: #fef3c7; color: #b45309; }
.cart-link { position: relative; }
.account { margin-left: auto; display: flex; align-items: center; gap: 0.75rem; }
.who { display: flex; flex-direction: column; text-align: right; line-height: 1.2; }
.who-name { font-weight: 600; font-size: 0.9rem; }
.who-type { font-size: 0.72rem; color: #6b7280; text-transform: capitalize; }
.content { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
</style>
