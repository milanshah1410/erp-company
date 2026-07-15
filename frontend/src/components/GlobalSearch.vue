<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useDebounceFn, onClickOutside } from '@vueuse/core'
import InputText from 'primevue/inputtext'
import http from '../api/http'

interface ResultItem {
  id: number
  title: string
  subtitle: string
  badge: string | null
}
interface Group {
  type: string
  label: string
  total: number
  items: ResultItem[]
}

const router = useRouter()

const q = ref('')
const groups = ref<Group[]>([])
const loading = ref(false)
const open = ref(false)
const rootEl = ref<HTMLElement | null>(null)

const hasResults = computed(() => groups.value.some((g) => g.items.length > 0))

// Products/customers deep-link to their module; sales invoices have no
// standalone list page, so those clicks fall through to the results view.
const routeMap: Record<string, string | null> = {
  products: '/products',
  customers: '/customers',
  invoices: null,
}
const iconMap: Record<string, string> = {
  products: 'pi pi-box',
  customers: 'pi pi-users',
  invoices: 'pi pi-file',
}

const runSearch = useDebounceFn(async () => {
  const term = q.value.trim()
  if (term.length < 2) {
    groups.value = []
    open.value = false
    return
  }
  loading.value = true
  open.value = true
  try {
    const { data } = await http.get('/search', { params: { q: term, limit: 5 } })
    groups.value = data.groups ?? []
  } catch {
    groups.value = []
  } finally {
    loading.value = false
  }
}, 300)

function onFocus() {
  if (q.value.trim().length >= 2) open.value = true
}

function submitAll() {
  const term = q.value.trim()
  if (term.length < 2) return
  open.value = false
  router.push({ name: 'search', query: { q: term } })
}

function goToItem(type: string, _item: ResultItem) {
  open.value = false
  const to = routeMap[type]
  if (to) router.push(to)
  else submitAll()
}

onClickOutside(rootEl, () => {
  open.value = false
})
</script>

<template>
  <div ref="rootEl" class="global-search">
    <span class="gs-input">
      <i class="pi pi-search" />
      <InputText
        v-model="q"
        placeholder="Search products, customers, invoices…"
        @input="runSearch"
        @focus="onFocus"
        @keyup.enter="submitAll"
        @keyup.escape="open = false"
      />
    </span>

    <div v-if="open" class="gs-dropdown">
      <div v-if="loading" class="gs-state">Searching…</div>
      <template v-else-if="hasResults">
        <div v-for="g in groups" :key="g.type" class="gs-group">
          <div class="gs-group-head"><i :class="iconMap[g.type]" /> {{ g.label }}</div>
          <button
            v-for="item in g.items"
            :key="g.type + '-' + item.id"
            class="gs-item"
            @click="goToItem(g.type, item)"
          >
            <span class="gs-item-body">
              <span class="gs-item-title">{{ item.title }}</span>
              <span v-if="item.subtitle" class="gs-item-sub">{{ item.subtitle }}</span>
            </span>
            <span v-if="item.badge" class="gs-badge">{{ item.badge }}</span>
          </button>
        </div>
        <button class="gs-viewall" @click="submitAll">View all results →</button>
      </template>
      <div v-else class="gs-state">No matches for “{{ q }}”.</div>
    </div>
  </div>
</template>

<style scoped>
.global-search {
  position: relative;
  width: min(340px, 32vw);
}
.gs-input {
  position: relative;
  display: block;
}
.gs-input > .pi-search {
  position: absolute;
  left: 0.7rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  font-size: 0.85rem;
  pointer-events: none;
}
.gs-input :deep(input) {
  width: 100%;
  padding-left: 2rem;
  height: 2.1rem;
  border-radius: 8px;
  font-size: 0.85rem;
}
.gs-dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
  z-index: 1200;
  max-height: 70vh;
  overflow-y: auto;
  padding: 0.35rem;
}
.gs-state {
  padding: 1rem;
  text-align: center;
  color: #6b7280;
  font-size: 0.85rem;
}
.gs-group + .gs-group {
  border-top: 1px solid #f3f4f6;
  margin-top: 0.25rem;
  padding-top: 0.25rem;
}
.gs-group-head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.6rem 0.25rem;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #2c4a5e;
}
.gs-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  width: 100%;
  border: none;
  background: none;
  text-align: left;
  cursor: pointer;
  padding: 0.45rem 0.6rem;
  border-radius: 6px;
}
.gs-item:hover {
  background: rgba(44, 74, 94, 0.08);
}
.gs-item-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.gs-item-title {
  font-size: 0.85rem;
  color: #111827;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gs-item-sub {
  font-size: 0.75rem;
  color: #6b7280;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gs-badge {
  flex-shrink: 0;
  font-size: 0.68rem;
  font-weight: 600;
  color: #7a5a12;
  background: rgba(232, 163, 61, 0.18);
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
  text-transform: capitalize;
}
.gs-viewall {
  width: 100%;
  border: none;
  background: none;
  cursor: pointer;
  color: #2c4a5e;
  font-weight: 600;
  font-size: 0.8rem;
  padding: 0.55rem;
  border-top: 1px solid #f3f4f6;
  margin-top: 0.25rem;
}
.gs-viewall:hover {
  background: rgba(44, 74, 94, 0.06);
}
</style>
