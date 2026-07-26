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
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()
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

const statusOptions = computed(() => [
  { label: pt('New'), value: 'new' },
  { label: pt('Contacted'), value: 'contacted' },
  { label: pt('Qualified'), value: 'qualified' },
  { label: pt('Proposal'), value: 'proposal' },
  { label: pt('Won'), value: 'won' },
  { label: pt('Lost'), value: 'lost' },
])

const statusColors: Record<string, string> = {
  new: 'secondary',
  contacted: 'info',
  qualified: 'warn',
  proposal: 'warn',
  won: 'success',
  lost: 'danger',
}

const sourceOptions = computed(() => [
  { label: pt('Walk-in'), value: 'walk-in' },
  { label: pt('Referral'), value: 'referral' },
  { label: pt('WhatsApp'), value: 'whatsapp' },
  { label: pt('Website'), value: 'website' },
  { label: pt('Phone'), value: 'phone' },
  { label: pt('Exhibition'), value: 'exhibition' },
  { label: pt('Other'), value: 'other' },
])

const stateOptions = computed(() => [
  { label: pt('Open pipeline'), value: 'open' },
  { label: pt('Converted'), value: 'converted' },
  { label: pt('All'), value: null },
])

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
      toast.add({ severity: 'success', summary: pt('Lead updated'), life: 2500 })
    } else {
      await crud.value.create(payload)
      toast.add({ severity: 'success', summary: pt('Lead created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: pt('Delete lead "{name}"?', { name: row.name }),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await crud.value.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
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

const typeOptions = computed(() => [
  { label: pt('Retail'), value: 'retail' },
  { label: pt('Wholesale'), value: 'wholesale' },
  { label: pt('Distributor'), value: 'distributor' },
])

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
    toast.add({ severity: 'success', summary: pt('Converted to customer'), detail: data.data?.code, life: 3000 })
    convertVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Conversion failed'), life: 4000 })
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
    <PageHeader :title="pt('Leads')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Lead')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search name / phone / code...')" @keyup.enter="reload" />
      </span>
      <Select
        v-model="stateFilter" :options="stateOptions" optionLabel="label" optionValue="value"
        :placeholder="pt('Pipeline')" class="filter" @change="reload"
      />
      <Select
        v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
        :placeholder="pt('All statuses')" showClear class="filter" @change="reload"
      />
      <Button :label="pt('Search')" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons :title="pt('Leads')" endpoint="/leads"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}), ...(stateFilter ? { state: stateFilter } : {}) })"
        :columns="[
          { key: 'code', label: pt('Code') },
          { key: 'name', label: pt('Name') },
          { key: 'company_name', label: pt('Company') },
          { key: 'phone', label: pt('Phone') },
          { key: 'source', label: pt('Source') },
          { key: 'estimated_value', label: pt('Est. Value') },
          { key: 'status', label: pt('Status') },
          { key: 'assignee.name', label: pt('Assigned') },
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
      <Column field="code" :header="pt('Code')" sortable style="width: 8rem" />
      <Column field="name" :header="pt('Name')" sortable>
        <template #body="{ data }">
          <span>{{ data.name }}</span>
          <span v-if="data.company_name" class="muted-sub">{{ data.company_name }}</span>
        </template>
      </Column>
      <Column field="phone" :header="pt('Phone')" />
      <Column field="source" :header="pt('Source')" />
      <Column :header="pt('Est. Value')" style="text-align: right">
        <template #body="{ data }">{{ money(data.estimated_value) }}</template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="pt(data.status)" />
          <span v-if="data.is_converted" class="muted-sub">{{ pt('→ customer') }}</span>
        </template>
      </Column>
      <Column :header="pt('Assigned')">
        <template #body="{ data }">{{ data.assignee?.name ?? '—' }}</template>
      </Column>
      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" :title="pt('View')" @click="openDetail(data)" />
          <Button
            v-if="canEdit && !data.is_converted"
            icon="pi pi-user-plus" text rounded size="small" severity="success"
            :title="pt('Convert to customer')" @click="openConvert(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" :title="pt('Edit')" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" :title="pt('Delete')" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No leads found.') }}</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? pt('Edit Lead') : pt('New Lead')" :style="{ width: '620px' }">
      <div class="form-grid">
        <div class="fld">
          <label>{{ pt('Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Company Name') }}</label>
          <InputText v-model="form.company_name" />
        </div>
        <div class="fld">
          <label>{{ pt('Email') }}</label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.phone" />
        </div>
        <div class="fld">
          <label>{{ pt('Source') }}</label>
          <Select v-model="form.source" :options="sourceOptions" optionLabel="label" optionValue="value" :placeholder="pt('Select source')" showClear />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select v-model="form.status" :options="statusOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Estimated Value') }}</label>
          <InputNumber v-model="form.estimated_value" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Expected Close Date') }}</label>
          <InputText type="date" v-model="form.expected_close_date" />
        </div>
        <div class="fld">
          <label>{{ pt('Assigned To') }}</label>
          <Select v-model="form.assigned_to" :options="users" optionLabel="label" optionValue="value" :placeholder="pt('Unassigned')" showClear filter />
        </div>
        <div v-if="form.status === 'lost'" class="fld">
          <label>{{ pt('Lost Reason') }}</label>
          <InputText v-model="form.lost_reason" />
        </div>
        <div class="fld full">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editing ? pt('Update') : pt('Create')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Convert dialog -->
    <Dialog v-model:visible="convertVisible" modal :header="pt('Convert Lead to Customer')" :style="{ width: '440px' }">
      <p class="muted small">
        {{ pt('Creating a customer from') }} <strong>{{ convertLead?.name }}</strong>. {{ pt('The lead will be marked') }}
        <strong>{{ pt('won') }}</strong> {{ pt('and any open follow-ups move to the new customer.') }}
      </p>
      <div class="form-grid">
        <div class="fld">
          <label>{{ pt('Customer Type') }}</label>
          <Select v-model="convertForm.type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Category') }}</label>
          <Select v-model="convertForm.customer_category_id" :options="categories" optionLabel="label" optionValue="value" :placeholder="pt('None')" showClear />
        </div>
        <div class="fld full">
          <label>{{ pt('Credit Limit') }}</label>
          <InputNumber v-model="convertForm.credit_limit" :minFractionDigits="2" :maxFractionDigits="2" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="convertVisible = false" />
        <Button :label="pt('Convert')" icon="pi pi-user-plus" :loading="converting" @click="doConvert" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Lead Details')" :fields="[
      { key: 'code', label: pt('Code') },
      { key: 'name', label: pt('Name') },
      { key: 'company_name', label: pt('Company') },
      { key: 'email', label: pt('Email') },
      { key: 'phone', label: pt('Phone') },
      { key: 'source', label: pt('Source') },
      { key: 'status', label: pt('Status') },
      { key: 'estimated_value', label: pt('Estimated Value'), format:(v)=>v==null||v===''?'—':'₹'+Number(v).toLocaleString('en-IN',{minimumFractionDigits:2}) },
      { key: 'expected_close_date', label: pt('Expected Close') },
      { key: 'assignee.name', label: pt('Assigned To') },
      { key: 'lost_reason', label: pt('Lost Reason') },
      { key: 'notes', label: pt('Notes'), full: true },
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
