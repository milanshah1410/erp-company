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
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const employees = ref<{ label: string; value: number }[]>([])
const TYPES = computed(() => [
  { label: pt('Performance'), value: 'performance' }, { label: pt('Festival'), value: 'festival' },
  { label: pt('Diwali'), value: 'diwali' }, { label: pt('Statutory'), value: 'statutory' }, { label: pt('Other'), value: 'other' },
])
const STATUSES = computed(() => [
  { label: pt('Pending'), value: 'pending' }, { label: pt('Approved'), value: 'approved' },
  { label: pt('Rejected'), value: 'rejected' }, { label: pt('Paid'), value: 'paid' },
])
const filters = ref<{ employee_id: number | null; status: string | null }>({ employee_id: null, status: null })

const canCreate = computed(() => auth.can('employee-bonuses.create'))
const canEdit = computed(() => auth.can('employee-bonuses.edit'))
const canApprove = computed(() => auth.can('employee-bonuses.approve'))
const canDelete = computed(() => auth.can('employee-bonuses.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ employee_id: null, month: new Date(), type: 'performance', amount: null, remarks: '' })

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function money(v: any) { return formatCurrency(v) }
function statusSeverity(s: string) { return { pending: 'warn', approved: 'info', rejected: 'danger', paid: 'success' }[s] || 'secondary' }
function fieldError(key: string): string | null { return errors.value[key]?.[0] ?? null }
function monthString(d: Date | null): string | undefined { return d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01` : undefined }

async function loadEmployees() {
  const { data } = await http.get('/employees', { params: { per_page: 200, is_active: true } })
  employees.value = (data.data ?? data).map((e: any) => ({ label: e.name, value: e.id }))
}
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/employee-bonuses', {
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
  form.value = { employee_id: filters.value.employee_id, month: new Date(), type: 'performance', amount: null, remarks: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = { employee_id: row.employee_id, month: row.month ? new Date(row.month) : new Date(), type: row.type, amount: Number(row.amount), remarks: row.remarks || '' }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { employee_id: form.value.employee_id, month: monthString(form.value.month), type: form.value.type, amount: form.value.amount, remarks: form.value.remarks || undefined }
    if (editingId.value) {
      await http.put(`/employee-bonuses/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Bonus updated'), life: 2500 })
    } else {
      await http.post('/employee-bonuses', payload)
      toast.add({ severity: 'success', summary: pt('Bonus added'), life: 2500 })
    }
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function approve(row: any) {
  try {
    await http.post(`/employee-bonuses/${row.id}/approve`)
    toast.add({ severity: 'success', summary: pt('Approved'), life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({ title: pt('Delete this bonus?'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: pt('Delete'), cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/employee-bonuses/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => { await loadEmployees(); await load() })
</script>

<template>
  <div>
    <PageHeader :title="pt('Employee Bonuses')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Bonus')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear :placeholder="pt('All employees')" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear :placeholder="pt('All statuses')" @change="load" />
      <TableExportButtons :title="pt('Employee Bonuses')" endpoint="/employee-bonuses"
        :params="() => ({ employee_id: filters.employee_id || undefined, status: filters.status || undefined })" :columns="[
        { key: 'month', label: pt('Month') },
        { key: 'employee_name', label: pt('Employee') },
        { key: 'type', label: pt('Type') },
        { key: 'amount', label: pt('Amount') },
        { key: 'remarks', label: pt('Remarks') },
        { key: 'status', label: pt('Status') },
      ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="month" :header="pt('Month')" />
      <Column field="employee_name" :header="pt('Employee')" />
      <Column field="type" :header="pt('Type')"><template #body="{ data }"><span class="cap">{{ data.type }}</span></template></Column>
      <Column :header="pt('Amount')"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
      <Column field="remarks" :header="pt('Remarks')" />
      <Column :header="pt('Status')"><template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template></Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canApprove && data.status === 'pending'" :label="pt('Approve')" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canEdit && data.status === 'pending'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && ['pending', 'rejected'].includes(data.status)" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No bonuses recorded.') }}</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? pt('Edit Bonus') : pt('New Bonus')" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>{{ pt('Employee') }} <span class="req">*</span></label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter :placeholder="pt('Select employee')" :class="{ invalid: fieldError('employee_id') }" />
          <small v-if="fieldError('employee_id')" class="error-text">{{ fieldError('employee_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>{{ pt('Month') }} <span class="req">*</span></label>
            <DatePicker v-model="form.month" view="month" dateFormat="yy-mm" showIcon iconDisplay="input" />
          </div>
          <div class="field">
            <label>{{ pt('Type') }}</label>
            <Select v-model="form.type" :options="TYPES" optionLabel="label" optionValue="value" />
          </div>
        </div>
        <div class="field">
          <label>{{ pt('Amount') }} <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0" :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>{{ pt('Remarks') }}</label>
          <Textarea v-model="form.remarks" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editingId ? pt('Update') : pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Bonus Details')" :fields="[
      { key: 'month', label: pt('Month') },
      { key: 'employee_name', label: pt('Employee') },
      { key: 'type', label: pt('Type') },
      { key: 'amount', label: pt('Amount'), format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: pt('Status') },
      { key: 'remarks', label: pt('Remarks'), full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.w-60 { width: 240px; }
.cap { text-transform: capitalize; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.field :deep(.p-inputnumber), .field :deep(.p-select), .field :deep(.p-datepicker), .field :deep(.p-inputtext) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
