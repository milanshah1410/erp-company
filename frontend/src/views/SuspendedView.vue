<script setup lang="ts">
// Landing page for a workspace hard-locked by a SaaS-operator suspension.
// The http interceptor redirects here whenever the API answers 403 with
// error=tenant_suspended, and the router guard keeps suspended sessions from
// navigating back into the app (every API call would fail anyway).
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import BrandMark from '../components/BrandMark.vue'
import { useBranding } from '../composables/useBranding'

const router = useRouter()
const auth = useAuthStore()
// White label: a branded workspace can suppress the platform credit line
// (Volume 11 §"White Label" — the `brand_powered_by` setting).
const { branding } = useBranding()

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="suspended">
    <div class="panel">
      <div class="badge"><i class="pi pi-lock" /></div>
      <h1>Workspace suspended</h1>
      <p class="sub">
        This workspace has been suspended by the platform administrator.
        Access to your data is temporarily disabled.
      </p>
      <p class="sub">
        If you believe this is a mistake, please contact support to have your
        workspace reviewed and reactivated.
      </p>
      <button class="go" type="button" @click="signOut">
        <i class="pi pi-sign-out" /> Sign out
      </button>

      <p v-if="branding.powered_by" class="by"><BrandMark :size="16" /> kosha</p>
    </div>
  </div>
</template>

<style scoped>
.suspended {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2c4a5e 0%, #1e2328 65%, #171a1e 100%);
  padding: 1.5rem;
}
.panel {
  background: #fff;
  border-radius: 16px;
  padding: 2.5rem;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
  text-align: center;
}
.badge {
  width: 60px;
  height: 60px;
  margin: 0 auto 1rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #d66a45, #c1502e);
  font-size: 1.6rem;
  color: #fff;
}
h1 {
  font-size: 1.35rem;
}
.sub {
  color: #6b7280;
  font-size: 0.9rem;
  margin-top: 0.6rem;
}
.go {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1.5rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  cursor: pointer;
  border-radius: 8px;
  padding: 0.7rem 1.4rem;
  font-weight: 700;
  font-size: 0.9rem;
}
.by {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  margin: 1.75rem 0 0;
  padding-top: 1.25rem;
  border-top: 1px solid #eceae4;
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-weight: 700;
  font-size: 0.85rem;
  letter-spacing: -0.01em;
  color: #9ca3af;
}
</style>
