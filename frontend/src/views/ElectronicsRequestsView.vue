<script setup lang="ts">
/**
 * The service-request desk.
 *
 * This is a screen somebody sits at all day with a phone, so it is arranged
 * around a call rather than around a table. Unscheduled work sorts to the top,
 * because a request with no slot is the one nobody has promised yet and the one
 * most likely to be dropped; overdue is a tile and a filter, because "we said
 * before lunch" is the promise this desk breaks.
 *
 * Every row ends one of two ways and the screen says which: a repair, an
 * installation or an AMC visit becomes a job card, and a delivery or a demo is
 * closed off the desk. Keeping the second kind out of the job book is what makes
 * the job book worth reading.
 */
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
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('electronics-requests.create'))
const canEdit = computed(() => auth.can('electronics-requests.edit'))
const canDelete = computed(() => auth.can('electronics-requests.delete'))
const canOpenJob = computed(() => auth.can('service-jobs.create'))

const TYPES = [
  { label: 'Repair', value: 'repair' },
  { label: 'Installation', value: 'installation' },
  { label: 'AMC visit', value: 'amc_visit' },
  { label: 'Delivery', value: 'delivery' },
  { label: 'Demo', value: 'demo' },
]

const SOURCES = [
  { label: 'Phone', value: 'phone' },
  { label: 'Walk-in', value: 'walk_in' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Online', value: 'online' },
  { label: 'Email', value: 'email' },
  { label: 'Campaign', value: 'campaign' },
]

const PRIORITIES = [
  { label: 'Low', value: 'low' },
  { label: 'Normal', value: 'normal' },
  { label: 'High', value: 'high' },
  { label: 'Urgent', value: 'urgent' },
]

const STATUS_SEVERITY: Record<string, string> = {
  logged: 'warn',
  scheduled: 'info',
  assigned: 'info',
  converted: 'success',
  closed: 'secondary',
  cancelled: 'danger',
}

const rows = ref<any[]>([])
const summary = ref({ open: 0, today: 0, unscheduled: 0, overdue: 0 })
const loading = ref(false)
const units = ref<any[]>([])
const customers = ref<any[]>([])
const products = ref<any[]>([])
const technicians = ref<any[]>([])
const search = ref('')
const typeFilter = ref<string | null>(null)
const openOnly = ref(true)
const overdueOnly = ref(false)

function typeLabel(v: string) {
  return TYPES.find((t) => t.value === v)?.label ?? v
}

function when(iso: string | null) {
  if (!iso) return null
  return new Date(iso).toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/electronics/requests', {
      params: {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        open: openOnly.value ? 1 : undefined,
        overdue: overdueOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [u, c, p] = await Promise.all([
    http.get('/electronics/units', { params: { per_page: 500 } }),
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  units.value = (u.data.data ?? u.data).map((x: any) => ({
    label: x.label,
    value: x.id,
    customer_id: x.customer_id,
  }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: x.phone ? `${x.name} (${x.phone})` : x.name,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
  }))

  /**
   * Technicians come from the HR employee master, which a two-person shop may
   * not run at all. An empty picker is the right outcome there — the request is
   * still logged and scheduled, just not assigned to a named person.
   */
  try {
    const { data } = await http.get('/employees', { params: { per_page: 200, is_active: 1 } })
    technicians.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
  } catch {
    technicians.value = []
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  type: 'repair',
  source: 'phone',
  priority: 'normal',
  unit_id: null as number | null,
  customer_id: null as number | null,
  product_id: null as number | null,
  scheduled_at: null as Date | null,
  promised_at: null as Date | null,
  assigned_to: null as number | null,
  is_onsite: true,
  address: '',
  contact_phone: '',
  complaint: '',
  estimated_cost: null as number | null,
  notes: '',
})

const form = ref(blank())

function asDateTime(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`
}

// Naming the machine names its owner too — the register already knows, and
// re-picking the customer is how a request ends up billed to the wrong person.
function onUnitPicked() {
  const u = units.value.find((x) => x.value === form.value.unit_id)
  if (u?.customer_id && !form.value.customer_id) form.value.customer_id = u.customer_id
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/electronics/requests/${row.id}`)
  const r = data.data
  editingId.value = r.id
  form.value = {
    type: r.type,
    source: r.source,
    priority: r.priority,
    unit_id: r.unit_id,
    customer_id: r.customer_id,
    product_id: r.product_id,
    scheduled_at: r.scheduled_at ? new Date(r.scheduled_at) : null,
    promised_at: r.promised_at ? new Date(r.promised_at) : null,
    assigned_to: r.assigned_to,
    is_onsite: !!r.is_onsite,
    address: r.address ?? '',
    contact_phone: r.contact_phone ?? '',
    complaint: r.complaint ?? '',
    estimated_cost: r.estimated_cost ?? null,
    notes: r.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      scheduled_at: asDateTime(form.value.scheduled_at),
      promised_at: asDateTime(form.value.promised_at),
      estimated_cost: form.value.estimated_cost ?? 0,
    }
    if (editingId.value) {
      await http.put(`/electronics/requests/${editingId.value}`, payload)
    } else {
      await http.post('/electronics/requests', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Request saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the request'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

// ---- Board actions --------------------------------------------------------

async function progress(row: any, status: string, extra: Record<string, unknown> = {}) {
  try {
    await http.post(`/electronics/requests/${row.id}/progress`, { status, ...extra })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

/** Hand it to the workshop. The reply names the job card that was opened. */
async function convert(row: any) {
  const res = await Swal.fire({
    title: pt('Open a job card?'),
    text: pt('The machine comes into the shop’s hands, and any live cover is picked up automatically.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Open job card'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/electronics/requests/${row.id}/convert`, {})
    await load()
    toast.add({
      severity: 'success',
      summary: data.message,
      detail: data.job?.is_warranty ? pt('Opened as warranty work — not billed.') : undefined,
      life: 4000,
    })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 5000 })
  }
}

/** Finish one that never needed a workshop — most deliveries and demos. */
async function close(row: any) {
  const res = await Swal.fire({
    title: pt('Close this request?'),
    text: pt('Use this when it was finished without a job card.'),
    input: 'text',
    inputPlaceholder: pt('What happened'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Close'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/electronics/requests/${row.id}/close`, { closure_note: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this request?'),
    text: pt('Only requests that never became a job card can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/electronics/requests/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Service Requests')"
      :subtitle="pt('Installations, repairs, deliveries and demos — from the call to the job card')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Log Request')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open') }}</span>
        <span class="t-value">{{ summary.open }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Today') }}</span>
        <span class="t-value">{{ summary.today }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Not scheduled') }}</span>
        <span class="t-value warn">{{ summary.unscheduled }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Past the promise') }}</span>
        <span class="t-value bad">{{ summary.overdue }}</span>
      </div>
    </div>

    <Message v-if="summary.unscheduled" severity="warn" :closable="false">
      {{
        pt(
          'Requests with no slot sort to the top of the list. Nobody has been promised a time for them yet.',
        )
      }}
    </Message>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Request number, customer, phone or fault')"
        @keyup.enter="load"
      />
      <Select
        v-model="typeFilter"
        :options="[{ label: pt('All types'), value: null }, ...TYPES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('Open only')"
        :outlined="!openOnly"
        icon="pi pi-inbox"
        @click="
          () => {
            openOnly = !openOnly
            load()
          }
        "
      />
      <Button
        :label="pt('Overdue')"
        :outlined="!overdueOnly"
        icon="pi pi-clock"
        severity="warn"
        @click="
          () => {
            overdueOnly = !overdueOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Service Requests"
        filenameBase="service-requests"
        :rows="rows"
        :columns="[
          { key: 'request_number', label: 'Request' },
          { key: 'type', label: 'Type' },
          { key: 'customer', label: 'Customer' },
          { key: 'unit', label: 'Machine' },
          { key: 'scheduled_at', label: 'Scheduled' },
          { key: 'technician', label: 'Technician' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="id"
      paginator
      :rows="25"
      striped-rows
      responsive-layout="scroll"
      :row-class="(d: any) => (d.is_overdue ? 'row-overdue' : '')"
    >
      <Column field="request_number" :header="pt('Request')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.request_number }}</div>
          <small class="muted">
            {{ pt(typeLabel(data.type)) }}
            <template v-if="data.priority !== 'normal'"> · {{ pt(data.priority) }}</template>
          </small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">
          <div>{{ data.customer || '—' }}</div>
          <small v-if="data.contact_phone" class="muted">{{ data.contact_phone }}</small>
        </template>
      </Column>

      <Column field="unit" :header="pt('Machine')">
        <template #body="{ data }">
          <div class="mono small">{{ data.unit || data.product || '—' }}</div>
          <Tag
            v-if="data.under_warranty"
            :value="pt('Under warranty')"
            severity="info"
            :title="pt('What the desk told the customer when the call was taken')"
          />
        </template>
      </Column>

      <Column field="scheduled_at" :header="pt('When')" sortable>
        <template #body="{ data }">
          <div v-if="data.scheduled_at">{{ when(data.scheduled_at) }}</div>
          <span v-else class="muted">{{ pt('Not scheduled') }}</span>
          <small v-if="data.promised_at" class="muted">
            {{ pt('promised') }} {{ when(data.promised_at) }}
          </small>
        </template>
      </Column>

      <Column field="technician" :header="pt('Technician')">
        <template #body="{ data }">
          <span>{{ data.technician || '—' }}</span>
          <Tag v-if="data.is_onsite" :value="pt('Onsite')" severity="secondary" class="ml" />
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="STATUS_SEVERITY[data.status]" />
          <Tag v-if="data.is_overdue" :value="pt('Late')" severity="danger" class="ml" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 14rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.is_open && data.status === 'logged'"
            icon="pi pi-calendar-plus"
            text
            rounded
            :title="pt('Schedule')"
            @click="openEdit(data)"
          />
          <!-- Convert only where a job card is the honest outcome. A delivery
               has nothing to diagnose and the API refuses it anyway. -->
          <Button
            v-if="canOpenJob && data.is_open && data.needs_job_card"
            icon="pi pi-wrench"
            text
            rounded
            severity="success"
            :title="pt('Open job card')"
            @click="convert(data)"
          />
          <Button
            v-if="canEdit && data.is_open"
            icon="pi pi-check"
            text
            rounded
            :title="pt('Close without a job card')"
            @click="close(data)"
          />
          <Button
            v-if="canEdit && data.is_open"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canEdit && data.is_open"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="progress(data, 'cancelled')"
          />
          <Button
            v-if="canDelete && !data.service_job_id"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing on the desk. Every call logged here is a visit somebody can be held to.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Request') : pt('Log Request')"
      modal
      :style="{ width: '860px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('What is it') }} *</label>
          <Select
            v-model="form.type"
            :options="TYPES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
          <small class="hint">
            {{ pt('Repairs, installations and AMC visits become job cards. Deliveries and demos are closed off the desk.') }}
          </small>
        </div>
        <div class="fld">
          <label>{{ pt('Came in by') }}</label>
          <Select
            v-model="form.source"
            :options="SOURCES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select
            v-model="form.priority"
            :options="PRIORITIES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Machine') }}</label>
          <Select
            v-model="form.unit_id"
            :options="units"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onUnitPicked"
          />
          <small class="hint">{{ pt('Naming the machine picks up its owner and its cover automatically.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Article') }}</label>
          <Select
            v-model="form.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
          <small class="hint">{{ pt('When the caller has not found the serial yet.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.contact_phone" class="w-full" />
          <small v-if="errors.contact_phone" class="err">{{ errors.contact_phone[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Scheduled for') }}</label>
          <DatePicker
            v-model="form.scheduled_at"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            show-icon
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Promised by') }}</label>
          <DatePicker
            v-model="form.promised_at"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            show-icon
            class="w-full"
          />
          <small v-if="errors.promised_at" class="err">{{ errors.promised_at[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Technician') }}</label>
          <Select
            v-model="form.assigned_to"
            :options="technicians"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            :placeholder="technicians.length ? undefined : pt('No employees on file')"
          />
        </div>

        <div class="fld checks span-2">
          <div class="chk">
            <Checkbox v-model="form.is_onsite" input-id="onsite" binary />
            <label for="onsite">{{ pt('Visit at the customer’s address') }}</label>
          </div>
        </div>

        <div v-if="form.is_onsite" class="fld span-2">
          <label>{{ pt('Address') }}</label>
          <Textarea
            v-model="form.address"
            rows="2"
            auto-resize
            class="w-full"
            :placeholder="pt('Where the machine actually is — often not the billing address')"
          />
          <small v-if="errors.address" class="err">{{ errors.address[0] }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('What the customer said') }}</label>
          <Textarea v-model="form.complaint" rows="2" auto-resize class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Estimated cost') }}</label>
          <InputNumber v-model="form.estimated_cost" :min="0" mode="decimal" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }
.t-value.bad { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.checks { flex-direction: row; gap: 1.25rem; align-items: center; flex-wrap: wrap; }
.chk { display: flex; gap: 0.4rem; align-items: center; }
.chk label { font-size: 0.8rem; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.small { font-size: 0.8rem; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.ml { margin-left: 0.35rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>

<style>
/* Not scoped: PrimeVue applies row-class to a <tr> the scoped attribute does
   not reach. Late work is the thing this desk is judged on, so it is tinted
   rather than left to a tag somebody has to notice. */
.p-datatable .row-overdue > td { background: #fef2f2; }
</style>
