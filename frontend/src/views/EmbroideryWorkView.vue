<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

// 'pending' → open dispatches (Pending Dispatch screen); 'completed' → received.
const mode = computed(() => (route.meta.workMode as string) || 'pending')
const isPending = computed(() => mode.value === 'pending')

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')

const vendors = ref<{ label: string; value: number }[]>([])
const products = ref<{ label: string; value: number }[]>([])
const warehouses = ref<{ label: string; value: number }[]>([])

const canCreate = computed(() => auth.can('embroidery-works.create'))
const canEdit = computed(() => auth.can('embroidery-works.edit'))
const canDelete = computed(() => auth.can('embroidery-works.delete'))

// --- Dispatch dialog ---
const dispatchVisible = ref(false)
const savingDispatch = ref(false)
const editingId = ref<number | null>(null)
const dispatchErrors = ref<Record<string, string[]>>({})
const dispatchForm = ref<any>({
  embroidery_vendor_id: null, product_id: null, design_number: '', stitch_count: null,
  dispatched_qty: null, cost: null, due_date: null, notes: '',
})

// --- Receive dialog ---
const receiveVisible = ref(false)
const savingReceive = ref(false)
const receiveErrors = ref<Record<string, string[]>>({})
const receiveTarget = ref<any>(null)
const receiveForm = ref<any>({ qty: null, received_at: new Date(), received_to_stock: false, warehouse_id: null, notes: '' })

// --- Detail (read-only view) dialog ---
const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function money(v: any) {
  return formatCurrency(v)
}

function statusSeverity(status: string) {
  return { pending: 'warn', partial: 'info', completed: 'success', cancelled: 'danger' }[status] || 'secondary'
}

function fieldError(errs: Record<string, string[]>, key: string): string | null {
  return errs[key]?.[0] ?? null
}

function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

async function loadOptions() {
  const [v, p, w] = await Promise.all([
    http.get('/embroidery-vendors', { params: { per_page: 100, is_active: true } }),
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  vendors.value = (v.data.data ?? v.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.sku} — ${x.name}`, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/embroidery-works', {
      params: {
        status: mode.value,
        search: search.value || undefined,
        page: page.value,
        per_page: perPage.value,
      },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function openDispatch() {
  editingId.value = null
  dispatchForm.value = {
    embroidery_vendor_id: null, product_id: null, design_number: '', stitch_count: null,
    dispatched_qty: null, cost: null, due_date: null, notes: '',
  }
  dispatchErrors.value = {}
  dispatchVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  dispatchForm.value = {
    embroidery_vendor_id: row.embroidery_vendor_id,
    product_id: row.product_id,
    design_number: row.design_number || '',
    stitch_count: row.stitch_count != null ? Number(row.stitch_count) : null,
    dispatched_qty: Number(row.dispatched_qty),
    cost: Number(row.cost),
    due_date: row.due_date ? new Date(row.due_date) : null,
    notes: row.notes || '',
  }
  dispatchErrors.value = {}
  dispatchVisible.value = true
}

async function saveDispatch() {
  savingDispatch.value = true
  dispatchErrors.value = {}
  try {
    const payload = {
      embroidery_vendor_id: dispatchForm.value.embroidery_vendor_id,
      product_id: dispatchForm.value.product_id,
      design_number: dispatchForm.value.design_number,
      stitch_count: dispatchForm.value.stitch_count ?? undefined,
      dispatched_qty: dispatchForm.value.dispatched_qty,
      cost: dispatchForm.value.cost,
      due_date: toDateString(dispatchForm.value.due_date),
      notes: dispatchForm.value.notes || undefined,
    }
    if (editingId.value) {
      await http.put(`/embroidery-works/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Dispatch updated', life: 2500 })
    } else {
      await http.post('/embroidery-works', payload)
      toast.add({ severity: 'success', summary: 'Dispatch created', life: 2500 })
    }
    dispatchVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) dispatchErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    savingDispatch.value = false
  }
}

function openReceive(row: any) {
  receiveTarget.value = row
  receiveForm.value = {
    qty: Number(row.pending_qty),
    received_at: new Date(),
    received_to_stock: false,
    warehouse_id: null,
    notes: '',
  }
  receiveErrors.value = {}
  receiveVisible.value = true
}

async function saveReceive() {
  if (!receiveTarget.value) return
  savingReceive.value = true
  receiveErrors.value = {}
  try {
    const payload = {
      qty: receiveForm.value.qty,
      received_at: toDateString(receiveForm.value.received_at),
      received_to_stock: receiveForm.value.received_to_stock,
      warehouse_id: receiveForm.value.received_to_stock ? receiveForm.value.warehouse_id : undefined,
      notes: receiveForm.value.notes || undefined,
    }
    await http.post(`/embroidery-works/${receiveTarget.value.id}/receive`, payload)
    toast.add({ severity: 'success', summary: 'Receipt recorded', life: 2500 })
    receiveVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) receiveErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    savingReceive.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this dispatch?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/embroidery-works/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

watch(mode, () => {
  page.value = 1
  search.value = ''
  load()
})

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>{{ isPending ? 'Pending Dispatch' : 'Received' }}</h1>
      <Button v-if="isPending && canCreate" label="New Dispatch" icon="pi pi-plus" @click="openDispatch" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search dispatch #, design, vendor, product..." @keyup.enter="load" />
      </span>
      <Button label="Search" outlined @click="load" />
      <TableExportButtons :title="isPending ? 'Pending Dispatch' : 'Received'" endpoint="/embroidery-works"
        :params="() => ({ status: mode, search: search || undefined })" :columns="[
        { key: 'work_number', label: 'Dispatch #' },
        { key: 'vendor_name', label: 'Vendor' },
        { key: 'product_name', label: 'Product' },
        { key: 'design_number', label: 'Design #' },
        { key: 'stitch_count', label: 'Stitches' },
        { key: 'cost', label: 'Cost' },
        { key: 'dispatched_qty', label: 'Dispatched' },
        { key: 'received_qty', label: 'Received' },
        { key: 'pending_qty', label: 'Pending' },
        { key: 'due_date', label: 'Due' },
        { key: 'status', label: 'Status' },
      ]" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="work_number" header="Dispatch #" />
      <Column field="vendor_name" header="Vendor" />
      <Column field="product_name" header="Product" />
      <Column field="design_number" header="Design #" />
      <Column header="Stitches">
        <template #body="{ data }">{{ data.stitch_count != null ? Number(data.stitch_count).toLocaleString() : '—' }}</template>
      </Column>
      <Column header="Cost">
        <template #body="{ data }">{{ money(data.cost) }}</template>
      </Column>
      <Column header="Dispatched">
        <template #body="{ data }">{{ Number(data.dispatched_qty) }}</template>
      </Column>
      <Column header="Received">
        <template #body="{ data }">{{ Number(data.received_qty) }}</template>
      </Column>
      <Column v-if="isPending" header="Pending">
        <template #body="{ data }">{{ Number(data.pending_qty) }}</template>
      </Column>
      <Column header="Value">
        <template #body="{ data }">{{ money(isPending ? data.amount : data.earned) }}</template>
      </Column>
      <Column field="due_date" header="Due" />
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="isPending">
            <Button v-if="canEdit" label="Receive" icon="pi pi-check" text size="small" @click="openReceive(data)" />
            <Button v-if="canEdit && Number(data.received_qty) === 0" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
            <Button v-if="canDelete && Number(data.received_qty) === 0" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <template #empty>{{ isPending ? 'No pending dispatches.' : 'Nothing received yet.' }}</template>
    </DataTable>

    <!-- Create / edit dispatch -->
    <Dialog v-model:visible="dispatchVisible" modal :header="editingId ? 'Edit Dispatch' : 'New Dispatch'" :style="{ width: '560px' }">
      <div class="form">
        <div class="field">
          <label>Embroidery Vendor <span class="req">*</span></label>
          <Select v-model="dispatchForm.embroidery_vendor_id" :options="vendors" optionLabel="label" optionValue="value" filter
            placeholder="Select vendor" :class="{ invalid: fieldError(dispatchErrors, 'embroidery_vendor_id') }" />
          <small v-if="fieldError(dispatchErrors, 'embroidery_vendor_id')" class="error-text">{{ fieldError(dispatchErrors, 'embroidery_vendor_id') }}</small>
        </div>
        <div class="field">
          <label>Product <span class="req">*</span></label>
          <Select v-model="dispatchForm.product_id" :options="products" optionLabel="label" optionValue="value" filter
            placeholder="Select product" :class="{ invalid: fieldError(dispatchErrors, 'product_id') }" />
          <small v-if="fieldError(dispatchErrors, 'product_id')" class="error-text">{{ fieldError(dispatchErrors, 'product_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Design Number <span class="req">*</span></label>
            <InputText v-model="dispatchForm.design_number" :class="{ invalid: fieldError(dispatchErrors, 'design_number') }" />
            <small v-if="fieldError(dispatchErrors, 'design_number')" class="error-text">{{ fieldError(dispatchErrors, 'design_number') }}</small>
          </div>
          <div class="field">
            <label>Stitch Count</label>
            <InputNumber v-model="dispatchForm.stitch_count" :min="0" :useGrouping="true" />
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>Dispatched Qty <span class="req">*</span></label>
            <InputNumber v-model="dispatchForm.dispatched_qty" :min="0" :class="{ invalid: fieldError(dispatchErrors, 'dispatched_qty') }" />
            <small v-if="fieldError(dispatchErrors, 'dispatched_qty')" class="error-text">{{ fieldError(dispatchErrors, 'dispatched_qty') }}</small>
          </div>
          <div class="field">
            <label>Cost / piece <span class="req">*</span></label>
            <InputNumber v-model="dispatchForm.cost" mode="currency" currency="INR" locale="en-IN" :min="0"
              :class="{ invalid: fieldError(dispatchErrors, 'cost') }" />
            <small v-if="fieldError(dispatchErrors, 'cost')" class="error-text">{{ fieldError(dispatchErrors, 'cost') }}</small>
          </div>
        </div>
        <div class="field">
          <label>Due Date</label>
          <DatePicker v-model="dispatchForm.due_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="dispatchForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dispatchVisible = false" />
        <Button :label="editingId ? 'Update' : 'Dispatch'" :loading="savingDispatch" @click="saveDispatch" />
      </template>
    </Dialog>

    <!-- Receive -->
    <Dialog v-model:visible="receiveVisible" modal header="Record Receipt" :style="{ width: '460px' }">
      <div v-if="receiveTarget" class="form">
        <p class="ctx">
          {{ receiveTarget.work_number }} · {{ receiveTarget.vendor_name }} · Design {{ receiveTarget.design_number }}
          <br /><small>Pending: {{ Number(receiveTarget.pending_qty) }} @ {{ money(receiveTarget.cost) }}/pc</small>
        </p>
        <div class="field">
          <label>Received Qty <span class="req">*</span></label>
          <InputNumber v-model="receiveForm.qty" :min="0" :max="Number(receiveTarget.pending_qty)"
            :class="{ invalid: fieldError(receiveErrors, 'qty') }" />
          <small v-if="fieldError(receiveErrors, 'qty')" class="error-text">{{ fieldError(receiveErrors, 'qty') }}</small>
        </div>
        <div class="field">
          <label>Receipt Date</label>
          <DatePicker v-model="receiveForm.received_at" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field checkbox-row">
          <Checkbox v-model="receiveForm.received_to_stock" binary inputId="rts" />
          <label for="rts">Receive embroidered goods into stock</label>
        </div>
        <div v-if="receiveForm.received_to_stock" class="field">
          <label>Warehouse <span class="req">*</span></label>
          <Select v-model="receiveForm.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value"
            placeholder="Select warehouse" :class="{ invalid: fieldError(receiveErrors, 'warehouse_id') }" />
          <small v-if="fieldError(receiveErrors, 'warehouse_id')" class="error-text">{{ fieldError(receiveErrors, 'warehouse_id') }}</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="receiveForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="receiveVisible = false" />
        <Button label="Save" :loading="savingReceive" @click="saveReceive" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Embroidery Work Details" :fields="[
      { key: 'work_number', label: 'Dispatch #' },
      { key: 'vendor_name', label: 'Vendor' },
      { key: 'product_name', label: 'Product' },
      { key: 'design_number', label: 'Design #' },
      { key: 'stitch_count', label: 'Stitches' },
      { key: 'cost', label: 'Cost', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'dispatched_qty', label: 'Dispatched' },
      { key: 'received_qty', label: 'Received' },
      { key: 'pending_qty', label: 'Pending' },
      { key: 'due_date', label: 'Due Date' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.search {
  position: relative;
  display: flex;
  align-items: center;
}
.search i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.search :deep(input) {
  padding-left: 2.25rem;
  width: 320px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
}
.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.checkbox-row {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}
.ctx {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  padding: 0.65rem 0.85rem;
  font-size: 0.9rem;
  color: #334155;
}
.req {
  color: #dc2626;
}
.muted {
  color: #9ca3af;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
