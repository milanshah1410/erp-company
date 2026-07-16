<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('roles.create'))
const canEdit = computed(() => auth.can('roles.edit'))
const canDelete = computed(() => auth.can('roles.delete'))

const SYSTEM_ROLES = ['super-admin', 'admin']

const roles = ref<any[]>([])
const rolesLoading = ref(false)
const modules = ref<any[]>([])

const selectedId = ref<number | null>(null)
const roleName = ref('')
const selectedPermissions = ref<string[]>([])
const detailLoading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const selectedRole = computed(() => roles.value.find((r: any) => r.id === selectedId.value) ?? null)
const isSystemRole = computed(() => !!selectedRole.value && SYSTEM_ROLES.includes(selectedRole.value.name))

// Group roles by department (org chart), preserving the server's ordering.
const groupedRoles = computed(() => {
  const groups: { department: string; roles: any[] }[] = []
  for (const role of roles.value) {
    const dept = role.department || 'Other'
    let group = groups.find((g) => g.department === dept)
    if (!group) {
      group = { department: dept, roles: [] }
      groups.push(group)
    }
    group.roles.push(role)
  }
  return groups
})

function actionLabel(permission: string): string {
  return permission.slice(permission.lastIndexOf('.') + 1)
}

async function loadRoles() {
  rolesLoading.value = true
  try {
    const { data } = await http.get('/roles')
    roles.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load roles', life: 4000 })
  } finally {
    rolesLoading.value = false
  }
}

async function loadPermissionModules() {
  try {
    const { data } = await http.get('/permissions')
    modules.value = data.modules ?? []
  } catch {
    modules.value = []
  }
}

async function selectRole(role: any) {
  selectedId.value = role.id
  detailLoading.value = true
  errors.value = {}
  try {
    const { data } = await http.get(`/roles/${role.id}`)
    roleName.value = data.data.name
    selectedPermissions.value = [...(data.data.permissions || [])]
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load role', life: 4000 })
  } finally {
    detailLoading.value = false
  }
}

function isModuleAllSelected(mod: any): boolean {
  return mod.permissions.every((p: string) => selectedPermissions.value.includes(p))
}

function toggleModule(mod: any, checked: boolean) {
  if (checked) {
    const set = new Set([...selectedPermissions.value, ...mod.permissions])
    selectedPermissions.value = Array.from(set)
  } else {
    selectedPermissions.value = selectedPermissions.value.filter((p) => !mod.permissions.includes(p))
  }
}

async function saveRole() {
  if (!selectedId.value) return
  saving.value = true
  errors.value = {}
  try {
    await http.put(`/roles/${selectedId.value}`, {
      name: roleName.value,
      permissions: selectedPermissions.value,
    })
    toast.add({ severity: 'success', summary: 'Role saved', life: 2500 })
    await loadRoles()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(role: any) {
  const res = await Swal.fire({
    title: `Delete role "${role.name}"?`,
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/roles/${role.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    if (selectedId.value === role.id) {
      selectedId.value = null
      roleName.value = ''
      selectedPermissions.value = []
    }
    await loadRoles()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

// --- New Role dialog ---
const createVisible = ref(false)
const createSaving = ref(false)
const newRoleName = ref('')
const createErrors = ref<Record<string, string[]>>({})

function openCreate() {
  newRoleName.value = ''
  createErrors.value = {}
  createVisible.value = true
}

async function createRole() {
  createSaving.value = true
  createErrors.value = {}
  try {
    const { data } = await http.post('/roles', { name: newRoleName.value, permissions: [] })
    toast.add({ severity: 'success', summary: 'Role created', life: 2500 })
    createVisible.value = false
    await loadRoles()
    const created = (data.data ?? data)
    const match = roles.value.find((r: any) => r.id === created?.id) ?? roles.value.find((r: any) => r.name === newRoleName.value)
    if (match) await selectRole(match)
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      createErrors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Create failed', life: 4000 })
    }
  } finally {
    createSaving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadRoles(), loadPermissionModules()])
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Roles &amp; Permissions</h1>
      <Button v-if="canCreate" label="New Role" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;margin-bottom:1rem">
      <TableExportButtons title="Roles" :rows="roles"
        :columns="[
          { key: 'name', label: 'Role' },
          { key: 'permissions_count', label: 'Permissions' },
          { key: 'users_count', label: 'Users' },
        ]" />
    </div>

    <div class="panels">
      <!-- Left: role list, grouped by department -->
      <div class="panel role-list">
        <template v-for="group in groupedRoles" :key="group.department">
          <div class="dept-label">{{ group.department }}</div>
          <div
            v-for="role in group.roles"
            :key="role.id"
            class="role-item"
            :class="{ active: role.id === selectedId }"
            @click="selectRole(role)"
          >
            <div class="role-info">
              <div class="role-name">
                {{ role.name }}
                <Tag v-if="SYSTEM_ROLES.includes(role.name)" value="system role" severity="secondary" class="system-tag" />
              </div>
              <div class="role-sub">{{ role.permissions_count }} perms &middot; {{ role.users_count }} users</div>
            </div>
            <Button
              v-if="canDelete"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              size="small"
              @click.stop="confirmDelete(role)"
            />
          </div>
        </template>
        <div v-if="!rolesLoading && !roles.length" class="empty">No roles found.</div>
      </div>

      <!-- Right: permission matrix -->
      <div class="panel detail">
        <div v-if="!selectedId" class="empty">Select a role to view and edit its permissions.</div>
        <div v-else-if="detailLoading" class="empty">Loading...</div>
        <template v-else>
          <div class="detail-head">
            <div class="name-field">
              <label>Role Name</label>
              <InputText v-model="roleName" :disabled="isSystemRole" :class="{ invalid: errors.name }" />
              <small v-if="errors.name" class="error-text">{{ errors.name[0] }}</small>
            </div>
            <Button v-if="canEdit" label="Save" icon="pi pi-check" :loading="saving" @click="saveRole" />
          </div>
          <small v-if="errors.permissions" class="error-text">{{ errors.permissions[0] }}</small>

          <div class="modules">
            <div v-for="mod in modules" :key="mod.module" class="module-card">
              <div class="module-head">
                <h3>{{ mod.module }}</h3>
                <div class="checkbox-row">
                  <Checkbox
                    :modelValue="isModuleAllSelected(mod)"
                    binary
                    :inputId="`all-${mod.module}`"
                    @update:modelValue="toggleModule(mod, $event as boolean)"
                  />
                  <label :for="`all-${mod.module}`">Select all</label>
                </div>
              </div>
              <div class="perm-grid">
                <div v-for="perm in mod.permissions" :key="perm" class="checkbox-row">
                  <Checkbox v-model="selectedPermissions" :value="perm" :inputId="perm" />
                  <label :for="perm">{{ actionLabel(perm) }}</label>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- New Role dialog -->
    <Dialog v-model:visible="createVisible" modal header="New Role" :style="{ width: '420px' }">
      <div class="name-field">
        <label>Name <span class="req">*</span></label>
        <InputText v-model="newRoleName" :class="{ invalid: createErrors.name }" @keyup.enter="createRole" />
        <small v-if="createErrors.name" class="error-text">{{ createErrors.name[0] }}</small>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="createVisible = false" />
        <Button label="Create" :loading="createSaving" @click="createRole" />
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
.panels {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 1rem;
  align-items: start;
}
.panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 0.5rem;
}
.role-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: calc(100vh - 220px);
  overflow-y: auto;
}
.dept-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #9ca3af;
  padding: 0.75rem 0.75rem 0.3rem;
}
.dept-label:first-child {
  padding-top: 0.25rem;
}
.role-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.6rem 0.75rem;
  border-radius: 6px;
  cursor: pointer;
}
.role-item:hover {
  background: #f3f4f6;
}
.role-item.active {
  background: #eef2ff;
}
.role-name {
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.system-tag :deep(.p-tag-label),
.system-tag {
  font-size: 0.68rem;
}
.role-sub {
  font-size: 0.78rem;
  color: #6b7280;
}
.detail {
  padding: 1rem;
}
.detail-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.name-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  max-width: 320px;
}
.name-field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.name-field :deep(.p-inputtext) {
  width: 100%;
}
.modules {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
  margin-top: 0.5rem;
}
.module-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 0.75rem;
}
.module-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.6rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid #f3f4f6;
}
.module-head h3 {
  font-size: 0.95rem;
  font-weight: 600;
  text-transform: capitalize;
}
.module-head .checkbox-row label {
  font-size: 0.78rem;
  color: #6b7280;
}
.perm-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.checkbox-row label {
  font-size: 0.85rem;
  cursor: pointer;
}
.empty {
  padding: 1.5rem;
  text-align: center;
  color: #6b7280;
  font-size: 0.9rem;
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
