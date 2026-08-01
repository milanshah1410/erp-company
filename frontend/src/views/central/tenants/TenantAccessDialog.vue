<script setup lang="ts">
/**
 * Per-tenant access control (/platform/tenants → Access).
 *
 * Two levels in one panel: the 16 module switches that gate whole slices of the
 * product, and the tenant's own 244-row menu tree for switching off a single
 * screen. Revoking either is a real entitlement — the tenant's role matrix stops
 * offering the permissions behind it and the API refuses the call — so the dialog
 * says so rather than implying it only hides the nav.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'

const props = defineProps<{ tenant: any | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const toast = useToast()

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

type MenuRow = {
  id: number
  parent_id: number | null
  label: string
  icon: string | null
  route: string | null
  permission: string | null
  module: string | null
  is_active: boolean
}

const modules = ref<any[]>([])
const modulesGated = ref(true)
const menu = ref<MenuRow[]>([])
const revokedGroups = ref<string[]>([])
const search = ref('')

// Working state: which module keys are on, and which menu ids are switched off.
const enabledModules = ref<string[]>([])
const disabledIds = ref<Set<number>>(new Set())

const planName = computed(() => props.tenant?.plan ?? null)

watch(
  () => props.tenant,
  (tenant) => {
    if (tenant) {
      visible.value = true
      void loadAccess(tenant.id)
    }
  },
  { immediate: true },
)

watch(visible, (open) => {
  if (!open) emit('close')
})

async function loadAccess(tenantId: number) {
  loading.value = true
  search.value = ''
  try {
    const { data } = await centralHttp.get(`/tenants/${tenantId}/access`)
    modules.value = data.modules
    modulesGated.value = data.modules_gated
    menu.value = data.menu
    revokedGroups.value = data.revoked_groups ?? []
    enabledModules.value = data.modules.filter((m: any) => m.enabled).map((m: any) => m.key)
    disabledIds.value = new Set(data.menu.filter((r: MenuRow) => !r.is_active).map((r: MenuRow) => r.id))
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load access', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

// The row owns the click; the switch/checkbox inside it is display-only (see the
// `.passive` rule), so a click is counted exactly once.
function toggleModule(key: string) {
  enabledModules.value = enabledModules.value.includes(key)
    ? enabledModules.value.filter((k) => k !== key)
    : [...enabledModules.value, key]
}

// --- menu tree ---
const children = computed(() => {
  const byParent = new Map<number | null, MenuRow[]>()
  for (const row of menu.value) {
    const list = byParent.get(row.parent_id) ?? []
    list.push(row)
    byParent.set(row.parent_id, list)
  }
  return byParent
})

function descendants(id: number): number[] {
  const out: number[] = []
  const walk = (parentId: number) => {
    for (const child of children.value.get(parentId) ?? []) {
      out.push(child.id)
      walk(child.id)
    }
  }
  walk(id)
  return out
}

const roots = computed(() => children.value.get(null) ?? [])

// A row is unreachable if it or any ancestor is switched off — the same rule the
// nav and the server-side revocation apply.
function isReachable(row: MenuRow): boolean {
  let cursor: MenuRow | undefined = row
  let guard = 0
  while (cursor && guard++ < 20) {
    if (disabledIds.value.has(cursor.id)) return false
    const parentId: number | null = cursor.parent_id
    cursor = parentId === null ? undefined : menu.value.find((r) => r.id === parentId)
  }
  return true
}

function ancestors(row: MenuRow): MenuRow[] {
  const out: MenuRow[] = []
  let cursor: MenuRow | undefined =
    row.parent_id === null ? undefined : menu.value.find((r) => r.id === row.parent_id)
  let guard = 0
  while (cursor && guard++ < 20) {
    out.push(cursor)
    const parentId: number | null = cursor.parent_id
    cursor = parentId === null ? undefined : menu.value.find((r) => r.id === parentId)
  }
  return out
}

/**
 * Is the tenant actually granted this screen?
 *
 * What the checkbox shows, because it is what the workspace gets: the row's own
 * switch on, every ancestor on, and its module enabled. A screen sitting under a
 * module that is switched off therefore reads unchecked — it used to show ticked
 * while being unreachable, which said the opposite of the truth.
 */
function isGranted(row: MenuRow): boolean {
  return isReachable(row) && !moduleOff(row)
}

// Ticking a row grants the screen outright: the row, the branch above it (a child
// is unreachable under a switched-off parent) and its module. Unticking revokes
// the row and everything beneath it, but leaves the module alone — one screen
// going away should not take a whole module with it.
function toggleGrant(row: MenuRow) {
  const next = new Set(disabledIds.value)

  if (isGranted(row)) {
    for (const id of [row.id, ...descendants(row.id)]) next.add(id)
  } else {
    for (const id of [row.id, ...ancestors(row).map((a) => a.id), ...descendants(row.id)]) {
      next.delete(id)
    }
    const key = moduleOf(row)
    if (key && !enabledModules.value.includes(key)) {
      enabledModules.value = [...enabledModules.value, key]
    }
  }

  disabledIds.value = next
}

// A module switched off hides its whole branch anyway; grey the rows out so the
// operator can see why, without the two settings fighting each other.
function moduleOff(row: MenuRow): boolean {
  const key = moduleOf(row)
  return key !== null && !enabledModules.value.includes(key)
}

function moduleOf(row: MenuRow): string | null {
  let cursor: MenuRow | undefined = row
  let guard = 0
  while (cursor && guard++ < 20) {
    if (cursor.module) return cursor.module
    const parentId: number | null = cursor.parent_id
    cursor = parentId === null ? undefined : menu.value.find((r) => r.id === parentId)
  }
  return null
}

const q = computed(() => search.value.trim().toLowerCase())

function matches(row: MenuRow): boolean {
  if (!q.value) return true
  const hay = `${row.label} ${row.route ?? ''} ${row.permission ?? ''}`.toLowerCase()
  if (hay.includes(q.value)) return true
  return descendants(row.id).some((id) => {
    const child = menu.value.find((r) => r.id === id)
    return child && `${child.label} ${child.route ?? ''} ${child.permission ?? ''}`.toLowerCase().includes(q.value)
  })
}

// Counts what the checkboxes show, module gating included, so the header and the
// tree can't disagree about how many screens this workspace has lost.
const revokedCount = computed(() => menu.value.filter((r) => !isGranted(r)).length)

async function save() {
  if (!props.tenant) return
  saving.value = true
  try {
    const { data } = await centralHttp.put(`/tenants/${props.tenant.id}/access`, {
      modules: enabledModules.value,
      disabled_menu_ids: [...disabledIds.value],
    })
    revokedGroups.value = data.revoked_groups ?? []
    modules.value = data.modules
    modulesGated.value = data.modules_gated
    toast.add({ severity: 'success', summary: 'Access updated', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Access — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '860px' }"
    :closable="!saving"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading access…</div>

    <template v-else>
      <p class="intro">
        <i class="pi pi-info-circle" />
        Switching something off here is an entitlement, not a cosmetic hide: it
        leaves the workspace's sidebar, disappears from its role permission matrix,
        and the API refuses the call. Role assignments are kept, so re-enabling
        restores exactly what the tenant had.
      </p>

      <!-- Modules -->
      <div class="section-head">
        <span class="section-name"><i class="pi pi-th-large" /> Modules</span>
        <span class="section-count">
          {{ enabledModules.length }} of {{ modules.length }} on
          <template v-if="planName"> &middot; plan: {{ planName }}</template>
        </span>
      </div>
      <p v-if="!modulesGated" class="note">
        This workspace never chose a module set, so nothing is gated today — saving
        this screen starts gating it at the selection below.
      </p>
      <!-- Rows are plain divs with one click handler, never a <label> wrapping the
           control: a label forwards the click to the input on top of the input's
           own handler, so the switch flipped twice and never appeared to move. -->
      <div class="modules">
        <div
          v-for="mod in modules"
          :key="mod.key"
          class="module"
          role="switch"
          tabindex="0"
          :aria-checked="enabledModules.includes(mod.key)"
          :title="mod.description"
          @click="toggleModule(mod.key)"
          @keydown.space.prevent="toggleModule(mod.key)"
          @keydown.enter.prevent="toggleModule(mod.key)"
        >
          <ToggleSwitch :modelValue="enabledModules.includes(mod.key)" :tabindex="-1" class="passive" />
          <span class="module-label">
            <i :class="mod.icon" />
            {{ mod.label }}
          </span>
          <!-- Held through a marketplace pack. Switching it off here only hides
               the menu — the entitlement returns on the next sync until the pack
               itself is revoked, so the tag is a pointer to the right lever. -->
          <Tag
            v-if="mod.granted_by_pack"
            value="pack"
            severity="info"
            class="plan-tag"
            title="Granted by a marketplace pack — revoke the pack under Marketplace → Pack Grants to remove it"
          />
          <!-- The plan is advisory here, not a lock: the operator may switch on a
               module the tenant never bought, and EnabledModules keeps it via the
               recorded grant. -->
          <Tag
            v-else-if="!mod.allowed_by_plan"
            value="off plan"
            severity="secondary"
            class="plan-tag"
            :title="`Not included in the ${planName ?? 'current'} plan`"
          />
        </div>
      </div>

      <!-- Menu tree -->
      <div class="section-head">
        <span class="section-name"><i class="pi pi-list" /> Menu</span>
        <span class="section-count">{{ revokedCount }} of {{ menu.length }} screens off</span>
        <InputText v-model="search" placeholder="Find a screen…" class="tree-search" />
      </div>

      <!-- Same rule as the module rows: the whole row is the click target, which
           also gives 244 rows a target bigger than a 14px box. -->
      <div class="tree">
        <template v-for="root in roots" :key="root.id">
          <div
            v-if="matches(root)"
            class="node depth-0"
            :class="{ dimmed: moduleOff(root) }"
            role="checkbox"
            tabindex="0"
            :aria-checked="isGranted(root)"
            @click="toggleGrant(root)"
            @keydown.space.prevent="toggleGrant(root)"
          >
            <Checkbox :modelValue="isGranted(root)" binary :tabindex="-1" class="passive" />
            <i v-if="root.icon" :class="root.icon" />
            <span class="node-label">{{ root.label }}</span>
            <Tag v-if="moduleOff(root)" value="module off" severity="secondary" class="node-tag" />
          </div>
          <template v-for="child in children.get(root.id) ?? []" :key="child.id">
            <div
              v-if="matches(child)"
              class="node depth-1"
              :class="{ dimmed: moduleOff(child) || !isReachable(child) }"
              role="checkbox"
              tabindex="0"
              :aria-checked="isGranted(child)"
              @click="toggleGrant(child)"
              @keydown.space.prevent="toggleGrant(child)"
            >
              <Checkbox :modelValue="isGranted(child)" binary :tabindex="-1" class="passive" />
              <i v-if="child.icon" :class="child.icon" />
              <span class="node-label">{{ child.label }}</span>
              <Tag v-if="moduleOff(child)" value="module off" severity="secondary" class="node-tag" />
              <code v-if="child.permission" class="perm">{{ child.permission }}</code>
            </div>
            <div
              v-for="grand in (children.get(child.id) ?? []).filter(matches)"
              :key="grand.id"
              class="node depth-2"
              :class="{ dimmed: moduleOff(grand) || !isReachable(grand) }"
              role="checkbox"
              tabindex="0"
              :aria-checked="isGranted(grand)"
              @click="toggleGrant(grand)"
              @keydown.space.prevent="toggleGrant(grand)"
            >
              <Checkbox :modelValue="isGranted(grand)" binary :tabindex="-1" class="passive" />
              <span class="node-label">{{ grand.label }}</span>
              <Tag v-if="moduleOff(grand)" value="module off" severity="secondary" class="node-tag" />
              <code v-if="grand.permission" class="perm">{{ grand.permission }}</code>
            </div>
          </template>
        </template>
      </div>

      <p v-if="revokedGroups.length" class="note revoked">
        <i class="pi pi-ban" />
        Last saved state revokes {{ revokedGroups.length }} permission
        group{{ revokedGroups.length === 1 ? '' : 's' }}:
        <b>{{ revokedGroups.join(', ') }}</b>
      </p>
    </template>

    <template #footer>
      <Button label="Close" text :disabled="saving" @click="visible = false" />
      <Button label="Save Access" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
    </template>
  </Dialog>
</template>

<style scoped>
.loading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem;
  justify-content: center;
  color: #6b7280;
}
.intro {
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
.section-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  margin-top: 1.2rem;
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
}
.section-name i {
  color: var(--safety-amber, #e8a33d);
}
.section-count {
  font-size: 0.72rem;
  color: var(--muted, #6f7680);
}
.tree-search {
  margin-left: auto;
  font-size: 0.8rem;
  padding: 0.25rem 0.5rem;
  width: 12rem;
}
.note {
  margin: 0.6rem 0 0;
  font-size: 0.78rem;
  color: var(--muted, #6b7280);
}
.note.revoked {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  flex-wrap: wrap;
  margin-top: 0.9rem;
  color: #b91c1c;
}
.modules {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.5rem 1rem;
  margin-top: 0.7rem;
}
.module {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.5rem;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.85rem;
  user-select: none;
}
.module:hover {
  background: #f3f4f6;
}
.module:focus-visible,
.node:focus-visible {
  outline: 2px solid var(--safety-amber, #e8a33d);
  outline-offset: 1px;
}
.module-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
/* The control is a display of state, not the input: the row's handler is the only
   thing that toggles, so a click on the switch itself can't fire twice. */
.passive {
  pointer-events: none;
}
.plan-tag :deep(.p-tag-label),
.plan-tag {
  margin-left: auto;
  font-size: 0.62rem;
}
.tree {
  margin-top: 0.6rem;
  max-height: 42vh;
  overflow-y: auto;
  border: 1px solid var(--border, #e8e4da);
  border-radius: 10px;
  padding: 0.4rem;
}
.node {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.28rem 0.4rem;
  border-radius: 6px;
  font-size: 0.85rem;
  cursor: pointer;
  user-select: none;
}
.node:hover {
  background: #f9fafb;
}
.node.dimmed {
  opacity: 0.5;
}
.depth-0 {
  font-weight: 600;
  margin-top: 0.35rem;
}
.depth-1 {
  padding-left: 1.6rem;
}
.depth-2 {
  padding-left: 3rem;
  font-size: 0.8rem;
}
.node-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.node-tag :deep(.p-tag-label),
.node-tag {
  font-size: 0.64rem;
}
.perm {
  margin-left: auto;
  font-size: 0.68rem;
  color: #9ca3af;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
</style>
