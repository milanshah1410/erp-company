<script setup lang="ts">
/**
 * The optician's diary.
 *
 * A day screen, not a register: it opens on today because that is what the front
 * desk needs at nine in the morning, and the two tiles that matter are who is
 * sitting in the shop right now and which slot has come and gone with nobody
 * marked in. History is one date-picker click away and deliberately not the
 * default — a diary that opens on last month's no-shows is a diary nobody uses.
 *
 * The one rule the server enforces is the clash: an optometrist cannot be in two
 * chairs at once. It comes back as a validation error naming the patient already
 * in that slot, which is more useful than a greyed-out time the desk cannot
 * argue with.
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

const canCreate = computed(() => auth.can('optical-appointments.create'))
const canEdit = computed(() => auth.can('optical-appointments.edit'))
const canDelete = computed(() => auth.can('optical-appointments.delete'))

const PURPOSES = [
  { label: 'Eye Test', value: 'eye_test' },
  { label: 'Fitting', value: 'fitting' },
  { label: 'Collection', value: 'collection' },
  { label: 'Adjustment', value: 'adjustment' },
  { label: 'Repair', value: 'repair' },
  { label: 'Follow-up', value: 'follow_up' },
]
const STATUSES = [
  { label: 'Booked', value: 'booked' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'Arrived', value: 'arrived' },
  { label: 'Completed', value: 'completed' },
  { label: 'No show', value: 'no_show' },
  { label: 'Cancelled', value: 'cancelled' },
]

const rows = ref<any[]>([])
const summary = ref({ total: 0, waiting: 0, upcoming: 0, overdue: 0, completed: 0, no_show: 0 })
const loading = ref(false)
const search = ref('')
const day = ref<Date>(new Date())
const statusFilter = ref<string | null>(null)
const openOnly = ref(false)

const customers = ref<any[]>([])
const staff = ref<any[]>([])
const prescriptions = ref<any[]>([])

function isoDate(d: Date | null) {
  if (!d) return undefined
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function isoDateTime(v: Date | null) {
  if (!v) return undefined
  const p = (n: number) => String(n).padStart(2, '0')
  return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())} ${p(v.getHours())}:${p(v.getMinutes())}:00`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/optical/appointments', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        open_only: openOnly.value ? 1 : undefined,
        day: openOnly.value ? undefined : isoDate(day.value),
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
  const [c, e] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    // The optometrist is an employee. A shop without the HR module simply gets
    // no picker and books slots against nobody, which the clash rule allows for.
    auth.can('employees.view')
      ? http.get('/employees', { params: { per_page: 200 } })
      : Promise.resolve({ data: { data: [] } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: `${x.name}${x.phone ? ' — ' + x.phone : ''}`,
    value: x.id,
    name: x.name,
    phone: x.phone,
  }))
  staff.value = (e.data.data ?? e.data ?? []).map((x: any) => ({ label: x.name, value: x.id }))
}

/** Only loaded when a slot is being closed against a test — it is a big list otherwise. */
async function loadPrescriptions() {
  if (prescriptions.value.length || !auth.can('eye-prescriptions.view')) return
  const { data } = await http.get('/eye-prescriptions', { params: { per_page: 100 } })
  prescriptions.value = (data.data ?? []).map((x: any) => ({
    label: `${x.prescription_number} — ${x.patient_name}`,
    value: x.id,
  }))
}

// ---- Booking ---------------------------------------------------------------

const formVisible = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  patient_name: '',
  patient_phone: '',
  patient_age: null as number | null,
  purpose: 'eye_test',
  scheduled_at: null as Date | null,
  duration_minutes: 20,
  optometrist_id: null as number | null,
  eye_prescription_id: null as number | null,
  optical_order_id: null as number | null,
  notes: '',
})
const form = ref(blank())

function openCreate() {
  editingId.value = null
  form.value = blank()
  // A slot with no time is the one that gets forgotten, so it opens on the next
  // half hour rather than empty.
  const at = new Date()
  at.setMinutes(at.getMinutes() > 30 ? 60 : 30, 0, 0)
  form.value.scheduled_at = at
  errors.value = {}
  formVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  form.value = {
    ...blank(),
    customer_id: row.customer_id,
    patient_name: row.patient_name,
    patient_phone: row.patient_phone ?? '',
    patient_age: row.patient_age,
    purpose: row.purpose,
    scheduled_at: row.scheduled_at ? new Date(row.scheduled_at) : null,
    duration_minutes: row.duration_minutes,
    optometrist_id: row.optometrist_id,
    eye_prescription_id: row.eye_prescription_id,
    optical_order_id: row.optical_order_id,
    notes: row.notes ?? '',
  }
  formVisible.value = true
}

/** Picking an account fills the patient in — that is why it was recorded. */
function onCustomerPicked() {
  const picked = customers.value.find((c) => c.value === form.value.customer_id)
  if (!picked) return
  if (!form.value.patient_name) form.value.patient_name = picked.name
  if (!form.value.patient_phone && picked.phone) form.value.patient_phone = picked.phone
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, scheduled_at: isoDateTime(form.value.scheduled_at) }
    editingId.value
      ? await http.put(`/optical/appointments/${editingId.value}`, payload)
      : await http.post('/optical/appointments', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Appointment updated' : 'Appointment booked'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the appointment'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

// ---- Working the diary -----------------------------------------------------

async function mark(row: any, status: string) {
  try {
    const { data } = await http.post(`/optical/appointments/${row.id}/status`, { status })
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('That did not work'), life: 4000 })
  }
}

/** Closing a test slot asks what it produced — that link is the point of the act. */
const completeVisible = ref(false)
const completing = ref<any>(null)
const completePrescription = ref<number | null>(null)

async function openComplete(row: any) {
  completing.value = row
  completePrescription.value = row.eye_prescription_id ?? null
  await loadPrescriptions()
  completeVisible.value = true
}

async function confirmComplete() {
  try {
    const { data } = await http.post(`/optical/appointments/${completing.value.id}/status`, {
      status: 'completed',
      eye_prescription_id: completePrescription.value || undefined,
    })
    completeVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('That did not work'), life: 4000 })
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this appointment?'),
    text: pt('Only a slot nobody was seen in can be deleted. Cancel the others.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Keep'),
    confirmButtonColor: '#b91c1c',
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/optical/appointments/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

function timeLabel(row: any) {
  if (!row.scheduled_at) return '—'
  return new Date(row.scheduled_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

function statusTag(row: any) {
  if (row.status === 'completed') return { label: pt('Completed'), severity: 'success' }
  if (row.status === 'no_show') return { label: pt('No show'), severity: 'danger' }
  if (row.status === 'cancelled') return { label: pt('Cancelled'), severity: 'contrast' }
  if (row.status === 'arrived') return { label: pt('Waiting'), severity: 'warn' }
  if (row.is_overdue) return { label: pt('Overdue'), severity: 'danger' }
  return row.status === 'confirmed'
    ? { label: pt('Confirmed'), severity: 'info' }
    : { label: pt('Booked'), severity: 'secondary' }
}

function purposeLabel(value: string) {
  return pt(PURPOSES.find((p) => p.value === value)?.label ?? value)
}

onMounted(async () => {
  await loadPickers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Appointments')"
      :subtitle="pt('Who is coming in, who is waiting, and which chair is free')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Book a Slot')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('In the shop now') }}</span>
        <span class="t-value" :class="{ warn: summary.waiting > 0 }">{{ summary.waiting }}</span>
        <small class="muted">{{ pt('marked arrived, not yet seen') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Still to come') }}</span>
        <span class="t-value">{{ summary.upcoming }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value" :class="{ danger: summary.overdue > 0 }">{{ summary.overdue }}</span>
        <small class="muted">{{ pt('time passed, nobody marked in') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Seen today') }}</span>
        <span class="t-value">{{ summary.completed }}</span>
        <small class="muted">{{ summary.no_show }} {{ pt('no shows') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker
        v-model="day"
        dateFormat="yy-mm-dd"
        showIcon
        :disabled="openOnly"
        @update:modelValue="load"
      />
      <InputText v-model="search" :placeholder="pt('Slot no, patient or phone')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('Any status')"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="openOnly" binary @change="load" />
        <span>{{ pt('Everything still open') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons
        title="Appointments"
        filenameBase="optical-appointments"
        :rows="rows"
        :columns="[
          { key: 'appointment_number', label: 'Slot' },
          { key: 'scheduled_at', label: 'When' },
          { key: 'patient_name', label: 'Patient' },
          { key: 'purpose', label: 'For' },
          { key: 'optometrist', label: 'Optometrist' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" stripedRows>
      <Column :header="pt('Time')" style="width: 7rem">
        <template #body="{ data }">
          <strong>{{ timeLabel(data) }}</strong>
          <br /><small class="muted">{{ data.duration_minutes }} {{ pt('min') }}</small>
        </template>
      </Column>
      <Column :header="pt('Patient')">
        <template #body="{ data }">
          {{ data.patient_name }}
          <br />
          <small class="muted">{{ data.patient_phone || data.customer?.phone || '—' }}</small>
        </template>
      </Column>
      <Column :header="pt('For')">
        <template #body="{ data }">
          <Tag :value="purposeLabel(data.purpose)" :severity="data.is_clinical ? 'info' : 'secondary'" />
        </template>
      </Column>
      <Column :header="pt('Optometrist')">
        <template #body="{ data }">{{ data.optometrist || '—' }}</template>
      </Column>
      <Column :header="pt('Outcome')">
        <template #body="{ data }">
          <span v-if="data.prescription_number">
            <i class="pi pi-eye" /> {{ data.prescription_number }}
          </span>
          <span v-else-if="data.order_number">
            <i class="pi pi-shopping-bag" /> {{ data.order_number }}
          </span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Status')" style="width: 8rem">
        <template #body="{ data }">
          <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 13rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && ['booked', 'confirmed'].includes(data.status)"
            icon="pi pi-sign-in"
            text
            rounded
            :title="pt('Arrived')"
            @click="mark(data, 'arrived')"
          />
          <Button
            v-if="canEdit && ['booked', 'confirmed', 'arrived'].includes(data.status)"
            icon="pi pi-check"
            text
            rounded
            severity="success"
            :title="pt('Seen')"
            @click="openComplete(data)"
          />
          <Button
            v-if="canEdit && ['booked', 'confirmed'].includes(data.status)"
            icon="pi pi-user-minus"
            text
            rounded
            severity="warn"
            :title="pt('No show')"
            @click="mark(data, 'no_show')"
          />
          <Button
            v-if="canEdit && !['completed', 'no_show', 'cancelled'].includes(data.status)"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="mark(data, 'cancelled')"
          />
          <Button
            v-if="canEdit && !['completed', 'no_show', 'cancelled'].includes(data.status)"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && !['arrived', 'completed'].includes(data.status)"
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
        <div class="empty-state">{{ pt('Nothing booked for this day.') }}</div>
      </template>
    </DataTable>

    <!-- Book / reschedule -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Reschedule') : pt('Book a Slot')"
      :style="{ width: '46rem' }"
    >
      <!-- The one rule the server defends, said out loud before it refuses. -->
      <Message v-if="errors.scheduled_at" severity="error" :closable="false" class="mb">
        <div v-for="(msg, i) in errors.scheduled_at" :key="i">{{ msg }}</div>
      </Message>

      <div class="grid">
        <div class="fld">
          <label>{{ pt('When') }} *</label>
          <DatePicker
            v-model="form.scheduled_at"
            showTime
            hourFormat="24"
            dateFormat="yy-mm-dd"
            showIcon
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('How long (minutes)') }}</label>
          <InputNumber v-model="form.duration_minutes" :min="5" :max="240" class="w-full" />
          <small class="hint">{{ pt('A refraction is about 20 minutes; a collection is five.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('What for') }} *</label>
          <Select v-model="form.purpose" :options="PURPOSES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Optometrist') }}</label>
          <Select
            v-model="form.optometrist_id"
            :options="staff"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Whoever is free')"
            class="w-full"
          />
          <small class="hint">{{ pt('Naming one is what lets the book refuse a double booking.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Customer account') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('New or walk-in')"
            class="w-full"
            @change="onCustomerPicked"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Patient name') }} *</label>
          <InputText v-model="form.patient_name" class="w-full" />
          <small v-if="errors.patient_name" class="err">{{ errors.patient_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.patient_phone" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Age') }}</label>
          <InputNumber v-model="form.patient_age" :min="0" :max="130" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="formVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Closing a slot against what it produced -->
    <Dialog v-model:visible="completeVisible" modal :header="pt('Close this slot')" :style="{ width: '32rem' }">
      <p class="small">
        {{ pt('If an eye test was recorded, name it — that is what ties the chair to the powers it produced.') }}
      </p>
      <Select
        v-model="completePrescription"
        :options="prescriptions"
        optionLabel="label"
        optionValue="value"
        filter
        showClear
        :placeholder="pt('No prescription (nothing recorded)')"
        class="w-full"
      />
      <template #footer>
        <Button :label="pt('Cancel')" text @click="completeVisible = false" />
        <Button :label="pt('Mark Seen')" icon="pi pi-check" @click="confirmComplete" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }
.t-value.warn { color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.muted { color: #6b7280; }
.small { font-size: 0.85rem; color: #374151; margin-bottom: 0.6rem; }
.mb { margin-bottom: 0.9rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
