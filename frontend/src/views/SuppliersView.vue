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
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import SupplierAccountDialog from '../components/SupplierAccountDialog.vue'

const toast = useToast()
const auth = useAuthStore()

// --- Account (ledger / payments) dialog ---
const accountVisible = ref(false)
const accountSupplier = ref<any>(null)
function openAccount(row: any) {
  accountSupplier.value = row
  accountVisible.value = true
}

const canView = computed(() => auth.can('suppliers.view'))
const canCreate = computed(() => auth.can('suppliers.create'))
const canEdit = computed(() => auth.can('suppliers.edit'))
const canDelete = computed(() => auth.can('suppliers.delete'))

// --- Supplier list ---
const crud = ref(useCrud('/suppliers'))
const search = ref('')

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

function reload() {
  crud.value.fetch(search.value ? { search: search.value } : {})
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

// --- Supplier create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    code: '',
    name: '',
    company_name: '',
    email: '',
    phone: '',
    gst_number: '',
    credit_limit: 0,
    payment_terms_days: 0,
    opening_balance: 0,
    address: '',
    city: '',
    state: '',
    pincode: '',
    is_active: true,
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
    code: row.code,
    name: row.name,
    company_name: row.company_name || '',
    email: row.email || '',
    phone: row.phone || '',
    gst_number: row.gst_number || '',
    credit_limit: Number(row.credit_limit ?? 0),
    payment_terms_days: Number(row.payment_terms_days ?? 0),
    opening_balance: Number(row.opening_balance ?? 0),
    address: row.address || '',
    city: row.city || '',
    state: row.state || '',
    pincode: row.pincode || '',
    is_active: !!row.is_active,
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.value.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: 'Supplier updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Supplier created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      if (!Object.keys(errors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.name}"?`,
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

// --- Purchase History dialog ---
const historyVisible = ref(false)
const historySupplier = ref<any>(null)
const historyLoading = ref(false)
const historyOrders = ref<any[]>([])
const historyTotal = ref(0)
const historyPage = ref(1)
const historyPerPage = ref(15)
const historySummary = ref<any>(null)
const historyStatus = ref<string | null>(null)

const statusColors: Record<string, string> = {
  draft: 'secondary',
  pending_approval: 'warn',
  approved: 'info',
  partially_received: 'warn',
  received: 'success',
  cancelled: 'danger',
}

const historyStatusOptions = [
  { label: 'All', value: null },
  { label: 'draft', value: 'draft' },
  { label: 'pending approval', value: 'pending_approval' },
  { label: 'approved', value: 'approved' },
  { label: 'partially received', value: 'partially_received' },
  { label: 'received', value: 'received' },
  { label: 'cancelled', value: 'cancelled' },
]

const historyMeta = computed(() => {
  const s = historySummary.value
  if (!s) return ''
  const credit = s.credit_limit != null ? money(s.credit_limit) : '—'
  const terms = s.payment_terms_days != null ? `${s.payment_terms_days} days` : '—'
  const last = s.last_order_date || '—'
  return `Credit limit: ${credit} · Payment terms: ${terms} · Last order: ${last}`
})

async function loadHistory() {
  if (!historySupplier.value) return
  historyLoading.value = true
  try {
    const params: Record<string, any> = { page: historyPage.value, per_page: historyPerPage.value }
    if (historyStatus.value) params.status = historyStatus.value
    const { data } = await http.get(`/suppliers/${historySupplier.value.id}/purchase-history`, { params })
    historyOrders.value = data.data ?? []
    historyTotal.value = data.meta?.total ?? historyOrders.value.length
    historySummary.value = data.summary ?? null
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to load purchase history', life: 4000 })
  } finally {
    historyLoading.value = false
  }
}

function openHistory(row: any) {
  historySupplier.value = row
  historyOrders.value = []
  historyTotal.value = 0
  historyPage.value = 1
  historyStatus.value = null
  historySummary.value = null
  historyVisible.value = true
  loadHistory()
}

function onHistoryPage(e: any) {
  historyPage.value = e.page + 1
  historyPerPage.value = e.rows
  loadHistory()
}

function onHistoryStatusChange() {
  historyPage.value = 1
  loadHistory()
}

onMounted(reload)
</script>

<template>
  <div>
    <div class="header">
      <h1>Suppliers</h1>
      <Button v-if="canCreate" label="New Supplier" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
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
      class="p-datatable-sm"
    >
      <Column field="code" header="Code" />
      <Column header="Name">
        <template #body="{ data }">
          <span>{{ data.name }}</span>
          <span v-if="data.company_name" class="muted-sub">{{ data.company_name }}</span>
        </template>
      </Column>
      <Column field="phone" header="Phone" />
      <Column header="GST">
        <template #body="{ data }">{{ data.gst_number || '—' }}</template>
      </Column>
      <Column header="Credit Limit">
        <template #body="{ data }">{{ money(data.credit_limit) }}</template>
      </Column>
      <Column header="Terms">
        <template #body="{ data }">{{ data.payment_terms_days ? `${data.payment_terms_days} days` : '—' }}</template>
      </Column>
      <Column field="is_active" header="Active">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button v-if="canView" icon="pi pi-history" text rounded size="small" title="Purchase History" @click="openHistory(data)" />
          <Button v-if="canView" icon="pi pi-wallet" text rounded size="small" title="Account (ledger / payments)" @click="openAccount(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No records found.</template>
    </DataTable>

    <!-- Supplier create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Supplier' : 'New Supplier'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Code <span class="req">*</span></label>
          <InputText v-model="form.code" :class="{ invalid: fieldError('code') }" />
          <small v-if="fieldError('code')" class="error-text">{{ fieldError('code') }}</small>
        </div>

        <div class="fld">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="fld">
          <label>Company Name</label>
          <InputText v-model="form.company_name" :class="{ invalid: fieldError('company_name') }" />
          <small v-if="fieldError('company_name')" class="error-text">{{ fieldError('company_name') }}</small>
        </div>

        <div class="fld">
          <label>Email</label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>

        <div class="fld">
          <label>Phone</label>
          <InputText v-model="form.phone" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>

        <div class="fld">
          <label>GST Number</label>
          <InputText v-model="form.gst_number" :class="{ invalid: fieldError('gst_number') }" />
          <small v-if="fieldError('gst_number')" class="error-text">{{ fieldError('gst_number') }}</small>
        </div>

        <div class="fld">
          <label>Credit Limit</label>
          <InputNumber v-model="form.credit_limit" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('credit_limit') }" />
          <small v-if="fieldError('credit_limit')" class="error-text">{{ fieldError('credit_limit') }}</small>
        </div>

        <div class="fld">
          <label>Payment Terms (days)</label>
          <InputNumber v-model="form.payment_terms_days" :minFractionDigits="0" :maxFractionDigits="0" :class="{ invalid: fieldError('payment_terms_days') }" />
          <small v-if="fieldError('payment_terms_days')" class="error-text">{{ fieldError('payment_terms_days') }}</small>
        </div>

        <div class="fld">
          <label>Opening Balance</label>
          <InputNumber v-model="form.opening_balance" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('opening_balance') }" />
          <small v-if="fieldError('opening_balance')" class="error-text">{{ fieldError('opening_balance') }}</small>
        </div>

        <div class="fld">
          <label>Status</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="sup_is_active" />
            <label for="sup_is_active">Active</label>
          </div>
        </div>

        <div class="fld full">
          <label>Address</label>
          <Textarea v-model="form.address" rows="2" :class="{ invalid: fieldError('address') }" />
          <small v-if="fieldError('address')" class="error-text">{{ fieldError('address') }}</small>
        </div>

        <div class="fld">
          <label>City</label>
          <InputText v-model="form.city" :class="{ invalid: fieldError('city') }" />
          <small v-if="fieldError('city')" class="error-text">{{ fieldError('city') }}</small>
        </div>

        <div class="fld">
          <label>State</label>
          <InputText v-model="form.state" :class="{ invalid: fieldError('state') }" />
          <small v-if="fieldError('state')" class="error-text">{{ fieldError('state') }}</small>
        </div>

        <div class="fld">
          <label>Pincode</label>
          <InputText v-model="form.pincode" :class="{ invalid: fieldError('pincode') }" />
          <small v-if="fieldError('pincode')" class="error-text">{{ fieldError('pincode') }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Purchase History dialog -->
    <Dialog
      v-model:visible="historyVisible"
      modal
      :header="`Purchase History — ${historySupplier?.name ?? ''}`"
      :style="{ width: '760px' }"
    >
      <div class="stat-grid">
        <div class="stat-tile">
          <div class="stat-value">{{ historySummary?.total_orders ?? 0 }}</div>
          <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(historySummary?.total_value) }}</div>
          <div class="stat-label">Lifetime Value</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ historySummary?.open_orders ?? 0 }}</div>
          <div class="stat-label">Open Orders</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(historySummary?.open_value) }}</div>
          <div class="stat-label">Open Value</div>
        </div>
      </div>

      <p class="summary-meta">{{ historyMeta }}</p>

      <div class="history-toolbar">
        <Select
          v-model="historyStatus"
          :options="historyStatusOptions"
          optionLabel="label"
          optionValue="value"
          placeholder="All"
          class="status-filter"
          @change="onHistoryStatusChange"
        />
      </div>

      <DataTable
        :value="historyOrders"
        :loading="historyLoading"
        lazy
        paginator
        :rows="historyPerPage"
        :totalRecords="historyTotal"
        :rowsPerPageOptions="[15, 30, 50]"
        dataKey="id"
        @page="onHistoryPage"
        class="p-datatable-sm"
      >
        <Column field="po_number" header="PO #" />
        <Column field="order_date" header="Date" />
        <Column header="Warehouse">
          <template #body="{ data }">{{ data.warehouse?.name }}</template>
        </Column>
        <Column header="Total">
          <template #body="{ data }">{{ money(data.total) }}</template>
        </Column>
        <Column field="goods_receipts_count" header="GRNs" />
        <Column header="Status">
          <template #body="{ data }">
            <Tag :severity="statusColors[data.status]" :value="String(data.status).replace(/_/g, ' ')" />
          </template>
        </Column>
        <template #empty>No purchase orders for this supplier yet.</template>
      </DataTable>
    </Dialog>

    <!-- Account: ledger / payments -->
    <SupplierAccountDialog v-model:visible="accountVisible" :supplier="accountSupplier" />
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
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
  width: 280px;
}
.muted-sub {
  display: block;
  color: #6b7280;
  font-size: 0.78rem;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.fld.full {
  grid-column: 1 / -1;
}
.fld label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber),
.fld :deep(textarea) {
  width: 100%;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.35rem;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}
.stat-tile {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 0.85rem 1rem;
}
.stat-value {
  font-size: 1.05rem;
  font-weight: 600;
  color: #111827;
}
.stat-label {
  color: #6b7280;
  font-size: 0.78rem;
  margin-top: 0.15rem;
}
.summary-meta {
  color: #6b7280;
  font-size: 0.82rem;
  margin-bottom: 0.85rem;
}
.history-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.75rem;
}
.status-filter {
  min-width: 180px;
}
</style>
