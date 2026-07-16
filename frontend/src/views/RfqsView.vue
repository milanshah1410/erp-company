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
import MultiSelect from 'primevue/multiselect'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/rfqs')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const warehouses = ref<any[]>([])
const suppliers = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('rfqs.create'))
const canEdit = computed(() => auth.can('rfqs.edit'))
const canDelete = computed(() => auth.can('rfqs.delete'))
const canAward = computed(() => auth.can('purchases.create'))

const statusColors: Record<string, string> = {
  draft: 'secondary', sent: 'warn', awarded: 'success', closed: 'info', cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Sent', value: 'sent' },
  { label: 'Awarded', value: 'awarded' },
  { label: 'Closed', value: 'closed' },
  { label: 'Cancelled', value: 'cancelled' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}
function money(v: number) {
  return v == null ? '—' : formatCurrency(v)
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return { warehouse_id: null, rfq_date: today(), response_deadline: null, notes: '', items: [], supplier_ids: [] }
}
function addLine() {
  form.value.items.push({ product_id: null, quantity: 1 })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function openCreate() {
  editingId.value = null
  form.value = blankForm()
  addLine()
  errors.value = {}
  dialogVisible.value = true
}
async function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  const { data } = await http.get(`/rfqs/${row.id}`)
  const d = data.data
  form.value = {
    warehouse_id: d.warehouse_id, rfq_date: d.rfq_date, response_deadline: d.response_deadline,
    notes: d.notes || '',
    items: d.items.map((i: any) => ({ product_id: i.product_id, quantity: Number(i.quantity) })),
    supplier_ids: (d.suppliers || []).map((s: any) => s.supplier_id),
  }
  dialogVisible.value = true
}
async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/rfqs/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'RFQ updated', life: 2500 })
    } else {
      await http.post('/rfqs', form.value)
      toast.add({ severity: 'success', summary: 'RFQ created', life: 2500 })
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

async function send(row: any) {
  await act(`/rfqs/${row.id}/send`, 'RFQ sent to vendors')
}
async function close(row: any) {
  const res = await Swal.fire({ title: `Close ${row.rfq_number}?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Close RFQ' })
  if (!res.isConfirmed) return
  await act(`/rfqs/${row.id}/close`, 'RFQ closed')
}
async function remove(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.rfq_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/rfqs/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function act(url: string, msg: string, body: any = {}) {
  try {
    await http.post(url, body)
    toast.add({ severity: 'success', summary: msg, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

// --- Record quote dialog ---
const quoteVisible = ref(false)
const quoteSaving = ref(false)
const quoteRfq = ref<any>(null)
const quoteVendorId = ref<number | null>(null)
const quoteLines = ref<any[]>([])
const quoteMeta = ref<any>({ lead_time_days: null, remarks: '' })

const vendorOptions = computed(() =>
  (quoteRfq.value?.suppliers || []).map((s: any) => ({
    label: `${s.supplier?.name}${s.status === 'responded' ? ' ✓' : ''}`,
    value: s.id,
  })),
)

async function openQuote(row: any) {
  const { data } = await http.get(`/rfqs/${row.id}`)
  quoteRfq.value = data.data
  quoteVendorId.value = null
  quoteMeta.value = { lead_time_days: null, remarks: '' }
  quoteLines.value = quoteRfq.value.items.map((i: any) => ({
    rfq_item_id: i.id, product: i.product?.name, quantity: Number(i.quantity),
    unit_price: 0, tax_percent: 0, remarks: '',
  }))
  quoteVisible.value = true
}
function onVendorPick() {
  // Prefill with the vendor's existing quote if they already responded.
  const rs = (quoteRfq.value?.suppliers || []).find((s: any) => s.id === quoteVendorId.value)
  if (!rs) return
  quoteMeta.value = { lead_time_days: rs.lead_time_days, remarks: rs.remarks || '' }
  quoteLines.value.forEach((line) => {
    const q = (rs.quotes || []).find((x: any) => x.rfq_item_id === line.rfq_item_id)
    line.unit_price = q ? Number(q.unit_price) : 0
    line.tax_percent = q ? Number(q.tax_percent) : 0
    line.remarks = q?.remarks || ''
  })
}
const quoteTotal = computed(() =>
  quoteLines.value.reduce((sum, l) => {
    const base = (Number(l.quantity) || 0) * (Number(l.unit_price) || 0)
    return sum + base * (1 + (Number(l.tax_percent) || 0) / 100)
  }, 0),
)
async function saveQuote() {
  if (!quoteVendorId.value) {
    toast.add({ severity: 'warn', summary: 'Select a vendor', life: 2500 })
    return
  }
  quoteSaving.value = true
  try {
    await http.post(`/rfqs/${quoteRfq.value.id}/suppliers/${quoteVendorId.value}/quotes`, {
      lead_time_days: quoteMeta.value.lead_time_days,
      remarks: quoteMeta.value.remarks,
      quotes: quoteLines.value.map((l) => ({
        rfq_item_id: l.rfq_item_id, unit_price: l.unit_price, tax_percent: l.tax_percent, remarks: l.remarks,
      })),
    })
    toast.add({ severity: 'success', summary: 'Quote recorded', life: 2500 })
    quoteVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    quoteSaving.value = false
  }
}

// --- Comparison dialog ---
const compareVisible = ref(false)
const compareLoading = ref(false)
const comparison = ref<any>(null)
const compareRfq = ref<any>(null)

async function openCompare(row: any) {
  compareRfq.value = row
  compareLoading.value = true
  compareVisible.value = true
  try {
    const { data } = await http.get(`/rfqs/${row.id}/comparison`)
    comparison.value = data
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load comparison', life: 3000 })
    compareVisible.value = false
  } finally {
    compareLoading.value = false
  }
}
function cellFor(rowCells: any[], vendorId: number) {
  return rowCells.find((c) => c.rfq_supplier_id === vendorId)
}
async function award(vendor: any) {
  const res = await Swal.fire({
    title: `Award to ${vendor.supplier}?`,
    text: `A draft purchase order will be created from ${vendor.supplier}'s quote (${money(vendor.quoted_total)}).`,
    icon: 'question', showCancelButton: true, confirmButtonText: 'Award & create PO',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/rfqs/${compareRfq.value.id}/award`, { rfq_supplier_id: vendor.rfq_supplier_id })
    toast.add({ severity: 'success', summary: `Awarded — PO ${data.data.po_number} created`, life: 4000 })
    compareVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
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

onMounted(async () => {
  const [w, s, p] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/suppliers', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Request for Quotation (RFQ)</h1>
      <Button v-if="canCreate" label="New RFQ" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;gap:0.5rem;align-items:center;margin-bottom:1rem">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
      <TableExportButtons title="RFQs" endpoint="/rfqs"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
          { key: 'rfq_number', label: 'RFQ #' },
          { key: 'rfq_date', label: 'Date' },
          { key: 'response_deadline', label: 'Deadline' },
          { key: 'items_count', label: 'Items' },
          { key: 'suppliers_count', label: 'Vendors' },
          { key: 'awarded_supplier.name', label: 'Awarded To' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="rfq_number" header="RFQ #" />
      <Column field="rfq_date" header="Date" />
      <Column header="Deadline"><template #body="{ data }">{{ data.response_deadline || '—' }}</template></Column>
      <Column header="Items"><template #body="{ data }">{{ data.items_count ?? '—' }}</template></Column>
      <Column header="Vendors"><template #body="{ data }">{{ data.suppliers_count ?? '—' }}</template></Column>
      <Column header="Awarded To"><template #body="{ data }">{{ data.awarded_supplier?.name || '—' }}</template></Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Actions" style="width: 22rem">
        <template #body="{ data }">
          <div class="actions">
            <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
            <Button v-if="canEdit && data.status === 'draft'" label="Send" size="small" outlined @click="send(data)" />
            <Button v-if="canEdit && data.status === 'sent'" label="Record Quote" size="small" outlined @click="openQuote(data)" />
            <Button v-if="['sent', 'awarded'].includes(data.status)" label="Compare" size="small" severity="info" outlined @click="openCompare(data)" />
            <Button v-if="canEdit && ['draft', 'sent'].includes(data.status)" label="Close" size="small" text @click="close(data)" />
            <Button v-if="canEdit && data.status === 'draft'" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete && data.status === 'draft'" icon="pi pi-trash" size="small" text severity="danger" @click="remove(data)" />
          </div>
        </template>
      </Column>
      <template #empty>No RFQs yet.</template>
    </DataTable>

    <!-- Create/edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit RFQ' : 'New RFQ'" :style="{ width: '760px' }">
      <div class="form-top">
        <div class="fld">
          <label>Warehouse</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" showClear placeholder="Select warehouse" />
        </div>
        <div class="fld">
          <label>RFQ Date *</label>
          <input type="date" v-model="form.rfq_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Response Deadline</label>
          <input type="date" v-model="form.response_deadline" class="date-input" />
        </div>
      </div>

      <div class="fld full">
        <label>Invite Vendors *</label>
        <MultiSelect v-model="form.supplier_ids" :options="suppliers" optionLabel="label" optionValue="value" filter display="chip" placeholder="Select vendors" />
        <small v-if="errors.supplier_ids" class="err">{{ errors.supplier_ids[0] }}</small>
      </div>

      <h3 class="lines-title">Items to Quote</h3>
      <table class="lines">
        <thead><tr><th style="width: 60%">Product</th><th>Qty</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td><Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter placeholder="Product" class="w-full" /></td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save' : 'Create RFQ'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Record quote dialog -->
    <Dialog v-model:visible="quoteVisible" modal :header="`Record Quote — ${quoteRfq?.rfq_number}`" :style="{ width: '760px' }">
      <div class="form-top">
        <div class="fld">
          <label>Vendor *</label>
          <Select v-model="quoteVendorId" :options="vendorOptions" optionLabel="label" optionValue="value" placeholder="Select vendor" @change="onVendorPick" />
        </div>
        <div class="fld">
          <label>Lead Time (days)</label>
          <InputNumber v-model="quoteMeta.lead_time_days" :min="0" />
        </div>
      </div>
      <table class="lines" style="margin-top: 1rem">
        <thead><tr><th style="width: 34%">Product</th><th>Qty</th><th>Unit Price</th><th>Tax %</th><th>Line Total</th></tr></thead>
        <tbody>
          <tr v-for="line in quoteLines" :key="line.rfq_item_id">
            <td>{{ line.product }}</td>
            <td>{{ line.quantity }}</td>
            <td><InputNumber v-model="line.unit_price" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><InputNumber v-model="line.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td class="line-total">{{ money((line.quantity || 0) * (line.unit_price || 0) * (1 + (line.tax_percent || 0) / 100)) }}</td>
          </tr>
        </tbody>
      </table>
      <div class="grand">Quoted Total: <strong>{{ money(quoteTotal) }}</strong></div>
      <div class="fld full">
        <label>Vendor Remarks</label>
        <Textarea v-model="quoteMeta.remarks" rows="2" />
      </div>
      <template #footer>
        <Button label="Cancel" text @click="quoteVisible = false" />
        <Button label="Save Quote" :loading="quoteSaving" @click="saveQuote" />
      </template>
    </Dialog>

    <!-- Comparison dialog -->
    <Dialog v-model:visible="compareVisible" modal :header="`Vendor Comparison — ${compareRfq?.rfq_number}`" :style="{ width: '900px' }">
      <div v-if="compareLoading" class="empty">Loading…</div>
      <div v-else-if="comparison && comparison.vendors.length === 0" class="empty">No vendor quotes recorded yet.</div>
      <div v-else-if="comparison" class="compare-wrap">
        <table class="compare">
          <thead>
            <tr>
              <th class="prod-col">Product</th>
              <th class="qty-col">Qty</th>
              <th v-for="v in comparison.vendors" :key="v.rfq_supplier_id">{{ v.supplier }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in comparison.rows" :key="row.rfq_item_id">
              <td class="prod-col">{{ row.product }}</td>
              <td class="qty-col">{{ row.quantity }}</td>
              <td v-for="v in comparison.vendors" :key="v.rfq_supplier_id"
                :class="{ best: cellFor(row.cells, v.rfq_supplier_id)?.is_best }">
                <template v-if="cellFor(row.cells, v.rfq_supplier_id)?.unit_price != null">
                  {{ money(cellFor(row.cells, v.rfq_supplier_id).unit_price) }}
                  <i v-if="cellFor(row.cells, v.rfq_supplier_id)?.is_best" class="pi pi-check-circle best-ico" />
                </template>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="lead-row">
              <td colspan="2">Lead Time (days)</td>
              <td v-for="v in comparison.vendors" :key="v.rfq_supplier_id">{{ v.lead_time_days ?? '—' }}</td>
            </tr>
            <tr class="total-row">
              <td colspan="2">Total</td>
              <td v-for="v in comparison.vendors" :key="v.rfq_supplier_id" :class="{ best: v.is_lowest_total }">
                <strong>{{ money(v.quoted_total) }}</strong>
              </td>
            </tr>
            <tr v-if="canAward && comparison.rfq.status === 'sent'" class="award-row">
              <td colspan="2"></td>
              <td v-for="v in comparison.vendors" :key="v.rfq_supplier_id">
                <Button label="Award" size="small" severity="success" @click="award(v)" />
              </td>
            </tr>
          </tfoot>
        </table>
        <p v-if="comparison.rfq.status === 'awarded'" class="awarded-note">
          <i class="pi pi-check-circle" /> This RFQ has been awarded and a purchase order was raised.
        </p>
      </div>
      <template #footer>
        <Button label="Close" text @click="compareVisible = false" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="RFQ" :fields="[
      { key: 'rfq_number', label: 'RFQ #' },
      { key: 'rfq_date', label: 'Date' },
      { key: 'response_deadline', label: 'Deadline' },
      { key: 'items_count', label: 'Items' },
      { key: 'suppliers_count', label: 'Vendors' },
      { key: 'awarded_supplier.name', label: 'Awarded To' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
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
.actions { display: flex; flex-wrap: wrap; gap: 4px; }
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(.p-multiselect), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.grand { text-align: right; margin: 0.75rem 0; font-size: 1.05rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.empty { padding: 2rem; text-align: center; color: #6b7280; }
.compare-wrap { overflow-x: auto; }
.compare { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.compare th, .compare td { padding: 0.5rem 0.7rem; border: 1px solid #e5e7eb; text-align: center; }
.compare th { background: #f8fafc; font-weight: 600; }
.compare .prod-col { text-align: left; min-width: 160px; }
.compare .qty-col { width: 60px; }
.compare td.best { background: #ecfdf5; color: #047857; font-weight: 600; }
.best-ico { font-size: 0.75rem; margin-left: 3px; }
.compare .total-row td, .compare .lead-row td { background: #f9fafb; }
.compare .total-row td.best { background: #d1fae5; }
.compare .award-row td { border-top: 2px solid #e5e7eb; }
.muted { color: #9ca3af; }
.awarded-note { margin-top: 1rem; color: #047857; font-size: 0.9rem; }
</style>
