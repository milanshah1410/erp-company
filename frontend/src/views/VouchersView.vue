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
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/vouchers')

const accounts = ref<any[]>([])
const branches = ref<any[]>([])

const canCreate = computed(() => auth.can('vouchers.create'))
const canEdit = computed(() => auth.can('vouchers.edit'))
const canDelete = computed(() => auth.can('vouchers.delete'))

const typeOptions = [
  { label: 'Receipt', value: 'receipt' },
  { label: 'Payment', value: 'payment' },
  { label: 'Contra', value: 'contra' },
  { label: 'Journal', value: 'journal' },
]
const typeColors: Record<string, string> = { receipt: 'success', payment: 'danger', contra: 'info', journal: 'warn' }
const modeOptions = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank', value: 'bank' },
  { label: 'UPI', value: 'upi' },
  { label: 'Cheque', value: 'cheque' },
  { label: 'Adjustment', value: 'adjustment' },
]
const partyTypeOptions = [
  { label: 'Customer', value: 'customer' },
  { label: 'Supplier', value: 'supplier' },
  { label: 'Broker', value: 'broker' },
  { label: 'Employee', value: 'employee' },
]

const search = ref('')
const filters = ref<{ voucher_type: string | null; status: string | null; date_from: string | null; date_to: string | null }>({
  voucher_type: null,
  status: null,
  date_from: null,
  date_to: null,
})

function money(v: any) {
  return formatCurrency(v)
}
function today() {
  return new Date().toISOString().slice(0, 10)
}

function activeParams() {
  const p: Record<string, any> = {}
  if (search.value) p.search = search.value
  if (filters.value.voucher_type) p.voucher_type = filters.value.voucher_type
  if (filters.value.status) p.status = filters.value.status
  if (filters.value.date_from) p.date_from = filters.value.date_from
  if (filters.value.date_to) p.date_to = filters.value.date_to
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

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    voucher_type: 'receipt',
    voucher_date: today(),
    amount: null,
    mode: 'cash',
    bank_account_id: null,
    counter_account_id: null,
    debit_ledger: '',
    credit_ledger: '',
    party_type: null,
    party_name: '',
    reference_no: '',
    narration: '',
    branch_id: null,
  }
}

const isContra = computed(() => form.value.voucher_type === 'contra')
const isJournal = computed(() => form.value.voucher_type === 'journal')
const showAccount = computed(() => ['receipt', 'payment', 'contra'].includes(form.value.voucher_type))

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/vouchers/${row.id}`)
    const v = data.data
    editingId.value = v.id
    form.value = {
      voucher_type: v.voucher_type,
      voucher_date: v.voucher_date,
      amount: Number(v.amount),
      mode: v.mode || 'cash',
      bank_account_id: v.bank_account_id,
      counter_account_id: v.counter_account_id,
      debit_ledger: v.debit_ledger || '',
      credit_ledger: v.credit_ledger || '',
      party_type: v.party_type,
      party_name: v.party_name || '',
      reference_no: v.reference_no || '',
      narration: v.narration || '',
      branch_id: v.branch_id,
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load voucher', life: 3000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/vouchers/${editingId.value}`, form.value)
    } else {
      await http.post('/vouchers', form.value)
    }
    toast.add({ severity: 'success', summary: editingId.value ? 'Voucher updated' : 'Voucher created', life: 2500 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function cancelVoucher(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.voucher_number}?`, text: 'It will be excluded from all books.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel voucher' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/vouchers/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Voucher cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeVoucher(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.voucher_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/vouchers/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

onMounted(async () => {
  const [a, b] = await Promise.all([
    http.get('/bank-accounts', { params: { per_page: 100, is_active: true } }),
    http.get('/branches', { params: { per_page: 100 } }),
  ])
  accounts.value = (a.data.data ?? a.data).map((x: any) => ({ label: `${x.name} (${x.type})`, value: x.id }))
  branches.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Vouchers" :count="crud.total.value">
      <template #actions>
        <Button v-if="canCreate" label="New Voucher" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="filters">
      <Select v-model="filters.voucher_type" :options="typeOptions" optionLabel="label" optionValue="value" showClear placeholder="All types" class="flt" @change="reload" />
      <Select v-model="filters.status" :options="[{ label: 'Posted', value: 'posted' }, { label: 'Cancelled', value: 'cancelled' }]" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="flt" @change="reload" />
      <input type="date" v-model="filters.date_from" class="date-input" title="From" @change="reload" />
      <input type="date" v-model="filters.date_to" class="date-input" title="To" @change="reload" />
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <TableExportButtons title="Vouchers" endpoint="/vouchers" :params="() => activeParams()"
        :columns="[
          { key: 'voucher_number', label: 'Voucher #' },
          { key: 'voucher_date', label: 'Date' },
          { key: 'voucher_type', label: 'Type' },
          { key: 'bank_account', label: 'Account' },
          { key: 'mode', label: 'Mode' },
          { key: 'party_name', label: 'Party' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="voucher_number" header="Voucher #" />
      <Column field="voucher_date" header="Date" />
      <Column header="Type"><template #body="{ data }"><Tag :severity="typeColors[data.voucher_type]" :value="data.voucher_type" /></template></Column>
      <Column header="Account"><template #body="{ data }">{{ data.bank_account || '—' }}<span v-if="data.counter_account"> ← {{ data.counter_account }}</span></template></Column>
      <Column field="mode" header="Mode"><template #body="{ data }">{{ data.mode || '—' }}</template></Column>
      <Column header="Party"><template #body="{ data }">{{ data.party_name || '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :severity="data.status === 'cancelled' ? 'secondary' : 'success'" :value="data.status" /></template></Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <template v-if="data.status === 'posted'">
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" label="Cancel" size="small" text severity="warn" @click="cancelVoucher(data)" />
          </template>
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeVoucher(data)" />
        </template>
      </Column>
      <template #empty>No vouchers recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Voucher' : 'New Voucher'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Voucher Type *</label>
          <Select v-model="form.voucher_type" :options="typeOptions" optionLabel="label" optionValue="value" :disabled="!!editingId" />
        </div>
        <div class="fld">
          <label>Date *</label>
          <input type="date" v-model="form.voucher_date" class="date-input" />
          <small v-if="fieldError('voucher_date')" class="err">{{ fieldError('voucher_date') }}</small>
        </div>
        <div class="fld">
          <label>Amount *</label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" class="w-full" />
          <small v-if="fieldError('amount')" class="err">{{ fieldError('amount') }}</small>
        </div>
        <div v-if="!isJournal" class="fld">
          <label>Mode</label>
          <Select v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" />
        </div>

        <!-- Contra: source -> destination -->
        <div v-if="isContra" class="fld">
          <label>From Account *</label>
          <Select v-model="form.counter_account_id" :options="accounts" optionLabel="label" optionValue="value" filter placeholder="Source account" />
          <small v-if="fieldError('counter_account_id')" class="err">{{ fieldError('counter_account_id') }}</small>
        </div>
        <div v-if="showAccount" class="fld">
          <label>{{ isContra ? 'To Account *' : 'Cash / Bank Account *' }}</label>
          <Select v-model="form.bank_account_id" :options="accounts" optionLabel="label" optionValue="value" filter placeholder="Select account" />
          <small v-if="fieldError('bank_account_id')" class="err">{{ fieldError('bank_account_id') }}</small>
        </div>

        <!-- Journal: dr / cr labels -->
        <div v-if="isJournal" class="fld">
          <label>Debit Ledger *</label>
          <InputText v-model="form.debit_ledger" placeholder="e.g. Depreciation" />
          <small v-if="fieldError('debit_ledger')" class="err">{{ fieldError('debit_ledger') }}</small>
        </div>
        <div v-if="isJournal" class="fld">
          <label>Credit Ledger *</label>
          <InputText v-model="form.credit_ledger" placeholder="e.g. Fixed Assets" />
          <small v-if="fieldError('credit_ledger')" class="err">{{ fieldError('credit_ledger') }}</small>
        </div>

        <div v-if="!isContra" class="fld">
          <label>Party Type</label>
          <Select v-model="form.party_type" :options="partyTypeOptions" optionLabel="label" optionValue="value" showClear placeholder="—" />
        </div>
        <div v-if="!isContra" class="fld">
          <label>Party Name</label>
          <InputText v-model="form.party_name" placeholder="e.g. Ramesh, ABC Traders" />
        </div>
        <div class="fld">
          <label>Reference No.</label>
          <InputText v-model="form.reference_no" placeholder="Cheque / txn no." />
        </div>
        <div class="fld">
          <label>Branch</label>
          <Select v-model="form.branch_id" :options="branches" optionLabel="label" optionValue="value" filter showClear placeholder="Company-wide" />
        </div>
      </div>
      <div class="fld full">
        <label>Narration</label>
        <Textarea v-model="form.narration" rows="2" placeholder="Notes / details" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 1rem; align-items: center; }
.flt { min-width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.w-full { width: 100%; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
