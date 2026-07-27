<script setup lang="ts">
// One shipment, in full: the trade/schedule/money header plus the five child
// records the desk works through — containers, documents, milestones, logistics
// costs and settlements. Opened from the Active Import / Active Export screens
// and from the boards, so every list can drill into the same detail.
//
// Writes go straight to the child endpoints and emit `changed`, letting the
// parent list refresh its own row rather than each screen re-implementing the
// child forms.
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../../api/http'
import { useAuthStore } from '../../stores/auth'
import ImportChainPanel from './ImportChainPanel.vue'
import ExportChainPanel from './ExportChainPanel.vue'
import {
  useTradeCatalogue, humanize, statusOptions,
  shipmentStatusSeverity, customsStatusSeverity, paymentStatusSeverity, containerStatusSeverity,
} from '../../composables/useTradeCatalogue'

const props = defineProps<{ visible: boolean; shipmentId: number | null }>()
const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'changed'): void
}>()

const toast = useToast()
const auth = useAuthStore()
const { catalogue, load, documentsFor, nextStatuses } = useTradeCatalogue()

const shipment = ref<any>(null)
const loading = ref(false)
const activeTab = ref('overview')

const canEdit = computed(() => auth.can('shipments.edit'))
const canCustoms = computed(() => auth.can('shipment-customs.edit'))
const canContainers = computed(() => auth.can('shipment-containers.edit'))
const canDocuments = computed(() => auth.can('shipment-documents.edit'))
const canExpenses = computed(() => auth.can('shipment-expenses.edit'))
const canPay = computed(() => auth.can('shipment-payments.create'))

const isImport = computed(() => shipment.value?.direction === 'import')

async function reload() {
  if (!props.shipmentId) return
  loading.value = true
  try {
    const [{ data }] = await Promise.all([http.get(`/shipments/${props.shipmentId}`), load()])
    shipment.value = data.data ?? data
    await loadTimeline()
  } finally {
    loading.value = false
  }
}

watch(() => [props.visible, props.shipmentId], ([visible]) => {
  if (visible) {
    activeTab.value = 'overview'
    reload()
  }
})

function close() {
  emit('update:visible', false)
}

function money(value: any): string {
  const n = Number(value ?? 0)
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fail(e: any, fallback = 'Failed') {
  toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || fallback, life: 4000 })
}

async function afterWrite(message: string) {
  toast.add({ severity: 'success', summary: message, life: 2200 })
  await reload()
  emit('changed')
}

// ---------------------------------------------------------------- status track
const statusBusy = ref(false)
async function moveStatus(target: string) {
  const res = await Swal.fire({
    title: `Move ${shipment.value.shipment_number} to "${humanize(target)}"?`,
    input: 'text', inputLabel: 'Location / remarks (optional)',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Move',
  })
  if (!res.isConfirmed) return
  statusBusy.value = true
  try {
    await http.post(`/shipments/${shipment.value.id}/status`, { status: target, remarks: res.value || null })
    await afterWrite('Status updated')
  } catch (e) {
    fail(e)
  } finally {
    statusBusy.value = false
  }
}

// --------------------------------------------------------------- customs track
const customsForm = ref<any>(null)
const customsSaving = ref(false)
function openCustoms() {
  const s = shipment.value
  customsForm.value = {
    customs_status: s.customs_status,
    // Leg-bound, so it is never read back off a shipment that stored the other one.
    customs_entry_type: isImport.value ? 'bill_of_entry' : 'shipping_bill',
    customs_entry_number: s.customs_entry_number || '',
    customs_entry_date: s.customs_entry_date || '',
    icegate_reference: s.icegate_reference || '',
    assessable_value: Number(s.assessable_value ?? 0),
    duty_bcd: Number(s.duty_bcd ?? 0),
    duty_igst: Number(s.duty_igst ?? 0),
    duty_cess: Number(s.duty_cess ?? 0),
    duty_other: Number(s.duty_other ?? 0),
    remarks: '',
  }
}
const dutyPreview = computed(() => {
  const f = customsForm.value
  if (!f) return 0
  return Number(f.duty_bcd || 0) + Number(f.duty_igst || 0) + Number(f.duty_cess || 0) + Number(f.duty_other || 0)
})
async function saveCustoms() {
  customsSaving.value = true
  try {
    await http.post(`/shipments/${shipment.value.id}/customs`, customsForm.value)
    customsForm.value = null
    await afterWrite('Customs updated')
  } catch (e) {
    fail(e)
  } finally {
    customsSaving.value = false
  }
}

// ------------------------------------------------------------------- timeline
const events = ref<any[]>([])
const eventForm = ref<any>(null)
async function loadTimeline() {
  if (!props.shipmentId) return
  const { data } = await http.get(`/shipments/${props.shipmentId}/timeline`)
  events.value = data.data
}
function openEvent() {
  eventForm.value = { event_type: 'in_transit', event_at: new Date().toISOString().slice(0, 16), location: '', description: '' }
}
async function saveEvent() {
  try {
    await http.post(`/shipments/${shipment.value.id}/events`, eventForm.value)
    eventForm.value = null
    await afterWrite('Milestone added')
  } catch (e) {
    fail(e)
  }
}
async function removeEvent(row: any) {
  try {
    await http.delete(`/shipments/${shipment.value.id}/events/${row.id}`)
    await afterWrite('Milestone removed')
  } catch (e) {
    fail(e, 'System milestones cannot be deleted.')
  }
}

// ------------------------------------------------------------------ containers
const containerForm = ref<any>(null)
const containerTypes = ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'].map(v => ({ value: v, label: v.toUpperCase() }))
function openContainer(row: any = null) {
  containerForm.value = row
    ? { ...row, gross_weight_kg: Number(row.gross_weight_kg ?? 0) || null, cbm: Number(row.cbm ?? 0) || null }
    : { container_number: '', container_type: '20ft', seal_number: '', packages: 0, gross_weight_kg: null, cbm: null, free_days: 0, remarks: '' }
}
async function saveContainer() {
  const f = containerForm.value
  try {
    if (f.id) await http.put(`/shipment-containers/${f.id}`, f)
    else await http.post('/shipment-containers', { ...f, shipment_id: shipment.value.id })
    containerForm.value = null
    await afterWrite('Container saved')
  } catch (e) {
    fail(e)
  }
}
async function moveContainer(row: any) {
  const options = containerStatusList.filter(s => s !== row.status)
  const res = await Swal.fire({
    title: `Move ${row.container_number}`,
    input: 'select',
    inputOptions: Object.fromEntries(options.map(s => [s, humanize(s)])),
    inputPlaceholder: 'New status',
    showCancelButton: true, confirmButtonText: 'Record move',
  })
  if (!res.isConfirmed || !res.value) return
  try {
    await http.post(`/shipment-containers/${row.id}/move`, { status: res.value })
    await afterWrite('Container moved')
  } catch (e) {
    fail(e)
  }
}
/**
 * Price a box's overdue days into a cost before the carrier's invoice arrives.
 * Offered only while the box is actually accruing, so the days come from the
 * shipment rather than being typed in. Demurrage is what the port bills for a
 * box still inside it; once it is out on the road the same overrun is detention.
 */
const canChargeDetention = computed(() =>
  auth.can('shipment-containers.edit') && auth.can('shipment-expenses.create'))

async function chargeDetention(row: any) {
  const inPort = ['gated_in', 'loaded', 'discharged'].includes(row.status)
  const head = inPort ? 'demurrage' : 'detention'
  const res = await Swal.fire({
    title: `Charge ${head} on ${row.container_number}`,
    text: `${row.detention_days} day(s) past the free days, which ran to ${row.detention_free_until}.`,
    input: 'number',
    inputLabel: 'Rate per day',
    inputAttributes: { min: '0.01', step: '0.01' },
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Post charge',
  })
  if (!res.isConfirmed || !res.value) return
  try {
    const { data } = await http.post(`/shipment-containers/${row.id}/detention-charge`, {
      expense_type: head,
      rate_per_day: Number(res.value),
    })
    await afterWrite(`${data.message} (${data.days_charged} day(s))`)
  } catch (e) {
    fail(e)
  }
}

async function removeContainer(row: any) {
  const res = await Swal.fire({ title: `Remove ${row.container_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-containers/${row.id}`)
    await afterWrite('Container removed')
  } catch (e) {
    fail(e)
  }
}
const containerStatusList = [
  'planned', 'stuffed', 'gated_in', 'loaded', 'in_transit', 'discharged', 'gated_out', 'delivered', 'empty_returned',
]

// ------------------------------------------------------------------- documents
const docForm = ref<any>(null)
const docStatuses = statusOptions(['pending', 'received', 'submitted', 'verified', 'rejected'])
function openDoc(row: any = null) {
  docForm.value = row
    ? { ...row }
    : { doc_type: documentsFor(shipment.value.direction)[0]?.value ?? 'other', doc_number: '', doc_date: '', due_date: '', status: 'pending', is_mandatory: false, remarks: '' }
}
async function saveDoc() {
  const f = docForm.value
  try {
    if (f.id) await http.put(`/shipment-documents/${f.id}`, f)
    else await http.post('/shipment-documents', { ...f, shipment_id: shipment.value.id })
    docForm.value = null
    await afterWrite('Document saved')
  } catch (e) {
    fail(e)
  }
}
async function uploadDoc(row: any, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  try {
    await http.post(`/shipment-documents/${row.id}/file`, body)
    await afterWrite('File uploaded')
  } catch (e) {
    fail(e, 'Upload failed')
  } finally {
    input.value = ''
  }
}
async function removeDoc(row: any) {
  const res = await Swal.fire({ title: `Remove ${row.doc_label}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-documents/${row.id}`)
    await afterWrite('Document removed')
  } catch (e) {
    fail(e)
  }
}

// -------------------------------------------------------------------- expenses
const expenseForm = ref<any>(null)
function openExpense(row: any = null) {
  expenseForm.value = row
    ? { ...row, amount: Number(row.amount), exchange_rate: Number(row.exchange_rate), paid_amount: Number(row.paid_amount) }
    : {
      expense_type: catalogue.value.expense_types[0]?.value ?? 'other',
      expense_date: new Date().toISOString().slice(0, 10),
      vendor_type: 'other', vendor_id: null, vendor_name: '', invoice_number: '',
      currency: 'INR', exchange_rate: 1, amount: 0, paid_amount: 0, due_date: '', is_billable: false, remarks: '',
    }
}
// Vendor pickers read the master matching the chosen vendor type.
const vendorEndpoints: Record<string, string> = {
  freight_forwarder: '/freight-forwarders',
  cha_agent: '/cha-agents',
  transporter: '/transporters',
  shipping_line: '/shipping-lines',
  airline: '/airlines',
  supplier: '/suppliers',
}
const vendorOptions = ref<any[]>([])
async function loadVendors(type: string) {
  vendorOptions.value = []
  const endpoint = vendorEndpoints[type]
  if (!endpoint) return
  const { data } = await http.get(endpoint, { params: { per_page: 100 } })
  vendorOptions.value = (data.data ?? data).map((v: any) => ({ label: v.name, value: v.id }))
}
watch(() => expenseForm.value?.vendor_type, type => {
  if (type && type !== 'other') loadVendors(type)
  else vendorOptions.value = []
})
async function saveExpense() {
  const f = expenseForm.value
  try {
    if (f.id) await http.put(`/shipment-expenses/${f.id}`, f)
    else await http.post('/shipment-expenses', { ...f, shipment_id: shipment.value.id })
    expenseForm.value = null
    await afterWrite('Expense saved')
  } catch (e) {
    fail(e)
  }
}
async function removeExpense(row: any) {
  const res = await Swal.fire({ title: 'Remove this cost?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-expenses/${row.id}`)
    await afterWrite('Expense removed')
  } catch (e) {
    fail(e)
  }
}

// -------------------------------------------------------------------- payments
const paymentForm = ref<any>(null)
const paymentModes = statusOptions(['bank_transfer', 'lc', 'cheque', 'cash', 'upi', 'other'])
function openPayment() {
  paymentForm.value = {
    payment_date: new Date().toISOString().slice(0, 10),
    currency: shipment.value.currency, exchange_rate: Number(shipment.value.exchange_rate),
    amount: 0, mode: 'bank_transfer', reference: '', remarks: '',
  }
}
async function savePayment() {
  try {
    await http.post('/shipment-payments', { ...paymentForm.value, shipment_id: shipment.value.id })
    paymentForm.value = null
    await afterWrite(isImport.value ? 'Remittance recorded' : 'Receipt recorded')
  } catch (e) {
    fail(e)
  }
}
async function removePayment(row: any) {
  const res = await Swal.fire({ title: 'Remove this settlement?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-payments/${row.id}`)
    await afterWrite('Settlement removed')
  } catch (e) {
    fail(e)
  }
}
</script>

<template>
  <Dialog :visible="visible" modal :style="{ width: '1040px' }" :breakpoints="{ '1200px': '95vw' }"
    @update:visible="v => emit('update:visible', v)">
    <template #header>
      <div class="dlg-head">
        <strong>{{ shipment?.shipment_number ?? 'Shipment' }}</strong>
        <Tag v-if="shipment" :severity="shipment.direction === 'import' ? 'info' : 'success'"
          :value="shipment.direction === 'import' ? 'Import' : 'Export'" />
        <Tag v-if="shipment" :severity="shipmentStatusSeverity[shipment.status]" :value="humanize(shipment.status)" />
        <Tag v-if="shipment" :severity="customsStatusSeverity[shipment.customs_status]"
          :value="`Customs: ${humanize(shipment.customs_status)}`" />
        <Tag v-if="shipment" :severity="paymentStatusSeverity[shipment.payment_status]"
          :value="humanize(shipment.payment_status)" />
      </div>
    </template>

    <div v-if="loading && !shipment" class="muted">Loading…</div>

    <template v-else-if="shipment">
      <!-- Status track: only the moves the workflow allows from here. -->
      <div v-if="canEdit && nextStatuses(shipment.status).length" class="track">
        <span class="track-label">Move to</span>
        <Button v-for="s in nextStatuses(shipment.status)" :key="s" :label="humanize(s)" size="small"
          :severity="s === 'cancelled' ? 'danger' : 'success'" :outlined="s === 'cancelled'"
          :loading="statusBusy" @click="moveStatus(s)" />
      </div>

      <Tabs v-model:value="activeTab">
        <TabList>
          <Tab value="overview">Overview</Tab>
          <Tab value="chain">{{ isImport ? 'Import Chain' : 'Export Chain' }}</Tab>
          <Tab value="timeline">Timeline ({{ events.length }})</Tab>
          <Tab value="containers">Containers ({{ shipment.containers?.length ?? 0 }})</Tab>
          <Tab value="documents">Documents ({{ shipment.documents?.length ?? 0 }})</Tab>
          <Tab value="customs">Customs</Tab>
          <Tab value="expenses">Expenses ({{ shipment.expenses?.length ?? 0 }})</Tab>
          <Tab value="payments">Payments ({{ shipment.payments?.length ?? 0 }})</Tab>
        </TabList>

        <TabPanels>
          <!-- ------------------------------------------------ overview -->
          <TabPanel value="overview">
            <div class="grid-3">
              <section class="card">
                <h4>Trade</h4>
                <dl>
                  <div><dt>{{ isImport ? 'Supplier' : 'Customer' }}</dt><dd>{{ shipment.party || '—' }}</dd></div>
                  <div><dt>Incoterm</dt><dd>{{ shipment.incoterm }}</dd></div>
                  <div><dt>Mode</dt><dd>{{ humanize(shipment.mode) }}</dd></div>
                  <div><dt>Carrier</dt><dd>{{ shipment.carrier || '—' }}</dd></div>
                  <div><dt>Forwarder</dt><dd>{{ shipment.freight_forwarder || '—' }}</dd></div>
                  <div><dt>CHA</dt><dd>{{ shipment.cha_agent || '—' }}</dd></div>
                  <div><dt>Transporter</dt><dd>{{ shipment.transporter || '—' }}</dd></div>
                  <div><dt>{{ shipment.mode === 'air' ? 'AWB' : 'B/L' }} No.</dt><dd>{{ shipment.bl_awb_number || '—' }}</dd></div>
                  <div><dt>Vessel / Voyage</dt><dd>{{ shipment.vessel_name || '—' }} {{ shipment.voyage_number ? `/ ${shipment.voyage_number}` : '' }}</dd></div>
                </dl>
              </section>

              <section class="card">
                <h4>Route &amp; schedule</h4>
                <dl>
                  <div><dt>Origin</dt><dd>{{ shipment.origin_country || '—' }}</dd></div>
                  <div><dt>Destination</dt><dd>{{ shipment.destination_country || '—' }}</dd></div>
                  <div><dt>Port of loading</dt><dd>{{ shipment.port_of_loading || '—' }}</dd></div>
                  <div><dt>Port of discharge</dt><dd>{{ shipment.port_of_discharge || '—' }}</dd></div>
                  <div><dt>Final place</dt><dd>{{ shipment.final_place || '—' }}</dd></div>
                  <div><dt>ETD → ATD</dt><dd>{{ shipment.etd || '—' }} → {{ shipment.atd || '—' }}</dd></div>
                  <div><dt>ETA → ATA</dt><dd>{{ shipment.eta || '—' }} → {{ shipment.ata || '—' }}</dd></div>
                  <div>
                    <dt>Delay</dt>
                    <dd>
                      <span v-if="shipment.eta_variance_days === null">—</span>
                      <span v-else :class="shipment.eta_variance_days > 0 ? 'bad' : 'good'">
                        {{ shipment.eta_variance_days > 0 ? `${shipment.eta_variance_days} days late` : `${-shipment.eta_variance_days} days early` }}
                      </span>
                    </dd>
                  </div>
                  <div><dt>Warehouse</dt><dd>{{ shipment.warehouse || '—' }}</dd></div>
                </dl>
              </section>

              <section class="card">
                <h4>Cargo &amp; money</h4>
                <dl>
                  <div><dt>Goods</dt><dd>{{ shipment.goods_description || '—' }}</dd></div>
                  <div><dt>Packages</dt><dd>{{ shipment.packages }}</dd></div>
                  <div><dt>Gross / Net kg</dt><dd>{{ shipment.gross_weight_kg || '—' }} / {{ shipment.net_weight_kg || '—' }}</dd></div>
                  <div><dt>CBM</dt><dd>{{ shipment.cbm || '—' }}</dd></div>
                  <div><dt>Invoice</dt><dd>{{ shipment.invoice_number || '—' }} <small v-if="shipment.invoice_date">({{ shipment.invoice_date }})</small></dd></div>
                  <div><dt>Value</dt><dd>{{ shipment.currency }} {{ money(shipment.invoice_value) }} <small>@ {{ shipment.exchange_rate }}</small></dd></div>
                  <div><dt>Value (base)</dt><dd><strong>{{ money(shipment.invoice_value_base) }}</strong></dd></div>
                  <div><dt>Settled</dt><dd>{{ money(shipment.amount_settled) }}</dd></div>
                  <div><dt>Outstanding</dt><dd :class="Number(shipment.outstanding_amount) > 0 ? 'bad' : 'good'"><strong>{{ money(shipment.outstanding_amount) }}</strong></dd></div>
                  <div><dt>Payment due</dt><dd>{{ shipment.payment_due_date || '—' }}</dd></div>
                  <div><dt>L/C</dt><dd>{{ shipment.lc_number || '—' }} <small v-if="shipment.lc_expiry">(exp. {{ shipment.lc_expiry }})</small></dd></div>
                </dl>
              </section>
            </div>
            <p v-if="shipment.remarks" class="remarks"><strong>Remarks:</strong> {{ shipment.remarks }}</p>
          </TabPanel>

          <!-- ------------------------------------------ import/export chain -->
          <!-- Import: PO → supplier invoice → B/L → container → vessel →
               freight → customs → warehouse receipt.
               Export: SO → packing list → booking → shipping bill → ETD →
               customer invoice → dispatch.
               One tab either way, with the action for each step. -->
          <TabPanel value="chain">
            <ImportChainPanel v-if="isImport" :shipment="shipment" @done="afterWrite" @tab="t => activeTab = t" />
            <ExportChainPanel v-else :shipment="shipment" @done="afterWrite" @tab="t => activeTab = t" />
          </TabPanel>

          <!-- ------------------------------------------------ timeline -->
          <TabPanel value="timeline">
            <div class="panel-actions">
              <Button v-if="canEdit" label="Add milestone" icon="pi pi-plus" size="small" @click="openEvent" />
            </div>
            <ol v-if="events.length" class="timeline">
              <li v-for="e in events" :key="e.id" :class="{ system: e.source === 'system' }">
                <span class="dot" />
                <div class="tl-body">
                  <div class="tl-top">
                    <strong>{{ e.event_label }}</strong>
                    <span class="muted">{{ new Date(e.event_at).toLocaleString() }}</span>
                    <Tag v-if="e.source === 'manual'" value="manual" severity="secondary" class="mini" />
                    <Button v-if="canEdit && e.source === 'manual'" icon="pi pi-times" text rounded size="small"
                      severity="danger" @click="removeEvent(e)" />
                  </div>
                  <div class="tl-desc">{{ e.description }}</div>
                  <div v-if="e.location" class="muted"><i class="pi pi-map-marker" /> {{ e.location }}</div>
                </div>
              </li>
            </ol>
            <p v-else class="muted">No milestones yet.</p>
          </TabPanel>

          <!-- ---------------------------------------------- containers -->
          <TabPanel value="containers">
            <div class="panel-actions">
              <Button v-if="canContainers" label="Add container" icon="pi pi-plus" size="small" @click="openContainer()" />
            </div>
            <DataTable :value="shipment.containers" class="p-datatable-sm" dataKey="id">
              <Column field="container_number" header="Container #" />
              <Column header="Type"><template #body="{ data }">{{ data.container_type.toUpperCase() }}</template></Column>
              <Column field="seal_number" header="Seal" />
              <Column header="Status">
                <template #body="{ data }"><Tag :severity="containerStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
              </Column>
              <Column field="current_location" header="Location" />
              <Column header="Detention">
                <template #body="{ data }">
                  <span v-if="data.accruing_detention" class="bad">{{ data.detention_days }} d over</span>
                  <span v-else-if="data.detention_free_until" class="muted">free to {{ data.detention_free_until }}</span>
                  <span v-else>—</span>
                </template>
              </Column>
              <Column header="Damage">
                <template #body="{ data }">
                  <Tag v-if="data.is_damaged" severity="danger" value="Damaged" class="mini" />
                  <span v-else class="muted">—</span>
                </template>
              </Column>
              <Column header="" style="width: 13rem">
                <template #body="{ data }">
                  <Button v-if="canContainers" label="Move" size="small" outlined @click="moveContainer(data)" />
                  <Button v-if="canChargeDetention && data.accruing_detention" icon="pi pi-clock" text rounded
                    size="small" severity="warn" title="Charge the overdue days" @click="chargeDetention(data)" />
                  <Button v-if="canContainers" icon="pi pi-pencil" text rounded size="small" @click="openContainer(data)" />
                  <Button v-if="auth.can('shipment-containers.delete')" icon="pi pi-trash" text rounded size="small"
                    severity="danger" @click="removeContainer(data)" />
                </template>
              </Column>
              <template #empty>No containers booked yet.</template>
            </DataTable>
          </TabPanel>

          <!-- ----------------------------------------------- documents -->
          <TabPanel value="documents">
            <div class="panel-actions">
              <Button v-if="canDocuments" label="Add document" icon="pi pi-plus" size="small" @click="openDoc()" />
            </div>
            <DataTable :value="shipment.documents" class="p-datatable-sm" dataKey="id">
              <Column header="Document">
                <template #body="{ data }">
                  {{ data.doc_label }}
                  <Tag v-if="data.is_mandatory" value="required" severity="secondary" class="mini" />
                </template>
              </Column>
              <Column field="doc_number" header="Number" />
              <Column field="doc_date" header="Date" />
              <Column header="Due">
                <template #body="{ data }">
                  <span :class="{ bad: data.is_overdue }">{{ data.due_date || '—' }}</span>
                </template>
              </Column>
              <Column header="Status">
                <template #body="{ data }">
                  <Tag :severity="data.status === 'pending' ? 'warn' : data.status === 'rejected' ? 'danger' : 'success'"
                    :value="humanize(data.status)" />
                </template>
              </Column>
              <Column header="File">
                <template #body="{ data }">
                  <a v-if="data.file_url" :href="data.file_url" target="_blank" rel="noopener">
                    <i class="pi pi-paperclip" /> open
                  </a>
                  <span v-else class="muted">—</span>
                </template>
              </Column>
              <Column header="" style="width: 11rem">
                <template #body="{ data }">
                  <label v-if="canDocuments" class="upload-btn" title="Upload scan">
                    <i class="pi pi-upload" />
                    <input type="file" hidden @change="e => uploadDoc(data, e)" />
                  </label>
                  <Button v-if="canDocuments" icon="pi pi-pencil" text rounded size="small" @click="openDoc(data)" />
                  <Button v-if="auth.can('shipment-documents.delete')" icon="pi pi-trash" text rounded size="small"
                    severity="danger" @click="removeDoc(data)" />
                </template>
              </Column>
              <template #empty>No documents on the checklist.</template>
            </DataTable>
          </TabPanel>

          <!-- ------------------------------------------------- customs -->
          <TabPanel value="customs">
            <div class="panel-actions">
              <Button v-if="canCustoms" label="Update customs" icon="pi pi-pencil" size="small" @click="openCustoms" />
            </div>
            <div class="grid-2">
              <section class="card">
                <h4>{{ isImport ? 'Bill of Entry' : 'Shipping Bill' }}</h4>
                <dl>
                  <div><dt>Status</dt><dd><Tag :severity="customsStatusSeverity[shipment.customs_status]" :value="humanize(shipment.customs_status)" /></dd></div>
                  <div><dt>Entry no.</dt><dd>{{ shipment.customs_entry_number || '—' }}</dd></div>
                  <div><dt>Entry date</dt><dd>{{ shipment.customs_entry_date || '—' }}</dd></div>
                  <div><dt>ICEGATE ref.</dt><dd>{{ shipment.icegate_reference || '—' }}</dd></div>
                  <div><dt>CHA</dt><dd>{{ shipment.cha_agent || '—' }}</dd></div>
                  <div><dt>Cleared at</dt><dd>{{ shipment.customs_cleared_at ? new Date(shipment.customs_cleared_at).toLocaleString() : '—' }}</dd></div>
                </dl>
              </section>
              <section class="card">
                <h4>Duty</h4>
                <dl>
                  <div><dt>Assessable value</dt><dd>{{ money(shipment.assessable_value) }}</dd></div>
                  <div><dt>BCD</dt><dd>{{ money(shipment.duty_bcd) }}</dd></div>
                  <div><dt>IGST</dt><dd>{{ money(shipment.duty_igst) }}</dd></div>
                  <div><dt>Cess / SWS</dt><dd>{{ money(shipment.duty_cess) }}</dd></div>
                  <div><dt>Other</dt><dd>{{ money(shipment.duty_other) }}</dd></div>
                  <div><dt>Total duty</dt><dd><strong>{{ money(shipment.duty_total) }}</strong></dd></div>
                  <div><dt>Duty paid</dt><dd>{{ shipment.duty_paid_at ? new Date(shipment.duty_paid_at).toLocaleString() : '—' }}</dd></div>
                </dl>
              </section>
            </div>
          </TabPanel>

          <!-- ------------------------------------------------ expenses -->
          <TabPanel value="expenses">
            <div class="panel-actions">
              <Button v-if="canExpenses" label="Add cost" icon="pi pi-plus" size="small" @click="openExpense()" />
            </div>
            <DataTable :value="shipment.expenses" class="p-datatable-sm" dataKey="id">
              <Column field="expense_label" header="Head" />
              <Column field="expense_date" header="Date" />
              <Column field="vendor_name" header="Vendor" />
              <Column field="invoice_number" header="Invoice" />
              <Column header="Amount">
                <template #body="{ data }">{{ data.currency }} {{ money(data.amount) }}</template>
              </Column>
              <Column header="Base"><template #body="{ data }">{{ money(data.base_amount) }}</template></Column>
              <Column header="Paid">
                <template #body="{ data }">
                  {{ money(data.paid_amount) }}
                  <Tag :severity="paymentStatusSeverity[data.payment_status]" :value="humanize(data.payment_status)" class="mini" />
                </template>
              </Column>
              <Column header="Billable">
                <template #body="{ data }"><i :class="data.is_billable ? 'pi pi-check good' : 'pi pi-minus muted'" /></template>
              </Column>
              <Column header="" style="width: 7rem">
                <template #body="{ data }">
                  <Button v-if="canExpenses" icon="pi pi-pencil" text rounded size="small" @click="openExpense(data)" />
                  <Button v-if="auth.can('shipment-expenses.delete')" icon="pi pi-trash" text rounded size="small"
                    severity="danger" @click="removeExpense(data)" />
                </template>
              </Column>
              <template #empty>No logistics costs booked yet.</template>
            </DataTable>
          </TabPanel>

          <!-- ------------------------------------------------ payments -->
          <TabPanel value="payments">
            <div class="panel-actions">
              <span class="muted">
                {{ isImport ? 'Remittances to the overseas supplier' : 'Export proceeds received' }} —
                outstanding <strong>{{ money(shipment.outstanding_amount) }}</strong>
              </span>
              <Button v-if="canPay" :label="isImport ? 'Record remittance' : 'Record receipt'" icon="pi pi-plus"
                size="small" @click="openPayment" />
            </div>
            <DataTable :value="shipment.payments" class="p-datatable-sm" dataKey="id">
              <Column field="payment_date" header="Date" />
              <Column header="Direction"><template #body="{ data }">{{ humanize(data.direction) }}</template></Column>
              <Column header="Amount"><template #body="{ data }">{{ data.currency }} {{ money(data.amount) }}</template></Column>
              <Column header="Base"><template #body="{ data }">{{ money(data.base_amount) }}</template></Column>
              <Column header="Mode"><template #body="{ data }">{{ humanize(data.mode) }}</template></Column>
              <Column field="reference" header="Reference" />
              <Column header="" style="width: 4rem">
                <template #body="{ data }">
                  <Button v-if="auth.can('shipment-payments.delete')" icon="pi pi-trash" text rounded size="small"
                    severity="danger" @click="removePayment(data)" />
                </template>
              </Column>
              <template #empty>Nothing settled yet.</template>
            </DataTable>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>

    <template #footer>
      <Button label="Close" text @click="close" />
    </template>
  </Dialog>

  <!-- ---------------------------------------------------- child form dialogs -->
  <Dialog :visible="!!customsForm" modal header="Customs" :style="{ width: '620px' }"
    @update:visible="v => { if (!v) customsForm = null }">
    <div v-if="customsForm" class="form-grid">
      <div class="fld">
        <label>Status *</label>
        <Select v-model="customsForm.customs_status" :options="statusOptions(['pending', 'filed', 'assessed', 'duty_paid', 'cleared', 'on_hold'])"
          optionLabel="label" optionValue="value" />
      </div>
      <div class="fld">
        <!-- The leg decides the entry, so it is shown, not chosen. -->
        <label>Entry type</label>
        <InputText :model-value="isImport ? 'Bill of Entry' : 'Shipping Bill'" disabled />
      </div>
      <div class="fld"><label>Entry no.</label><InputText v-model="customsForm.customs_entry_number" /></div>
      <div class="fld"><label>Entry date</label><input type="date" v-model="customsForm.customs_entry_date" class="date-input" /></div>
      <div class="fld"><label>ICEGATE reference</label><InputText v-model="customsForm.icegate_reference" /></div>
      <div class="fld"><label>Assessable value</label><InputNumber v-model="customsForm.assessable_value" :minFractionDigits="2" /></div>
      <div class="fld"><label>BCD</label><InputNumber v-model="customsForm.duty_bcd" :minFractionDigits="2" /></div>
      <div class="fld"><label>IGST</label><InputNumber v-model="customsForm.duty_igst" :minFractionDigits="2" /></div>
      <div class="fld"><label>Cess / SWS</label><InputNumber v-model="customsForm.duty_cess" :minFractionDigits="2" /></div>
      <div class="fld"><label>Other duty</label><InputNumber v-model="customsForm.duty_other" :minFractionDigits="2" /></div>
      <div class="fld full"><label>Remarks</label><Textarea v-model="customsForm.remarks" rows="2" /></div>
      <p class="total-hint">Total duty <strong>{{ money(dutyPreview) }}</strong></p>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="customsForm = null" />
      <Button label="Save" :loading="customsSaving" @click="saveCustoms" />
    </template>
  </Dialog>

  <Dialog :visible="!!eventForm" modal header="Add milestone" :style="{ width: '520px' }"
    @update:visible="v => { if (!v) eventForm = null }">
    <div v-if="eventForm" class="form-grid">
      <div class="fld">
        <label>Milestone *</label>
        <Select v-model="eventForm.event_type" :options="catalogue.milestones" optionLabel="label" optionValue="value" filter />
      </div>
      <div class="fld"><label>When *</label><input type="datetime-local" v-model="eventForm.event_at" class="date-input" /></div>
      <div class="fld"><label>Location</label><InputText v-model="eventForm.location" /></div>
      <div class="fld full"><label>Description</label><Textarea v-model="eventForm.description" rows="2" /></div>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="eventForm = null" />
      <Button label="Add" @click="saveEvent" />
    </template>
  </Dialog>

  <Dialog :visible="!!containerForm" modal :header="containerForm?.id ? 'Edit container' : 'Add container'"
    :style="{ width: '620px' }" @update:visible="v => { if (!v) containerForm = null }">
    <div v-if="containerForm" class="form-grid">
      <div class="fld"><label>Container no. *</label><InputText v-model="containerForm.container_number" /></div>
      <div class="fld">
        <label>Type *</label>
        <Select v-model="containerForm.container_type" :options="containerTypes" optionLabel="label" optionValue="value" />
      </div>
      <div class="fld"><label>Seal no.</label><InputText v-model="containerForm.seal_number" /></div>
      <div class="fld"><label>Packages</label><InputNumber v-model="containerForm.packages" :min="0" /></div>
      <div class="fld"><label>Gross kg</label><InputNumber v-model="containerForm.gross_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></div>
      <div class="fld"><label>CBM</label><InputNumber v-model="containerForm.cbm" :minFractionDigits="0" :maxFractionDigits="3" /></div>
      <div class="fld">
        <label>Free days</label>
        <InputNumber v-model="containerForm.free_days" :min="0" />
        <small class="muted">Detention starts the day after gate-in + free days.</small>
      </div>
      <div class="fld chk">
        <Checkbox v-model="containerForm.is_damaged" :binary="true" inputId="dmg" />
        <label for="dmg">Damaged</label>
      </div>
      <div class="fld full" v-if="containerForm.is_damaged">
        <label>Damage details</label><Textarea v-model="containerForm.damage_remarks" rows="2" />
      </div>
      <div class="fld full"><label>Remarks</label><Textarea v-model="containerForm.remarks" rows="2" /></div>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="containerForm = null" />
      <Button label="Save" @click="saveContainer" />
    </template>
  </Dialog>

  <Dialog :visible="!!docForm" modal :header="docForm?.id ? 'Edit document' : 'Add document'"
    :style="{ width: '580px' }" @update:visible="v => { if (!v) docForm = null }">
    <div v-if="docForm" class="form-grid">
      <div class="fld">
        <label>Document *</label>
        <Select v-model="docForm.doc_type" :options="documentsFor(shipment?.direction ?? 'import')"
          optionLabel="label" optionValue="value" filter />
      </div>
      <div class="fld">
        <label>Status</label>
        <Select v-model="docForm.status" :options="docStatuses" optionLabel="label" optionValue="value" />
      </div>
      <div class="fld"><label>Number</label><InputText v-model="docForm.doc_number" /></div>
      <div class="fld"><label>Document date</label><input type="date" v-model="docForm.doc_date" class="date-input" /></div>
      <div class="fld"><label>Due date</label><input type="date" v-model="docForm.due_date" class="date-input" /></div>
      <div class="fld chk">
        <Checkbox v-model="docForm.is_mandatory" :binary="true" inputId="mand" />
        <label for="mand">Mandatory</label>
      </div>
      <div class="fld full"><label>Remarks</label><Textarea v-model="docForm.remarks" rows="2" /></div>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="docForm = null" />
      <Button label="Save" @click="saveDoc" />
    </template>
  </Dialog>

  <Dialog :visible="!!expenseForm" modal :header="expenseForm?.id ? 'Edit cost' : 'Add logistics cost'"
    :style="{ width: '680px' }" @update:visible="v => { if (!v) expenseForm = null }">
    <div v-if="expenseForm" class="form-grid">
      <div class="fld">
        <label>Head *</label>
        <Select v-model="expenseForm.expense_type" :options="catalogue.expense_types" optionLabel="label" optionValue="value" filter />
      </div>
      <div class="fld"><label>Date *</label><input type="date" v-model="expenseForm.expense_date" class="date-input" /></div>
      <div class="fld">
        <label>Billed by</label>
        <Select v-model="expenseForm.vendor_type" :options="catalogue.vendor_types" optionLabel="label" optionValue="value" />
      </div>
      <div class="fld" v-if="expenseForm.vendor_type && expenseForm.vendor_type !== 'other'">
        <label>Vendor *</label>
        <Select v-model="expenseForm.vendor_id" :options="vendorOptions" optionLabel="label" optionValue="value" filter showClear />
      </div>
      <div class="fld" v-else><label>Vendor name</label><InputText v-model="expenseForm.vendor_name" /></div>
      <div class="fld"><label>Invoice no.</label><InputText v-model="expenseForm.invoice_number" /></div>
      <div class="fld"><label>Currency</label><InputText v-model="expenseForm.currency" maxlength="3" /></div>
      <div class="fld"><label>Exchange rate</label><InputNumber v-model="expenseForm.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" /></div>
      <div class="fld"><label>Amount *</label><InputNumber v-model="expenseForm.amount" :minFractionDigits="2" /></div>
      <div class="fld"><label>Paid so far</label><InputNumber v-model="expenseForm.paid_amount" :minFractionDigits="2" /></div>
      <div class="fld"><label>Due date</label><input type="date" v-model="expenseForm.due_date" class="date-input" /></div>
      <div class="fld chk">
        <Checkbox v-model="expenseForm.is_billable" :binary="true" inputId="billable" />
        <label for="billable">Recoverable from the counterparty</label>
      </div>
      <div class="fld full"><label>Remarks</label><Textarea v-model="expenseForm.remarks" rows="2" /></div>
      <p class="total-hint">
        Base amount <strong>{{ money(Number(expenseForm.amount || 0) * Number(expenseForm.exchange_rate || 1)) }}</strong>
      </p>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="expenseForm = null" />
      <Button label="Save" @click="saveExpense" />
    </template>
  </Dialog>

  <Dialog :visible="!!paymentForm" modal :header="isImport ? 'Record remittance' : 'Record receipt'"
    :style="{ width: '560px' }" @update:visible="v => { if (!v) paymentForm = null }">
    <div v-if="paymentForm" class="form-grid">
      <div class="fld"><label>Date *</label><input type="date" v-model="paymentForm.payment_date" class="date-input" /></div>
      <div class="fld">
        <label>Mode *</label>
        <Select v-model="paymentForm.mode" :options="paymentModes" optionLabel="label" optionValue="value" />
      </div>
      <div class="fld"><label>Currency</label><InputText v-model="paymentForm.currency" maxlength="3" /></div>
      <div class="fld"><label>Exchange rate</label><InputNumber v-model="paymentForm.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" /></div>
      <div class="fld"><label>Amount *</label><InputNumber v-model="paymentForm.amount" :minFractionDigits="2" /></div>
      <div class="fld"><label>Reference</label><InputText v-model="paymentForm.reference" /></div>
      <div class="fld full"><label>Remarks</label><Textarea v-model="paymentForm.remarks" rows="2" /></div>
      <p class="total-hint">
        Settles <strong>{{ money(Number(paymentForm.amount || 0) * Number(paymentForm.exchange_rate || 1)) }}</strong>
        of {{ money(shipment?.outstanding_amount) }} outstanding
      </p>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="paymentForm = null" />
      <Button label="Save" @click="savePayment" />
    </template>
  </Dialog>
</template>

<style scoped>
.dlg-head { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.track { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
.track-label { font-size: 0.8rem; color: #6b7280; }
.panel-actions { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.6rem; flex-wrap: wrap; }
.panel-actions:only-child { justify-content: flex-end; }

.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
.card { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.9rem 1rem; }
.card h4 { margin: 0 0 0.6rem; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; }
.card dl { margin: 0; display: flex; flex-direction: column; gap: 0.35rem; }
.card dl > div { display: grid; grid-template-columns: 42% 1fr; gap: 0.5rem; align-items: baseline; }
.card dt { font-size: 0.78rem; color: #6b7280; }
.card dd { margin: 0; font-size: 0.87rem; word-break: break-word; }

.remarks { margin-top: 0.9rem; font-size: 0.85rem; }
.muted { color: #9ca3af; font-size: 0.82rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.timeline { list-style: none; margin: 0; padding: 0 0 0 1rem; border-left: 2px solid var(--border, #e8e4da); }
.timeline li { position: relative; padding: 0 0 1rem 1rem; }
.timeline .dot { position: absolute; left: -1.45rem; top: 0.35rem; width: 0.7rem; height: 0.7rem; border-radius: 50%; background: #6366f1; }
.timeline li.system .dot { background: #9ca3af; }
.tl-top { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.tl-desc { font-size: 0.86rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld.chk { flex-direction: row; align-items: center; gap: 0.5rem; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; color: #374151; }

.upload-btn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 50%; cursor: pointer; color: #6366f1; }
.upload-btn:hover { background: rgba(99, 102, 241, 0.1); }
</style>
