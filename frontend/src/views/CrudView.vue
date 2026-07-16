<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import { resources, type ResourceConfig, type FieldConfig } from '../config/resources'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import { useTableExport, type ExportColumn } from '../composables/useTableExport'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

const config = ref<ResourceConfig>(resources[route.meta.resource as string])
const crud = ref(useCrud(config.value.endpoint))
const search = ref('')
// Structured filter: active status (universally supported by list endpoints
// via the `is_active` query param). Only surfaced when the resource has an
// is_active column.
const statusFilter = ref<'' | '1' | '0'>('')
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const { exporting, exportCsv, printRows, fetchAll } = useTableExport()

const dialogVisible = ref(false)
const viewVisible = ref(false)
const viewing = ref<any>(null)
const editing = ref<any>(null)
const form = ref<Record<string, any>>({})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const selectOptions = ref<Record<string, any[]>>({})

const canCreate = computed(() => auth.can(`${config.value.permission}.create`))
const canEdit = computed(() => auth.can(`${config.value.permission}.edit`))
const canDelete = computed(() => auth.can(`${config.value.permission}.delete`))

const hasStatusFilter = computed(() =>
  config.value.columns.some((c) => c.type === 'boolean' && c.key === 'is_active'),
)
const statusOptions = [
  { label: 'All', value: '' },
  { label: 'Active', value: '1' },
  { label: 'Inactive', value: '0' },
]

function blankForm() {
  const f: Record<string, any> = {}
  for (const field of config.value.fields) {
    f[field.key] = field.default ?? (field.type === 'checkbox' ? false : null)
  }
  return f
}

async function loadSelectOptions() {
  for (const field of config.value.fields) {
    if (field.optionsFrom) {
      try {
        const { data } = await http.get(field.optionsFrom, { params: { per_page: 100 } })
        selectOptions.value[field.key] = (data.data ?? data).map((o: any) => ({
          label: o[field.optionLabel || 'name'],
          value: o.id,
        }))
      } catch {
        selectOptions.value[field.key] = []
      }
    } else if (field.options) {
      selectOptions.value[field.key] = field.options
    }
  }
}

/** Query params reflecting the current search + filters + sort. */
function queryParams(): Record<string, any> {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value !== '') params.is_active = statusFilter.value
  if (sortField.value && sortOrder.value) {
    params.sort = sortField.value
    params.direction = sortOrder.value === 1 ? 'asc' : 'desc'
  }
  return params
}

function reload() {
  crud.value.fetch(queryParams())
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  crud.value.currentPage = 1
  reload()
}

function applyFilters() {
  crud.value.currentPage = 1
  reload()
}

function clearFilters() {
  search.value = ''
  statusFilter.value = ''
  applyFilters()
}

// --- Export / Print (whole result set, honouring current search + filters) ---
function exportColumns(): ExportColumn[] {
  return config.value.columns.map((col) => ({
    key: col.key,
    label: col.label,
    format: (value: unknown) => {
      if (col.type === 'boolean') return value ? 'Yes' : 'No'
      if (col.type === 'currency') return formatCurrency(value as number)
      if (col.type === 'date') return formatDate(value)
      return value == null ? '' : String(value)
    },
  }))
}

async function onExport() {
  try {
    const rows = await fetchAll(config.value.endpoint, queryParams())
    exportCsv(exportColumns(), rows, config.value.title.replace(/\s+/g, '-').toLowerCase())
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Export failed', detail: e.response?.data?.message || 'Could not export', life: 4000 })
  }
}

async function onPrint() {
  try {
    const rows = await fetchAll(config.value.endpoint, queryParams())
    printRows(config.value.title, exportColumns(), rows)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Print failed', detail: e.response?.data?.message || 'Could not print', life: 4000 })
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
  form.value = {}
  for (const field of config.value.fields) {
    form.value[field.key] = row[field.key]
  }
  errors.value = {}
  dialogVisible.value = true
}

function openView(row: any) {
  viewing.value = row
  viewVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.value.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: 'Updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this record?',
    text: 'This action cannot be undone.',
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

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function formatDate(value: unknown): string {
  if (!value) return ''
  const d = new Date(value as string)
  return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString()
}

function optionsFor(field: FieldConfig) {
  return selectOptions.value[field.key] || []
}

/** Human-readable value for the read-only View dialog (resolves select labels). */
function displayValue(field: FieldConfig, row: any): string {
  const raw = row[field.key]
  if (raw == null || raw === '') return '—'
  if (field.type === 'checkbox') return raw ? 'Yes' : 'No'
  if (field.type === 'number') return String(raw)
  if (field.type === 'date' || field.type === 'month') return formatDate(raw)
  if (field.type === 'select') {
    const opt = optionsFor(field).find((o: any) => o.value === raw)
    return opt ? opt.label : String(raw)
  }
  return String(raw)
}

async function reinit() {
  config.value = resources[route.meta.resource as string]
  crud.value = useCrud(config.value.endpoint)
  search.value = ''
  statusFilter.value = ''
  sortField.value = null
  sortOrder.value = null
  selectOptions.value = {}
  await loadSelectOptions()
  reload()
}

watch(() => route.meta.resource, reinit)
onMounted(reinit)
</script>

<template>
  <div class="crud">
    <div class="crud-header">
      <h1>{{ config.title }}</h1>
      <Button v-if="canCreate" :label="`New ${config.title.replace(/s$/, '')}`" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="crud-toolbar no-print">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="applyFilters" />
      </span>
      <Select
        v-if="hasStatusFilter"
        v-model="statusFilter"
        :options="statusOptions"
        optionLabel="label"
        optionValue="value"
        class="status-filter"
        @change="applyFilters"
      />
      <Button label="Search" outlined @click="applyFilters" />
      <Button
        v-if="search || statusFilter !== ''"
        label="Clear"
        severity="secondary"
        text
        @click="clearFilters"
      />
      <span class="toolbar-spacer" />
      <Button label="Export" icon="pi pi-download" outlined :loading="exporting" @click="onExport" />
      <Button label="Print" icon="pi pi-print" outlined :loading="exporting" @click="onPrint" />
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
      removableSort
      :sortField="sortField ?? undefined"
      :sortOrder="sortOrder ?? undefined"
      @page="onPage"
      @sort="onSort"
      class="p-datatable-sm"
    >
      <Column v-for="col in config.columns" :key="col.key" :field="col.key" :header="col.label" sortable>
        <template #body="{ data }">
          <Tag v-if="col.type === 'boolean'" :severity="data[col.key] ? 'success' : 'danger'" :value="data[col.key] ? 'Yes' : 'No'" />
          <span v-else-if="col.type === 'currency'">{{ formatCurrency(data[col.key]) }}</span>
          <span v-else-if="col.type === 'date'">{{ formatDate(data[col.key]) }}</span>
          <span v-else>{{ data[col.key] }}</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openView(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No records found.</template>
    </DataTable>

    <!-- Create / Edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? `Edit ${config.title}` : `New ${config.title}`" :style="{ width: '540px' }">
      <div class="form-grid">
        <div v-for="field in config.fields" :key="field.key" class="form-field" :class="{ full: field.type === 'textarea' }">
          <label>{{ field.label }} <span v-if="field.required" class="req">*</span></label>

          <InputText v-if="field.type === 'text'" v-model="form[field.key]" :class="{ invalid: fieldError(field.key) }" />
          <InputText v-else-if="field.type === 'date' || field.type === 'month'" type="date" v-model="form[field.key]" :class="{ invalid: fieldError(field.key) }" />
          <InputText v-else-if="field.type === 'time'" type="time" v-model="form[field.key]" :class="{ invalid: fieldError(field.key) }" />
          <Textarea v-else-if="field.type === 'textarea'" v-model="form[field.key]" rows="3" :class="{ invalid: fieldError(field.key) }" />
          <InputNumber v-else-if="field.type === 'number'" v-model="form[field.key]" :minFractionDigits="0" :maxFractionDigits="6" class="w-full" :class="{ invalid: fieldError(field.key) }" />
          <Select
            v-else-if="field.type === 'select'"
            v-model="form[field.key]"
            :options="optionsFor(field)"
            optionLabel="label"
            optionValue="value"
            :placeholder="`Select ${field.label}`"
            showClear
            :class="{ invalid: fieldError(field.key) }"
          />
          <div v-else-if="field.type === 'checkbox'" class="checkbox-row">
            <Checkbox v-model="form[field.key]" binary :inputId="field.key" />
            <label :for="field.key">Enabled</label>
          </div>

          <small v-if="fieldError(field.key)" class="error-text">{{ fieldError(field.key) }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Read-only detail dialog -->
    <Dialog v-model:visible="viewVisible" modal :header="config.title.replace(/s$/, '') + ' Details'" :style="{ width: '540px' }">
      <div v-if="viewing" class="detail-grid">
        <div v-for="field in config.fields" :key="field.key" class="detail-row" :class="{ full: field.type === 'textarea' }">
          <span class="detail-label">{{ field.label }}</span>
          <span class="detail-value">{{ displayValue(field, viewing) }}</span>
        </div>
      </div>
      <template #footer>
        <Button v-if="canEdit && viewing" label="Edit" icon="pi pi-pencil" outlined @click="viewVisible = false; openEdit(viewing)" />
        <Button label="Close" text @click="viewVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.crud-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.crud-header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.crud-toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  align-items: center;
}
.toolbar-spacer {
  flex: 1 1 auto;
}
.status-filter {
  min-width: 9rem;
}
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
  width: 280px;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.form-field.full {
  grid-column: 1 / -1;
}
.form-field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.form-field :deep(.p-inputtext),
.form-field :deep(.p-select),
.form-field :deep(.p-inputnumber) {
  width: 100%;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem 1.5rem;
  padding-top: 0.5rem;
}
.detail-row {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.detail-row.full {
  grid-column: 1 / -1;
}
.detail-label {
  font-size: 0.75rem;
  font-weight: 500;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.detail-value {
  font-size: 0.95rem;
  color: var(--graphite);
}
</style>
