<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

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
const leaveTypes = ref<{ label: string; value: number }[]>([])

const STATUSES = [
  { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' }, { label: 'Cancelled', value: 'cancelled' },
]

const filters = ref<{ employee_id: number | null; status: string | null }>({ employee_id: null, status: null })

const canCreate = computed(() => auth.can('leave-applications.create'))
const canEdit = computed(() => auth.can('leave-applications.edit'))
const canApprove = computed(() => auth.can('leave-applications.approve'))
const canDelete = computed(() => auth.can('leave-applications.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ employee_id: null, leave_type_id: null, from_date: new Date(), to_date: new Date(), half_day: false, reason: '' })

function statusSeverity(s: string) {
  return { pending: 'warn', approved: 'success', rejected: 'danger', cancelled: 'secondary' }[s] || 'secondary'
}
function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}
function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

async function loadOptions() {
  const [emp, types] = await Promise.all([
    http.get('/employees', { params: { per_page: 200, is_active: true } }),
    http.get('/leave-types', { params: { per_page: 200, is_active: true } }),
  ])
  employees.value = (emp.data.data ?? emp.data).map((e: any) => ({ label: e.name, value: e.id }))
  leaveTypes.value = (types.data.data ?? types.data).map((t: any) => ({ label: t.name, value: t.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/leave-applications', {
      params: {
        employee_id: filters.value.employee_id || undefined,
        status: filters.value.status || undefined,
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

function openCreate() {
  editingId.value = null
  form.value = { employee_id: filters.value.employee_id, leave_type_id: null, from_date: new Date(), to_date: new Date(), half_day: false, reason: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    employee_id: row.employee_id, leave_type_id: row.leave_type_id,
    from_date: row.from_date ? new Date(row.from_date) : new Date(),
    to_date: row.to_date ? new Date(row.to_date) : new Date(),
    half_day: row.half_day, reason: row.reason || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      employee_id: form.value.employee_id,
      leave_type_id: form.value.leave_type_id,
      from_date: toDateString(form.value.from_date),
      to_date: form.value.half_day ? toDateString(form.value.from_date) : toDateString(form.value.to_date),
      half_day: form.value.half_day,
      reason: form.value.reason || undefined,
    }
    if (editingId.value) {
      await http.put(`/leave-applications/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Leave updated', life: 2500 })
    } else {
      await http.post('/leave-applications', payload)
      toast.add({ severity: 'success', summary: 'Leave applied', life: 2500 })
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
    await http.post(`/leave-applications/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function reject(row: any) {
  const res = await Swal.fire({
    title: 'Reject this leave?', input: 'text', inputLabel: 'Reason (optional)',
    showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Reject',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/leave-applications/${row.id}/reject`, { remarks: res.value || undefined })
    toast.add({ severity: 'success', summary: 'Rejected', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({ title: 'Delete this leave request?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/leave-applications/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Leave Applications</h1>
      <Button v-if="canCreate" label="Apply Leave" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear
        placeholder="All employees" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="load" />
      <TableExportButtons title="Leave Applications" endpoint="/leave-applications"
        :params="() => ({ ...(filters.employee_id ? { employee_id: filters.employee_id } : {}), ...(filters.status ? { status: filters.status } : {}) })"
        :columns="[
          { key: 'employee_name', label: 'Employee' },
          { key: 'leave_type_name', label: 'Type' },
          { key: 'from_date', label: 'From' },
          { key: 'to_date', label: 'To' },
          { key: 'days', label: 'Days' },
          { key: 'reason', label: 'Reason' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="employee_name" header="Employee" />
      <Column field="leave_type_name" header="Type" />
      <Column field="from_date" header="From" />
      <Column field="to_date" header="To" />
      <Column field="days" header="Days" />
      <Column field="reason" header="Reason" />
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 15rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canApprove && data.status === 'pending'" label="Approve" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canApprove && data.status === 'pending'" label="Reject" icon="pi pi-times" text severity="danger" size="small" @click="reject(data)" />
          <Button v-if="canEdit && data.status === 'pending'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && data.status !== 'approved'" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No leave applications.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Leave' : 'Apply Leave'" :style="{ width: '480px' }">
      <div class="form">
        <div class="field">
          <label>Employee <span class="req">*</span></label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter
            placeholder="Select employee" :class="{ invalid: fieldError('employee_id') }" />
          <small v-if="fieldError('employee_id')" class="error-text">{{ fieldError('employee_id') }}</small>
        </div>
        <div class="field">
          <label>Leave Type <span class="req">*</span></label>
          <Select v-model="form.leave_type_id" :options="leaveTypes" optionLabel="label" optionValue="value"
            placeholder="Select leave type" :class="{ invalid: fieldError('leave_type_id') }" />
          <small v-if="fieldError('leave_type_id')" class="error-text">{{ fieldError('leave_type_id') }}</small>
        </div>
        <div class="field checkbox-row">
          <Checkbox v-model="form.half_day" binary inputId="halfDay" />
          <label for="halfDay">Half day</label>
        </div>
        <div class="row">
          <div class="field">
            <label>From <span class="req">*</span></label>
            <DatePicker v-model="form.from_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
          <div class="field" v-if="!form.half_day">
            <label>To <span class="req">*</span></label>
            <DatePicker v-model="form.to_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
        </div>
        <div class="field">
          <label>Reason</label>
          <Textarea v-model="form.reason" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Apply'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Leave Application Details" :fields="[
      { key: 'employee_name', label: 'Employee' },
      { key: 'leave_type_name', label: 'Leave Type' },
      { key: 'from_date', label: 'From' },
      { key: 'to_date', label: 'To' },
      { key: 'days', label: 'Days' },
      { key: 'half_day', label: 'Half Day' },
      { key: 'status', label: 'Status' },
      { key: 'reason', label: 'Reason', full: true },
    ]" />
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
.checkbox-row { flex-direction: row; align-items: center; gap: 0.5rem; }
.field :deep(.p-select), .field :deep(.p-datepicker), .field :deep(.p-inputtext) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
