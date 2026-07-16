<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
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
const crud = useCrud('/electricity-bills')

const branches = ref<any[]>([])

const canCreate = computed(() => auth.can('electricity-bills.create'))
const canApprove = computed(() => auth.can('electricity-bills.approve'))
const canEdit = computed(() => auth.can('electricity-bills.edit'))
const canDelete = computed(() => auth.can('electricity-bills.delete'))

const statusColors: Record<string, string> = { draft: 'secondary', pending: 'warn', approved: 'success', rejected: 'danger' }
const statusOptions = [
  { label: 'Draft', value: 'draft' }, { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' },
]
const paymentMethods = [
  { label: 'Cash', value: 'cash' }, { label: 'Bank Transfer', value: 'bank' }, { label: 'UPI', value: 'upi' },
  { label: 'Card', value: 'card' }, { label: 'Cheque', value: 'cheque' }, { label: 'Other', value: 'other' },
]

function currency(v: any) {
  return `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

const search = ref('')
const tab = ref<'list' | 'report'>('list')
const filters = ref<Record<string, any>>({ status: null, branch_id: null, date_from: null, date_to: null })
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

const wf = useExpenseWorkflow('/electricity-bills', reload)

const reportRows = ref<any[]>([])
const reportTotal = ref(0)
const reportLoading = ref(false)
async function loadReport() {
  reportLoading.value = true
  try {
    const { data } = await http.get('/electricity-bills/report', { params: activeParams() })
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
    branch_id: null, meter_number: '', provider: '', connection_name: '', bill_date: today(),
    billing_period_start: null, billing_period_end: null, due_date: null,
    previous_reading: null, current_reading: null, units_consumed: null, amount: null,
    payment_method: 'bank', reference_no: '', description: '',
  }
}
// Derive units from readings client-side for immediate feedback.
watch(() => [form.value.previous_reading, form.value.current_reading], () => {
  const p = Number(form.value.previous_reading)
  const c = Number(form.value.current_reading)
  if (!isNaN(p) && !isNaN(c) && form.value.current_reading !== null && form.value.previous_reading !== null) {
    form.value.units_consumed = Math.max(0, Math.round((c - p) * 100) / 100)
  }
})

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  selectedFile.value = null
  existingAttachment.value = null
  errors.value = {}
  dialogVisible.value = true
}
async function openEdit(row: any) {
  const { data } = await http.get(`/electricity-bills/${row.id}`)
  const e = data.data
  editingId.value = e.id
  form.value = {
    branch_id: e.branch_id, meter_number: e.meter_number || '', provider: e.provider || '',
    connection_name: e.connection_name || '', bill_date: e.bill_date,
    billing_period_start: e.billing_period_start, billing_period_end: e.billing_period_end, due_date: e.due_date,
    previous_reading: e.previous_reading !== null ? Number(e.previous_reading) : null,
    current_reading: e.current_reading !== null ? Number(e.current_reading) : null,
    units_consumed: e.units_consumed !== null ? Number(e.units_consumed) : null,
    amount: Number(e.amount), payment_method: e.payment_method,
    reference_no: e.reference_no || '', description: e.description || '',
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
    if (id) await http.put(`/electricity-bills/${id}`, form.value)
    else {
      const { data } = await http.post('/electricity-bills', form.value)
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
  const b = await http.get('/branches', { params: { per_page: 100 } })
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Electricity Bills</h1>
      <Button v-if="canCreate" label="New Bill" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="tabbar">
      <button class="tab" :class="{ active: tab === 'list' }" @click="switchTab('list')"><i class="pi pi-bolt" /> Bills</button>
      <button class="tab" :class="{ active: tab === 'report' }" @click="switchTab('report')"><i class="pi pi-chart-bar" /> Units & Cost</button>
    </div>

    <div class="filters">
      <Select v-if="tab === 'list'" v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="applyFilters" />
      <Select v-model="filters.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="All branches" class="flt" @change="applyFilters" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="applyFilters" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="applyFilters" />
      <span v-if="tab === 'list'" class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button v-if="tab === 'list'" label="Search" outlined @click="reload" />
      <TableExportButtons v-if="tab === 'list'" title="Electricity Bills" endpoint="/electricity-bills"
        :params="() => activeParams()"
        :columns="[
          { key: 'bill_number', label: 'Bill #' },
          { key: 'bill_date', label: 'Date' },
          { key: 'branch', label: 'Branch' },
          { key: 'meter_number', label: 'Meter' },
          { key: 'units_consumed', label: 'Units' },
          { key: 'amount', label: 'Amount' },
          { key: 'due_date', label: 'Due' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable v-if="tab === 'list'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="bill_number" header="Bill #" />
      <Column field="bill_date" header="Date" />
      <Column header="Branch"><template #body="{ data }">{{ data.branch || '—' }}</template></Column>
      <Column header="Meter"><template #body="{ data }">{{ data.meter_number || '—' }}</template></Column>
      <Column header="Units" style="text-align: right"><template #body="{ data }">{{ data.units_consumed ?? '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.amount) }}</strong></template></Column>
      <Column header="Due"><template #body="{ data }">{{ data.due_date || '—' }}</template></Column>
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
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="wf.remove(data, data.bill_number)" />
          </template>
          <template v-if="data.status === 'pending' && canApprove">
            <Button label="Approve" size="small" severity="success" @click="wf.approve(data)" />
            <Button label="Reject" size="small" severity="danger" outlined @click="wf.reject(data, data.bill_number)" />
          </template>
          <template v-if="data.status === 'approved' && canEdit">
            <Button :label="data.is_paid ? 'Mark Unpaid' : 'Mark Paid'" size="small" :severity="data.is_paid ? 'secondary' : 'success'" outlined @click="wf.togglePaid(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No electricity bills recorded.</template>
    </DataTable>

    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="branch_id" class="p-datatable-sm">
      <Column header="Branch"><template #body="{ data }">{{ data.branch }}</template></Column>
      <Column field="count" header="Bills" style="text-align: right" />
      <Column header="Units" style="text-align: right"><template #body="{ data }">{{ data.units }}</template></Column>
      <Column header="₹ / Unit" style="text-align: right"><template #body="{ data }">{{ data.per_unit ?? '—' }}</template></Column>
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved bills in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Electricity Bill' : 'New Electricity Bill'" :style="{ width: '680px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
        <div class="fld">
          <label>Provider</label>
          <InputText v-model="form.provider" placeholder="e.g. MSEB, Torrent" />
        </div>
        <div class="fld">
          <label>Meter Number</label>
          <InputText v-model="form.meter_number" />
        </div>
        <div class="fld">
          <label>Connection Name</label>
          <InputText v-model="form.connection_name" />
        </div>
        <div class="fld">
          <label>Bill Date *</label>
          <input type="date" v-model="form.bill_date" class="date-input" />
          <small v-if="errors.bill_date" class="err">{{ errors.bill_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Due Date</label>
          <input type="date" v-model="form.due_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Period Start</label>
          <input type="date" v-model="form.billing_period_start" class="date-input" />
        </div>
        <div class="fld">
          <label>Period End</label>
          <input type="date" v-model="form.billing_period_end" class="date-input" />
          <small v-if="errors.billing_period_end" class="err">{{ errors.billing_period_end[0] }}</small>
        </div>
        <div class="fld">
          <label>Previous Reading</label>
          <InputNumber v-model="form.previous_reading" :minFractionDigits="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>Current Reading</label>
          <InputNumber v-model="form.current_reading" :minFractionDigits="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>Units Consumed</label>
          <InputNumber v-model="form.units_consumed" :minFractionDigits="0" :maxFractionDigits="2" class="w-full" />
          <small class="hint">Auto-filled from readings; editable.</small>
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
          <InputText v-model="form.reference_no" placeholder="Consumer / txn no." />
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

    <DetailDialog
      v-model:visible="detailVisible"
      title="Electricity Bill"
      :record="detailRecord"
      :fields="[
        { label: 'Bill #', key: 'bill_number' },
        { label: 'Bill Date', key: 'bill_date' },
        { label: 'Provider', key: 'provider' },
        { label: 'Branch', key: 'branch' },
        { label: 'Meter', key: 'meter_number' },
        { label: 'Units Consumed', key: 'units_consumed' },
        { label: 'Amount', key: 'amount', format: (v) => currency(v) },
        { label: 'Due Date', key: 'due_date' },
        { label: 'Status', key: 'status' },
        { label: 'Paid', key: 'is_paid' },
        { label: 'Notes', key: 'notes', full: true },
      ]"
    />
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
