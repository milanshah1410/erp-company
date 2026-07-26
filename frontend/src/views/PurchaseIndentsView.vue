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
import MultiSelect from 'primevue/multiselect'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()
const auth = useAuthStore()
const crud = useCrud('/purchase-indents')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const departments = ref<any[]>([])
const warehouses = ref<any[]>([])
const suppliers = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('purchase-indents.create'))
const canEdit = computed(() => auth.can('purchase-indents.edit'))
const canDelete = computed(() => auth.can('purchase-indents.delete'))
const canApprove = computed(() => auth.can('purchase-indents.approve'))
const canRfq = computed(() => auth.can('rfqs.create'))
const canPo = computed(() => auth.can('purchases.create'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  pending_approval: 'warn',
  approved: 'info',
  rejected: 'danger',
  converted: 'success',
  cancelled: 'danger',
}
const priorityColors: Record<string, string> = {
  low: 'secondary', normal: 'info', high: 'warn', urgent: 'danger',
}
const priorities = computed(() => [
  { label: pt('Low'), value: 'low' },
  { label: pt('Normal'), value: 'normal' },
  { label: pt('High'), value: 'high' },
  { label: pt('Urgent'), value: 'urgent' },
])

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Pending Approval'), value: 'pending_approval' },
  { label: pt('Approved'), value: 'approved' },
  { label: pt('Rejected'), value: 'rejected' },
  { label: pt('Converted'), value: 'converted' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

function today() {
  return new Date().toISOString().slice(0, 10)
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    department_id: null, warehouse_id: null, priority: 'normal',
    indent_date: today(), required_date: null, notes: '', items: [],
  }
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, estimated_cost: 0, notes: '' })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) line.estimated_cost = Number(p.cost_price) || 0
}

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  const { data } = await http.get(`/purchase-indents/${row.id}`)
  const d = data.data
  form.value = {
    department_id: d.department_id, warehouse_id: d.warehouse_id, priority: d.priority,
    indent_date: d.indent_date, required_date: d.required_date, notes: d.notes || '',
    items: d.items.map((i: any) => ({
      product_id: i.product_id, quantity: Number(i.quantity),
      estimated_cost: Number(i.estimated_cost), notes: i.notes || '',
    })),
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/purchase-indents/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: pt('Indent updated'), life: 2500 })
    } else {
      await http.post('/purchase-indents', form.value)
      toast.add({ severity: 'success', summary: pt('Indent created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function submit(row: any) {
  await act(`/purchase-indents/${row.id}/submit`, pt('Submitted for approval'))
}
async function approve(row: any) {
  const res = await Swal.fire({ title: pt('Approve {n}?', { n: row.indent_number }), icon: 'question', showCancelButton: true, confirmButtonText: pt('Approve'), cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  await act(`/purchase-indents/${row.id}/approve`, pt('Indent approved'))
}
async function reject(row: any) {
  const res = await Swal.fire({
    title: pt('Reject {n}?', { n: row.indent_number }), input: 'text', inputLabel: pt('Reason (optional)'),
    icon: 'warning', showCancelButton: true, confirmButtonText: pt('Reject'), cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  await act(`/purchase-indents/${row.id}/reject`, pt('Indent rejected'), { reason: res.value })
}
async function remove(row: any) {
  const res = await Swal.fire({ title: pt('Delete {n}?', { n: row.indent_number }), icon: 'warning', showCancelButton: true, confirmButtonText: pt('Delete'), cancelButtonText: pt('Cancel'), confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/purchase-indents/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}
async function act(url: string, msg: string, body: any = {}) {
  try {
    await http.post(url, body)
    toast.add({ severity: 'success', summary: msg, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}

// --- Convert to RFQ dialog ---
const rfqVisible = ref(false)
const rfqSaving = ref(false)
const rfqSource = ref<any>(null)
const rfqForm = ref<any>({ supplier_ids: [], response_deadline: null })

function openConvertRfq(row: any) {
  rfqSource.value = row
  rfqForm.value = { supplier_ids: [], response_deadline: null }
  rfqVisible.value = true
}
async function convertToRfq() {
  if (!rfqForm.value.supplier_ids.length) {
    toast.add({ severity: 'warn', summary: pt('Pick at least one vendor'), life: 3000 })
    return
  }
  rfqSaving.value = true
  try {
    const { data } = await http.post(`/purchase-indents/${rfqSource.value.id}/convert-to-rfq`, rfqForm.value)
    toast.add({ severity: 'success', summary: pt('RFQ {n} created', { n: data.data.rfq_number }), life: 3500 })
    rfqVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  } finally {
    rfqSaving.value = false
  }
}

// --- Convert to PO dialog ---
const poVisible = ref(false)
const poSaving = ref(false)
const poSource = ref<any>(null)
const poForm = ref<any>({ supplier_id: null, warehouse_id: null })

function openConvertPo(row: any) {
  poSource.value = row
  poForm.value = { supplier_id: null, warehouse_id: row.warehouse?.id ?? null }
  poVisible.value = true
}
async function convertToPo() {
  if (!poForm.value.supplier_id || !poForm.value.warehouse_id) {
    toast.add({ severity: 'warn', summary: pt('Supplier and warehouse are required'), life: 3000 })
    return
  }
  poSaving.value = true
  try {
    const { data } = await http.post(`/purchase-indents/${poSource.value.id}/convert-to-po`, poForm.value)
    toast.add({ severity: 'success', summary: pt('PO {n} created (draft)', { n: data.data.po_number }), life: 3500 })
    poVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  } finally {
    poSaving.value = false
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

onMounted(async () => {
  const [d, w, s, p] = await Promise.all([
    http.get('/departments', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/suppliers', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  departments.value = (d.data.data ?? d.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Purchase Indents')" :count="crud.total.value">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Indent')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;gap:0.5rem;align-items:center;margin-bottom:1rem">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
      </span>
      <Button :label="pt('Search')" outlined @click="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All statuses')" @change="reload" class="status-filter" />
      <TableExportButtons :title="pt('Purchase Indents')" endpoint="/purchase-indents"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
          { key: 'indent_number', label: pt('Indent #') },
          { key: 'department.name', label: pt('Department') },
          { key: 'indent_date', label: pt('Date') },
          { key: 'required_date', label: pt('Required') },
          { key: 'priority', label: pt('Priority') },
          { key: 'items_count', label: pt('Items') },
          { key: 'status', label: pt('Status') },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="indent_number" :header="pt('Indent #')" />
      <Column :header="pt('Department')"><template #body="{ data }">{{ data.department?.name || '—' }}</template></Column>
      <Column field="indent_date" :header="pt('Date')" />
      <Column :header="pt('Required')"><template #body="{ data }">{{ data.required_date || '—' }}</template></Column>
      <Column :header="pt('Priority')">
        <template #body="{ data }"><Tag :severity="priorityColors[data.priority]" :value="pt(data.priority)" /></template>
      </Column>
      <Column :header="pt('Items')"><template #body="{ data }">{{ data.items_count ?? '—' }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="pt(data.status.replace('_', ' '))" /></template>
      </Column>
      <Column :header="pt('Actions')" style="width: 20rem">
        <template #body="{ data }">
          <div class="actions">
            <Button icon="pi pi-eye" text rounded size="small" severity="secondary" :title="pt('View')" @click="openDetail(data)" />
            <Button v-if="canEdit && ['draft', 'rejected'].includes(data.status)" :label="pt('Submit')" size="small" outlined @click="submit(data)" />
            <Button v-if="canApprove && data.status === 'pending_approval'" :label="pt('Approve')" size="small" severity="success" outlined @click="approve(data)" />
            <Button v-if="canApprove && data.status === 'pending_approval'" :label="pt('Reject')" size="small" severity="danger" outlined @click="reject(data)" />
            <Button v-if="canRfq && data.status === 'approved'" :label="pt('→ RFQ')" size="small" outlined @click="openConvertRfq(data)" />
            <Button v-if="canPo && data.status === 'approved'" :label="pt('→ PO')" size="small" severity="info" outlined @click="openConvertPo(data)" />
            <Button v-if="canEdit && ['draft', 'rejected'].includes(data.status)" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete && ['draft', 'rejected'].includes(data.status)" icon="pi pi-trash" size="small" text severity="danger" @click="remove(data)" />
          </div>
        </template>
      </Column>
      <template #empty>{{ pt('No purchase indents yet.') }}</template>
    </DataTable>

    <!-- Create/edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? pt('Edit Indent') : pt('New Purchase Indent')" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>{{ pt('Department') }}</label>
          <Select v-model="form.department_id" :options="departments" optionLabel="label" optionValue="value" showClear filter :placeholder="pt('Select department')" />
        </div>
        <div class="fld">
          <label>{{ pt('Warehouse') }}</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" showClear :placeholder="pt('Select warehouse')" />
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select v-model="form.priority" :options="priorities" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Indent Date') }} *</label>
          <input type="date" v-model="form.indent_date" class="date-input" />
        </div>
        <div class="fld">
          <label>{{ pt('Required By') }}</label>
          <input type="date" v-model="form.required_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">{{ pt('Requested Items') }}</h3>
      <table class="lines">
        <thead>
          <tr><th style="width: 34%">{{ pt('Product') }}</th><th>{{ pt('Qty') }}</th><th>{{ pt('Est. Cost') }}</th><th>{{ pt('Notes') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter :placeholder="pt('Product')" class="w-full" @change="onProductPick(line)" />
            </td>
            <td><InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="line.estimated_cost" :minFractionDigits="2" :maxFractionDigits="2" class="num" /></td>
            <td><input v-model="line.notes" class="date-input" :placeholder="pt('Optional')" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add Line')" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editingId ? pt('Save') : pt('Create Indent')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Convert to RFQ -->
    <Dialog v-model:visible="rfqVisible" modal :header="pt('Create RFQ from {n}', { n: rfqSource?.indent_number })" :style="{ width: '520px' }">
      <div class="fld">
        <label>{{ pt('Invite Vendors') }} *</label>
        <MultiSelect v-model="rfqForm.supplier_ids" :options="suppliers" optionLabel="label" optionValue="value" filter display="chip" :placeholder="pt('Select vendors to invite')" />
      </div>
      <div class="fld" style="margin-top: 1rem">
        <label>{{ pt('Response Deadline') }}</label>
        <input type="date" v-model="rfqForm.response_deadline" class="date-input" />
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="rfqVisible = false" />
        <Button :label="pt('Create RFQ')" :loading="rfqSaving" @click="convertToRfq" />
      </template>
    </Dialog>

    <!-- Convert to PO -->
    <Dialog v-model:visible="poVisible" modal :header="pt('Create PO from {n}', { n: poSource?.indent_number })" :style="{ width: '520px' }">
      <p class="hint">{{ pt("Line costs default to the indent's estimated cost (or the product's cost price).") }}</p>
      <div class="fld">
        <label>{{ pt('Supplier') }} *</label>
        <Select v-model="poForm.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter :placeholder="pt('Select supplier')" />
      </div>
      <div class="fld" style="margin-top: 1rem">
        <label>{{ pt('Warehouse') }} *</label>
        <Select v-model="poForm.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" :placeholder="pt('Select warehouse')" />
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="poVisible = false" />
        <Button :label="pt('Create PO')" :loading="poSaving" @click="convertToPo" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Purchase Indent Details')" :fields="[
      { key: 'indent_number', label: pt('Indent #') },
      { key: 'department.name', label: pt('Department') },
      { key: 'warehouse.name', label: pt('Warehouse') },
      { key: 'priority', label: pt('Priority') },
      { key: 'indent_date', label: pt('Indent Date') },
      { key: 'required_date', label: pt('Required By') },
      { key: 'items_count', label: pt('Items') },
      { key: 'status', label: pt('Status') },
      { key: 'notes', label: pt('Notes'), full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.status-filter { width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.actions { display: flex; flex-wrap: wrap; gap: 4px; }
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(.p-multiselect), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.hint { font-size: 0.82rem; color: #6b7280; margin-bottom: 0.75rem; }
</style>
