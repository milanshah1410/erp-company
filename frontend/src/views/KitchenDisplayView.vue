<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canWork = computed(() => auth.can('kitchen-orders.edit'))

const tickets = ref<any[]>([])
const summary = ref({ active: 0, by_station: {} as Record<string, number>, by_status: {} as Record<string, number> })
const loading = ref(false)

const STATIONS = [
  { label: 'All', value: null },
  { label: 'Kitchen', value: 'kitchen' },
  { label: 'Tandoor', value: 'tandoor' },
  { label: 'Chinese', value: 'chinese' },
  { label: 'Bar', value: 'bar' },
  { label: 'Dessert', value: 'dessert' },
]
const station = ref<string | null>(null)

// What each state offers next. The pass calls ready; the floor calls served.
const NEXT: Record<string, { status: string; label: string; severity: string }> = {
  pending: { status: 'preparing', label: 'Start', severity: 'secondary' },
  preparing: { status: 'ready', label: 'Ready', severity: 'warn' },
  ready: { status: 'served', label: 'Served', severity: 'success' },
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/kitchen-orders', {
      params: { station: station.value || undefined, per_page: 100 },
    })
    tickets.value = data.data.data ?? data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function advance(ticket: any) {
  const next = NEXT[ticket.status]
  if (!next) return
  try {
    await http.post(`/kitchen-orders/${ticket.id}/status`, { status: next.status })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4000 })
  }
}

/** A docket that has been waiting too long should be impossible to miss. */
function urgency(minutes: number | null) {
  if (minutes === null) return ''
  if (minutes >= 20) return 'late'
  if (minutes >= 10) return 'warn'
  return ''
}

// A kitchen screen is never touched; it has to refresh itself.
let timer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await load()
  timer = setInterval(load, 10000)
})
onBeforeUnmount(() => timer && clearInterval(timer))
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Kitchen Display')"
      :subtitle="pt('Oldest docket first — the only question a kitchen asks')"
    />

    <div class="bar">
      <SelectButton v-model="station" :options="STATIONS" optionLabel="label" optionValue="value" @change="load" />
      <span class="count">{{ summary.active }} {{ pt('active') }}</span>
      <Button icon="pi pi-refresh" text :loading="loading" @click="load" />
    </div>

    <div class="board">
      <div
        v-for="t in tickets"
        :key="t.id"
        class="kot"
        :class="[t.status, urgency(t.waiting_minutes)]"
      >
        <div class="kot-head">
          <strong>{{ t.kot_number }}</strong>
          <Tag :value="t.station" severity="secondary" />
        </div>
        <div class="kot-meta">
          <span>{{ t.table ? pt('Table') + ' ' + t.table : pt(t.order_type ?? '') }}</span>
          <span v-if="t.waiting_minutes !== null" class="wait">{{ t.waiting_minutes }}m</span>
        </div>

        <ul class="kot-items">
          <li v-for="i in t.items" :key="i.id" :class="{ voided: i.status === 'void' }">
            <span class="qty">{{ i.quantity }}×</span>
            <span>{{ i.name }}</span>
            <small v-if="i.instructions" class="instr">{{ i.instructions }}</small>
          </li>
        </ul>

        <Button
          v-if="canWork && NEXT[t.status]"
          :label="pt(NEXT[t.status].label)"
          :severity="NEXT[t.status].severity"
          size="small"
          class="kot-action"
          @click="advance(t)"
        />
        <Tag v-else :value="t.status" class="kot-action" />
      </div>
    </div>

    <p v-if="!tickets.length && !loading" class="empty">
      {{ pt('Nothing waiting. The kitchen is clear.') }}
    </p>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.count { font-size: 0.85rem; color: #6b7280; margin-left: auto; }
/* Cards, not a table: a cook reads this from a few feet away. */
.board { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.85rem; }
.kot {
  border: 2px solid #d8d3c8; border-radius: 12px; padding: 0.75rem 0.85rem;
  background: #fbfaf7; display: flex; flex-direction: column; gap: 0.35rem;
}
.kot.preparing { border-color: #2c4a5e; background: rgba(44, 74, 94, 0.06); }
.kot.ready { border-color: #4c8b6c; background: rgba(76, 139, 108, 0.1); }
.kot.warn { border-color: #e8a33d; }
.kot.late { border-color: #b91c1c; background: rgba(185, 28, 28, 0.07); }
.kot-head { display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; }
.kot-head strong { font-size: 0.95rem; }
.kot-meta { display: flex; justify-content: space-between; font-size: 0.75rem; color: #6b7280; }
.wait { font-weight: 700; }
.kot.late .wait { color: #b91c1c; }
.kot-items { list-style: none; margin: 0.3rem 0 0; padding: 0; }
.kot-items li { padding: 0.15rem 0; font-size: 0.92rem; display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: baseline; }
.kot-items li.voided { opacity: 0.5; text-decoration: line-through; }
.qty { font-weight: 700; }
.instr { width: 100%; font-size: 0.72rem; color: #7a5b1c; }
.kot-action { margin-top: auto; align-self: flex-start; }
.empty { color: #6b7280; font-size: 0.95rem; text-align: center; padding: 2rem 0; }
</style>
