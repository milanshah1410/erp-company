<script setup lang="ts">
/**
 * Machines — the plant a factory schedules by.
 *
 * The list leads with state rather than specification: what a supervisor opens
 * this screen to find out is what is running, what is stopped and what is
 * overdue for service. Serial numbers and model codes matter once a year; "is
 * the press down?" matters every hour.
 *
 * Logging a stop from here also moves the machine's status, so a breakdown is
 * visible to whoever loads the next order instead of being buried in a log.
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

const canCreate = computed(() => auth.can('machines.create'))
const canEdit = computed(() => auth.can('machines.edit'))
const canDelete = computed(() => auth.can('machines.delete'))

const rows = ref<any[]>([])
const summary = ref({ total: 0, running: 0, idle: 0, down: 0, service_due: 0 })
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)

const warehouses = ref<any[]>([])
const departments = ref<any[]>([])
const employees = ref<any[]>([])

const STATUSES = [
  { label: 'Running', value: 'running' },
  { label: 'Idle', value: 'idle' },
  { label: 'Breakdown', value: 'breakdown' },
  { label: 'Maintenance', value: 'maintenance' },
  { label: 'Retired', value: 'retired' },
]

/** Reason codes, mirroring MachineDowntime::REASONS on the backend. */
const REASONS = [
  { label: 'Breakdown', value: 'breakdown' },
  { label: 'Planned Maintenance', value: 'maintenance' },
  { label: 'Power Failure', value: 'power_failure' },
  { label: 'No Material', value: 'no_material' },
  { label: 'No Operator', value: 'no_operator' },
  { label: 'Changeover / Setup', value: 'changeover' },
  { label: 'Quality Hold', value: 'quality_hold' },
  { label: 'Other', value: 'other' },
]

function money(v: any) {
  return formatCurrency(v)
}

/** Red for a stopped machine — the state that costs money while it is true. */
function statusSeverity(status: string) {
  if (status === 'running') return 'success'
  if (status === 'idle') return 'info'
  if (status === 'retired') return 'secondary'
  return 'danger'
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/machines', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  const [w, d, e] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 200 } }),
    http.get('/departments', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    http.get('/employees', { params: { per_page: 300, is_active: 1 } }).catch(() => ({ data: { data: [] } })),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  departments.value = (d.data.data ?? d.data).map((x: any) => ({ label: x.name, value: x.id }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: `${x.first_name ?? ''} ${x.last_name ?? ''}`.trim() || x.employee_code,
    value: x.id,
  }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  code: '',
  name: '',
  machine_type: '',
  warehouse_id: null as number | null,
  department_id: null as number | null,
  operator_id: null as number | null,
  manufacturer: '',
  model_number: '',
  serial_number: '',
  installed_on: null as Date | null,
  capacity_per_hour: 0,
  capacity_uom: '',
  hourly_cost: 0,
  power_kw: null as number | null,
  status: 'idle',
  last_service_on: null as Date | null,
  next_service_due: null as Date | null,
  is_active: true,
  notes: '',
})

const form = ref(blank())

function toDate(v: any) {
  return v ? new Date(v) : null
}

function toIso(v: Date | null) {
  if (!v) return null
  // Local date, not toISOString(): a machine installed on the 1st in IST would
  // otherwise be stored as the 31st.
  const p = (n: number) => String(n).padStart(2, '0')
  return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  errors.value = {}
  editingId.value = row.id
  form.value = {
    ...blank(),
    ...row,
    installed_on: toDate(row.installed_on),
    last_service_on: toDate(row.last_service_on),
    next_service_due: toDate(row.next_service_due),
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      installed_on: toIso(form.value.installed_on),
      last_service_on: toIso(form.value.last_service_on),
      next_service_due: toIso(form.value.next_service_due),
    }
    if (editingId.value) {
      await http.put(`/machines/${editingId.value}`, payload)
    } else {
      await http.post('/machines', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Machine saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the machine'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this machine?'),
    text: pt('A machine with production history cannot be deleted — retire it instead.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/machines/${row.id}`)
    await load()
    toast.add({ severity: 'success', summary: pt('Machine deleted'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  }
}

// ---- Downtime -------------------------------------------------------------

const downtimeVisible = ref(false)
const downtimeMachine = ref<any>(null)
const downtimeRows = ref<any[]>([])
const downtimeSaving = ref(false)
const downtimeForm = ref({
  started_at: new Date(),
  ended_at: null as Date | null,
  reason: 'breakdown',
  remarks: '',
})

async function openDowntime(row: any) {
  downtimeMachine.value = row
  downtimeForm.value = { started_at: new Date(), ended_at: null, reason: 'breakdown', remarks: '' }
  downtimeVisible.value = true
  const { data } = await http.get(`/machines/${row.id}/downtimes`)
  downtimeRows.value = data.data
}

function toDateTime(v: Date | null) {
  if (!v) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())} ${p(v.getHours())}:${p(v.getMinutes())}:00`
}

async function saveDowntime() {
  downtimeSaving.value = true
  try {
    await http.post(`/machines/${downtimeMachine.value.id}/downtimes`, {
      started_at: toDateTime(downtimeForm.value.started_at),
      ended_at: toDateTime(downtimeForm.value.ended_at),
      reason: downtimeForm.value.reason,
      remarks: downtimeForm.value.remarks || undefined,
    })
    const { data } = await http.get(`/machines/${downtimeMachine.value.id}/downtimes`)
    downtimeRows.value = data.data
    downtimeForm.value = { started_at: new Date(), ended_at: null, reason: 'breakdown', remarks: '' }
    await load()
    toast.add({ severity: 'success', summary: pt('Downtime logged'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  } finally {
    downtimeSaving.value = false
  }
}

async function endDowntime(row: any) {
  await http.post(`/machines/${downtimeMachine.value.id}/downtimes/${row.id}/end`, {})
  const { data } = await http.get(`/machines/${downtimeMachine.value.id}/downtimes`)
  downtimeRows.value = data.data
  await load()
  toast.add({ severity: 'success', summary: pt('Machine back in service'), life: 2000 })
}

onMounted(() => {
  load()
  loadOptions()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Machines')"
      :subtitle="pt('The plant the floor is scheduled by — state, capacity and the hourly rate a run is charged at')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Machine')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Machines') }}</span>
        <span class="t-value">{{ summary.total }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Running') }}</span>
        <span class="t-value ok">{{ summary.running }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Idle') }}</span>
        <span class="t-value">{{ summary.idle }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Down') }}</span>
        <span class="t-value bad">{{ summary.down }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Service overdue') }}</span>
        <span class="t-value" :class="{ bad: summary.service_due > 0 }">{{ summary.service_due }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search code, name or serial')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Any status')"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Machines"
        filenameBase="machines"
        :rows="rows"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Machine' },
          { key: 'machine_type', label: 'Type' },
          { key: 'status', label: 'Status' },
          { key: 'capacity_per_hour', label: 'Capacity / hr' },
          { key: 'hourly_cost', label: 'Hourly cost' },
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
      <Column field="code" :header="pt('Code')" sortable />

      <Column field="name" :header="pt('Machine')" sortable>
        <template #body="{ data }">
          <div>
            <div>{{ data.name }}</div>
            <small class="muted">{{ data.machine_type || '—' }}</small>
          </div>
        </template>
      </Column>

      <Column field="warehouse" :header="pt('Location')" sortable>
        <template #body="{ data }">
          <span>{{ data.warehouse || '—' }}</span>
          <small v-if="data.department" class="muted"> · {{ data.department }}</small>
        </template>
      </Column>

      <Column field="operator" :header="pt('Operator')">
        <template #body="{ data }">{{ data.operator || '—' }}</template>
      </Column>

      <Column field="capacity_per_hour" :header="pt('Capacity / hr')" sortable>
        <template #body="{ data }">
          <span v-if="data.capacity_per_hour > 0">
            {{ data.capacity_per_hour }} <small class="muted">{{ data.capacity_uom }}</small>
          </span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="hourly_cost" :header="pt('Hourly cost')" sortable>
        <template #body="{ data }">
          <span v-if="data.hourly_cost > 0">{{ money(data.hourly_cost) }}</span>
          <span v-else class="muted" :title="pt('Not rated — machine time cannot be charged to a run')">—</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="statusSeverity(data.status)" />
          <Tag
            v-if="data.service_overdue"
            class="ml"
            :value="pt('Service due')"
            severity="warn"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit"
            icon="pi pi-clock"
            text
            rounded
            :title="pt('Downtime log')"
            @click="openDowntime(data)"
          />
          <Button
            v-if="canEdit"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete"
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
          {{ pt('No machines yet. Without them, downtime and machine cost cannot be tracked.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Machine') : pt('New Machine')"
      modal
      :style="{ width: '860px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Code') }} *</label>
          <InputText v-model="form.code" :placeholder="pt('e.g. PRESS-2')" />
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Machine name') }} *</label>
          <InputText v-model="form.name" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Type') }}</label>
          <InputText v-model="form.machine_type" :placeholder="pt('e.g. Injection Moulding')" />
        </div>

        <div class="fld">
          <label>{{ pt('Warehouse / Unit') }}</label>
          <Select v-model="form.warehouse_id" :options="warehouses" option-label="label" option-value="value" filter show-clear />
        </div>
        <div class="fld">
          <label>{{ pt('Department') }}</label>
          <Select v-model="form.department_id" :options="departments" option-label="label" option-value="value" filter show-clear />
        </div>
        <div class="fld">
          <label>{{ pt('Operator') }}</label>
          <Select v-model="form.operator_id" :options="employees" option-label="label" option-value="value" filter show-clear />
        </div>

        <div class="fld">
          <label>{{ pt('Capacity / hour') }}</label>
          <InputNumber v-model="form.capacity_per_hour" :min="0" :max-fraction-digits="3" />
        </div>
        <div class="fld">
          <label>{{ pt('Capacity unit') }}</label>
          <InputText v-model="form.capacity_uom" :placeholder="pt('pcs / kg / m')" />
        </div>
        <div class="fld">
          <label>{{ pt('Hourly cost') }}</label>
          <InputNumber v-model="form.hourly_cost" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt('Power, consumables and upkeep per running hour — what a run is charged.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Power (kW)') }}</label>
          <InputNumber v-model="form.power_kw" :min="0" :max-fraction-digits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select v-model="form.status" :options="STATUSES" option-label="label" option-value="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Installed on') }}</label>
          <DatePicker v-model="form.installed_on" date-format="dd/mm/yy" show-icon />
        </div>

        <div class="fld">
          <label>{{ pt('Manufacturer') }}</label>
          <InputText v-model="form.manufacturer" />
        </div>
        <div class="fld">
          <label>{{ pt('Model') }}</label>
          <InputText v-model="form.model_number" />
        </div>
        <div class="fld">
          <label>{{ pt('Serial number') }}</label>
          <InputText v-model="form.serial_number" />
        </div>

        <div class="fld">
          <label>{{ pt('Last service') }}</label>
          <DatePicker v-model="form.last_service_on" date-format="dd/mm/yy" show-icon />
        </div>
        <div class="fld">
          <label>{{ pt('Next service due') }}</label>
          <DatePicker v-model="form.next_service_due" date-format="dd/mm/yy" show-icon />
        </div>
        <div class="fld">
          <label>&nbsp;</label>
          <label class="inline-check">
            <Checkbox v-model="form.is_active" binary />
            {{ pt('Active') }}
          </label>
        </div>

        <div class="fld span-3">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Downtime -->
    <Dialog
      v-model:visible="downtimeVisible"
      :header="pt('Downtime') + ' — ' + (downtimeMachine?.name ?? '')"
      modal
      :style="{ width: '900px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Stopped at') }} *</label>
          <DatePicker v-model="downtimeForm.started_at" show-time hour-format="24" date-format="dd/mm/yy" show-icon />
        </div>
        <div class="fld">
          <label>{{ pt('Back in service') }}</label>
          <DatePicker v-model="downtimeForm.ended_at" show-time hour-format="24" date-format="dd/mm/yy" show-icon />
          <small class="hint">{{ pt('Leave empty if the machine is still down.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Reason') }} *</label>
          <Select v-model="downtimeForm.reason" :options="REASONS" option-label="label" option-value="value" />
        </div>
        <div class="fld span-3">
          <label>{{ pt('Remarks') }}</label>
          <InputText v-model="downtimeForm.remarks" />
        </div>
      </div>

      <div class="lines-footer">
        <span class="hint">
          {{ pt('An open stop marks the machine down so nobody schedules onto it.') }}
        </span>
        <Button :label="pt('Log downtime')" icon="pi pi-plus" :loading="downtimeSaving" @click="saveDowntime" />
      </div>

      <h4 class="section-title">{{ pt('Recent stops') }}</h4>
      <DataTable :value="downtimeRows" data-key="id" striped-rows responsive-layout="scroll" :rows="10" paginator>
        <Column field="started_at" :header="pt('Stopped at')" />
        <Column field="ended_at" :header="pt('Resumed')">
          <template #body="{ data }">
            <span v-if="data.ended_at">{{ data.ended_at }}</span>
            <Tag v-else :value="pt('Still down')" severity="danger" />
          </template>
        </Column>
        <Column field="hours" :header="pt('Hours')">
          <template #body="{ data }">{{ data.is_open ? '—' : data.hours }}</template>
        </Column>
        <Column field="reason_label" :header="pt('Reason')" />
        <Column field="mo_number" :header="pt('Order')">
          <template #body="{ data }">{{ data.mo_number || '—' }}</template>
        </Column>
        <Column field="remarks" :header="pt('Remarks')" />
        <Column :header="''" style="width: 8rem">
          <template #body="{ data }">
            <Button
              v-if="data.is_open && canEdit"
              :label="pt('End')"
              size="small"
              text
              @click="endDowntime(data)"
            />
          </template>
        </Column>
        <template #empty>
          <div class="empty-state">{{ pt('No stops recorded.') }}</div>
        </template>
      </DataTable>

      <template #footer>
        <Button :label="pt('Close')" text @click="downtimeVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.ok { color: #15803d; }
.t-value.bad { color: #b91c1c; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.ml { margin-left: 0.35rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.section-title { margin: 1.2rem 0 0.5rem; font-size: 0.9rem; }
.lines-footer { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 0.8rem; }
</style>
