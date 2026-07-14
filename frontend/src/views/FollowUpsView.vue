<script setup lang="ts">
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
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('follow-ups.create'))
const canEdit = computed(() => auth.can('follow-ups.edit'))
const canDelete = computed(() => auth.can('follow-ups.delete'))

const crud = ref(useCrud('/follow-ups'))

// --- Agenda scope tabs ---
type Scope = 'today' | 'overdue' | 'upcoming' | 'all'
const tabs: { key: Scope; label: string; icon: string }[] = [
  { key: 'today', label: 'Today', icon: 'pi pi-calendar' },
  { key: 'overdue', label: 'Overdue', icon: 'pi pi-exclamation-triangle' },
  { key: 'upcoming', label: 'Upcoming', icon: 'pi pi-clock' },
  { key: 'all', label: 'All', icon: 'pi pi-list' },
]
const scope = ref<Scope>('today')

const typeColors: Record<string, string> = {
  call: 'info', visit: 'success', email: 'warn', whatsapp: 'success', other: 'secondary',
}
const statusColors: Record<string, string> = {
  pending: 'warn', done: 'success', cancelled: 'secondary',
}

const typeOptions = [
  { label: 'Call', value: 'call' },
  { label: 'Visit', value: 'visit' },
  { label: 'Email', value: 'email' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Other', value: 'other' },
]

function setScope(s: Scope) {
  scope.value = s
  crud.value.currentPage = 1
  reload()
}

function reload() {
  const params: Record<string, any> = {}
  if (scope.value !== 'all') params.scope = scope.value
  crud.value.fetch(params)
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

function fmtDue(value: string | null): string {
  if (!value) return ''
  const d = new Date(value)
  return isNaN(d.getTime()) ? String(value) : d.toLocaleString()
}

// --- Related-party pickers (leads + customers) ---
const users = ref<any[]>([])
const leads = ref<any[]>([])
const customers = ref<any[]>([])

async function loadPickers() {
  try {
    const [u, l, c] = await Promise.all([
      http.get('/users', { params: { per_page: 100 } }),
      http.get('/leads', { params: { per_page: 100, state: 'open' } }),
      http.get('/customers', { params: { per_page: 100 } }),
    ])
    users.value = (u.data.data ?? []).map((x: any) => ({ label: x.name, value: x.id }))
    leads.value = (l.data.data ?? []).map((x: any) => ({ label: `${x.name} (${x.code})`, value: x.id }))
    customers.value = (c.data.data ?? []).map((x: any) => ({ label: `${x.name} (${x.code})`, value: x.id }))
  } catch {
    /* non-blocking */
  }
}

const relatedOptions = computed(() => (form.value.related_type === 'lead' ? leads.value : customers.value))

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    related_type: 'customer', related_id: null,
    type: 'call', due_at: new Date(), title: '', notes: '', assigned_to: null,
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
    type: row.type || 'call',
    due_at: row.due_at ? new Date(row.due_at) : new Date(),
    title: row.title || '',
    notes: row.notes || '',
    assigned_to: row.assigned_to ?? null,
  }
  errors.value = {}
  dialogVisible.value = true
}

// The API accepts a parseable datetime; send "YYYY-MM-DD HH:mm:ss".
function toApiDate(d: Date | null): string | null {
  if (!d) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: Record<string, any> = {
      type: form.value.type,
      due_at: toApiDate(form.value.due_at),
      title: form.value.title,
      notes: form.value.notes,
      assigned_to: form.value.assigned_to,
    }
    if (editing.value) {
      await crud.value.update(editing.value.id, payload)
      toast.add({ severity: 'success', summary: 'Follow-up updated', life: 2500 })
    } else {
      payload.related_type = form.value.related_type
      payload.related_id = form.value.related_id
      await crud.value.create(payload)
      toast.add({ severity: 'success', summary: 'Follow-up created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Complete action ---
async function complete(row: any) {
  const res = await Swal.fire({
    title: 'Complete follow-up',
    input: 'textarea',
    inputLabel: 'Outcome (optional)',
    inputPlaceholder: 'What happened?',
    showCancelButton: true,
    confirmButtonText: 'Mark done',
    confirmButtonColor: '#16a34a',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/follow-ups/${row.id}/complete`, { status: 'done', outcome: res.value || null })
    toast.add({ severity: 'success', summary: 'Marked done', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this follow-up?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await crud.value.remove(row.id)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(() => {
  loadPickers()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Follow-ups</h1>
      <Button v-if="canCreate" label="New Follow-up" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="tabstrip">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ active: scope === t.key }"
        @click="setScope(t.key)"
      >
        <i :class="t.icon" /> {{ t.label }}
      </button>
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
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column header="Due">
        <template #body="{ data }">
          <span :class="{ overdue: data.is_overdue }">{{ fmtDue(data.due_at) }}</span>
        </template>
      </Column>
      <Column header="Type" style="width: 7rem">
        <template #body="{ data }">
          <Tag :severity="typeColors[data.type]" :value="data.type" />
        </template>
      </Column>
      <Column header="Related">
        <template #body="{ data }">
          <span>{{ data.related?.name ?? '—' }}</span>
          <span class="muted-sub">{{ data.related_type }}</span>
        </template>
      </Column>
      <Column field="title" header="Title" />
      <Column header="Assigned">
        <template #body="{ data }">{{ data.assignee?.name ?? '—' }}</template>
      </Column>
      <Column header="Status" style="width: 7rem">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.status === 'pending'"
            icon="pi pi-check" text rounded size="small" severity="success"
            title="Mark done" @click="complete(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No follow-ups here.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Follow-up' : 'New Follow-up'" :style="{ width: '560px' }">
      <div class="form-grid">
        <template v-if="!editing">
          <div class="fld">
            <label>Related To <span class="req">*</span></label>
            <Select
              v-model="form.related_type"
              :options="[{ label: 'Customer', value: 'customer' }, { label: 'Lead', value: 'lead' }]"
              optionLabel="label" optionValue="value"
              @change="form.related_id = null"
            />
          </div>
          <div class="fld">
            <label>{{ form.related_type === 'lead' ? 'Lead' : 'Customer' }} <span class="req">*</span></label>
            <Select
              v-model="form.related_id"
              :options="relatedOptions"
              optionLabel="label" optionValue="value"
              filter placeholder="Select"
              :class="{ invalid: fieldError('related_id') }"
            />
            <small v-if="fieldError('related_id')" class="error-text">{{ fieldError('related_id') }}</small>
          </div>
        </template>

        <div class="fld">
          <label>Type</label>
          <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Due At <span class="req">*</span></label>
          <DatePicker v-model="form.due_at" showTime hourFormat="24" dateFormat="yy-mm-dd" :class="{ invalid: fieldError('due_at') }" />
          <small v-if="fieldError('due_at')" class="error-text">{{ fieldError('due_at') }}</small>
        </div>
        <div class="fld full">
          <label>Title</label>
          <InputText v-model="form.title" placeholder="e.g. Send quotation" />
        </div>
        <div class="fld">
          <label>Assigned To</label>
          <Select v-model="form.assigned_to" :options="users" optionLabel="label" optionValue="value" placeholder="Unassigned" showClear filter />
        </div>
        <div class="fld full">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.tabstrip { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; flex-wrap: wrap; }
.tab {
  background: none; border: none; border-bottom: 2px solid transparent;
  padding: 0.6rem 0.9rem; font-size: 0.88rem; color: #6b7280; cursor: pointer;
  display: flex; align-items: center; gap: 0.4rem;
}
.tab:hover { color: #111827; }
.tab.active { color: var(--ink-blue, #2c4a5e); border-bottom-color: var(--safety-amber, #e8a33d); font-weight: 600; }
.muted-sub { display: block; color: #6b7280; font-size: 0.78rem; text-transform: capitalize; }
.overdue { color: #dc2626; font-weight: 600; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-datepicker),
.fld :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
