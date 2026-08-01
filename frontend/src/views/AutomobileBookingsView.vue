<script setup lang="ts">
/**
 * The appointment book.
 *
 * A booking is not a job card, and this screen is built around that difference:
 * most slots never become work, so the actions are "confirm", "arrived",
 * "no-show" and "cancel" — and only one of them, "Open job card", crosses into
 * the workshop. Converting is where the shop takes custody of somebody else's
 * vehicle, so it asks for the reading at the gate and tells the advisor
 * immediately whether any warranty was picked up on the way through.
 *
 * The day view is the default because that is how an appointment book is
 * actually read: what is coming in today, in time order.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
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
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const router = useRouter()

const canCreate = computed(() => auth.can('automobile-bookings.create'))
const canEdit = computed(() => auth.can('automobile-bookings.edit'))
const canDelete = computed(() => auth.can('automobile-bookings.delete'))
const canOpenJobCard = computed(() => auth.can('service-jobs.create'))

const SERVICE_TYPES = [
  { label: 'General Service', value: 'general' },
  { label: 'Free Service', value: 'free_service' },
  { label: 'Running Repair', value: 'running_repair' },
  { label: 'Accident / Body', value: 'accident' },
  { label: 'Warranty', value: 'warranty' },
  { label: 'Inspection', value: 'inspection' },
  { label: 'Body & Paint', value: 'body_paint' },
]

const SOURCES = [
  { label: 'Phone', value: 'phone' },
  { label: 'Walk-in', value: 'walk_in' },
  { label: 'Online', value: 'online' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Campaign', value: 'campaign' },
]

const STATUS_SEVERITY: Record<string, string> = {
  booked: 'info',
  confirmed: 'primary',
  arrived: 'warn',
  converted: 'success',
  no_show: 'danger',
  cancelled: 'secondary',
}

const rows = ref<any[]>([])
const summary = ref({ today: 0, open: 0, overdue: 0, by_status: {} as Record<string, number> })
const loading = ref(false)
const vehicles = ref<any[]>([])
const employees = ref<any[]>([])
const warehouses = ref<any[]>([])
const search = ref('')
const statusFilter = ref<string | null>(null)
const dateFilter = ref<Date | null>(null)

function money(v: any) {
  return formatCurrency(v)
}

function dateTime(v: string | null) {
  return v ? new Date(v).toLocaleString() : '—'
}

function statusLabel(s: string) {
  return pt(s === 'no_show' ? 'No show' : s.charAt(0).toUpperCase() + s.slice(1))
}

function asIso(d: Date | null) {
  return d ? d.toISOString() : null
}

function asDay(d: Date | null) {
  if (!d) return undefined
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/automobile/bookings', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        date: asDay(dateFilter.value),
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
  const [v, e, w] = await Promise.all([
    http.get('/automobile/vehicles', { params: { per_page: 500, is_active: 1 } }),
    http.get('/employees', { params: { per_page: 300 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  vehicles.value = (v.data.data ?? v.data).map((x: any) => ({
    label: x.label,
    value: x.id,
    customer: x.customer,
    odometer: x.odometer,
  }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: `${x.first_name ?? ''} ${x.last_name ?? ''}`.trim() || x.employee_code,
    value: x.id,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  vehicle_id: null as number | null,
  service_type: 'general',
  source: 'phone',
  scheduled_at: null as Date | null,
  estimated_ready_at: null as Date | null,
  assigned_to: null as number | null,
  warehouse_id: null as number | null,
  odometer: null as number | null,
  complaint: '',
  estimated_cost: 0,
  pickup_required: false,
  pickup_address: '',
  notes: '',
})

const form = ref(blank())

/** The owner the register knows, shown so the counter does not have to ask. */
const pickedVehicle = computed(() => vehicles.value.find((v) => v.value === form.value.vehicle_id))

function openCreate() {
  form.value = blank()
  form.value.scheduled_at = new Date()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/automobile/bookings/${row.id}`)
  const b = data.data
  editingId.value = b.id
  form.value = {
    vehicle_id: b.vehicle_id,
    service_type: b.service_type,
    source: b.source,
    scheduled_at: b.scheduled_at ? new Date(b.scheduled_at) : null,
    estimated_ready_at: b.estimated_ready_at ? new Date(b.estimated_ready_at) : null,
    assigned_to: b.assigned_to,
    warehouse_id: b.warehouse_id,
    odometer: b.odometer,
    complaint: b.complaint ?? '',
    estimated_cost: b.estimated_cost,
    pickup_required: b.pickup_required,
    pickup_address: b.pickup_address ?? '',
    notes: b.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      scheduled_at: asIso(form.value.scheduled_at),
      estimated_ready_at: asIso(form.value.estimated_ready_at),
    }
    if (editingId.value) {
      await http.put(`/automobile/bookings/${editingId.value}`, payload)
    } else {
      await http.post('/automobile/bookings', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Booking saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the booking'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function progress(row: any, status: 'confirmed' | 'arrived') {
  try {
    await http.post(`/automobile/bookings/${row.id}/progress`, { status })
    await load()
    toast.add({
      severity: 'success',
      summary: status === 'confirmed' ? pt('Booking confirmed') : pt('Vehicle marked as arrived'),
      life: 2000,
    })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function close(row: any, status: 'cancelled' | 'no_show') {
  const res = await Swal.fire({
    title: status === 'no_show' ? pt('Mark as no-show?') : pt('Cancel this booking?'),
    input: 'text',
    inputPlaceholder: pt('Reason (optional)'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Confirm'),
    cancelButtonText: pt('Back'),
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/automobile/bookings/${row.id}/close`, { status, reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this booking?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/automobile/bookings/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// ---- Opening the job card -------------------------------------------------

const convertVisible = ref(false)
const converting = ref(false)
const convertRow = ref<any>(null)
const convertForm = ref({ odometer: null as number | null, assigned_to: null as number | null })

function openConvert(row: any) {
  convertRow.value = row
  convertForm.value = { odometer: row.odometer ?? null, assigned_to: row.assigned_to ?? null }
  convertVisible.value = true
}

async function convert() {
  converting.value = true
  try {
    const { data } = await http.post(
      `/automobile/bookings/${convertRow.value.id}/convert`,
      convertForm.value,
    )
    convertVisible.value = false
    await load()

    // Warranty is called out rather than left to be discovered on the invoice:
    // it changes who pays, and the advisor is standing in front of the customer.
    toast.add({
      severity: 'success',
      summary: pt('Job card opened') + ` ${data.job_card.job_number}`,
      detail: data.job_card.is_warranty
        ? pt('Live warranty found — this job will not be billed to the customer.')
        : undefined,
      life: 5000,
    })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.booking?.[0] ?? e.response?.data?.message,
      life: 5000,
    })
  } finally {
    converting.value = false
  }
}

/**
 * The job book is the Service screen — a job card is a service job with a
 * vehicle on it, and there is no second one. No deep link because that screen
 * has no per-job route; it opens on the workshop's open jobs, which is where
 * this booking's card now is.
 */
function goToJobCard() {
  router.push('/service-jobs')
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Service Bookings')"
      :subtitle="pt('What is coming in, and what is on the ramp')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Booking')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Today') }}</span>
        <span class="t-value">{{ summary.today }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Open') }}</span>
        <span class="t-value">{{ summary.open }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value warn">{{ summary.overdue }}</span>
        <small class="muted">{{ pt('slot passed, vehicle not booked in') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('No-shows') }}</span>
        <span class="t-value">{{ summary.by_status?.no_show ?? 0 }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Booking, registration or owner')"
        @keyup.enter="load"
      />
      <DatePicker
        v-model="dateFilter"
        date-format="dd/mm/yy"
        show-button-bar
        :placeholder="pt('Any day')"
        @date-select="load"
        @clear-click="load"
      />
      <Select
        v-model="statusFilter"
        :options="[
          { label: pt('All statuses'), value: null },
          { label: pt('Booked'), value: 'booked' },
          { label: pt('Confirmed'), value: 'confirmed' },
          { label: pt('Arrived'), value: 'arrived' },
          { label: pt('Converted'), value: 'converted' },
          { label: pt('No show'), value: 'no_show' },
          { label: pt('Cancelled'), value: 'cancelled' },
        ]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Service Bookings"
        filenameBase="service-bookings"
        :rows="rows"
        :columns="[
          { key: 'booking_number', label: 'Booking' },
          { key: 'scheduled_at', label: 'Scheduled' },
          { key: 'vehicle', label: 'Vehicle' },
          { key: 'customer', label: 'Owner' },
          { key: 'service_type', label: 'Type' },
          { key: 'status', label: 'Status' },
          { key: 'estimated_cost', label: 'Estimate' },
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
    >
      <Column field="scheduled_at" :header="pt('Scheduled')" sortable>
        <template #body="{ data }">
          <div :class="{ overdue: data.is_overdue }">{{ dateTime(data.scheduled_at) }}</div>
          <small class="muted">{{ data.booking_number }}</small>
        </template>
      </Column>

      <Column field="vehicle" :header="pt('Vehicle')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.registration_no || pt('Unregistered') }}</div>
          <small class="muted">{{ data.vehicle }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Owner')">
        <template #body="{ data }">
          <div>{{ data.customer || '—' }}</div>
          <small class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>

      <Column field="service_type" :header="pt('Type')" sortable>
        <template #body="{ data }">
          {{ SERVICE_TYPES.find((t) => t.value === data.service_type)?.label ?? data.service_type }}
        </template>
      </Column>

      <Column field="advisor" :header="pt('Advisor')">
        <template #body="{ data }">{{ data.advisor || '—' }}</template>
      </Column>

      <Column field="estimated_cost" :header="pt('Estimate')" sortable>
        <template #body="{ data }">{{ money(data.estimated_cost) }}</template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusLabel(data.status)" :severity="STATUS_SEVERITY[data.status]" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.status === 'booked'"
            icon="pi pi-check"
            text
            rounded
            :title="pt('Confirm')"
            @click="progress(data, 'confirmed')"
          />
          <Button
            v-if="canEdit && ['booked', 'confirmed'].includes(data.status)"
            icon="pi pi-sign-in"
            text
            rounded
            :title="pt('Vehicle has arrived')"
            @click="progress(data, 'arrived')"
          />
          <Button
            v-if="canOpenJobCard && !['converted', 'cancelled', 'no_show'].includes(data.status)"
            icon="pi pi-wrench"
            text
            rounded
            severity="success"
            :title="pt('Open job card')"
            @click="openConvert(data)"
          />
          <Button
            v-if="data.service_job_id"
            icon="pi pi-external-link"
            text
            rounded
            :title="pt('Go to job card')"
            @click="goToJobCard()"
          />
          <Button
            v-if="canEdit && !['converted', 'cancelled', 'no_show'].includes(data.status)"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canEdit && !['converted', 'cancelled', 'no_show'].includes(data.status)"
            icon="pi pi-user-minus"
            text
            rounded
            severity="warn"
            :title="pt('No show')"
            @click="close(data, 'no_show')"
          />
          <Button
            v-if="canEdit && !['converted', 'cancelled', 'no_show'].includes(data.status)"
            icon="pi pi-ban"
            text
            rounded
            severity="danger"
            :title="pt('Cancel')"
            @click="close(data, 'cancelled')"
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
        <div class="empty-state">{{ pt('Nothing booked for this view.') }}</div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Booking') : pt('New Booking')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Vehicle') }} *</label>
          <Select
            v-model="form.vehicle_id"
            :options="vehicles"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('Registration or chassis')"
          />
          <small v-if="pickedVehicle?.customer" class="hint">
            {{ pt('Owner') }}: {{ pickedVehicle.customer }} · {{ pickedVehicle.odometer }} km
          </small>
          <small v-if="errors.vehicle_id" class="err">{{ errors.vehicle_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Service type') }}</label>
          <Select
            v-model="form.service_type"
            :options="SERVICE_TYPES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Booked via') }}</label>
          <Select
            v-model="form.source"
            :options="SOURCES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Scheduled for') }} *</label>
          <DatePicker
            v-model="form.scheduled_at"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            class="w-full"
          />
          <small v-if="errors.scheduled_at" class="err">{{ errors.scheduled_at[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Promised back by') }}</label>
          <DatePicker
            v-model="form.estimated_ready_at"
            show-time
            hour-format="24"
            date-format="dd/mm/yy"
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Service advisor') }}</label>
          <Select
            v-model="form.assigned_to"
            :options="employees"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Branch') }}</label>
          <Select
            v-model="form.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Odometer (declared)') }}</label>
          <InputNumber v-model="form.odometer" :min="0" suffix=" km" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Estimate') }}</label>
          <InputNumber v-model="form.estimated_cost" :min="0" :max-fraction-digits="2" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Complaint') }}</label>
          <Textarea
            v-model="form.complaint"
            rows="2"
            auto-resize
            class="w-full"
            :placeholder="pt('What the customer says is wrong')"
          />
        </div>

        <div class="fld">
          <label>&nbsp;</label>
          <label class="inline-check">
            <Checkbox v-model="form.pickup_required" binary />
            {{ pt('Pick up the vehicle') }}
          </label>
        </div>
        <div v-if="form.pickup_required" class="fld span-2">
          <label>{{ pt('Pickup address') }}</label>
          <Textarea v-model="form.pickup_address" rows="2" auto-resize class="w-full" />
          <small v-if="errors.pickup_address" class="err">{{ errors.pickup_address[0] }}</small>
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

    <!-- Open job card -->
    <Dialog
      v-model:visible="convertVisible"
      :header="pt('Open Job Card')"
      modal
      :style="{ width: '480px' }"
    >
      <p class="lead" v-if="convertRow">
        {{ pt('Taking custody of') }} <strong>{{ convertRow.vehicle }}</strong>.
      </p>
      <p class="hint">
        {{
          pt(
            'Any live warranty on this vehicle is picked up automatically — the job card will say so, and the customer will not be billed.',
          )
        }}
      </p>

      <div class="grid">
        <div class="fld">
          <label>{{ pt('Odometer at the gate') }}</label>
          <InputNumber v-model="convertForm.odometer" :min="0" suffix=" km" class="w-full" />
          <small class="hint">{{ pt('Used to check the kilometre limit on any warranty.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Technician') }}</label>
          <Select
            v-model="convertForm.assigned_to"
            :options="employees"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="convertVisible = false" />
        <Button :label="pt('Open job card')" :loading="converting" @click="convert" />
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
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.overdue { color: #b91c1c; font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.lead { font-size: 0.9rem; margin: 0 0 0.4rem; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
