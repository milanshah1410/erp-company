<script setup lang="ts">
// Logistics Expenses. Every cost booked against a shipment — freight, THC, CFS,
// CHA, duty, inland transport, detention, bank charges — across all consignments,
// with the cost mix on top so the desk can see where the freight spend goes.
//
// Amounts are entered in whatever currency the vendor bills in; the base amount
// is derived server-side and is what the profit board sums.
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
import { useTradeCatalogue, humanize, statusOptions, paymentStatusSeverity } from '../composables/useTradeCatalogue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shipment-expenses')
const { catalogue, load: loadCatalogue } = useTradeCatalogue()

const canCreate = computed(() => auth.can('shipment-expenses.create'))
const canEdit = computed(() => auth.can('shipment-expenses.edit'))
const canDelete = computed(() => auth.can('shipment-expenses.delete'))

const summary = ref<any>(null)
const search = ref('')
const typeFilter = ref<string | null>(null)
const directionFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const unsettledOnly = ref(false)
const dateFrom = ref('')
const dateTo = ref('')

const directionChoices = statusOptions(['import', 'export'])
const statusChoices = statusOptions(['unpaid', 'partial', 'paid'])

function params(): Record<string, any> {
  return {
    ...(search.value ? { search: search.value } : {}),
    ...(typeFilter.value ? { expense_type: typeFilter.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(statusFilter.value ? { payment_status: statusFilter.value } : {}),
    ...(unsettledOnly.value ? { unsettled_only: 1 } : {}),
    ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
    ...(dateTo.value ? { date_to: dateTo.value } : {}),
  }
}
async function reload() {
  crud.fetch(params())
  const { data } = await http.get('/shipment-expenses/summary', {
    params: {
      ...(directionFilter.value ? { direction: directionFilter.value } : {}),
      ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
      ...(dateTo.value ? { date_to: dateTo.value } : {}),
    },
  })
  summary.value = data
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch(params())
}

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
}
const mixMax = computed(() => Math.max(1, ...(summary.value?.by_type ?? []).map((e: any) => Number(e.total))))

// --- create / edit ---
const form = ref<any>(null)
const saving = ref(false)
const shipmentOptions = ref<any[]>([])
const vendorOptions = ref<any[]>([])

const vendorEndpoints: Record<string, string> = {
  freight_forwarder: '/freight-forwarders',
  cha_agent: '/cha-agents',
  transporter: '/transporters',
  shipping_line: '/shipping-lines',
  airline: '/airlines',
  supplier: '/suppliers',
}

async function loadShipments() {
  if (shipmentOptions.value.length) return
  const { data } = await http.get('/shipments', { params: { per_page: 100, state: 'all' } })
  shipmentOptions.value = (data.data ?? data).map((s: any) => ({
    label: `${s.shipment_number} — ${s.party ?? humanize(s.direction)}`,
    value: s.id,
  }))
}
async function loadVendors(type: string) {
  vendorOptions.value = []
  const endpoint = vendorEndpoints[type]
  if (!endpoint) return
  const { data } = await http.get(endpoint, { params: { per_page: 100 } })
  vendorOptions.value = (data.data ?? data).map((v: any) => ({ label: v.name, value: v.id }))
}
async function onVendorTypeChange() {
  form.value.vendor_id = null
  if (form.value.vendor_type && form.value.vendor_type !== 'other') await loadVendors(form.value.vendor_type)
  else vendorOptions.value = []
}

async function openForm(row: any = null) {
  await loadShipments()
  form.value = row
    ? {
      id: row.id, shipment_id: row.shipment_id, expense_type: row.expense_type, expense_date: row.expense_date,
      vendor_type: row.vendor_type || 'other', vendor_id: row.vendor_id, vendor_name: row.vendor_name || '',
      invoice_number: row.invoice_number || '', currency: row.currency, exchange_rate: Number(row.exchange_rate),
      amount: Number(row.amount), paid_amount: Number(row.paid_amount), due_date: row.due_date || '',
      is_billable: !!row.is_billable, remarks: row.remarks || '',
    }
    : {
      shipment_id: null, expense_type: catalogue.value.expense_types[0]?.value ?? 'other',
      expense_date: new Date().toISOString().slice(0, 10),
      vendor_type: 'other', vendor_id: null, vendor_name: '', invoice_number: '',
      currency: 'INR', exchange_rate: 1, amount: 0, paid_amount: 0, due_date: '', is_billable: false, remarks: '',
    }
  if (form.value.vendor_type !== 'other') await loadVendors(form.value.vendor_type)
}

async function save() {
  saving.value = true
  try {
    const body = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]))
    if (form.value.id) await http.put(`/shipment-expenses/${form.value.id}`, body)
    else await http.post('/shipment-expenses', body)
    toast.add({ severity: 'success', summary: 'Cost saved', life: 2200 })
    form.value = null
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

// Settling a bill is a figure, not a form: post the running total paid.
async function pay(row: any) {
  const res = await Swal.fire({
    title: `Settle ${row.expense_label}`,
    text: `Outstanding ${money(row.outstanding_amount)} of ${money(row.base_amount)}. Enter the total paid so far.`,
    input: 'number',
    inputValue: String(Number(row.base_amount)),
    showCancelButton: true,
    confirmButtonText: 'Record',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/shipment-expenses/${row.id}/pay`, { paid_amount: Number(res.value) })
    toast.add({ severity: 'success', summary: 'Payment recorded', life: 2200 })
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({ title: 'Remove this cost?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-expenses/${row.id}`)
    toast.add({ severity: 'success', summary: 'Removed', life: 2200 })
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(row: any) {
  detailId.value = row.shipment_id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'expense_label', label: 'Head' },
  { key: 'expense_date', label: 'Date' },
  { key: 'vendor_name', label: 'Vendor' },
  { key: 'invoice_number', label: 'Invoice' },
  { key: 'currency', label: 'Currency' },
  { key: 'amount', label: 'Amount' },
  { key: 'base_amount', label: 'Base amount' },
  { key: 'paid_amount', label: 'Paid' },
  { key: 'outstanding_amount', label: 'Outstanding' },
  { key: 'payment_status', label: 'Status' },
  { key: 'is_billable', label: 'Billable' },
]

onMounted(async () => {
  await loadCatalogue()
  await reload()
})
</script>

<template>
  <div>
    <PageHeader title="Logistics Expenses" :count="crud.total.value" countLabel="cost entries"
      subtitle="Freight, handling, clearing, duty and detention booked against each shipment.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Vendor, invoice, shipment…" @keyup.enter="reload" />
          </span>
          <Select v-model="typeFilter" :options="catalogue.expense_types" optionLabel="label" optionValue="value"
            showClear filter placeholder="Any head" class="filter-md" @change="reload" />
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value"
            showClear placeholder="Both legs" class="filter-sm" @change="reload" />
          <Select v-model="statusFilter" :options="statusChoices" optionLabel="label" optionValue="value"
            showClear placeholder="Any status" class="filter-sm" @change="reload" />
          <TableExportButtons title="Logistics Expenses" endpoint="/shipment-expenses" :params="params" :columns="exportColumns" />
          <Button v-if="canCreate" label="Add cost" icon="pi pi-plus" @click="openForm()" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="mix">
      <div class="mix-sums">
        <div class="sum"><span class="sum-value">{{ money(summary.total) }}</span><span class="sum-label">Booked</span></div>
        <div class="sum"><span class="sum-value">{{ money(summary.paid) }}</span><span class="sum-label">Paid</span></div>
        <div class="sum" :class="{ bad: summary.outstanding > 0 }"><span class="sum-value">{{ money(summary.outstanding) }}</span><span class="sum-label">Owed to vendors</span></div>
      </div>
      <ul v-if="summary.by_type.length" class="bars">
        <li v-for="row in summary.by_type" :key="row.expense_type">
          <span class="bar-label">{{ row.label }}</span>
          <span class="bar-track"><span class="bar-fill" :style="{ width: `${(row.total / mixMax) * 100}%` }" /></span>
          <span class="bar-value">{{ money(row.total) }}</span>
        </li>
      </ul>
    </div>

    <div class="toolbar">
      <label class="switch"><ToggleSwitch v-model="unsettledOnly" @change="reload" /><span>Unpaid only</span></label>
      <span class="dates">
        <label>From</label><input type="date" v-model="dateFrom" class="date-input" @change="reload" />
        <label>To</label><input type="date" v-model="dateTo" class="date-input" @change="reload" />
      </span>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="expense_label" header="Head" />
      <Column field="expense_date" header="Date" />
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Vendor">
        <template #body="{ data }">
          {{ data.vendor_name || '—' }}
          <br v-if="data.vendor_type && data.vendor_type !== 'other'" />
          <small v-if="data.vendor_type && data.vendor_type !== 'other'" class="muted">{{ humanize(data.vendor_type) }}</small>
        </template>
      </Column>
      <Column field="invoice_number" header="Invoice" />
      <Column header="Amount">
        <template #body="{ data }">
          {{ data.currency }} {{ money(data.amount) }}
          <br /><small class="muted">base {{ money(data.base_amount) }}</small>
        </template>
      </Column>
      <Column header="Paid / Owed">
        <template #body="{ data }">
          {{ money(data.paid_amount) }}
          <span v-if="Number(data.outstanding_amount) > 0" class="bad"> / {{ money(data.outstanding_amount) }}</span>
          <br />
          <Tag :severity="paymentStatusSeverity[data.payment_status]" :value="humanize(data.payment_status)" class="mini" />
        </template>
      </Column>
      <Column header="Due">
        <template #body="{ data }">{{ data.due_date || '—' }}</template>
      </Column>
      <Column header="Billable">
        <template #body="{ data }"><i :class="data.is_billable ? 'pi pi-check good' : 'pi pi-minus muted'" /></template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <Button v-if="canEdit && data.payment_status !== 'paid'" label="Settle" size="small" outlined @click="pay(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openForm(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>No logistics costs booked yet.</template>
    </DataTable>

    <Dialog :visible="!!form" modal :header="form?.id ? 'Edit cost' : 'Add logistics cost'" :style="{ width: '700px' }"
      @update:visible="v => { if (!v) form = null }">
      <div v-if="form" class="form-grid">
        <div class="fld full">
          <label>Shipment *</label>
          <Select v-model="form.shipment_id" :options="shipmentOptions" optionLabel="label" optionValue="value" filter
            placeholder="Which consignment is this cost on?" />
        </div>
        <div class="fld">
          <label>Head *</label>
          <Select v-model="form.expense_type" :options="catalogue.expense_types" optionLabel="label" optionValue="value" filter />
        </div>
        <div class="fld"><label>Date *</label><input type="date" v-model="form.expense_date" class="date-input" /></div>
        <div class="fld">
          <label>Billed by</label>
          <Select v-model="form.vendor_type" :options="catalogue.vendor_types" optionLabel="label" optionValue="value"
            @change="onVendorTypeChange" />
        </div>
        <div class="fld" v-if="form.vendor_type && form.vendor_type !== 'other'">
          <label>Vendor *</label>
          <Select v-model="form.vendor_id" :options="vendorOptions" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld" v-else><label>Vendor name</label><InputText v-model="form.vendor_name" /></div>
        <div class="fld"><label>Invoice no.</label><InputText v-model="form.invoice_number" /></div>
        <div class="fld"><label>Currency</label><InputText v-model="form.currency" maxlength="3" /></div>
        <div class="fld"><label>Exchange rate</label><InputNumber v-model="form.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" /></div>
        <div class="fld"><label>Amount *</label><InputNumber v-model="form.amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>Paid so far</label><InputNumber v-model="form.paid_amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>Due date</label><input type="date" v-model="form.due_date" class="date-input" /></div>
        <div class="fld chk">
          <Checkbox v-model="form.is_billable" :binary="true" inputId="billable2" />
          <label for="billable2">Recoverable from the counterparty</label>
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="form.remarks" rows="2" /></div>
        <p class="total-hint">
          Base amount <strong>{{ money(Number(form.amount || 0) * Number(form.exchange_rate || 1)) }}</strong>
          <span class="muted"> — what the profit board counts</span>
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="form = null" />
        <Button label="Save" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 140px; }
.filter-md { width: 180px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.mix { display: grid; grid-template-columns: minmax(220px, 320px) 1fr; gap: 1.5rem; align-items: start; margin-bottom: 1rem; }
.mix-sums { display: flex; flex-direction: column; gap: 0.5rem; }
.sum { display: flex; flex-direction: column; border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.5rem 0.85rem; }
.sum-value { font-size: 1.2rem; font-weight: 700; line-height: 1.2; }
.sum-label { font-size: 0.72rem; color: #6b7280; }
.sum.bad .sum-value { color: #dc2626; }

.bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.bars li { display: grid; grid-template-columns: 30% 1fr auto; gap: 0.6rem; align-items: center; }
.bar-label { font-size: 0.8rem; }
.bar-track { height: 0.5rem; background: rgba(99, 102, 241, 0.12); border-radius: 999px; overflow: hidden; }
.bar-fill { display: block; height: 100%; background: #6366f1; border-radius: 999px; }
.bar-value { font-size: 0.8rem; font-variant-numeric: tabular-nums; }

.toolbar { display: flex; gap: 1.5rem; align-items: center; margin-bottom: 0.9rem; flex-wrap: wrap; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
.dates { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
.date-input { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.78rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld.chk { flex-direction: row; align-items: center; gap: 0.5rem; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; }

@media (max-width: 900px) {
  .mix { grid-template-columns: 1fr; }
  .form-grid { grid-template-columns: 1fr; }
}
</style>
