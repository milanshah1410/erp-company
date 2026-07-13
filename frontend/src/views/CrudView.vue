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
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

const config = ref<ResourceConfig>(resources[route.meta.resource as string])
const crud = ref(useCrud(config.value.endpoint))
const search = ref('')

const dialogVisible = ref(false)
const editing = ref<any>(null)
const form = ref<Record<string, any>>({})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const selectOptions = ref<Record<string, any[]>>({})

const canCreate = computed(() => auth.can(`${config.value.permission}.create`))
const canEdit = computed(() => auth.can(`${config.value.permission}.edit`))
const canDelete = computed(() => auth.can(`${config.value.permission}.delete`))

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

function reload() {
  crud.value.fetch(search.value ? { search: search.value } : {})
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
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

function optionsFor(field: FieldConfig) {
  return selectOptions.value[field.key] || []
}

async function reinit() {
  config.value = resources[route.meta.resource as string]
  crud.value = useCrud(config.value.endpoint)
  search.value = ''
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

    <div class="crud-toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
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
      <Column v-for="col in config.columns" :key="col.key" :field="col.key" :header="col.label">
        <template #body="{ data }">
          <Tag v-if="col.type === 'boolean'" :severity="data[col.key] ? 'success' : 'danger'" :value="data[col.key] ? 'Yes' : 'No'" />
          <span v-else-if="col.type === 'currency'">{{ formatCurrency(data[col.key]) }}</span>
          <span v-else>{{ data[col.key] }}</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No records found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? `Edit ${config.title}` : `New ${config.title}`" :style="{ width: '540px' }">
      <div class="form-grid">
        <div v-for="field in config.fields" :key="field.key" class="form-field" :class="{ full: field.type === 'textarea' }">
          <label>{{ field.label }} <span v-if="field.required" class="req">*</span></label>

          <InputText v-if="field.type === 'text'" v-model="form[field.key]" :class="{ invalid: fieldError(field.key) }" />
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
</style>
