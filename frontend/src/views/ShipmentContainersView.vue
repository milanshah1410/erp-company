<script setup lang="ts">
// Container Status board. Cross-shipment on purpose: the desk works the boxes,
// not the paperwork — which are still at the port, which are running out of free
// days, which came back damaged, which are yet to be returned empty.
//
// A move is recorded here rather than on the shipment, because that is where the
// information arrives (a gate-in SMS, a carrier portal, a driver's call). The
// same goes for the money: when a box runs past its free days the board is where
// the detention shows up, so it is also where it gets billed onto the shipment.
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { humanize, statusOptions, containerStatusSeverity } from '../composables/useTradeCatalogue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shipment-containers')

const canCreate = computed(() => auth.can('shipment-containers.create'))
const canEdit = computed(() => auth.can('shipment-containers.edit'))
const canDelete = computed(() => auth.can('shipment-containers.delete'))
// Billing detention writes a logistics cost, so it needs both permissions —
// the same pair the route enforces.
const canBillDetention = computed(() => canEdit.value && auth.can('shipment-expenses.create'))

const summary = ref<any>(null)
const search = ref('')
const statusFilter = ref<string | null>(null)
const directionFilter = ref<string | null>(null)
const typeFilter = ref<string | null>(null)
const detentionOnly = ref(false)
const damagedOnly = ref(false)
const openOnly = ref(true)

const containerStatuses = [
  'planned', 'stuffed', 'gated_in', 'loaded', 'in_transit', 'discharged', 'gated_out', 'delivered', 'empty_returned',
]
const statusChoices = statusOptions(containerStatuses)
const directionChoices = statusOptions(['import', 'export'])
const containerTypes = ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other']
const typeChoices = containerTypes.map(v => ({ label: v.toUpperCase(), value: v }))
const chargeHeads = statusOptions(['detention', 'demurrage'])

function params(): Record<string, any> {
  return {
    ...(search.value ? { search: search.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(typeFilter.value ? { container_type: typeFilter.value } : {}),
    ...(detentionOnly.value ? { detention_only: 1 } : {}),
    ...(damagedOnly.value ? { damaged_only: 1 } : {}),
    ...(openOnly.value ? { open_only: 1 } : {}),
  }
}
// "Hide returned" is on by default, so it does not count as the desk having
// filtered — an empty board under it still means there is nothing to work.
const filtered = computed(() =>
  !!(search.value || statusFilter.value || directionFilter.value || typeFilter.value
    || detentionOnly.value || damagedOnly.value))

async function reload() {
  crud.fetch(params())
  const { data } = await http.get('/shipment-containers/summary')
  summary.value = data
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch(params())
}
function fail(e: any) {
  const res = e.response?.data
  const detail = res?.errors ? Object.values(res.errors).flat().join(' ') : (res?.message || 'Failed')
  toast.add({ severity: 'error', summary: 'Error', detail, life: 5000 })
}

/** Jump straight from a summary chip into the boxes behind it. */
function drillStatus(status: string) {
  statusFilter.value = statusFilter.value === status ? null : status
  if (statusFilter.value) openOnly.value = false
  detentionOnly.value = damagedOnly.value = false
  reload()
}
function drillFlag(flag: 'detention' | 'damaged') {
  const target = flag === 'detention' ? detentionOnly : damagedOnly
  const other = flag === 'detention' ? damagedOnly : detentionOnly
  target.value = !target.value
  other.value = false
  statusFilter.value = null
  reload()
}

// --- add / edit a box -------------------------------------------------------
// The board is the register, so a box can be booked here as well as from its
// shipment; the shipment is the one thing that cannot change afterwards.
const form = ref<any>(null)
const saving = ref(false)
const shipmentOptions = ref<any[]>([])

async function loadShipments() {
  if (shipmentOptions.value.length) return
  const { data } = await http.get('/shipments', { params: { per_page: 100, state: 'all' } })
  shipmentOptions.value = (data.data ?? data).map((s: any) => ({
    label: `${s.shipment_number} — ${s.party ?? humanize(s.direction)}`,
    value: s.id,
  }))
}

async function openForm(row: any = null) {
  await loadShipments()
  form.value = row
    ? {
      id: row.id, shipment_id: row.shipment_id, shipment_number: row.shipment_number,
      container_number: row.container_number, container_type: row.container_type,
      seal_number: row.seal_number || '', current_location: row.current_location || '',
      packages: Number(row.packages ?? 0),
      gross_weight_kg: row.gross_weight_kg === null ? null : Number(row.gross_weight_kg),
      net_weight_kg: row.net_weight_kg === null ? null : Number(row.net_weight_kg),
      cbm: row.cbm === null ? null : Number(row.cbm),
      free_days: Number(row.free_days ?? 0),
      detention_free_until: row.detention_free_until || '',
      empty_returned_on: row.empty_returned_on || '',
      is_damaged: !!row.is_damaged, damage_remarks: row.damage_remarks || '',
      remarks: row.remarks || '',
    }
    : {
      shipment_id: null, container_number: '', container_type: '20ft', seal_number: '',
      status: 'planned', current_location: '', packages: 0,
      gross_weight_kg: null, net_weight_kg: null, cbm: null,
      free_days: 0, detention_free_until: '', empty_returned_on: '',
      is_damaged: false, damage_remarks: '', remarks: '',
    }
}

async function save() {
  saving.value = true
  try {
    const { shipment_number, ...rest } = form.value
    const body = Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, v === '' ? null : v]))
    if (form.value.id) await http.put(`/shipment-containers/${form.value.id}`, body)
    else await http.post('/shipment-containers', body)
    toast.add({ severity: 'success', summary: 'Container saved', life: 2200 })
    form.value = null
    await reload()
  } catch (e: any) {
    fail(e)
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: `Remove ${row.container_number}?`,
    text: 'The box comes off its shipment. Costs already booked against it stay.',
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-containers/${row.id}`)
    toast.add({ severity: 'success', summary: 'Container removed', life: 2200 })
    await reload()
  } catch (e: any) {
    fail(e)
  }
}

// --- record a move ----------------------------------------------------------
const moveRow = ref<any>(null)
const moveForm = ref<any>(null)
const moving = ref(false)
/** `status` forces a target (the Return-empty shortcut); otherwise offer the next step. */
function openMove(row: any, status?: string) {
  moveRow.value = row
  moveForm.value = {
    status: status ?? containerStatuses[Math.min(containerStatuses.indexOf(row.status) + 1, containerStatuses.length - 1)],
    current_location: row.current_location || '',
    moved_at: new Date().toISOString().slice(0, 10),
    is_damaged: !!row.is_damaged,
    damage_remarks: row.damage_remarks || '',
    remarks: '',
  }
}
async function saveMove() {
  moving.value = true
  try {
    await http.post(`/shipment-containers/${moveRow.value.id}/move`, moveForm.value)
    toast.add({ severity: 'success', summary: 'Move recorded', life: 2200 })
    moveRow.value = null
    await reload()
  } catch (e: any) {
    fail(e)
  } finally {
    moving.value = false
  }
}

// --- bill the detention -----------------------------------------------------
// Posts a detention / demurrage cost onto the box's shipment. Re-running while
// the box is still out re-prices the open charge rather than stacking a second.
const chargeRow = ref<any>(null)
const chargeForm = ref<any>(null)
const charging = ref(false)
function openCharge(row: any) {
  chargeRow.value = row
  chargeForm.value = {
    expense_type: 'detention',
    rate_per_day: null,
    days: row.detention_days || 1,
    is_billable: false,
    invoice_number: '',
    remarks: '',
  }
}
const chargeAmount = computed(() =>
  Number(chargeForm.value?.rate_per_day || 0) * Number(chargeForm.value?.days || 0))

async function saveCharge() {
  charging.value = true
  try {
    const body = Object.fromEntries(
      Object.entries(chargeForm.value).map(([k, v]) => [k, v === '' ? null : v]),
    )
    const { data } = await http.post(`/shipment-containers/${chargeRow.value.id}/detention-charge`, body)
    toast.add({ severity: 'success', summary: data.message || 'Charge posted', life: 2600 })
    chargeRow.value = null
    await reload()
  } catch (e: any) {
    fail(e)
  } finally {
    charging.value = false
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(row: any) {
  detailId.value = row.shipment_id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'container_number', label: 'Container #' },
  { key: 'container_type', label: 'Type' },
  { key: 'seal_number', label: 'Seal' },
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'status', label: 'Status' },
  { key: 'current_location', label: 'Location' },
  { key: 'detention_free_until', label: 'Free until' },
  { key: 'detention_days', label: 'Detention days' },
  { key: 'is_damaged', label: 'Damaged' },
  { key: 'empty_returned_on', label: 'Empty returned' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Container Status" :count="crud.total.value" countLabel="containers"
      subtitle="Where every box is, what it is costing in detention, and what is still to come back empty.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Container, seal, shipment…" @keyup.enter="reload" />
          </span>
          <Select v-model="statusFilter" :options="statusChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any status" class="filter-sm" @change="reload" />
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Both legs" class="filter-sm" @change="reload" />
          <Select v-model="typeFilter" :options="typeChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any type" class="filter-sm" @change="reload" />
          <TableExportButtons title="Container Status" endpoint="/shipment-containers" :params="params" :columns="exportColumns" />
          <Button v-if="canCreate" label="Add container" icon="pi pi-plus" @click="openForm()" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="strip">
      <button v-for="s in summary.by_status" :key="s.status" type="button" class="chip"
        :class="{ zero: !s.total, active: statusFilter === s.status }" @click="drillStatus(s.status)">
        <strong>{{ s.total }}</strong> {{ humanize(s.status) }}
      </button>
      <button type="button" class="chip warn" :class="{ active: detentionOnly }" @click="drillFlag('detention')">
        <strong>{{ summary.in_detention }}</strong> in detention
      </button>
      <button type="button" class="chip warn" :class="{ active: damagedOnly }" @click="drillFlag('damaged')">
        <strong>{{ summary.damaged }}</strong> damaged
      </button>
    </div>

    <div class="toolbar">
      <label class="switch"><ToggleSwitch v-model="openOnly" @change="reload" /><span>Hide returned boxes</span></label>
      <label class="switch"><ToggleSwitch v-model="detentionOnly" @change="reload" /><span>Detention only</span></label>
      <label class="switch"><ToggleSwitch v-model="damagedOnly" @change="reload" /><span>Damaged only</span></label>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="container_number" header="Container #" />
      <Column header="Type"><template #body="{ data }">{{ String(data.container_type).toUpperCase() }}</template></Column>
      <Column field="seal_number" header="Seal" />
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="containerStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="Location">
        <template #body="{ data }">
          {{ data.current_location || '—' }}
          <br v-if="data.status_updated_at" />
          <small v-if="data.status_updated_at" class="muted">{{ new Date(data.status_updated_at).toLocaleDateString() }}</small>
        </template>
      </Column>
      <Column header="Free days / Detention">
        <template #body="{ data }">
          <span v-if="data.accruing_detention" class="bad">{{ data.detention_days }} days over</span>
          <span v-else-if="data.detention_free_until" class="muted">free to {{ data.detention_free_until }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Damage">
        <template #body="{ data }">
          <Tag v-if="data.is_damaged" severity="danger" value="Damaged" class="mini" :title="data.damage_remarks" />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Empty return">
        <template #body="{ data }">{{ data.empty_returned_on || '—' }}</template>
      </Column>
      <Column header="Actions" style="width: 15rem">
        <template #body="{ data }">
          <Button v-if="canEdit && data.status !== 'empty_returned'" label="Move" size="small" outlined @click="openMove(data)" />
          <Button v-if="canEdit && data.status !== 'empty_returned'" icon="pi pi-reply" text rounded size="small"
            severity="success" title="Return empty" @click="openMove(data, 'empty_returned')" />
          <Button v-if="canBillDetention && data.accruing_detention" icon="pi pi-indian-rupee" text rounded size="small"
            severity="warn" title="Bill detention" @click="openCharge(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openForm(data)" />
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="Open shipment" @click="openShipment(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" title="Remove"
            @click="remove(data)" />
        </template>
      </Column>
      <template #empty>
        <div class="empty">
          <p v-if="filtered">No containers match this filter.</p>
          <template v-else>
            <p>No containers booked yet.</p>
            <p class="muted">Book the boxes against their shipment and the desk can track gate-in, detention and the empty return from here.</p>
            <Button v-if="canCreate" label="Add the first container" icon="pi pi-plus" size="small" @click="openForm()" />
          </template>
        </div>
      </template>
    </DataTable>

    <!-- add / edit -->
    <Dialog :visible="!!form" modal :header="form?.id ? `Edit ${form.container_number}` : 'Add container'"
      :style="{ width: '720px' }" @update:visible="v => { if (!v) form = null }">
      <div v-if="form" class="form-grid">
        <div class="fld full">
          <label>Shipment *</label>
          <Select v-if="!form.id" v-model="form.shipment_id" :options="shipmentOptions" optionLabel="label"
            optionValue="value" filter placeholder="Which consignment is this box on?" />
          <!-- A box never moves to another consignment: the costs, customs and
               timeline already booked against it belong to this one. -->
          <InputText v-else :model-value="form.shipment_number" disabled />
        </div>
        <div class="fld"><label>Container no. *</label><InputText v-model="form.container_number" placeholder="MSKU1234567" /></div>
        <div class="fld">
          <label>Size &amp; type *</label>
          <Select v-model="form.container_type" :options="typeChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Seal no.</label><InputText v-model="form.seal_number" /></div>
        <div class="fld">
          <label v-if="!form.id">Status</label>
          <Select v-if="!form.id" v-model="form.status" :options="statusChoices" optionLabel="label" optionValue="value" />
          <!-- After booking, status only changes through a move, so it is
               stamped with a date, a place and a timeline entry. -->
          <p v-else class="hint">Status changes through <strong>Move</strong>, so the timeline keeps the trail.</p>
        </div>
        <div class="fld"><label>Location</label><InputText v-model="form.current_location" placeholder="Port, CFS, yard…" /></div>
        <div class="fld"><label>Packages</label><InputNumber v-model="form.packages" :min="0" /></div>
        <div class="fld"><label>Gross weight (kg)</label><InputNumber v-model="form.gross_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></div>
        <div class="fld"><label>Net weight (kg)</label><InputNumber v-model="form.net_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></div>
        <div class="fld"><label>CBM</label><InputNumber v-model="form.cbm" :minFractionDigits="0" :maxFractionDigits="3" /></div>
        <div class="fld"><label>Free days</label><InputNumber v-model="form.free_days" :min="0" /></div>
        <div class="fld"><label>Free until</label><input type="date" v-model="form.detention_free_until" class="date-input" /></div>
        <div class="fld"><label>Empty returned on</label><input type="date" v-model="form.empty_returned_on" class="date-input" /></div>
        <div class="fld chk">
          <Checkbox v-model="form.is_damaged" :binary="true" inputId="dmg1" />
          <label for="dmg1">Damaged</label>
        </div>
        <div class="fld full" v-if="form.is_damaged">
          <label>Damage details</label><Textarea v-model="form.damage_remarks" rows="2" />
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="form.remarks" rows="2" /></div>
        <p class="hint full">
          Free days count from gate-in or discharge — leave <strong>Free until</strong> blank and the move sets it.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="form = null" />
        <Button label="Save" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- move -->
    <Dialog :visible="!!moveRow" modal :header="`Move ${moveRow?.container_number ?? ''}`" :style="{ width: '520px' }"
      @update:visible="v => { if (!v) moveRow = null }">
      <div v-if="moveForm" class="form-grid">
        <div class="fld">
          <label>New status *</label>
          <Select v-model="moveForm.status" :options="statusChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Moved on</label><input type="date" v-model="moveForm.moved_at" class="date-input" /></div>
        <div class="fld full"><label>Location</label><InputText v-model="moveForm.current_location" placeholder="Port, CFS, yard…" /></div>
        <div class="fld chk">
          <Checkbox v-model="moveForm.is_damaged" :binary="true" inputId="dmg2" />
          <label for="dmg2">Damage found</label>
        </div>
        <div class="fld full" v-if="moveForm.is_damaged">
          <label>Damage details</label><Textarea v-model="moveForm.damage_remarks" rows="2" />
        </div>
        <div class="fld full"><label>Remarks (goes on the shipment timeline)</label><Textarea v-model="moveForm.remarks" rows="2" /></div>
        <p v-if="moveForm.status === 'empty_returned'" class="hint full">
          Returning the box empty closes it out and stops the detention clock.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="moveRow = null" />
        <Button label="Record move" :loading="moving" @click="saveMove" />
      </template>
    </Dialog>

    <!-- detention / demurrage -->
    <Dialog :visible="!!chargeRow" modal :header="`Bill detention on ${chargeRow?.container_number ?? ''}`"
      :style="{ width: '560px' }" @update:visible="v => { if (!v) chargeRow = null }">
      <div v-if="chargeForm" class="form-grid">
        <p class="hint full">
          {{ chargeRow.detention_days }} day(s) past free time
          <span v-if="chargeRow.detention_free_until" class="muted">(free to {{ chargeRow.detention_free_until }})</span>
          — posts a cost on shipment <strong>{{ chargeRow.shipment_number }}</strong>, payable to the carrier.
        </p>
        <div class="fld">
          <label>Head *</label>
          <Select v-model="chargeForm.expense_type" :options="chargeHeads" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Days *</label><InputNumber v-model="chargeForm.days" :min="1" /></div>
        <div class="fld"><label>Rate per day *</label><InputNumber v-model="chargeForm.rate_per_day" :minFractionDigits="2" /></div>
        <div class="fld"><label>Invoice no.</label><InputText v-model="chargeForm.invoice_number" /></div>
        <div class="fld chk">
          <Checkbox v-model="chargeForm.is_billable" :binary="true" inputId="bill1" />
          <label for="bill1">Recoverable from the counterparty</label>
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="chargeForm.remarks" rows="2" /></div>
        <p class="hint full">
          Charge <strong>{{ chargeAmount.toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</strong>.
          Re-running while the box is still out re-prices the open charge instead of adding a second one.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="chargeRow = null" />
        <Button label="Post charge" :loading="charging" @click="saveCharge" />
      </template>
    </Dialog>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 150px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.strip { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.9rem; }
.chip { border: 1px solid var(--border, #e8e4da); border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.78rem;
  background: none; color: inherit; font-family: inherit; cursor: pointer; }
.chip:hover { border-color: #6366f1; }
.chip strong { font-size: 0.9rem; }
.chip.zero { opacity: 0.5; }
.chip.warn { border-color: #fca5a5; color: #b91c1c; }
.chip.active { background: rgba(99, 102, 241, 0.12); border-color: #6366f1; }
.chip.warn.active { background: rgba(220, 38, 38, 0.1); border-color: #dc2626; }

.toolbar { display: flex; gap: 1.25rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.bad { color: #dc2626; font-weight: 600; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.empty { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 1.25rem 0; text-align: center; }
.empty p { margin: 0; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld.chk { flex-direction: row; align-items: center; gap: 0.5rem; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.hint { margin: 0; font-size: 0.8rem; color: #6b7280; }
.hint.full { grid-column: 1 / -1; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

@media (max-width: 900px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
