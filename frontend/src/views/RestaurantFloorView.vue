<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import { useRouter } from 'vue-router'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const router = useRouter()

const canSeat = computed(() => auth.can('restaurant-orders.create'))
const canSetStatus = computed(() => auth.can('restaurant-tables.edit'))

const sections = ref<any[]>([])
const summary = ref({ total: 0, free: 0, occupied: 0, cleaning: 0, reserved: 0 })
const loading = ref(false)
const waiters = ref<any[]>([])

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/restaurant/floor')
    sections.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadWaiters() {
  try {
    const { data } = await http.get('/employees', { params: { per_page: 200, is_active: true } })
    waiters.value = (data.data ?? data).map((x: any) => ({
      label: [x.first_name, x.last_name].filter(Boolean).join(' ') || x.employee_code,
      value: x.id,
    }))
  } catch {
    waiters.value = []
  }
}

// A floor screen is left open all shift; without a refresh it goes stale the
// moment another waiter seats a table.
let timer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await Promise.all([load(), loadWaiters()])
  timer = setInterval(load, 15000)
})
onBeforeUnmount(() => timer && clearInterval(timer))

// ---- Seating ---------------------------------------------------------------------

const seatVisible = ref(false)
const seatTable = ref<any>(null)
const seatForm = ref({ guest_count: 2, waiter_id: null as number | null, service_charge_percent: 0 })
const seating = ref(false)

function openSeat(table: any) {
  if (!canSeat.value || !table.is_seatable) return
  seatTable.value = table
  seatForm.value = { guest_count: Math.min(2, table.capacity), waiter_id: null, service_charge_percent: 0 }
  seatVisible.value = true
}

async function seat() {
  seating.value = true
  try {
    const { data } = await http.post('/restaurant-orders', {
      order_type: 'dine_in',
      restaurant_table_id: seatTable.value.id,
      ...seatForm.value,
    })
    seatVisible.value = false
    toast.add({ severity: 'success', summary: pt('Table seated'), life: 2000 })
    router.push({ path: '/restaurant/orders', query: { order: data.data.id } })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.restaurant_table_id?.[0]
        ?? e.response?.data?.message
        ?? pt('Could not seat the table'),
      life: 4000,
    })
    await load()
  } finally {
    seating.value = false
  }
}

async function setStatus(table: any, status: string) {
  try {
    await http.post(`/restaurant-tables/${table.id}/status`, { status })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4000 })
  }
}

function openTicket(table: any) {
  if (table.order) router.push({ path: '/restaurant/orders', query: { order: table.order.id } })
}
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Floor')"
      :subtitle="pt('Every table and what is sitting at it — tap a free table to seat a party')"
    />

    <div class="tiles">
      <div class="tile"><span class="t-label">{{ pt('Tables') }}</span><strong class="t-value">{{ summary.total }}</strong></div>
      <div class="tile free"><span class="t-label">{{ pt('Free') }}</span><strong class="t-value">{{ summary.free }}</strong></div>
      <div class="tile busy"><span class="t-label">{{ pt('Occupied') }}</span><strong class="t-value">{{ summary.occupied }}</strong></div>
      <div class="tile"><span class="t-label">{{ pt('Cleaning') }}</span><strong class="t-value">{{ summary.cleaning }}</strong></div>
      <Button icon="pi pi-refresh" :label="pt('Refresh')" outlined class="refresh" :loading="loading" @click="load" />
    </div>

    <div v-for="sec in sections" :key="sec.section" class="section">
      <h3>{{ sec.section }}</h3>
      <div class="tables">
        <div
          v-for="t in sec.tables"
          :key="t.id"
          class="table-card"
          :class="t.status"
          @click="t.order ? openTicket(t) : openSeat(t)"
        >
          <div class="tc-head">
            <strong>{{ t.name }}</strong>
            <span class="tc-cap"><i class="pi pi-users" /> {{ t.capacity }}</span>
          </div>

          <template v-if="t.order">
            <span class="tc-order">{{ t.order.order_number }}</span>
            <span class="tc-total">{{ money(t.order.total) }}</span>
            <span class="tc-meta">
              {{ t.order.guest_count }} {{ pt('guests') }}
              <template v-if="t.order.seated_minutes !== null"> · {{ t.order.seated_minutes }}m</template>
            </span>
            <span v-if="t.order.waiter" class="tc-meta">{{ t.order.waiter }}</span>
          </template>
          <template v-else>
            <span class="tc-status">{{ pt(t.status) }}</span>
            <!-- A cleaning table is one tap from being ready again; that tap is
                 the most common thing a floor manager does. -->
            <Button
              v-if="canSetStatus && t.status === 'cleaning'"
              :label="pt('Ready')"
              size="small"
              text
              @click.stop="setStatus(t, 'free')"
            />
            <Button
              v-else-if="canSetStatus && t.status === 'free'"
              :label="pt('Reserve')"
              size="small"
              text
              @click.stop="setStatus(t, 'reserved')"
            />
            <Button
              v-else-if="canSetStatus && t.status === 'reserved'"
              :label="pt('Release')"
              size="small"
              text
              @click.stop="setStatus(t, 'free')"
            />
          </template>
        </div>
      </div>
    </div>

    <p v-if="!sections.length && !loading" class="empty">
      {{ pt('No tables yet — add them under Restaurant → Tables & Sections.') }}
    </p>

    <Dialog
      v-model:visible="seatVisible"
      modal
      :header="seatTable ? pt('Seat table {name}', { name: seatTable.name }) : pt('Seat table')"
      :style="{ width: '24rem' }"
    >
      <div class="fld">
        <label>{{ pt('Guests') }}</label>
        <InputNumber v-model="seatForm.guest_count" :min="1" :max="100" showButtons class="w-full" />
      </div>
      <div class="fld">
        <label>{{ pt('Waiter') }}</label>
        <Select v-model="seatForm.waiter_id" :options="waiters" optionLabel="label" optionValue="value" filter showClear class="w-full" />
      </div>
      <div class="fld">
        <label>{{ pt('Service charge %') }}</label>
        <InputNumber v-model="seatForm.service_charge_percent" :min="0" :max="100" suffix=" %" class="w-full" />
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="seatVisible = false" />
        <Button :label="pt('Seat')" icon="pi pi-check" :loading="seating" @click="seat" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1.25rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.6rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; min-width: 110px; }
.tile.free { border-color: #4c8b6c; background: rgba(76, 139, 108, 0.08); }
.tile.busy { border-color: #e8a33d; background: rgba(232, 163, 61, 0.1); }
.t-label { font-size: 0.72rem; color: #6b7280; }
.t-value { font-size: 1.3rem; font-weight: 700; }
.refresh { margin-left: auto; }
.section { margin-bottom: 1.5rem; }
.section h3 { font-size: 0.95rem; font-weight: 600; margin: 0 0 0.6rem; color: #374151; }
/* Big tap targets: this screen is used on a tablet, standing up. */
.tables { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; }
.table-card {
  border: 2px solid #d8d3c8; border-radius: 12px; padding: 0.7rem 0.8rem;
  display: flex; flex-direction: column; gap: 0.2rem; cursor: pointer;
  background: #fbfaf7; min-height: 110px; transition: transform 0.08s ease;
}
.table-card:hover { transform: translateY(-2px); }
.table-card.free { border-color: #4c8b6c; background: rgba(76, 139, 108, 0.07); }
.table-card.occupied { border-color: #e8a33d; background: rgba(232, 163, 61, 0.1); }
.table-card.reserved { border-color: #2c4a5e; background: rgba(44, 74, 94, 0.07); }
.table-card.cleaning { border-color: #b0a99b; background: #f4f2ee; }
.tc-head { display: flex; align-items: center; justify-content: space-between; }
.tc-head strong { font-size: 1.1rem; }
.tc-cap { font-size: 0.72rem; color: #6b7280; display: inline-flex; align-items: center; gap: 0.2rem; }
.tc-order { font-size: 0.75rem; color: #7a5b1c; font-weight: 600; }
.tc-total { font-size: 1.05rem; font-weight: 700; }
.tc-meta { font-size: 0.72rem; color: #6b7280; }
.tc-status { font-size: 0.8rem; color: #6b7280; text-transform: capitalize; margin-top: auto; }
.empty { color: #6b7280; font-size: 0.9rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.75rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
</style>
