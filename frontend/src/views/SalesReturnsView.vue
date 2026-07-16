<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
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
import TableExportButtons from '../components/TableExportButtons.vue'

const discountTypes = [
  { label: '%', value: 'percent' },
  { label: '₹', value: 'flat' },
]

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/sales-returns')

const invoices = ref<any[]>([])
const invoiceItems = ref<any[]>([])
const invoiceInfo = ref<any>(null)

const canCreate = computed(() => auth.can('sales-returns.create'))
const canApprove = computed(() => auth.can('sales-returns.approve'))
const canEdit = computed(() => auth.can('sales-returns.edit'))
const canDelete = computed(() => auth.can('sales-returns.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  approved: 'success',
  cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Approved', value: 'approved' },
  { label: 'Cancelled', value: 'cancelled' },
]

// --- Create dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const loadingInvoice = ref(false)
const form = ref<any>({ invoice_id: null, return_date: today(), notes: '', discount_type: null as DiscountType, discount_value: 0, items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

const pricing = computed(() => priceDocument(form.value.items, form.value.discount_type, form.value.discount_value))

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_price: 0, tax_percent: 0, discount_type: null, discount_value: 0, reason: '' })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onProductPick(line: any) {
  const src = invoiceItems.value.find((x) => x.product_id === line.product_id)
  if (src) {
    line.unit_price = Number(src.unit_price) || 0
    line.tax_percent = Number(src.tax_percent) || 0
    line.discount_type = src.discount_type ?? null
    line.discount_value = Number(src.discount_value) || 0
  }
}

async function onInvoicePick() {
  invoiceItems.value = []
  invoiceInfo.value = null
  form.value.items = []
  if (!form.value.invoice_id) return

  loadingInvoice.value = true
  try {
    const { data } = await http.get(`/invoices/${form.value.invoice_id}`)
    invoiceInfo.value = data.data
    const { data: soData } = await http.get(`/sales-orders/${data.data.sales_order_id}`)
    invoiceItems.value = (soData.data?.items ?? []).map((i: any) => ({
      product_id: i.product_id,
      name: i.product?.name,
      unit_price: i.unit_price,
      tax_percent: i.tax_percent,
      discount_type: i.discount_type ?? null,
      discount_value: i.discount_value,
    }))
    // Default the credit note's overall discount to the original order's, so a
    // full-quantity return mirrors the invoice; the user can still adjust it.
    form.value.discount_type = soData.data?.discount_type ?? null
    form.value.discount_value = Number(soData.data?.discount_value) || 0
    addLine()
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoice details', life: 3000 })
  } finally {
    loadingInvoice.value = false
  }
}

function openCreate() {
  form.value = { invoice_id: null, return_date: today(), notes: '', discount_type: null, discount_value: 0, items: [] }
  invoiceItems.value = []
  invoiceInfo.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function saveReturn() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/sales-returns', form.value)
    toast.add({ severity: 'success', summary: 'Credit note created as draft', life: 2500 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function approve(row: any) {
  const res = await Swal.fire({
    title: `Approve ${row.credit_note_number}?`,
    text: 'Stock will be added back to the warehouse and the invoice credited. This cannot be undone.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/sales-returns/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved — stock and invoice updated', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Error', detail, life: 5000 })
  }
}

async function cancelReturn(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.credit_note_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/sales-returns/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeReturn(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.credit_note_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/sales-returns/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
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
function money(v: number) {
  return formatCurrency(v)
}

onMounted(async () => {
  const { data } = await http.get('/invoices', { params: { per_page: 100 } })
  invoices.value = (data.data ?? []).map((x: any) => ({ label: `${x.invoice_number} — ${x.customer?.name ?? ''}`, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Sales Returns / Credit Notes</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="Sales Returns" endpoint="/sales-returns"
          :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
          :columns="[
            { key: 'credit_note_number', label: 'Credit Note #' },
            { key: 'invoice.invoice_number', label: 'Invoice' },
            { key: 'customer.name', label: 'Customer' },
            { key: 'return_date', label: 'Date' },
            { key: 'total', label: 'Total' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button v-if="canCreate" label="New Credit Note" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="credit_note_number" header="Credit Note #" />
      <Column header="Invoice"><template #body="{ data }">{{ data.invoice?.invoice_number }}</template></Column>
      <Column header="Customer"><template #body="{ data }">{{ data.customer?.name }}</template></Column>
      <Column field="return_date" header="Date" />
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <template v-if="data.status === 'draft'">
            <Button v-if="canApprove" label="Approve" size="small" severity="success" outlined @click="approve(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelReturn(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeReturn(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No credit notes yet.</template>
    </DataTable>

    <!-- Create dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="New Sales Return / Credit Note" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>Invoice *</label>
          <Select v-model="form.invoice_id" :options="invoices" optionLabel="label" optionValue="value" filter
            placeholder="Select invoice" @change="onInvoicePick" />
          <small v-if="errors.invoice_id" class="err">{{ errors.invoice_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Return Date *</label>
          <input type="date" v-model="form.return_date" class="date-input" />
          <small v-if="errors.return_date" class="err">{{ errors.return_date[0] }}</small>
        </div>
      </div>

      <div v-if="invoiceInfo" class="inv-summary">
        <div class="inv-cell"><span class="inv-label">Customer</span><span class="inv-value">{{ invoiceInfo.customer?.name }}</span></div>
        <div class="inv-cell"><span class="inv-label">Invoice Total</span><span class="inv-value">{{ money(invoiceInfo.total) }}</span></div>
        <div class="inv-cell"><span class="inv-label">Balance Due</span><span class="inv-value">{{ money(invoiceInfo.balance_due) }}</span></div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <p class="hint">Stock is added back to the sale's warehouse and the credit is applied to this invoice.</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 26%">Product</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Tax %</th><th>Line Total</th><th style="width: 18%">Reason</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="invoiceItems" optionLabel="name" optionValue="product_id" filter
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
            <td><InputText v-model="line.reason" placeholder="e.g. damaged" class="w-full" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
          <tr v-if="!form.items.length"><td colspan="8" class="muted" style="text-align: center">Select an invoice first.</td></tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" :disabled="!invoiceItems.length" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div v-if="form.items.length" class="totals-panel">
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
          <div class="grand-row"><span>Credit Total</span><span>{{ money(pricing.header.total) }}</span></div>
        </div>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Create Draft" :loading="saving" @click="saveReturn" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
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
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.25rem; }
.hint { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.disc-cell { display: flex; gap: 0.3rem; align-items: center; }
.disc-type { width: 60px; }
.disc-type :deep(.p-select) { width: 60px; }
.disc-val :deep(input) { width: 64px; }
.totals-panel { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 1rem; gap: 2rem; flex-wrap: wrap; }
.overall-disc { display: flex; align-items: center; gap: 0.5rem; }
.overall-disc label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.totals { min-width: 260px; margin-left: auto; font-size: 0.9rem; }
.totals > div { display: flex; justify-content: space-between; padding: 0.15rem 0; }
.totals .grand-row { font-size: 1.05rem; font-weight: 600; border-top: 1px solid #e5e7eb; margin-top: 0.25rem; padding-top: 0.4rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.muted { color: #6b7280; }
.w-full :deep(input) { width: 100%; }
.inv-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.75rem; }
.inv-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.inv-label { font-size: 0.75rem; color: #6b7280; }
.inv-value { font-weight: 600; font-size: 0.95rem; }
</style>
