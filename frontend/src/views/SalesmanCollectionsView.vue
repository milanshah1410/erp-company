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
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')

const salesmen = ref<{ label: string; value: number }[]>([])
const customers = ref<{ label: string; value: number }[]>([])

const MODES = [
  { label: 'Cash', value: 'cash' }, { label: 'Cheque', value: 'cheque' },
  { label: 'UPI', value: 'upi' }, { label: 'Bank', value: 'bank' },
]
const STATUSES = [
  { label: 'Collected', value: 'collected' }, { label: 'Deposited', value: 'deposited' },
  { label: 'Bounced', value: 'bounced' }, { label: 'Cancelled', value: 'cancelled' },
]

const filters = ref<{ salesman_id: number | null; status: string | null }>({ salesman_id: null, status: null })

const canCreate = computed(() => auth.can('salesman-collections.create'))
const canEdit = computed(() => auth.can('salesman-collections.edit'))
const canDelete = computed(() => auth.can('salesman-collections.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ salesman_id: null, customer_id: null, amount: null, mode: 'cash', reference_no: '', collection_date: new Date(), notes: '' })

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function money(v: any) {
  return formatCurrency(v)
}
function statusSeverity(s: string) {
  return { collected: 'info', deposited: 'success', bounced: 'danger', cancelled: 'secondary' }[s] || 'secondary'
}
function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}
function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

async function loadOptions() {
  const [s, c] = await Promise.all([
    http.get('/salesmen', { params: { per_page: 100, is_active: true } }),
    http.get('/customers', { params: { per_page: 200 } }),
  ])
  salesmen.value = (s.data.data ?? s.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salesman-collections', {
      params: {
        salesman_id: filters.value.salesman_id || undefined,
        status: filters.value.status || undefined,
        search: search.value || undefined,
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
  form.value = { salesman_id: filters.value.salesman_id, customer_id: null, amount: null, mode: 'cash', reference_no: '', collection_date: new Date(), notes: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    salesman_id: row.salesman_id, customer_id: row.customer_id, amount: Number(row.amount), mode: row.mode,
    reference_no: row.reference_no || '', collection_date: row.collection_date ? new Date(row.collection_date) : new Date(), notes: row.notes || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      salesman_id: form.value.salesman_id,
      customer_id: form.value.customer_id,
      amount: form.value.amount,
      mode: form.value.mode,
      reference_no: form.value.reference_no || undefined,
      collection_date: toDateString(form.value.collection_date),
      notes: form.value.notes || undefined,
    }
    if (editingId.value) {
      await http.put(`/salesman-collections/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Collection updated', life: 2500 })
    } else {
      await http.post('/salesman-collections', payload)
      toast.add({ severity: 'success', summary: 'Collection recorded', life: 2500 })
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

async function deposit(row: any) {
  try {
    await http.post(`/salesman-collections/${row.id}/deposit`)
    toast.add({ severity: 'success', summary: 'Marked deposited', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function bounce(row: any) {
  const res = await Swal.fire({
    title: 'Mark as bounced?',
    text: `${row.collection_number} · ${money(row.amount)}`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Mark bounced',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/salesman-collections/${row.id}/bounce`)
    toast.add({ severity: 'success', summary: 'Marked bounced', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this collection?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/salesman-collections/${row.id}`)
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
      <h1>Collection Entry</h1>
      <Button v-if="canCreate" label="New Collection" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select v-model="filters.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter showClear
        placeholder="All salesmen" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="load" />
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search #, reference..." @keyup.enter="load" />
      </span>
      <Button label="Search" outlined @click="load" />
      <TableExportButtons title="Collection Entry" endpoint="/salesman-collections"
        :params="() => ({
          salesman_id: filters.salesman_id || undefined,
          status: filters.status || undefined,
          search: search || undefined,
        })"
        :columns="[
          { key: 'collection_number', label: 'Collection #' },
          { key: 'collection_date', label: 'Date' },
          { key: 'salesman', label: 'Salesman' },
          { key: 'customer', label: 'Customer' },
          { key: 'amount', label: 'Amount' },
          { key: 'mode', label: 'Mode' },
          { key: 'reference_no', label: 'Reference' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="collection_number" header="Collection #" />
      <Column field="collection_date" header="Date" />
      <Column field="salesman" header="Salesman" />
      <Column field="customer" header="Customer" />
      <Column header="Amount">
        <template #body="{ data }">{{ money(data.amount) }}</template>
      </Column>
      <Column field="mode" header="Mode">
        <template #body="{ data }"><span class="cap">{{ data.mode }}</span></template>
      </Column>
      <Column field="reference_no" header="Reference" />
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 13rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canEdit && data.status === 'collected'" label="Deposit" icon="pi pi-download" text size="small" @click="deposit(data)" />
          <Button v-if="canEdit && ['collected', 'deposited'].includes(data.status)" label="Bounce" icon="pi pi-times-circle" text severity="danger" size="small" @click="bounce(data)" />
          <Button v-if="canEdit && data.status === 'collected'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && data.status !== 'deposited'" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No collections recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Collection' : 'New Collection'" :style="{ width: '480px' }">
      <div class="form">
        <div class="field">
          <label>Salesman <span class="req">*</span></label>
          <Select v-model="form.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter
            placeholder="Select salesman" :class="{ invalid: fieldError('salesman_id') }" />
          <small v-if="fieldError('salesman_id')" class="error-text">{{ fieldError('salesman_id') }}</small>
        </div>
        <div class="field">
          <label>Customer <span class="req">*</span></label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter
            placeholder="Select customer" :class="{ invalid: fieldError('customer_id') }" />
          <small v-if="fieldError('customer_id')" class="error-text">{{ fieldError('customer_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Amount <span class="req">*</span></label>
            <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0"
              :class="{ invalid: fieldError('amount') }" />
            <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
          </div>
          <div class="field">
            <label>Mode</label>
            <Select v-model="form.mode" :options="MODES" optionLabel="label" optionValue="value" />
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>Reference No.</label>
            <InputText v-model="form.reference_no" placeholder="Cheque / txn no." />
          </div>
          <div class="field">
            <label>Date</label>
            <DatePicker v-model="form.collection_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Save'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Collection Details" :fields="[
      { key: 'collection_number', label: 'Collection #' },
      { key: 'collection_date', label: 'Date' },
      { key: 'salesman', label: 'Salesman' },
      { key: 'customer', label: 'Customer' },
      { key: 'amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'mode', label: 'Mode' },
      { key: 'reference_no', label: 'Reference' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.w-60 { width: 240px; }
.search {
  position: relative;
  display: flex;
  align-items: center;
}
.search i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.search :deep(input) {
  padding-left: 2.25rem;
  width: 240px;
}
.cap { text-transform: capitalize; }
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
}
.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
