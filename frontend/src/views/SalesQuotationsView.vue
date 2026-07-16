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
import { priceDocument, type DiscountType } from '../utils/pricing'
import { openPdfBlob } from '../utils/blob'
import SendEmailDialog from '../components/SendEmailDialog.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const discountTypes = [
  { label: '%', value: 'percent' },
  { label: '₹', value: 'flat' },
]

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/sales-quotations')

const customers = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('sales.create'))
const canEdit = computed(() => auth.can('sales.edit'))
const canEmail = computed(() => auth.can('email.create'))

// --- PDF preview + Email ---
async function previewPdf(row: any) {
  try {
    const res = await http.get(`/sales-quotations/${row.id}/pdf`, { responseType: 'blob' })
    openPdfBlob(res.data)
  } catch {
    toast.add({ severity: 'error', summary: 'Could not open PDF', life: 3000 })
  }
}

const emailVisible = ref(false)
const emailCtx = ref<{ endpoint: string; title: string; defaultTo: string; defaultSubject: string }>({
  endpoint: '', title: '', defaultTo: '', defaultSubject: '',
})
function openEmail(row: any) {
  emailCtx.value = {
    endpoint: `/email/quotations/${row.id}`,
    title: `Email Quotation ${row.quote_number}`,
    defaultTo: row.customer?.email ?? '',
    defaultSubject: `Quotation ${row.quote_number}`,
  }
  emailVisible.value = true
}

const statusColors: Record<string, string> = {
  draft: 'secondary',
  sent: 'info',
  accepted: 'success',
  rejected: 'danger',
  expired: 'warn',
  converted: 'success',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Sent', value: 'sent' },
  { label: 'Accepted', value: 'accepted' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Expired', value: 'expired' },
  { label: 'Converted', value: 'converted' },
]

// --- Create dialog ---
const createVisible = ref(false)
const saving = ref(false)
const form = ref<any>({ customer_id: null, warehouse_id: null, quote_date: today(), valid_until: '', notes: '', discount_type: null as DiscountType, discount_value: 0, items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

const pricing = computed(() => priceDocument(form.value.items, form.value.discount_type, form.value.discount_value))

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_price: 0, tax_percent: 0, discount_type: null, discount_value: 0 })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) {
    line.unit_price = Number(p.selling_price) || 0
    line.tax_percent = Number(p.tax_percent) || 0
  }
}

function openCreate() {
  form.value = { customer_id: null, warehouse_id: null, quote_date: today(), valid_until: '', notes: '', discount_type: null, discount_value: 0, items: [] }
  addLine()
  errors.value = {}
  createVisible.value = true
}

async function saveQuotation() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/sales-quotations', form.value)
    toast.add({ severity: 'success', summary: 'Quotation created', life: 2500 })
    createVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

// --- Row actions ---
async function sendQuotation(row: any) {
  const res = await Swal.fire({ title: `Send ${row.quote_number}?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Send' })
  if (!res.isConfirmed) return
  await runAction(row, 'send', 'Quotation sent')
}
async function acceptQuotation(row: any) {
  const res = await Swal.fire({ title: `Mark ${row.quote_number} as accepted?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Accept' })
  if (!res.isConfirmed) return
  await runAction(row, 'accept', 'Quotation accepted')
}
async function rejectQuotation(row: any) {
  const res = await Swal.fire({ title: `Reject ${row.quote_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  await runAction(row, 'reject', 'Quotation rejected')
}
async function convertQuotation(row: any) {
  const res = await Swal.fire({ title: `Convert ${row.quote_number} to a Sales Order?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Convert' })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/sales-quotations/${row.id}/convert`)
    toast.add({ severity: 'success', summary: `Converted to ${data.data?.so_number}`, life: 3500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot convert', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}
async function runAction(row: any, action: string, successMsg: string) {
  try {
    await http.post(`/sales-quotations/${row.id}/${action}`)
    toast.add({ severity: 'success', summary: successMsg, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Action failed', detail: e.response?.data?.message || 'Failed', life: 6000 })
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
function money(v: number) {
  return formatCurrency(v)
}

onMounted(async () => {
  const [c, w, p] = await Promise.all([
    http.get('/customers', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Sales Quotations</h1>
      <div style="display:flex;gap:0.5rem;align-items:center">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons only="export" title="Sales Quotations" endpoint="/sales-quotations"
          :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
          :columns="[
            { key: 'quote_number', label: 'Quote #' },
            { key: 'customer.name', label: 'Customer' },
            { key: 'warehouse.name', label: 'Warehouse' },
            { key: 'quote_date', label: 'Date' },
            { key: 'valid_until', label: 'Valid Until' },
            { key: 'total', label: 'Total' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button v-if="canCreate" label="New Quotation" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="quote_number" header="Quote #" />
      <Column header="Customer"><template #body="{ data }">{{ data.customer?.name }}</template></Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column field="quote_date" header="Date" />
      <Column field="valid_until" header="Valid Until" />
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <Button icon="pi pi-file-pdf" text rounded size="small" title="View PDF" @click="previewPdf(data)" />
          <Button v-if="canEmail" icon="pi pi-envelope" text rounded size="small" title="Email to customer" @click="openEmail(data)" />
          <Button v-if="canEdit && data.status === 'draft'" label="Send" size="small" outlined @click="sendQuotation(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && data.status === 'sent'" label="Accept" size="small" outlined severity="success" @click="acceptQuotation(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && data.status === 'sent'" label="Reject" size="small" outlined severity="danger" @click="rejectQuotation(data)" style="margin-left: 4px" />
          <Button v-if="canCreate && data.status === 'accepted'" label="Convert to SO" size="small" severity="success" @click="convertQuotation(data)" style="margin-left: 4px" />
        </template>
      </Column>
      <template #empty>No quotations yet.</template>
    </DataTable>

    <!-- Create Quotation dialog -->
    <Dialog v-model:visible="createVisible" modal header="New Sales Quotation" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>Customer *</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter placeholder="Select customer" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Quote Date *</label>
          <input type="date" v-model="form.quote_date" class="date-input" />
          <small v-if="errors.quote_date" class="err">{{ errors.quote_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Valid Until</label>
          <input type="date" v-model="form.valid_until" class="date-input" />
          <small v-if="errors.valid_until" class="err">{{ errors.valid_until[0] }}</small>
        </div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <table class="lines">
        <thead>
          <tr><th style="width: 30%">Product</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Tax %</th><th>Line Total</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                placeholder="Product" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_price" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td class="disc-cell">
              <Select v-model="line.discount_type" :options="discountTypes" optionLabel="label" optionValue="value" showClear placeholder="—" class="disc-type" />
              <InputNumber v-model="line.discount_value" :minFractionDigits="0" :maxFractionDigits="2" class="disc-val" :disabled="!line.discount_type" />
            </td>
            <td><InputNumber v-model="line.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td class="line-total">{{ money(pricing.lines[Number(i)]?.line_total ?? 0) }}</td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="totals-panel">
        <div class="overall-disc">
          <label>Invoice Discount</label>
          <Select v-model="form.discount_type" :options="discountTypes" optionLabel="label" optionValue="value" showClear placeholder="—" class="disc-type" />
          <InputNumber v-model="form.discount_value" :minFractionDigits="0" :maxFractionDigits="2" class="disc-val" :disabled="!form.discount_type" />
        </div>
        <div class="totals">
          <div><span>Subtotal</span><span>{{ money(pricing.header.subtotal) }}</span></div>
          <div v-if="pricing.header.item_discount_total"><span>Item Discount</span><span>−{{ money(pricing.header.item_discount_total) }}</span></div>
          <div v-if="pricing.header.discount_amount"><span>Invoice Discount</span><span>−{{ money(pricing.header.discount_amount) }}</span></div>
          <div><span>Taxable</span><span>{{ money(pricing.header.taxable) }}</span></div>
          <div><span>GST</span><span>{{ money(pricing.header.tax_amount) }}</span></div>
          <div v-if="pricing.header.round_off"><span>Round Off</span><span>{{ money(pricing.header.round_off) }}</span></div>
          <div class="grand-row"><span>Grand Total</span><span>{{ money(pricing.header.total) }}</span></div>
        </div>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="createVisible = false" />
        <Button label="Create Quotation" :loading="saving" @click="saveQuotation" />
      </template>
    </Dialog>

    <SendEmailDialog
      v-model:visible="emailVisible"
      :endpoint="emailCtx.endpoint"
      :title="emailCtx.title"
      :defaultTo="emailCtx.defaultTo"
      :defaultSubject="emailCtx.defaultSubject"
    />
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
.disc-cell { display: flex; gap: 0.3rem; align-items: center; }
.disc-type { width: 64px; }
.disc-type :deep(.p-select) { width: 64px; }
.disc-val :deep(input) { width: 70px; }
.totals-panel { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 1rem; gap: 2rem; flex-wrap: wrap; }
.overall-disc { display: flex; align-items: center; gap: 0.5rem; }
.overall-disc label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.totals { min-width: 260px; margin-left: auto; font-size: 0.9rem; }
.totals > div { display: flex; justify-content: space-between; padding: 0.15rem 0; }
.totals .grand-row { font-size: 1.05rem; font-weight: 600; border-top: 1px solid #e5e7eb; margin-top: 0.25rem; padding-top: 0.4rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.w-full :deep(input) { width: 100%; }
</style>
