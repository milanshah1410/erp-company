<script setup lang="ts">
// Standard list/detail screen header: module eyebrow, title, subtitle, a
// record-count chip and a slot for the screen's primary actions. Styling lives
// in the shared listing design layer (src/styles/list-design.css), so screens
// that keep their plain `.header` markup still match visually.
//
// The eyebrow and icon default to the current screen's place in the dynamic
// nav (menu_items, §3) — "Masters", "Sales", … — so the common call is just:
//
//   <PageHeader :title="pt('Products')" :count="crud.total.value"
//               :countLabel="pt('products')">
//     <template #actions><Button ... /></template>
//   </PageHeader>
//
// Pass `eyebrow`/`icon` explicitly to override, or `eyebrow=""` to hide it.
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useMenuStore } from '../stores/menu'
import { useMenuLabel } from '../i18n/menuLabels'

const props = defineProps<{
  title: string
  eyebrow?: string
  icon?: string
  subtitle?: string
  count?: number | null
  countLabel?: string
  // Hide the barcode-stripe divider under the header.
  noDivider?: boolean
}>()

const route = useRoute()
const menu = useMenuStore()
const ml = useMenuLabel()

const moduleLabel = computed(() => {
  if (props.eyebrow !== undefined) return props.eyebrow
  const raw = menu.moduleByRoute[route.path]
  return raw ? ml(raw) : ''
})

const moduleIcon = computed(() => props.icon ?? menu.leavesByRoute[route.path]?.icon ?? '')

// "1,204 sales orders" — the label falls back to the screen title.
const chipLabel = computed(() => props.countLabel ?? props.title.toLowerCase())
const chipCount = computed(() => (props.count ?? 0).toLocaleString())
</script>

<template>
  <div>
    <div class="page-head">
      <div class="head-text">
        <span v-if="moduleLabel" class="eyebrow"><i v-if="moduleIcon" :class="moduleIcon" /> {{ moduleLabel }}</span>
        <h1>{{ title }}</h1>
        <p v-if="subtitle" class="page-sub">{{ subtitle }}</p>
      </div>
      <div class="head-actions">
        <span v-if="count !== undefined && count !== null" class="count-chip">
          <strong>{{ chipCount }}</strong> {{ chipLabel }}
        </span>
        <slot name="actions" />
      </div>
    </div>
    <div v-if="!noDivider" class="barcode-divider head-divider" />
  </div>
</template>

<style scoped>
.page-head {
  margin-bottom: 0;
}
.page-head h1 {
  margin: 0.35rem 0 0;
}
.head-divider {
  margin: 1rem 0 1.25rem;
}
</style>
