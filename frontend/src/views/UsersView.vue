<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/users')

const search = ref('')
const roleOptions = ref<any[]>([])
const companyOptions = ref<any[]>([])
const branchOptions = ref<any[]>([])

const canCreate = computed(() => auth.can('users.create'))
const canEdit = computed(() => auth.can('users.edit'))
const canDelete = computed(() => auth.can('users.delete'))

// --- Create / Edit dialog ---
const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({ name: '', email: '', phone: '', password: '', is_active: true, roles: [], company_ids: [], branch_ids: [] })
const errors = ref<Record<string, string[]>>({})

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function reload() {
  crud.fetch(search.value ? { search: search.value } : {})
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

function openCreate() {
  editing.value = null
  form.value = { name: '', email: '', phone: '', password: '', is_active: true, roles: [], company_ids: [], branch_ids: [] }
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    name: row.name,
    email: row.email,
    phone: row.phone,
    password: '',
    is_active: !!row.is_active,
    roles: [...(row.roles || [])],
    company_ids: [...(row.company_ids || [])],
    branch_ids: [...(row.branch_ids || [])],
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: Record<string, any> = {
      name: form.value.name,
      email: form.value.email,
      phone: form.value.phone,
      is_active: form.value.is_active,
      roles: form.value.roles,
      company_ids: form.value.company_ids,
      branch_ids: form.value.branch_ids,
    }
    if (editing.value) {
      if (form.value.password) payload.password = form.value.password
      await crud.update(editing.value.id, payload)
      toast.add({ severity: 'success', summary: 'User updated', life: 2500 })
    } else {
      payload.password = form.value.password
      await crud.create(payload)
      toast.add({ severity: 'success', summary: 'User created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete user "${row.name}"?`,
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  try {
    const { data } = await http.get('/roles')
    roleOptions.value = data.data ?? data
  } catch {
    roleOptions.value = []
  }
  try {
    const { data } = await http.get('/companies', { params: { per_page: 100 } })
    companyOptions.value = data.data ?? data
  } catch {
    companyOptions.value = []
  }
  try {
    const { data } = await http.get('/branches', { params: { per_page: 100 } })
    branchOptions.value = data.data ?? data
  } catch {
    branchOptions.value = []
  }
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Users</h1>
      <Button v-if="canCreate" label="New User" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search users..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="name" header="Name" />
      <Column field="email" header="Email" />
      <Column field="phone" header="Phone" />
      <Column header="Roles">
        <template #body="{ data }">
          <div class="role-tags">
            <Tag v-for="role in data.roles" :key="role" :value="role" severity="info" class="role-tag" />
          </div>
        </template>
      </Column>
      <Column header="Active">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No users found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit User' : 'New User'" :style="{ width: '540px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="form-field">
          <label>Email <span class="req">*</span></label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>
        <div class="form-field">
          <label>Phone</label>
          <InputText v-model="form.phone" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>
        <div class="form-field">
          <label>{{ editing ? 'New Password (leave blank to keep)' : 'Password' }} <span v-if="!editing" class="req">*</span></label>
          <Password v-model="form.password" :feedback="false" toggleMask :class="{ invalid: fieldError('password') }" />
          <small v-if="fieldError('password')" class="error-text">{{ fieldError('password') }}</small>
        </div>
        <div class="form-field full">
          <label>Roles</label>
          <MultiSelect v-model="form.roles" :options="roleOptions" optionLabel="name" optionValue="name"
            placeholder="Select roles" display="chip" :class="{ invalid: fieldError('roles') }" />
          <small v-if="fieldError('roles')" class="error-text">{{ fieldError('roles') }}</small>
        </div>
        <div class="form-field">
          <label>Companies</label>
          <MultiSelect v-model="form.company_ids" :options="companyOptions" optionLabel="name" optionValue="id"
            placeholder="All (super-admin only)" display="chip" :class="{ invalid: fieldError('company_ids') }" />
          <small v-if="fieldError('company_ids')" class="error-text">{{ fieldError('company_ids') }}</small>
        </div>
        <div class="form-field">
          <label>Branches</label>
          <MultiSelect v-model="form.branch_ids" :options="branchOptions" optionLabel="name" optionValue="id"
            placeholder="All (super-admin only)" display="chip" :class="{ invalid: fieldError('branch_ids') }" />
          <small v-if="fieldError('branch_ids')" class="error-text">{{ fieldError('branch_ids') }}</small>
        </div>
        <div class="form-field">
          <label>Active</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="user-active" />
            <label for="user-active">Enabled</label>
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
.role-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}
.role-tag :deep(.p-tag-label),
.role-tag {
  font-size: 0.72rem;
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
.form-field :deep(.p-password),
.form-field :deep(.p-multiselect) {
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
