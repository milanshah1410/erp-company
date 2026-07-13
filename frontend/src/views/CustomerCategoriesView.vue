<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('customer-categories.create'))
const canEdit = computed(() => auth.can('customer-categories.edit'))
const canDelete = computed(() => auth.can('customer-categories.delete'))

const crud = ref(useCrud('/customer-categories'))
const search = ref('')

const priceLevelOptions = [
  { label: 'MRP', value: 'mrp' },
  { label: 'Wholesale', value: 'wholesale' },
  { label: 'Retail', value: 'retail' },
  { label: 'Discount', value: 'discount' },
]

function reload() {
  crud.value.fetch(search.value ? { search: search.value } : {})
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    name: '',
    code: '',
    description: '',
    default_price_level: 'retail',
    discount_percent: 0,
    is_active: true,
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
    name: row.name,
    code: row.code || '',
    description: row.description || '',
    default_price_level: row.default_price_level || 'retail',
    discount_percent: Number(row.discount_percent ?? 0),
    is_active: !!row.is_active,
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
      toast.add({ severity: 'success', summary: 'Category updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Category created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      if (!Object.keys(errors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.name}"?`,
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

onMounted(reload)
</script>

<template>
  <div>
    <div class="header">
      <h1>Customer Categories</h1>
      <Button v-if="canCreate" label="New Category" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
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
      <Column field="name" header="Name" />
      <Column field="code" header="Code" />
      <Column header="Price Level">
        <template #body="{ data }"><Tag :value="data.default_price_level" severity="info" /></template>
      </Column>
      <Column header="Discount %">
        <template #body="{ data }">{{ Number(data.discount_percent) }}%</template>
      </Column>
      <Column header="Customers">
        <template #body="{ data }">{{ data.customers_count ?? 0 }}</template>
      </Column>
      <Column field="is_active" header="Active">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No categories found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Category' : 'New Category'" :style="{ width: '520px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="fld">
          <label>Code</label>
          <InputText v-model="form.code" :class="{ invalid: fieldError('code') }" />
          <small v-if="fieldError('code')" class="error-text">{{ fieldError('code') }}</small>
        </div>
        <div class="fld">
          <label>Default Price Level <span class="req">*</span></label>
          <Select
            v-model="form.default_price_level"
            :options="priceLevelOptions"
            optionLabel="label"
            optionValue="value"
            :class="{ invalid: fieldError('default_price_level') }"
          />
          <small v-if="fieldError('default_price_level')" class="error-text">{{ fieldError('default_price_level') }}</small>
        </div>
        <div class="fld">
          <label>Discount %</label>
          <InputNumber v-model="form.discount_percent" :min="0" :max="100" :minFractionDigits="0" :maxFractionDigits="2" :class="{ invalid: fieldError('discount_percent') }" />
          <small v-if="fieldError('discount_percent')" class="error-text">{{ fieldError('discount_percent') }}</small>
        </div>
        <div class="fld full">
          <label>Description</label>
          <Textarea v-model="form.description" rows="2" />
        </div>
        <div class="fld">
          <label>Status</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="cc_is_active" />
            <label for="cc_is_active">Active</label>
          </div>
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
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.fld.full {
  grid-column: 1 / -1;
}
.fld label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber),
.fld :deep(textarea) {
  width: 100%;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.35rem;
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
