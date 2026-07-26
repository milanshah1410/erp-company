<script setup lang="ts">
// Dot + label status pill for table cells. Either pass `active` for the common
// boolean case, or `state` for workflow statuses:
//
//   <StatusPill :active="data.is_active" />
//   <StatusPill :label="data.status" :state="statusState(data.status)" />
import { computed } from 'vue'
import { usePt } from '../i18n/pt'

const props = defineProps<{
  label?: string
  active?: boolean
  state?: 'on' | 'off' | 'warn' | 'info' | 'muted'
}>()

const pt = usePt()

const stateClass = computed(() => {
  if (props.state) return `is-${props.state}`
  return props.active ? 'is-on' : 'is-off'
})

const text = computed(() => props.label ?? (props.active ? pt('Active') : pt('Inactive')))
</script>

<template>
  <span class="status-pill" :class="stateClass"><i class="dot" />{{ text }}</span>
</template>
