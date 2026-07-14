<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const employees = ref<{ label: string; value: number }[]>([])
const STATUSES = [
  { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' },
  { label: 'Disbursed', value: 'disbursed' }, { label: 'Closed', value: 'closed' }, { label: 'Rejected', value: 'rejected' },
]
const filters = ref<{ employee_id: number | null; status: string | null }>({ employee_id: null, status: null })

const canCreate = computed(() => auth.can('employee-loans.create'))
const canEdit = computed(() => auth.can('employee-loans.edit'))
const canApprove = computed(() => auth.can('employee-loans.approve'))
const canDelete = computed(() => auth.can('employee-loans.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ employee_id: null, principal: null, interest_rate: 0, tenure_months: 12, disbursed_on: new Date(), reason: '' })

const scheduleVisible = ref(false)
const scheduleLoan = ref<any>(null)

// Flat-rate EMI preview mirroring the backend amortization.
const emiPreview = computed(() => {
  const p = Number(form.value.principal) || 0
  const r = Number(form.value.interest_rate) || 0
  const t = Math.max(1, Number(form.value.tenure_months) || 1)
  const interest = p * (r / 100) * (t / 12)
  return { total: p + interest, emi: (p + interest) / t }
})

function money(v: any) { return formatCurrency(v) }
function statusSeverity(s: string) { return { pending: 'warn', approved: 'info', disbursed: 'contrast', closed: 'success', rejected: 'danger' }[s] || 'secondary' }
function fieldError(key: string): string | null { return errors.value[key]?.[0] ?? null }
function toDateString(d: Date | null): string | undefined { return d ? d.toISOString().slice(0, 10) : undefined }

async function loadEmployees() {
  const { data } = await http.get('/employees', { params: { per_page: 200, is_active: true } })
  employees.value = (data.data ?? data).map((e: any) => ({ label: e.name, value: e.id }))
}
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/employee-loans', {
      params: { employee_id: filters.value.employee_id || undefined, status: filters.value.status || undefined, page: page.value, per_page: perPage.value },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}
function onPage(e: any) { page.value = e.page + 1; perPage.value = e.rows; load() }

function openCreate() {
  editingId.value = null
  form.value = { employee_id: filters.value.employee_id, principal: null, interest_rate: 0, tenure_months: 12, disbursed_on: new Date(), reason: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = { employee_id: row.employee_id, principal: Number(row.principal), interest_rate: Number(row.interest_rate), tenure_months: Number(row.tenure_months), disbursed_on: row.disbursed_on ? new Date(row.disbursed_on) : new Date(), reason: row.reason || '' }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { employee_id: form.value.employee_id, principal: form.value.principal, interest_rate: form.value.interest_rate, tenure_months: form.value.tenure_months, disbursed_on: toDateString(form.value.disbursed_on), reason: form.value.reason || undefined }
    if (editingId.value) {
      await http.put(`/employee-loans/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Loan updated', life: 2500 })
    } else {
      await http.post('/employee-loans', payload)
      toast.add({ severity: 'success', summary: 'Loan created', life: 2500 })
    }
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function approve(row: any) {
  try {
    await http.post(`/employee-loans/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function disburse(row: any) {
  const res = await Swal.fire({ title: 'Disburse this loan?', text: 'The EMI repayment schedule will be generated and recovered via payroll.', icon: 'question', showCancelButton: true, confirmButtonText: 'Disburse' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/employee-loans/${row.id}/disburse`)
    toast.add({ severity: 'success', summary: 'Disbursed', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function viewSchedule(row: any) {
  try {
    const { data } = await http.get(`/employee-loans/${row.id}`)
    scheduleLoan.value = data.data ?? data
    scheduleVisible.value = true
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load schedule', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({ title: 'Delete this loan?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/employee-loans/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => { await loadEmployees(); await load() })
</script>

<template>
  <div>
    <div class="header">
      <h1>Employee Loans</h1>
      <Button v-if="canCreate" label="New Loan" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear placeholder="All employees" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear placeholder="All statuses" @change="load" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="loan_number" header="Loan #" />
      <Column field="employee_name" header="Employee" />
      <Column header="Principal"><template #body="{ data }">{{ money(data.principal) }}</template></Column>
      <Column header="EMI"><template #body="{ data }">{{ money(data.emi) }}</template></Column>
      <Column field="tenure_months" header="Tenure" />
      <Column header="Repaid"><template #body="{ data }">{{ money(data.repaid) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template></Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button v-if="canApprove && data.status === 'pending'" label="Approve" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canEdit && ['approved', 'pending'].includes(data.status)" label="Disburse" icon="pi pi-send" text severity="info" size="small" @click="disburse(data)" />
          <Button v-if="['disbursed', 'closed'].includes(data.status)" icon="pi pi-list" text rounded size="small" @click="viewSchedule(data)" v-tooltip.top="'Schedule'" />
          <Button v-if="canEdit && data.status === 'pending'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && Number(data.repaid) === 0" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No loans recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Loan' : 'New Loan'" :style="{ width: '480px' }">
      <div class="form">
        <div class="field">
          <label>Employee <span class="req">*</span></label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter placeholder="Select employee" :class="{ invalid: fieldError('employee_id') }" />
          <small v-if="fieldError('employee_id')" class="error-text">{{ fieldError('employee_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Principal <span class="req">*</span></label>
            <InputNumber v-model="form.principal" mode="currency" currency="INR" locale="en-IN" :min="0" :class="{ invalid: fieldError('principal') }" />
            <small v-if="fieldError('principal')" class="error-text">{{ fieldError('principal') }}</small>
          </div>
          <div class="field">
            <label>Interest Rate (% p.a.)</label>
            <InputNumber v-model="form.interest_rate" :minFractionDigits="0" :maxFractionDigits="2" :min="0" />
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>Tenure (months) <span class="req">*</span></label>
            <InputNumber v-model="form.tenure_months" :min="1" :max="600" :class="{ invalid: fieldError('tenure_months') }" />
            <small v-if="fieldError('tenure_months')" class="error-text">{{ fieldError('tenure_months') }}</small>
          </div>
          <div class="field">
            <label>Disbursed On <span class="req">*</span></label>
            <DatePicker v-model="form.disbursed_on" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
        </div>
        <div class="preview-box">
          <div><span>EMI</span><strong>{{ money(emiPreview.emi) }}</strong></div>
          <div><span>Total Payable</span><strong>{{ money(emiPreview.total) }}</strong></div>
        </div>
        <div class="field">
          <label>Reason</label>
          <Textarea v-model="form.reason" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Save'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog v-model:visible="scheduleVisible" modal :header="`Repayment Schedule — ${scheduleLoan?.loan_number || ''}`" :style="{ width: '520px' }">
      <DataTable :value="scheduleLoan?.repayments || []" class="p-datatable-sm" dataKey="id">
        <Column field="installment_no" header="#" />
        <Column field="due_date" header="Due Date" />
        <Column header="Amount"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
        <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="data.status === 'paid' ? 'success' : 'warn'" /></template></Column>
        <Column field="paid_on" header="Paid On" />
        <template #empty>No schedule generated yet.</template>
      </DataTable>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.w-60 { width: 240px; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.field :deep(.p-inputnumber), .field :deep(.p-select), .field :deep(.p-datepicker), .field :deep(.p-inputtext) { width: 100%; }
.preview-box { display: flex; gap: 1.5rem; background: #f3f4f6; border-radius: 8px; padding: 0.75rem 1rem; }
.preview-box div { display: flex; flex-direction: column; }
.preview-box span { font-size: 0.75rem; color: #6b7280; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
