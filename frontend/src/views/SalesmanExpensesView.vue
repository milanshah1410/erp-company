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

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const salesmen = ref<{ label: string; value: number }[]>([])

const CATEGORIES = [
  { label: 'Travel', value: 'travel' }, { label: 'Food', value: 'food' }, { label: 'Lodging', value: 'lodging' },
  { label: 'Fuel', value: 'fuel' }, { label: 'Miscellaneous', value: 'misc' },
]
const STATUSES = [
  { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' },
]

const filters = ref<{ salesman_id: number | null; status: string | null }>({ salesman_id: null, status: null })

const canCreate = computed(() => auth.can('salesman-expenses.create'))
const canEdit = computed(() => auth.can('salesman-expenses.edit'))
const canDelete = computed(() => auth.can('salesman-expenses.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ salesman_id: null, expense_date: new Date(), category: 'travel', amount: null, description: '' })

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function money(v: any) {
  return formatCurrency(v)
}
function statusSeverity(s: string) {
  return { pending: 'warn', approved: 'success', rejected: 'danger' }[s] || 'secondary'
}
function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}
function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

async function loadSalesmen() {
  const { data } = await http.get('/salesmen', { params: { per_page: 100, is_active: true } })
  salesmen.value = (data.data ?? data).map((s: any) => ({ label: `${s.code} — ${s.name}`, value: s.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salesman-expenses', {
      params: {
        salesman_id: filters.value.salesman_id || undefined,
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
  form.value = { salesman_id: filters.value.salesman_id, expense_date: new Date(), category: 'travel', amount: null, description: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    salesman_id: row.salesman_id, expense_date: row.expense_date ? new Date(row.expense_date) : new Date(),
    category: row.category, amount: Number(row.amount), description: row.description || '',
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
      expense_date: toDateString(form.value.expense_date),
      category: form.value.category,
      amount: form.value.amount,
      description: form.value.description || undefined,
    }
    if (editingId.value) {
      await http.put(`/salesman-expenses/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Expense updated', life: 2500 })
    } else {
      await http.post('/salesman-expenses', payload)
      toast.add({ severity: 'success', summary: 'Expense added', life: 2500 })
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
    await http.post(`/salesman-expenses/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function reject(row: any) {
  const res = await Swal.fire({
    title: 'Reject this expense?',
    input: 'text',
    inputLabel: 'Reason (optional)',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Reject',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/salesman-expenses/${row.id}/reject`, { rejection_reason: res.value || undefined })
    toast.add({ severity: 'success', summary: 'Rejected', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this expense?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/salesman-expenses/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  await loadSalesmen()
  await load()
})
</script>

<template>
  <div>
    <PageHeader title="Salesman Daily Expenses">
      <template #actions>
        <Button v-if="canCreate" label="New Expense" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filters.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter showClear
        placeholder="All salesmen" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="load" />
      <TableExportButtons title="Salesman Expenses" endpoint="/salesman-expenses"
        :params="() => ({
          salesman_id: filters.salesman_id || undefined,
          status: filters.status || undefined,
        })"
        :columns="[
          { key: 'expense_date', label: 'Date' },
          { key: 'salesman', label: 'Salesman' },
          { key: 'category', label: 'Category' },
          { key: 'amount', label: 'Amount' },
          { key: 'description', label: 'Description' },
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
      <Column field="expense_date" header="Date" />
      <Column field="salesman" header="Salesman" />
      <Column field="category" header="Category">
        <template #body="{ data }"><span class="cap">{{ data.category }}</span></template>
      </Column>
      <Column header="Amount">
        <template #body="{ data }">{{ money(data.amount) }}</template>
      </Column>
      <Column field="description" header="Description" />
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" class="actions-col" style="width: 19rem; white-space: nowrap">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canEdit && data.status === 'pending'" label="Approve" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canEdit && data.status === 'pending'" label="Reject" icon="pi pi-times" text severity="danger" size="small" @click="reject(data)" />
          <Button v-if="canEdit && data.status !== 'approved'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && data.status !== 'approved'" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No expenses recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Expense' : 'New Expense'" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>Salesman <span class="req">*</span></label>
          <Select v-model="form.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter
            placeholder="Select salesman" :class="{ invalid: fieldError('salesman_id') }" />
          <small v-if="fieldError('salesman_id')" class="error-text">{{ fieldError('salesman_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Date <span class="req">*</span></label>
            <DatePicker v-model="form.expense_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
          <div class="field">
            <label>Category</label>
            <Select v-model="form.category" :options="CATEGORIES" optionLabel="label" optionValue="value" />
          </div>
        </div>
        <div class="field">
          <label>Amount <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0"
            :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>Description</label>
          <Textarea v-model="form.description" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Save'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Expense Details" :fields="[
      { key: 'expense_date', label: 'Date' },
      { key: 'salesman', label: 'Salesman' },
      { key: 'category', label: 'Category' },
      { key: 'amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: 'Status' },
      { key: 'description', label: 'Description', full: true },
    ]" />
  </div>
</template>

<style scoped>
/* Center the Actions column header + cells (matches Collection Entry). */
:deep(th.actions-col .p-datatable-column-header-content) {
  justify-content: center;
}
:deep(td.actions-col) {
  text-align: center;
}

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
