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
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('operators.create'))
const canEdit = computed(() => auth.can('operators.edit'))
const canDelete = computed(() => auth.can('operators.delete'))

const operators = ref<any[]>([])
const loading = ref(false)
const roleOptions = ref<any[]>([])

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({ name: '', email: '', password: '', is_active: true, roles: [] })
const errors = ref<Record<string, string[]>>({})

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/operators')
    operators.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load operators', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function loadRoles() {
  try {
    const { data } = await centralHttp.get('/roles')
    roleOptions.value = (data.data ?? data).map((r: any) => ({ name: r.name }))
  } catch {
    roleOptions.value = []
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', email: '', password: '', is_active: true, can_manage_tenant_access: false, roles: [] }
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    name: row.name,
    email: row.email,
    password: '',
    is_active: !!row.is_active,
    can_manage_tenant_access: !!row.can_manage_tenant_access,
    roles: [...(row.roles || [])],
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
      is_active: form.value.is_active,
      roles: form.value.roles,
    }
    // Only a super-admin may change this flag, and the API rejects an attempt —
    // so only send it when it is actually editable here.
    if (auth.isSuperAdmin) payload.can_manage_tenant_access = form.value.can_manage_tenant_access
    if (editing.value) {
      if (form.value.password) payload.password = form.value.password
      await centralHttp.put(`/operators/${editing.value.id}`, payload)
      toast.add({ severity: 'success', summary: 'Operator updated', life: 2500 })
    } else {
      payload.password = form.value.password
      await centralHttp.post('/operators', payload)
      toast.add({ severity: 'success', summary: 'Operator created', life: 2500 })
    }
    dialogVisible.value = false
    await load()
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
    title: `Delete operator "${row.name}"?`,
    text: 'This revokes all their platform access.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/operators/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  await Promise.all([load(), loadRoles()])
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Operators</h1>
      <Button v-if="canCreate" label="New Operator" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="operators" :loading="loading" dataKey="id" class="p-datatable-sm" paginator :rows="15" :rowsPerPageOptions="[15, 30, 50]">
      <Column field="name" header="Name" sortable />
      <Column field="email" header="Email" sortable />
      <Column header="Roles">
        <template #body="{ data }">
          <div class="role-tags">
            <Tag v-for="role in data.roles" :key="role" :value="role" severity="info" class="role-tag" />
            <span v-if="!data.roles?.length" class="muted">—</span>
          </div>
        </template>
      </Column>
      <Column header="Active" style="width: 6rem">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Tenant access" style="width: 8rem">
        <template #body="{ data }">
          <Tag
            v-if="data.can_manage_tenant_access || data.roles?.includes('super-admin')"
            severity="warn"
            value="can save"
            title="May save tenant module & menu access"
          />
          <span v-else class="muted">read-only</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No operators found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Operator' : 'New Operator'" :style="{ width: '480px' }">
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
        <div class="form-field full">
          <label>{{ editing ? 'New Password (leave blank to keep)' : 'Password' }} <span v-if="!editing" class="req">*</span></label>
          <Password v-model="form.password" :feedback="false" toggleMask :class="{ invalid: fieldError('password') }" />
          <small v-if="fieldError('password')" class="error-text">{{ fieldError('password') }}</small>
        </div>
        <div class="form-field full">
          <label>Roles</label>
          <MultiSelect v-model="form.roles" :options="roleOptions" optionLabel="name" optionValue="name"
            placeholder="Select platform roles" display="chip" filter :class="{ invalid: fieldError('roles') }" />
          <small v-if="fieldError('roles')" class="error-text">{{ fieldError('roles') }}</small>
          <small class="hint">Only a super-admin may grant the super-admin role.</small>
        </div>
        <div class="form-field">
          <label>Active</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="op-active" />
            <label for="op-active">Enabled</label>
          </div>
        </div>
        <div class="form-field">
          <label>Tenant access</label>
          <div class="checkbox-row">
            <Checkbox
              v-model="form.can_manage_tenant_access"
              binary
              inputId="op-tenant-access"
              :disabled="!auth.isSuperAdmin || form.roles.includes('super-admin')"
            />
            <label for="op-tenant-access">May save tenant module &amp; menu access</label>
          </div>
          <small class="hint">
            <template v-if="form.roles.includes('super-admin')">Super-admins always may.</template>
            <template v-else-if="!auth.isSuperAdmin">Only a super-admin may change this.</template>
            <template v-else>Off = can open a workspace's Access panel but not save it.</template>
          </small>
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
.role-tags { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.role-tag :deep(.p-tag-label), .role-tag { font-size: 0.72rem; }
.muted { color: #9ca3af; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-password), .form-field :deep(.p-multiselect) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
