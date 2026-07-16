<script setup lang="ts">
import { useRouteProgress } from '../composables/useRouteProgress'

// Global top loading bar for lazy route-chunk fetches. Fixed to the viewport
// top, above all app chrome. Purely presentational — state lives in the shared
// useRouteProgress() store, toggled by the router navigation guards.
const { active, progress } = useRouteProgress()
</script>

<template>
  <div class="route-progress" :class="{ 'is-active': active }" aria-hidden="true">
    <div class="route-progress__bar" :style="{ width: progress + '%' }" />
  </div>
</template>

<style scoped>
.route-progress {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  z-index: 9999;
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}
.route-progress.is-active {
  opacity: 1;
}
.route-progress__bar {
  height: 100%;
  width: 0;
  background: linear-gradient(90deg, var(--brand, #c79a3a), #e6c15f);
  box-shadow: 0 0 8px rgba(199, 154, 58, 0.6);
  transition: width 0.18s ease;
}
</style>
