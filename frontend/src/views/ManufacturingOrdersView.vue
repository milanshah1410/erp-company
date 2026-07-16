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
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import {
  MANUFACTURING_STAGES,
  STAGE_STATUS_COLORS,
  STAGE_STATUS_OPTIONS,
} from '../constants/manufacturingStages'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/manufacturing-orders')

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const employees = ref<any[]>([])
const boms = ref<any[]>([])
const loadingBom = ref(false)

const canCreate = computed(() => auth.can('manufacturing-orders.create'))
const canEdit = computed(() => auth.can('manufacturing-orders.edit'))
const canDelete = computed(() => auth.can('manufacturing-orders.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  in_progress: 'info',
  completed: 'success',
  cancelled: 'danger',
}
const statusLabels: Record<string, string> = {
  draft: 'Draft',
  in_progress: 'In Progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}
function money(v: number | string) {
  return formatCurrency(v)
}

// --- Create / edit dialog ---
const editVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blankForm())

// Named production-cost buckets besides raw material (which is auto-derived
// from the materials). Mirrors ManufacturingOrder::COST_BUCKETS on the backend.
const COST_BUCKETS = [
  { key: 'tailor_cost', label: 'Tailor Cost' },
  { key: 'printing_cost', label: 'Printing Cost' },
  { key: 'embroidery_cost', label: 'Embroidery Cost' },
  { key: 'packing_cost', label: 'Packing Cost' },
  { key: 'transport_cost', label: 'Transport' },
  { key: 'other_cost', label: 'Other Expenses' },
]

function blankForm() {
  return {
    id: null as number | null,
    product_id: null,
    bom_id: null as number | null,
    warehouse_id: null,
    quantity_to_produce: 1,
    order_date: today(),
    expected_completion_date: '',
    notes: '',
    materials: [] as any[],
    // default: every stage selected; user unchecks the ones that don't apply.
    stages: MANUFACTURING_STAGES.map((s) => s.key),
    tailor_cost: 0,
    printing_cost: 0,
    embroidery_cost: 0,
    packing_cost: 0,
    transport_cost: 0,
    other_cost: 0,
  }
}

const isEditing = computed(() => !!form.value.id)

const materialCost = computed(() =>
  form.value.materials.reduce(
    (sum: number, m: any) => sum + (Number(m.quantity) || 0) * (Number(m.unit_cost) || 0),
    0,
  ),
)

// Sum of the entered buckets — the labour/overhead added on top of raw material.
const bucketsCost = computed(() =>
  COST_BUCKETS.reduce((sum, b) => sum + (Number(form.value[b.key]) || 0), 0),
)
// Finished Product Cost = raw material + every bucket. Recomputed live.
const finishedCost = computed(() => materialCost.value + bucketsCost.value)
const finishedUnitCost = computed(() => {
  const qty = Number(form.value.quantity_to_produce) || 0
  return qty > 0 ? finishedCost.value / qty : 0
})

// Ordered rows for the detail costing breakdown (keys match backend costing.lines).
const COSTING_LINES = [{ key: 'raw_material', label: 'Raw Material' }, ...COST_BUCKETS]

function addMaterial() {
  form.value.materials.push({ product_id: null, quantity: 1, unit_cost: 0, remarks: '' })
}
function removeMaterial(i: number | string) {
  form.value.materials.splice(Number(i), 1)
}
function onMaterialProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) line.unit_cost = Number(p.cost_price) || 0
}

// Load the active BOMs for the chosen finished product so the user can seed
// the material lines from a saved recipe. Preselects the product's default BOM.
async function loadBomsForProduct(preselect = true) {
  form.value.bom_id = null
  boms.value = []
  if (!form.value.product_id) return
  try {
    const { data } = await http.get('/boms', {
      params: { product_id: form.value.product_id, is_active: 1, per_page: 100 },
    })
    boms.value = data.data ?? data
    if (preselect) {
      const def = boms.value.find((b) => b.is_default)
      if (def) form.value.bom_id = def.id
    }
  } catch {
    /* BOMs are optional — a load failure just leaves the picker empty. */
  }
}

function onFinishedProductPick() {
  loadBomsForProduct()
}

// Explode the selected BOM to the target quantity and replace the material
// lines with the scaled components.
async function applyBom() {
  if (!form.value.bom_id) return
  const qty = Number(form.value.quantity_to_produce) || 0
  if (qty <= 0) {
    toast.add({ severity: 'warn', summary: 'Set a quantity to produce first', life: 3000 })
    return
  }
  loadingBom.value = true
  try {
    const { data } = await http.get(`/boms/${form.value.bom_id}/explode`, { params: { quantity: qty } })
    form.value.materials = (data.data.materials || []).map((m: any) => ({
      product_id: m.product_id,
      quantity: Number(m.quantity),
      unit_cost: Number(m.unit_cost),
      remarks: m.remarks || '',
    }))
    toast.add({ severity: 'success', summary: 'Materials loaded from BOM', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Could not load BOM', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    loadingBom.value = false
  }
}
function toggleStage(key: string) {
  const i = form.value.stages.indexOf(key)
  if (i >= 0) form.value.stages.splice(i, 1)
  else form.value.stages.push(key)
}
function isStageSelected(key: string) {
  return form.value.stages.includes(key)
}

function openCreate() {
  form.value = blankForm()
  errors.value = {}
  boms.value = []
  editVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  try {
    const { data } = await http.get(`/manufacturing-orders/${row.id}`)
    const o = data.data
    form.value = {
      id: o.id,
      product_id: o.product_id,
      bom_id: o.bom_id ?? null,
      warehouse_id: o.warehouse_id,
      quantity_to_produce: Number(o.quantity_to_produce),
      order_date: o.order_date,
      expected_completion_date: o.expected_completion_date || '',
      notes: o.notes || '',
      materials: (o.materials || []).map((m: any) => ({
        product_id: m.product_id,
        quantity: Number(m.quantity),
        unit_cost: Number(m.unit_cost),
        remarks: m.remarks || '',
      })),
      stages: (o.stages || []).map((s: any) => s.stage_key),
      tailor_cost: Number(o.tailor_cost) || 0,
      printing_cost: Number(o.printing_cost) || 0,
      embroidery_cost: Number(o.embroidery_cost) || 0,
      packing_cost: Number(o.packing_cost) || 0,
      transport_cost: Number(o.transport_cost) || 0,
      other_cost: Number(o.other_cost) || 0,
    }
    // Load the product's BOMs for the picker without clobbering the saved bom_id.
    const savedBomId = o.bom_id ?? null
    await loadBomsForProduct(false)
    form.value.bom_id = savedBomId
    editVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load order', life: 3000 })
  }
}

async function saveOrder() {
  saving.value = true
  errors.value = {}
  const payload = {
    product_id: form.value.product_id,
    bom_id: form.value.bom_id,
    warehouse_id: form.value.warehouse_id,
    quantity_to_produce: form.value.quantity_to_produce,
    order_date: form.value.order_date,
    expected_completion_date: form.value.expected_completion_date || null,
    notes: form.value.notes,
    materials: form.value.materials,
    stages: form.value.stages,
    tailor_cost: form.value.tailor_cost || 0,
    printing_cost: form.value.printing_cost || 0,
    embroidery_cost: form.value.embroidery_cost || 0,
    packing_cost: form.value.packing_cost || 0,
    transport_cost: form.value.transport_cost || 0,
    other_cost: form.value.other_cost || 0,
  }
  try {
    if (isEditing.value) {
      await http.put(`/manufacturing-orders/${form.value.id}`, payload)
      toast.add({ severity: 'success', summary: 'Order updated', life: 2500 })
    } else {
      await http.post('/manufacturing-orders', payload)
      toast.add({ severity: 'success', summary: 'Manufacturing order created', life: 2500 })
    }
    editVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

// --- Workflow actions ---
async function startOrder(row: any) {
  const res = await Swal.fire({
    title: `Start ${row.mo_number}?`,
    text: 'Raw materials will be consumed from stock.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Start Production',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/manufacturing-orders/${row.id}/start`)
    toast.add({ severity: 'success', summary: 'Production started', life: 2500 })
    reload()
    if (detail.value?.id === row.id) openDetail(row)
  } catch (e: any) {
    const msg = e.response?.data?.errors?.materials?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Cannot start', detail: msg, life: 6000 })
  }
}

async function completeOrder(row: any) {
  const { value: qty } = await Swal.fire({
    title: `Complete ${row.mo_number}?`,
    input: 'text',
    inputLabel: 'Quantity produced',
    inputValue: String(row.quantity_to_produce),
    text: 'The finished product will be added to stock.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Complete',
  })
  if (qty === undefined) return
  try {
    await http.post(`/manufacturing-orders/${row.id}/complete`, { quantity_produced: Number(qty) })
    toast.add({ severity: 'success', summary: 'Order completed — stock received', life: 3000 })
    reload()
    if (detail.value?.id === row.id) closeDetail()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot complete', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function cancelOrder(row: any) {
  const res = await Swal.fire({
    title: `Cancel ${row.mo_number}?`,
    text: row.materials_consumed ? 'Consumed materials will be returned to stock.' : undefined,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Cancel Order',
    confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/manufacturing-orders/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Order cancelled', life: 2500 })
    reload()
    if (detail.value?.id === row.id) closeDetail()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot cancel', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function deleteOrder(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.mo_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/manufacturing-orders/${row.id}`)
    toast.add({ severity: 'success', summary: 'Order deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot delete', detail: e.response?.data?.message || 'Failed', life: 5000 })
  }
}

// --- Detail / stage dialog ---
const detailVisible = ref(false)
const detail = ref<any>(null)
const stageSaving = ref<number | null>(null)
const uploadingStage = ref<number | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
let pendingStageId: number | null = null

async function openDetail(row: any) {
  detailVisible.value = true
  detail.value = null
  try {
    const { data } = await http.get(`/manufacturing-orders/${row.id}`)
    detail.value = data.data
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load order', life: 3000 })
    detailVisible.value = false
  }
}
function closeDetail() {
  detailVisible.value = false
  detail.value = null
}

const stageEditable = computed(() => detail.value && detail.value.status !== 'cancelled')

async function saveStage(stage: any) {
  stageSaving.value = stage.id
  try {
    const { data } = await http.put(`/manufacturing-orders/${detail.value.id}/stages/${stage.id}`, {
      assigned_employee_id: stage.assigned_employee_id || null,
      start_date: stage.start_date || null,
      end_date: stage.end_date || null,
      status: stage.status,
      remarks: stage.remarks || null,
    })
    detail.value = data.data
    toast.add({ severity: 'success', summary: `${stage.name} saved`, life: 2000 })
    reload()
  } catch (e: any) {
    const msg = e.response?.data?.errors ? Object.values(e.response.data.errors)[0] : e.response?.data?.message
    toast.add({ severity: 'error', summary: 'Cannot save stage', detail: (msg as any) || 'Failed', life: 5000 })
  } finally {
    stageSaving.value = null
  }
}

function triggerUpload(stageId: number) {
  pendingStageId = stageId
  fileInput.value?.click()
}
async function onFileChosen(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || pendingStageId == null) return
  uploadingStage.value = pendingStageId
  const fd = new FormData()
  fd.append('image', file)
  try {
    await http.post(`/manufacturing-orders/${detail.value.id}/stages/${pendingStageId}/image`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    // Refresh the detail so the new image URL shows.
    const { data } = await http.get(`/manufacturing-orders/${detail.value.id}`)
    detail.value = data.data
    toast.add({ severity: 'success', summary: 'Image uploaded', life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Upload failed', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    uploadingStage.value = null
    pendingStageId = null
    input.value = ''
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
  const [p, w, e] = await Promise.all([
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/employees', { params: { per_page: 100, is_active: true } }),
  ])
  products.value = p.data.data ?? p.data
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Manufacturing Orders</h1>
      <div class="header-actions" style="display: flex; gap: 0.75rem; align-items: center">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="Manufacturing Orders" endpoint="/manufacturing-orders"
          :params="() => ({ status: statusFilter || undefined, search: search || undefined })"
          :columns="[
            { key: 'mo_number', label: 'MO #' },
            { key: 'product.name', label: 'Product' },
            { key: 'warehouse.name', label: 'Warehouse' },
            { key: 'quantity_to_produce', label: 'Qty' },
            { key: 'order_date', label: 'Date' },
            { key: 'total_cost', label: 'Total Cost' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button v-if="canCreate" label="New Manufacturing Order" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="mo_number" header="MO #" />
      <Column header="Product"><template #body="{ data }">{{ data.product?.name }}</template></Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="Qty"><template #body="{ data }">{{ Number(data.quantity_to_produce) }}</template></Column>
      <Column field="order_date" header="Date" />
      <Column header="Total Cost"><template #body="{ data }">{{ money(data.total_cost) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="statusLabels[data.status]" />
        </template>
      </Column>
      <Column header="Actions" style="width: 22rem">
        <template #body="{ data }">
          <Button label="Stages" icon="pi pi-list" size="small" outlined @click="openDetail(data)" />
          <Button v-if="canEdit && data.status === 'draft'" label="Edit" size="small" outlined @click="openEdit(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && data.status === 'draft'" label="Start" size="small" severity="info" @click="startOrder(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && data.status === 'in_progress'" label="Complete" size="small" severity="success" @click="completeOrder(data)" style="margin-left: 4px" />
          <Button v-if="canEdit && ['draft', 'in_progress'].includes(data.status)" label="Cancel" size="small" outlined severity="danger" @click="cancelOrder(data)" style="margin-left: 4px" />
          <Button v-if="canDelete && data.status === 'draft'" icon="pi pi-trash" size="small" text severity="danger" @click="deleteOrder(data)" style="margin-left: 4px" />
        </template>
      </Column>
      <template #empty>No manufacturing orders yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="editVisible" modal :header="isEditing ? 'Edit Manufacturing Order' : 'New Manufacturing Order'" :style="{ width: '860px' }">
      <div class="form-top">
        <div class="fld">
          <label>Finished Product *</label>
          <Select v-model="form.product_id" :options="products" optionLabel="name" optionValue="id" filter placeholder="Select product" @change="onFinishedProductPick" />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Select warehouse" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Quantity to Produce *</label>
          <InputNumber v-model="form.quantity_to_produce" :minFractionDigits="0" :maxFractionDigits="3" class="w-full" />
          <small v-if="errors.quantity_to_produce" class="err">{{ errors.quantity_to_produce[0] }}</small>
        </div>
        <div class="fld">
          <label>Order Date *</label>
          <input type="date" v-model="form.order_date" class="date-input" />
          <small v-if="errors.order_date" class="err">{{ errors.order_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Expected Completion</label>
          <input type="date" v-model="form.expected_completion_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">Raw Materials</h3>
      <div v-if="boms.length" class="bom-bar">
        <i class="pi pi-sitemap" />
        <span class="bom-label">Seed from BOM</span>
        <Select v-model="form.bom_id" :options="boms" optionValue="id" filter showClear placeholder="Select a bill of materials" class="bom-select">
          <template #option="{ option }">{{ option.bom_number }} — {{ option.name }}<span v-if="option.is_default" class="bom-default"> (default)</span></template>
          <template #value="{ value }">
            <template v-if="value">{{ boms.find((b) => b.id === value)?.bom_number }} — {{ boms.find((b) => b.id === value)?.name }}</template>
            <template v-else>Select a bill of materials</template>
          </template>
        </Select>
        <Button label="Load Materials" icon="pi pi-download" size="small" outlined :loading="loadingBom" :disabled="!form.bom_id" @click="applyBom" />
      </div>
      <table class="lines">
        <thead>
          <tr><th style="width: 40%">Material</th><th>Qty</th><th>Unit Cost</th><th>Line Cost</th><th>Remarks</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(m, i) in form.materials" :key="i">
            <td>
              <Select v-model="m.product_id" :options="products" optionLabel="name" optionValue="id" filter placeholder="Material" class="w-full" @change="onMaterialProductPick(m)" />
            </td>
            <td><InputNumber v-model="m.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="m.unit_cost" :minFractionDigits="2" :maxFractionDigits="4" class="num" /></td>
            <td class="line-total">{{ money((m.quantity || 0) * (m.unit_cost || 0)) }}</td>
            <td><input v-model="m.remarks" class="text-input" placeholder="—" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeMaterial(i)" /></td>
          </tr>
          <tr v-if="!form.materials.length"><td colspan="6" class="muted" style="text-align: center; padding: 0.75rem">No materials — add the raw materials this order consumes.</td></tr>
        </tbody>
      </table>
      <Button label="Add Material" icon="pi pi-plus" text size="small" @click="addMaterial" />

      <h3 class="lines-title">Production Costing</h3>
      <small class="muted block" style="margin-bottom: 0.5rem">Raw material is totalled from the lines above; enter the rest. Finished Product Cost updates automatically.</small>
      <div class="costing">
        <div class="cost-row">
          <span class="cost-label">Raw Material</span>
          <span class="cost-auto">{{ money(materialCost) }}<em>auto</em></span>
        </div>
        <div class="cost-row" v-for="b in COST_BUCKETS" :key="b.key">
          <span class="cost-label">{{ b.label }}</span>
          <InputNumber v-model="form[b.key]" :min="0" :minFractionDigits="2" :maxFractionDigits="2" class="cost-input" />
        </div>
        <div class="cost-row total">
          <span class="cost-label">Finished Product Cost</span>
          <span class="cost-total">{{ money(finishedCost) }}</span>
        </div>
        <div class="cost-row per-unit">
          <span class="cost-label">Per Unit ({{ Number(form.quantity_to_produce) || 0 }})</span>
          <span>{{ money(finishedUnitCost) }}</span>
        </div>
      </div>

      <h3 class="lines-title">Production Stages</h3>
      <small class="muted block" style="margin-bottom: 0.5rem">Untick the stages that don't apply to this order.</small>
      <div class="stage-picker">
        <label v-for="s in MANUFACTURING_STAGES" :key="s.key" class="stage-check">
          <Checkbox :modelValue="isStageSelected(s.key)" :binary="true" @update:modelValue="toggleStage(s.key)" />
          <span>{{ s.name }}</span>
        </label>
      </div>
      <small v-if="errors.stages" class="err block">{{ errors.stages[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="editVisible = false" />
        <Button :label="isEditing ? 'Save Changes' : 'Create Order'" :loading="saving" @click="saveOrder" />
      </template>
    </Dialog>

    <!-- Detail / stage tracking dialog -->
    <Dialog v-model:visible="detailVisible" modal :header="detail ? detail.mo_number : 'Manufacturing Order'" :style="{ width: '960px' }">
      <div v-if="!detail" class="muted">Loading…</div>
      <template v-else>
        <div class="detail-head">
          <div class="dh-cell"><span class="dh-label">Product</span><span class="dh-value">{{ detail.product?.name }}</span></div>
          <div class="dh-cell"><span class="dh-label">Warehouse</span><span class="dh-value">{{ detail.warehouse?.name }}</span></div>
          <div class="dh-cell"><span class="dh-label">Qty to Produce</span><span class="dh-value">{{ Number(detail.quantity_to_produce) }}</span></div>
          <div class="dh-cell"><span class="dh-label">Status</span><Tag :severity="statusColors[detail.status]" :value="statusLabels[detail.status]" /></div>
          <div class="dh-cell"><span class="dh-label">Finished Product Cost</span><span class="dh-value strong">{{ money(detail.total_cost) }}</span></div>
          <div class="dh-cell"><span class="dh-label">Per Unit</span><span class="dh-value">{{ money(detail.unit_production_cost) }}</span></div>
        </div>

        <h3 class="lines-title">Production Costing</h3>
        <table class="lines costing-table">
          <thead>
            <tr><th style="width: 50%">Cost Component</th><th class="ta-right">Total</th><th class="ta-right">Per Unit</th></tr>
          </thead>
          <tbody>
            <tr v-for="l in COSTING_LINES" :key="l.key">
              <td>{{ l.label }}</td>
              <td class="ta-right">{{ money(detail.costing?.lines?.[l.key]?.total ?? 0) }}</td>
              <td class="ta-right muted">{{ money(detail.costing?.lines?.[l.key]?.per_unit ?? 0) }}</td>
            </tr>
            <tr class="grand-row">
              <td>Finished Product Cost ({{ Number(detail.costing?.quantity) || 0 }} unit)</td>
              <td class="ta-right">{{ money(detail.costing?.total ?? detail.total_cost) }}</td>
              <td class="ta-right">{{ money(detail.costing?.per_unit_total ?? detail.unit_production_cost) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="detail-actions">
          <Button v-if="canEdit && detail.status === 'draft'" label="Start Production" icon="pi pi-play" size="small" severity="info" @click="startOrder(detail)" />
          <Button v-if="canEdit && detail.status === 'in_progress'" label="Complete" icon="pi pi-check" size="small" severity="success" @click="completeOrder(detail)" />
          <Button v-if="canEdit && ['draft', 'in_progress'].includes(detail.status)" label="Cancel Order" size="small" outlined severity="danger" @click="cancelOrder(detail)" />
        </div>

        <div v-if="detail.materials?.length" class="materials-summary">
          <h3 class="lines-title">Materials</h3>
          <table class="lines">
            <thead><tr><th>Material</th><th>Qty</th><th>Unit Cost</th><th>Line Cost</th><th>Consumed</th></tr></thead>
            <tbody>
              <tr v-for="m in detail.materials" :key="m.id">
                <td>{{ m.product?.name }}</td>
                <td>{{ Number(m.quantity) }}</td>
                <td>{{ money(m.unit_cost) }}</td>
                <td>{{ money(m.total_cost) }}</td>
                <td>{{ Number(m.consumed_quantity) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="lines-title">Stages</h3>
        <div class="stages">
          <div v-for="stage in detail.stages" :key="stage.id" class="stage-card">
            <div class="stage-top">
              <span class="stage-seq">{{ stage.sequence }}</span>
              <span class="stage-name">{{ stage.name }}</span>
              <Tag :severity="STAGE_STATUS_COLORS[stage.status]" :value="stage.status.replace('_', ' ')" class="stage-status" />
            </div>
            <div class="stage-grid">
              <div class="fld">
                <label>Employee</label>
                <Select v-model="stage.assigned_employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear placeholder="Unassigned" :disabled="!stageEditable" />
              </div>
              <div class="fld">
                <label>Status</label>
                <Select v-model="stage.status" :options="STAGE_STATUS_OPTIONS" optionLabel="label" optionValue="value" :disabled="!stageEditable" />
              </div>
              <div class="fld">
                <label>Start Date</label>
                <input type="date" v-model="stage.start_date" class="date-input" :disabled="!stageEditable" />
              </div>
              <div class="fld">
                <label>End Date</label>
                <input type="date" v-model="stage.end_date" class="date-input" :disabled="!stageEditable" />
              </div>
              <div class="fld">
                <label>Image</label>
                <div class="stage-image">
                  <a v-if="stage.image_url" :href="stage.image_url" target="_blank"><img :src="stage.image_url" class="thumb" /></a>
                  <span v-else class="muted no-img">No image</span>
                  <Button v-if="stageEditable" :icon="uploadingStage === stage.id ? 'pi pi-spin pi-spinner' : 'pi pi-upload'" size="small" text @click="triggerUpload(stage.id)" />
                </div>
              </div>
            </div>
            <div class="fld full">
              <label>Remarks</label>
              <Textarea v-model="stage.remarks" rows="1" :disabled="!stageEditable" />
            </div>
            <div class="stage-save" v-if="stageEditable">
              <Button label="Save Stage" size="small" :loading="stageSaving === stage.id" @click="saveStage(stage)" />
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <Button label="Close" text @click="closeDetail" />
      </template>
    </Dialog>

    <!-- Hidden file input for stage image uploads -->
    <input ref="fileInput" type="file" accept="image/*" style="display: none" @change="onFileChosen" />
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
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.text-input { padding: 0.45rem 0.55rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; width: 100%; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.grand { text-align: right; margin-top: 0.75rem; font-size: 1.05rem; }

/* Production costing (create/edit dialog) */
.costing { max-width: 460px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
.cost-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.45rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
.cost-row:last-child { border-bottom: none; }
.cost-label { font-size: 0.88rem; color: #374151; }
.cost-input :deep(input) { width: 140px; text-align: right; }
.cost-auto { font-size: 0.9rem; font-weight: 600; }
.cost-auto em { color: #9ca3af; font-size: 0.7rem; font-style: normal; margin-left: 0.4rem; text-transform: uppercase; letter-spacing: 0.03em; }
.cost-row.total { background: #f8fafc; font-weight: 700; }
.cost-total { font-size: 1.05rem; font-weight: 700; }
.cost-row.per-unit { background: #f8fafc; color: #6b7280; font-size: 0.85rem; }

/* Production costing (detail view) */
.costing-table .ta-right { text-align: right; }
.costing-table .grand-row td { border-top: 2px solid #e5e7eb; font-weight: 700; padding-top: 0.55rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.muted { color: #6b7280; }
.muted.block { display: block; }
.w-full :deep(input) { width: 100%; }

.bom-bar { display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.75rem; margin-bottom: 0.6rem; background: #eef2ff; border: 1px solid #e0e7ff; border-radius: 8px; }
.bom-bar .pi-sitemap { color: #4338ca; }
.bom-label { font-size: 0.85rem; font-weight: 600; color: #3730a3; }
.bom-select { flex: 1; }
.bom-default { color: #6b7280; font-size: 0.8rem; }

.stage-picker { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem 1rem; }
.stage-check { display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; cursor: pointer; }

.detail-head { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; padding: 0.85rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.dh-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.dh-label { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.dh-value { font-weight: 600; font-size: 0.92rem; }
.dh-value.strong { color: #1e2328; font-size: 1rem; }
.detail-actions { display: flex; gap: 0.5rem; margin: 1rem 0; }

.stages { display: flex; flex-direction: column; gap: 0.85rem; }
.stage-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.85rem 1rem; background: #fff; }
.stage-top { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.6rem; }
.stage-seq { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: #eef2ff; color: #4338ca; font-size: 0.78rem; font-weight: 700; }
.stage-name { font-weight: 600; font-size: 0.95rem; flex: 1; }
.stage-status { text-transform: capitalize; }
.stage-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.stage-image { display: flex; align-items: center; gap: 0.5rem; }
.thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid #e5e7eb; }
.no-img { font-size: 0.8rem; }
.stage-save { display: flex; justify-content: flex-end; margin-top: 0.6rem; }
</style>
