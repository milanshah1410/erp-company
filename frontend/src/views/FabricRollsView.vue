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
import Checkbox from 'primevue/checkbox'
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
const crud = useCrud('/fabric-rolls')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const suppliers = ref<any[]>([])

const canCreate = computed(() => auth.can('textile.create'))
const canEdit = computed(() => auth.can('textile.edit'))
const canDelete = computed(() => auth.can('textile.delete'))

const statusColors: Record<string, string> = {
  available: 'success',
  partial: 'warn',
  consumed: 'secondary',
  quarantined: 'danger',
  scrapped: 'contrast',
}
const statusOptions = [
  { label: 'Available', value: 'available' },
  { label: 'Partial', value: 'partial' },
  { label: 'Consumed', value: 'consumed' },
  { label: 'Quarantined', value: 'quarantined' },
  { label: 'Scrapped', value: 'scrapped' },
]
const gradeColors: Record<string, string> = { A: 'success', B: 'warn', C: 'danger' }

// --- Tabs ---
const tab = ref<'rolls' | 'leftovers' | 'summary'>('rolls')
const tabs = [
  { key: 'rolls', label: 'Fabric Rolls', icon: 'pi pi-th-large' },
  { key: 'leftovers', label: 'Leftover Fabric', icon: 'pi pi-clone' },
  { key: 'summary', label: 'Stock Summary', icon: 'pi pi-chart-bar' },
] as const

const summaryRows = ref<any[]>([])
const summaryLoading = ref(false)
async function loadSummary() {
  summaryLoading.value = true
  try {
    const { data } = await http.get('/fabric-rolls/stock-summary')
    summaryRows.value = data.data ?? data
  } finally {
    summaryLoading.value = false
  }
}

const statusFilter = ref<string | null>(null)
const search = ref('')
function switchTab(key: typeof tab.value) {
  tab.value = key
  if (key === 'summary') loadSummary()
  else reload()
}

function reload() {
  const params: Record<string, any> = {}
  if (tab.value === 'leftovers') params.leftover = 1
  if (statusFilter.value) params.status = statusFilter.value
  if (search.value) params.search = search.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(emptyForm())
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}
function emptyForm() {
  return {
    product_id: null, variant_id: null, warehouse_id: null, supplier_id: null,
    shade_no: '', dye_lot: '', gsm: null, width: null, width_unit: 'inch',
    length_unit: 'meter', initial_length: null, unit_cost: 0,
    received_date: today(), post_to_stock: true, notes: '',
  }
}

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/fabric-rolls/${row.id}`)
    const r = data.data
    editingId.value = r.id
    form.value = {
      product_id: r.product_id, variant_id: r.variant_id, warehouse_id: r.warehouse_id,
      supplier_id: r.supplier_id, shade_no: r.shade_no || '', dye_lot: r.dye_lot || '',
      gsm: r.gsm != null ? Number(r.gsm) : null, width: r.width != null ? Number(r.width) : null,
      width_unit: r.width_unit || 'inch', length_unit: r.length_unit || 'meter',
      initial_length: Number(r.initial_length), unit_cost: Number(r.unit_cost),
      received_date: r.received_date, post_to_stock: r.posted_to_stock, notes: r.notes || '',
      _lengthLocked: r.posted_to_stock || (r.cuttings_count ?? 0) > 0,
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load roll', life: 3000 })
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/fabric-rolls/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Roll updated', life: 2500 })
    } else {
      await http.post('/fabric-rolls', form.value)
      toast.add({ severity: 'success', summary: 'Fabric roll added', life: 2500 })
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

async function changeStatus(row: any, status: string, verb: string) {
  const res = await Swal.fire({
    title: `${verb} ${row.roll_number}?`,
    icon: status === 'available' ? 'question' : 'warning',
    showCancelButton: true,
    confirmButtonText: verb,
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/fabric-rolls/${row.id}/status`, { status })
    toast.add({ severity: 'success', summary: `Roll ${verb.toLowerCase()}d`, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeRow(row: any) {
  const res = await Swal.fire({
    title: `Delete ${row.roll_number}?`,
    text: row.posted_to_stock ? 'Its remaining length will be removed from stock.' : undefined,
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/fabric-rolls/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

onMounted(async () => {
  const [p, w, s] = await Promise.all([
    http.get('/products', { params: { per_page: 200 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/suppliers', { params: { per_page: 200 } }),
  ])
  products.value = p.data.data ?? p.data
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Fabric Rolls</h1>
      <div class="header-actions">
        <span v-if="tab !== 'summary'" class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button v-if="tab !== 'summary'" label="Search" outlined @click="reload" />
        <Select v-if="tab !== 'summary'" v-model="statusFilter" :options="statusOptions" optionLabel="label"
          optionValue="value" showClear placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons v-if="tab !== 'summary'" title="Fabric Rolls" endpoint="/fabric-rolls"
          :params="() => ({ leftover: tab === 'leftovers' ? 1 : undefined, status: statusFilter || undefined, search: search || undefined })" :columns="[
          { key: 'roll_number', label: 'Roll #' },
          { key: 'product.name', label: 'Fabric' },
          { key: 'shade_no', label: 'Shade No.' },
          { key: 'dye_lot', label: 'Dye Lot' },
          { key: 'gsm', label: 'GSM' },
          { key: 'width', label: 'Width' },
          { key: 'current_length', label: 'Length' },
          { key: 'grade', label: 'Grade' },
          { key: 'status', label: 'Status' },
        ]" />
        <Button v-if="canCreate" label="Add Roll" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <div class="tabbar">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- Rolls / Leftovers -->
    <DataTable v-if="tab !== 'summary'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="roll_number" header="Roll #" />
      <Column header="Fabric">
        <template #body="{ data }">
          <div>{{ data.product?.name }}</div>
          <small v-if="data.is_leftover" class="muted">leftover of {{ data.parent_roll }}</small>
        </template>
      </Column>
      <Column header="Shade / Dye Lot">
        <template #body="{ data }">
          <span>{{ data.shade_no || '—' }}</span>
          <small class="muted"> / {{ data.dye_lot || '—' }}</small>
        </template>
      </Column>
      <Column header="GSM" style="text-align:right">
        <template #body="{ data }">{{ data.gsm != null ? Number(data.gsm) : '—' }}</template>
      </Column>
      <Column header="Width">
        <template #body="{ data }">{{ data.width != null ? `${Number(data.width)} ${data.width_unit}` : '—' }}</template>
      </Column>
      <Column header="Length" style="text-align:right">
        <template #body="{ data }">
          <strong>{{ Number(data.current_length) }}</strong>
          <small class="muted"> / {{ Number(data.initial_length) }} {{ data.length_unit }}</small>
        </template>
      </Column>
      <Column header="Grade">
        <template #body="{ data }"><Tag v-if="data.grade" :severity="gradeColors[data.grade] || 'secondary'" :value="data.grade" /><span v-else>—</span></template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Actions" style="width: 15rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" title="Edit" />
          <Button v-if="canEdit && data.status !== 'quarantined' && data.status !== 'scrapped'" icon="pi pi-exclamation-triangle" size="small" text severity="warn" title="Quarantine" @click="changeStatus(data, 'quarantined', 'Quarantine')" />
          <Button v-if="canEdit && (data.status === 'quarantined' || data.status === 'scrapped')" icon="pi pi-check" size="small" text severity="success" title="Reinstate" @click="changeStatus(data, 'available', 'Reinstate')" />
          <Button v-if="canEdit && data.status !== 'scrapped'" icon="pi pi-ban" size="small" text severity="danger" title="Scrap" @click="changeStatus(data, 'scrapped', 'Scrap')" />
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" title="Delete" @click="removeRow(data)" />
        </template>
      </Column>
      <template #empty>No fabric rolls yet.</template>
    </DataTable>

    <!-- Stock summary -->
    <DataTable v-else :value="summaryRows" :loading="summaryLoading" dataKey="product_id" class="p-datatable-sm" paginator :rows="20">
      <Column header="Fabric">
        <template #body="{ data }">{{ data.product?.name }} <small class="muted">{{ data.product?.sku }}</small></template>
      </Column>
      <Column field="roll_count" header="Rolls" style="text-align:right" />
      <Column header="Total Length" style="text-align:right">
        <template #body="{ data }"><strong>{{ Number(data.total_length) }}</strong></template>
      </Column>
      <Column field="available_rolls" header="Available" style="text-align:right" />
      <Column field="partial_rolls" header="Partial" style="text-align:right" />
      <Column header="Quarantined" style="text-align:right">
        <template #body="{ data }"><span :class="{ dmg: data.quarantined_rolls > 0 }">{{ data.quarantined_rolls }}</span></template>
      </Column>
      <Column header="Leftover Length" style="text-align:right">
        <template #body="{ data }">{{ Number(data.leftover_length) }}</template>
      </Column>
      <template #empty>No fabric in stock.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Fabric Roll' : 'Add Fabric Roll'" :style="{ width: '760px' }">
      <div class="form-grid">
        <div class="fld span2">
          <label>Fabric Product *</label>
          <Select v-model="form.product_id" :options="products" optionLabel="name" optionValue="id" filter placeholder="Select fabric" />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Supplier</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter showClear placeholder="Optional" />
        </div>
        <div class="fld">
          <label>Shade No.</label>
          <InputText v-model="form.shade_no" placeholder="e.g. SH-204" />
        </div>
        <div class="fld">
          <label>Dye Lot</label>
          <InputText v-model="form.dye_lot" placeholder="e.g. DL-1187" />
        </div>
        <div class="fld">
          <label>GSM</label>
          <InputNumber v-model="form.gsm" :minFractionDigits="0" :maxFractionDigits="2" placeholder="grams/m²" />
        </div>
        <div class="fld">
          <label>Width</label>
          <div class="inline">
            <InputNumber v-model="form.width" :minFractionDigits="0" :maxFractionDigits="2" class="grow" />
            <Select v-model="form.width_unit" :options="[{label:'inch',value:'inch'},{label:'cm',value:'cm'}]" optionLabel="label" optionValue="value" class="unit" />
          </div>
        </div>
        <div class="fld">
          <label>Roll Length *</label>
          <div class="inline">
            <InputNumber v-model="form.initial_length" :minFractionDigits="0" :maxFractionDigits="3" class="grow" :disabled="form._lengthLocked" />
            <Select v-model="form.length_unit" :options="[{label:'meter',value:'meter'},{label:'yard',value:'yard'}]" optionLabel="label" optionValue="value" class="unit" :disabled="form._lengthLocked" />
          </div>
          <small v-if="form._lengthLocked" class="muted">Length is locked once stock is posted or the roll is cut.</small>
          <small v-if="errors.initial_length" class="err">{{ errors.initial_length[0] }}</small>
        </div>
        <div class="fld">
          <label>Unit Cost (per {{ form.length_unit }})</label>
          <InputNumber v-model="form.unit_cost" mode="currency" currency="INR" locale="en-IN" :maxFractionDigits="4" />
        </div>
        <div class="fld">
          <label>Received Date</label>
          <input type="date" v-model="form.received_date" class="date-input" />
        </div>
      </div>

      <div v-if="!editingId" class="stock-opt">
        <Checkbox v-model="form.post_to_stock" :binary="true" inputId="pts" />
        <label for="pts">Add this roll's length to inventory stock (recommended if not already received via GRN)</label>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Add Roll'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="Fabric Roll" :record="detailRecord" :fields="[
      { key: 'roll_number', label: 'Roll #' },
      { key: 'product.name', label: 'Fabric' },
      { key: 'shade_no', label: 'Shade No.' },
      { key: 'dye_lot', label: 'Dye Lot' },
      { key: 'gsm', label: 'GSM', format: (v) => v == null ? '—' : String(Number(v)) },
      { key: 'width', label: 'Width', format: (v, row) => v == null ? '—' : `${Number(v)} ${row.width_unit}` },
      { key: 'current_length', label: 'Current Length', format: (v, row) => v == null ? '—' : `${Number(v)} ${row.length_unit}` },
      { key: 'initial_length', label: 'Initial Length', format: (v, row) => v == null ? '—' : `${Number(v)} ${row.length_unit}` },
      { key: 'grade', label: 'Grade' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 170px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.tabbar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.tab { background: none; border: none; padding: 0.6rem 1rem; font: inherit; font-size: 0.9rem; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; display: flex; align-items: center; gap: 0.4rem; }
.tab:hover { color: #111827; }
.tab.active { color: var(--p-primary-color, #6366f1); border-bottom-color: var(--p-primary-color, #6366f1); font-weight: 600; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.span2 { grid-column: span 2; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.inline { display: flex; gap: 0.4rem; }
.inline .grow { flex: 1; }
.inline .unit { width: 90px; flex: none; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.stock-opt { display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; font-size: 0.78rem; }
.dmg { color: #dc2626; font-weight: 600; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
