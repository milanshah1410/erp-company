<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
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

const route = useRoute()
const router = useRouter()

const q = ref((route.query.q as string) ?? '')
const groups = ref<Group[]>([])
const loading = ref(false)
const searched = ref(false)

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

async function load(term: string) {
  const trimmed = term.trim()
  if (trimmed.length < 2) {
    groups.value = []
    searched.value = false
    return
  }
  loading.value = true
  try {
    const { data } = await http.get('/search', { params: { q: trimmed, limit: 20 } })
    groups.value = data.groups ?? []
  } catch {
    groups.value = []
  } finally {
    loading.value = false
    searched.value = true
  }
}

function submit() {
  const trimmed = q.value.trim()
  if (trimmed.length < 2) return
  // Keep the URL in sync so the search is shareable / back-button friendly.
  router.replace({ name: 'search', query: { q: trimmed } })
  load(trimmed)
}

function openItem(type: string) {
  const to = routeMap[type]
  if (to) router.push(to)
}

// React to q changes arriving via the topbar (?q=...) while already on this page.
watch(
  () => route.query.q,
  (val) => {
    q.value = (val as string) ?? ''
    load(q.value)
  },
  { immediate: true },
)
</script>

<template>
  <div class="search-page">
    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="q" placeholder="Search products, customers, invoices…" @keyup.enter="submit" />
      </span>
      <Button label="Search" outlined @click="submit" />
    </div>

    <div v-if="loading" class="state">Searching…</div>

    <template v-else-if="searched">
      <div v-if="!groups.length" class="state">No results for “{{ q }}”.</div>

      <div v-for="g in groups" :key="g.type" class="result-group">
        <h3 class="group-title">
          <i :class="iconMap[g.type]" /> {{ g.label }}
          <span class="count">{{ g.total }}</span>
        </h3>
        <div class="cards">
          <button
            v-for="item in g.items"
            :key="g.type + '-' + item.id"
            class="result-card"
            :class="{ clickable: routeMap[g.type] }"
            @click="openItem(g.type)"
          >
            <div class="card-main">
              <span class="card-title">{{ item.title }}</span>
              <span v-if="item.subtitle" class="card-sub">{{ item.subtitle }}</span>
            </div>
            <span v-if="item.badge" class="card-badge">{{ item.badge }}</span>
          </button>
        </div>
      </div>
    </template>

    <div v-else class="state">Type at least 2 characters to search.</div>
  </div>
</template>

<style scoped>
.search-page {
  padding: 1.25rem;
}
.toolbar {
  display: flex;
  gap: 0.6rem;
  margin-bottom: 1.25rem;
}
.search {
  position: relative;
  flex: 1;
  max-width: 480px;
}
.search > .pi-search {
  position: absolute;
  left: 0.7rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  pointer-events: none;
}
.search :deep(input) {
  width: 100%;
  padding-left: 2rem;
}
.state {
  padding: 2rem;
  text-align: center;
  color: #6b7280;
}
.result-group {
  margin-bottom: 1.5rem;
}
.group-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.95rem;
  color: #2c4a5e;
  margin: 0 0 0.6rem;
}
.group-title .count {
  font-size: 0.72rem;
  font-weight: 600;
  color: #7a5a12;
  background: rgba(232, 163, 61, 0.18);
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 0.6rem;
}
.result-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  text-align: left;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.75rem 0.9rem;
  cursor: default;
}
.result-card.clickable {
  cursor: pointer;
}
.result-card.clickable:hover {
  border-color: #2c4a5e;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}
.card-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.card-title {
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.card-sub {
  font-size: 0.78rem;
  color: #6b7280;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.card-badge {
  flex-shrink: 0;
  font-size: 0.68rem;
  font-weight: 600;
  color: #7a5a12;
  background: rgba(232, 163, 61, 0.18);
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
  text-transform: capitalize;
}
</style>
