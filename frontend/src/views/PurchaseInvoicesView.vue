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

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/purchase-invoices')

const purchaseOrders = ref<any[]>([])

const canCreate = computed(() => auth.can('purchase-invoices.create'))
const canApprove = computed(() => auth.can('purchase-invoices.approve'))
const canEdit = computed(() => auth.can('purchase-invoices.edit'))
const canDelete = computed(() => auth.can('purchase-invoices.delete'))

const statusColors: Record<string, string> = { pending: 'secondary', approved: 'success', cancelled: 'danger' }
const matchColors: Record<string, string> = { matched: 'success', exception: 'warn' }

const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Cancelled', value: 'cancelled' },
]

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({ purchase_order_id: null, invoice_number: '', invoice_date: today(), due_date: null, notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})
const poLoading = ref(false)

// --- Detail (variance) dialog ---
const detailVisible = ref(false)
const detailInvoice = ref<any>(null)

// --- Payment dialog ---
const payVisible = ref(false)
const paying = ref(false)
const payInvoiceRow = ref<any>(null)
const payForm = ref<{ amount: number | null; mode: string; payment_date: string; notes: string }>({
  amount: null, mode: 'bank', payment_date: today(), notes: '',
})
const payErrors = ref<Record<string, string[]>>({})
const payModes = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank', value: 'bank' },
  { label: 'UPI', value: 'upi' },
  { label: 'Cheque', value: 'cheque' },
  { label: 'Adjustment', value: 'adjustment' },
]
const paymentStatusColors: Record<string, string> = { unpaid: 'danger', partial: 'warn', paid: 'success' }

function today() {
  return new Date().toISOString().slice(0, 10)
}

async function onPoPick() {
  form.value.items = []
  if (!form.value.purchase_order_id) return
  poLoading.value = true
  try {
    const { data } = await http.get(`/purchase-orders/${form.value.purchase_order_id}`)
    const po = data.data
    form.value.items = po.items.map((i: any) => ({
      purchase_item_id: i.id,
      product: i.product,
      ordered_quantity: Number(i.quantity),
      received_quantity: Number(i.received_quantity),
      quantity: Number(i.received_quantity) || Number(i.quantity),
      unit_cost: Number(i.unit_cost),
      tax_percent: Number(i.tax_percent) || 0,
    }))
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load purchase order', life: 3000 })
  } finally {
    poLoading.value = false
  }
}

const grandTotal = computed(() =>
  form.value.items.reduce((sum: number, it: any) => {
    const base = (Number(it.quantity) || 0) * (Number(it.unit_cost) || 0)
    return sum + base + (base * (Number(it.tax_percent) || 0)) / 100
  }, 0),
)

function openCreate() {
  editingId.value = null
  form.value = { purchase_order_id: null, invoice_number: '', invoice_date: today(), due_date: null, notes: '', items: [] }
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/purchase-invoices/${row.id}`)
    const inv = data.data
    editingId.value = inv.id
    form.value = {
      purchase_order_id: inv.purchase_order_id,
      invoice_number: inv.invoice_number,
      invoice_date: inv.invoice_date,
      due_date: inv.due_date,
      notes: inv.notes || '',
      items: inv.items.map((i: any) => ({
        purchase_item_id: i.purchase_item_id,
        product: i.product,
        ordered_quantity: null,
        received_quantity: i.variance?.received_quantity ?? null,
        quantity: Number(i.quantity),
        unit_cost: Number(i.unit_cost),
        tax_percent: Number(i.tax_percent) || 0,
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoice', life: 3000 })
  }
}

async function saveInvoice() {
  saving.value = true
  errors.value = {}
  const payload = {
    purchase_order_id: form.value.purchase_order_id,
    invoice_number: form.value.invoice_number,
    invoice_date: form.value.invoice_date,
    due_date: form.value.due_date || null,
    notes: form.value.notes,
    items: form.value.items.map((l: any) => ({
      purchase_item_id: l.purchase_item_id,
      quantity: l.quantity,
      unit_cost: l.unit_cost,
      tax_percent: l.tax_percent,
    })),
  }
  try {
    if (editingId.value) {
      await http.put(`/purchase-invoices/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Invoice updated', life: 2500 })
    } else {
      await http.post('/purchase-invoices', payload)
      toast.add({ severity: 'success', summary: 'Invoice recorded', life: 2500 })
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

async function openDetail(row: any) {
  try {
    const { data } = await http.get(`/purchase-invoices/${row.id}`)
    detailInvoice.value = data.data
    detailVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoice', life: 3000 })
  }
}

async function approve(row: any) {
  const exceptionText = row.match_status === 'exception'
    ? 'This invoice has line(s) outside tolerance — approve anyway?'
    : `Approve ${row.invoice_number}?`
  const res = await Swal.fire({ title: exceptionText, icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/purchase-invoices/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved for payment', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function cancelInvoice(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.invoice_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel invoice' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/purchase-invoices/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeInvoice(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.invoice_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/purchase-invoices/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function openPay(row: any) {
  payInvoiceRow.value = row
  payForm.value = { amount: Number(row.balance_due) || null, mode: 'bank', payment_date: today(), notes: '' }
  payErrors.value = {}
  payVisible.value = true
}

async function savePayment() {
  if (!payInvoiceRow.value) return
  paying.value = true
  payErrors.value = {}
  try {
    await http.post(`/purchase-invoices/${payInvoiceRow.value.id}/payments`, {
      amount: payForm.value.amount,
      mode: payForm.value.mode,
      payment_date: payForm.value.payment_date,
      notes: payForm.value.notes || null,
    })
    toast.add({ severity: 'success', summary: 'Payment recorded', life: 2500 })
    payVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) payErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    paying.value = false
  }
}

function reload() {
  crud.fetch(statusFilter.value ? { status: statusFilter.value } : {})
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
  const { data } = await http.get('/purchase-orders', { params: { per_page: 100 } })
  purchaseOrders.value = (data.data ?? data).map((p: any) => ({ label: p.po_number, value: p.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Vendor Invoices</h1>
      <div class="header-actions">
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <Button v-if="canCreate" label="Record Invoice" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="invoice_number" header="Invoice #">
        <template #body="{ data }"><a href="#" @click.prevent="openDetail(data)">{{ data.invoice_number }}</a></template>
      </Column>
      <Column header="PO"><template #body="{ data }">{{ data.purchase_order?.po_number }}</template></Column>
      <Column header="Supplier"><template #body="{ data }">{{ data.supplier?.name }}</template></Column>
      <Column field="invoice_date" header="Date" />
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Match">
        <template #body="{ data }"><Tag :severity="matchColors[data.match_status]" :value="data.match_status" /></template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Payment">
        <template #body="{ data }">
          <template v-if="data.status === 'approved'">
            <Tag :severity="paymentStatusColors[data.payment_status]" :value="data.payment_status" />
            <div v-if="data.payment_status !== 'paid'" class="balance">Due: {{ money(data.balance_due) }}</div>
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <template v-if="data.status === 'pending'">
            <Button v-if="canApprove" label="Approve" size="small" severity="success" outlined @click="approve(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelInvoice(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeInvoice(data)" />
          </template>
          <Button v-if="data.status === 'approved' && data.payment_status !== 'paid' && canCreate"
            label="Record Payment" icon="pi pi-wallet" size="small" outlined @click="openPay(data)" />
        </template>
      </Column>
      <template #empty>No vendor invoices yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Vendor Invoice' : 'Record Vendor Invoice'" :style="{ width: '860px' }">
      <div class="form-top">
        <div class="fld">
          <label>Purchase Order *</label>
          <Select v-model="form.purchase_order_id" :options="purchaseOrders" optionLabel="label" optionValue="value"
            filter placeholder="Select PO" :disabled="!!editingId" @change="onPoPick" />
          <small v-if="errors.purchase_order_id" class="err">{{ errors.purchase_order_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Vendor Invoice # *</label>
          <InputText v-model="form.invoice_number" placeholder="Supplier's invoice number" />
          <small v-if="errors.invoice_number" class="err">{{ errors.invoice_number[0] }}</small>
        </div>
        <div class="fld">
          <label>Invoice Date *</label>
          <input type="date" v-model="form.invoice_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Due Date</label>
          <input type="date" v-model="form.due_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <p class="hint">Ordered/received quantities and PO price are shown for reference; edit to match the vendor's invoice.</p>
      <table class="lines" v-if="form.purchase_order_id">
        <thead>
          <tr><th style="width: 28%">Product</th><th>Ordered</th><th>Received</th><th>Invoice Qty</th><th>Unit Cost</th><th>Tax %</th><th>Line Total</th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>{{ line.product?.name }}</td>
            <td>{{ line.ordered_quantity ?? '—' }}</td>
            <td>{{ line.received_quantity ?? '—' }}</td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_cost" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><InputNumber v-model="line.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td class="line-total">{{ money(((line.quantity || 0) * (line.unit_cost || 0)) * (1 + (line.tax_percent || 0) / 100)) }}</td>
          </tr>
          <tr v-if="!form.items.length"><td colspan="7" style="text-align: center; color: #6b7280">{{ poLoading ? 'Loading…' : 'Select a purchase order to load its lines.' }}</td></tr>
        </tbody>
      </table>
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="grand">Total: <strong>{{ money(grandTotal) }}</strong></div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Record Invoice'" :loading="saving" @click="saveInvoice" />
      </template>
    </Dialog>

    <!-- Variance detail dialog -->
    <Dialog v-model:visible="detailVisible" modal :header="`Match Detail — ${detailInvoice?.invoice_number}`" :style="{ width: '760px' }">
      <table class="lines" v-if="detailInvoice">
        <thead>
          <tr><th style="width: 24%">Product</th><th>Invoiced</th><th>Received</th><th>Invoice Price</th><th>PO Price</th><th>Variance</th></tr>
        </thead>
        <tbody>
          <tr v-for="line in detailInvoice.items" :key="line.id" :class="{ exception: !line.variance?.within_tolerance }">
            <td>{{ line.product?.name }}</td>
            <td>{{ line.quantity }}</td>
            <td>{{ line.variance?.received_quantity }}</td>
            <td>{{ money(line.unit_cost) }}</td>
            <td>{{ money(line.variance?.po_unit_cost) }}</td>
            <td>
              <Tag v-if="line.variance?.within_tolerance" severity="success" value="matched" />
              <Tag v-else severity="warn" :value="`qty ${line.variance?.quantity_variance}, price ${line.variance?.price_variance}`" />
            </td>
          </tr>
        </tbody>
      </table>
      <template #footer>
        <Button label="Close" text @click="detailVisible = false" />
      </template>
    </Dialog>

    <!-- Record payment dialog -->
    <Dialog v-model:visible="payVisible" modal :header="`Record Payment — ${payInvoiceRow?.invoice_number}`" :style="{ width: '440px' }">
      <div class="pay-info" v-if="payInvoiceRow">
        <span>Supplier: <strong>{{ payInvoiceRow.supplier?.name }}</strong></span>
        <span>Balance Due: <strong>{{ money(payInvoiceRow.balance_due) }}</strong></span>
      </div>
      <div class="pay-form">
        <div class="fld">
          <label>Amount *</label>
          <InputNumber v-model="payForm.amount" mode="currency" currency="INR" locale="en-IN" />
          <small v-if="payErrors.amount" class="err">{{ payErrors.amount[0] }}</small>
        </div>
        <div class="fld">
          <label>Mode *</label>
          <Select v-model="payForm.mode" :options="payModes" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Payment Date *</label>
          <input type="date" v-model="payForm.payment_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Notes</label>
          <Textarea v-model="payForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="payVisible = false" />
        <Button label="Save Payment" :loading="paying" @click="savePayment" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 160px; }
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(input) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; width: 100%; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.25rem; }
.hint { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.num :deep(input) { width: 90px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.grand { text-align: right; margin-top: 0.75rem; font-size: 1.05rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.exception { background: #fffbeb; }
.balance { font-size: 0.75rem; color: #b45309; margin-top: 0.15rem; }
.muted { color: #9ca3af; }
.pay-info { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb; }
.pay-form { display: flex; flex-direction: column; gap: 0.9rem; }
</style>
