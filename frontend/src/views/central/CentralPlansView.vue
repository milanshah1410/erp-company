<script setup lang="ts">
// Subscription Plans is its own platform surface (it used to be a tab inside
// Billing). The plan catalogue is edited far more often than the billing
// ledgers, so it gets a top-level nav entry of its own.
import PlansPanel from './billing/PlansPanel.vue'
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Subscription Plans</h1>
        <p class="sub">The plan catalogue — pricing, billing cycle &amp; per-plan limits.</p>
      </div>
    </div>

    <PlansPanel />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.88rem; margin-top: 0.15rem; }
</style>
