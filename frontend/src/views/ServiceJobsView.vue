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
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/service-jobs')

const canCreate = computed(() => auth.can('service-jobs.create'))
const canEdit = computed(() => auth.can('service-jobs.edit'))
// Completing raises the invoice, so it rides on the same authority as delete.
const canClose = computed(() => auth.can('service-jobs.delete'))

const customers = ref<any[]>([])
const warehouses = ref<any[]>([])
const employees = ref<any[]>([])
const products = ref<any[]>([])
const contracts = ref<any[]>([])

const summary = ref({ open: 0, overdue: 0, unbilled_value: 0, by_status: {} as Record<string, number> })

const search = ref('')
const statusFilter = ref<string | null>(null)
const openOnly = ref(true)
const overdueOnly = ref(false)

const STATUSES = [
  { label: 'Received', value: 'received' },
  { label: 'Assigned', value: 'assigned' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'On Hold', value: 'on_hold' },
  { label: 'Completed', value: 'completed' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]
const PRIORITIES = [
  { label: 'Low', value: 'low' },
  { label: 'Normal', value: 'normal' },
  { label: 'High', value: 'high' },
  { label: 'Urgent', value: 'urgent' },
]

const statusColors: Record<string, string> = {
  received: 'secondary',
  assigned: 'info',
  in_progress: 'info',
  on_hold: 'warn',
  completed: 'success',
  delivered: 'contrast',
  cancelled: 'danger',
}
const priorityColors: Record<string, string> = {
  low: 'secondary',
  normal: 'info',
  high: 'warn',
  urgent: 'danger',
}

function money(v: any) {
  return formatCurrency(v)
}

function load() {
  return crud.fetch({
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    open_only: openOnly.value ? 1 : undefined,
    overdue_only: overdueOnly.value ? 1 : undefined,
  })
}

async function loadSummary() {
  const { data } = await http.get('/service-jobs', { params: { per_page: 1 } })
  if (data.summary) summary.value = data.summary
}

async function refresh() {
  await Promise.all([load(), loadSummary()])
}

async function loadLookups() {
  const [c, w, e, p, k] = await Promise.all([
    http.get('/customers', { params: { per_page: 200, is_active: true } }),
    http.get('/warehouses', { params: { per_page: 100, is_active: true } }),
    http.get('/employees', { params: { per_page: 200, is_active: true } }).catch(() => ({ data: { data: [] } })),
    http.get('/products', { params: { per_page: 300, is_active: true } }),
    http.get('/service-contracts', { params: { per_page: 200, status: 'active' } }).catch(() => ({ data: { data: [] } })),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: [x.first_name, x.last_name].filter(Boolean).join(' ') || x.employee_code,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    selling_price: Number(x.selling_price) || 0,
    tax_percent: Number(x.tax_percent) || 0,
    is_service: !!x.is_service,
  }))
  // Only contracts that can actually take a job today are offerable — a lapsed
  // one is rejected server-side anyway.
  contracts.value = (k.data.data ?? k.data)
    .filter((x: any) => x.covers_today)
    .map((x: any) => ({
      label: `${x.contract_number} — ${x.title}`,
      value: x.id,
      customer_id: x.customer_id,
    }))
}

// ---- Create / edit -----------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  service_contract_id: null as number | null,
  warehouse_id: null as number | null,
  assigned_to: null as number | null,
  priority: 'normal',
  item_name: '',
  item_serial: '',
  complaint: '',
  diagnosis: '',
  resolution: '',
  is_warranty: false,
  promised_at: null as Date | null,
  labour_charge: 0,
  labour_tax_percent: 0,
  discount_type: null as string | null,
  discount_value: 0,
  notes: '',
  items: [] as any[],
})
const form = ref(blank())

/** Contracts belonging to the chosen customer — booking someone else's is not a thing. */
const customerContracts = computed(() =>
  contracts.value.filter((c) => c.customer_id === form.value.customer_id),
)

// Mirrors the server's pricing closely enough to show the counter a number
// before it saves; the saved totals always come back from the API.
const previewTotal = computed(() => {
  const lines = form.value.items.map((i: any) => {
    const base = (Number(i.quantity) || 0) * (Number(i.unit_price) || 0)
    return base + (base * (Number(i.tax_percent) || 0)) / 100
  })
  const labour =
    (Number(form.value.labour_charge) || 0) *
    (1 + (Number(form.value.labour_tax_percent) || 0) / 100)
  return lines.reduce((s, v) => s + v, 0) + labour
})

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    ...blank(),
    customer_id: row.customer_id,
    service_contract_id: row.service_contract_id,
    warehouse_id: row.warehouse_id,
    assigned_to: row.assigned_to,
    priority: row.priority,
    item_name: row.item_name,
    item_serial: row.item_serial ?? '',
    complaint: row.complaint ?? '',
    diagnosis: row.diagnosis ?? '',
    resolution: row.resolution ?? '',
    is_warranty: row.is_warranty,
    promised_at: row.promised_at ? new Date(row.promised_at) : null,
    labour_charge: row.labour_charge,
    labour_tax_percent: row.labour_tax_percent,
    discount_type: row.discount_type,
    discount_value: row.discount_value,
    notes: row.notes ?? '',
    items: (row.items ?? []).map((i: any) => ({ ...i })),
  }
  dialogVisible.value = true
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, unit_price: 0, tax_percent: 0, is_issued: false })
}
function removeLine(i: number) {
  form.value.items.splice(i, 1)
}
function onProductPicked(line: any) {
  const p = products.value.find((x) => x.value === line.product_id)
  if (!p) return
  line.unit_price = p.selling_price
  line.tax_percent = p.tax_percent
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      promised_at: form.value.promised_at ? form.value.promised_at.toISOString() : null,
      items: form.value.items
        .filter((i: any) => i.product_id)
        .map((i: any) => ({
          // Sent so the server can recognise an already-issued line and leave
          // it alone instead of creating a duplicate of it.
          id: i.id ?? null,
          product_id: i.product_id,
          variant_id: i.variant_id ?? null,
          quantity: i.quantity,
          unit_price: i.unit_price,
          tax_percent: i.tax_percent,
          discount_type: i.discount_type ?? null,
          discount_value: i.discount_value ?? 0,
        })),
    }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Service job saved'), life: 2500 })
    dialogVisible.value = false
    await refresh()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the job'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

// ---- Lifecycle actions -------------------------------------------------------

async function act(row: any, action: string, body: Record<string, any> = {}, confirmText?: string) {
  if (confirmText) {
    const ok = await Swal.fire({
      title: confirmText,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: pt('Yes'),
      cancelButtonText: pt('Cancel'),
    })
    if (!ok.isConfirmed) return
  }
  try {
    const { data } = await http.post(`/service-jobs/${row.id}/${action}`, body)
    const extra =
      action === 'complete' && data.invoice_raised
        ? pt('Invoice raised')
        : action === 'issue'
          ? pt('{n} line(s) issued', { n: data.issued_lines })
          : ''
    toast.add({ severity: 'success', summary: pt('Job updated'), detail: extra, life: 3000 })
    await refresh()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Action failed'),
      life: 4000,
    })
  }
}

// The technician picker for "assign", asked inline rather than in its own dialog.
const assignFor = ref<any>(null)
const assignTo = ref<number | null>(null)

function openAssign(row: any) {
  assignFor.value = row
  assignTo.value = row.assigned_to
}
async function confirmAssign() {
  if (!assignFor.value || !assignTo.value) return
  await act(assignFor.value, 'assign', { assigned_to: assignTo.value })
  assignFor.value = null
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this job?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Job deleted'), life: 2500 })
    await refresh()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

function when(v: string | null) {
  return v ? new Date(v).toLocaleDateString() : '—'
}

onMounted(async () => {
  await loadLookups()
  await refresh()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Service Jobs')"
      :subtitle="pt('Repairs and installations on customer items — intake, technician, parts and billing')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open jobs') }}</span>
        <strong class="t-value">{{ summary.open }}</strong>
      </div>
      <div class="tile" :class="{ alert: summary.overdue > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <strong class="t-value">{{ summary.overdue }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">{{ pt('Unbilled value') }}</span>
        <strong class="t-value">{{ money(summary.unbilled_value) }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Job no, item, serial or customer')" @keyup.enter="refresh" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="filter"
        @change="refresh"
      />
      <label class="inline-check">
        <Checkbox v-model="openOnly" binary @change="refresh" /> {{ pt('Open only') }}
      </label>
      <label class="inline-check">
        <Checkbox v-model="overdueOnly" binary @change="refresh" /> {{ pt('Overdue only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="refresh" />
      <Button v-if="canCreate" :label="pt('New Job')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Service Jobs"
        filenameBase="service-jobs"
        :rows="crud.items.value"
        :columns="[
          { key: 'job_number', label: 'Job No' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'item_name', label: 'Item' },
          { key: 'status', label: 'Status' },
          { key: 'priority', label: 'Priority' },
          { key: 'technician.name', label: 'Technician' },
          { key: 'total', label: 'Total' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Job')">
        <template #body="{ data }">
          <strong>{{ data.job_number }}</strong>
          <br /><small class="muted">{{ when(data.received_at) }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">
          {{ data.customer?.name }}
          <br /><small class="muted">{{ data.customer?.phone }}</small>
        </template>
      </Column>
      <Column :header="pt('Item')">
        <template #body="{ data }">
          {{ data.item_name }}
          <br /><small class="muted">{{ data.item_serial || '—' }}</small>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="statusColors[data.status]" />
          <Tag v-if="data.is_overdue" :value="pt('Overdue')" severity="danger" class="ml-1" />
          <Tag v-if="data.is_warranty" :value="pt('Warranty')" severity="secondary" class="ml-1" />
          <Tag v-if="data.service_contract_id" :value="pt('AMC')" severity="secondary" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Priority')">
        <template #body="{ data }"><Tag :value="data.priority" :severity="priorityColors[data.priority]" /></template>
      </Column>
      <Column :header="pt('Technician')">
        <template #body="{ data }">{{ data.technician?.name ?? '—' }}</template>
      </Column>
      <Column :header="pt('Total')">
        <template #body="{ data }">
          {{ money(data.total) }}
          <br v-if="data.invoice" /><small v-if="data.invoice" class="muted">{{ data.invoice.invoice_number }}</small>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 15rem">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              v-if="canEdit && !['delivered', 'cancelled'].includes(data.status)"
              icon="pi pi-pencil"
              text
              size="small"
              :title="pt('Edit')"
              @click="openEdit(data)"
            />
            <Button
              v-if="canEdit && !['delivered', 'cancelled'].includes(data.status)"
              icon="pi pi-user"
              text
              size="small"
              :title="pt('Assign technician')"
              @click="openAssign(data)"
            />
            <Button
              v-if="canEdit && ['received', 'assigned', 'on_hold'].includes(data.status)"
              icon="pi pi-play"
              text
              size="small"
              :title="pt('Start work')"
              @click="act(data, 'progress', { status: 'in_progress' })"
            />
            <Button
              v-if="canEdit && data.status === 'in_progress'"
              icon="pi pi-pause"
              text
              size="small"
              :title="pt('Put on hold')"
              @click="act(data, 'progress', { status: 'on_hold' })"
            />
            <Button
              v-if="canEdit && !['delivered', 'cancelled', 'completed'].includes(data.status)"
              icon="pi pi-box"
              text
              size="small"
              :title="pt('Issue parts')"
              @click="act(data, 'issue')"
            />
            <Button
              v-if="canClose && !['completed', 'delivered', 'cancelled'].includes(data.status)"
              icon="pi pi-check"
              text
              size="small"
              severity="success"
              :title="pt('Complete & bill')"
              @click="act(data, 'complete', {}, pt('Complete this job and raise its invoice?'))"
            />
            <Button
              v-if="canClose && data.status === 'completed'"
              icon="pi pi-send"
              text
              size="small"
              severity="success"
              :title="pt('Deliver')"
              @click="act(data, 'deliver', {}, pt('Hand the item back to the customer?'))"
            />
            <Button
              v-if="canClose && !['delivered', 'cancelled'].includes(data.status)"
              icon="pi pi-times"
              text
              size="small"
              severity="danger"
              :title="pt('Cancel job')"
              @click="act(data, 'cancel', {}, pt('Cancel this job?'))"
            />
            <Button
              v-if="canClose && !data.invoice_id"
              icon="pi pi-trash"
              text
              size="small"
              severity="danger"
              :title="pt('Delete')"
              @click="remove(data)"
            />
          </div>
        </template>
      </Column>
      <template #empty>{{ pt('No service jobs match this view.') }}</template>
    </DataTable>

    <!-- Assign technician -->
    <Dialog
      :visible="!!assignFor"
      modal
      :header="pt('Assign technician')"
      :style="{ width: '24rem' }"
      @update:visible="assignFor = null"
    >
      <Select
        v-model="assignTo"
        :options="employees"
        optionLabel="label"
        optionValue="value"
        filter
        :placeholder="pt('Select a technician')"
        class="w-full"
      />
      <template #footer>
        <Button :label="pt('Cancel')" text @click="assignFor = null" />
        <Button :label="pt('Assign')" icon="pi pi-check" :disabled="!assignTo" @click="confirmAssign" />
      </template>
    </Dialog>

    <!-- Create / edit -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Service Job') : pt('New Service Job')"
      :style="{ width: '56rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Customer') }} *</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter class="w-full" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('AMC contract') }}</label>
          <Select
            v-model="form.service_contract_id"
            :options="customerContracts"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="customerContracts.length ? pt('None') : pt('No active contract')"
            :disabled="!customerContracts.length"
            class="w-full"
          />
          <small v-if="errors.service_contract_id" class="err">{{ errors.service_contract_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Parts warehouse') }}</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" showClear class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Technician') }}</label>
          <Select v-model="form.assigned_to" :options="employees" optionLabel="label" optionValue="value" filter showClear class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Item') }} *</label>
          <InputText v-model="form.item_name" class="w-full" :placeholder="pt('e.g. Split AC 1.5T')" />
          <small v-if="errors.item_name" class="err">{{ errors.item_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Serial / IMEI') }}</label>
          <InputText v-model="form.item_serial" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select v-model="form.priority" :options="PRIORITIES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Promised by') }}</label>
          <DatePicker v-model="form.promised_at" showTime hourFormat="24" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Complaint') }}</label>
          <Textarea v-model="form.complaint" rows="2" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Diagnosis') }}</label>
          <Textarea v-model="form.diagnosis" rows="2" class="w-full" />
        </div>
        <div v-if="editing" class="fld span-2">
          <label>{{ pt('Resolution') }}</label>
          <Textarea v-model="form.resolution" rows="2" class="w-full" />
        </div>
      </div>

      <h4 class="sec">{{ pt('Spare parts') }}</h4>
      <div v-for="(line, i) in form.items" :key="i" class="line">
        <Select
          v-model="line.product_id"
          :options="products"
          optionLabel="label"
          optionValue="value"
          filter
          :placeholder="pt('Part')"
          class="l-product"
          :disabled="line.is_issued"
          @change="onProductPicked(line)"
        />
        <InputNumber v-model="line.quantity" :min="0" :maxFractionDigits="3" :placeholder="pt('Qty')" class="l-qty" :disabled="line.is_issued" />
        <InputNumber v-model="line.unit_price" :min="0" :maxFractionDigits="2" :placeholder="pt('Rate')" class="l-rate" :disabled="line.is_issued" />
        <InputNumber v-model="line.tax_percent" :min="0" :max="100" suffix=" %" class="l-tax" :disabled="line.is_issued" />
        <Tag v-if="line.is_issued" :value="pt('Issued')" severity="success" />
        <Button v-else icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" />
      </div>
      <Button :label="pt('Add part')" icon="pi pi-plus" text size="small" @click="addLine" />
      <p v-if="form.items.some((l: any) => l.is_issued)" class="hint">
        {{ pt('Issued lines have already left stock and can no longer be changed here.') }}
      </p>

      <h4 class="sec">{{ pt('Charges') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Labour charge') }}</label>
          <InputNumber v-model="form.labour_charge" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Labour tax %') }}</label>
          <InputNumber v-model="form.labour_tax_percent" :min="0" :max="100" suffix=" %" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Discount type') }}</label>
          <Select
            v-model="form.discount_type"
            :options="[{ label: pt('Percent'), value: 'percent' }, { label: pt('Flat'), value: 'flat' }]"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Discount value') }}</label>
          <InputNumber v-model="form.discount_value" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld check-fld">
          <label for="svc-warranty">{{ pt('Under warranty (do not bill)') }}</label>
          <Checkbox v-model="form.is_warranty" binary inputId="svc-warranty" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <div class="preview">
        <span>{{ pt('Estimated total (before job discount)') }}</span>
        <strong>{{ money(previewTotal) }}</strong>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.alert { border-color: #dc2626; background: rgba(220, 38, 38, 0.06); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.filter { width: 180px; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.25rem; }
.row-actions { display: flex; flex-wrap: wrap; gap: 0.1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.check-fld { flex-direction: row; align-items: center; gap: 0.5rem; }
.w-full { width: 100%; }
.sec { margin: 1.1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.line { display: flex; gap: 0.4rem; align-items: center; margin-bottom: 0.4rem; flex-wrap: wrap; }
.l-product { flex: 1 1 16rem; min-width: 12rem; }
.l-qty { width: 6.5rem; }
.l-rate { width: 7.5rem; }
.l-tax { width: 6.5rem; }
.hint { font-size: 0.75rem; color: #8a6d3b; margin-top: 0.35rem; }
.err { color: #b91c1c; font-size: 0.75rem; }
.preview { display: flex; justify-content: space-between; align-items: baseline; margin-top: 1rem; padding-top: 0.6rem; border-top: 1px solid var(--surface-border, #e5e7eb); font-size: 0.9rem; }
.preview strong { font-size: 1.1rem; }
</style>
