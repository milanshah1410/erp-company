<script setup lang="ts">
/**
 * The support desk's call book.
 *
 * Built around the two questions a desk actually opens in the morning: what is
 * still open, and what are we late on. Both are answered by the list itself —
 * open calls sort to the top by oldest promise, so what the shop is late on
 * rises there without anyone having to sort or filter for it.
 *
 * Most calls end here. `Open job card` is for the ones that do not: it hands the
 * machine to the bench, and everything after that happens on the Service screen.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
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

const canCreate = computed(() => auth.can('it-service-calls.create'))
const canEdit = computed(() => auth.can('it-service-calls.edit'))
const canDelete = computed(() => auth.can('it-service-calls.delete'))
const canOpenJob = computed(() => auth.can('service-jobs.create'))

const TYPES = [
  { label: 'On site', value: 'onsite' },
  { label: 'Remote', value: 'remote' },
  { label: 'Carry in', value: 'carry_in' },
  { label: 'Installation', value: 'installation' },
  { label: 'Preventive', value: 'preventive' },
]

const PRIORITIES = [
  { label: 'Critical — 4 h', value: 'critical' },
  { label: 'High — 8 h', value: 'high' },
  { label: 'Normal — 24 h', value: 'normal' },
  { label: 'Low — 48 h', value: 'low' },
]

const SOURCES = [
  { label: 'Phone', value: 'phone' },
  { label: 'Email', value: 'email' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Walk in', value: 'walk_in' },
  { label: 'Portal', value: 'portal' },
]

const STATUS_SEVERITY: Record<string, string> = {
  logged: 'secondary',
  scheduled: 'info',
  assigned: 'info',
  attended: 'warn',
  resolved: 'success',
  converted: 'success',
  cancelled: 'danger',
}

const rows = ref<any[]>([])
const summary = ref({ open: 0, unassigned: 0, today: 0, breached: 0, free_visits: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const assets = ref<any[]>([])
const engineers = ref<any[]>([])
const search = ref('')
const statusFilter = ref<string | null>(null)
const openOnly = ref(true)
const breachedOnly = ref(false)

/** "2 h 10 m late" / "3 h 40 m left" — the only number this desk is judged on. */
function sla(row: any) {
  if (row.attended_at) return pt('Attended')
  if (row.sla_minutes_remaining === null || row.sla_minutes_remaining === undefined) return '—'
  const mins = Math.abs(row.sla_minutes_remaining)
  const h = Math.floor(mins / 60)
  const m = mins % 60
  const span = h ? `${h} h ${m} m` : `${m} m`
  return row.sla_minutes_remaining < 0 ? `${span} ${pt('late')}` : `${span} ${pt('left')}`
}

function when(iso: string | null) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/computer-it/service-calls', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        open_only: openOnly.value ? 1 : undefined,
        breached: breachedOnly.value ? 1 : undefined,
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
  const [c, a, e] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/computer-it/assets', { params: { per_page: 500, is_active: 1 } }),
    http.get('/employees', { params: { per_page: 300, is_active: 1 } }).catch(() => ({ data: { data: [] } })),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  assets.value = (a.data.data ?? a.data).map((x: any) => ({
    label: `${x.asset_tag} — ${x.label}`,
    value: x.id,
    customer_id: x.customer_id,
    site: x.site,
  }))
  engineers.value = (e.data.data ?? e.data ?? []).map((x: any) => ({
    label: x.name ?? `${x.first_name ?? ''} ${x.last_name ?? ''}`.trim(),
    value: x.id,
  }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  asset_id: null as number | null,
  type: 'onsite',
  source: 'phone',
  priority: 'normal',
  scheduled_at: null as Date | null,
  assigned_to: null as number | null,
  site_address: '',
  contact_person: '',
  contact_phone: '',
  complaint: '',
  notes: '',
})

const form = ref(blank())

/**
 * Picking the machine fills in who owns it and where it is. Re-keying a site
 * address per call is how half of them end up blank, and an engineer sent to a
 * company name rather than a floor does not find the machine.
 */
function onAssetPicked() {
  const a = assets.value.find((x) => x.value === form.value.asset_id)
  if (!a) return
  if (!form.value.customer_id && a.customer_id) form.value.customer_id = a.customer_id
  if (!form.value.site_address && a.site) form.value.site_address = a.site
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/computer-it/service-calls/${row.id}`)
  const c = data.data
  editingId.value = c.id
  form.value = {
    customer_id: c.customer_id,
    asset_id: c.asset_id,
    type: c.type,
    source: c.source,
    priority: c.priority,
    scheduled_at: c.scheduled_at ? new Date(c.scheduled_at) : null,
    assigned_to: c.assigned_to,
    site_address: c.site_address ?? '',
    contact_person: c.contact_person ?? '',
    contact_phone: c.contact_phone ?? '',
    complaint: c.complaint ?? '',
    notes: c.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      scheduled_at: form.value.scheduled_at ? form.value.scheduled_at.toISOString() : null,
    }
    if (editingId.value) {
      await http.put(`/computer-it/service-calls/${editingId.value}`, payload)
    } else {
      await http.post('/computer-it/service-calls', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Call logged'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the call'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function progress(row: any, status: string) {
  let resolution: string | undefined
  if (status === 'resolved') {
    const res = await Swal.fire({
      title: pt('Close this call?'),
      input: 'textarea',
      inputPlaceholder: pt('What was done'),
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: pt('Close call'),
      cancelButtonText: pt('Cancel'),
    })
    if (!res.isConfirmed) return
    resolution = res.value || undefined
  }

  try {
    await http.post(`/computer-it/service-calls/${row.id}/progress`, { status, resolution })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

/** Hand the machine to the bench. Cover is re-checked on the day, by the server. */
async function convert(row: any) {
  const res = await Swal.fire({
    title: pt('Open a job card?'),
    text: pt(
      'The machine is coming in. Cover is checked again now, so the job opens as free work only if something still covers it today.',
    ),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Open job card'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/computer-it/service-calls/${row.id}/convert`)
    await load()
    toast.add({
      severity: 'success',
      summary: pt('Job card') + ` ${data.service_job.job_number}`,
      detail: data.service_job.is_warranty
        ? pt('Opened as warranty work — the customer is not billed.')
        : data.service_job.service_contract_id
          ? pt('Opened under the AMC — one visit drawn down.')
          : pt('Opened as chargeable work.'),
      life: 5000,
    })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this call?'),
    text: pt('Only calls with no job card behind them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/computer-it/service-calls/${row.id}`)
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
      :title="pt('Service Calls')"
      :subtitle="pt('What is open, and what we are late on')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Log Call')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open') }}</span>
        <span class="t-value">{{ summary.open }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Unassigned') }}</span>
        <span class="t-value">{{ summary.unassigned }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Scheduled today') }}</span>
        <span class="t-value">{{ summary.today }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Past promise') }}</span>
        <span class="t-value danger">{{ summary.breached }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Free visits') }}</span>
        <span class="t-value">{{ summary.free_visits }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Call number, machine, customer or fault')"
        @keyup.enter="load"
      />
      <Select
        v-model="statusFilter"
        :options="[
          { label: pt('Any status'), value: null },
          { label: pt('Logged'), value: 'logged' },
          { label: pt('Scheduled'), value: 'scheduled' },
          { label: pt('Assigned'), value: 'assigned' },
          { label: pt('Attended'), value: 'attended' },
          { label: pt('Resolved'), value: 'resolved' },
          { label: pt('Converted'), value: 'converted' },
        ]"
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
        :label="pt('Past promise')"
        :outlined="!breachedOnly"
        severity="danger"
        icon="pi pi-clock"
        @click="
          () => {
            breachedOnly = !breachedOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Service Calls"
        filenameBase="service-calls"
        :rows="rows"
        :columns="[
          { key: 'call_number', label: 'Call' },
          { key: 'customer', label: 'Customer' },
          { key: 'asset', label: 'Machine' },
          { key: 'type', label: 'Type' },
          { key: 'priority', label: 'Priority' },
          { key: 'status', label: 'Status' },
          { key: 'sla_due_at', label: 'Promised by' },
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
      :row-class="(data: any) => (data.is_breached && !data.attended_at ? 'row-late' : '')"
    >
      <Column field="call_number" :header="pt('Call')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.call_number }}</div>
          <small class="muted">
            {{ TYPES.find((t) => t.value === data.type)?.label }} ·
            {{ when(data.logged_at) }}
          </small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">
          <div>{{ data.customer || '—' }}</div>
          <small v-if="data.site" class="muted">{{ data.site }}</small>
        </template>
      </Column>

      <Column field="asset" :header="pt('Machine')">
        <template #body="{ data }">
          <div v-if="data.asset">{{ data.asset }}</div>
          <span v-else class="muted">{{ pt('Not identified') }}</span>
          <small v-if="data.asset_tag" class="muted mono">{{ data.asset_tag }}</small>
        </template>
      </Column>

      <Column field="complaint" :header="pt('Fault')">
        <template #body="{ data }">
          <div class="clamp">{{ data.complaint }}</div>
        </template>
      </Column>

      <Column field="priority" :header="pt('Promise')" sortable>
        <template #body="{ data }">
          <div :class="{ late: data.is_breached && !data.attended_at }">{{ sla(data) }}</div>
          <small class="muted">
            {{ PRIORITIES.find((p) => p.value === data.priority)?.label }}
          </small>
        </template>
      </Column>

      <Column field="engineer" :header="pt('Engineer')">
        <template #body="{ data }">
          <span v-if="data.engineer">{{ data.engineer }}</span>
          <span v-else class="muted">{{ pt('Unassigned') }}</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="STATUS_SEVERITY[data.status] ?? 'secondary'" />
          <Tag
            v-if="!data.is_chargeable"
            class="ml"
            :value="pt('Covered')"
            severity="success"
            :title="pt('Under warranty or AMC — this visit is not billed')"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 13rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.is_open"
            icon="pi pi-user-plus"
            text
            rounded
            :title="pt('Mark attended')"
            @click="progress(data, 'attended')"
          />
          <Button
            v-if="canEdit && data.is_open"
            icon="pi pi-check"
            text
            rounded
            severity="success"
            :title="pt('Close call')"
            @click="progress(data, 'resolved')"
          />
          <Button
            v-if="canOpenJob && data.is_open"
            icon="pi pi-wrench"
            text
            rounded
            :title="pt('Open job card')"
            @click="convert(data)"
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
          {{ pt('No calls on the book. Log one as soon as the phone rings — the promise clock starts then, not when someone gets to it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Call') : pt('Log Call')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Machine') }}</label>
          <Select
            v-model="form.asset_id"
            :options="assets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            :placeholder="pt('Asset tag, model or serial')"
            @change="onAssetPicked"
          />
          <small class="hint">{{ pt('Picking the machine fills in the owner and the site.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Call type') }}</label>
          <Select v-model="form.type" :options="TYPES" option-label="label" option-value="value" class="w-full" />
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
          <small class="hint">{{ pt('Sets the response promise.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Came in by') }}</label>
          <Select v-model="form.source" :options="SOURCES" option-label="label" option-value="value" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Scheduled for') }}</label>
          <DatePicker v-model="form.scheduled_at" show-time hour-format="24" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Engineer') }}</label>
          <Select
            v-model="form.assigned_to"
            :options="engineers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Site') }}</label>
          <InputText v-model="form.site_address" class="w-full" :placeholder="pt('Where the machine actually is')" />
        </div>
        <div class="fld">
          <label>{{ pt('Contact') }}</label>
          <InputText v-model="form.contact_person" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Contact phone') }}</label>
          <InputText v-model="form.contact_phone" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Fault reported') }} *</label>
          <Textarea
            v-model="form.complaint"
            rows="3"
            auto-resize
            class="w-full"
            :placeholder="pt('In the customer’s words')"
          />
          <small v-if="errors.complaint" class="err">{{ errors.complaint[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
        </div>

        <Message v-if="!editingId" class="span-2" severity="info" :closable="false">
          {{ pt('Cover is checked as the call is logged, so the desk can say straight away whether the visit is chargeable.') }}
        </Message>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.72rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.late { color: #b91c1c; font-weight: 600; }
.ml { margin-left: 0.35rem; }
.clamp { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 22rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
:deep(.row-late) { background: #fef2f2; }
</style>
