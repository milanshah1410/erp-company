<script setup lang="ts">
/**
 * The QSR counter queue.
 *
 * Cards, not a table: the person working this screen is standing at a hatch
 * reading it from arm's length, and a dense grid of rows is the wrong shape for
 * that. Waiting oldest-first, because the whole job is "who has been standing
 * there longest".
 *
 * The display mode strips everything except the numbers, so the same screen can
 * be thrown onto a second monitor facing the customers without showing them what
 * anyone ordered or paid.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
const toast = useToast()

const rows = ref<any[]>([])
const stats = ref({ issued: 0, called: 0, pending: 0, avg_minutes: 0 })
const loading = ref(false)
const mode = ref<'counter' | 'display'>('counter')

const modes = computed(() => [
  { label: pt('Counter'), value: 'counter' },
  { label: pt('Display'), value: 'display' },
])

/** The next state each card offers, so the button is one tap and never a menu. */
const NEXT: Record<string, string> = {
  waiting: 'preparing',
  preparing: 'ready',
  ready: 'called',
  called: 'collected',
}

const SEVERITY: Record<string, string> = {
  waiting: 'secondary',
  preparing: 'warn',
  ready: 'success',
  called: 'info',
}

const ready = computed(() => rows.value.filter((r) => ['ready', 'called'].includes(r.token_status)))
const working = computed(() => rows.value.filter((r) => !['ready', 'called'].includes(r.token_status)))

let timer: number | undefined

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/token-queue')
    rows.value = data.data
    stats.value = data.stats
  } finally {
    loading.value = false
  }
}

async function advance(row: any) {
  const to = NEXT[row.token_status]
  if (!to) return

  try {
    await http.post(`/token-queue/${row.id}/advance`, { status: to })
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Could not update the token'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  }
}

onMounted(() => {
  load()
  /**
   * Polled rather than pushed. A counter board is read by several devices at
   * once — the till, the hatch, the customer display — and ten seconds of lag on
   * a number is invisible to a person waiting for food, so this is not worth a
   * socket.
   */
  timer = window.setInterval(load, 10000)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Token Queue')"
      :subtitle="pt('Numbers restart every day, so they stay small enough to call across a room')"
    />

    <div class="toolbar">
      <SelectButton v-model="mode" :options="modes" option-label="label" option-value="value" />
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
    </div>

    <!-- Customer-facing: nothing but the numbers. -->
    <div v-if="mode === 'display'" class="display">
      <div class="display-head">{{ pt('Now serving') }}</div>
      <div v-if="ready.length" class="display-grid">
        <div v-for="row in ready" :key="row.id" class="display-token">
          {{ row.token_number }}
        </div>
      </div>
      <div v-else class="display-idle">{{ pt('Nothing ready just yet') }}</div>
    </div>

    <template v-else>
      <div class="tiles">
        <div class="tile">
          <span class="t-label">{{ pt('Issued today') }}</span>
          <span class="t-value">{{ stats.issued }}</span>
        </div>
        <div class="tile" :class="{ accent: stats.pending > 0 }">
          <span class="t-label">{{ pt('Still waiting') }}</span>
          <span class="t-value">{{ stats.pending }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Called') }}</span>
          <span class="t-value">{{ stats.called }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Avg wait') }}</span>
          <span class="t-value">{{ stats.avg_minutes }} {{ pt('min') }}</span>
        </div>
      </div>

      <h3 v-if="ready.length" class="section">{{ pt('Ready to hand over') }}</h3>
      <div v-if="ready.length" class="cards">
        <div v-for="row in ready" :key="row.id" class="card is-ready">
          <div class="card-head">
            <span class="token">{{ row.token_number }}</span>
            <Tag :value="pt(row.token_status)" :severity="SEVERITY[row.token_status]" />
          </div>
          <div class="items">
            <span v-for="(item, i) in row.items" :key="i" class="item">
              {{ item.quantity }}× {{ item.name }}
            </span>
          </div>
          <Button
            size="small"
            :label="pt(row.token_status === 'ready' ? 'Call' : 'Collected')"
            :icon="row.token_status === 'ready' ? 'pi pi-volume-up' : 'pi pi-check'"
            @click="advance(row)"
          />
        </div>
      </div>

      <h3 class="section">{{ pt('In the kitchen') }}</h3>
      <div class="cards">
        <div v-for="row in working" :key="row.id" class="card">
          <div class="card-head">
            <span class="token">{{ row.token_number }}</span>
            <Tag :value="pt(row.token_status)" :severity="SEVERITY[row.token_status]" />
          </div>
          <div class="meta">
            <span>{{ pt(row.order_type) }}</span>
            <span>{{ formatCurrency(row.total) }}</span>
            <span :class="{ late: row.waiting_minutes > 10 }">
              {{ row.waiting_minutes }} {{ pt('min') }}
            </span>
          </div>
          <div class="items">
            <span v-for="(item, i) in row.items" :key="i" class="item">
              {{ item.quantity }}× {{ item.name }}
            </span>
          </div>
          <Button
            size="small"
            outlined
            :label="pt(row.token_status === 'waiting' ? 'Start' : 'Ready')"
            @click="advance(row)"
          />
        </div>

        <div v-if="!working.length && !ready.length" class="empty-state">
          {{ pt('No tokens have been issued today.') }}
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.section { margin: 1.25rem 0 0.6rem; font-size: 0.95rem; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.75rem; }
.card { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.8rem; display: flex; flex-direction: column; gap: 0.5rem; }
.card.is-ready { border-color: #16a34a; background: rgba(22, 163, 74, 0.06); }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.token { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.meta { display: flex; gap: 0.6rem; font-size: 0.75rem; color: #6b7280; flex-wrap: wrap; }
.meta .late { color: #dc2626; font-weight: 600; }
.items { display: flex; flex-direction: column; gap: 0.15rem; font-size: 0.8rem; }
.item { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; grid-column: 1 / -1; }
.display { padding: 1rem 0; }
.display-head { text-align: center; font-size: 1.1rem; color: #6b7280; margin-bottom: 1rem; }
.display-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
.display-token { border: 2px solid #16a34a; border-radius: 14px; padding: 1.5rem 0; text-align: center; font-size: 3.5rem; font-weight: 800; color: #16a34a; }
.display-idle { text-align: center; padding: 3rem 0; font-size: 1.2rem; color: #9ca3af; }
</style>
