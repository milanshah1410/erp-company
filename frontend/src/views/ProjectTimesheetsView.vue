<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
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
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/project-timesheets')

const canCreate = computed(() => auth.can('project-timesheets.create'))
const canEdit = computed(() => auth.can('project-timesheets.edit'))
const canApprove = computed(() => auth.can('project-timesheets.approve'))
const canDelete = computed(() => auth.can('project-timesheets.delete'))

const projects = ref<any[]>([])
const employees = ref<any[]>([])
const tasks = ref<any[]>([])

const totals = ref({ hours: 0, amount: 0, billable_hours: 0 })
const selected = ref<any[]>([])

const projectFilter = ref<number | null>(null)
const employeeFilter = ref<number | null>(null)
const statusFilter = ref<string | null>(null)
const invoiceableOnly = ref(false)
const from = ref<Date | null>(null)
const to = ref<Date | null>(null)

const STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Submitted', value: 'submitted' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
]
const statusColors: Record<string, string> = {
  draft: 'secondary',
  submitted: 'info',
  approved: 'success',
  rejected: 'danger',
}

function money(v: any) {
  return formatCurrency(v)
}

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  await crud.fetch({
    project_id: projectFilter.value || undefined,
    employee_id: employeeFilter.value || undefined,
    status: statusFilter.value || undefined,
    invoiceable_only: invoiceableOnly.value ? 1 : undefined,
    from: iso(from.value) || undefined,
    to: iso(to.value) || undefined,
  })
  // useCrud keeps only `data`; the footer totals ride alongside it, so re-read
  // them from the same filtered call.
  const { data } = await http.get('/project-timesheets', {
    params: {
      per_page: 1,
      project_id: projectFilter.value || undefined,
      employee_id: employeeFilter.value || undefined,
      status: statusFilter.value || undefined,
      from: iso(from.value) || undefined,
      to: iso(to.value) || undefined,
    },
  })
  if (data.totals) totals.value = data.totals
  selected.value = []
}

async function loadLookups() {
  const [p, e] = await Promise.all([
    http.get('/projects', { params: { per_page: 200, open_only: 1 } }),
    http.get('/employees', { params: { per_page: 200, is_active: true } }).catch(() => ({ data: { data: [] } })),
  ])
  projects.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.project_number} — ${x.name}`,
    value: x.id,
    hourly_rate: x.hourly_rate,
  }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: [x.first_name, x.last_name].filter(Boolean).join(' ') || x.employee_code,
    value: x.id,
  }))
}

/** Tasks narrow to the project being logged against. */
async function loadTasksFor(projectId: number | null) {
  if (!projectId) {
    tasks.value = []
    return
  }
  const { data } = await http.get('/project-tasks', { params: { project_id: projectId, per_page: 200 } })
  tasks.value = data.data.map((t: any) => ({ label: t.title, value: t.id }))
}

// ---- Log / edit an entry -------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  project_id: null as number | null,
  project_task_id: null as number | null,
  employee_id: null as number | null,
  work_date: new Date() as Date | null,
  hours: 0,
  description: '',
  is_billable: true,
  hourly_rate: 0,
})
const form = ref(blank())

function openCreate() {
  editing.value = null
  form.value = blank()
  form.value.project_id = projectFilter.value
  errors.value = {}
  loadTasksFor(form.value.project_id)
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    project_id: row.project_id,
    project_task_id: row.project_task_id,
    employee_id: row.employee_id,
    work_date: row.work_date ? new Date(row.work_date) : null,
    hours: row.hours,
    description: row.description ?? '',
    is_billable: row.is_billable,
    hourly_rate: row.hourly_rate,
  }
  loadTasksFor(row.project_id)
  dialogVisible.value = true
}

function onProjectPicked() {
  form.value.project_task_id = null
  loadTasksFor(form.value.project_id)
}

/** What the entry will be worth — blank rate falls back to the project's. */
const previewAmount = computed(() => {
  const project = projects.value.find((p) => p.value === form.value.project_id)
  const rate = Number(form.value.hourly_rate) || Number(project?.hourly_rate) || 0
  return (Number(form.value.hours) || 0) * rate
})

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, work_date: iso(form.value.work_date) }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Timesheet saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the entry'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this entry?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Entry deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

/** Batch transition — approval is a weekly act over a grid, not a per-row one. */
async function batch(action: 'submit' | 'approve' | 'reject') {
  if (!selected.value.length) return
  try {
    const { data } = await http.post(`/project-timesheets/${action}`, {
      ids: selected.value.map((r) => r.id),
    })
    toast.add({
      severity: data.moved ? 'success' : 'warn',
      summary: data.message,
      detail: data.skipped ? pt('{n} skipped (wrong state or already billed)', { n: data.skipped }) : '',
      life: 4000,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4000 })
  }
}

onMounted(async () => {
  await loadLookups()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Timesheets')"
      :subtitle="pt('Hours logged against projects — only approved billable hours can be invoiced')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Hours in view') }}</span>
        <strong class="t-value">{{ totals.hours }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Billable hours') }}</span>
        <strong class="t-value">{{ totals.billable_hours }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">{{ pt('Labour value') }}</span>
        <strong class="t-value">{{ money(totals.amount) }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <Select
        v-model="projectFilter"
        :options="projects"
        optionLabel="label"
        optionValue="value"
        filter
        showClear
        :placeholder="pt('All projects')"
        class="filter-wide"
        @change="load"
      />
      <Select
        v-model="employeeFilter"
        :options="employees"
        optionLabel="label"
        optionValue="value"
        filter
        showClear
        :placeholder="pt('All people')"
        class="filter"
        @change="load"
      />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="filter"
        @change="load"
      />
      <DatePicker v-model="from" dateFormat="yy-mm-dd" :placeholder="pt('From')" class="filter-date" @date-select="load" />
      <DatePicker v-model="to" dateFormat="yy-mm-dd" :placeholder="pt('To')" class="filter-date" @date-select="load" />
      <label class="inline-check">
        <Checkbox v-model="invoiceableOnly" binary @change="load" /> {{ pt('Ready to bill') }}
      </label>
      <Button :label="pt('Refresh')" icon="pi pi-refresh" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('Log Hours')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Timesheets"
        filenameBase="project-timesheets"
        :rows="crud.items.value"
        :columns="[
          { key: 'work_date', label: 'Date' },
          { key: 'project.project_number', label: 'Project' },
          { key: 'employee.name', label: 'Person' },
          { key: 'task', label: 'Task' },
          { key: 'hours', label: 'Hours' },
          { key: 'hourly_rate', label: 'Rate' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <div v-if="selected.length" class="batch-bar">
      <span>{{ pt('{n} selected', { n: selected.length }) }}</span>
      <Button v-if="canEdit" :label="pt('Submit')" icon="pi pi-send" size="small" outlined @click="batch('submit')" />
      <Button v-if="canApprove" :label="pt('Approve')" icon="pi pi-check" size="small" severity="success" @click="batch('approve')" />
      <Button v-if="canApprove" :label="pt('Reject')" icon="pi pi-times" size="small" severity="danger" outlined @click="batch('reject')" />
    </div>

    <DataTable
      v-model:selection="selected"
      :value="crud.items.value"
      :loading="crud.loading.value"
      dataKey="id"
      class="p-datatable-sm"
    >
      <Column selectionMode="multiple" style="width: 3rem" />
      <Column :header="pt('Date')">
        <template #body="{ data }">{{ data.work_date }}</template>
      </Column>
      <Column :header="pt('Project')">
        <template #body="{ data }">
          <strong>{{ data.project?.project_number }}</strong>
          <br /><small class="muted">{{ data.project?.name }}</small>
        </template>
      </Column>
      <Column :header="pt('Task')">
        <template #body="{ data }">{{ data.task ?? '—' }}</template>
      </Column>
      <Column :header="pt('Person')">
        <template #body="{ data }">{{ data.employee?.name }}</template>
      </Column>
      <Column :header="pt('Hours')">
        <template #body="{ data }">
          {{ data.hours }}
          <Tag v-if="!data.is_billable" :value="pt('Non-billable')" severity="secondary" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Rate')">
        <template #body="{ data }">{{ money(data.hourly_rate) }}</template>
      </Column>
      <Column :header="pt('Amount')">
        <template #body="{ data }">{{ money(data.amount) }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="statusColors[data.status]" />
          <Tag v-if="data.invoice" :value="data.invoice" severity="contrast" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit && !data.invoice_id" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button
            v-if="canDelete && !data.invoice_id"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No hours logged for this view.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Entry') : pt('Log Hours')"
      :style="{ width: '38rem' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Project') }} *</label>
          <Select
            v-model="form.project_id"
            :options="projects"
            optionLabel="label"
            optionValue="value"
            filter
            class="w-full"
            @change="onProjectPicked"
          />
          <small v-if="errors.project_id" class="err">{{ errors.project_id[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Task') }}</label>
          <Select
            v-model="form.project_task_id"
            :options="tasks"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="tasks.length ? pt('None') : pt('No tasks on this project')"
            :disabled="!tasks.length"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Person') }} *</label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter class="w-full" />
          <small v-if="errors.employee_id" class="err">{{ errors.employee_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Date') }} *</label>
          <DatePicker v-model="form.work_date" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Hours') }} *</label>
          <InputNumber v-model="form.hours" :min="0" :max="24" :maxFractionDigits="2" class="w-full" />
          <small v-if="errors.hours" class="err">{{ errors.hours[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Hourly rate') }}</label>
          <InputNumber v-model="form.hourly_rate" :min="0" :maxFractionDigits="2" class="w-full" />
          <small class="hint">{{ pt('Leave at 0 to use the project rate.') }}</small>
        </div>
        <div class="fld check-fld">
          <label for="ts-billable">{{ pt('Billable') }}</label>
          <Checkbox v-model="form.is_billable" binary inputId="ts-billable" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('What was done') }}</label>
          <Textarea v-model="form.description" rows="2" class="w-full" />
        </div>
      </div>

      <div class="preview">
        <span>{{ pt('Entry value') }}</span>
        <strong>{{ money(previewAmount) }}</strong>
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
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.filter { width: 170px; }
.filter-wide { width: 240px; }
.filter-date { width: 145px; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.batch-bar { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border: 1px solid #e8a33d; background: rgba(232, 163, 61, 0.1); border-radius: 8px; font-size: 0.85rem; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.25rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.check-fld { flex-direction: row; align-items: center; gap: 0.5rem; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.preview { display: flex; justify-content: space-between; align-items: baseline; margin-top: 1rem; padding-top: 0.6rem; border-top: 1px solid var(--surface-border, #e5e7eb); font-size: 0.9rem; }
.preview strong { font-size: 1.1rem; }
</style>
