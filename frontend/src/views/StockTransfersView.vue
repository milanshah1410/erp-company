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
const crud = useCrud('/stock-transfers')

const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('stock-transfers.create'))
const canApprove = computed(() => auth.can('stock-transfers.approve'))
const canEdit = computed(() => auth.can('stock-transfers.edit'))
const canDelete = computed(() => auth.can('stock-transfers.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  in_transit: 'warn',
  received: 'success',
  cancelled: 'danger',
}
const statusLabels: Record<string, string> = {
  draft: 'draft',
  in_transit: 'in transit',
  received: 'received',
  cancelled: 'cancelled',
}

const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'In Transit', value: 'in_transit' },
  { label: 'Received', value: 'received' },
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
const form = ref<any>({ from_warehouse_id: null, to_warehouse_id: null, transfer_date: today(), notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1 })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}

function openCreate() {
  editingId.value = null
  form.value = { from_warehouse_id: null, to_warehouse_id: null, transfer_date: today(), notes: '', items: [] }
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/stock-transfers/${row.id}`)
    const t = data.data
    editingId.value = t.id
    form.value = {
      from_warehouse_id: t.from_warehouse_id,
      to_warehouse_id: t.to_warehouse_id,
      transfer_date: t.transfer_date,
      notes: t.notes || '',
      items: t.items.map((i: any) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load transfer', life: 3000 })
  }
}

async function saveTransfer() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/stock-transfers/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Transfer updated', life: 2500 })
    } else {
      await http.post('/stock-transfers', form.value)
      toast.add({ severity: 'success', summary: 'Transfer created as draft', life: 2500 })
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

// --- Workflow actions ---
async function dispatchTransfer(row: any) {
  const res = await Swal.fire({
    title: `Dispatch ${row.transfer_number}?`,
    text: `Stock will leave ${row.from_warehouse?.name} and go in transit.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Dispatch',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-transfers/${row.id}/dispatch`)
    toast.add({ severity: 'success', summary: 'Dispatched — stock in transit', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Cannot dispatch', detail, life: 5000 })
  }
}

async function receiveTransfer(row: any) {
  const res = await Swal.fire({
    title: `Receive ${row.transfer_number}?`,
    text: `Stock will be added to ${row.to_warehouse?.name}.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Receive',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-transfers/${row.id}/receive`)
    toast.add({ severity: 'success', summary: 'Received — stock updated', life: 3000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot receive', detail: e.response?.data?.message || 'Failed', life: 5000 })
  }
}

async function cancelTransfer(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.transfer_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel transfer' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/stock-transfers/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeTransfer(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.transfer_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/stock-transfers/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  crud.fetch({ status: statusFilter.value || undefined, search: search.value || undefined })
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
    <PageHeader title="Stock Transfers" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="Stock Transfers" endpoint="/stock-transfers"
        :params="() => ({ status: statusFilter || undefined, search: search || undefined })"
        :columns="[
        { key: 'transfer_number', label: 'Transfer #' },
        { key: 'from_warehouse.name', label: 'From' },
        { key: 'to_warehouse.name', label: 'To' },
        { key: 'transfer_date', label: 'Date' },
        { key: 'items_count', label: 'Lines' },
        { key: 'status', label: 'Status' },
        { key: 'received_date', label: 'Received' },
        ]" />
        <Button v-if="canCreate" label="New Transfer" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="transfer_number" header="Transfer #" />
      <Column header="Route">
        <template #body="{ data }">
          {{ data.from_warehouse?.name }}
          <i class="pi pi-arrow-right route-arrow" />
          {{ data.to_warehouse?.name }}
        </template>
      </Column>
      <Column field="transfer_date" header="Date" />
      <Column field="items_count" header="Lines" style="width: 5rem" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="statusLabels[data.status] ?? data.status" />
        </template>
      </Column>
      <Column field="received_date" header="Received">
        <template #body="{ data }">{{ data.received_date || '—' }}</template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <template v-if="data.status === 'draft'">
            <Button v-if="canApprove" label="Dispatch" size="small" severity="success" outlined @click="dispatchTransfer(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelTransfer(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeTransfer(data)" />
          </template>
          <Button v-if="canEdit && data.status === 'in_transit'" label="Receive" size="small" severity="success" @click="receiveTransfer(data)" />
        </template>
      </Column>
      <template #empty>No stock transfers yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Transfer' : 'New Stock Transfer'" :style="{ width: '720px' }">
      <div class="form-top">
        <div class="fld">
          <label>From Warehouse *</label>
          <Select v-model="form.from_warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Source" />
          <small v-if="errors.from_warehouse_id" class="err">{{ errors.from_warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>To Warehouse *</label>
          <Select v-model="form.to_warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Destination" />
          <small v-if="errors.to_warehouse_id" class="err">{{ errors.to_warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Transfer Date *</label>
          <input type="date" v-model="form.transfer_date" class="date-input" />
          <small v-if="errors.transfer_date" class="err">{{ errors.transfer_date[0] }}</small>
        </div>
      </div>

      <h3 class="lines-title">Line Items</h3>
      <p class="hint">Cost is snapshotted from the source warehouse at dispatch and carried to the destination.</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 70%">Product</th><th>Qty</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter
                placeholder="Product" class="w-full" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" placeholder="e.g. vehicle number, courier reference" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="saveTransfer" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="Stock Transfer" :record="detailRecord" :fields="[
      { key: 'transfer_number', label: 'Transfer #' },
      { key: 'from_warehouse.name', label: 'From' },
      { key: 'to_warehouse.name', label: 'To' },
      { key: 'transfer_date', label: 'Date' },
      { key: 'items_count', label: 'Lines' },
      { key: 'status', label: 'Status', format: (v) => statusLabels[v] ?? v },
      { key: 'received_date', label: 'Received' },
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
.route-arrow { font-size: 0.7rem; color: #6b7280; margin: 0 0.4rem; }
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
