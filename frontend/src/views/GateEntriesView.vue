<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
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
const crud = useCrud('/gate-entries')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const warehouses = ref<any[]>([])

const canCreate = computed(() => auth.can('gate-entries.create'))
const canEdit = computed(() => auth.can('gate-entries.edit'))
const canDelete = computed(() => auth.can('gate-entries.delete'))

const statusColors: Record<string, string> = {
  open: 'info',
  closed: 'secondary',
}

const directionOptions = [
  { label: 'Inward', value: 'inward' },
  { label: 'Outward', value: 'outward' },
]
const directionLabel = (v: string) => directionOptions.find((d) => d.value === v)?.label ?? v

const referenceTypeOptions = [
  { label: 'Goods Receipt', value: 'goods_receipt' },
  { label: 'Sales Order', value: 'sales_order' },
  { label: 'Stock Transfer', value: 'stock_transfer' },
  { label: 'Purchase Order', value: 'purchase_order' },
  { label: 'Stock Return', value: 'stock_return' },
  { label: 'Other', value: 'other' },
]

const search = ref('')
const directionFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const warehouseFilter = ref<number | null>(null)
const statusOptions = [
  { label: 'Open', value: 'open' },
  { label: 'Closed', value: 'closed' },
]

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({
  warehouse_id: null, direction: 'inward', vehicle_number: '', driver_name: '', driver_contact: '',
  reference_type: null, reference_number: '', purpose: '',
})
const errors = ref<Record<string, string[]>>({})

function openCreate() {
  editingId.value = null
  form.value = {
    warehouse_id: null, direction: 'inward', vehicle_number: '', driver_name: '', driver_contact: '',
    reference_type: null, reference_number: '', purpose: '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    warehouse_id: row.warehouse_id,
    direction: row.direction,
    vehicle_number: row.vehicle_number,
    driver_name: row.driver_name || '',
    driver_contact: row.driver_contact || '',
    reference_type: row.reference_type,
    reference_number: row.reference_number || '',
    purpose: row.purpose || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function saveEntry() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/gate-entries/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Gate entry updated', life: 2500 })
    } else {
      await http.post('/gate-entries', form.value)
      toast.add({ severity: 'success', summary: 'Gate entry logged', life: 2500 })
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

async function closeEntry(row: any) {
  const res = await Swal.fire({ title: `Close gate entry ${row.entry_number}?`, text: 'Marks the vehicle as having exited.', icon: 'question', showCancelButton: true, confirmButtonText: 'Close' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/gate-entries/${row.id}/close`)
    toast.add({ severity: 'success', summary: 'Gate entry closed', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeEntry(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.entry_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/gate-entries/${row.id}`)
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
  if (directionFilter.value) params.direction = directionFilter.value
  if (warehouseFilter.value) params.warehouse_id = warehouseFilter.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const { data } = await http.get('/warehouses', { params: { per_page: 100 } })
  warehouses.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Gate Entries" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="directionFilter" :options="directionOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All directions" @change="reload" class="status-filter" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
        <Select v-model="warehouseFilter" :options="warehouses" optionLabel="label" optionValue="value" showClear
        placeholder="All warehouses" @change="reload" class="status-filter" />
        <TableExportButtons title="Gate Entries" endpoint="/gate-entries"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}), ...(directionFilter ? { direction: directionFilter } : {}), ...(warehouseFilter ? { warehouse_id: warehouseFilter } : {}) })"
        :columns="[
        { key: 'entry_number', label: 'Entry #' },
        { key: 'warehouse.name', label: 'Warehouse' },
        { key: 'direction', label: 'Direction' },
        { key: 'vehicle_number', label: 'Vehicle #' },
        { key: 'driver_name', label: 'Driver' },
        { key: 'reference_number', label: 'Reference' },
        { key: 'status', label: 'Status' },
        ]" />
        <Button v-if="canCreate" label="New Gate Entry" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="entry_number" header="Entry #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="Direction">
        <template #body="{ data }"><Tag severity="secondary" :value="directionLabel(data.direction)" /></template>
      </Column>
      <Column field="vehicle_number" header="Vehicle #" />
      <Column field="driver_name" header="Driver" />
      <Column header="Reference"><template #body="{ data }">{{ data.reference_number || '—' }}</template></Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="data.status === 'open'">
            <Button v-if="canEdit" label="Close" size="small" severity="success" outlined @click="closeEntry(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeEntry(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No gate entries logged yet.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Gate Entry' : 'New Gate Entry'" :style="{ width: '620px' }">
      <div class="form-top">
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Direction *</label>
          <Select v-model="form.direction" :options="directionOptions" optionLabel="label" optionValue="value" />
          <small v-if="errors.direction" class="err">{{ errors.direction[0] }}</small>
        </div>
        <div class="fld">
          <label>Vehicle Number *</label>
          <InputText v-model="form.vehicle_number" placeholder="e.g. MH12AB1234" />
          <small v-if="errors.vehicle_number" class="err">{{ errors.vehicle_number[0] }}</small>
        </div>
      </div>

      <div class="form-top">
        <div class="fld">
          <label>Driver Name</label>
          <InputText v-model="form.driver_name" />
        </div>
        <div class="fld">
          <label>Driver Contact</label>
          <InputText v-model="form.driver_contact" />
        </div>
        <div class="fld">
          <label>Reference Type</label>
          <Select v-model="form.reference_type" :options="referenceTypeOptions" optionLabel="label" optionValue="value" showClear placeholder="None" />
        </div>
      </div>

      <div class="form-top">
        <div class="fld">
          <label>Reference Number</label>
          <InputText v-model="form.reference_number" placeholder="e.g. GRN-000012" />
        </div>
      </div>

      <div class="fld full">
        <label>Purpose / Remarks</label>
        <Textarea v-model="form.purpose" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Log Entry'" :loading="saving" @click="saveEntry" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Gate Entry Details" :fields="[
      { key: 'entry_number', label: 'Entry #' },
      { key: 'warehouse.name', label: 'Warehouse' },
      { key: 'direction', label: 'Direction', format:(v)=>directionLabel(v) },
      { key: 'vehicle_number', label: 'Vehicle Number' },
      { key: 'driver_name', label: 'Driver Name' },
      { key: 'driver_contact', label: 'Driver Contact' },
      { key: 'reference_type', label: 'Reference Type' },
      { key: 'reference_number', label: 'Reference Number' },
      { key: 'status', label: 'Status' },
      { key: 'purpose', label: 'Purpose / Remarks', full: true },
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
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(input) { width: 100%; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
