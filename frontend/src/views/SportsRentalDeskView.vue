<script setup lang="ts">
/**
 * The hire desk.
 *
 * A board to be worked, not a register to be browsed. The default view is
 * everything still out or booked with the soonest promise first, overdue rows in
 * red, and the four tiles that matter are "out", "overdue", "back today" and the
 * deposits the shop is holding — rather than a lifetime count of contracts.
 *
 * Two rules of the trade are visible in the screen. Money is quoted *live*: a
 * hire that is out has a bill that grows every hour, so the card shows what it
 * would cost if the kit came back now, labelled as a running figure rather than
 * dressed up as a final one. And the deposit is shown separately from the bill
 * all the way through, because it is the customer's money until settlement
 * decides how much of it the shop is keeping.
 */
import { ref, computed, onMounted } from 'vue'
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
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('rentals.create'))
const canEdit = computed(() => auth.can('rentals.edit'))
const canDelete = computed(() => auth.can('rentals.delete'))
// Settling raises an invoice, so it rides on the sales register's authority —
// see the note in the plugin's routes.php.
const canSettle = computed(() => auth.can('sales.create'))

const STATUSES = [
  { label: 'Booked', value: 'booked' },
  { label: 'Out', value: 'out' },
  { label: 'Back, not settled', value: 'returned' },
  { label: 'Settled', value: 'closed' },
  { label: 'Cancelled', value: 'cancelled' },
]

const BASES = [
  { label: 'Per hour', value: 'hourly' },
  { label: 'Per day', value: 'daily' },
  { label: 'Per week', value: 'weekly' },
  { label: 'Per month', value: 'monthly' },
]

const CONDITIONS = ['new', 'good', 'fair', 'worn', 'damaged']

const rows = ref<any[]>([])
const summary = ref({ booked: 0, out: 0, overdue: 0, awaiting_settlement: 0, due_today: 0, deposits_held: 0 })
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const overdueOnly = ref(false)
const showAll = ref(false)

const customers = ref<any[]>([])
const warehouses = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/sports/rentals', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        overdue: overdueOnly.value ? 1 : undefined,
        all: showAll.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [c, w] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: `${x.name}${x.phone ? ` — ${x.phone}` : ''}`,
    value: x.id,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Book / edit ----------------------------------------------------------

const formVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blank())
const editingId = ref<number | null>(null)

/** The fleet that is actually free over the window being asked about. */
const fleet = ref<any[]>([])
const fleetLoading = ref(false)

function blank() {
  const from = new Date()
  const due = new Date()
  due.setDate(due.getDate() + 1)

  return {
    customer_id: null,
    warehouse_id: null,
    rate_basis: 'daily',
    booked_from: from as Date | null,
    due_at: due as Date | null,
    grace_hours: 0,
    id_proof_type: '',
    id_proof_number: '',
    discount_type: null as string | null,
    discount_value: 0,
    notes: '',
    assets: [] as any[],
  }
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  fleet.value = []
  formVisible.value = true
  loadFleet()
}

async function openEdit(row: any) {
  const { data } = await http.get(`/sports/rentals/${row.id}`)
  const d = data.data
  editingId.value = d.id
  errors.value = {}
  form.value = {
    customer_id: d.customer_id,
    warehouse_id: d.warehouse_id,
    rate_basis: d.rate_basis,
    booked_from: d.booked_from ? new Date(d.booked_from) : null,
    due_at: d.due_at ? new Date(d.due_at) : null,
    grace_hours: d.grace_hours ?? 0,
    id_proof_type: d.id_proof_type ?? '',
    id_proof_number: d.id_proof_number ?? '',
    discount_type: d.discount_type,
    discount_value: Number(d.discount_value ?? 0),
    notes: d.notes ?? '',
    assets: d.items.map((i: any) => ({ asset_id: i.asset_id, condition_out: i.condition_out })),
  }
  formVisible.value = true
  loadFleet()
}

/**
 * Availability is asked for the *window*, not for right now. A counter on
 * Thursday taking a booking for Sunday needs Sunday's answer, and asking about
 * Thursday would happily promise a bike that is already out over the weekend.
 */
async function loadFleet() {
  if (!form.value.due_at) return
  fleetLoading.value = true
  try {
    const { data } = await http.get('/sports/rental-assets/available', {
      params: {
        from: asDateTime(form.value.booked_from),
        to: asDateTime(form.value.due_at),
        rate_basis: form.value.rate_basis,
        warehouse_id: form.value.warehouse_id || undefined,
        ignore_rental_id: editingId.value || undefined,
      },
    })
    fleet.value = data.data
  } finally {
    fleetLoading.value = false
  }
}

const chosenAssets = computed(() =>
  form.value.assets
    .map((a: any) => fleet.value.find((f: any) => f.id === a.asset_id))
    .filter(Boolean),
)

const quotedHire = computed(() =>
  chosenAssets.value.reduce((sum: number, a: any) => sum + Number(a.rate ?? 0), 0),
)
const quotedDeposit = computed(() =>
  chosenAssets.value.reduce((sum: number, a: any) => sum + Number(a.deposit_amount ?? 0), 0),
)
const needsId = computed(() => chosenAssets.value.some((a: any) => a.requires_id_proof))

function toggleAsset(asset: any) {
  const at = form.value.assets.findIndex((a: any) => a.asset_id === asset.id)
  if (at >= 0) form.value.assets.splice(at, 1)
  else form.value.assets.push({ asset_id: asset.id, condition_out: asset.condition })
}

function isChosen(assetId: number) {
  return form.value.assets.some((a: any) => a.asset_id === assetId)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      booked_from: asDateTime(form.value.booked_from),
      due_at: asDateTime(form.value.due_at),
    }
    editingId.value
      ? await http.put(`/sports/rentals/${editingId.value}`, payload)
      : await http.post('/sports/rentals', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Booking updated' : 'Booked'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the booking'), life: 4000 })
  } finally {
    saving.value = false
  }
}

// ---- Working the board ----------------------------------------------------

async function issue(row: any) {
  try {
    const { data } = await http.post(`/sports/rentals/${row.id}/issue`)
    await load()
    if (detail.value?.id === row.id) detail.value = data.data
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('That did not work'), life: 4500 })
  }
}

async function extend(row: any) {
  const res = await Swal.fire({
    title: pt('Extend to when?'),
    input: 'datetime-local',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Extend'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed || !res.value) return

  try {
    const { data } = await http.post(`/sports/rentals/${row.id}/extend`, {
      due_at: String(res.value).replace('T', ' ') + ':00',
    })
    await load()
    if (detail.value?.id === row.id) detail.value = data.data
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

async function cancel(row: any) {
  const res = await Swal.fire({
    title: pt('Cancel this booking?'),
    text: pt('The kit goes back into availability straight away.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Cancel Booking'),
    cancelButtonText: pt('Keep It'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/sports/rentals/${row.id}/cancel`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this hire?'),
    text: pt('Only a hire that never went out can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/sports/rentals/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

// ---- The contract card ----------------------------------------------------

const cardVisible = ref(false)
const detail = ref<any>(null)

async function openCard(row: any) {
  const { data } = await http.get(`/sports/rentals/${row.id}`)
  detail.value = data.data
  cardVisible.value = true
}

// ---- Taking it back -------------------------------------------------------

const returnVisible = ref(false)
const returnSaving = ref(false)
const returnRows = ref<any[]>([])
const returnAt = ref<Date | null>(null)

function openReturn(row: any) {
  http.get(`/sports/rentals/${row.id}`).then(({ data }) => {
    detail.value = data.data
    returnAt.value = new Date()
    returnRows.value = data.data.items
      .filter((i: any) => !i.returned_at)
      .map((i: any) => ({
        item_id: i.id,
        asset_code: i.asset_code,
        name: i.name,
        condition_out: i.condition_out,
        take: true,
        condition_in: i.condition_out,
        damage_charge: 0,
        damage_note: '',
        send_to_bench: false,
        hire_charge: i.hire_charge,
        late_charge: i.late_charge,
      }))
    returnVisible.value = true
  })
}

const returnTotal = computed(() =>
  returnRows.value
    .filter((r) => r.take)
    .reduce((sum, r) => sum + Number(r.hire_charge ?? 0) + Number(r.late_charge ?? 0) + Number(r.damage_charge ?? 0), 0),
)

async function saveReturn() {
  const taking = returnRows.value.filter((r) => r.take)
  if (!taking.length) return

  returnSaving.value = true
  try {
    const { data } = await http.post(`/sports/rentals/${detail.value.id}/return`, {
      returned_at: asDateTime(returnAt.value),
      items: taking.map((r) => ({
        item_id: r.item_id,
        condition_in: r.condition_in,
        damage_charge: r.damage_charge || 0,
        damage_note: r.damage_note || undefined,
        send_to_bench: r.send_to_bench,
      })),
    })
    returnVisible.value = false
    detail.value = data.data
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not record the return'), life: 4500 })
  } finally {
    returnSaving.value = false
  }
}

// ---- Settling -------------------------------------------------------------

const settleVisible = ref(false)
const settleSaving = ref(false)
const applyDeposit = ref<number>(0)

function openSettle(row: any) {
  http.get(`/sports/rentals/${row.id}`).then(({ data }) => {
    detail.value = data.data
    // Defaults to taking the bill out of the deposit, which is what a counter
    // does without being asked. Editable, because sometimes the customer would
    // rather pay and keep the deposit whole.
    applyDeposit.value = Math.min(Number(data.data.deposit_amount ?? 0), Number(data.data.total ?? 0))
    settleVisible.value = true
  })
}

const refundDue = computed(() =>
  Math.max(0, Number(detail.value?.deposit_amount ?? 0) - Number(applyDeposit.value ?? 0)),
)
const collectDue = computed(() =>
  Math.max(0, Number(detail.value?.total ?? 0) - Number(applyDeposit.value ?? 0)),
)

async function settle() {
  settleSaving.value = true
  try {
    const { data } = await http.post(`/sports/rentals/${detail.value.id}/close`, {
      apply_deposit: applyDeposit.value,
    })
    settleVisible.value = false
    detail.value = data.data
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 4500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not settle the hire'), life: 4500 })
  } finally {
    settleSaving.value = false
  }
}

// ---- Presentation ---------------------------------------------------------

function statusTag(row: any) {
  if (row.status === 'closed') return { label: pt('Settled'), severity: 'success' }
  if (row.status === 'cancelled') return { label: pt('Cancelled'), severity: 'contrast' }
  if (row.status === 'returned') return { label: pt('Back, not settled'), severity: 'info' }
  if (row.status === 'booked') return { label: pt('Booked'), severity: 'secondary' }
  return row.is_overdue ? { label: pt('Overdue'), severity: 'danger' } : { label: pt('Out'), severity: 'warn' }
}

function whenLabel(value: string | null) {
  if (!value) return '—'
  return new Date(value).toLocaleString(undefined, {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}

/** "3 days late" reads better on a board than "-73.5 hours". */
function lateLabel(row: any) {
  if (!row.is_overdue || row.hours_late == null) return ''
  const hours = Number(row.hours_late)
  if (hours < 24) return `${Math.floor(hours)} ${pt('h late')}`
  return `${Math.floor(hours / 24)} ${pt('d late')}`
}

function asDateTime(v: any): string | undefined {
  if (!v) return undefined
  const d = v instanceof Date ? v : new Date(v)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

const exportColumns: ExportColumn[] = [
  { key: 'rental_number', label: 'Hire #' },
  { key: 'customer', label: 'Customer' },
  { key: 'item_count', label: 'Items' },
  { key: 'issued_at', label: 'Out' },
  { key: 'due_at', label: 'Due back' },
  { key: 'deposit_amount', label: 'Deposit' },
  { key: 'status', label: 'Status' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Hire Desk')" :subtitle="pt('What is out, what is late, and what is back')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Hire')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Out') }}</span>
        <span class="t-value">{{ summary.out }}</span>
        <small class="muted">{{ summary.due_today }} {{ pt('due back today') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value" :class="{ danger: summary.overdue > 0 }">{{ summary.overdue }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Booked ahead') }}</span>
        <span class="t-value">{{ summary.booked }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Back, not settled') }}</span>
        <span class="t-value">{{ summary.awaiting_settlement }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Deposits held') }}</span>
        <span class="t-value">{{ formatCurrency(summary.deposits_held) }}</span>
        <small class="muted">{{ pt('the customers’ money') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search hire no., customer or asset code')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        showClear
        :placeholder="pt('Status')"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="overdueOnly" binary @change="load" />
        <span>{{ pt('Overdue only') }}</span>
      </label>
      <label class="chk">
        <Checkbox v-model="showAll" binary @change="load" />
        <span>{{ pt('Include settled') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="hires" :title="pt('Hire Desk')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="rental_number" :header="pt('Hire #')" sortable />
      <Column field="customer" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div>{{ data.customer }}</div>
          <small class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>
      <Column :header="pt('Kit')">
        <template #body="{ data }">
          <div class="codes">
            <Tag v-for="code in data.assets.slice(0, 3)" :key="code" :value="code" severity="secondary" />
            <small v-if="data.assets.length > 3" class="muted">+{{ data.assets.length - 3 }}</small>
          </div>
          <small v-if="data.outstanding_count && data.outstanding_count < data.item_count" class="muted">
            {{ data.outstanding_count }} {{ pt('of') }} {{ data.item_count }} {{ pt('still out') }}
          </small>
        </template>
      </Column>
      <Column field="due_at" :header="pt('Due back')" sortable>
        <template #body="{ data }">
          <span :class="{ late: data.is_overdue }">{{ whenLabel(data.due_at) }}</span>
          <div v-if="data.is_overdue" class="late small">{{ lateLabel(data) }}</div>
        </template>
      </Column>
      <Column field="deposit_amount" :header="pt('Deposit')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.deposit_amount) }}</template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.status === 'booked'"
            icon="pi pi-sign-out"
            text
            rounded
            severity="success"
            :title="pt('Hand over')"
            @click="issue(data)"
          />
          <Button
            v-if="canEdit && data.status === 'out'"
            icon="pi pi-sign-in"
            text
            rounded
            severity="success"
            :title="pt('Take back')"
            @click="openReturn(data)"
          />
          <Button
            v-if="canEdit && data.status === 'out'"
            icon="pi pi-clock"
            text
            rounded
            :title="pt('Extend')"
            @click="extend(data)"
          />
          <Button
            v-if="canSettle && data.status === 'returned'"
            icon="pi pi-indian-rupee"
            text
            rounded
            severity="success"
            :title="pt('Settle')"
            @click="openSettle(data)"
          />
          <Button icon="pi pi-file" text rounded :title="pt('Contract')" @click="openCard(data)" />
          <Button
            v-if="canEdit && data.status === 'booked'"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && data.status === 'booked'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="cancel(data)"
          />
          <Button
            v-if="canDelete && data.status === 'cancelled'"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing on hire. A new hire holds the kit from the moment it is booked, so nobody else can be promised it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Book / edit -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Edit Booking') : pt('New Hire')"
      :style="{ width: '900px' }"
    >
      <div class="grid-form">
        <div class="fld">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('Who is hiring?')"
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Charged') }}</label>
          <Select
            v-model="form.rate_basis"
            :options="BASES"
            option-label="label"
            option-value="value"
            class="w-full"
            @change="loadFleet"
          />
          <small class="hint">{{ pt('Part of a period counts as a whole one — six hours of a daily hire is a day.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Out from') }}</label>
          <DatePicker
            v-model="form.booked_from"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            show-icon
            class="w-full"
            @date-select="loadFleet"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Due back') }} *</label>
          <DatePicker
            v-model="form.due_at"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            show-icon
            class="w-full"
            @date-select="loadFleet"
          />
          <small v-if="errors.due_at" class="err">{{ errors.due_at[0] }}</small>
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
            @change="loadFleet"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Grace (hours)') }}</label>
          <InputNumber v-model="form.grace_hours" :min="0" :max="168" class="w-full" />
          <small class="hint">{{ pt('How late is late. Settles the twenty-minute overrun before it is argued about.') }}</small>
        </div>

        <!-- The fleet, over the window asked for -->
        <div class="fld span-2">
          <label>{{ pt('Kit') }} *</label>
          <Message v-if="!fleetLoading && !fleet.length" severity="warn" :closable="false">
            {{ pt('Nothing in the fleet is free over that period on this basis. Try another window, or check the fleet register.') }}
          </Message>
          <div v-else class="fleet-grid">
            <button
              v-for="asset in fleet"
              :key="asset.id"
              type="button"
              class="fleet-card"
              :class="{ chosen: isChosen(asset.id) }"
              @click="toggleAsset(asset)"
            >
              <div class="fc-top">
                <strong>{{ asset.asset_code }}</strong>
                <span v-if="asset.rate != null" class="fc-rate">{{ formatCurrency(asset.rate) }}</span>
              </div>
              <div class="fc-name">{{ asset.name }}</div>
              <div class="fc-meta">
                <span v-if="asset.variant">{{ asset.variant }}</span>
                <span class="muted">{{ pt('Deposit') }} {{ formatCurrency(asset.deposit_amount) }}</span>
              </div>
              <div class="fc-tags">
                <Tag :value="pt(asset.condition)" severity="secondary" />
                <Tag v-if="asset.requires_id_proof" :value="pt('ID needed')" severity="warn" />
              </div>
            </button>
          </div>
          <small v-if="errors.assets" class="err">{{ errors.assets[0] }}</small>
        </div>

        <div v-if="chosenAssets.length" class="fld span-2 quote-strip">
          <div>
            <span class="q-label">{{ pt('Chosen') }}</span>
            <strong>{{ chosenAssets.length }}</strong>
          </div>
          <div>
            <span class="q-label">{{ pt('Per period') }}</span>
            <strong>{{ formatCurrency(quotedHire) }}</strong>
          </div>
          <div>
            <span class="q-label">{{ pt('Deposit to take') }}</span>
            <strong>{{ formatCurrency(quotedDeposit) }}</strong>
          </div>
        </div>

        <Message v-if="needsId" severity="info" :closable="false" class="span-2">
          {{ pt('Some of this kit is not handed over without identification. Record it now — the handover will refuse without it.') }}
        </Message>

        <div class="fld">
          <label>{{ pt('ID type') }}</label>
          <InputText v-model="form.id_proof_type" class="w-full" :placeholder="pt('Aadhaar, driving licence…')" />
        </div>
        <div class="fld">
          <label>{{ pt('ID number') }}</label>
          <InputText v-model="form.id_proof_number" class="w-full" />
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

    <!-- Take back -->
    <Dialog v-model:visible="returnVisible" modal :header="pt('Take Kit Back')" :style="{ width: '900px' }">
      <Message severity="info" :closable="false" class="mb">
        {{ pt('Take back what is actually here. Anything left unticked stays out, keeps running, and stays unavailable to the next customer.') }}
      </Message>

      <div class="fld mb" style="max-width: 260px">
        <label>{{ pt('Back at') }}</label>
        <DatePicker v-model="returnAt" show-time hour-format="24" date-format="dd/mm/yy" show-icon class="w-full" />
        <small class="hint">{{ pt('Written up on Monday for a Sunday return? Say so — the late fee is worked out from this.') }}</small>
      </div>

      <DataTable :value="returnRows" data-key="item_id" size="small">
        <Column :header="pt('Back?')" style="width: 5rem">
          <template #body="{ data }"><Checkbox v-model="data.take" binary /></template>
        </Column>
        <Column :header="pt('Kit')">
          <template #body="{ data }">
            <strong>{{ data.asset_code }}</strong>
            <div class="muted small">{{ data.name }}</div>
            <small class="muted">{{ pt('Out as') }}: {{ pt(data.condition_out) }}</small>
          </template>
        </Column>
        <Column :header="pt('Back as')" style="width: 10rem">
          <template #body="{ data }">
            <Select
              v-model="data.condition_in"
              :options="[...CONDITIONS, 'lost']"
              class="w-full"
              :disabled="!data.take"
            />
          </template>
        </Column>
        <Column :header="pt('Damage')" style="width: 9rem">
          <template #body="{ data }">
            <InputNumber v-model="data.damage_charge" :min="0" class="w-full" :disabled="!data.take" />
          </template>
        </Column>
        <Column :header="pt('What happened')" style="width: 14rem">
          <template #body="{ data }">
            <InputText
              v-model="data.damage_note"
              class="w-full"
              :disabled="!data.take"
              :placeholder="data.damage_charge > 0 ? pt('Required') : ''"
            />
          </template>
        </Column>
        <Column :header="pt('To bench')" style="width: 6rem">
          <template #body="{ data }">
            <Checkbox v-model="data.send_to_bench" binary :disabled="!data.take" />
          </template>
        </Column>
      </DataTable>

      <div class="quote-strip mt">
        <div>
          <span class="q-label">{{ pt('Charged on this return') }}</span>
          <strong>{{ formatCurrency(returnTotal) }}</strong>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="returnVisible = false" />
        <Button :label="pt('Take Back')" icon="pi pi-sign-in" :loading="returnSaving" @click="saveReturn" />
      </template>
    </Dialog>

    <!-- Settle -->
    <Dialog v-model:visible="settleVisible" modal :header="pt('Settle Hire')" :style="{ width: '560px' }">
      <div v-if="detail">
        <div class="settle-lines">
          <div><span>{{ pt('Hire') }}</span><strong>{{ formatCurrency(detail.hire_total) }}</strong></div>
          <div><span>{{ pt('Late fee') }}</span><strong>{{ formatCurrency(detail.late_total) }}</strong></div>
          <div><span>{{ pt('Damage') }}</span><strong>{{ formatCurrency(detail.damage_total) }}</strong></div>
          <div v-if="Number(detail.tax_amount)"><span>{{ pt('Tax') }}</span><strong>{{ formatCurrency(detail.tax_amount) }}</strong></div>
          <div class="total"><span>{{ pt('Bill') }}</span><strong>{{ formatCurrency(detail.total) }}</strong></div>
        </div>

        <Message severity="info" :closable="false" class="mb mt">
          {{ pt('The deposit is the customer’s money until you keep some of it. Whatever is applied here settles the bill; the rest goes back.') }}
        </Message>

        <div class="fld">
          <label>{{ pt('Deposit held') }}: {{ formatCurrency(detail.deposit_amount) }}</label>
          <InputNumber
            v-model="applyDeposit"
            :min="0"
            :max="Math.min(Number(detail.deposit_amount), Number(detail.total))"
            class="w-full"
          />
          <small class="hint">{{ pt('How much of it to keep against the bill.') }}</small>
        </div>

        <div class="settle-lines mt">
          <div><span>{{ pt('Refund to customer') }}</span><strong>{{ formatCurrency(refundDue) }}</strong></div>
          <div class="total"><span>{{ pt('Still to collect') }}</span><strong>{{ formatCurrency(collectDue) }}</strong></div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="settleVisible = false" />
        <Button :label="pt('Settle & Bill')" icon="pi pi-check" :loading="settleSaving" @click="settle" />
      </template>
    </Dialog>

    <!-- The contract -->
    <Dialog v-model:visible="cardVisible" modal :header="pt('Hire Contract')" :style="{ width: '760px' }">
      <div v-if="detail">
        <div class="detail-strip">
          <Tag :value="detail.rental_number" severity="secondary" />
          <Tag :value="statusTag(detail).label" :severity="statusTag(detail).severity" />
          <Tag v-if="detail.invoice_number" :value="detail.invoice_number" severity="success" />
        </div>

        <div class="detail-grid">
          <div><span class="q-label">{{ pt('Customer') }}</span>{{ detail.customer }}</div>
          <div><span class="q-label">{{ pt('Charged') }}</span>{{ pt(detail.rate_basis) }}</div>
          <div><span class="q-label">{{ pt('Out') }}</span>{{ whenLabel(detail.issued_at) }}</div>
          <div><span class="q-label">{{ pt('Due back') }}</span>{{ whenLabel(detail.due_at) }}</div>
          <div v-if="detail.id_proof_number">
            <span class="q-label">{{ pt('ID seen') }}</span>{{ detail.id_proof_type }} {{ detail.id_proof_number }}
          </div>
          <div><span class="q-label">{{ pt('Deposit') }}</span>{{ formatCurrency(detail.deposit_amount) }}</div>
        </div>

        <DataTable :value="detail.items" size="small" class="mt">
          <Column field="asset_code" :header="pt('Kit')">
            <template #body="{ data }">
              <strong>{{ data.asset_code }}</strong>
              <div class="muted small">{{ data.name }}</div>
            </template>
          </Column>
          <Column :header="pt('Rate')">
            <template #body="{ data }">{{ formatCurrency(data.rate) }}</template>
          </Column>
          <Column :header="pt('Hire')">
            <template #body="{ data }">
              {{ formatCurrency(data.hire_charge) }}
              <small class="muted"> × {{ data.hire_units }}</small>
            </template>
          </Column>
          <Column :header="pt('Late')">
            <template #body="{ data }">
              <span :class="{ late: Number(data.late_charge) > 0 }">{{ formatCurrency(data.late_charge) }}</span>
            </template>
          </Column>
          <Column :header="pt('Damage')">
            <template #body="{ data }">
              {{ formatCurrency(data.damage_charge) }}
              <div v-if="data.damage_note" class="muted small">{{ data.damage_note }}</div>
            </template>
          </Column>
          <Column :header="pt('Back')">
            <template #body="{ data }">
              <span v-if="data.returned_at">{{ whenLabel(data.returned_at) }}</span>
              <Tag v-else :value="pt('Still out')" severity="warn" />
            </template>
          </Column>
        </DataTable>

        <!-- Live figure, said to be live. See the component note. -->
        <div v-if="detail.quote" class="quote-strip mt">
          <div>
            <span class="q-label">{{ detail.status === 'out' ? pt('If it came back now') : pt('Charges') }}</span>
            <strong>{{ formatCurrency(detail.quote.subtotal) }}</strong>
          </div>
          <div>
            <span class="q-label">{{ pt('Deposit') }}</span>
            <strong>{{ formatCurrency(detail.quote.deposit_balance) }}</strong>
          </div>
          <div>
            <span class="q-label">{{ detail.quote.due_from_customer >= 0 ? pt('To collect') : pt('To refund') }}</span>
            <strong>{{ formatCurrency(Math.abs(detail.quote.due_from_customer)) }}</strong>
          </div>
        </div>

        <div v-if="detail.notes" class="mt small">{{ detail.notes }}</div>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="cardVisible = false" />
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
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }

.fleet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.6rem; max-height: 260px; overflow-y: auto; padding: 0.15rem; }
.fleet-card { text-align: left; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.55rem 0.65rem; background: transparent; cursor: pointer; display: flex; flex-direction: column; gap: 0.2rem; font: inherit; color: inherit; }
.fleet-card:hover { border-color: #9ca3af; }
.fleet-card.chosen { border-color: var(--p-primary-color, #2563eb); box-shadow: inset 0 0 0 1px var(--p-primary-color, #2563eb); }
.fc-top { display: flex; justify-content: space-between; align-items: baseline; gap: 0.4rem; }
.fc-rate { font-weight: 600; font-size: 0.85rem; }
.fc-name { font-size: 0.8rem; }
.fc-meta { display: flex; justify-content: space-between; gap: 0.4rem; font-size: 0.72rem; }
.fc-tags { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-top: 0.2rem; }

.quote-strip { display: flex; gap: 1.5rem; flex-wrap: wrap; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.6rem 0.9rem; }
.quote-strip > div { display: flex; flex-direction: column; gap: 0.1rem; }
.q-label { font-size: 0.72rem; color: #6b7280; }

.settle-lines { display: flex; flex-direction: column; gap: 0.3rem; }
.settle-lines > div { display: flex; justify-content: space-between; font-size: 0.9rem; }
.settle-lines .total { border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.35rem; margin-top: 0.2rem; font-size: 1rem; }

.detail-strip { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.8rem; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; }
.detail-grid > div { display: flex; flex-direction: column; gap: 0.1rem; font-size: 0.9rem; }

.codes { display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap; }
.muted { color: #6b7280; }
.late { color: #b91c1c; font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.small { font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.mt { margin-top: 0.8rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
