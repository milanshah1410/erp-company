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
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { useExpenseWorkflow } from '../composables/useExpenseWorkflow'

const auth = useAuthStore()
const crud = useCrud('/factory-maintenances')

const branches = ref<any[]>([])
const assets = ref<any[]>([])

const canCreate = computed(() => auth.can('factory-maintenances.create'))
const canApprove = computed(() => auth.can('factory-maintenances.approve'))
const canEdit = computed(() => auth.can('factory-maintenances.edit'))
const canDelete = computed(() => auth.can('factory-maintenances.delete'))

const statusColors: Record<string, string> = { draft: 'secondary', pending: 'warn', approved: 'success', rejected: 'danger' }
const statusOptions = [
  { label: 'Draft', value: 'draft' }, { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' },
]
const paymentMethods = [
  { label: 'Cash', value: 'cash' }, { label: 'Bank Transfer', value: 'bank' }, { label: 'UPI', value: 'upi' },
  { label: 'Card', value: 'card' }, { label: 'Cheque', value: 'cheque' }, { label: 'Other', value: 'other' },
]
const types = [
  { label: 'Preventive', value: 'preventive' }, { label: 'Breakdown', value: 'breakdown' },
  { label: 'Routine', value: 'routine' }, { label: 'Installation', value: 'installation' }, { label: 'Other', value: 'other' },
]

function currency(v: any) {
  return `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

const tab = ref<'list' | 'report'>('list')
const filters = ref<Record<string, any>>({ status: null, branch_id: null, maintenance_type: null, date_from: null, date_to: null })
function activeParams() {
  const p: Record<string, any> = {}
  for (const [k, v] of Object.entries(filters.value)) if (v) p[k] = v
  return p
}
function reload() {
  crud.fetch(activeParams())
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

const wf = useExpenseWorkflow('/factory-maintenances', reload)

const reportRows = ref<any[]>([])
const reportTotal = ref(0)
const reportLoading = ref(false)
async function loadReport() {
  reportLoading.value = true
  try {
    const { data } = await http.get('/factory-maintenances/report', { params: activeParams() })
    reportRows.value = data.data ?? []
    reportTotal.value = data.total ?? 0
  } finally {
    reportLoading.value = false
  }
}
function switchTab(key: 'list' | 'report') {
  tab.value = key
  key === 'list' ? reload() : loadReport()
}
function applyFilters() {
  tab.value === 'list' ? reload() : loadReport()
}

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})
const selectedFile = ref<File | null>(null)
const existingAttachment = ref<string | null>(null)

function blankForm() {
  return {
    branch_id: null, asset_id: null, area: '', maintenance_type: 'breakdown', maintenance_date: today(),
    performed_by: '', downtime_hours: null, amount: null, payment_method: 'cash', reference_no: '', description: '',
  }
}
function openCreate() {
  editingId.value = null
  form.value = blankForm()
  selectedFile.value = null
  existingAttachment.value = null
  errors.value = {}
  dialogVisible.value = true
}
async function openEdit(row: any) {
  const { data } = await http.get(`/factory-maintenances/${row.id}`)
  const e = data.data
  editingId.value = e.id
  form.value = {
    branch_id: e.branch_id, asset_id: e.asset_id, area: e.area || '', maintenance_type: e.maintenance_type,
    maintenance_date: e.maintenance_date, performed_by: e.performed_by || '',
    downtime_hours: e.downtime_hours !== null ? Number(e.downtime_hours) : null, amount: Number(e.amount),
    payment_method: e.payment_method, reference_no: e.reference_no || '', description: e.description || '',
  }
  selectedFile.value = null
  existingAttachment.value = e.attachment_url || null
  errors.value = {}
  dialogVisible.value = true
}
function onFileChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  selectedFile.value = files && files.length ? files[0] : null
}
async function save() {
  saving.value = true
  errors.value = {}
  try {
    let id = editingId.value
    if (id) await http.put(`/factory-maintenances/${id}`, form.value)
    else {
      const { data } = await http.post('/factory-maintenances', form.value)
      id = (data.data ?? data).id
    }
    if (selectedFile.value && id) await wf.uploadAttachment(id, selectedFile.value)
    wf.toast.add({ severity: 'success', summary: editingId.value ? 'Updated' : 'Created', life: 2500 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else wf.toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const [b, a] = await Promise.all([
    http.get('/branches', { params: { per_page: 100 } }),
    http.get('/assets', { params: { per_page: 100, is_active: true } }),
  ])
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  assets.value = (a.data.data ?? a.data).map((x: any) => ({ label: `${x.asset_code} — ${x.name}`, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Factory Maintenance</h1>
      <Button v-if="canCreate" label="New Record" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="tabbar">
      <button class="tab" :class="{ active: tab === 'list' }" @click="switchTab('list')"><i class="pi pi-wrench" /> Records</button>
      <button class="tab" :class="{ active: tab === 'report' }" @click="switchTab('report')"><i class="pi pi-chart-bar" /> By Type</button>
    </div>

    <div class="filters">
      <Select v-if="tab === 'list'" v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="applyFilters" />
      <Select v-model="filters.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="All branches" class="flt" @change="applyFilters" />
      <Select v-if="tab === 'list'" v-model="filters.maintenance_type" :options="types" optionLabel="label" optionValue="value" showClear placeholder="All types" class="flt" @change="applyFilters" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="applyFilters" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="applyFilters" />
    </div>

    <DataTable v-if="tab === 'list'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="maintenance_number" header="Record #" />
      <Column field="maintenance_date" header="Date" />
      <Column header="Area / Asset"><template #body="{ data }">{{ data.area || data.asset || '—' }}</template></Column>
      <Column header="Type"><template #body="{ data }">{{ data.maintenance_type }}</template></Column>
      <Column header="Downtime (h)" style="text-align: right"><template #body="{ data }">{{ data.downtime_hours ?? '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.amount) }}</strong></template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <Tag v-if="data.is_paid" severity="success" value="paid" class="paid-tag" />
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <template v-if="data.status === 'draft' || data.status === 'rejected'">
            <Button v-if="canEdit" label="Submit" size="small" severity="info" outlined @click="wf.submit(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="wf.remove(data, data.maintenance_number)" />
          </template>
          <template v-if="data.status === 'pending' && canApprove">
            <Button label="Approve" size="small" severity="success" @click="wf.approve(data)" />
            <Button label="Reject" size="small" severity="danger" outlined @click="wf.reject(data, data.maintenance_number)" />
          </template>
          <template v-if="data.status === 'approved' && canEdit">
            <Button :label="data.is_paid ? 'Mark Unpaid' : 'Mark Paid'" size="small" :severity="data.is_paid ? 'secondary' : 'success'" outlined @click="wf.togglePaid(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No maintenance records.</template>
    </DataTable>

    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="maintenance_type" class="p-datatable-sm">
      <Column header="Type"><template #body="{ data }">{{ data.maintenance_type }}</template></Column>
      <Column field="count" header="Records" style="text-align: right" />
      <Column header="Downtime (h)" style="text-align: right"><template #body="{ data }">{{ data.downtime_hours }}</template></Column>
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved records in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Maintenance' : 'New Maintenance'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
        <div class="fld">
          <label>Asset / Machine</label>
          <Select v-model="form.asset_id" :options="assets" optionLabel="label" optionValue="value" filter showClear placeholder="Optional" />
        </div>
        <div class="fld">
          <label>Area / Section</label>
          <InputText v-model="form.area" placeholder="e.g. Cutting section, Boiler" />
        </div>
        <div class="fld">
          <label>Type *</label>
          <Select v-model="form.maintenance_type" :options="types" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Date *</label>
          <input type="date" v-model="form.maintenance_date" class="date-input" />
          <small v-if="errors.maintenance_date" class="err">{{ errors.maintenance_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Performed By</label>
          <InputText v-model="form.performed_by" placeholder="Technician / vendor" />
        </div>
        <div class="fld">
          <label>Downtime (hours)</label>
          <InputNumber v-model="form.downtime_hours" :minFractionDigits="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>Amount *</label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" />
          <small v-if="errors.amount" class="err">{{ errors.amount[0] }}</small>
        </div>
        <div class="fld">
          <label>Payment Method *</label>
          <Select v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Reference No.</label>
          <InputText v-model="form.reference_no" placeholder="Bill / invoice no." />
        </div>
        <div class="fld">
          <label>Bill / Receipt</label>
          <input type="file" accept=".jpg,.jpeg,.png,.pdf" @change="onFileChange" class="file-input" />
          <small v-if="existingAttachment" class="hint">Current: <a :href="existingAttachment" target="_blank" rel="noopener">view</a> — choose a file to replace.</small>
        </div>
      </div>
      <div class="fld full">
        <label>Description</label>
        <Textarea v-model="form.description" rows="2" />
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.tabbar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.tab { background: none; border: none; padding: 0.6rem 1rem; font: inherit; font-size: 0.9rem; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; display: flex; align-items: center; gap: 0.4rem; }
.tab:hover { color: #111827; }
.tab.active { color: var(--p-primary-color, #6366f1); border-bottom-color: var(--p-primary-color, #6366f1); font-weight: 600; }
.filters { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 1rem; align-items: center; }
.flt { min-width: 160px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.file-input { font-size: 0.85rem; }
.w-full { width: 100%; }
.hint { font-size: 0.78rem; color: #6b7280; }
.err { color: #dc2626; font-size: 0.78rem; }
.paid-tag { margin-left: 0.35rem; }
.rpt-total { text-align: right; font-weight: 600; font-size: 0.95rem; padding: 0.25rem 0.5rem; }
a { color: var(--p-primary-color, #6366f1); }
</style>
