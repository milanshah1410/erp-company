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
const crud = useCrud('/employee-claims')

const employees = ref<any[]>([])
const branches = ref<any[]>([])
const categories = ref<any[]>([])

const canCreate = computed(() => auth.can('employee-claims.create'))
const canApprove = computed(() => auth.can('employee-claims.approve'))
const canEdit = computed(() => auth.can('employee-claims.edit'))
const canDelete = computed(() => auth.can('employee-claims.delete'))

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

const filters = ref<Record<string, any>>({ status: null, employee_id: null, branch_id: null, date_from: null, date_to: null })
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

const wf = useExpenseWorkflow('/employee-claims', reload)

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
    employee_id: null, branch_id: null, claim_date: today(), title: '',
    payment_method: 'bank', reference_no: '', description: '',
    items: [blankItem()],
  }
}
function blankItem() {
  return { expense_category_id: null, expense_date: today(), description: '', amount: null }
}
const claimTotal = computed(() => form.value.items.reduce((s: number, it: any) => s + (Number(it.amount) || 0), 0))

function addItem() {
  form.value.items.push(blankItem())
}
function removeItem(i: number) {
  form.value.items.splice(i, 1)
  if (!form.value.items.length) addItem()
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
  const { data } = await http.get(`/employee-claims/${row.id}`)
  const e = data.data
  editingId.value = e.id
  form.value = {
    employee_id: e.employee_id, branch_id: e.branch_id, claim_date: e.claim_date, title: e.title || '',
    payment_method: e.payment_method, reference_no: e.reference_no || '', description: e.description || '',
    items: (e.items || []).map((it: any) => ({
      expense_category_id: it.expense_category_id,
      expense_date: it.expense_date,
      description: it.description || '',
      amount: Number(it.amount),
    })),
  }
  if (!form.value.items.length) form.value.items = [blankItem()]
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
    if (id) await http.put(`/employee-claims/${id}`, form.value)
    else {
      const { data } = await http.post('/employee-claims', form.value)
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
  const [emp, b, c] = await Promise.all([
    http.get('/employees', { params: { per_page: 100 } }),
    http.get('/branches', { params: { per_page: 100 } }),
    http.get('/expense-categories', { params: { per_page: 100, is_active: true } }),
  ])
  employees.value = (emp.data.data ?? emp.data).map((x: any) => ({
    label: x.full_name || `${x.employee_code} — ${x.first_name} ${x.last_name || ''}`.trim(), value: x.id,
  }))
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  categories.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Employee Expense Claims</h1>
      <Button v-if="canCreate" label="New Claim" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="filters">
      <Select v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="reload" />
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear placeholder="All employees" class="flt" @change="reload" />
      <Select v-model="filters.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="All branches" class="flt" @change="reload" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="reload" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="reload" />
      <TableExportButtons title="Employee Expense Claims" endpoint="/employee-claims"
        :params="() => activeParams()" :columns="[
        { key: 'claim_number', label: 'Claim #' },
        { key: 'claim_date', label: 'Date' },
        { key: 'employee', label: 'Employee' },
        { key: 'title', label: 'Purpose' },
        { key: 'items_count', label: 'Lines' },
        { key: 'total_amount', label: 'Amount' },
        { key: 'status', label: 'Status' },
      ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="claim_number" header="Claim #" />
      <Column field="claim_date" header="Date" />
      <Column header="Employee"><template #body="{ data }">{{ data.employee }}</template></Column>
      <Column header="Purpose"><template #body="{ data }">{{ data.title || '—' }}</template></Column>
      <Column header="Lines" style="text-align: right"><template #body="{ data }">{{ data.items_count ?? '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ currency(data.total_amount) }}</strong></template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <Tag v-if="data.is_paid" severity="success" value="reimbursed" class="paid-tag" />
        </template>
      </Column>
      <Column header="Bill" style="width: 4rem">
        <template #body="{ data }">
          <a v-if="data.attachment_url" :href="data.attachment_url" target="_blank" rel="noopener"><i class="pi pi-paperclip" /></a>
          <span v-else>—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="data.status === 'draft' || data.status === 'rejected'">
            <Button v-if="canEdit" label="Submit" size="small" severity="info" outlined @click="wf.submit(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="wf.remove(data, data.claim_number)" />
          </template>
          <template v-if="data.status === 'pending' && canApprove">
            <Button label="Approve" size="small" severity="success" @click="wf.approve(data)" />
            <Button label="Reject" size="small" severity="danger" outlined @click="wf.reject(data, data.claim_number)" />
          </template>
          <template v-if="data.status === 'approved' && canEdit">
            <Button :label="data.is_paid ? 'Mark Unpaid' : 'Mark Reimbursed'" size="small" :severity="data.is_paid ? 'secondary' : 'success'" outlined @click="wf.togglePaid(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No claims recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Claim' : 'New Claim'" :style="{ width: '820px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Employee *</label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter placeholder="Select employee" />
          <small v-if="errors.employee_id" class="err">{{ errors.employee_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
        <div class="fld">
          <label>Claim Date *</label>
          <input type="date" v-model="form.claim_date" class="date-input" />
          <small v-if="errors.claim_date" class="err">{{ errors.claim_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Purpose / Title</label>
          <InputText v-model="form.title" placeholder="e.g. Client visit — Mumbai" />
        </div>
        <div class="fld">
          <label>Reimbursement Method *</label>
          <Select v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Reference No.</label>
          <InputText v-model="form.reference_no" />
        </div>
      </div>

      <div class="items-head">
        <h3>Claim Items</h3>
        <Button label="Add Item" icon="pi pi-plus" size="small" text @click="addItem" />
      </div>
      <small v-if="errors.items" class="err">{{ errors.items[0] }}</small>
      <table class="items-table">
        <thead>
          <tr><th>Date</th><th>Category</th><th>Description</th><th style="text-align: right">Amount</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(it, i) in form.items" :key="i">
            <td><input type="date" v-model="it.expense_date" class="date-input sm" /></td>
            <td><Select v-model="it.expense_category_id" :options="categories" optionLabel="label" optionValue="value" filter showClear placeholder="—" class="cat-sel" /></td>
            <td><InputText v-model="it.description" placeholder="Details" class="w-full" /></td>
            <td><InputNumber v-model="it.amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="amt" /></td>
            <td><Button icon="pi pi-times" size="small" text severity="danger" @click="removeItem(Number(i))" /></td>
          </tr>
        </tbody>
        <tfoot>
          <tr><td colspan="3" style="text-align: right; font-weight: 600">Total</td><td style="text-align: right; font-weight: 700">{{ currency(claimTotal) }}</td><td></td></tr>
        </tfoot>
      </table>

      <div class="form-grid" style="margin-top: 1rem">
        <div class="fld">
          <label>Supporting Bill</label>
          <input type="file" accept=".jpg,.jpeg,.png,.pdf" @change="onFileChange" class="file-input" />
          <small v-if="existingAttachment" class="hint">Current: <a :href="existingAttachment" target="_blank" rel="noopener">view</a> — choose a file to replace.</small>
        </div>
        <div class="fld">
          <label>Notes</label>
          <Textarea v-model="form.description" rows="2" />
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Claim Details" :fields="[
      { key: 'claim_number', label: 'Claim #' },
      { key: 'claim_date', label: 'Date' },
      { key: 'employee', label: 'Employee' },
      { key: 'title', label: 'Purpose' },
      { key: 'items_count', label: 'Lines' },
      { key: 'total_amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: 'Status' },
      { key: 'is_paid', label: 'Reimbursed' },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 1rem; align-items: center; }
.flt { min-width: 160px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.items-head { display: flex; justify-content: space-between; align-items: center; margin: 1.25rem 0 0.5rem; }
.items-head h3 { font-size: 1rem; font-weight: 600; }
.items-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.items-table th { text-align: left; font-weight: 600; color: #6b7280; padding: 0.35rem 0.4rem; border-bottom: 1px solid #e5e7eb; }
.items-table td { padding: 0.3rem 0.4rem; vertical-align: top; }
.items-table tfoot td { border-top: 1px solid #e5e7eb; padding-top: 0.5rem; }
.cat-sel { min-width: 150px; }
.amt :deep(.p-inputnumber) { width: 140px; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.date-input.sm { padding: 0.4rem 0.5rem; }
.file-input { font-size: 0.85rem; }
.w-full { width: 100%; }
.hint { font-size: 0.78rem; color: #6b7280; }
.err { color: #dc2626; font-size: 0.78rem; }
.paid-tag { margin-left: 0.35rem; }
a { color: var(--p-primary-color, #6366f1); }
</style>
