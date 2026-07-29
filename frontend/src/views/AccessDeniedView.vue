<script setup lang="ts">
// Where the router lands a navigation to a screen this workspace is not granted.
//
// Not the same thing as SuspendedView (whole workspace locked) or a missing
// permission on the user's role: this is the platform operator having switched
// the screen's module off, or revoked the screen itself, on /platform/tenants.
// It renders inside the app shell on purpose — the nav stays usable, so the user
// can go somewhere they do have.
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'

const route = useRoute()
const router = useRouter()

const attempted = computed(() => (route.query.from as string) || '')
</script>

<template>
  <div class="denied">
    <div class="badge"><i class="pi pi-lock" /></div>
    <h1>Access denied</h1>
    <p class="sub">
      This screen is not enabled for your workspace.
      <template v-if="attempted">
        <br />
        <code>{{ attempted }}</code>
      </template>
    </p>
    <p class="hint">
      Your platform administrator controls which modules and screens this
      workspace can use. Ask them to enable it if you need access.
    </p>
    <Button label="Back to dashboard" icon="pi pi-home" @click="router.push('/')" />
  </div>
</template>

<style scoped>
.denied {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.75rem;
  padding: 3.5rem 1.5rem;
}
.badge {
  width: 3.25rem;
  height: 3.25rem;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 18%, transparent);
  color: #8a5a12;
  font-size: 1.35rem;
}
h1 {
  font-size: 1.35rem;
  font-weight: 600;
}
.sub {
  color: var(--muted, #6b7280);
  font-size: 0.92rem;
  line-height: 1.55;
}
.sub code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.82rem;
  color: var(--graphite, #1e2328);
}
.hint {
  max-width: 32rem;
  color: var(--muted, #6b7280);
  font-size: 0.85rem;
  line-height: 1.55;
}
</style>
