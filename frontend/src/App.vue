<script setup lang="ts">
import Toast from 'primevue/toast'
import RouteProgress from './components/RouteProgress.vue'
</script>

<template>
  <RouteProgress />
  <RouterView />
  <Toast position="top-right" />
</template>
