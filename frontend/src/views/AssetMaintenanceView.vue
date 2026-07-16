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
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const auth = useAuthStore()
const crud = useCrud('/asset-maintenances')

const branches = ref<any[]>([])
const assets = ref<any[]>([])

const canCreate = computed(() => auth.can('asset-maintenances.create'))
const canApprove = computed(() => auth.can('asset-maintenances.approve'))
const canEdit = computed(() => auth.can('asset-maintenances.edit'))
const canDelete = computed(() => auth.can('asset-maintenances.delete'))

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
  { label: 'Preventive', value: 'preventive' }, { label: 'Repair', value: 'repair' },
  { label: 'Calibration', value: 'calibration' }, { label: 'AMC', value: 'amc' },
  { label: 'Inspection', value: 'inspection' }, { label: 'Other', value: 'other' },
]

function currency(v: any) {
  return `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

const search = ref('')
const tab = ref<'list' | 'upcoming' | 'report'>('list')
const filters = ref<Record<string, any>>({ status: null, asset_id: null, maintenance_type: null, date_from: null, date_to: null })
function activeParams() {
  const p: Record<string, any> = {}
  if (search.value) p.search = search.value
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

const wf = useExpenseWorkflow('/asset-maintenances', reload)

const reportRows = ref<any[]>([])
const reportTotal = ref(0)
const upcomingRows = ref<any[]>([])
const reportLoading = ref(false)
async function loadReport() {
  reportLoading.value = true
  try {
    const { data } = await http.get('/asset-maintenances/report', { params: activeParams() })
    reportRows.value = data.data ?? []
    reportTotal.value = data.total ?? 0
  } finally {
    reportLoading.value = false
  }
}
async function loadUpcoming() {
  reportLoading.value = true
  try {
    const { data } = await http.get('/asset-maintenances/upcoming')
    upcomingRows.value = data.data ?? []
  } finally {
    reportLoading.value = false
  }
}
function switchTab(key: 'list' | 'upcoming' | 'report') {
  tab.value = key
  if (key === 'list') reload()
  else if (key === 'upcoming') loadUpcoming()
  else loadReport()
}
function applyFilters() {
  if (tab.value === 'list') reload()
  else if (tab.value === 'report') loadReport()
}

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})
const selectedFile = ref<File | null>(null)
const existingAttachment = ref<string | null>(null)

function blankForm() {
  return {
    asset_id: null, branch_id: null, maintenance_type: 'repair', maintenance_date: today(),
    next_service_date: null, performed_by: '', amount: null, payment_method: 'cash', reference_no: '', description: '',
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
  const { data } = await http.get(`/asset-maintenances/${row.id}`)
  const e = data.data
  editingId.value = e.id
  form.value = {
    asset_id: e.asset_id, branch_id: e.branch_id, maintenance_type: e.maintenance_type,
    maintenance_date: e.maintenance_date, next_service_date: e.next_service_date, performed_by: e.performed_by || '',
    amount: Number(e.amount), payment_method: e.payment_method, reference_no: e.reference_no || '', description: e.description || '',
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
    if (id) await http.put(`/asset-maintenances/${id}`, form.value)
    else {
      const { data } = await http.post('/asset-maintenances', form.value)
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
      <h1>Asset Maintenance</h1>
      <div style="display: flex; gap: 0.5rem; align-items: center">
        <TableExportButtons title="Asset Maintenance" endpoint="/asset-maintenances" :params="() => activeParams()"
          :columns="[
            { key: 'maintenance_number', label: 'Record #' },
            { key: 'maintenance_date', label: 'Date' },
            { key: 'asset', label: 'Asset' },
            { key: 'maintenance_type', label: 'Type' },
            { key: 'next_service_date', label: 'Next Service' },
            { key: 'amount', label: 'Amount' },
            { key: 'status', label: 'Status' },
            { key: 'is_paid', label: 'Paid', format: (v) => v ? 'Yes' : 'No' },
          ]" />
        <Button v-if="canCreate" label="New Record" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <div class="tabbar">
      <button class="tab" :class="{ active: tab === 'list' }" @click="switchTab('list')"><i class="pi pi-cog" /> Records</button>
      <button class="tab" :class="{ active: tab === 'upcoming' }" @click="switchTab('upcoming')"><i class="pi pi-calendar-clock" /> Upcoming</button>
      <button class="tab" :class="{ active: tab === 'report' }" @click="switchTab('report')"><i class="pi pi-chart-bar" /> By Asset</button>
    </div>

    <div class="filters" v-if="tab !== 'upcoming'">
      <Select v-if="tab === 'list'" v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="applyFilters" />
      <Select v-model="filters.asset_id" :options="assets" optionLabel="label" optionValue="value" filter showClear placeholder="All assets" class="flt" @change="applyFilters" />
      <Select v-if="tab === 'list'" v-model="filters.maintenance_type" :options="types" optionLabel="label" optionValue="value" showClear placeholder="All types" class="flt" @change="applyFilters" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="applyFilters" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="applyFilters" />
      <span v-if="tab === 'list'" class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button v-if="tab === 'list'" label="Search" outlined @click="reload" />
    </div>

    <DataTable v-if="tab === 'list'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="maintenance_number" header="Record #" />
      <Column field="maintenance_date" header="Date" />
      <Column header="Asset"><template #body="{ data }">{{ data.asset }}</template></Column>
      <Column header="Type"><template #body="{ data }">{{ data.maintenance_type }}</template></Column>
      <Column header="Next Service"><template #body="{ data }">{{ data.next_service_date || '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.amount) }}</strong></template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <Tag v-if="data.is_paid" severity="success" value="paid" class="paid-tag" />
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
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

    <DataTable v-else-if="tab === 'upcoming'" :value="upcomingRows" :loading="reportLoading" dataKey="id" class="p-datatable-sm">
      <Column header="Asset"><template #body="{ data }">{{ data.asset_code }} — {{ data.asset }}</template></Column>
      <Column header="Type"><template #body="{ data }">{{ data.maintenance_type }}</template></Column>
      <Column header="Last Service"><template #body="{ data }">{{ data.last_service_date || '—' }}</template></Column>
      <Column header="Next Service"><template #body="{ data }">{{ data.next_service_date }}</template></Column>
      <Column header="Days Left" style="text-align: right">
        <template #body="{ data }">
          <Tag :severity="data.days_remaining <= 7 ? 'danger' : data.days_remaining <= 30 ? 'warn' : 'success'" :value="`${data.days_remaining} d`" />
        </template>
      </Column>
      <template #empty>No upcoming servicing scheduled.</template>
    </DataTable>

    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="asset_id" class="p-datatable-sm">
      <Column header="Asset"><template #body="{ data }">{{ data.asset_code }} — {{ data.asset }}</template></Column>
      <Column field="count" header="Records" style="text-align: right" />
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved records in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Maintenance' : 'New Maintenance'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Asset *</label>
          <Select v-model="form.asset_id" :options="assets" optionLabel="label" optionValue="value" filter placeholder="Select asset" />
          <small v-if="errors.asset_id" class="err">{{ errors.asset_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
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
          <label>Next Service Date</label>
          <input type="date" v-model="form.next_service_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Performed By</label>
          <InputText v-model="form.performed_by" placeholder="Technician / vendor" />
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

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Maintenance Details" :fields="[
      { key: 'maintenance_number', label: 'Record #' },
      { key: 'maintenance_date', label: 'Date' },
      { key: 'asset', label: 'Asset' },
      { key: 'maintenance_type', label: 'Type' },
      { key: 'next_service_date', label: 'Next Service' },
      { key: 'performed_by', label: 'Performed By' },
      { key: 'amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'payment_method', label: 'Payment Method' },
      { key: 'reference_no', label: 'Reference No.' },
      { key: 'status', label: 'Status' },
      { key: 'is_paid', label: 'Paid' },
      { key: 'description', label: 'Description', full: true },
    ]" />
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
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
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
