<script setup lang="ts">
/**
 * The hire fleet, and how hard it is working.
 *
 * Two menu rows onto one component, told apart by the route — the same shape the
 * toy shop's classification screens use. The register and the utilisation report
 * are one book to whoever keeps them: the person who decides what a bike costs
 * by the day is the person who wants to know whether it has earned that back.
 *
 * Whether a piece of kit is *out* is not stored anywhere; it is derived from the
 * contracts on every read. So the board says who has it and when it is promised
 * back rather than showing a flag that would go stale the first time anything
 * failed halfway through.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import ProgressBar from 'primevue/progressbar'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const isUtilisation = computed(() => route.path.endsWith('/utilisation'))

const canCreate = computed(() => auth.can('rental-assets.create'))
const canEdit = computed(() => auth.can('rental-assets.edit'))
const canDelete = computed(() => auth.can('rental-assets.delete'))

const CONDITIONS = ['new', 'good', 'fair', 'worn', 'damaged']
const STORED_STATUSES = [
  { label: 'On the rack', value: 'available' },
  { label: 'On the bench', value: 'maintenance' },
  { label: 'Written off', value: 'retired' },
]

// ---- The register ---------------------------------------------------------

const rows = ref<any[]>([])
const summary = ref({ fleet: 0, on_hire: 0, booked: 0, free_now: 0, on_bench: 0, retired: 0 })
const loading = ref(false)
const search = ref('')
const categoryFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)

const categories = computed(() =>
  [...new Set(rows.value.map((r) => r.category).filter(Boolean))].map((c) => ({ label: c, value: c })),
)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/sports/rental-assets', {
      params: {
        search: search.value || undefined,
        category: categoryFilter.value || undefined,
        status: statusFilter.value || undefined,
        per_page: 200,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

// ---- Utilisation ----------------------------------------------------------

const util = ref<any[]>([])
const utilLoading = ref(false)
const period = ref<Date[]>([])

async function loadUtilisation() {
  utilLoading.value = true
  try {
    const [from, to] = period.value ?? []
    const { data } = await http.get('/sports/rental-assets/utilisation', {
      params: { from: asDate(from), to: asDate(to) },
    })
    util.value = data.data
  } finally {
    utilLoading.value = false
  }
}

const utilTotals = computed(() => ({
  earned: util.value.reduce((s, r) => s + Number(r.earned ?? 0), 0),
  hires: util.value.reduce((s, r) => s + Number(r.hires ?? 0), 0),
  idle: util.value.filter((r) => Number(r.hires ?? 0) === 0).length,
}))

// ---- Add / edit kit -------------------------------------------------------

const formVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blank())
const editingId = ref<number | null>(null)
const warehouses = ref<any[]>([])
const brands = ref<any[]>([])

function blank() {
  return {
    asset_code: '',
    name: '',
    category: '',
    brand_id: null,
    warehouse_id: null,
    serial_no: '',
    rate_hourly: null as number | null,
    rate_daily: null as number | null,
    rate_weekly: null as number | null,
    rate_monthly: null as number | null,
    deposit_amount: 0,
    late_fee_percent: 100,
    requires_id_proof: false,
    condition: 'good',
    status: 'available',
    purchase_cost: null as number | null,
    acquired_on: null as Date | null,
    notes: '',
    is_active: true,
  }
}

async function loadPickers() {
  const [w, b] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/brands', { params: { per_page: 200 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  brands.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  formVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/sports/rental-assets/${row.id}`)
  const d = data.data
  editingId.value = d.id
  errors.value = {}
  form.value = {
    asset_code: d.asset_code,
    name: d.name,
    category: d.category ?? '',
    brand_id: d.brand_id,
    warehouse_id: d.warehouse_id,
    serial_no: d.serial_no ?? '',
    rate_hourly: d.rates.hourly,
    rate_daily: d.rates.daily,
    rate_weekly: d.rates.weekly,
    rate_monthly: d.rates.monthly,
    deposit_amount: Number(d.deposit_amount ?? 0),
    late_fee_percent: d.late_fee_percent ?? 100,
    requires_id_proof: !!d.requires_id_proof,
    condition: d.condition,
    status: d.stored_status,
    purchase_cost: d.purchase_cost,
    acquired_on: d.acquired_on ? new Date(d.acquired_on) : null,
    notes: d.notes ?? '',
    is_active: !!d.is_active,
  }
  formVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, acquired_on: asDate(form.value.acquired_on) }
    editingId.value
      ? await http.put(`/sports/rental-assets/${editingId.value}`, payload)
      : await http.post('/sports/rental-assets', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Kit updated' : 'Kit added to the fleet'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the kit'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove from the fleet?'),
    text: pt('Kit that has been out on hire is retired instead — deleting it would take its earnings and its history with it.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/sports/rental-assets/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

/** Straight to the bench and back — the two states the register is edited for. */
async function setStatus(row: any, status: string) {
  try {
    await http.put(`/sports/rental-assets/${row.id}`, {
      asset_code: row.asset_code,
      name: row.name,
      status,
      // The rate card has to come along: the request insists on at least one
      // rate, and a partial update would look like kit priced at nothing.
      rate_hourly: row.rates.hourly,
      rate_daily: row.rates.daily,
      rate_weekly: row.rates.weekly,
      rate_monthly: row.rates.monthly,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

// ---- Presentation ---------------------------------------------------------

function statusTag(row: any) {
  switch (row.status) {
    case 'on_hire': return { label: pt('Out'), severity: 'warn' }
    case 'booked': return { label: pt('Booked'), severity: 'secondary' }
    case 'maintenance': return { label: pt('On the bench'), severity: 'danger' }
    case 'retired': return { label: pt('Written off'), severity: 'contrast' }
    default: return { label: pt('On the rack'), severity: 'success' }
  }
}

function rateSummary(row: any) {
  const parts: string[] = []
  if (row.rates.hourly != null) parts.push(`${formatCurrency(row.rates.hourly)}/${pt('hr')}`)
  if (row.rates.daily != null) parts.push(`${formatCurrency(row.rates.daily)}/${pt('day')}`)
  if (row.rates.weekly != null) parts.push(`${formatCurrency(row.rates.weekly)}/${pt('wk')}`)
  if (row.rates.monthly != null) parts.push(`${formatCurrency(row.rates.monthly)}/${pt('mo')}`)
  return parts.join(' · ')
}

function asDate(v: any): string | undefined {
  if (!v) return undefined
  const d = v instanceof Date ? v : new Date(v)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

const exportColumns: ExportColumn[] = [
  { key: 'asset_code', label: 'Code' },
  { key: 'name', label: 'Kit' },
  { key: 'category', label: 'Category' },
  { key: 'condition', label: 'Condition' },
  { key: 'deposit_amount', label: 'Deposit' },
  { key: 'status', label: 'Status' },
  { key: 'held_by', label: 'With' },
  { key: 'due_back', label: 'Due back' },
]

const utilColumns: ExportColumn[] = [
  { key: 'asset_code', label: 'Code' },
  { key: 'name', label: 'Kit' },
  { key: 'hires', label: 'Hires' },
  { key: 'days_out', label: 'Days out' },
  { key: 'utilisation_percent', label: 'Utilisation %' },
  { key: 'earned', label: 'Earned' },
  { key: 'cost_recovered_percent', label: 'Cost recovered %' },
]

watch(isUtilisation, (on) => (on ? loadUtilisation() : load()))

onMounted(() => {
  isUtilisation.value ? loadUtilisation() : load()
  loadPickers()
})
</script>

<template>
  <div>
    <!-- ===================== Utilisation ===================== -->
    <template v-if="isUtilisation">
      <PageHeader
        :title="pt('Fleet Utilisation')"
        :subtitle="pt('How hard each piece of kit is working, and whether it has paid for itself')"
      />

      <div class="toolbar">
        <DatePicker
          v-model="period"
          selection-mode="range"
          date-format="dd/mm/yy"
          show-icon
          :placeholder="pt('Last 90 days')"
          @date-select="loadUtilisation"
        />
        <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="loadUtilisation" />
        <div class="spacer" />
        <TableExportButtons
          :rows="util"
          :columns="utilColumns"
          filename="fleet-utilisation"
          :title="pt('Fleet Utilisation')"
        />
      </div>

      <div class="tiles">
        <div class="tile">
          <span class="t-label">{{ pt('Earned in the period') }}</span>
          <span class="t-value">{{ formatCurrency(utilTotals.earned) }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Hires') }}</span>
          <span class="t-value">{{ utilTotals.hires }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Kit that never went out') }}</span>
          <span class="t-value" :class="{ danger: utilTotals.idle > 0 }">{{ utilTotals.idle }}</span>
          <small class="muted">{{ pt('the rows worth arguing about') }}</small>
        </div>
      </div>

      <DataTable :value="util" :loading="utilLoading" paginator :rows="25" data-key="asset_id" size="small" striped-rows>
        <Column field="asset_code" :header="pt('Code')" sortable />
        <Column field="name" :header="pt('Kit')" sortable>
          <template #body="{ data }">
            <div>{{ data.name }}</div>
            <small class="muted">{{ data.category }}</small>
          </template>
        </Column>
        <Column field="hires" :header="pt('Hires')" sortable />
        <Column field="days_out" :header="pt('Days out')" sortable />
        <Column field="utilisation_percent" :header="pt('Utilisation')" sortable>
          <template #body="{ data }">
            <ProgressBar :value="data.utilisation_percent" :show-value="false" style="height: 0.5rem" />
            <small class="muted">{{ data.utilisation_percent }}%</small>
          </template>
        </Column>
        <Column field="earned" :header="pt('Earned')" sortable>
          <template #body="{ data }">{{ formatCurrency(data.earned) }}</template>
        </Column>
        <Column field="cost_recovered_percent" :header="pt('Cost recovered')" sortable>
          <template #body="{ data }">
            <span v-if="data.cost_recovered_percent == null" class="muted">—</span>
            <span v-else :class="{ good: data.cost_recovered_percent >= 100 }">
              {{ data.cost_recovered_percent }}%
            </span>
          </template>
        </Column>
        <Column field="status" :header="pt('Status')">
          <template #body="{ data }">
            <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
          </template>
        </Column>

        <template #empty>
          <div class="empty-state">
            {{ pt('Nothing came back inside this period. Utilisation counts settled returns only — a hire still running has not earned anything yet.') }}
          </div>
        </template>
      </DataTable>
    </template>

    <!-- ===================== The fleet ===================== -->
    <template v-else>
      <PageHeader :title="pt('Hire Fleet')" :subtitle="pt('What the shop owns, what it costs to hire, and where it is')">
        <template #actions>
          <Button v-if="canCreate" :label="pt('Add Kit')" icon="pi pi-plus" @click="openCreate" />
        </template>
      </PageHeader>

      <div class="tiles">
        <div class="tile">
          <span class="t-label">{{ pt('Free now') }}</span>
          <span class="t-value">{{ summary.free_now }}</span>
          <small class="muted">{{ pt('of') }} {{ summary.fleet }}</small>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Out') }}</span>
          <span class="t-value">{{ summary.on_hire }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Held on bookings') }}</span>
          <span class="t-value">{{ summary.booked }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('On the bench') }}</span>
          <span class="t-value" :class="{ danger: summary.on_bench > 0 }">{{ summary.on_bench }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Written off') }}</span>
          <span class="t-value">{{ summary.retired }}</span>
        </div>
      </div>

      <div class="toolbar">
        <InputText v-model="search" :placeholder="pt('Search code, name or serial')" @keyup.enter="load" />
        <Select
          v-model="categoryFilter"
          :options="categories"
          option-label="label"
          option-value="value"
          showClear
          :placeholder="pt('Category')"
          @change="load"
        />
        <Select
          v-model="statusFilter"
          :options="['available', 'booked', 'on_hire', 'maintenance', 'retired']"
          showClear
          :placeholder="pt('Status')"
          @change="load"
        />
        <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
        <div class="spacer" />
        <TableExportButtons :rows="rows" :columns="exportColumns" filename="hire-fleet" :title="pt('Hire Fleet')" />
      </div>

      <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
        <Column field="asset_code" :header="pt('Code')" sortable />
        <Column field="name" :header="pt('Kit')" sortable>
          <template #body="{ data }">
            <div>{{ data.name }}</div>
            <small class="muted">
              {{ [data.category, data.brand, data.variant].filter(Boolean).join(' · ') }}
            </small>
          </template>
        </Column>
        <Column :header="pt('Rates')">
          <template #body="{ data }">
            <div class="small">{{ rateSummary(data) }}</div>
            <small class="muted">{{ pt('Deposit') }} {{ formatCurrency(data.deposit_amount) }}</small>
          </template>
        </Column>
        <Column field="condition" :header="pt('Condition')" sortable>
          <template #body="{ data }">{{ pt(data.condition) }}</template>
        </Column>
        <Column field="status" :header="pt('Status')" sortable>
          <template #body="{ data }">
            <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
            <div v-if="data.held_by" class="muted small">
              {{ data.held_by }} · {{ pt('due') }} {{ new Date(data.due_back).toLocaleDateString() }}
            </div>
          </template>
        </Column>
        <Column :header="pt('Actions')" style="width: 12rem">
          <template #body="{ data }">
            <Button
              v-if="canEdit && data.status === 'available'"
              icon="pi pi-wrench"
              text
              rounded
              severity="warn"
              :title="pt('Send to the bench')"
              @click="setStatus(data, 'maintenance')"
            />
            <Button
              v-if="canEdit && data.status === 'maintenance'"
              icon="pi pi-check-circle"
              text
              rounded
              severity="success"
              :title="pt('Back on the rack')"
              @click="setStatus(data, 'available')"
            />
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
            <Button
              v-if="canDelete"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              :title="pt('Remove')"
              @click="remove(data)"
            />
          </template>
        </Column>

        <template #empty>
          <div class="empty-state">
            {{ pt('No hire kit yet. Add a piece and it becomes bookable — the fleet is what the desk hires out, and it is deliberately not stock.') }}
          </div>
        </template>
      </DataTable>
    </template>

    <!-- Add / edit -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Edit Kit') : pt('Add Kit')"
      :style="{ width: '820px' }"
    >
      <Message severity="info" :closable="false" class="mb">
        {{ pt('Hire kit is not stock. It goes out and comes back, so it lives here with its own rates and condition rather than on the shelf.') }}
      </Message>

      <div class="grid-form">
        <div class="fld">
          <label>{{ pt('Asset code') }} *</label>
          <InputText v-model="form.asset_code" class="w-full" :placeholder="pt('SPB-07')" />
          <small class="hint">{{ pt('What is painted on it, or on the tag tied to it.') }}</small>
          <small v-if="errors.asset_code" class="err">{{ errors.asset_code[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Description') }} *</label>
          <InputText v-model="form.name" class="w-full" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Category') }}</label>
          <InputText v-model="form.category" class="w-full" :placeholder="pt('Cycles, Racquets, Camping…')" />
        </div>
        <div class="fld">
          <label>{{ pt('Brand') }}</label>
          <Select
            v-model="form.brand_id"
            :options="brands"
            option-label="label"
            option-value="value"
            filter
            showClear
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Branch') }}</label>
          <Select
            v-model="form.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            showClear
            class="w-full"
          />
          <small class="hint">{{ pt('Availability is answered per branch — kit across town is not free here.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Serial / frame no.') }}</label>
          <InputText v-model="form.serial_no" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Rates') }}</label>
          <small class="hint">
            {{ pt('Leave a basis empty if the kit is not hired that way — nobody hires a treadmill by the hour, and an empty rate stops it being booked that way by mistake.') }}
          </small>
          <div class="rate-row">
            <div>
              <small>{{ pt('Per hour') }}</small>
              <InputNumber v-model="form.rate_hourly" :min="0" class="w-full" />
            </div>
            <div>
              <small>{{ pt('Per day') }}</small>
              <InputNumber v-model="form.rate_daily" :min="0" class="w-full" />
            </div>
            <div>
              <small>{{ pt('Per week') }}</small>
              <InputNumber v-model="form.rate_weekly" :min="0" class="w-full" />
            </div>
            <div>
              <small>{{ pt('Per month') }}</small>
              <InputNumber v-model="form.rate_monthly" :min="0" class="w-full" />
            </div>
          </div>
          <small v-if="errors.rate_daily" class="err">{{ errors.rate_daily[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Deposit') }}</label>
          <InputNumber v-model="form.deposit_amount" :min="0" class="w-full" />
          <small class="hint">{{ pt('Held against the kit coming back. Never counted as income until it is kept.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Late rate (% of normal)') }}</label>
          <InputNumber v-model="form.late_fee_percent" :min="100" :max="1000" class="w-full" />
          <small class="hint">{{ pt('150 means a late day costs one and a half.') }}</small>
          <small v-if="errors.late_fee_percent" class="err">{{ errors.late_fee_percent[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Condition') }}</label>
          <Select v-model="form.condition" :options="CONDITIONS" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Where it is') }}</label>
          <Select
            v-model="form.status"
            :options="STORED_STATUSES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
          <small class="hint">{{ pt('Out on hire is worked out from the contracts — it is not set here.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('What it cost') }}</label>
          <InputNumber v-model="form.purchase_cost" :min="0" class="w-full" />
          <small class="hint">{{ pt('Only used to say whether it has earned itself back.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Bought on') }}</label>
          <DatePicker v-model="form.acquired_on" date-format="dd/mm/yy" show-icon class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Needs ID to hand over') }}</label>
          <div class="switch-row">
            <ToggleSwitch v-model="form.requires_id_proof" />
            <small class="hint">{{ pt('The handover refuses without it.') }}</small>
          </div>
        </div>
        <div class="fld">
          <label>{{ pt('In the fleet') }}</label>
          <div class="switch-row">
            <ToggleSwitch v-model="form.is_active" />
            <small class="hint">{{ pt('Off takes it out of every picker.') }}</small>
          </div>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="formVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.switch-row { display: flex; gap: 0.6rem; align-items: center; }
.rate-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.rate-row small { font-size: 0.72rem; color: #6b7280; }

.muted { color: #6b7280; }
.good { color: #15803d; font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.small { font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
