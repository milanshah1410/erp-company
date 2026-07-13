<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
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

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/sales-quotations')

const customers = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('sales.create'))
const canEdit = computed(() => auth.can('sales.edit'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  sent: 'info',
  accepted: 'success',
  rejected: 'danger',
  expired: 'warn',
  converted: 'success',
}

// --- Create dialog ---
const createVisible = ref(false)
const saving = ref(false)
const form = ref<any>({ customer_id: null, warehouse_id: null, quote_date: today(), valid_until: '', notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

const grandTotal = computed(() =>
  form.value.items.reduce((sum: number, it: any) => {
    const base = (Number(it.quantity) || 0) * (Number(it.unit_price) || 0)
    return sum + base + (base * (Number(it.tax_percent) || 0)) / 100
  }, 0),
)

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_price: 0, tax_percent: 0 })
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
  form.value = { customer_id: null, warehouse_id: null, quote_date: today(), valid_until: '', notes: '', items: [] }
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
  crud.fetch()
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
      <Button v-if="canCreate" label="New Quotation" icon="pi pi-plus" @click="openCreate" />
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
          <Button v-if="canEdit && data.status === 'draft'" label="Send" size="small" outlined @click="sendQuotation(data)" />
          <Button v-if="canEdit && data.status === 'sent'" label="Accept" size="small" outlined severity="success" @click="acceptQuotation(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && data.status === 'sent'" label="Reject" size="small" outlined severity="danger" @click="rejectQuotation(data)" style="margin-left: 4px" />
          <Button v-if="canCreate && data.status === 'accepted'" label="Convert to SO" size="small" severity="success" @click="convertQuotation(data)" />
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
          <tr><th style="width: 38%">Product</th><th>Qty</th><th>Unit Price</th><th>Tax %</th><th>Line Total</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                placeholder="Product" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_price" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><InputNumber v-model="line.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td class="line-total">{{ money(((line.quantity || 0) * (line.unit_price || 0)) * (1 + (line.tax_percent || 0) / 100)) }}</td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="grand">Total: <strong>{{ money(grandTotal) }}</strong></div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="createVisible = false" />
        <Button label="Create Quotation" :loading="saving" @click="saveQuotation" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
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
.w-full :deep(input) { width: 100%; }
</style>
