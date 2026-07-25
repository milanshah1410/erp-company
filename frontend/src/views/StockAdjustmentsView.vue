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

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/stock-adjustments')

const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('stock-adjustments.create'))
const canApprove = computed(() => auth.can('stock-adjustments.approve'))
const canEdit = computed(() => auth.can('stock-adjustments.edit'))
const canDelete = computed(() => auth.can('stock-adjustments.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  approved: 'success',
  cancelled: 'danger',
}

const reasons = [
  { label: 'Physical Mismatch', value: 'physical_mismatch' },
  { label: 'Damage', value: 'damage' },
  { label: 'Theft', value: 'theft' },
  { label: 'Correction', value: 'correction' },
  { label: 'Opening Stock', value: 'opening_stock' },
  { label: 'Sample Issue', value: 'sample_issue' },
  { label: 'Consumption', value: 'consumption' },
]
const reasonLabel = (v: string) => reasons.find((r) => r.value === v)?.label ?? v

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
const form = ref<any>({ warehouse_id: null, reason: null, adjustment_date: today(), notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_cost: 0 })
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
  form.value = { warehouse_id: null, reason: null, adjustment_date: today(), notes: '', items: [] }
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/stock-adjustments/${row.id}`)
    const a = data.data
    editingId.value = a.id
    form.value = {
      warehouse_id: a.warehouse_id,
      reason: a.reason,
      adjustment_date: a.adjustment_date,
      notes: a.notes || '',
      items: a.items.map((i: any) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
        unit_cost: Number(i.unit_cost),
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load adjustment', life: 3000 })
  }
}

async function saveAdjustment() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/stock-adjustments/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Adjustment updated', life: 2500 })
    } else {
      await http.post('/stock-adjustments', form.value)
      toast.add({ severity: 'success', summary: 'Adjustment created as draft', life: 2500 })
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
    title: `Approve ${row.adjustment_number}?`,
    text: 'Stock levels will be updated and ledger entries posted. This cannot be undone.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-adjustments/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved — stock updated', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Error', detail, life: 5000 })
  }
}

async function cancelAdjustment(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.adjustment_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel adjustment' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-adjustments/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeAdjustment(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.adjustment_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/stock-adjustments/${row.id}`)
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
  const [w, p] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Stock Adjustments</h1>
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
        <TableExportButtons title="Stock Adjustments" endpoint="/stock-adjustments"
          :params="() => ({ status: statusFilter || undefined, search: search || undefined, warehouse_id: warehouseFilter || undefined })"
          :columns="[
            { key: 'adjustment_number', label: 'Adjustment #' },
            { key: 'warehouse.name', label: 'Warehouse' },
            { key: 'reason', label: 'Reason' },
            { key: 'adjustment_date', label: 'Date' },
            { key: 'items_count', label: 'Lines' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button v-if="canCreate" label="New Adjustment" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="adjustment_number" header="Adjustment #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="Reason">
        <template #body="{ data }"><Tag severity="secondary" :value="reasonLabel(data.reason)" /></template>
      </Column>
      <Column field="adjustment_date" header="Date" />
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
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelAdjustment(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeAdjustment(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No stock adjustments yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Adjustment' : 'New Stock Adjustment'" :style="{ width: '760px' }">
      <div class="form-top">
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Reason *</label>
          <Select v-model="form.reason" :options="reasons" optionLabel="label" optionValue="value" placeholder="Select reason" />
          <small v-if="errors.reason" class="err">{{ errors.reason[0] }}</small>
        </div>
        <div class="fld">
          <label>Adjustment Date *</label>
          <input type="date" v-model="form.adjustment_date" class="date-input" />
          <small v-if="errors.adjustment_date" class="err">{{ errors.adjustment_date[0] }}</small>
        </div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <p class="hint">Positive quantity adds stock, negative removes it. Unit cost is used only for inward (positive) lines.</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 46%">Product</th><th>Qty (+/-)</th><th>Unit Cost</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                placeholder="Product" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.unit_cost" :minFractionDigits="2" :maxFractionDigits="2" class="num" :disabled="(line.quantity || 0) <= 0" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" placeholder="e.g. cycle-count variance details, damage report reference" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="saveAdjustment" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="Stock Adjustment" :record="detailRecord" :fields="[
      { key: 'adjustment_number', label: 'Adjustment #' },
      { key: 'warehouse.name', label: 'Warehouse' },
      { key: 'reason', label: 'Reason', format: (v) => reasonLabel(v) },
      { key: 'adjustment_date', label: 'Date' },
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
.num :deep(input) { width: 110px; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
</style>
