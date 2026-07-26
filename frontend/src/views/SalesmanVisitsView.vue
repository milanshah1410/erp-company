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
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(50)

const salesmen = ref<{ label: string; value: number }[]>([])
const customers = ref<{ label: string; value: number }[]>([])
const beats = ref<{ label: string; value: number }[]>([])

const STATUSES = [
  { label: 'Planned', value: 'planned' }, { label: 'Visited', value: 'visited' },
  { label: 'Skipped', value: 'skipped' }, { label: 'Cancelled', value: 'cancelled' },
]

const filters = ref<{ salesman_id: number | null; status: string | null; date: Date | null }>({
  salesman_id: null,
  status: null,
  date: new Date(),
})

const canCreate = computed(() => auth.can('salesman-visits.create'))
const canEdit = computed(() => auth.can('salesman-visits.edit'))
const canDelete = computed(() => auth.can('salesman-visits.delete'))

// --- Add visit dialog ---
const visitVisible = ref(false)
const savingVisit = ref(false)
const editingId = ref<number | null>(null)
const visitErrors = ref<Record<string, string[]>>({})
const visitForm = ref<any>({ salesman_id: null, customer_id: null, beat_id: null, visit_date: new Date(), purpose: '', notes: '' })

// --- Generate plan dialog ---
const planVisible = ref(false)
const savingPlan = ref(false)
const planForm = ref<any>({ beat_id: null, visit_date: new Date() })

// --- Check-in dialog ---
const checkinVisible = ref(false)
const savingCheckin = ref(false)
const checkinTarget = ref<any>(null)
const geoLoading = ref(false)
const checkinForm = ref<any>({ latitude: null, longitude: null, outcome: null, sales_order_id: null, notes: '' })
const customerOrders = ref<{ label: string; value: number }[]>([])
const ordersLoading = ref(false)

const OUTCOMES = [
  { label: 'Order booked', value: 'order' }, { label: 'Payment collected', value: 'collection' },
  { label: 'No order', value: 'no-order' }, { label: 'Closed / not available', value: 'closed' },
]

function statusSeverity(s: string) {
  return { planned: 'info', visited: 'success', skipped: 'warn', cancelled: 'danger' }[s] || 'secondary'
}
function fieldError(errs: Record<string, string[]>, key: string): string | null {
  return errs[key]?.[0] ?? null
}
function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}
function fmtTime(v: string | null) {
  if (!v) return '—'
  const d = new Date(v)
  return isNaN(d.getTime()) ? '—' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function loadOptions() {
  const [s, c, b] = await Promise.all([
    http.get('/salesmen', { params: { per_page: 100, is_active: true } }),
    http.get('/customers', { params: { per_page: 200 } }),
    http.get('/beats', { params: { per_page: 100 } }),
  ])
  salesmen.value = (s.data.data ?? s.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  beats.value = (b.data.data ?? b.data).map((x: any) => ({ label: `${x.name}${x.salesman ? ' · ' + x.salesman : ''}`, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salesman-visits', {
      params: {
        salesman_id: filters.value.salesman_id || undefined,
        status: filters.value.status || undefined,
        date: toDateString(filters.value.date),
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

function openAdd() {
  editingId.value = null
  visitForm.value = { salesman_id: filters.value.salesman_id, customer_id: null, beat_id: null, visit_date: filters.value.date || new Date(), purpose: '', notes: '' }
  visitErrors.value = {}
  visitVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  visitForm.value = {
    salesman_id: row.salesman_id, customer_id: row.customer_id, beat_id: row.beat_id,
    visit_date: row.visit_date ? new Date(row.visit_date) : new Date(), purpose: row.purpose || '', notes: row.notes || '',
  }
  visitErrors.value = {}
  visitVisible.value = true
}

async function saveVisit() {
  savingVisit.value = true
  visitErrors.value = {}
  try {
    const payload = {
      salesman_id: visitForm.value.salesman_id,
      customer_id: visitForm.value.customer_id,
      beat_id: visitForm.value.beat_id || undefined,
      visit_date: toDateString(visitForm.value.visit_date),
      purpose: visitForm.value.purpose || undefined,
      notes: visitForm.value.notes || undefined,
    }
    if (editingId.value) {
      await http.put(`/salesman-visits/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Visit updated', life: 2500 })
    } else {
      await http.post('/salesman-visits', payload)
      toast.add({ severity: 'success', summary: 'Visit added', life: 2500 })
    }
    visitVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) visitErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    savingVisit.value = false
  }
}

function openPlan() {
  planForm.value = { beat_id: null, visit_date: filters.value.date || new Date() }
  planVisible.value = true
}
async function savePlan() {
  savingPlan.value = true
  try {
    const { data } = await http.post('/salesman-visits/generate-plan', {
      beat_id: planForm.value.beat_id,
      visit_date: toDateString(planForm.value.visit_date),
    })
    toast.add({ severity: 'success', summary: data.message || 'Plan generated', life: 3000 })
    planVisible.value = false
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not generate plan', life: 4000 })
  } finally {
    savingPlan.value = false
  }
}

function openCheckin(row: any) {
  checkinTarget.value = row
  checkinForm.value = { latitude: null, longitude: null, outcome: null, sales_order_id: null, notes: '' }
  customerOrders.value = []
  checkinVisible.value = true
  captureLocation()
}

// Load this customer's recent orders so a booked order can be attached to the
// visit (Order Collection). Called when the "Order booked" outcome is chosen.
async function loadCustomerOrders() {
  if (!checkinTarget.value?.customer_id) return
  ordersLoading.value = true
  try {
    const { data } = await http.get('/sales-orders', {
      params: { customer_id: checkinTarget.value.customer_id, per_page: 50 },
    })
    customerOrders.value = (data.data ?? data).map((o: any) => ({
      label: `${o.so_number} · ${o.order_date ?? ''}`,
      value: o.id,
    }))
  } catch {
    customerOrders.value = []
  } finally {
    ordersLoading.value = false
  }
}
function onOutcomeChange() {
  if (checkinForm.value.outcome === 'order' && !customerOrders.value.length) {
    loadCustomerOrders()
  }
}
function captureLocation() {
  if (!navigator.geolocation) return
  geoLoading.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      checkinForm.value.latitude = Number(pos.coords.latitude.toFixed(7))
      checkinForm.value.longitude = Number(pos.coords.longitude.toFixed(7))
      geoLoading.value = false
    },
    () => {
      geoLoading.value = false
      toast.add({ severity: 'warn', summary: 'Location unavailable', detail: 'Enter coordinates manually or continue without.', life: 3500 })
    },
    { enableHighAccuracy: true, timeout: 8000 },
  )
}
async function saveCheckin() {
  if (!checkinTarget.value) return
  savingCheckin.value = true
  try {
    await http.post(`/salesman-visits/${checkinTarget.value.id}/check-in`, {
      latitude: checkinForm.value.latitude ?? undefined,
      longitude: checkinForm.value.longitude ?? undefined,
      outcome: checkinForm.value.outcome || undefined,
      sales_order_id: checkinForm.value.outcome === 'order' ? checkinForm.value.sales_order_id || undefined : undefined,
      notes: checkinForm.value.notes || undefined,
    })
    toast.add({ severity: 'success', summary: 'Checked in', life: 2500 })
    checkinVisible.value = false
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Check-in failed', life: 4000 })
  } finally {
    savingCheckin.value = false
  }
}
async function checkOut(row: any) {
  try {
    await http.post(`/salesman-visits/${row.id}/check-out`)
    toast.add({ severity: 'success', summary: 'Checked out', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Check-out failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this visit?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/salesman-visits/${row.id}`)
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
    <PageHeader title="Daily Visit Plan">
      <template #actions>
        <div class="header-actions">
        <Button v-if="canCreate" label="Generate from Beat" icon="pi pi-map" outlined @click="openPlan" />
        <Button v-if="canCreate" label="Add Visit" icon="pi pi-plus" @click="openAdd" />
        </div>
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filters.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter showClear
        placeholder="All salesmen" class="w-60" @change="load" />
      <DatePicker v-model="filters.date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" showButtonBar @update:modelValue="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="load" />
      <TableExportButtons title="Daily Visit Plan" endpoint="/salesman-visits"
        :params="() => ({
          salesman_id: filters.salesman_id || undefined,
          status: filters.status || undefined,
          date: toDateString(filters.date),
        })"
        :columns="[
          { key: 'sequence', label: '#' },
          { key: 'visit_date', label: 'Date' },
          { key: 'salesman', label: 'Salesman' },
          { key: 'customer', label: 'Customer' },
          { key: 'beat', label: 'Beat' },
          { key: 'check_in_at', label: 'Check-in' },
          { key: 'check_out_at', label: 'Check-out' },
          { key: 'outcome', label: 'Outcome' },
          { key: 'sales_order_number', label: 'Order' },
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
      <Column header="#" style="width: 3rem">
        <template #body="{ data }">{{ data.sequence || '—' }}</template>
      </Column>
      <Column field="visit_date" header="Date" />
      <Column field="salesman" header="Salesman" />
      <Column field="customer" header="Customer" />
      <Column field="beat" header="Beat" />
      <Column header="Check-in">
        <template #body="{ data }">{{ fmtTime(data.check_in_at) }}</template>
      </Column>
      <Column header="Check-out">
        <template #body="{ data }">{{ fmtTime(data.check_out_at) }}</template>
      </Column>
      <Column field="outcome" header="Outcome" />
      <Column header="Order">
        <template #body="{ data }">
          <span v-if="data.sales_order_number">{{ data.sales_order_number }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 13rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canEdit && data.status === 'planned'" label="Check in" icon="pi pi-map-marker" text size="small" @click="openCheckin(data)" />
          <Button v-if="canEdit && data.status === 'visited' && !data.check_out_at" label="Check out" icon="pi pi-sign-out" text size="small" @click="checkOut(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No visits for the selected filters.</template>
    </DataTable>

    <!-- Add / edit visit -->
    <Dialog v-model:visible="visitVisible" modal :header="editingId ? 'Edit Visit' : 'Add Visit'" :style="{ width: '480px' }">
      <div class="form">
        <div class="field">
          <label>Salesman <span class="req">*</span></label>
          <Select v-model="visitForm.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter
            placeholder="Select salesman" :class="{ invalid: fieldError(visitErrors, 'salesman_id') }" />
          <small v-if="fieldError(visitErrors, 'salesman_id')" class="error-text">{{ fieldError(visitErrors, 'salesman_id') }}</small>
        </div>
        <div class="field">
          <label>Customer <span class="req">*</span></label>
          <Select v-model="visitForm.customer_id" :options="customers" optionLabel="label" optionValue="value" filter
            placeholder="Select customer" :class="{ invalid: fieldError(visitErrors, 'customer_id') }" />
          <small v-if="fieldError(visitErrors, 'customer_id')" class="error-text">{{ fieldError(visitErrors, 'customer_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Date <span class="req">*</span></label>
            <DatePicker v-model="visitForm.visit_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
          <div class="field">
            <label>Beat</label>
            <Select v-model="visitForm.beat_id" :options="beats" optionLabel="label" optionValue="value" filter showClear placeholder="None" />
          </div>
        </div>
        <div class="field">
          <label>Purpose</label>
          <InputText v-model="visitForm.purpose" />
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="visitForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="visitVisible = false" />
        <Button :label="editingId ? 'Update' : 'Add'" :loading="savingVisit" @click="saveVisit" />
      </template>
    </Dialog>

    <!-- Generate plan from beat -->
    <Dialog v-model:visible="planVisible" modal header="Generate Visit Plan" :style="{ width: '440px' }">
      <div class="form">
        <p class="ctx">Creates one planned visit per customer on the beat, in route order.</p>
        <div class="field">
          <label>Beat <span class="req">*</span></label>
          <Select v-model="planForm.beat_id" :options="beats" optionLabel="label" optionValue="value" filter placeholder="Select beat" />
        </div>
        <div class="field">
          <label>Visit Date <span class="req">*</span></label>
          <DatePicker v-model="planForm.visit_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="planVisible = false" />
        <Button label="Generate" :loading="savingPlan" :disabled="!planForm.beat_id" @click="savePlan" />
      </template>
    </Dialog>

    <!-- Check in -->
    <Dialog v-model:visible="checkinVisible" modal header="Check In" :style="{ width: '440px' }">
      <div v-if="checkinTarget" class="form">
        <p class="ctx">{{ checkinTarget.customer }} · {{ checkinTarget.visit_date }}</p>
        <div class="field">
          <label>GPS Location <Button label="Use my location" icon="pi pi-map-marker" text size="small" :loading="geoLoading" @click="captureLocation" /></label>
          <div class="row">
            <InputNumber v-model="checkinForm.latitude" :maxFractionDigits="7" placeholder="Latitude" />
            <InputNumber v-model="checkinForm.longitude" :maxFractionDigits="7" placeholder="Longitude" />
          </div>
        </div>
        <div class="field">
          <label>Outcome</label>
          <Select v-model="checkinForm.outcome" :options="OUTCOMES" optionLabel="label" optionValue="value" showClear
            placeholder="Select outcome" @change="onOutcomeChange" />
        </div>
        <div v-if="checkinForm.outcome === 'order'" class="field">
          <label>Order booked <Button label="Refresh" icon="pi pi-refresh" text size="small" :loading="ordersLoading" @click="loadCustomerOrders" /></label>
          <Select v-model="checkinForm.sales_order_id" :options="customerOrders" optionLabel="label" optionValue="value" filter showClear
            :placeholder="ordersLoading ? 'Loading…' : 'Link a sales order'" :emptyMessage="'No orders for this customer yet'" />
          <small class="hint">Create the order under Sales → Sales Orders (with this salesman), then link it here.</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="checkinForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="checkinVisible = false" />
        <Button label="Check In" icon="pi pi-check" :loading="savingCheckin" @click="saveCheckin" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Visit Details" :fields="[
      { key: 'sequence', label: 'Sequence' },
      { key: 'visit_date', label: 'Date' },
      { key: 'salesman', label: 'Salesman' },
      { key: 'customer', label: 'Customer' },
      { key: 'beat', label: 'Beat' },
      { key: 'check_in_at', label: 'Check-in', format:(v)=>fmtTime(v) },
      { key: 'check_out_at', label: 'Check-out', format:(v)=>fmtTime(v) },
      { key: 'outcome', label: 'Outcome' },
      { key: 'sales_order_number', label: 'Order' },
      { key: 'status', label: 'Status' },
      { key: 'purpose', label: 'Purpose', full: true },
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
.header-actions { display: flex; gap: 0.5rem; }
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.w-60 { width: 240px; }
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
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.ctx {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  padding: 0.65rem 0.85rem;
  font-size: 0.9rem;
  color: #334155;
  margin: 0;
}
.req { color: #dc2626; }
.hint { color: #64748b; font-size: 0.75rem; }
.muted { color: #94a3b8; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
