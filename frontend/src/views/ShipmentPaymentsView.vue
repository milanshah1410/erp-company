<script setup lang="ts">
// Pending Payments. Two sides of the same coin: goods value still to be received
// on exports and still to be remitted on imports, aged against the payment due
// date, plus the forwarder / CHA bills that are owed on the vendor side.
//
// Settling happens in place — a receipt or remittance recorded here updates the
// shipment's payment status straight away.
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { humanize, statusOptions, paymentStatusSeverity, shipmentStatusSeverity } from '../composables/useTradeCatalogue'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const canPay = computed(() => auth.can('shipment-payments.create'))

const rows = ref<any[]>([])
const meta = ref<any>({ total: 0, per_page: 25, current_page: 1 })
const summary = ref<any>(null)
const loading = ref(false)

const search = ref('')
const directionFilter = ref<string | null>(null)
const overdueOnly = ref(false)
const directionChoices = statusOptions(['import', 'export'])

function params(): Record<string, any> {
  return {
    per_page: meta.value.per_page,
    page: meta.value.current_page,
    ...(search.value ? { search: search.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(overdueOnly.value ? { overdue_only: 1 } : {}),
  }
}
async function reload() {
  loading.value = true
  try {
    const { data } = await http.get('/import-export/pending-payments', { params: params() })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}
function onPage(e: any) {
  meta.value.current_page = e.page + 1
  meta.value.per_page = e.rows
  reload()
}

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
}

// Days past the agreed payment date; nothing to show before it falls due.
function agedDays(row: any): number | null {
  if (!row.payment_due_date) return null
  const due = new Date(row.payment_due_date).getTime()
  const days = Math.floor((Date.now() - due) / 86400000)
  return days > 0 ? days : null
}

// --- settle dialog ---
const row = ref<any>(null)
const form = ref<any>(null)
const saving = ref(false)
const paymentModes = statusOptions(['bank_transfer', 'lc', 'cheque', 'cash', 'upi', 'other'])

function openSettle(target: any) {
  row.value = target
  // Default to clearing the balance, in the shipment's own currency.
  const rate = Number(target.exchange_rate) || 1
  form.value = {
    payment_date: new Date().toISOString().slice(0, 10),
    currency: target.currency,
    exchange_rate: rate,
    amount: Number((Number(target.outstanding_amount) / rate).toFixed(2)),
    mode: 'bank_transfer',
    reference: '',
    remarks: '',
  }
}
async function save() {
  saving.value = true
  try {
    await http.post('/shipment-payments', { ...form.value, shipment_id: row.value.id })
    toast.add({ severity: 'success', summary: row.value.direction === 'import' ? 'Remittance recorded' : 'Receipt recorded', life: 2400 })
    row.value = null
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(target: any) {
  detailId.value = target.id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'party', label: 'Party' },
  { key: 'invoice_number', label: 'Invoice' },
  { key: 'currency', label: 'Currency' },
  { key: 'invoice_value', label: 'Invoice value' },
  { key: 'invoice_value_base', label: 'Value (base)' },
  { key: 'amount_settled', label: 'Settled' },
  { key: 'outstanding_amount', label: 'Outstanding' },
  { key: 'payment_due_date', label: 'Due' },
  { key: 'payment_status', label: 'Status' },
  { key: 'lc_number', label: 'L/C' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Pending Payments" :count="meta.total" countLabel="shipments"
      subtitle="Export proceeds still to come in, supplier remittances still to go out, and the vendor bills behind them.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Shipment, invoice, L/C…" @keyup.enter="reload" />
          </span>
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Both legs" class="filter-sm" @change="reload" />
          <TableExportButtons title="Pending Payments" endpoint="/import-export/pending-payments" :params="params" :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="summary-row">
      <div class="sum good"><span class="sum-value">{{ money(summary.receivable) }}</span><span class="sum-label">Receivable on exports</span></div>
      <div class="sum bad"><span class="sum-value">{{ money(summary.payable) }}</span><span class="sum-label">Payable on imports</span></div>
      <div class="sum"><span class="sum-value">{{ money(summary.vendor_bills_outstanding) }}</span><span class="sum-label">{{ summary.vendor_bills_count }} vendor bills owed</span></div>
      <div class="sum" :class="{ bad: summary.overdue > 0 }"><span class="sum-value">{{ summary.overdue }}</span><span class="sum-label">Past due date</span></div>
      <Button label="Logistics expenses" icon="pi pi-arrow-right" iconPos="right" size="small" text
        @click="router.push('/import-export/expenses')" />
    </div>

    <div class="toolbar">
      <label class="switch"><ToggleSwitch v-model="overdueOnly" @change="reload" /><span>Overdue only</span></label>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="meta.per_page" :totalRecords="meta.total"
      :rowsPerPageOptions="[25, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="Invoice">
        <template #body="{ data }">
          {{ data.invoice_number || '—' }}
          <br v-if="data.lc_number" />
          <small v-if="data.lc_number" class="muted">L/C {{ data.lc_number }}</small>
        </template>
      </Column>
      <Column header="Value">
        <template #body="{ data }">
          {{ data.currency }} {{ money(data.invoice_value) }}
          <br /><small class="muted">base {{ money(data.invoice_value_base) }}</small>
        </template>
      </Column>
      <Column header="Settled"><template #body="{ data }">{{ money(data.amount_settled) }}</template></Column>
      <Column header="Outstanding">
        <template #body="{ data }"><strong class="bad">{{ money(data.outstanding_amount) }}</strong></template>
      </Column>
      <Column header="Due">
        <template #body="{ data }">
          {{ data.payment_due_date || '—' }}
          <br v-if="agedDays(data)" />
          <small v-if="agedDays(data)" class="bad">{{ agedDays(data) }} days overdue</small>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="paymentStatusSeverity[data.payment_status]" :value="humanize(data.payment_status)" />
          <br />
          <Tag :severity="shipmentStatusSeverity[data.status]" :value="humanize(data.status)" class="mini" />
        </template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <Button v-if="canPay" :label="data.direction === 'import' ? 'Remit' : 'Receive'" size="small"
            severity="success" outlined @click="openSettle(data)" />
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openShipment(data)" />
        </template>
      </Column>
      <template #empty>Nothing outstanding.</template>
    </DataTable>

    <Dialog :visible="!!row" modal :style="{ width: '560px' }"
      :header="row?.direction === 'import' ? `Remit — ${row?.shipment_number}` : `Receive — ${row?.shipment_number}`"
      @update:visible="v => { if (!v) row = null }">
      <div v-if="form" class="form-grid">
        <div class="fld"><label>Date *</label><input type="date" v-model="form.payment_date" class="date-input" /></div>
        <div class="fld">
          <label>Mode *</label>
          <Select v-model="form.mode" :options="paymentModes" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Currency</label><InputText v-model="form.currency" maxlength="3" /></div>
        <div class="fld"><label>Exchange rate</label><InputNumber v-model="form.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" /></div>
        <div class="fld"><label>Amount *</label><InputNumber v-model="form.amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>Reference</label><InputText v-model="form.reference" placeholder="UTR / cheque / L/C ref" /></div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="form.remarks" rows="2" /></div>
        <p class="total-hint">
          Settles <strong>{{ money(Number(form.amount || 0) * Number(form.exchange_rate || 1)) }}</strong>
          of {{ money(row?.outstanding_amount) }} outstanding
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="row = null" />
        <Button label="Save" :loading="saving" @click="save" />
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

.summary-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.sum { display: flex; flex-direction: column; border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.6rem 0.9rem; min-width: 150px; }
.sum-value { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }
.sum-label { font-size: 0.72rem; color: #6b7280; }
.sum.good .sum-value { color: #16a34a; }
.sum.bad .sum-value { color: #dc2626; }

.toolbar { display: flex; gap: 1.25rem; margin-bottom: 0.9rem; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.78rem; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; }
</style>
