<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

// Platform role names are fixed (the Level-1 hierarchy is a product decision);
// only their permission sets can be re-tuned, and only by a super-admin.
const canEdit = computed(() => auth.can('roles.edit'))

const roles = ref<any[]>([])
const rolesLoading = ref(false)
const modules = ref<any[]>([])

const selectedId = ref<number | null>(null)
const selectedPermissions = ref<string[]>([])
const saving = ref(false)

const selectedRole = computed(() => roles.value.find((r) => r.id === selectedId.value) ?? null)

function actionLabel(permission: string): string {
  return permission.slice(permission.lastIndexOf('.') + 1)
}

async function loadRoles() {
  rolesLoading.value = true
  try {
    const { data } = await centralHttp.get('/roles')
    roles.value = data.data ?? data
    if (!selectedId.value && roles.value.length) selectRole(roles.value[0])
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load roles', life: 4000 })
  } finally {
    rolesLoading.value = false
  }
}

async function loadPermissionModules() {
  try {
    const { data } = await centralHttp.get('/permissions')
    modules.value = data.modules ?? []
  } catch {
    modules.value = []
  }
}

function selectRole(role: any) {
  selectedId.value = role.id
  selectedPermissions.value = [...(role.permissions || [])]
}

function isModuleAllSelected(mod: any): boolean {
  return mod.permissions.every((p: string) => selectedPermissions.value.includes(p))
}

function toggleModule(mod: any, checked: boolean) {
  if (checked) {
    selectedPermissions.value = Array.from(new Set([...selectedPermissions.value, ...mod.permissions]))
  } else {
    selectedPermissions.value = selectedPermissions.value.filter((p) => !mod.permissions.includes(p))
  }
}

async function saveRole() {
  if (!selectedId.value) return
  saving.value = true
  try {
    await centralHttp.patch(`/roles/${selectedId.value}`, { permissions: selectedPermissions.value })
    toast.add({ severity: 'success', summary: 'Role saved', life: 2500 })
    await loadRoles()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
  } finally {
    saving.value = false
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
      <span v-if="!canEdit" class="readonly-note"><i class="pi pi-lock" /> Read-only — super-admin can edit</span>
    </div>

    <div class="panels">
      <!-- Left: role list -->
      <div class="panel role-list">
        <div
          v-for="role in roles"
          :key="role.id"
          class="role-item"
          :class="{ active: role.id === selectedId }"
          @click="selectRole(role)"
        >
          <div class="role-info">
            <div class="role-name">{{ role.name }}</div>
            <div class="role-sub">{{ role.permissions_count }} perms &middot; {{ role.operators_count }} operators</div>
          </div>
        </div>
        <div v-if="!rolesLoading && !roles.length" class="empty">No roles found.</div>
      </div>

      <!-- Right: permission matrix -->
      <div class="panel detail">
        <div v-if="!selectedId" class="empty">Select a role to view its permissions.</div>
        <template v-else>
          <div class="detail-head">
            <div>
              <h2 class="role-title">{{ selectedRole?.name }}</h2>
              <div class="role-meta">
                <Tag :value="`${selectedPermissions.length} permissions`" severity="secondary" />
                <Tag :value="`${selectedRole?.operators_count ?? 0} operators`" severity="secondary" />
              </div>
            </div>
            <Button v-if="canEdit" label="Save" icon="pi pi-check" :loading="saving" @click="saveRole" />
          </div>

          <div class="modules">
            <div v-for="mod in modules" :key="mod.module" class="module-card">
              <div class="module-head">
                <h3>{{ mod.module }}</h3>
                <div class="checkbox-row">
                  <Checkbox
                    :modelValue="isModuleAllSelected(mod)"
                    binary
                    :disabled="!canEdit"
                    :inputId="`all-${mod.module}`"
                    @update:modelValue="toggleModule(mod, $event as boolean)"
                  />
                  <label :for="`all-${mod.module}`">All</label>
                </div>
              </div>
              <div class="perm-grid">
                <div v-for="perm in mod.permissions" :key="perm" class="checkbox-row">
                  <Checkbox v-model="selectedPermissions" :value="perm" :inputId="perm" :disabled="!canEdit" />
                  <label :for="perm">{{ actionLabel(perm) }}</label>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.readonly-note { font-size: 0.8rem; color: #6b7280; display: inline-flex; align-items: center; gap: 0.35rem; }
.panels { display: grid; grid-template-columns: 260px 1fr; gap: 1rem; align-items: start; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.5rem; }
.role-list { display: flex; flex-direction: column; gap: 0.25rem; }
.role-item { padding: 0.6rem 0.75rem; border-radius: 6px; cursor: pointer; }
.role-item:hover { background: #f3f4f6; }
.role-item.active { background: #fdf7ec; }
.role-name { font-weight: 500; text-transform: capitalize; }
.role-sub { font-size: 0.78rem; color: #6b7280; }
.detail { padding: 1rem; }
.detail-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.role-title { font-size: 1.15rem; font-weight: 600; text-transform: capitalize; }
.role-meta { display: flex; gap: 0.35rem; margin-top: 0.4rem; }
.modules { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; margin-top: 0.5rem; }
.module-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.75rem; }
.module-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.6rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f3f4f6; }
.module-head h3 { font-size: 0.9rem; font-weight: 600; text-transform: capitalize; }
.module-head .checkbox-row label { font-size: 0.78rem; color: #6b7280; }
.perm-grid { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; }
.checkbox-row { display: flex; align-items: center; gap: 0.4rem; }
.checkbox-row label { font-size: 0.85rem; cursor: pointer; }
.empty { padding: 1.5rem; text-align: center; color: #6b7280; font-size: 0.9rem; }
</style>
