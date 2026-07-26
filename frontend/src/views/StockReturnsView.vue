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
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/stock-returns')

const warehouses = ref<any[]>([])
const products = ref<any[]>([])
const customers = ref<any[]>([])
const suppliers = ref<any[]>([])

const canCreate = computed(() => auth.can('stock-returns.create'))
const canApprove = computed(() => auth.can('stock-returns.approve'))
const canEdit = computed(() => auth.can('stock-returns.edit'))
const canDelete = computed(() => auth.can('stock-returns.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  approved: 'success',
  cancelled: 'danger',
}

const returnTypes = [
  { label: 'Customer Return', value: 'customer_return' },
  { label: 'Vendor Return (RTV)', value: 'vendor_return' },
  { label: 'Other', value: 'other' },
]
const returnTypeLabel = (v: string) => returnTypes.find((t) => t.value === v)?.label ?? v

const statusFilter = ref<string | null>(null)
const warehouseFilter = ref<number | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Approved', value: 'approved' },
  { label: 'Cancelled', value: 'cancelled' },
]

const search = ref('')

// --- Read-only detail dialog ---
const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({
  warehouse_id: null, return_type: 'customer_return', customer_id: null, supplier_id: null,
  debit_note_number: '', return_date: today(), notes: '', items: [],
})
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_cost: 0, reason: '' })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) line.unit_cost = Number(p.cost_price) || 0
}

function openCreate() {
  editingId.value = null
  form.value = {
    warehouse_id: null, return_type: 'customer_return', customer_id: null, supplier_id: null,
    debit_note_number: '', return_date: today(), notes: '', items: [],
  }
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/stock-returns/${row.id}`)
    const r = data.data
    editingId.value = r.id
    form.value = {
      warehouse_id: r.warehouse_id,
      return_type: r.return_type,
      customer_id: r.customer_id,
      supplier_id: r.supplier_id,
      debit_note_number: r.debit_note_number || '',
      return_date: r.return_date,
      notes: r.notes || '',
      items: r.items.map((i: any) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
        unit_cost: Number(i.unit_cost),
        reason: i.reason || '',
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load return', life: 3000 })
  }
}

async function saveReturn() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/stock-returns/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Return updated', life: 2500 })
    } else {
      await http.post('/stock-returns', form.value)
      toast.add({ severity: 'success', summary: 'Return created as draft', life: 2500 })
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

async function approve(row: any) {
  const res = await Swal.fire({
    title: `Approve ${row.return_number}?`,
    text: row.return_type === 'vendor_return'
      ? 'Stock will be removed from the warehouse and sent back to the vendor. This cannot be undone.'
      : 'Stock will be added to the warehouse and ledger entries posted. This cannot be undone.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-returns/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved — stock updated', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Error', detail, life: 5000 })
  }
}

async function cancelReturn(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.return_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel return' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-returns/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeReturn(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.return_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/stock-returns/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  crud.fetch({
    status: statusFilter.value || undefined,
    search: search.value || undefined,
    warehouse_id: warehouseFilter.value || undefined,
  })
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const [w, p, c, s] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 100 } }),
    http.get('/suppliers', { params: { per_page: 100 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Stock Returns (Inward)" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
        <Select v-model="warehouseFilter" :options="warehouses" optionLabel="name" optionValue="id" showClear
        placeholder="All warehouses" @change="reload" class="status-filter" />
        <TableExportButtons title="Stock Returns" endpoint="/stock-returns"
        :params="() => ({ status: statusFilter || undefined, search: search || undefined, warehouse_id: warehouseFilter || undefined })"
        :columns="[
        { key: 'return_number', label: 'Return #' },
        { key: 'warehouse.name', label: 'Warehouse' },
        { key: 'return_type', label: 'Type' },
        { key: 'customer.name', label: 'Customer' },
        { key: 'supplier.name', label: 'Supplier' },
        { key: 'return_date', label: 'Date' },
        { key: 'items_count', label: 'Lines' },
        { key: 'status', label: 'Status' },
        ]" />
        <Button v-if="canCreate" label="New Return" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="return_number" header="Return #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="Type">
        <template #body="{ data }"><Tag severity="secondary" :value="returnTypeLabel(data.return_type)" /></template>
      </Column>
      <Column header="Customer / Supplier"><template #body="{ data }">{{ data.customer?.name || data.supplier?.name || '—' }}</template></Column>
      <Column field="return_date" header="Date" />
      <Column field="items_count" header="Lines" style="width: 5rem" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <template v-if="data.status === 'draft'">
            <Button v-if="canApprove" label="Approve" size="small" severity="success" outlined @click="approve(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelReturn(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeReturn(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No stock returns yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Return' : 'New Stock Return (Inward)'" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Return Type *</label>
          <Select v-model="form.return_type" :options="returnTypes" optionLabel="label" optionValue="value" placeholder="Select type" />
          <small v-if="errors.return_type" class="err">{{ errors.return_type[0] }}</small>
        </div>
        <div class="fld">
          <label>Return Date *</label>
          <input type="date" v-model="form.return_date" class="date-input" />
          <small v-if="errors.return_date" class="err">{{ errors.return_date[0] }}</small>
        </div>
      </div>

      <div class="form-top" v-if="form.return_type === 'customer_return'">
        <div class="fld">
          <label>Customer</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter showClear placeholder="Select customer" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
      </div>

      <div class="form-top" v-if="form.return_type === 'vendor_return'">
        <div class="fld">
          <label>Supplier *</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter showClear placeholder="Select supplier" />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Debit Note Number</label>
          <InputText v-model="form.debit_note_number" placeholder="e.g. DN-000123" />
          <small v-if="errors.debit_note_number" class="err">{{ errors.debit_note_number[0] }}</small>
        </div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <p class="hint">
        {{ form.return_type === 'vendor_return' ? 'All lines remove stock from the selected warehouse and are sent back to the supplier.' : 'All lines add stock back to the selected warehouse.' }}
      </p>
      <table class="lines">
        <thead>
          <tr><th style="width: 32%">Product</th><th>Qty</th><th>Unit Cost</th><th style="width: 26%">Reason</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                placeholder="Product" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_cost" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><InputText v-model="line.reason" placeholder="e.g. wrong item, damaged" class="w-full" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" placeholder="e.g. credit note reference, courier RMA number" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="saveReturn" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="Stock Return" :record="detailRecord" :fields="[
      { key: 'return_number', label: 'Return #' },
      { key: 'warehouse.name', label: 'Warehouse' },
      { key: 'return_type', label: 'Type', format: (v) => returnTypeLabel(v) },
      { key: 'customer.name', label: 'Customer' },
      { key: 'supplier.name', label: 'Supplier' },
      { key: 'debit_note_number', label: 'Debit Note #' },
      { key: 'return_date', label: 'Date' },
      { key: 'items_count', label: 'Lines' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
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
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
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
.num :deep(input) { width: 100px; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
</style>
