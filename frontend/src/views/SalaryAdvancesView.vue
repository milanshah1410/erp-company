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
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const employees = ref<{ label: string; value: number }[]>([])
const STATUSES = [
  { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' },
  { label: 'Recovering', value: 'recovering' }, { label: 'Closed', value: 'closed' }, { label: 'Rejected', value: 'rejected' },
]
const filters = ref<{ employee_id: number | null; status: string | null }>({ employee_id: null, status: null })

const canCreate = computed(() => auth.can('salary-advances.create'))
const canEdit = computed(() => auth.can('salary-advances.edit'))
const canApprove = computed(() => auth.can('salary-advances.approve'))
const canDelete = computed(() => auth.can('salary-advances.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ employee_id: null, date: new Date(), amount: null, monthly_recovery: null, reason: '' })

function money(v: any) { return formatCurrency(v) }
function statusSeverity(s: string) { return { pending: 'warn', approved: 'info', recovering: 'contrast', closed: 'success', rejected: 'danger' }[s] || 'secondary' }
function fieldError(key: string): string | null { return errors.value[key]?.[0] ?? null }
function toDateString(d: Date | null): string | undefined { return d ? d.toISOString().slice(0, 10) : undefined }

async function loadEmployees() {
  const { data } = await http.get('/employees', { params: { per_page: 200, is_active: true } })
  employees.value = (data.data ?? data).map((e: any) => ({ label: e.name, value: e.id }))
}
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salary-advances', {
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
  form.value = { employee_id: filters.value.employee_id, date: new Date(), amount: null, monthly_recovery: null, reason: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = { employee_id: row.employee_id, date: row.date ? new Date(row.date) : new Date(), amount: Number(row.amount), monthly_recovery: Number(row.monthly_recovery), reason: row.reason || '' }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { employee_id: form.value.employee_id, date: toDateString(form.value.date), amount: form.value.amount, monthly_recovery: form.value.monthly_recovery, reason: form.value.reason || undefined }
    if (editingId.value) {
      await http.put(`/salary-advances/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Advance updated', life: 2500 })
    } else {
      await http.post('/salary-advances', payload)
      toast.add({ severity: 'success', summary: 'Advance added', life: 2500 })
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
    await http.post(`/salary-advances/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({ title: 'Delete this advance?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/salary-advances/${row.id}`)
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
    <PageHeader title="Salary Advances">
      <template #actions>
        <Button v-if="canCreate" label="New Advance" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear placeholder="All employees" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear placeholder="All statuses" @change="load" />
      <TableExportButtons title="Salary Advances" endpoint="/salary-advances"
        :params="() => ({ employee_id: filters.employee_id || undefined, status: filters.status || undefined })"
        :columns="[
          { key: 'date', label: 'Date' },
          { key: 'employee_name', label: 'Employee' },
          { key: 'amount', label: 'Amount' },
          { key: 'monthly_recovery', label: 'Monthly Recovery' },
          { key: 'recovered', label: 'Recovered' },
          { key: 'outstanding', label: 'Outstanding' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column field="employee_name" header="Employee" />
      <Column header="Amount"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
      <Column header="Monthly Recovery"><template #body="{ data }">{{ money(data.monthly_recovery) }}</template></Column>
      <Column header="Recovered"><template #body="{ data }">{{ money(data.recovered) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template></Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canApprove && data.status === 'pending'" label="Approve" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canEdit && ['pending', 'approved'].includes(data.status)" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && Number(data.recovered) === 0" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No advances recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Advance' : 'New Advance'" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>Employee <span class="req">*</span></label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter placeholder="Select employee" :class="{ invalid: fieldError('employee_id') }" />
          <small v-if="fieldError('employee_id')" class="error-text">{{ fieldError('employee_id') }}</small>
        </div>
        <div class="field">
          <label>Date <span class="req">*</span></label>
          <DatePicker v-model="form.date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="row">
          <div class="field">
            <label>Amount <span class="req">*</span></label>
            <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0" :class="{ invalid: fieldError('amount') }" />
            <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
          </div>
          <div class="field">
            <label>Monthly Recovery <span class="req">*</span></label>
            <InputNumber v-model="form.monthly_recovery" mode="currency" currency="INR" locale="en-IN" :min="0" :class="{ invalid: fieldError('monthly_recovery') }" />
            <small v-if="fieldError('monthly_recovery')" class="error-text">{{ fieldError('monthly_recovery') }}</small>
          </div>
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

    <DetailDialog
      v-model:visible="detailVisible"
      title="Salary Advance"
      :record="detailRecord"
      :fields="[
        { label: 'Date', key: 'date' },
        { label: 'Employee', key: 'employee_name' },
        { label: 'Amount', key: 'amount', format: (v) => money(v) },
        { label: 'Monthly Recovery', key: 'monthly_recovery', format: (v) => money(v) },
        { label: 'Recovered', key: 'recovered', format: (v) => money(v) },
        { label: 'Outstanding', key: 'outstanding', format: (v) => money(v) },
        { label: 'Status', key: 'status' },
        { label: 'Reason', key: 'reason', full: true },
      ]"
    />
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
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
