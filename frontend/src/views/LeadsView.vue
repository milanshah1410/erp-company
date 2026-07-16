<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const canCreate = computed(() => auth.can('leads.create'))
const canEdit = computed(() => auth.can('leads.edit'))
const canDelete = computed(() => auth.can('leads.delete'))

const crud = ref(useCrud('/leads'))
const search = ref('')
const statusFilter = ref<string | null>(null)
const stateFilter = ref<string | null>('open')
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

const statusOptions = [
  { label: 'New', value: 'new' },
  { label: 'Contacted', value: 'contacted' },
  { label: 'Qualified', value: 'qualified' },
  { label: 'Proposal', value: 'proposal' },
  { label: 'Won', value: 'won' },
  { label: 'Lost', value: 'lost' },
]

const statusColors: Record<string, string> = {
  new: 'secondary',
  contacted: 'info',
  qualified: 'warn',
  proposal: 'warn',
  won: 'success',
  lost: 'danger',
}

const sourceOptions = [
  { label: 'Walk-in', value: 'walk-in' },
  { label: 'Referral', value: 'referral' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Website', value: 'website' },
  { label: 'Phone', value: 'phone' },
  { label: 'Exhibition', value: 'exhibition' },
  { label: 'Other', value: 'other' },
]

const stateOptions = [
  { label: 'Open pipeline', value: 'open' },
  { label: 'Converted', value: 'converted' },
  { label: 'All', value: null },
]

// Users for the "assigned to" dropdown.
const users = ref<any[]>([])
async function loadUsers() {
  try {
    const { data } = await http.get('/users', { params: { per_page: 100 } })
    users.value = (data.data ?? []).map((u: any) => ({ label: u.name, value: u.id }))
  } catch {
    /* non-blocking */
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  if (stateFilter.value) params.state = stateFilter.value
  if (sortField.value && sortOrder.value) {
    params.sort = sortField.value
    params.direction = sortOrder.value === 1 ? 'asc' : 'desc'
  }
  crud.value.fetch(params)
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  crud.value.currentPage = 1
  reload()
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    name: '', company_name: '', email: '', phone: '',
    source: null, status: 'new', estimated_value: 0,
    expected_close_date: '', assigned_to: null, notes: '', lost_reason: '',
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    name: row.name,
    company_name: row.company_name || '',
    email: row.email || '',
    phone: row.phone || '',
    source: row.source ?? null,
    status: row.status || 'new',
    estimated_value: Number(row.estimated_value ?? 0),
    expected_close_date: row.expected_close_date || '',
    assigned_to: row.assigned_to ?? null,
    notes: row.notes || '',
    lost_reason: row.lost_reason || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value }
    if (!payload.expected_close_date) delete payload.expected_close_date
    if (editing.value) {
      await crud.value.update(editing.value.id, payload)
      toast.add({ severity: 'success', summary: 'Lead updated', life: 2500 })
    } else {
      await crud.value.create(payload)
      toast.add({ severity: 'success', summary: 'Lead created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete lead "${row.name}"?`,
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await crud.value.remove(row.id)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Convert dialog ---
const convertVisible = ref(false)
const converting = ref(false)
const convertLead = ref<any>(null)
const convertForm = ref<any>({ type: 'retail', customer_category_id: null, credit_limit: 0 })
const categories = ref<any[]>([])

const typeOptions = [
  { label: 'Retail', value: 'retail' },
  { label: 'Wholesale', value: 'wholesale' },
  { label: 'Distributor', value: 'distributor' },
]

async function loadCategories() {
  try {
    const { data } = await http.get('/customer-categories', { params: { per_page: 100, is_active: true } })
    categories.value = (data.data ?? []).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {
    /* non-blocking */
  }
}

function openConvert(row: any) {
  convertLead.value = row
  convertForm.value = { type: 'retail', customer_category_id: null, credit_limit: 0 }
  if (!categories.value.length) loadCategories()
  convertVisible.value = true
}

async function doConvert() {
  if (!convertLead.value) return
  converting.value = true
  try {
    const { data } = await http.post(`/leads/${convertLead.value.id}/convert`, convertForm.value)
    toast.add({ severity: 'success', summary: 'Converted to customer', detail: data.data?.code, life: 3000 })
    convertVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Conversion failed', life: 4000 })
  } finally {
    converting.value = false
  }
}

onMounted(() => {
  loadUsers()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Leads</h1>
      <Button v-if="canCreate" label="New Lead" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search name / phone / code..." @keyup.enter="reload" />
      </span>
      <Select
        v-model="stateFilter" :options="stateOptions" optionLabel="label" optionValue="value"
        placeholder="Pipeline" class="filter" @change="reload"
      />
      <Select
        v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
        placeholder="All statuses" showClear class="filter" @change="reload"
      />
      <Button label="Search" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="Leads" endpoint="/leads"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}), ...(stateFilter ? { state: stateFilter } : {}) })"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Name' },
          { key: 'company_name', label: 'Company' },
          { key: 'phone', label: 'Phone' },
          { key: 'source', label: 'Source' },
          { key: 'estimated_value', label: 'Est. Value' },
          { key: 'status', label: 'Status' },
          { key: 'assignee.name', label: 'Assigned' },
        ]" />
    </div>

    <DataTable
      :value="crud.items"
      :loading="crud.loading"
      lazy
      paginator
      :rows="crud.perPage"
      :totalRecords="crud.total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      removableSort
      :sortField="sortField ?? undefined"
      :sortOrder="sortOrder ?? undefined"
      @sort="onSort"
      class="p-datatable-sm"
    >
      <Column field="code" header="Code" sortable style="width: 8rem" />
      <Column field="name" header="Name" sortable>
        <template #body="{ data }">
          <span>{{ data.name }}</span>
          <span v-if="data.company_name" class="muted-sub">{{ data.company_name }}</span>
        </template>
      </Column>
      <Column field="phone" header="Phone" />
      <Column field="source" header="Source" />
      <Column header="Est. Value" style="text-align: right">
        <template #body="{ data }">{{ money(data.estimated_value) }}</template>
      </Column>
      <Column field="status" header="Status" sortable>
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <span v-if="data.is_converted" class="muted-sub">→ customer</span>
        </template>
      </Column>
      <Column header="Assigned">
        <template #body="{ data }">{{ data.assignee?.name ?? '—' }}</template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button
            v-if="canEdit && !data.is_converted"
            icon="pi pi-user-plus" text rounded size="small" severity="success"
            title="Convert to customer" @click="openConvert(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No leads found.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Lead' : 'New Lead'" :style="{ width: '620px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="fld">
          <label>Company Name</label>
          <InputText v-model="form.company_name" />
        </div>
        <div class="fld">
          <label>Email</label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>
        <div class="fld">
          <label>Phone</label>
          <InputText v-model="form.phone" />
        </div>
        <div class="fld">
          <label>Source</label>
          <Select v-model="form.source" :options="sourceOptions" optionLabel="label" optionValue="value" placeholder="Select source" showClear />
        </div>
        <div class="fld">
          <label>Status</label>
          <Select v-model="form.status" :options="statusOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Estimated Value</label>
          <InputNumber v-model="form.estimated_value" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>Expected Close Date</label>
          <InputText type="date" v-model="form.expected_close_date" />
        </div>
        <div class="fld">
          <label>Assigned To</label>
          <Select v-model="form.assigned_to" :options="users" optionLabel="label" optionValue="value" placeholder="Unassigned" showClear filter />
        </div>
        <div v-if="form.status === 'lost'" class="fld">
          <label>Lost Reason</label>
          <InputText v-model="form.lost_reason" />
        </div>
        <div class="fld full">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Convert dialog -->
    <Dialog v-model:visible="convertVisible" modal header="Convert Lead to Customer" :style="{ width: '440px' }">
      <p class="muted small">
        Creating a customer from <strong>{{ convertLead?.name }}</strong>. The lead will be marked
        <strong>won</strong> and any open follow-ups move to the new customer.
      </p>
      <div class="form-grid">
        <div class="fld">
          <label>Customer Type</label>
          <Select v-model="convertForm.type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Category</label>
          <Select v-model="convertForm.customer_category_id" :options="categories" optionLabel="label" optionValue="value" placeholder="None" showClear />
        </div>
        <div class="fld full">
          <label>Credit Limit</label>
          <InputNumber v-model="convertForm.credit_limit" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="convertVisible = false" />
        <Button label="Convert" icon="pi pi-user-plus" :loading="converting" @click="doConvert" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Lead Details" :fields="[
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'company_name', label: 'Company' },
      { key: 'email', label: 'Email' },
      { key: 'phone', label: 'Phone' },
      { key: 'source', label: 'Source' },
      { key: 'status', label: 'Status' },
      { key: 'estimated_value', label: 'Estimated Value', format:(v)=>v==null||v===''?'—':'₹'+Number(v).toLocaleString('en-IN',{minimumFractionDigits:2}) },
      { key: 'expected_close_date', label: 'Expected Close' },
      { key: 'assignee.name', label: 'Assigned To' },
      { key: 'lost_reason', label: 'Lost Reason' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar-spacer { flex: 1 1 auto; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 280px; }
.filter { min-width: 160px; }
.muted-sub { display: block; color: #6b7280; font-size: 0.78rem; }
.muted.small { color: #6b7280; font-size: 0.82rem; margin: 0 0 0.75rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber),
.fld :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
