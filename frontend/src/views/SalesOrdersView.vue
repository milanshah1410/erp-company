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
const crud = useCrud('/sales-orders')

const customers = ref<any[]>([])
const brokers = ref<any[]>([])
const salesmen = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('sales.create'))
const canEdit = computed(() => auth.can('sales.edit'))
const canApprove = computed(() => auth.can('sales.approve'))
const canEmail = computed(() => auth.can('email.create'))

// --- Invoice PDF preview + Email ---
async function previewInvoicePdf(row: any) {
  if (!row.invoice) return
  try {
    const res = await http.get(`/invoices/${row.invoice.id}/pdf`, { responseType: 'blob' })
    openPdfBlob(res.data)
  } catch {
    toast.add({ severity: 'error', summary: 'Could not open invoice PDF', life: 3000 })
  }
}

const emailVisible = ref(false)
const emailCtx = ref<{ endpoint: string; title: string; defaultTo: string; defaultSubject: string }>({
  endpoint: '', title: '', defaultTo: '', defaultSubject: '',
})
function emailInvoice(row: any) {
  if (!row.invoice) return
  emailCtx.value = {
    endpoint: `/email/invoices/${row.invoice.id}`,
    title: `Email Invoice ${row.invoice.invoice_number}`,
    defaultTo: row.customer?.email ?? '',
    defaultSubject: `Invoice ${row.invoice.invoice_number}`,
  }
  emailVisible.value = true
}

const statusColors: Record<string, string> = {
  draft: 'secondary',
  confirmed: 'info',
  backordered: 'warn',
  delivered: 'success',
  cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'Backordered', value: 'backordered' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]

const invoiceStatusColors: Record<string, string> = {
  unpaid: 'danger',
  partial: 'warn',
  paid: 'success',
}

const fulfillmentColors: Record<string, string> = {
  pending: 'secondary',
  picked: 'info',
  packed: 'warn',
  shipped: 'success',
}
const fulfillmentLabels: Record<string, string> = {
  pending: 'Not Started',
  picked: 'Picked',
  packed: 'Packed',
  shipped: 'Shipped',
}

// --- Create dialog ---
const createVisible = ref(false)
const saving = ref(false)
const form = ref<any>({ customer_id: null, broker_id: null, salesman_id: null, warehouse_id: null, order_date: today(), notes: '', discount_type: null as DiscountType, discount_value: 0, items: [], brokers: [] })
const errors = ref<Record<string, string[]>>({})

// Multi-broker per invoice: when enabled, the single broker select is replaced
// by an editable list (each broker with an optional rate override + deal share).
const multiBroker = ref(false)
function addBroker() {
  form.value.brokers.push({ broker_id: null, commission_percent: null, fixed_commission: null, share_percent: 100 })
}
function removeBroker(i: number | string) {
  form.value.brokers.splice(Number(i), 1)
}

function today() {
  return new Date().toISOString().slice(0, 10)
}

const pricing = computed(() => priceDocument(form.value.items, form.value.discount_type, form.value.discount_value))

const tcsRates = ref<any[]>([])
const estTcs = computed(() => Math.round((Number(pricing.value.header.total) || 0) * (Number(form.value.tcs_rate) || 0)) / 100)

function onTcsSectionChange() {
  form.value.tcs_rate = tcsRates.value.find((r) => r.value === form.value.tcs_section)?.rate ?? 0
}

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
  form.value = { customer_id: null, broker_id: null, salesman_id: null, warehouse_id: null, order_date: today(), tcs_section: null, tcs_rate: 0, notes: '', discount_type: null, discount_value: 0, items: [], brokers: [] }
  multiBroker.value = false
  addLine()
  errors.value = {}
  createVisible.value = true
}

async function saveOrder() {
  saving.value = true
  errors.value = {}
  try {
    // Multi-broker mode sends brokers[] (backend derives the primary broker_id);
    // single mode sends broker_id and no brokers[].
    const { brokers, broker_id, ...rest } = form.value
    const payload = multiBroker.value
      ? { ...rest, brokers: (brokers ?? []).filter((b: any) => b.broker_id) }
      : { ...rest, broker_id }
    await http.post('/sales-orders', payload)
    toast.add({ severity: 'success', summary: 'Sales order created', life: 2500 })
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
async function confirmOrder(row: any) {
  const res = await Swal.fire({ title: `Confirm ${row.so_number}?`, text: 'Available stock will be reserved. Any shortfall is flagged as a backorder instead of blocking the order.', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirm' })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/sales-orders/${row.id}/confirm`)
    if (data.data?.status === 'backordered') {
      toast.add({ severity: 'warn', summary: 'Order backordered', detail: 'Some lines could not be fully reserved.', life: 4000 })
    } else {
      toast.add({ severity: 'success', summary: 'Order confirmed', life: 2500 })
    }
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot confirm', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function recheckOrder(row: any) {
  try {
    const { data } = await http.post(`/sales-orders/${row.id}/recheck`)
    if (data.data?.status === 'confirmed') {
      toast.add({ severity: 'success', summary: 'Fully reserved — order confirmed', life: 3000 })
    } else {
      toast.add({ severity: 'info', summary: 'Still short on stock for some lines', life: 3000 })
    }
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot recheck', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function pickOrder(row: any) {
  try {
    await http.post(`/sales-orders/${row.id}/pick`)
    toast.add({ severity: 'success', summary: 'Order picked', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot pick', detail: e.response?.data?.message || 'Failed', life: 5000 })
  }
}

async function packOrder(row: any) {
  try {
    await http.post(`/sales-orders/${row.id}/pack`)
    toast.add({ severity: 'success', summary: 'Order packed', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot pack', detail: e.response?.data?.message || 'Failed', life: 5000 })
  }
}

async function shipOrder(row: any) {
  try {
    await http.post(`/sales-orders/${row.id}/ship`)
    toast.add({ severity: 'success', summary: 'Order shipped', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot ship', detail: e.response?.data?.message || 'Failed', life: 5000 })
  }
}

async function deliverOrder(row: any) {
  const res = await Swal.fire({ title: `Deliver ${row.so_number}?`, text: 'Stock will be deducted and an invoice created.', icon: 'question', showCancelButton: true, confirmButtonText: 'Deliver' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/sales-orders/${row.id}/deliver`)
    toast.add({ severity: 'success', summary: 'Order delivered — invoice created', life: 3000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot deliver', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function cancelOrder(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.so_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel Order', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/sales-orders/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Order cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot cancel', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

// --- Payment dialog ---
const payVisible = ref(false)
const payLoading = ref(false)
const paySaving = ref(false)
const invoice = ref<any>(null)
const payForm = ref<any>({ amount: 0, mode: 'cash', payment_date: today(), notes: '' })
const payErrors = ref<Record<string, string[]>>({})

const paymentModes = [
  { label: 'Cash', value: 'cash' },
  { label: 'Card', value: 'card' },
  { label: 'UPI', value: 'upi' },
  { label: 'Credit', value: 'credit' },
]

async function openPayment(row: any) {
  if (!row.invoice) return
  payVisible.value = true
  payLoading.value = true
  invoice.value = null
  payErrors.value = {}
  try {
    const { data } = await http.get(`/invoices/${row.invoice.id}`)
    invoice.value = data.data
    payForm.value = { amount: Number(invoice.value.balance_due) || 0, mode: 'cash', payment_date: today(), notes: '' }
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoice', life: 3000 })
    payVisible.value = false
  } finally {
    payLoading.value = false
  }
}

async function savePayment() {
  paySaving.value = true
  payErrors.value = {}
  try {
    const { data } = await http.post(`/invoices/${invoice.value.id}/payments`, payForm.value)
    invoice.value = data.data
    payForm.value = { amount: Number(invoice.value.balance_due) || 0, mode: 'cash', payment_date: today(), notes: '' }
    toast.add({ severity: 'success', summary: 'Payment recorded', life: 2500 })
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) payErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    paySaving.value = false
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
  const [c, w, p, b, sm, t] = await Promise.all([
    http.get('/customers', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/brokers', { params: { per_page: 100, is_active: true } }),
    http.get('/salesmen', { params: { per_page: 100, is_active: true } }),
    http.get('/tds-tcs-rates', { params: { per_page: 100, nature: 'tcs', is_active: true } }),
  ])
  tcsRates.value = (t.data.data ?? t.data).map((x: any) => ({ label: `${x.section} (${x.rate}%)`, value: x.section, rate: Number(x.rate) }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  brokers.value = (b.data.data ?? b.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  salesmen.value = (sm.data.data ?? sm.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Sales Orders</h1>
      <Button v-if="canCreate" label="New Sales Order" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;gap:0.5rem;align-items:center;margin-bottom:1rem">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
      <TableExportButtons title="Sales Orders" only="export" endpoint="/sales-orders"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
          { key: 'so_number', label: 'SO #' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'order_date', label: 'Date' },
          { key: 'total', label: 'Total' },
          { key: 'status', label: 'Status' },
          { key: 'fulfillment_stage', label: 'Fulfillment' },
          { key: 'invoice.invoice_number', label: 'Invoice' },
          { key: 'invoice.status', label: 'Invoice Status' },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="so_number" header="SO #" />
      <Column header="Customer"><template #body="{ data }">{{ data.customer?.name }}</template></Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column field="order_date" header="Date" />
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Fulfillment">
        <template #body="{ data }">
          <Tag v-if="data.status === 'confirmed' && data.channel !== 'pos'" :severity="fulfillmentColors[data.fulfillment_stage]" :value="fulfillmentLabels[data.fulfillment_stage]" />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Invoice">
        <template #body="{ data }">
          <template v-if="data.invoice">
            <span class="inv-number">{{ data.invoice.invoice_number }}</span>
            <Tag class="inv-tag" :severity="invoiceStatusColors[data.invoice.status]" :value="data.invoice.status" />
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 20rem">
        <template #body="{ data }">
          <Button v-if="canApprove && data.status === 'draft'" label="Confirm" size="small" outlined @click="confirmOrder(data)" />
          <Button v-if="canApprove && data.status === 'backordered'" label="Recheck" size="small" outlined severity="warn" @click="recheckOrder(data)" />
          <template v-if="canEdit && data.status === 'confirmed' && data.channel !== 'pos'">
            <Button v-if="data.fulfillment_stage === 'pending'" label="Pick" size="small" outlined @click="pickOrder(data)" />
            <Button v-if="data.fulfillment_stage === 'picked'" label="Pack" size="small" outlined @click="packOrder(data)" />
            <Button v-if="data.fulfillment_stage === 'packed'" label="Ship" size="small" outlined @click="shipOrder(data)" />
          </template>
          <Button v-if="canEdit && data.status === 'confirmed' && (data.channel === 'pos' || data.fulfillment_stage === 'shipped')" label="Deliver" size="small" severity="success" @click="deliverOrder(data)" />
          <Button v-if="canEdit && ['draft', 'confirmed', 'backordered'].includes(data.status)" label="Cancel" size="small" outlined severity="danger" @click="cancelOrder(data)" style="margin-left: 4px" />
          <Button v-if="canCreate && data.status === 'delivered' && data.invoice" label="Payment" icon="pi pi-wallet" size="small" outlined @click="openPayment(data)" style="margin-left: 4px" />
          <Button v-if="data.invoice" icon="pi pi-file-pdf" text rounded size="small" title="Invoice PDF" @click="previewInvoicePdf(data)" />
          <Button v-if="canEmail && data.invoice" icon="pi pi-envelope" text rounded size="small" title="Email invoice" @click="emailInvoice(data)" />
        </template>
      </Column>
      <template #empty>No sales orders yet.</template>
    </DataTable>

    <!-- Create SO dialog -->
    <Dialog v-model:visible="createVisible" modal header="New Sales Order" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>Customer *</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter placeholder="Select customer" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Broker
            <a class="multi-toggle" @click="multiBroker = !multiBroker">
              {{ multiBroker ? 'single broker' : 'multiple brokers' }}
            </a>
          </label>
          <Select v-if="!multiBroker" v-model="form.broker_id" :options="brokers" optionLabel="label" optionValue="value" filter showClear placeholder="No broker" />
          <span v-else class="multi-hint">{{ form.brokers.length }} broker(s) — see below</span>
          <small v-if="errors.broker_id" class="err">{{ errors.broker_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Salesman</label>
          <Select v-model="form.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter showClear placeholder="No salesman" />
          <small v-if="errors.salesman_id" class="err">{{ errors.salesman_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Order Date *</label>
          <input type="date" v-model="form.order_date" class="date-input" />
          <small v-if="errors.order_date" class="err">{{ errors.order_date[0] }}</small>
        </div>
      </div>

      <div v-if="multiBroker" class="brokers-block">
        <h3 class="lines-title">Brokers (Dalal)</h3>
        <table class="lines">
          <thead>
            <tr><th style="width: 40%">Broker</th><th>Commission %</th><th>Fixed</th><th>Share %</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="(b, i) in form.brokers" :key="i">
              <td>
                <Select v-model="b.broker_id" :options="brokers" optionLabel="label" optionValue="value" filter placeholder="Broker" class="w-full" />
              </td>
              <td><InputNumber v-model="b.commission_percent" :minFractionDigits="0" :maxFractionDigits="2" placeholder="default" class="num" /></td>
              <td><InputNumber v-model="b.fixed_commission" :minFractionDigits="0" :maxFractionDigits="2" placeholder="default" class="num" /></td>
              <td><InputNumber v-model="b.share_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
              <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeBroker(i)" /></td>
            </tr>
            <tr v-if="!form.brokers.length"><td colspan="5" class="muted">No brokers added.</td></tr>
          </tbody>
        </table>
        <Button label="Add Broker" icon="pi pi-plus" text size="small" @click="addBroker" />
        <small class="hint block">Leave Commission %/Fixed blank to use each broker's product/customer rate or default. Share % splits one deal across brokers.</small>
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
          <div class="tcs-row">
            <Select v-model="form.tcs_section" :options="tcsRates" optionLabel="label" optionValue="value" showClear placeholder="No TCS" class="tcs-select" @change="onTcsSectionChange" />
            <span>TCS {{ form.tcs_rate }}%</span><span>+{{ money(estTcs) }}</span>
          </div>
          <div v-if="estTcs" class="grand-row"><span>Total Collectible</span><span>{{ money(pricing.header.total + estTcs) }}</span></div>
        </div>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="createVisible = false" />
        <Button label="Create Order" :loading="saving" @click="saveOrder" />
      </template>
    </Dialog>

    <!-- Payment dialog -->
    <Dialog v-model:visible="payVisible" modal :header="invoice ? `Invoice ${invoice.invoice_number}` : 'Invoice'" :style="{ width: '560px' }">
      <div v-if="payLoading" class="muted">Loading invoice…</div>
      <template v-else-if="invoice">
        <div class="inv-summary">
          <div class="inv-cell"><span class="inv-label">Total</span><span class="inv-value">{{ money(invoice.total) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Paid</span><span class="inv-value">{{ money(invoice.paid_amount) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Balance Due</span><span class="inv-value due">{{ money(invoice.balance_due) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Status</span><Tag :severity="invoiceStatusColors[invoice.status]" :value="invoice.status" /></div>
        </div>

        <h3 class="lines-title">Payments</h3>
        <table class="lines">
          <thead><tr><th>Date</th><th>Mode</th><th style="text-align: right">Amount</th></tr></thead>
          <tbody>
            <tr v-for="p in invoice.payments" :key="p.id">
              <td>{{ p.payment_date }}</td>
              <td>{{ p.mode }}</td>
              <td style="text-align: right">{{ money(p.amount) }}</td>
            </tr>
            <tr v-if="!invoice.payments?.length"><td colspan="3" class="muted" style="text-align: center">No payments yet.</td></tr>
          </tbody>
        </table>

        <template v-if="invoice.status !== 'paid'">
          <h3 class="lines-title">Record Payment</h3>
          <div class="form-top">
            <div class="fld">
              <label>Amount *</label>
              <InputNumber v-model="payForm.amount" :minFractionDigits="2" :maxFractionDigits="2" class="w-full" />
              <small v-if="payErrors.amount" class="err">{{ payErrors.amount[0] }}</small>
            </div>
            <div class="fld">
              <label>Mode *</label>
              <Select v-model="payForm.mode" :options="paymentModes" optionLabel="label" optionValue="value" />
              <small v-if="payErrors.mode" class="err">{{ payErrors.mode[0] }}</small>
            </div>
            <div class="fld">
              <label>Payment Date *</label>
              <input type="date" v-model="payForm.payment_date" class="date-input" />
              <small v-if="payErrors.payment_date" class="err">{{ payErrors.payment_date[0] }}</small>
            </div>
            <div class="fld">
              <label>Notes</label>
              <Textarea v-model="payForm.notes" rows="1" />
            </div>
          </div>
        </template>
        <div v-else class="paid-note">Fully paid — no balance outstanding.</div>
      </template>
      <template #footer>
        <Button label="Close" text @click="payVisible = false" />
        <Button v-if="invoice && invoice.status !== 'paid'" label="Record Payment" :loading="paySaving" @click="savePayment" />
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
.totals .tcs-row { align-items: center; gap: 0.5rem; margin-top: 0.35rem; }
.totals .tcs-select { flex: 1; min-width: 0; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.muted { color: #6b7280; }
.inv-number { font-size: 0.85rem; }
.inv-tag { margin-left: 6px; font-size: 0.65rem; padding: 1px 6px; }
.inv-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.inv-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.inv-label { font-size: 0.75rem; color: #6b7280; }
.inv-value { font-weight: 600; font-size: 0.95rem; }
.inv-value.due { color: #dc2626; }
.paid-note { margin-top: 1rem; padding: 0.6rem 0.8rem; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; color: #047857; font-size: 0.9rem; }
.w-full :deep(input) { width: 100%; }
.multi-toggle { margin-left: 0.5rem; font-size: 0.75rem; color: #2563eb; cursor: pointer; text-decoration: underline; }
.multi-hint { font-size: 0.85rem; color: #6b7280; padding: 0.4rem 0; }
.brokers-block { margin-top: 0.5rem; }
.hint { color: #6b7280; font-size: 0.78rem; }
.hint.block { display: block; margin-top: 0.4rem; }
</style>
