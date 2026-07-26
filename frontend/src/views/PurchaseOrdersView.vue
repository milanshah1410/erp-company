<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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
import { formatCurrency } from '../utils/currency'
import { openPdfBlob } from '../utils/blob'
import SendEmailDialog from '../components/SendEmailDialog.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()
const auth = useAuthStore()
const crud = useCrud('/purchase-orders')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const suppliers = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('purchases.create'))
const canApprove = computed(() => auth.can('purchases.approve'))
const canEmail = computed(() => auth.can('email.create'))

// --- PDF preview + Email ---
async function previewPdf(row: any) {
  try {
    const res = await http.get(`/purchase-orders/${row.id}/pdf`, { responseType: 'blob' })
    openPdfBlob(res.data)
  } catch {
    toast.add({ severity: 'error', summary: pt('Could not open PDF'), life: 3000 })
  }
}

const emailVisible = ref(false)
const emailCtx = ref<{ endpoint: string; title: string; defaultTo: string; defaultSubject: string }>({
  endpoint: '', title: '', defaultTo: '', defaultSubject: '',
})
function openEmail(row: any) {
  emailCtx.value = {
    endpoint: `/email/purchase-orders/${row.id}`,
    title: pt('Email Purchase Order {n}', { n: row.po_number }),
    defaultTo: row.supplier?.email ?? '',
    defaultSubject: `Purchase Order ${row.po_number}`,
  }
  emailVisible.value = true
}

const statusColors: Record<string, string> = {
  draft: 'secondary',
  pending_approval: 'warn',
  approved: 'info',
  partially_received: 'warn',
  received: 'success',
  cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Pending Approval'), value: 'pending_approval' },
  { label: pt('Approved'), value: 'approved' },
  { label: pt('Partially Received'), value: 'partially_received' },
  { label: pt('Received'), value: 'received' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

// --- Create dialog ---
const createVisible = ref(false)
const saving = ref(false)
const form = ref<any>({ supplier_id: null, warehouse_id: null, order_date: today(), expected_date: null, notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

const grandTotal = computed(() =>
  form.value.items.reduce((sum: number, it: any) => {
    const base = (Number(it.quantity) || 0) * (Number(it.unit_cost) || 0)
    return sum + base + (base * (Number(it.tax_percent) || 0)) / 100
  }, 0),
)

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_cost: 0, tax_percent: 0 })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) {
    line.unit_cost = Number(p.cost_price) || 0
    line.tax_percent = Number(p.tax_percent) || 0
  }
}

function openCreate() {
  form.value = { supplier_id: null, warehouse_id: null, order_date: today(), expected_date: null, notes: '', items: [] }
  addLine()
  errors.value = {}
  createVisible.value = true
}

async function saveOrder() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/purchase-orders', form.value)
    toast.add({ severity: 'success', summary: pt('Purchase order created'), life: 2500 })
    createVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

function isAwaitingFinalApproval(row: any): boolean {
  return !!row.requires_second_approval && !!row.first_approved_at && row.status === 'pending_approval'
}

async function approve(row: any) {
  const res = await Swal.fire({ title: pt('Approve {n}?', { n: row.po_number }), icon: 'question', showCancelButton: true, confirmButtonText: pt('Approve'), cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  try {
    await http.post(`/purchase-orders/${row.id}/approve`)
    toast.add({ severity: 'success', summary: pt('Approved'), life: 2500 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.approval?.[0] || e.response?.data?.message || pt('Failed')
    toast.add({ severity: 'error', summary: pt('Error'), detail, life: 5000 })
  }
}

// --- Receive (GRN) dialog ---
const grnVisible = ref(false)
const grnSaving = ref(false)
const grnPo = ref<any>(null)
const grnDate = ref(today())
const grnLines = ref<any[]>([])
const grnLocations = ref<any[]>([]) // put-away options for the PO's warehouse
const grnCharges = ref<any>({ freight_amount: 0, duty_amount: 0, other_amount: 0, allocation_method: 'value' })
const allocationMethods = computed(() => [
  { label: pt('By Value'), value: 'value' },
  { label: pt('By Quantity'), value: 'quantity' },
])

async function openReceive(row: any) {
  try {
    const { data } = await http.get(`/purchase-orders/${row.id}`)
    grnPo.value = data.data
    grnDate.value = today()
    grnCharges.value = { freight_amount: 0, duty_amount: 0, other_amount: 0, allocation_method: 'value' }
    try {
      const loc = await http.get(`/warehouses/${grnPo.value.warehouse_id}/locations-flat`)
      grnLocations.value = (loc.data.data ?? []).map((l: any) => ({
        label: `${l.code} — ${l.name} (${l.type})`,
        value: l.id,
      }))
    } catch {
      grnLocations.value = []
    }
    grnLines.value = grnPo.value.items
      .filter((i: any) => Number(i.outstanding_quantity) > 0)
      .map((i: any) => ({
        purchase_item_id: i.id,
        product: i.product,
        outstanding: Number(i.outstanding_quantity),
        quantity: Number(i.outstanding_quantity),
        batch_number: '',
        expiry_date: '',
        serials: '', // comma/newline separated, split before submit
        location_id: null, // optional put-away target
      }))
    grnVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: pt('Could not load order'), life: 3000 })
  }
}

function hasLandedCharges(): boolean {
  return (Number(grnCharges.value.freight_amount) || 0) > 0
    || (Number(grnCharges.value.duty_amount) || 0) > 0
    || (Number(grnCharges.value.other_amount) || 0) > 0
}

async function saveGrn() {
  grnSaving.value = true
  try {
    const payload: any = {
      purchase_order_id: grnPo.value.id,
      received_date: grnDate.value,
      items: grnLines.value
        .filter((l) => Number(l.quantity) > 0)
        .map((l) => {
          const item: any = { purchase_item_id: l.purchase_item_id, quantity: l.quantity }
          if (l.location_id) item.location_id = l.location_id
          if (l.batch_number?.trim()) item.batch_number = l.batch_number.trim()
          if (l.expiry_date) item.expiry_date = l.expiry_date
          const serials = String(l.serials || '')
            .split(/[\n,]+/)
            .map((s: string) => s.trim())
            .filter(Boolean)
          if (serials.length) item.serials = serials
          return item
        }),
    }
    if (hasLandedCharges()) payload.landed_cost = grnCharges.value
    await http.post('/goods-receipts', payload)
    toast.add({ severity: 'success', summary: pt('Goods received — stock updated'), life: 3000 })
    grnVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Receipt failed'), life: 4000 })
  } finally {
    grnSaving.value = false
  }
}

function serialCount(line: any): number {
  return String(line.serials || '')
    .split(/[\n,]+/)
    .map((s: string) => s.trim())
    .filter(Boolean).length
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}
function money(v: number) {
  return formatCurrency(v)
}

onMounted(async () => {
  const [s, w, p] = await Promise.all([
    http.get('/suppliers', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Purchase Orders')" :count="crud.total.value">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Purchase Order')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;gap:0.5rem;align-items:center;margin-bottom:1rem">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
      </span>
      <Button :label="pt('Search')" outlined @click="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All statuses')" @change="reload" class="status-filter" />
      <TableExportButtons :title="pt('Purchase Orders')" only="export" endpoint="/purchase-orders"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
          { key: 'po_number', label: pt('PO #') },
          { key: 'supplier.name', label: pt('Supplier') },
          { key: 'warehouse.name', label: pt('Warehouse') },
          { key: 'order_date', label: pt('Date') },
          { key: 'total', label: pt('Total') },
          { key: 'status', label: pt('Status') },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="po_number" :header="pt('PO #')" />
      <Column :header="pt('Supplier')"><template #body="{ data }">{{ data.supplier?.name }}</template></Column>
      <Column :header="pt('Warehouse')"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column field="order_date" :header="pt('Date')" />
      <Column :header="pt('Total')"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag v-if="isAwaitingFinalApproval(data)" severity="warn" :value="pt('awaiting final approval')" />
          <Tag v-else :severity="statusColors[data.status]" :value="pt(data.status.replace('_', ' '))" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" :title="pt('View')" @click="openDetail(data)" />
          <Button icon="pi pi-file-pdf" text rounded size="small" :title="pt('View PDF')" @click="previewPdf(data)" />
          <Button v-if="canEmail" icon="pi pi-envelope" text rounded size="small" :title="pt('Email to supplier')" @click="openEmail(data)" />
          <Button v-if="canApprove && ['draft', 'pending_approval'].includes(data.status)" :label="pt('Approve')" size="small" outlined @click="approve(data)" style="margin-left: 4px" />
          <Button v-if="canCreate && ['approved', 'partially_received'].includes(data.status)" :label="pt('Receive')" size="small" severity="success" @click="openReceive(data)" style="margin-left: 4px" />
        </template>
      </Column>
      <template #empty>{{ pt('No purchase orders yet.') }}</template>
    </DataTable>

    <!-- Create PO dialog -->
    <Dialog v-model:visible="createVisible" modal :header="pt('New Purchase Order')" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>{{ pt('Supplier') }} *</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter :placeholder="pt('Select supplier')" />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Warehouse') }} *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" :placeholder="pt('Select warehouse')" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Order Date') }} *</label>
          <input type="date" v-model="form.order_date" class="date-input" />
        </div>
        <div class="fld">
          <label>{{ pt('Expected Date') }}</label>
          <input type="date" v-model="form.expected_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">{{ pt('Line Items') }}</h3>
      <table class="lines">
        <thead>
          <tr><th style="width: 38%">{{ pt('Product') }}</th><th>{{ pt('Qty') }}</th><th>{{ pt('Unit Cost') }}</th><th>{{ pt('Tax %') }}</th><th>{{ pt('Line Total') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                :placeholder="pt('Product')" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_cost" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><InputNumber v-model="line.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td class="line-total">{{ money(((line.quantity || 0) * (line.unit_cost || 0)) * (1 + (line.tax_percent || 0) / 100)) }}</td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add Line')" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="grand">{{ pt('Total:') }} <strong>{{ money(grandTotal) }}</strong></div>

      <div class="fld full">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="createVisible = false" />
        <Button :label="pt('Create Order')" :loading="saving" @click="saveOrder" />
      </template>
    </Dialog>

    <!-- Receive (GRN) dialog -->
    <Dialog v-model:visible="grnVisible" modal :header="pt('Receive Goods — {n}', { n: grnPo?.po_number })" :style="{ width: '640px' }">
      <div class="fld" style="margin-bottom: 1rem">
        <label>{{ pt('Received Date') }} *</label>
        <input type="date" v-model="grnDate" class="date-input" />
      </div>
      <table class="lines">
        <thead><tr><th>{{ pt('Product') }}</th><th>{{ pt('Outstanding') }}</th><th>{{ pt('Receive Now') }}</th><th v-if="grnLocations.length">{{ pt('Put Away To') }}</th></tr></thead>
        <tbody>
          <template v-for="line in grnLines" :key="line.purchase_item_id">
            <tr>
              <td>
                {{ line.product?.name }}
                <div class="track-tags">
                  <Tag v-if="line.product?.track_batch" :value="pt('batch')" severity="info" />
                  <Tag v-if="line.product?.track_expiry" :value="pt('expiry')" severity="warn" />
                  <Tag v-if="line.product?.track_serial" :value="pt('serial')" severity="secondary" />
                </div>
              </td>
              <td>{{ line.outstanding }}</td>
              <td><InputNumber v-model="line.quantity" :min="0" :max="line.outstanding" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
              <td v-if="grnLocations.length">
                <Select v-model="line.location_id" :options="grnLocations" optionLabel="label" optionValue="value" showClear filter :placeholder="pt('No location')" class="loc-select" />
              </td>
            </tr>
            <tr v-if="line.product?.track_batch || line.product?.track_expiry || line.product?.track_serial" class="track-row">
              <td :colspan="grnLocations.length ? 4 : 3">
                <div class="track-grid">
                  <div v-if="line.product?.track_batch" class="fld">
                    <label>{{ pt('Batch Number') }} *</label>
                    <input v-model="line.batch_number" class="date-input" placeholder="e.g. BATCH-2026-07" />
                  </div>
                  <div v-if="line.product?.track_expiry" class="fld">
                    <label>{{ pt('Expiry Date') }} *</label>
                    <input type="date" v-model="line.expiry_date" class="date-input" />
                  </div>
                  <div v-if="line.product?.track_serial" class="fld serials">
                    <label>
                      {{ pt('Serial Numbers * (comma or newline separated)') }}
                      <span class="count">{{ serialCount(line) }}/{{ line.quantity || 0 }}</span>
                    </label>
                    <textarea v-model="line.serials" rows="2" class="date-input" placeholder="SN001, SN002, ..." />
                  </div>
                </div>
              </td>
            </tr>
          </template>
          <tr v-if="!grnLines.length"><td :colspan="grnLocations.length ? 4 : 3" style="text-align: center; color: #6b7280">{{ pt('Nothing left to receive.') }}</td></tr>
        </tbody>
      </table>

      <h3 class="lines-title">{{ pt('Additional Charges (optional)') }}</h3>
      <div class="charges-grid">
        <div class="fld">
          <label>{{ pt('Freight') }}</label>
          <InputNumber v-model="grnCharges.freight_amount" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Duty / Customs') }}</label>
          <InputNumber v-model="grnCharges.duty_amount" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Other') }}</label>
          <InputNumber v-model="grnCharges.other_amount" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Allocate') }}</label>
          <Select v-model="grnCharges.allocation_method" :options="allocationMethods" optionLabel="label" optionValue="value" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="grnVisible = false" />
        <Button :label="pt('Confirm Receipt')" :loading="grnSaving" :disabled="!grnLines.length" @click="saveGrn" />
      </template>
    </Dialog>

    <SendEmailDialog
      v-model:visible="emailVisible"
      :endpoint="emailCtx.endpoint"
      :title="emailCtx.title"
      :defaultTo="emailCtx.defaultTo"
      :defaultSubject="emailCtx.defaultSubject"
    />

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Purchase Order Details')" :fields="[
      { key: 'po_number', label: pt('PO #') },
      { key: 'supplier.name', label: pt('Supplier') },
      { key: 'warehouse.name', label: pt('Warehouse') },
      { key: 'order_date', label: pt('Order Date') },
      { key: 'expected_date', label: pt('Expected Date') },
      { key: 'status', label: pt('Status') },
      { key: 'total', label: pt('Total'), format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'notes', label: pt('Notes'), full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.status-filter { width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-top { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.grand { text-align: right; margin-top: 0.75rem; font-size: 1.05rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.track-tags { display: flex; gap: 4px; margin-top: 2px; }
.track-tags :deep(.p-tag) { font-size: 0.65rem; padding: 1px 6px; }
.track-row td { background: #f8fafc; border-bottom: 1px solid #e5e7eb; }
.track-grid { display: flex; gap: 1rem; flex-wrap: wrap; padding: 0.25rem 0; }
.track-grid .fld { min-width: 180px; }
.track-grid .serials { flex: 1; min-width: 260px; }
.track-grid textarea { width: 100%; resize: vertical; }
.count { color: #6b7280; font-weight: 400; margin-left: 6px; }
.loc-select { width: 200px; }
.loc-select :deep(.p-select-label) { font-size: 0.85rem; }
.charges-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-top: 0.5rem; }
</style>
