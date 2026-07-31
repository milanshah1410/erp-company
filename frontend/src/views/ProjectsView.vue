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
const crud = useCrud('/projects')

const canCreate = computed(() => auth.can('projects.create'))
const canEdit = computed(() => auth.can('projects.edit'))
// Billing raises an invoice, so it rides on the same authority as delete.
const canBill = computed(() => auth.can('projects.delete'))
const canEditTasks = computed(() => auth.can('project-tasks.edit'))

const customers = ref<any[]>([])
const employees = ref<any[]>([])
const summary = ref({ open: 0, over_budget: 0, total_budget: 0, by_status: {} as Record<string, number> })

const search = ref('')
const statusFilter = ref<string | null>(null)
const openOnly = ref(true)

const STATUSES = [
  { label: 'Planning', value: 'planning' },
  { label: 'Active', value: 'active' },
  { label: 'On Hold', value: 'on_hold' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
]
const PRIORITIES = [
  { label: 'Low', value: 'low' },
  { label: 'Normal', value: 'normal' },
  { label: 'High', value: 'high' },
  { label: 'Urgent', value: 'urgent' },
]
const BILLING_TYPES = [
  { label: 'Non-billable (internal)', value: 'non_billable' },
  { label: 'Fixed price', value: 'fixed' },
  { label: 'Time & material', value: 'time_and_material' },
]
const TASK_STATUSES = [
  { label: 'To Do', value: 'todo' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Review', value: 'review' },
  { label: 'Blocked', value: 'blocked' },
  { label: 'Done', value: 'done' },
]

const statusColors: Record<string, string> = {
  planning: 'secondary',
  active: 'info',
  on_hold: 'warn',
  completed: 'success',
  cancelled: 'danger',
}
const taskStatusColors: Record<string, string> = {
  todo: 'secondary',
  in_progress: 'info',
  review: 'warn',
  blocked: 'danger',
  done: 'success',
}

function money(v: any) {
  return formatCurrency(v)
}

function load() {
  return crud.fetch({
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    open_only: openOnly.value ? 1 : undefined,
  })
}

async function refresh() {
  await load()
  const { data } = await http.get('/projects', { params: { per_page: 1 } })
  if (data.summary) summary.value = data.summary
}

async function loadLookups() {
  const [c, e] = await Promise.all([
    http.get('/customers', { params: { per_page: 200, is_active: true } }),
    http.get('/employees', { params: { per_page: 200, is_active: true } }).catch(() => ({ data: { data: [] } })),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: [x.first_name, x.last_name].filter(Boolean).join(' ') || x.employee_code,
    value: x.id,
  }))
}

// ---- Create / edit -----------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  name: '',
  customer_id: null as number | null,
  manager_id: null as number | null,
  status: 'planning',
  priority: 'normal',
  billing_type: 'non_billable',
  budget_amount: 0,
  estimated_hours: 0,
  hourly_rate: 0,
  start_date: null as Date | null,
  end_date: null as Date | null,
  description: '',
  notes: '',
})
const form = ref(blank())

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

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
    name: row.name,
    customer_id: row.customer_id,
    manager_id: row.manager_id,
    status: row.status,
    priority: row.priority,
    billing_type: row.billing_type,
    budget_amount: row.budget_amount,
    estimated_hours: row.estimated_hours,
    hourly_rate: row.hourly_rate,
    start_date: row.start_date ? new Date(row.start_date) : null,
    end_date: row.end_date ? new Date(row.end_date) : null,
    description: row.description ?? '',
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      start_date: iso(form.value.start_date),
      end_date: iso(form.value.end_date),
    }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Project saved'), life: 2500 })
    dialogVisible.value = false
    await refresh()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the project'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

// ---- Detail: cost sheet + task board -----------------------------------------

const detailVisible = ref(false)
const detail = ref<any>(null)
const tasks = ref<any[]>([])
const tasksLoading = ref(false)

async function openDetail(row: any) {
  detailVisible.value = true
  detail.value = row
  await Promise.all([reloadDetail(row.id), loadTasks(row.id)])
}

async function reloadDetail(id: number) {
  const { data } = await http.get(`/projects/${id}`)
  detail.value = data.data
}

async function loadTasks(projectId: number) {
  tasksLoading.value = true
  try {
    const { data } = await http.get('/project-tasks', {
      params: { project_id: projectId, roots_only: 1, per_page: 200 },
    })
    tasks.value = data.data
  } finally {
    tasksLoading.value = false
  }
}

const newTask = ref({ title: '', assigned_to: null as number | null, estimated_hours: 0, due_date: null as Date | null })
const addingTask = ref(false)

async function addTask() {
  if (!detail.value || !newTask.value.title.trim()) return
  addingTask.value = true
  try {
    await http.post('/project-tasks', {
      project_id: detail.value.id,
      title: newTask.value.title,
      assigned_to: newTask.value.assigned_to,
      estimated_hours: newTask.value.estimated_hours,
      due_date: iso(newTask.value.due_date),
    })
    newTask.value = { title: '', assigned_to: null, estimated_hours: 0, due_date: null }
    await Promise.all([loadTasks(detail.value.id), reloadDetail(detail.value.id)])
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not add the task'), life: 4000 })
  } finally {
    addingTask.value = false
  }
}

async function moveTask(task: any, status: string) {
  try {
    await http.post(`/project-tasks/${task.id}/move`, { status })
    await Promise.all([loadTasks(detail.value.id), reloadDetail(detail.value.id)])
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not move the task'), life: 4000 })
  }
}

async function removeTask(task: any) {
  const ok = await Swal.fire({
    title: pt('Delete this task?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/project-tasks/${task.id}`)
    await Promise.all([loadTasks(detail.value.id), reloadDetail(detail.value.id)])
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

// ---- Lifecycle + billing -----------------------------------------------------

async function transition(row: any, status: string) {
  try {
    await http.post(`/projects/${row.id}/transition`, { status })
    toast.add({ severity: 'success', summary: pt('Project updated'), life: 2500 })
    if (detail.value?.id === row.id) await reloadDetail(row.id)
    await refresh()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4000 })
  }
}

async function bill(row: any) {
  const ok = await Swal.fire({
    title: pt('Invoice the approved billable hours?'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Yes'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    const { data } = await http.post(`/projects/${row.id}/bill`, {})
    toast.add({
      severity: 'success',
      summary: pt('Invoice raised'),
      detail: `${data.invoice.invoice_number} · ${money(data.invoice.total)}`,
      life: 4000,
    })
    if (detail.value?.id === row.id) await reloadDetail(row.id)
    await refresh()
  } catch (e: any) {
    toast.add({ severity: 'warn', summary: e.response?.data?.message ?? pt('Nothing to bill'), life: 4000 })
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this project?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Project deleted'), life: 2500 })
    await refresh()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

/** Budget-consumed bar; clamped so an overrun still renders as a full bar. */
function spentPercent(costing: any) {
  if (!costing || !costing.budget) return 0
  return Math.min(100, Math.round((costing.actual_cost / costing.budget) * 100))
}

onMounted(async () => {
  await loadLookups()
  await refresh()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Projects')"
      :subtitle="pt('Budgeted work, its task board, and what it has actually cost so far')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open projects') }}</span>
        <strong class="t-value">{{ summary.open }}</strong>
      </div>
      <div class="tile" :class="{ alert: summary.over_budget > 0 }">
        <span class="t-label">{{ pt('Over budget') }}</span>
        <strong class="t-value">{{ summary.over_budget }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">{{ pt('Total budget') }}</span>
        <strong class="t-value">{{ money(summary.total_budget) }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Project no, name or customer')" @keyup.enter="refresh" />
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
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="refresh" />
      <Button v-if="canCreate" :label="pt('New Project')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Projects"
        filenameBase="projects"
        :rows="crud.items.value"
        :columns="[
          { key: 'project_number', label: 'Project No' },
          { key: 'name', label: 'Name' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'status', label: 'Status' },
          { key: 'costing.budget', label: 'Budget' },
          { key: 'costing.actual_cost', label: 'Actual' },
          { key: 'costing.variance', label: 'Variance' },
          { key: 'costing.hours_logged', label: 'Hours' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Project')">
        <template #body="{ data }">
          <a class="link" @click="openDetail(data)">{{ data.project_number }}</a>
          <br /><small class="muted">{{ data.name }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">{{ data.customer?.name ?? pt('Internal') }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="statusColors[data.status]" />
          <Tag v-if="data.is_overdue" :value="pt('Overdue')" severity="danger" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Progress')">
        <template #body="{ data }">
          <div class="bar"><span :style="{ width: (data.costing?.progress_percent ?? 0) + '%' }" /></div>
          <small class="muted">{{ data.costing?.progress_percent ?? 0 }}%</small>
        </template>
      </Column>
      <Column :header="pt('Budget vs Actual')">
        <template #body="{ data }">
          <div class="bar" :class="{ over: data.costing?.is_over_budget }">
            <span :style="{ width: spentPercent(data.costing) + '%' }" />
          </div>
          <small :class="data.costing?.is_over_budget ? 'over-text' : 'muted'">
            {{ money(data.costing?.actual_cost) }} / {{ money(data.costing?.budget) }}
          </small>
        </template>
      </Column>
      <Column :header="pt('Hours')">
        <template #body="{ data }">
          {{ data.costing?.hours_logged ?? 0 }}
          <small class="muted">/ {{ data.costing?.hours_estimated ?? 0 }}</small>
        </template>
      </Column>
      <Column :header="pt('Unbilled')">
        <template #body="{ data }">
          <span v-if="data.bills_by_the_hour">{{ money(data.costing?.unbilled_value) }}</span>
          <small v-else class="muted">—</small>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <div class="row-actions">
            <Button icon="pi pi-eye" text size="small" :title="pt('Open')" @click="openDetail(data)" />
            <Button
              v-if="canEdit && !['completed', 'cancelled'].includes(data.status)"
              icon="pi pi-pencil"
              text
              size="small"
              :title="pt('Edit')"
              @click="openEdit(data)"
            />
            <Button
              v-if="canEdit && data.status === 'planning'"
              icon="pi pi-play"
              text
              size="small"
              :title="pt('Start')"
              @click="transition(data, 'active')"
            />
            <Button
              v-if="canBill && data.bills_by_the_hour && (data.costing?.unbilled_value ?? 0) > 0"
              icon="pi pi-indian-rupee"
              text
              size="small"
              severity="success"
              :title="pt('Bill hours')"
              @click="bill(data)"
            />
            <Button
              v-if="canEdit && !['completed', 'cancelled'].includes(data.status)"
              icon="pi pi-check"
              text
              size="small"
              severity="success"
              :title="pt('Complete')"
              @click="transition(data, 'completed')"
            />
            <Button
              v-if="canBill && !data.tasks_count"
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
      <template #empty>{{ pt('No projects match this view.') }}</template>
    </DataTable>

    <!-- Detail: cost sheet + tasks -->
    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? `${detail.project_number} — ${detail.name}` : pt('Project')"
      :style="{ width: '58rem' }"
    >
      <template v-if="detail">
        <div class="cost-grid">
          <div class="cost"><span>{{ pt('Budget') }}</span><strong>{{ money(detail.costing?.budget) }}</strong></div>
          <div class="cost"><span>{{ pt('Labour') }}</span><strong>{{ money(detail.costing?.labour_cost) }}</strong></div>
          <div class="cost"><span>{{ pt('Expenses') }}</span><strong>{{ money(detail.costing?.expense_cost) }}</strong></div>
          <div class="cost"><span>{{ pt('Actual') }}</span><strong>{{ money(detail.costing?.actual_cost) }}</strong></div>
          <div class="cost" :class="detail.costing?.is_over_budget ? 'bad' : 'good'">
            <span>{{ pt('Variance') }}</span>
            <strong>{{ money(detail.costing?.variance) }} ({{ detail.costing?.variance_percent }}%)</strong>
          </div>
          <div class="cost"><span>{{ pt('Hours') }}</span><strong>{{ detail.costing?.hours_logged }} / {{ detail.costing?.hours_estimated }}</strong></div>
        </div>

        <div v-if="detail.bills_by_the_hour" class="bill-row">
          <span>
            {{ pt('Unbilled approved hours') }}: <strong>{{ money(detail.costing?.unbilled_value) }}</strong>
            · {{ pt('already billed') }} {{ money(detail.costing?.billed_value) }}
          </span>
          <Button
            v-if="canBill && (detail.costing?.unbilled_value ?? 0) > 0"
            :label="pt('Bill hours')"
            icon="pi pi-indian-rupee"
            size="small"
            @click="bill(detail)"
          />
        </div>

        <h4 class="sec">{{ pt('Tasks') }}</h4>
        <div v-if="canEditTasks && !['completed', 'cancelled'].includes(detail.status)" class="task-add">
          <InputText v-model="newTask.title" :placeholder="pt('Task title')" class="ta-title" @keyup.enter="addTask" />
          <Select
            v-model="newTask.assigned_to"
            :options="employees"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Assignee')"
            class="ta-who"
          />
          <InputNumber v-model="newTask.estimated_hours" :min="0" :placeholder="pt('Est. hrs')" class="ta-hrs" />
          <DatePicker v-model="newTask.due_date" dateFormat="yy-mm-dd" :placeholder="pt('Due')" class="ta-due" />
          <Button icon="pi pi-plus" :loading="addingTask" :disabled="!newTask.title.trim()" @click="addTask" />
        </div>

        <DataTable :value="tasks" :loading="tasksLoading" dataKey="id" class="p-datatable-sm">
          <Column :header="pt('Task')">
            <template #body="{ data }">
              {{ data.title }}
              <Tag v-if="data.is_overdue" :value="pt('Overdue')" severity="danger" class="ml-1" />
            </template>
          </Column>
          <Column :header="pt('Assignee')">
            <template #body="{ data }">{{ data.assignee?.name ?? '—' }}</template>
          </Column>
          <Column :header="pt('Status')">
            <template #body="{ data }">
              <Select
                v-if="canEditTasks && !['completed', 'cancelled'].includes(detail.status)"
                :modelValue="data.status"
                :options="TASK_STATUSES"
                optionLabel="label"
                optionValue="value"
                class="st-select"
                @update:modelValue="(v: string) => moveTask(data, v)"
              />
              <Tag v-else :value="data.status" :severity="taskStatusColors[data.status]" />
            </template>
          </Column>
          <Column :header="pt('Progress')">
            <template #body="{ data }">{{ data.progress_percent }}%</template>
          </Column>
          <Column :header="pt('Hours')">
            <template #body="{ data }">
              {{ data.hours_logged ?? 0 }}
              <small class="muted">/ {{ data.estimated_hours }}</small>
            </template>
          </Column>
          <Column :header="pt('Due')">
            <template #body="{ data }">{{ data.due_date ?? '—' }}</template>
          </Column>
          <Column style="width: 3rem">
            <template #body="{ data }">
              <Button
                v-if="canEditTasks"
                icon="pi pi-trash"
                text
                size="small"
                severity="danger"
                @click="removeTask(data)"
              />
            </template>
          </Column>
          <template #empty>{{ pt('No tasks yet.') }}</template>
        </DataTable>
      </template>
    </Dialog>

    <!-- Create / edit -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Project') : pt('New Project')"
      :style="{ width: '46rem' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Project name') }} *</label>
          <InputText v-model="form.name" class="w-full" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Internal project')"
            class="w-full"
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Project manager') }}</label>
          <Select v-model="form.manager_id" :options="employees" optionLabel="label" optionValue="value" filter showClear class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Billing type') }}</label>
          <Select v-model="form.billing_type" :options="BILLING_TYPES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select v-model="form.status" :options="STATUSES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select v-model="form.priority" :options="PRIORITIES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Budget amount') }}</label>
          <InputNumber v-model="form.budget_amount" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Estimated hours') }}</label>
          <InputNumber v-model="form.estimated_hours" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Default hourly rate') }}</label>
          <InputNumber v-model="form.hourly_rate" :min="0" :maxFractionDigits="2" class="w-full" />
          <small class="hint">{{ pt('Timesheets inherit this unless they set their own.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Start date') }}</label>
          <DatePicker v-model="form.start_date" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('End date') }}</label>
          <DatePicker v-model="form.end_date" dateFormat="yy-mm-dd" class="w-full" />
          <small v-if="errors.end_date" class="err">{{ errors.end_date[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Description') }}</label>
          <Textarea v-model="form.description" rows="2" class="w-full" />
        </div>
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
.over-text { color: #b91c1c; font-weight: 600; }
.ml-1 { margin-left: 0.25rem; }
.link { color: #2c4a5e; font-weight: 600; cursor: pointer; text-decoration: underline; }
.row-actions { display: flex; flex-wrap: wrap; gap: 0.1rem; }
/* Budget / progress bars — a filled track reads faster than a number in a scan. */
.bar { height: 6px; border-radius: 3px; background: #e5e7eb; overflow: hidden; margin-bottom: 0.2rem; }
.bar span { display: block; height: 100%; background: #4c8b6c; }
.bar.over span { background: #b91c1c; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.sec { margin: 1.1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.cost-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.6rem; }
.cost { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.5rem 0.7rem; display: flex; flex-direction: column; gap: 0.15rem; }
.cost span { font-size: 0.72rem; color: #6b7280; }
.cost strong { font-size: 0.95rem; }
.cost.good { border-color: #4c8b6c; background: rgba(76, 139, 108, 0.08); }
.cost.bad { border-color: #b91c1c; background: rgba(185, 28, 28, 0.07); }
.bill-row { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 0.8rem; padding: 0.55rem 0.7rem; border: 1px solid #f0d9a8; background: #fdf6e7; border-radius: 8px; font-size: 0.85rem; }
.task-add { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.7rem; }
.ta-title { flex: 1 1 14rem; min-width: 10rem; }
.ta-who { width: 11rem; }
.ta-hrs { width: 7rem; }
.ta-due { width: 10rem; }
.st-select { width: 9.5rem; }
</style>
