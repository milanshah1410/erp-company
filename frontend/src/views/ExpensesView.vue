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
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/expenses')

const categories = ref<any[]>([])
const branches = ref<any[]>([])
const tdsRates = ref<any[]>([])

const canCreate = computed(() => auth.can('expenses.create'))
const canApprove = computed(() => auth.can('expenses.approve'))
const canEdit = computed(() => auth.can('expenses.edit'))
const canDelete = computed(() => auth.can('expenses.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  pending: 'warn',
  approved: 'success',
  rejected: 'danger',
}
const statusOptions = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Pending'), value: 'pending' },
  { label: pt('Approved'), value: 'approved' },
  { label: pt('Rejected'), value: 'rejected' },
])
const paymentMethods = computed(() => [
  { label: pt('Cash'), value: 'cash' },
  { label: pt('Bank Transfer'), value: 'bank' },
  { label: pt('UPI'), value: 'upi' },
  { label: pt('Card'), value: 'card' },
  { label: pt('Cheque'), value: 'cheque' },
  { label: pt('Other'), value: 'other' },
])

function currency(v: any) {
  return `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

// --- Tabs ---
const tab = ref<'expenses' | 'ledger' | 'monthly' | 'branch' | 'category'>('expenses')
const tabs = computed(() => [
  { key: 'expenses', label: pt('Expenses'), icon: 'pi pi-receipt' },
  { key: 'ledger', label: pt('Ledger'), icon: 'pi pi-book' },
  { key: 'monthly', label: pt('Monthly'), icon: 'pi pi-calendar' },
  { key: 'branch', label: pt('Branch-wise'), icon: 'pi pi-map-marker' },
  { key: 'category', label: pt('Category-wise'), icon: 'pi pi-tags' },
] as const)

// --- List filters ---
const search = ref('')
const filters = ref<{ status: string | null; expense_category_id: number | null; branch_id: number | null; date_from: string | null; date_to: string | null }>({
  status: null,
  expense_category_id: null,
  branch_id: null,
  date_from: null,
  date_to: null,
})

function activeParams() {
  const p: Record<string, any> = {}
  if (search.value) p.search = search.value
  if (filters.value.status) p.status = filters.value.status
  if (filters.value.expense_category_id) p.expense_category_id = filters.value.expense_category_id
  if (filters.value.branch_id) p.branch_id = filters.value.branch_id
  if (filters.value.date_from) p.date_from = filters.value.date_from
  if (filters.value.date_to) p.date_to = filters.value.date_to
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

// --- Reports ---
const reportRows = ref<any[]>([])
const reportTotal = ref(0)
const reportLoading = ref(false)

async function loadReport(kind: 'ledger' | 'monthly' | 'branch' | 'category') {
  reportLoading.value = true
  try {
    const path = kind === 'ledger' ? '/expenses/ledger' : `/expenses/report/${kind}`
    const { data } = await http.get(path, { params: activeParams() })
    reportRows.value = data.data ?? []
    reportTotal.value = data.total ?? 0
  } finally {
    reportLoading.value = false
  }
}

function switchTab(key: typeof tab.value) {
  tab.value = key
  if (key === 'expenses') reload()
  else loadReport(key)
}

function applyFilters() {
  if (tab.value === 'expenses') reload()
  else loadReport(tab.value)
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})
const selectedFile = ref<File | null>(null)
const existingAttachment = ref<string | null>(null)

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function blankForm() {
  return {
    expense_category_id: null,
    branch_id: null,
    expense_date: today(),
    amount: null,
    tds_section: null,
    tds_rate: 0,
    tds_amount: 0,
    payment_method: 'cash',
    payee: '',
    reference_no: '',
    description: '',
  }
}

/** When a TDS section is picked, pull its rate and recompute the TDS amount. */
function onTdsSectionChange() {
  const rate = tdsRates.value.find((r) => r.value === form.value.tds_section)?.rate ?? 0
  form.value.tds_rate = rate
  recomputeTds()
}
function recomputeTds() {
  const base = Number(form.value.amount) || 0
  const rate = Number(form.value.tds_rate) || 0
  form.value.tds_amount = Math.round(base * rate) / 100
}
const netPayable = computed(() => (Number(form.value.amount) || 0) - (Number(form.value.tds_amount) || 0))

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  selectedFile.value = null
  existingAttachment.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/expenses/${row.id}`)
    const e = data.data
    editingId.value = e.id
    form.value = {
      expense_category_id: e.expense_category_id,
      branch_id: e.branch_id,
      expense_date: e.expense_date,
      amount: Number(e.amount),
      tds_section: e.tds_section ?? null,
      tds_rate: Number(e.tds_rate) || 0,
      tds_amount: Number(e.tds_amount) || 0,
      payment_method: e.payment_method,
      payee: e.payee || '',
      reference_no: e.reference_no || '',
      description: e.description || '',
    }
    selectedFile.value = null
    existingAttachment.value = e.attachment_url || null
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load expense', life: 3000 })
  }
}

function onFileChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  selectedFile.value = files && files.length ? files[0] : null
}

async function uploadAttachment(id: number) {
  if (!selectedFile.value) return
  const fd = new FormData()
  fd.append('attachment', selectedFile.value)
  await http.post(`/expenses/${id}/attachment`, fd)
}

async function saveExpense() {
  saving.value = true
  errors.value = {}
  try {
    let id = editingId.value
    if (id) {
      await http.put(`/expenses/${id}`, form.value)
    } else {
      const { data } = await http.post('/expenses', form.value)
      id = (data.data ?? data).id
    }
    if (selectedFile.value && id) await uploadAttachment(id)
    toast.add({ severity: 'success', summary: editingId.value ? 'Expense updated' : 'Expense created', life: 2500 })
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
async function post(url: string, okMsg: string, body: any = {}) {
  try {
    await http.post(url, body)
    toast.add({ severity: 'success', summary: okMsg, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function submitExpense(row: any) {
  post(`/expenses/${row.id}/submit`, 'Submitted for approval')
}
function approveExpense(row: any) {
  post(`/expenses/${row.id}/approve`, 'Expense approved')
}
async function rejectExpense(row: any) {
  const res = await Swal.fire({
    title: `Reject ${row.expense_number}?`,
    input: 'text',
    inputLabel: 'Reason (optional)',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Reject',
  })
  if (!res.isConfirmed) return
  post(`/expenses/${row.id}/reject`, 'Expense rejected', { rejection_reason: res.value || null })
}
function togglePaid(row: any) {
  post(`/expenses/${row.id}/mark-paid`, row.is_paid ? 'Marked unpaid' : 'Marked paid', { paid: !row.is_paid })
}

async function removeExpense(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.expense_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/expenses/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

onMounted(async () => {
  const [c, b, t] = await Promise.all([
    http.get('/expense-categories', { params: { per_page: 100, is_active: true } }),
    http.get('/branches', { params: { per_page: 100 } }),
    http.get('/tds-tcs-rates', { params: { per_page: 100, nature: 'tds', is_active: true } }),
  ])
  categories.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  tdsRates.value = (t.data.data ?? t.data).map((x: any) => ({ label: `${x.section} (${x.rate}%)`, value: x.section, rate: Number(x.rate) }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Company Expenses" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <TableExportButtons title="Company Expenses" endpoint="/expenses" :params="() => activeParams()" :columns="[
        { key: 'expense_number', label: 'Expense #' },
        { key: 'expense_date', label: 'Date' },
        { key: 'category', label: 'Category' },
        { key: 'branch', label: 'Branch' },
        { key: 'payee', label: 'Payee' },
        { key: 'amount', label: 'Amount' },
        { key: 'status', label: 'Status' },
        ]" />
        <Button v-if="canCreate" label="New Expense" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <div class="tabbar">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- Filters (shared across list + reports) -->
    <div class="filters">
      <Select v-if="tab === 'expenses'" v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="applyFilters" />
      <Select v-model="filters.expense_category_id" :options="categories" optionLabel="label" optionValue="value" filter showClear placeholder="All categories" class="flt" @change="applyFilters" />
      <Select v-model="filters.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="All branches" class="flt" @change="applyFilters" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="applyFilters" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="applyFilters" />
    </div>

    <!-- Expenses list -->
    <DataTable v-if="tab === 'expenses'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="expense_number" header="Expense #" />
      <Column field="expense_date" header="Date" />
      <Column header="Category"><template #body="{ data }">{{ data.category }}</template></Column>
      <Column header="Branch"><template #body="{ data }">{{ data.branch || '—' }}</template></Column>
      <Column header="Payee"><template #body="{ data }">{{ data.payee || '—' }}</template></Column>
      <Column header="Amount" style="text-align: right">
        <template #body="{ data }"><strong>{{ currency(data.amount) }}</strong></template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <Tag v-if="data.is_paid" severity="success" value="paid" class="paid-tag" />
        </template>
      </Column>
      <Column header="Bill" style="width: 4rem">
        <template #body="{ data }">
          <a v-if="data.attachment_url" :href="data.attachment_url" target="_blank" rel="noopener" title="View bill"><i class="pi pi-paperclip" /></a>
          <span v-else>—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="data.status === 'draft' || data.status === 'rejected'">
            <Button v-if="canEdit" label="Submit" size="small" severity="info" outlined @click="submitExpense(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeExpense(data)" />
          </template>
          <template v-if="data.status === 'pending' && canApprove">
            <Button label="Approve" size="small" severity="success" @click="approveExpense(data)" />
            <Button label="Reject" size="small" severity="danger" outlined @click="rejectExpense(data)" />
          </template>
          <template v-if="data.status === 'approved' && canEdit">
            <Button :label="data.is_paid ? 'Mark Unpaid' : 'Mark Paid'" size="small" :severity="data.is_paid ? 'secondary' : 'success'" outlined @click="togglePaid(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No expenses recorded.</template>
    </DataTable>

    <!-- Ledger -->
    <DataTable v-else-if="tab === 'ledger'" :value="reportRows" :loading="reportLoading" dataKey="id" class="p-datatable-sm" paginator :rows="25">
      <Column field="expense_date" header="Date" />
      <Column field="expense_number" header="Expense #" />
      <Column header="Category"><template #body="{ data }">{{ data.category }}</template></Column>
      <Column header="Branch"><template #body="{ data }">{{ data.branch || '—' }}</template></Column>
      <Column header="Payee"><template #body="{ data }">{{ data.payee || '—' }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }">{{ currency(data.amount) }}</template></Column>
      <Column header="Running Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.running_total) }}</strong></template></Column>
      <template #empty>No expenses match the filters.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <!-- Monthly -->
    <DataTable v-else-if="tab === 'monthly'" :value="reportRows" :loading="reportLoading" dataKey="period" class="p-datatable-sm">
      <Column field="period" header="Month" />
      <Column field="count" header="Entries" style="text-align: right" />
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved expenses in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <!-- Branch-wise -->
    <DataTable v-else-if="tab === 'branch'" :value="reportRows" :loading="reportLoading" dataKey="branch_id" class="p-datatable-sm">
      <Column header="Branch"><template #body="{ data }">{{ data.branch }}</template></Column>
      <Column field="count" header="Entries" style="text-align: right" />
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved expenses in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <!-- Category-wise -->
    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="expense_category_id" class="p-datatable-sm">
      <Column header="Category"><template #body="{ data }">{{ data.category }}</template></Column>
      <Column field="count" header="Entries" style="text-align: right" />
      <Column header="Total" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total) }}</strong></template></Column>
      <template #empty>No approved expenses in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Expense' : 'New Expense'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Category *</label>
          <Select v-model="form.expense_category_id" :options="categories" optionLabel="label" optionValue="value" filter placeholder="Select category" />
          <small v-if="errors.expense_category_id" class="err">{{ errors.expense_category_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
        <div class="fld">
          <label>Expense Date *</label>
          <input type="date" v-model="form.expense_date" class="date-input" />
          <small v-if="errors.expense_date" class="err">{{ errors.expense_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Amount *</label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" @update:modelValue="recomputeTds" />
          <small v-if="errors.amount" class="err">{{ errors.amount[0] }}</small>
        </div>
        <div class="fld">
          <label>TDS Section</label>
          <Select v-model="form.tds_section" :options="tdsRates" optionLabel="label" optionValue="value" showClear placeholder="No TDS" @change="onTdsSectionChange" />
        </div>
        <div class="fld">
          <label>TDS Amount</label>
          <InputNumber v-model="form.tds_amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" />
          <small class="hint">Rate {{ form.tds_rate }}% · Net payable {{ currency(netPayable) }}</small>
        </div>
        <div class="fld">
          <label>Payment Method *</label>
          <Select v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Payee / Paid To</label>
          <InputText v-model="form.payee" placeholder="e.g. Landlord, MSEB" />
        </div>
        <div class="fld">
          <label>Reference No.</label>
          <InputText v-model="form.reference_no" placeholder="Bill / cheque / txn no." />
        </div>
        <div class="fld">
          <label>Bill / Receipt</label>
          <input type="file" accept=".jpg,.jpeg,.png,.pdf" @change="onFileChange" class="file-input" />
          <small v-if="existingAttachment" class="hint">
            Current: <a :href="existingAttachment" target="_blank" rel="noopener">view attachment</a> — choose a file to replace it.
          </small>
        </div>
      </div>
      <div class="fld full">
        <label>Description</label>
        <Textarea v-model="form.description" rows="2" placeholder="Notes / details" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="saveExpense" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Expense Details" :fields="[
      { key: 'expense_number', label: 'Expense #' },
      { key: 'expense_date', label: 'Date' },
      { key: 'category', label: 'Category' },
      { key: 'branch', label: 'Branch' },
      { key: 'payee', label: 'Payee' },
      { key: 'amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: 'Status' },
      { key: 'is_paid', label: 'Paid' },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
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
