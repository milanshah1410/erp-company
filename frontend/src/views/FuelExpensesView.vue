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

const auth = useAuthStore()
const crud = useCrud('/fuel-expenses')

const vehicles = ref<any[]>([])
const branches = ref<any[]>([])

const canCreate = computed(() => auth.can('fuel-expenses.create'))
const canApprove = computed(() => auth.can('fuel-expenses.approve'))
const canEdit = computed(() => auth.can('fuel-expenses.edit'))
const canDelete = computed(() => auth.can('fuel-expenses.delete'))

const statusColors: Record<string, string> = { draft: 'secondary', pending: 'warn', approved: 'success', rejected: 'danger' }
const statusOptions = [
  { label: 'Draft', value: 'draft' }, { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' },
]
const paymentMethods = [
  { label: 'Cash', value: 'cash' }, { label: 'Bank Transfer', value: 'bank' }, { label: 'UPI', value: 'upi' },
  { label: 'Card', value: 'card' }, { label: 'Cheque', value: 'cheque' }, { label: 'Other', value: 'other' },
]
const fuelTypes = [
  { label: 'Petrol', value: 'petrol' }, { label: 'Diesel', value: 'diesel' },
  { label: 'CNG', value: 'cng' }, { label: 'Electric', value: 'electric' },
]

function currency(v: any) {
  return `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

const tab = ref<'list' | 'mileage'>('list')
const filters = ref<Record<string, any>>({ status: null, vehicle_id: null, fuel_type: null, date_from: null, date_to: null })
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

const wf = useExpenseWorkflow('/fuel-expenses', reload)

const reportRows = ref<any[]>([])
const reportTotal = ref(0)
const reportLoading = ref(false)
async function loadReport() {
  reportLoading.value = true
  try {
    const { data } = await http.get('/fuel-expenses/report', { params: activeParams() })
    reportRows.value = data.data ?? []
    reportTotal.value = data.total ?? 0
  } finally {
    reportLoading.value = false
  }
}
function switchTab(key: 'list' | 'mileage') {
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
    vehicle_id: null, branch_id: null, fuel_date: today(), fuel_type: 'diesel',
    litres: null, rate_per_litre: null, amount: null, odometer: null, station: '',
    payment_method: 'cash', reference_no: '', description: '',
  }
}
// Auto-fill amount = litres × rate unless the user has overridden it.
watch(() => [form.value.litres, form.value.rate_per_litre], () => {
  const l = Number(form.value.litres) || 0
  const r = Number(form.value.rate_per_litre) || 0
  if (l && r) form.value.amount = Math.round(l * r * 100) / 100
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
  const { data } = await http.get(`/fuel-expenses/${row.id}`)
  const e = data.data
  editingId.value = e.id
  form.value = {
    vehicle_id: e.vehicle_id, branch_id: e.branch_id, fuel_date: e.fuel_date, fuel_type: e.fuel_type,
    litres: Number(e.litres), rate_per_litre: Number(e.rate_per_litre), amount: Number(e.amount),
    odometer: e.odometer, station: e.station || '', payment_method: e.payment_method,
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
    if (id) await http.put(`/fuel-expenses/${id}`, form.value)
    else {
      const { data } = await http.post('/fuel-expenses', form.value)
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
  const [v, b] = await Promise.all([
    http.get('/vehicles', { params: { per_page: 100, is_active: true } }),
    http.get('/branches', { params: { per_page: 100 } }),
  ])
  vehicles.value = (v.data.data ?? v.data).map((x: any) => ({ label: x.name ? `${x.registration_no} — ${x.name}` : x.registration_no, value: x.id }))
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Fuel Expenses</h1>
      <Button v-if="canCreate" label="New Fill" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="tabbar">
      <button class="tab" :class="{ active: tab === 'list' }" @click="switchTab('list')"><i class="pi pi-bolt" /> Fills</button>
      <button class="tab" :class="{ active: tab === 'mileage' }" @click="switchTab('mileage')"><i class="pi pi-gauge" /> Mileage</button>
    </div>

    <div class="filters">
      <Select v-if="tab === 'list'" v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="applyFilters" />
      <Select v-model="filters.vehicle_id" :options="vehicles" optionLabel="label" optionValue="value" filter showClear placeholder="All vehicles" class="flt" @change="applyFilters" />
      <Select v-if="tab === 'list'" v-model="filters.fuel_type" :options="fuelTypes" optionLabel="label" optionValue="value" showClear placeholder="All fuels" class="flt" @change="applyFilters" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="applyFilters" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="applyFilters" />
    </div>

    <DataTable v-if="tab === 'list'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="expense_number" header="Fill #" />
      <Column field="fuel_date" header="Date" />
      <Column header="Vehicle"><template #body="{ data }">{{ data.vehicle }}</template></Column>
      <Column header="Fuel"><template #body="{ data }">{{ data.fuel_type }}</template></Column>
      <Column header="Litres" style="text-align: right"><template #body="{ data }">{{ Number(data.litres).toFixed(2) }}</template></Column>
      <Column header="Rate" style="text-align: right"><template #body="{ data }">{{ currency(data.rate_per_litre) }}</template></Column>
      <Column header="Odometer"><template #body="{ data }">{{ data.odometer ?? '—' }}</template></Column>
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
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="wf.remove(data, data.expense_number)" />
          </template>
          <template v-if="data.status === 'pending' && canApprove">
            <Button label="Approve" size="small" severity="success" @click="wf.approve(data)" />
            <Button label="Reject" size="small" severity="danger" outlined @click="wf.reject(data, data.expense_number)" />
          </template>
          <template v-if="data.status === 'approved' && canEdit">
            <Button :label="data.is_paid ? 'Mark Unpaid' : 'Mark Paid'" size="small" :severity="data.is_paid ? 'secondary' : 'success'" outlined @click="wf.togglePaid(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No fuel fills recorded.</template>
    </DataTable>

    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="vehicle_id" class="p-datatable-sm">
      <Column header="Vehicle"><template #body="{ data }">{{ data.vehicle }}</template></Column>
      <Column field="fills" header="Fills" style="text-align: right" />
      <Column header="Litres" style="text-align: right"><template #body="{ data }">{{ Number(data.total_litres).toFixed(2) }}</template></Column>
      <Column header="Distance (km)" style="text-align: right"><template #body="{ data }">{{ data.distance_km ?? '—' }}</template></Column>
      <Column header="Mileage (km/l)" style="text-align: right"><template #body="{ data }"><strong>{{ data.mileage_kmpl ?? '—' }}</strong></template></Column>
      <Column header="Total" style="text-align: right"><template #body="{ data }">{{ currency(data.total_amount) }}</template></Column>
      <template #empty>No approved fills in range.</template>
      <template #footer><div class="rpt-total">Total: {{ currency(reportTotal) }}</div></template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Fuel Fill' : 'New Fuel Fill'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Vehicle *</label>
          <Select v-model="form.vehicle_id" :options="vehicles" optionLabel="label" optionValue="value" filter placeholder="Select vehicle" />
          <small v-if="errors.vehicle_id" class="err">{{ errors.vehicle_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
        <div class="fld">
          <label>Date *</label>
          <input type="date" v-model="form.fuel_date" class="date-input" />
          <small v-if="errors.fuel_date" class="err">{{ errors.fuel_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Fuel Type *</label>
          <Select v-model="form.fuel_type" :options="fuelTypes" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Litres *</label>
          <InputNumber v-model="form.litres" :minFractionDigits="2" :maxFractionDigits="3" class="w-full" />
          <small v-if="errors.litres" class="err">{{ errors.litres[0] }}</small>
        </div>
        <div class="fld">
          <label>Rate / Litre *</label>
          <InputNumber v-model="form.rate_per_litre" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" />
          <small v-if="errors.rate_per_litre" class="err">{{ errors.rate_per_litre[0] }}</small>
        </div>
        <div class="fld">
          <label>Amount *</label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" />
          <small v-if="errors.amount" class="err">{{ errors.amount[0] }}</small>
        </div>
        <div class="fld">
          <label>Odometer (km)</label>
          <InputNumber v-model="form.odometer" :useGrouping="false" class="w-full" />
        </div>
        <div class="fld">
          <label>Fuel Station</label>
          <InputText v-model="form.station" placeholder="e.g. HP, IOC pump" />
        </div>
        <div class="fld">
          <label>Payment Method *</label>
          <Select v-model="form.payment_method" :options="paymentMethods" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Reference No.</label>
          <InputText v-model="form.reference_no" placeholder="Bill / txn no." />
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
