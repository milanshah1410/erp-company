<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import http from '../api/http'

interface Live {
  salesman_id: number
  code: string
  name: string
  location: {
    recorded_at: string
    latitude: number
    longitude: number
    battery: number | null
    speed: number | null
  } | null
}

const rows = ref<Live[]>([])
const loading = ref(false)
const lastRefreshed = ref<Date | null>(null)
const autoRefresh = ref(false)
let timer: number | undefined

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salesman-locations/live')
    rows.value = data.data ?? []
    lastRefreshed.value = new Date()
  } finally {
    loading.value = false
  }
}

function ago(iso: string | undefined): string {
  if (!iso) return '—'
  const then = new Date(iso).getTime()
  if (isNaN(then)) return '—'
  const mins = Math.round((Date.now() - then) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs} hr ago`
  return `${Math.round(hrs / 24)} d ago`
}

// A ping within the last 15 minutes counts as "online".
function isOnline(iso: string | undefined): boolean {
  if (!iso) return false
  return Date.now() - new Date(iso).getTime() < 15 * 60000
}

function mapsUrl(lat: number, lng: number): string {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
}

function setAuto(on: boolean) {
  autoRefresh.value = on
  if (timer) {
    clearInterval(timer)
    timer = undefined
  }
  if (on) {
    timer = window.setInterval(load, 30000)
  }
}

onMounted(load)
onBeforeUnmount(() => timer && clearInterval(timer))
</script>

<template>
  <div>
    <div class="header">
      <h1>Live Location Tracking</h1>
      <div class="header-actions">
        <label class="auto"><ToggleSwitch :modelValue="autoRefresh" @update:modelValue="setAuto" /> Auto-refresh (30s)</label>
        <Button label="Refresh" icon="pi pi-refresh" :loading="loading" outlined @click="load" />
      </div>
    </div>

    <p v-if="lastRefreshed" class="refreshed">Last updated {{ lastRefreshed.toLocaleTimeString() }}</p>

    <div v-if="rows.length" class="grid">
      <div v-for="r in rows" :key="r.salesman_id" class="card" :class="{ offline: !isOnline(r.location?.recorded_at) }">
        <div class="card-top">
          <div class="who">
            <span class="name">{{ r.name }}</span>
            <span class="code">{{ r.code }}</span>
          </div>
          <Tag :severity="isOnline(r.location?.recorded_at) ? 'success' : 'secondary'"
            :value="isOnline(r.location?.recorded_at) ? 'Online' : 'Offline'" />
        </div>

        <div v-if="r.location" class="loc">
          <div class="loc-row"><i class="pi pi-map-marker" /> {{ r.location.latitude }}, {{ r.location.longitude }}</div>
          <div class="loc-row"><i class="pi pi-clock" /> {{ ago(r.location.recorded_at) }}</div>
          <div class="loc-meta">
            <span v-if="r.location.battery != null"><i class="pi pi-bolt" /> {{ r.location.battery }}%</span>
            <span v-if="r.location.speed != null"><i class="pi pi-forward" /> {{ Number(r.location.speed).toFixed(0) }} km/h</span>
          </div>
          <a :href="mapsUrl(r.location.latitude, r.location.longitude)" target="_blank" rel="noopener" class="map-link">
            <i class="pi pi-external-link" /> Open in Maps
          </a>
        </div>
        <div v-else class="no-loc">No location reported yet.</div>
      </div>
    </div>

    <p v-else-if="!loading" class="empty">No active salesmen to track.</p>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; align-items: center; gap: 1rem; }
.auto { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #475569; }
.refreshed { color: #94a3b8; font-size: 0.8rem; margin: 0 0 1rem; }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}
.card {
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem;
  background: var(--surface, #fff);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.card.offline { opacity: 0.72; }
.card-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 0.75rem;
}
.who { display: flex; flex-direction: column; }
.name { font-weight: 600; color: #1e293b; }
.code { font-size: 0.78rem; color: #94a3b8; }
.loc { display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.88rem; color: #334155; }
.loc-row { display: flex; align-items: center; gap: 0.5rem; }
.loc-row i { color: #6366f1; }
.loc-meta { display: flex; gap: 1rem; color: #64748b; font-size: 0.82rem; }
.loc-meta i { margin-right: 0.25rem; }
.map-link {
  margin-top: 0.35rem;
  font-size: 0.85rem;
  color: #4338ca;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.map-link:hover { text-decoration: underline; }
.no-loc, .empty { color: #94a3b8; font-size: 0.88rem; }
.empty { text-align: center; padding: 3rem 0; }
</style>
