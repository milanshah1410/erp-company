<script setup lang="ts">
// Active Import Shipments / Active Export Shipments. One screen serves both legs
// — the route's `direction` meta picks which — because an import and an export
// are the same document with the counterparty on the other side, and keeping one
// list means the columns, filters and the detail dialog never drift apart.
//
// "Active" is the default: the list shows shipments still in play (draft →
// cleared) until the state filter is switched to all.
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
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import {
  useTradeCatalogue, humanize, statusOptions,
  shipmentStatusSeverity, customsStatusSeverity, paymentStatusSeverity,
} from '../composables/useTradeCatalogue'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shipments')
const { load: loadCatalogue } = useTradeCatalogue()

const direction = computed(() => (route.meta.direction as string) ?? 'import')
const isImport = computed(() => direction.value === 'import')
const title = computed(() => (isImport.value ? 'Active Import Shipments' : 'Active Export Shipments'))

const canCreate = computed(() => auth.can('shipments.create'))
const canEdit = computed(() => auth.can('shipments.edit'))
const canDelete = computed(() => auth.can('shipments.delete'))

// --- filters ---
const search = ref('')
const statusFilter = ref<string | null>(null)
const customsFilter = ref<string | null>(null)
const paymentFilter = ref<string | null>(null)
const stateFilter = ref<'open' | 'all'>('open')

const statusChoices = statusOptions(['draft', 'booked', 'in_transit', 'arrived', 'customs', 'cleared', 'delivered', 'closed', 'cancelled'])
const customsChoices = statusOptions(['pending', 'filed', 'assessed', 'duty_paid', 'cleared', 'on_hold'])
const paymentChoices = statusOptions(['unpaid', 'partial', 'paid'])
const stateChoices = [
  { label: 'Active only', value: 'open' },
  { label: 'All shipments', value: 'all' },
]

function params(): Record<string, any> {
  return {
    direction: direction.value,
    state: stateFilter.value,
    ...(search.value ? { search: search.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
    ...(customsFilter.value ? { customs_status: customsFilter.value } : {}),
    ...(paymentFilter.value ? { payment_status: paymentFilter.value } : {}),
  }
}
function reload() {
  crud.fetch(params())
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

// --- detail dialog ---
const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openDetail(row: any) {
  detailId.value = row.id
  detailVisible.value = true
}

// --- create / edit ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blankForm())

// Master option lists, fetched on first form open and reused after that.
const options = ref<Record<string, any[]>>({})
const optionsLoaded = ref(false)

const modeChoices = statusOptions(['sea', 'air', 'road', 'rail', 'courier'])
const incotermChoices = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CIP', 'DAP', 'DDP', 'other'].map(v => ({ label: v, value: v }))

function today() {
  return new Date().toISOString().slice(0, 10)
}
function blankForm() {
  return {
    shipment_date: today(),
    supplier_id: null, customer_id: null, purchase_order_id: null, sales_order_id: null, warehouse_id: null,
    mode: 'sea', incoterm: 'FOB',
    shipping_line_id: null, airline_id: null, freight_forwarder_id: null, cha_agent_id: null, transporter_id: null,
    bl_awb_number: '', bl_awb_date: '', vessel_name: '', voyage_number: '',
    origin_country_id: null, destination_country_id: null,
    port_of_loading_id: null, port_of_discharge_id: null, final_place: '',
    etd: '', eta: '',
    goods_description: '', packages: 0, gross_weight_kg: null, net_weight_kg: null, cbm: null,
    currency: 'USD', exchange_rate: 1, invoice_number: '', invoice_date: '',
    invoice_value: 0, freight_amount: 0, insurance_amount: 0, goods_cost: 0,
    payment_due_date: '', lc_number: '', lc_expiry: '', remarks: '',
  }
}

async function loadOptions() {
  if (optionsLoaded.value) return
  const parties = isImport.value
    ? http.get('/suppliers', { params: { per_page: 100, is_overseas: 1 } })
    : http.get('/customers', { params: { per_page: 100, is_overseas: 1 } })
  const orders = isImport.value
    ? http.get('/purchase-orders', { params: { per_page: 100 } })
    : http.get('/sales-orders', { params: { per_page: 100 } })

  const [p, o, ports, countries, lines, airlines, forwarders, chas, transporters, warehouses] = await Promise.all([
    parties, orders,
    http.get('/ports', { params: { per_page: 200 } }),
    http.get('/countries', { params: { per_page: 300 } }),
    http.get('/shipping-lines', { params: { per_page: 100 } }),
    http.get('/airlines', { params: { per_page: 100 } }),
    http.get('/freight-forwarders', { params: { per_page: 100 } }),
    http.get('/cha-agents', { params: { per_page: 100 } }),
    http.get('/transporters', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  const rows = (r: any) => r.data.data ?? r.data
  const named = (r: any) => rows(r).map((x: any) => ({ label: x.name, value: x.id }))

  options.value = {
    parties: named(p),
    orders: rows(o).map((x: any) => ({ label: x.po_number ?? x.so_number, value: x.id })),
    ports: rows(ports).map((x: any) => ({ label: `${x.name} (${x.code})`, value: x.id })),
    countries: named(countries),
    lines: named(lines),
    airlines: named(airlines),
    forwarders: named(forwarders),
    chas: named(chas),
    transporters: named(transporters),
    warehouses: named(warehouses),
  }
  optionsLoaded.value = true
}

async function openCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
  await loadOptions()
}

async function openEdit(row: any) {
  errors.value = {}
  dialogVisible.value = true
  await loadOptions()
  try {
    const { data } = await http.get(`/shipments/${row.id}`)
    const s = data.data ?? data
    editingId.value = s.id
    form.value = {
      ...blankForm(),
      ...Object.fromEntries(Object.keys(blankForm()).map(k => [k, s[k] ?? blankForm()[k as keyof ReturnType<typeof blankForm>]])),
      // Numerics arrive as decimal strings; the number inputs need real numbers.
      exchange_rate: Number(s.exchange_rate),
      invoice_value: Number(s.invoice_value),
      freight_amount: Number(s.freight_amount),
      insurance_amount: Number(s.insurance_amount),
      goods_cost: Number(s.goods_cost),
      gross_weight_kg: s.gross_weight_kg != null ? Number(s.gross_weight_kg) : null,
      net_weight_kg: s.net_weight_kg != null ? Number(s.net_weight_kg) : null,
      cbm: s.cbm != null ? Number(s.cbm) : null,
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load shipment', life: 3000 })
    dialogVisible.value = false
  }
}

// Blank date/text inputs must go over as null, not '', or the date columns reject them.
function payload(): Record<string, any> {
  const out: Record<string, any> = { ...form.value }
  for (const [k, v] of Object.entries(out)) {
    if (v === '') out[k] = null
  }
  if (!editingId.value) out.direction = direction.value
  return out
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/shipments/${editingId.value}`, payload())
      toast.add({ severity: 'success', summary: 'Shipment updated', life: 2500 })
    } else {
      await http.post('/shipments', payload())
      toast.add({ severity: 'success', summary: 'Shipment created as draft', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: `Delete ${row.shipment_number}?`, icon: 'warning',
    showCancelButton: true, confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipments/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'shipment_date', label: 'Date' },
  { key: 'party', label: isImport.value ? 'Supplier' : 'Customer' },
  { key: 'incoterm', label: 'Incoterm' },
  { key: 'carrier', label: 'Carrier' },
  { key: 'bl_awb_number', label: 'B/L or AWB' },
  { key: 'port_of_loading', label: 'POL' },
  { key: 'port_of_discharge', label: 'POD' },
  { key: 'etd', label: 'ETD' },
  { key: 'eta', label: 'ETA' },
  { key: 'status', label: 'Status' },
  { key: 'customs_status', label: 'Customs' },
  { key: 'invoice_value_base', label: 'Value (base)' },
  { key: 'outstanding_amount', label: 'Outstanding' },
]

onMounted(() => {
  loadCatalogue()
  reload()
})
// Switching between the import and export screens reuses this component.
watch(direction, () => {
  optionsLoaded.value = false
  crud.currentPage.value = 1
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="title" :count="crud.total.value" :countLabel="isImport ? 'import shipments' : 'export shipments'">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Number, B/L, invoice…" @keyup.enter="reload" />
          </span>
          <Select v-model="stateFilter" :options="stateChoices" optionLabel="label" optionValue="value"
            class="filter-sm" @change="reload" />
          <Select v-model="statusFilter" :options="statusChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any status" class="filter-sm" @change="reload" />
          <Select v-model="customsFilter" :options="customsChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any customs" class="filter-sm" @change="reload" />
          <Select v-model="paymentFilter" :options="paymentChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any payment" class="filter-sm" @change="reload" />
          <TableExportButtons :title="title" endpoint="/shipments" :params="params" :columns="exportColumns" />
          <Button v-if="canCreate" :label="isImport ? 'New Import' : 'New Export'" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="shipment_number" header="Shipment #" />
      <Column field="shipment_date" header="Date" />
      <Column :header="isImport ? 'Supplier' : 'Customer'">
        <template #body="{ data }">{{ data.party || '—' }}</template>
      </Column>
      <Column header="Lane">
        <template #body="{ data }">
          <span class="lane">{{ data.port_of_loading || '—' }} <i class="pi pi-arrow-right" /> {{ data.port_of_discharge || '—' }}</span>
        </template>
      </Column>
      <Column header="Carrier">
        <template #body="{ data }">
          {{ data.carrier || '—' }}
          <small class="muted">{{ data.bl_awb_number ? `· ${data.bl_awb_number}` : '' }}</small>
        </template>
      </Column>
      <Column header="ETD / ETA">
        <template #body="{ data }">
          {{ data.etd || '—' }} / {{ data.eta || '—' }}
          <Tag v-if="data.eta_variance_days > 0 && !data.ata" severity="danger" :value="`+${data.eta_variance_days}d`" class="mini" />
        </template>
      </Column>
      <Column header="Boxes / Docs">
        <template #body="{ data }">
          {{ data.containers_count ?? 0 }}
          <span class="muted">/</span>
          <span :class="{ bad: data.pending_documents_count > 0 }">{{ data.pending_documents_count ?? 0 }} pending</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="shipmentStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="Customs">
        <template #body="{ data }"><Tag :severity="customsStatusSeverity[data.customs_status]" :value="humanize(data.customs_status)" /></template>
      </Column>
      <Column header="Value / Outstanding">
        <template #body="{ data }">
          {{ money(data.invoice_value_base) }}
          <br />
          <small :class="Number(data.outstanding_amount) > 0 ? 'bad' : 'good'">{{ money(data.outstanding_amount) }}</small>
          <Tag :severity="paymentStatusSeverity[data.payment_status]" :value="humanize(data.payment_status)" class="mini" />
        </template>
      </Column>
      <Column header="Actions" style="width: 9rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="Open" @click="openDetail(data)" />
          <Button v-if="canEdit && !['closed', 'cancelled'].includes(data.status)" icon="pi pi-pencil" text rounded
            size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && data.status === 'draft'" icon="pi pi-trash" text rounded size="small"
            severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>No {{ isImport ? 'import' : 'export' }} shipments yet.</template>
    </DataTable>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />

    <!-- Create / edit header -->
    <Dialog v-model:visible="dialogVisible" modal :style="{ width: '960px' }" :breakpoints="{ '1100px': '95vw' }"
      :header="editingId ? 'Edit Shipment' : isImport ? 'New Import Shipment' : 'New Export Shipment'">
      <h4 class="sec">Parties &amp; terms</h4>
      <div class="form-grid">
        <div class="fld">
          <label>Shipment date *</label>
          <input type="date" v-model="form.shipment_date" class="date-input" />
          <small v-if="errors.shipment_date" class="err">{{ errors.shipment_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ isImport ? 'Overseas supplier *' : 'Overseas customer *' }}</label>
          <Select v-model="form[isImport ? 'supplier_id' : 'customer_id']" :options="options.parties" optionLabel="label"
            optionValue="value" filter showClear :placeholder="isImport ? 'Supplier' : 'Customer'" />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ isImport ? 'Purchase order' : 'Sales order' }}</label>
          <Select v-model="form[isImport ? 'purchase_order_id' : 'sales_order_id']" :options="options.orders"
            optionLabel="label" optionValue="value" filter showClear placeholder="Link the order" />
        </div>
        <div class="fld">
          <label>Mode *</label>
          <Select v-model="form.mode" :options="modeChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Incoterm *</label>
          <Select v-model="form.incoterm" :options="incotermChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ isImport ? 'Receiving warehouse' : 'Dispatching warehouse' }}</label>
          <Select v-model="form.warehouse_id" :options="options.warehouses" optionLabel="label" optionValue="value" showClear filter />
        </div>
      </div>

      <h4 class="sec">Carrier &amp; service providers</h4>
      <div class="form-grid">
        <div class="fld" v-if="form.mode !== 'air'">
          <label>Shipping line</label>
          <Select v-model="form.shipping_line_id" :options="options.lines" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld" v-else>
          <label>Airline</label>
          <Select v-model="form.airline_id" :options="options.airlines" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Freight forwarder</label>
          <Select v-model="form.freight_forwarder_id" :options="options.forwarders" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>CHA</label>
          <Select v-model="form.cha_agent_id" :options="options.chas" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Inland transporter</label>
          <Select v-model="form.transporter_id" :options="options.transporters" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>{{ form.mode === 'air' ? 'AWB number' : 'B/L number' }}</label>
          <InputText v-model="form.bl_awb_number" />
        </div>
        <div class="fld">
          <label>{{ form.mode === 'air' ? 'AWB date' : 'B/L date' }}</label>
          <input type="date" v-model="form.bl_awb_date" class="date-input" />
        </div>
        <div class="fld" v-if="form.mode !== 'air'">
          <label>Vessel</label>
          <InputText v-model="form.vessel_name" />
        </div>
        <div class="fld" v-if="form.mode !== 'air'">
          <label>Voyage no.</label>
          <InputText v-model="form.voyage_number" />
        </div>
      </div>

      <h4 class="sec">Route &amp; schedule</h4>
      <div class="form-grid">
        <div class="fld">
          <label>Origin country</label>
          <Select v-model="form.origin_country_id" :options="options.countries" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Destination country</label>
          <Select v-model="form.destination_country_id" :options="options.countries" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Port of loading</label>
          <Select v-model="form.port_of_loading_id" :options="options.ports" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Port of discharge</label>
          <Select v-model="form.port_of_discharge_id" :options="options.ports" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>Final place of delivery</label>
          <InputText v-model="form.final_place" placeholder="Door / ICD / factory" />
        </div>
        <div class="fld">
          <label>ETD</label>
          <input type="date" v-model="form.etd" class="date-input" />
        </div>
        <div class="fld">
          <label>ETA</label>
          <input type="date" v-model="form.eta" class="date-input" />
          <small v-if="errors.eta" class="err">{{ errors.eta[0] }}</small>
        </div>
      </div>

      <h4 class="sec">Cargo</h4>
      <div class="form-grid">
        <div class="fld full">
          <label>Goods description</label>
          <Textarea v-model="form.goods_description" rows="2" />
        </div>
        <div class="fld"><label>Packages</label><InputNumber v-model="form.packages" :min="0" /></div>
        <div class="fld"><label>Gross weight (kg)</label><InputNumber v-model="form.gross_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></div>
        <div class="fld"><label>Net weight (kg)</label><InputNumber v-model="form.net_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></div>
        <div class="fld"><label>CBM</label><InputNumber v-model="form.cbm" :minFractionDigits="0" :maxFractionDigits="3" /></div>
      </div>

      <h4 class="sec">Commercial invoice &amp; money</h4>
      <div class="form-grid">
        <div class="fld"><label>Invoice no.</label><InputText v-model="form.invoice_number" /></div>
        <div class="fld"><label>Invoice date</label><input type="date" v-model="form.invoice_date" class="date-input" /></div>
        <div class="fld">
          <label>Currency *</label>
          <InputText v-model="form.currency" maxlength="3" style="text-transform: uppercase" />
          <small v-if="errors.currency" class="err">{{ errors.currency[0] }}</small>
        </div>
        <div class="fld">
          <label>Exchange rate *</label>
          <InputNumber v-model="form.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" />
          <small class="muted">Converts the foreign amounts to base currency.</small>
        </div>
        <div class="fld"><label>Invoice value ({{ form.currency || '—' }})</label><InputNumber v-model="form.invoice_value" :minFractionDigits="2" /></div>
        <div class="fld"><label>Freight ({{ form.currency || '—' }})</label><InputNumber v-model="form.freight_amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>Insurance ({{ form.currency || '—' }})</label><InputNumber v-model="form.insurance_amount" :minFractionDigits="2" /></div>
        <div class="fld">
          <label>Goods cost (base)</label>
          <InputNumber v-model="form.goods_cost" :minFractionDigits="2" />
          <small class="muted">{{ isImport ? 'Value of the goods before duty & logistics.' : 'Cost of the goods sold — the profit board\'s COGS.' }}</small>
        </div>
        <div class="fld"><label>Payment due date</label><input type="date" v-model="form.payment_due_date" class="date-input" /></div>
        <div class="fld"><label>L/C number</label><InputText v-model="form.lc_number" /></div>
        <div class="fld"><label>L/C expiry</label><input type="date" v-model="form.lc_expiry" class="date-input" /></div>
        <div class="fld full">
          <label>Remarks</label>
          <Textarea v-model="form.remarks" rows="2" />
        </div>
        <p class="total-hint">
          Invoice value in base currency
          <strong>{{ money(Number(form.invoice_value || 0) * Number(form.exchange_rate || 1)) }}</strong>
        </p>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save changes' : 'Create draft'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 150px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.lane { white-space: nowrap; font-size: 0.85rem; }
.lane i { font-size: 0.7rem; color: #9ca3af; margin: 0 0.2rem; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.sec { margin: 1.1rem 0 0.5rem; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
.sec:first-of-type { margin-top: 0; }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.err { color: #dc2626; font-size: 0.78rem; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; }

@media (max-width: 900px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
