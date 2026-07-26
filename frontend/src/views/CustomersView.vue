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
import { usePt } from '../i18n/pt'
import CustomerDetailDialog from '../components/CustomerDetailDialog.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canView = computed(() => auth.can('customers.view'))
const canCreate = computed(() => auth.can('customers.create'))
const canEdit = computed(() => auth.can('customers.edit'))
const canDelete = computed(() => auth.can('customers.delete'))

// --- Customer list ---
const crud = ref(useCrud('/customers'))
const search = ref('')
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

const typeColors: Record<string, string> = {
  retail: 'info',
  wholesale: 'success',
  dealer: 'success',
  distributor: 'warn',
  retailer: 'info',
}

const typeOptions = computed(() => [
  { label: pt('Retail'), value: 'retail' },
  { label: pt('Wholesale'), value: 'wholesale' },
  { label: pt('Dealer'), value: 'dealer' },
  { label: pt('Distributor'), value: 'distributor' },
  { label: pt('Retailer'), value: 'retailer' },
])

const gstTypeOptions = computed(() => [
  { label: pt('Regular'), value: 'regular' },
  { label: pt('Composition'), value: 'composition' },
  { label: pt('Unregistered'), value: 'unregistered' },
  { label: pt('Consumer'), value: 'consumer' },
  { label: pt('Overseas'), value: 'overseas' },
])

const priceLevelOptions = computed(() => [
  { label: pt('Category default'), value: null },
  { label: pt('MRP'), value: 'mrp' },
  { label: pt('Wholesale'), value: 'wholesale' },
  { label: pt('Retail'), value: 'retail' },
  { label: pt('Discount'), value: 'discount' },
])

// Customer categories for the assignment dropdown + filter.
const categories = ref<any[]>([])
const categoryFilter = ref<number | null>(null)
async function loadCategories() {
  try {
    const { data } = await http.get('/customer-categories', { params: { per_page: 100, is_active: true } })
    categories.value = (data.data ?? []).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {
    /* non-blocking */
  }
}

// Existing customers, for the "referred by" picker.
const customerOptions = ref<any[]>([])
async function loadCustomerOptions() {
  try {
    const { data } = await http.get('/customers', { params: { per_page: 100 } })
    customerOptions.value = (data.data ?? []).map((c: any) => ({ label: `${c.name} (${c.code})`, value: c.id }))
  } catch {
    /* non-blocking */
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (categoryFilter.value) params.customer_category_id = categoryFilter.value
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

// --- Customer create / edit dialog ---
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
    type: 'retail',
    customer_category_id: null,
    email: '',
    phone: '',
    gst_number: '',
    gst_registration_type: 'unregistered',
    pan: '',
    place_of_supply: '',
    date_of_birth: '',
    anniversary_date: '',
    referred_by_customer_id: null,
    credit_limit: 0,
    opening_balance: 0,
    default_price_level: null,
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
    type: row.type || 'retail',
    customer_category_id: row.customer_category_id ?? null,
    email: row.email || '',
    phone: row.phone || '',
    gst_number: row.gst_number || '',
    gst_registration_type: row.gst_registration_type || 'unregistered',
    pan: row.pan || '',
    place_of_supply: row.place_of_supply || '',
    date_of_birth: row.date_of_birth || '',
    anniversary_date: row.anniversary_date || '',
    referred_by_customer_id: row.referred_by_customer_id ?? null,
    credit_limit: Number(row.credit_limit ?? 0),
    opening_balance: Number(row.opening_balance ?? 0),
    default_price_level: row.default_price_level ?? null,
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
      toast.add({ severity: 'success', summary: pt('Customer updated'), life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: pt('Customer created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      if (!Object.keys(errors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: pt('Error'), detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: pt('Delete "{name}"?', { name: row.name }),
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

// --- Purchase History dialog ---
const historyVisible = ref(false)
const historyCustomer = ref<any>(null)
const historyLoading = ref(false)
const historyOrders = ref<any[]>([])
const historyTotal = ref(0)
const historyPage = ref(1)
const historyPerPage = ref(15)
const historySummary = ref<any>(null)
const historyStatus = ref<string | null>(null)

const statusColors: Record<string, string> = {
  draft: 'secondary',
  confirmed: 'info',
  delivered: 'success',
  cancelled: 'danger',
}

const invoiceStatusColors: Record<string, string> = {
  unpaid: 'danger',
  partial: 'warn',
  paid: 'success',
}

const historyStatusOptions = computed(() => [
  { label: pt('All'), value: null },
  { label: pt('draft'), value: 'draft' },
  { label: pt('confirmed'), value: 'confirmed' },
  { label: pt('delivered'), value: 'delivered' },
  { label: pt('cancelled'), value: 'cancelled' },
])

const historyMeta = computed(() => {
  const s = historySummary.value
  if (!s) return ''
  const credit = s.credit_limit != null ? money(s.credit_limit) : '—'
  const last = s.last_order_date || '—'
  return pt('Credit limit: {credit} · Last order: {last}', { credit, last })
})

const outstandingBalance = computed(() => Number(historySummary.value?.outstanding_balance ?? 0))

async function loadHistory() {
  if (!historyCustomer.value) return
  historyLoading.value = true
  try {
    const params: Record<string, any> = { page: historyPage.value, per_page: historyPerPage.value }
    if (historyStatus.value) params.status = historyStatus.value
    const { data } = await http.get(`/customers/${historyCustomer.value.id}/purchase-history`, { params })
    historyOrders.value = data.data ?? []
    historyTotal.value = data.meta?.total ?? historyOrders.value.length
    historySummary.value = data.summary ?? null
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed to load purchase history'), life: 4000 })
  } finally {
    historyLoading.value = false
  }
}

function openHistory(row: any) {
  historyCustomer.value = row
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

// --- Account detail dialog (ledger / payments / addresses / price list) ---
const detailVisible = ref(false)
const detailCustomer = ref<any>(null)

function openDetail(row: any) {
  detailCustomer.value = row
  detailVisible.value = true
}

// --- B2B portal access dialog ---
const portalVisible = ref(false)
const portalCustomer = ref<any>(null)
const portalSaving = ref(false)
const portalForm = ref<{ portal_enabled: boolean; password: string }>({ portal_enabled: false, password: '' })
const portalErrors = ref<Record<string, string[]>>({})
const portalError = ref<string | null>(null)

function openPortal(row: any) {
  portalCustomer.value = row
  portalForm.value = { portal_enabled: !!row.portal_enabled, password: '' }
  portalErrors.value = {}
  portalError.value = null
  portalVisible.value = true
}

async function savePortal() {
  portalSaving.value = true
  portalErrors.value = {}
  portalError.value = null
  try {
    await http.post(`/customers/${portalCustomer.value.id}/portal-access`, {
      portal_enabled: portalForm.value.portal_enabled,
      password: portalForm.value.password || null,
    })
    toast.add({ severity: 'success', summary: pt('Portal access updated'), life: 2500 })
    portalVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      portalErrors.value = e.response.data.errors || {}
      portalError.value = e.response.data.message || null
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
    }
  } finally {
    portalSaving.value = false
  }
}

onMounted(() => {
  loadCategories()
  loadCustomerOptions()
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Customers')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Customer')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
      </span>
      <Select
        v-model="categoryFilter"
        :options="categories"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All categories')"
        showClear
        class="cat-filter"
        @change="reload"
      />
      <Button :label="pt('Search')" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons :title="pt('Customers')" endpoint="/customers"
        :params="() => ({
          ...(search ? { search } : {}),
          ...(categoryFilter ? { customer_category_id: categoryFilter } : {}),
        })"
        :columns="[
          { key: 'code', label: pt('Code') },
          { key: 'name', label: pt('Name') },
          { key: 'company_name', label: pt('Company') },
          { key: 'type', label: pt('Type') },
          { key: 'phone', label: pt('Phone') },
          { key: 'outstanding_balance', label: pt('Outstanding') },
          { key: 'is_active', label: pt('Active'), format: (v) => v ? pt('Yes') : pt('No') },
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
      removableSort
      :sortField="sortField ?? undefined"
      :sortOrder="sortOrder ?? undefined"
      @sort="onSort"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="code" :header="pt('Code')" sortable />
      <Column field="name" :header="pt('Name')" sortable>
        <template #body="{ data }">
          <span>{{ data.name }}</span>
          <span v-if="data.company_name" class="muted-sub">{{ data.company_name }}</span>
        </template>
      </Column>
      <Column :header="pt('Type')">
        <template #body="{ data }">
          <Tag :severity="typeColors[data.type]" :value="data.type" />
          <span v-if="data.category" class="muted-sub">{{ data.category.name }}</span>
        </template>
      </Column>
      <Column field="phone" :header="pt('Phone')" />
      <Column :header="pt('Outstanding')">
        <template #body="{ data }">
          <span :class="Number(data.outstanding_balance) > 0 ? 'amount-due' : 'amount-clear'">
            {{ money(data.outstanding_balance) }}
          </span>
          <span v-if="data.available_credit != null" class="muted-sub">
            {{ pt('{amount} avail.', { amount: money(data.available_credit) }) }}
          </span>
        </template>
      </Column>
      <Column field="is_active" :header="pt('Active')">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? pt('Yes') : pt('No')" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button v-if="canView" icon="pi pi-wallet" text rounded size="small" :title="pt('Account (ledger / payments / addresses / prices)')" @click="openDetail(data)" />
          <Button v-if="canView" icon="pi pi-history" text rounded size="small" :title="pt('Purchase History')" @click="openHistory(data)" />
          <Button v-if="canEdit" :icon="data.portal_enabled ? 'pi pi-globe' : 'pi pi-lock'" text rounded size="small"
            :severity="data.portal_enabled ? 'success' : 'secondary'" :title="pt('B2B Portal Access')" @click="openPortal(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" :title="pt('Edit')" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" :title="pt('Delete')" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No records found.') }}</template>
    </DataTable>

    <!-- Customer create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? pt('Edit Customer') : pt('New Customer')" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>{{ pt('Code') }} <span class="req">*</span></label>
          <InputText v-model="form.code" :class="{ invalid: fieldError('code') }" />
          <small v-if="fieldError('code')" class="error-text">{{ fieldError('code') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Company Name') }}</label>
          <InputText v-model="form.company_name" :class="{ invalid: fieldError('company_name') }" />
          <small v-if="fieldError('company_name')" class="error-text">{{ fieldError('company_name') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Type') }} <span class="req">*</span></label>
          <Select
            v-model="form.type"
            :options="typeOptions"
            optionLabel="label"
            optionValue="value"
            :class="{ invalid: fieldError('type') }"
          />
          <small v-if="fieldError('type')" class="error-text">{{ fieldError('type') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Category') }}</label>
          <Select
            v-model="form.customer_category_id"
            :options="categories"
            optionLabel="label"
            optionValue="value"
            :placeholder="pt('None')"
            showClear
            :class="{ invalid: fieldError('customer_category_id') }"
          />
          <small v-if="fieldError('customer_category_id')" class="error-text">{{ fieldError('customer_category_id') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Email') }}</label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.phone" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('GST Number') }}</label>
          <InputText v-model="form.gst_number" :class="{ invalid: fieldError('gst_number') }" />
          <small v-if="fieldError('gst_number')" class="error-text">{{ fieldError('gst_number') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('GST Registration Type') }}</label>
          <Select
            v-model="form.gst_registration_type"
            :options="gstTypeOptions"
            optionLabel="label"
            optionValue="value"
            :class="{ invalid: fieldError('gst_registration_type') }"
          />
          <small v-if="fieldError('gst_registration_type')" class="error-text">{{ fieldError('gst_registration_type') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('PAN') }}</label>
          <InputText v-model="form.pan" :class="{ invalid: fieldError('pan') }" />
          <small v-if="fieldError('pan')" class="error-text">{{ fieldError('pan') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Place of Supply') }}</label>
          <InputText v-model="form.place_of_supply" :placeholder="pt('e.g. Maharashtra (27)')" :class="{ invalid: fieldError('place_of_supply') }" />
          <small v-if="fieldError('place_of_supply')" class="error-text">{{ fieldError('place_of_supply') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Default Price Level') }}</label>
          <Select
            v-model="form.default_price_level"
            :options="priceLevelOptions"
            optionLabel="label"
            optionValue="value"
            :class="{ invalid: fieldError('default_price_level') }"
          />
          <small v-if="fieldError('default_price_level')" class="error-text">{{ fieldError('default_price_level') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Date of Birth') }}</label>
          <InputText type="date" v-model="form.date_of_birth" :class="{ invalid: fieldError('date_of_birth') }" />
          <small v-if="fieldError('date_of_birth')" class="error-text">{{ fieldError('date_of_birth') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Anniversary') }}</label>
          <InputText type="date" v-model="form.anniversary_date" :class="{ invalid: fieldError('anniversary_date') }" />
          <small v-if="fieldError('anniversary_date')" class="error-text">{{ fieldError('anniversary_date') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Referred By') }}</label>
          <Select
            v-model="form.referred_by_customer_id"
            :options="customerOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="pt('None')"
            showClear filter
            :class="{ invalid: fieldError('referred_by_customer_id') }"
          />
          <small v-if="fieldError('referred_by_customer_id')" class="error-text">{{ fieldError('referred_by_customer_id') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Credit Limit') }}</label>
          <InputNumber v-model="form.credit_limit" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('credit_limit') }" />
          <small v-if="fieldError('credit_limit')" class="error-text">{{ fieldError('credit_limit') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Opening Balance') }}</label>
          <InputNumber v-model="form.opening_balance" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('opening_balance') }" />
          <small v-if="fieldError('opening_balance')" class="error-text">{{ fieldError('opening_balance') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="cust_is_active" />
            <label for="cust_is_active">{{ pt('Active') }}</label>
          </div>
        </div>

        <div class="fld full">
          <label>{{ pt('Address') }}</label>
          <Textarea v-model="form.address" rows="2" :class="{ invalid: fieldError('address') }" />
          <small v-if="fieldError('address')" class="error-text">{{ fieldError('address') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('City') }}</label>
          <InputText v-model="form.city" :class="{ invalid: fieldError('city') }" />
          <small v-if="fieldError('city')" class="error-text">{{ fieldError('city') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('State') }}</label>
          <InputText v-model="form.state" :class="{ invalid: fieldError('state') }" />
          <small v-if="fieldError('state')" class="error-text">{{ fieldError('state') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Pincode') }}</label>
          <InputText v-model="form.pincode" :class="{ invalid: fieldError('pincode') }" />
          <small v-if="fieldError('pincode')" class="error-text">{{ fieldError('pincode') }}</small>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editing ? pt('Update') : pt('Create')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Purchase History dialog -->
    <Dialog
      v-model:visible="historyVisible"
      modal
      :header="pt('Purchase History — {name}', { name: historyCustomer?.name ?? '' })"
      :style="{ width: '780px' }"
    >
      <div class="stat-grid">
        <div class="stat-tile">
          <div class="stat-value">{{ historySummary?.total_orders ?? 0 }}</div>
          <div class="stat-label">{{ pt('Total Orders') }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(historySummary?.total_value) }}</div>
          <div class="stat-label">{{ pt('Lifetime Value') }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ historySummary?.open_orders ?? 0 }}</div>
          <div class="stat-label">{{ pt('Open Orders') }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(historySummary?.open_value) }}</div>
          <div class="stat-label">{{ pt('Open Value') }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value" :class="outstandingBalance > 0 ? 'balance-due' : 'balance-clear'">
            {{ money(historySummary?.outstanding_balance) }}
          </div>
          <div class="stat-label">{{ pt('Outstanding Balance') }}</div>
        </div>
      </div>

      <p class="summary-meta">{{ historyMeta }}</p>

      <div class="history-toolbar">
        <Select
          v-model="historyStatus"
          :options="historyStatusOptions"
          optionLabel="label"
          optionValue="value"
          :placeholder="pt('All')"
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
        <Column field="so_number" :header="pt('SO #')" />
        <Column field="order_date" :header="pt('Date')" />
        <Column :header="pt('Warehouse')">
          <template #body="{ data }">{{ data.warehouse?.name }}</template>
        </Column>
        <Column :header="pt('Total')">
          <template #body="{ data }">{{ money(data.total) }}</template>
        </Column>
        <Column :header="pt('Invoice')">
          <template #body="{ data }">
            <template v-if="data.invoice">
              <span class="invoice-number">{{ data.invoice.invoice_number }}</span>
              <Tag :severity="invoiceStatusColors[data.invoice.status]" :value="data.invoice.status" class="invoice-tag" />
            </template>
            <template v-else>—</template>
          </template>
        </Column>
        <Column :header="pt('Status')">
          <template #body="{ data }">
            <Tag :severity="statusColors[data.status]" :value="data.status" />
          </template>
        </Column>
        <template #empty>{{ pt('No sales orders for this customer yet.') }}</template>
      </DataTable>
    </Dialog>

    <!-- Account detail: ledger / payments / addresses / price list -->
    <CustomerDetailDialog v-model:visible="detailVisible" :customer="detailCustomer" :can-edit="canEdit" />

    <!-- B2B portal access -->
    <Dialog v-model:visible="portalVisible" modal :header="pt('B2B Portal Access — {name}', { name: portalCustomer?.name ?? '' })" :style="{ width: '460px' }">
      <p class="portal-hint">
        {{ pt('Let this customer sign in to the B2B portal to browse the catalogue, see their prices and live stock, and place orders for approval. They log in with their email') }}
        <strong>{{ portalCustomer?.email || pt('(no email set)') }}</strong> {{ pt('and the password you set here.') }}
      </p>

      <div class="checkbox-row">
        <Checkbox v-model="portalForm.portal_enabled" binary inputId="portal_enabled" />
        <label for="portal_enabled">{{ pt('Portal login enabled') }}</label>
      </div>

      <div class="fld full" style="margin-top: 1rem">
        <label>{{ portalCustomer?.has_portal_password ? pt('Reset password') : pt('Set password') }}</label>
        <InputText v-model="portalForm.password" type="password" autocomplete="new-password"
          :placeholder="portalCustomer?.has_portal_password ? pt('Leave blank to keep current') : pt('At least 6 characters')" />
        <small v-if="portalErrors.password" class="error-text">{{ portalErrors.password[0] }}</small>
        <small v-else-if="portalCustomer?.portal_last_login_at" class="muted-sub">
          {{ pt('Last login: {time}', { time: new Date(portalCustomer.portal_last_login_at).toLocaleString() }) }}
        </small>
      </div>

      <p v-if="portalError" class="error-text" style="margin-top: 0.75rem">{{ portalError }}</p>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="portalVisible = false" />
        <Button :label="pt('Save')" :loading="portalSaving" @click="savePortal" />
      </template>
    </Dialog>
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
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.toolbar-spacer {
  flex: 1 1 auto;
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
.cat-filter {
  min-width: 180px;
}
.amount-due {
  color: #dc2626;
  font-weight: 600;
}
.amount-clear {
  color: #16a34a;
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
  grid-template-columns: repeat(5, 1fr);
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
.stat-value.balance-due {
  color: #dc2626;
}
.stat-value.balance-clear {
  color: #16a34a;
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
.invoice-number {
  display: block;
  font-size: 0.82rem;
}
.invoice-tag {
  margin-top: 0.2rem;
}
.portal-hint {
  font-size: 0.85rem;
  color: #6b7280;
  margin-bottom: 1rem;
  line-height: 1.4;
}
</style>
