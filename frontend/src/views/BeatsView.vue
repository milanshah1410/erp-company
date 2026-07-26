<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
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

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')

const salesmen = ref<{ label: string; value: number }[]>([])
const customers = ref<{ label: string; value: number }[]>([])

const DAYS = [
  { label: 'Monday', value: 'mon' }, { label: 'Tuesday', value: 'tue' }, { label: 'Wednesday', value: 'wed' },
  { label: 'Thursday', value: 'thu' }, { label: 'Friday', value: 'fri' }, { label: 'Saturday', value: 'sat' },
  { label: 'Sunday', value: 'sun' },
]

const filterSalesman = ref<number | null>(null)

const canCreate = computed(() => auth.can('beats.create'))
const canEdit = computed(() => auth.can('beats.edit'))
const canDelete = computed(() => auth.can('beats.delete'))

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ salesman_id: null, name: '', day_of_week: null, area: '', notes: '', is_active: true })
const stops = ref<{ customer_id: number | null }[]>([])
const stopToAdd = ref<number | null>(null)

function dayLabel(v: string | null) {
  return DAYS.find((d) => d.value === v)?.label ?? '—'
}
function customerLabel(id: number | null) {
  return customers.value.find((c) => c.value === id)?.label ?? `#${id}`
}
function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function loadOptions() {
  const [s, c] = await Promise.all([
    http.get('/salesmen', { params: { per_page: 100, is_active: true } }),
    http.get('/customers', { params: { per_page: 200 } }),
  ])
  salesmen.value = (s.data.data ?? s.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/beats', {
      params: {
        salesman_id: filterSalesman.value || undefined,
        search: search.value || undefined,
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

function openCreate() {
  editingId.value = null
  form.value = { salesman_id: null, name: '', day_of_week: null, area: '', notes: '', is_active: true }
  stops.value = []
  stopToAdd.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  // Fetch the full beat (with ordered stops) for editing.
  const { data } = await http.get(`/beats/${row.id}`)
  const beat = data.data ?? data
  form.value = {
    salesman_id: beat.salesman_id, name: beat.name, day_of_week: beat.day_of_week,
    area: beat.area || '', notes: beat.notes || '', is_active: beat.is_active,
  }
  stops.value = (beat.stops ?? []).map((s: any) => ({ customer_id: s.customer_id }))
  stopToAdd.value = null
  dialogVisible.value = true
}

function addStop() {
  if (!stopToAdd.value) return
  if (stops.value.some((s) => s.customer_id === stopToAdd.value)) {
    toast.add({ severity: 'warn', summary: 'Already on this beat', life: 2000 })
    return
  }
  stops.value.push({ customer_id: stopToAdd.value })
  stopToAdd.value = null
}
function removeStop(i: number) {
  stops.value.splice(i, 1)
}
function moveStop(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= stops.value.length) return
  const tmp = stops.value[i]
  stops.value[i] = stops.value[j]
  stops.value[j] = tmp
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      area: form.value.area || undefined,
      notes: form.value.notes || undefined,
      customers: stops.value.map((s, i) => ({ customer_id: s.customer_id, sequence: i + 1 })),
    }
    if (editingId.value) {
      await http.put(`/beats/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Beat updated', life: 2500 })
    } else {
      await http.post('/beats', payload)
      toast.add({ severity: 'success', summary: 'Beat created', life: 2500 })
    }
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this beat?',
    text: row.name,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/beats/${row.id}`)
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
    <PageHeader title="Beat Plan &amp; Routes">
      <template #actions>
        <Button v-if="canCreate" label="New Beat" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filterSalesman" :options="salesmen" optionLabel="label" optionValue="value" filter showClear
        placeholder="All salesmen" class="w-60" @change="load" />
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search beat / area..." @keyup.enter="load" />
      </span>
      <Button label="Search" outlined @click="load" />
      <TableExportButtons title="Beats" endpoint="/beats" :params="() => ({ salesman_id: filterSalesman || undefined, search: search || undefined })"
        :columns="[
          { key: 'name', label: 'Beat' },
          { key: 'salesman', label: 'Salesman' },
          { key: 'day_of_week', label: 'Day' },
          { key: 'area', label: 'Area' },
          { key: 'stops_count', label: 'Stops' },
          { key: 'is_active', label: 'Active', format: (v) => v ? 'Yes' : 'No' },
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
      <Column field="name" header="Beat" />
      <Column field="salesman" header="Salesman" />
      <Column header="Day">
        <template #body="{ data }">{{ dayLabel(data.day_of_week) }}</template>
      </Column>
      <Column field="area" header="Area" />
      <Column header="Stops">
        <template #body="{ data }">{{ data.stops_count ?? 0 }}</template>
      </Column>
      <Column header="Active">
        <template #body="{ data }"><Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" /></template>
      </Column>
      <Column header="Actions" style="width: 10rem; white-space: nowrap">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No beats defined.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Beat' : 'New Beat'" :style="{ width: '640px' }">
      <div class="form">
        <div class="row">
          <div class="field">
            <label>Salesman <span class="req">*</span></label>
            <Select v-model="form.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter
              placeholder="Select salesman" :class="{ invalid: fieldError('salesman_id') }" />
            <small v-if="fieldError('salesman_id')" class="error-text">{{ fieldError('salesman_id') }}</small>
          </div>
          <div class="field">
            <label>Beat Name <span class="req">*</span></label>
            <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
            <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>Scheduled Day</label>
            <Select v-model="form.day_of_week" :options="DAYS" optionLabel="label" optionValue="value" showClear placeholder="Ad-hoc" />
          </div>
          <div class="field">
            <label>Area</label>
            <InputText v-model="form.area" />
          </div>
        </div>

        <div class="field">
          <label>Route Stops <span class="muted">(in visiting order)</span></label>
          <div class="stop-add">
            <Select v-model="stopToAdd" :options="customers" optionLabel="label" optionValue="value" filter
              placeholder="Add a customer..." class="grow" />
            <Button label="Add" icon="pi pi-plus" outlined :disabled="!stopToAdd" @click="addStop" />
          </div>
          <ol v-if="stops.length" class="stop-list">
            <li v-for="(s, i) in stops" :key="s.customer_id ?? i">
              <span class="seq">{{ i + 1 }}</span>
              <span class="cust">{{ customerLabel(s.customer_id) }}</span>
              <span class="stop-actions">
                <Button icon="pi pi-arrow-up" text rounded size="small" :disabled="i === 0" @click="moveStop(i, -1)" />
                <Button icon="pi pi-arrow-down" text rounded size="small" :disabled="i === stops.length - 1" @click="moveStop(i, 1)" />
                <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeStop(i)" />
              </span>
            </li>
          </ol>
          <p v-else class="empty-stops">No stops yet — add customers to build the route.</p>
        </div>

        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
        <div class="field checkbox-row">
          <Checkbox v-model="form.is_active" binary inputId="beat-active" />
          <label for="beat-active">Active</label>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Beat Details" :fields="[
      { key: 'name', label: 'Beat' },
      { key: 'salesman', label: 'Salesman' },
      { key: 'day_of_week', label: 'Day' },
      { key: 'area', label: 'Area' },
      { key: 'stops_count', label: 'Stops' },
      { key: 'is_active', label: 'Active' },
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
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.w-60 { width: 240px; }
.search {
  position: relative;
  display: flex;
  align-items: center;
}
.search i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.search :deep(input) {
  padding-left: 2.25rem;
  width: 260px;
}
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
}
.field :deep(.p-select),
.field :deep(.p-inputtext) {
  width: 100%;
}
.stop-add {
  display: flex;
  gap: 0.5rem;
}
.grow { flex: 1; }
.stop-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  max-height: 220px;
  overflow-y: auto;
}
.stop-list li {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.35rem 0.6rem;
  border-bottom: 1px solid #f1f5f9;
}
.stop-list li:last-child { border-bottom: none; }
.seq {
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 999px;
  background: #eef2ff;
  color: #4338ca;
  font-size: 0.8rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cust { flex: 1; font-size: 0.9rem; }
.stop-actions { display: flex; }
.empty-stops {
  color: #94a3b8;
  font-size: 0.85rem;
  margin: 0.5rem 0 0;
}
.checkbox-row {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}
.muted { color: #94a3b8; font-weight: 400; font-size: 0.78rem; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
