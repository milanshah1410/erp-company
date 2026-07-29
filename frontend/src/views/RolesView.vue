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
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('roles.create'))
const canEdit = computed(() => auth.can('roles.edit'))
const canDelete = computed(() => auth.can('roles.delete'))

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

// System roles are the shared catalogue every workspace gets; they are read-only
// here because the rows are the same rows for every tenant. Custom roles belong
// to this workspace alone and are fully editable.
const isSystemRole = computed(() => !!selectedRole.value?.is_system)

function groupByDepartment(list: any[]): { department: string; roles: any[] }[] {
  const groups: { department: string; roles: any[] }[] = []
  for (const role of list) {
    const dept = role.department || 'Other'
    let group = groups.find((g) => g.department === dept)
    if (!group) {
      group = { department: dept, roles: [] }
      groups.push(group)
    }
    group.roles.push(role)
  }
  return groups
}

// Two sections; the server already orders system roles by org chart and puts the
// workspace's own roles last, so encounter order is the display order.
const systemGroups = computed(() => groupByDepartment(roles.value.filter((r: any) => r.is_system)))
const customRoles = computed(() => roles.value.filter((r: any) => !r.is_system))

function actionLabel(permission: string): string {
  return permission.slice(permission.lastIndexOf('.') + 1)
}

// The matrix is tenant-scoped: /permissions only returns the groups belonging to
// modules this tenant switched on (plus core groups, reported as "Core"). Section
// the cards by that module so the screen reads module → permissions.
const hiddenModules = ref<{ key: string; label: string }[]>([])

const moduleSections = computed(() => {
  const sections: { label: string; groups: any[] }[] = []
  for (const mod of modules.value) {
    const label = mod.module_label || 'Core'
    let section = sections.find((s) => s.label === label)
    if (!section) {
      section = { label, groups: [] }
      sections.push(section)
    }
    section.groups.push(mod)
  }
  // Core last — a tenant looks for its business modules first.
  return sections.sort((a, b) => Number(a.label === 'Core') - Number(b.label === 'Core'))
})

function isSectionAllSelected(section: { groups: any[] }): boolean {
  return section.groups.every((g) => g.permissions.every((p: string) => selectedPermissions.value.includes(p)))
}

function toggleSection(section: { groups: any[] }, checked: boolean) {
  const perms = section.groups.flatMap((g) => g.permissions as string[])
  if (checked) {
    selectedPermissions.value = [...new Set([...selectedPermissions.value, ...perms])]
  } else {
    selectedPermissions.value = selectedPermissions.value.filter((p) => !perms.includes(p))
  }
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
    hiddenModules.value = data.hidden_modules ?? []
  } catch {
    modules.value = []
    hiddenModules.value = []
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
  if (!selectedId.value || isSystemRole.value) return
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

// --- Duplicate dialog ---
// How a workspace tailors a system role: copy it here, edit the copy. The
// catalogue row stays as it is for every other workspace.
const dupVisible = ref(false)
const dupSaving = ref(false)
const dupSource = ref<any>(null)
const dupName = ref('')
const dupErrors = ref<Record<string, string[]>>({})

function openDuplicate(role: any) {
  dupSource.value = role
  dupName.value = `${role.name}-custom`
  dupErrors.value = {}
  dupVisible.value = true
}

async function duplicateRole() {
  if (!dupSource.value) return
  dupSaving.value = true
  dupErrors.value = {}
  try {
    const { data } = await http.post(`/roles/${dupSource.value.id}/duplicate`, { name: dupName.value })
    toast.add({ severity: 'success', summary: 'Custom role created', life: 2500 })
    dupVisible.value = false
    await loadRoles()
    const created = data.data ?? data
    const match = roles.value.find((r: any) => r.id === created?.id)
    if (match) await selectRole(match)
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      dupErrors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Duplicate failed', life: 4000 })
    }
  } finally {
    dupSaving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadRoles(), loadPermissionModules()])
})
</script>

<template>
  <div>
    <PageHeader title="Roles &amp; Permissions">
      <template #actions>
        <Button v-if="canCreate" label="New Custom Role" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="panels">
      <!-- Left: this workspace's own custom roles first, then the shared system
           catalogue grouped by department -->
      <div class="panel role-list">
        <div class="scope-label">
          <i class="pi pi-pencil" /> Custom Roles
          <span class="scope-hint">this workspace only</span>
        </div>
        <div
          v-for="role in customRoles"
          :key="role.id"
          class="role-item"
          :class="{ active: role.id === selectedId }"
          @click="selectRole(role)"
        >
          <div class="role-info">
            <div class="role-name">
              {{ role.name }}
              <Tag value="custom" severity="info" class="scope-tag" />
            </div>
            <!-- Both counts are scoped to this workspace: permissions within the
                 tenant's enabled modules, users assigned inside this tenant. -->
            <div class="role-sub">
              {{ role.permissions_count }} perms &middot;
              {{ role.users_count }} {{ role.users_count === 1 ? 'user' : 'users' }}
            </div>
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
        <div v-if="!rolesLoading && !customRoles.length" class="scope-empty">
          None yet — create one, or duplicate a system role below.
        </div>

        <div class="scope-label">
          <i class="pi pi-lock" /> System Roles
          <span class="scope-hint">shared catalogue</span>
        </div>
        <template v-for="group in systemGroups" :key="group.department">
          <div class="dept-label">{{ group.department }}</div>
          <div
            v-for="role in group.roles"
            :key="role.id"
            class="role-item"
            :class="{ active: role.id === selectedId }"
            @click="selectRole(role)"
          >
            <div class="role-info">
              <div class="role-name">{{ role.name }}</div>
              <div class="role-sub">
                {{ role.permissions_count }} perms &middot;
                {{ role.users_count }} {{ role.users_count === 1 ? 'user' : 'users' }}
              </div>
            </div>
            <Button
              v-if="canCreate"
              icon="pi pi-copy"
              text
              rounded
              size="small"
              title="Duplicate as custom role"
              @click.stop="openDuplicate(role)"
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
            <Tag :value="isSystemRole ? 'system role' : 'custom role'" :severity="isSystemRole ? 'secondary' : 'info'" />
            <Button
              v-if="isSystemRole && canCreate"
              label="Duplicate as custom role"
              icon="pi pi-copy"
              outlined
              @click="openDuplicate(selectedRole)"
            />
            <Button v-if="!isSystemRole && canEdit" label="Save" icon="pi pi-check" :loading="saving" @click="saveRole" />
          </div>

          <p v-if="isSystemRole" class="system-note">
            <i class="pi pi-lock" />
            This role is part of the shared system catalogue, so its permissions
            are the same in every workspace and cannot be changed here. Duplicate
            it to get an editable copy that belongs to this workspace only.
          </p>

          <small v-if="errors.permissions" class="error-text">{{ errors.permissions[0] }}</small>

          <!-- Permissions are grouped module → resource, and only this tenant's
               modules are listed (plus the always-on Core group). -->
          <div v-for="section in moduleSections" :key="section.label" class="perm-section">
            <div class="section-head">
              <span class="section-name">
                <i :class="section.label === 'Core' ? 'pi pi-shield' : 'pi pi-th-large'" />
                {{ section.label }}
              </span>
              <span class="section-count">{{ section.groups.length }} groups</span>
              <div class="checkbox-row section-all">
                <Checkbox
                  :modelValue="isSectionAllSelected(section)"
                  binary
                  :disabled="isSystemRole"
                  :inputId="`sec-${section.label}`"
                  @update:modelValue="toggleSection(section, $event as boolean)"
                />
                <label :for="`sec-${section.label}`">Select all</label>
              </div>
            </div>

            <div class="modules">
              <div v-for="mod in section.groups" :key="mod.module" class="module-card">
                <div class="module-head">
                  <h3>{{ mod.module }}</h3>
                  <div class="checkbox-row">
                    <Checkbox
                      :modelValue="isModuleAllSelected(mod)"
                      binary
                      :disabled="isSystemRole"
                      :inputId="`all-${mod.module}`"
                      @update:modelValue="toggleModule(mod, $event as boolean)"
                    />
                    <label :for="`all-${mod.module}`">Select all</label>
                  </div>
                </div>
                <div class="perm-grid">
                  <div v-for="perm in mod.permissions" :key="perm" class="checkbox-row">
                    <Checkbox v-model="selectedPermissions" :value="perm" :disabled="isSystemRole" :inputId="perm" />
                    <label :for="perm">{{ actionLabel(perm) }}</label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <p v-if="hiddenModules.length" class="hidden-note">
            <i class="pi pi-lock" />
            {{ hiddenModules.length }} module{{ hiddenModules.length === 1 ? '' : 's' }} not enabled for this
            workspace — their permissions are hidden:
            <b>{{ hiddenModules.map((m) => m.label).join(', ') }}</b>
          </p>
        </template>
      </div>
    </div>

    <!-- Duplicate dialog: system role -> editable copy owned by this workspace -->
    <Dialog v-model:visible="dupVisible" modal header="Duplicate Role" :style="{ width: '440px' }">
      <p class="dialog-note">
        Copies <b>{{ dupSource?.name }}</b> and all of its permissions into a new
        custom role for this workspace. The system role is left unchanged.
      </p>
      <div class="name-field">
        <label>New role name <span class="req">*</span></label>
        <InputText v-model="dupName" :class="{ invalid: dupErrors.name }" @keyup.enter="duplicateRole" />
        <small v-if="dupErrors.name" class="error-text">{{ dupErrors.name[0] }}</small>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dupVisible = false" />
        <Button label="Duplicate" :loading="dupSaving" @click="duplicateRole" />
      </template>
    </Dialog>

    <!-- New Role dialog -->
    <Dialog v-model:visible="createVisible" modal header="New Custom Role" :style="{ width: '420px' }">
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
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.5rem;
}
.role-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: calc(100vh - 220px);
  overflow-y: auto;
}
/* Top-level split: shared system catalogue vs this workspace's own roles. */
.scope-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.6rem;
  padding: 0.45rem 0.7rem;
  border-radius: 8px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
  font-size: 0.74rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--graphite, #1e2328);
}
.scope-label:first-child {
  margin-top: 0;
}
.scope-label i {
  color: var(--safety-amber, #e8a33d);
  font-size: 0.8rem;
}
.scope-hint {
  margin-left: auto;
  font-size: 0.62rem;
  font-weight: 500;
  text-transform: none;
  letter-spacing: 0;
  color: var(--muted, #6f7680);
}
.scope-tag :deep(.p-tag-label),
.scope-tag {
  font-size: 0.68rem;
}
.scope-empty {
  padding: 0.6rem 0.75rem;
  font-size: 0.78rem;
  color: var(--muted, #6b7280);
}
.system-note {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 1rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.dialog-note {
  margin: 0 0 0.9rem;
  font-size: 0.82rem;
  line-height: 1.45;
  color: var(--muted, #6b7280);
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
/* One section per module (Sales, Inventory, …) with its resource groups inside. */
.perm-section {
  margin-top: 1.2rem;
}
.section-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  padding: 0.45rem 0.7rem;
  border-radius: 10px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
.section-name {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--graphite, #1e2328);
  letter-spacing: 0.01em;
}
.section-name i {
  color: var(--safety-amber, #e8a33d);
  font-size: 0.85rem;
}
.section-count {
  font-size: 0.72rem;
  color: var(--muted, #6f7680);
}
.section-all {
  margin-left: auto;
}
.section-all label {
  font-size: 0.76rem;
  color: var(--muted, #6f7680);
}
.hidden-note {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  margin: 1.4rem 0 0;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.78rem;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.modules {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
  margin-top: 0.5rem;
}
.module-card {
  border: 1px solid var(--border, #e8e4da);
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
