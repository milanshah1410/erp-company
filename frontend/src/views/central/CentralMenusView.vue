<script setup lang="ts">
/**
 * Menu Catalogue (/platform/menus) — every screen the product ships, listed once
 * and switchable at the source.
 *
 * This is the *shared* catalogue (menu_items with no tenant), the one each new
 * workspace is cloned from at provisioning. Its two tenant-scoped cousins live on
 * the Tenants screen and answer different questions:
 *
 *   Tenants → Access       — may this workspace use this screen?
 *   Tenants → Menu Builder — where does it sit in this workspace's own tree?
 *
 * The reach caveat is stated on the page rather than buried, because it is the
 * first thing an operator gets wrong here: a workspace that owns a cloned menu
 * reads its own copy, so switching a row off only reaches the next signup unless
 * "apply to existing workspaces" is on. The "off in N" column is the same fact
 * from the other side — what the workspaces already have.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('menus.edit'))

type MenuRow = {
  id: number
  parent_id: number | null
  depth: number
  section: string
  label: string
  icon: string | null
  route: string | null
  permission: string | null
  feature: string | null
  module: string | null
  sort_order: number
  is_active: boolean
  is_group: boolean
  children_count: number
  /** Workspaces holding their own copy of this row, and how many have it off. */
  tenants_with_row: number
  tenants_off: number
}

type Stats = {
  total: number
  active: number
  groups: number
  screens: number
  tenants_total: number
  tenants_with_own_menu: number
}

const rows = ref<MenuRow[]>([])
const modules = ref<{ key: string; label: string }[]>([])
const sections = ref<string[]>([])
const stats = ref<Stats | null>(null)
const loading = ref(false)
const selected = ref<MenuRow[]>([])

/**
 * Whether a switch is pushed down into workspaces that already own a copy.
 *
 * A single sticky control rather than a prompt per toggle: an operator retiring a
 * screen makes this decision once for the sitting, and asking on every flip would
 * train them to click through it. Off on load — retro-actively removing a screen
 * from a live workspace should never be the default.
 */
const applyToTenants = ref(false)

const search = ref('')
const moduleFilter = ref<string | null>(null)
const sectionFilter = ref<string | null>(null)
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Active only', value: 'active' },
  { label: 'Inactive only', value: 'inactive' },
]

const moduleOptions = computed(() => modules.value.map((m) => ({ label: m.label, value: m.key })))
const sectionOptions = computed(() => sections.value.map((s) => ({ label: s, value: s })))

const moduleLabels = computed(() => {
  const map = new Map<string, string>()
  for (const m of modules.value) map.set(m.key, m.label)
  return map
})

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()

  return rows.value.filter((r) => {
    if (statusFilter.value === 'active' && !r.is_active) return false
    if (statusFilter.value === 'inactive' && r.is_active) return false
    if (moduleFilter.value && r.module !== moduleFilter.value) return false
    if (sectionFilter.value && r.section !== sectionFilter.value) return false
    if (!q) return true
    return (
      r.label.toLowerCase().includes(q) ||
      (r.route ?? '').toLowerCase().includes(q) ||
      (r.permission ?? '').toLowerCase().includes(q)
    )
  })
})

/** Filtering flattens the hierarchy, so indentation is only honest unfiltered. */
const isFiltered = computed(
  () => !!search.value.trim() || !!moduleFilter.value || !!sectionFilter.value || statusFilter.value !== 'all',
)

const inactiveCount = computed(() => (stats.value ? stats.value.total - stats.value.active : 0))

function indent(row: MenuRow): Record<string, string> {
  return { paddingLeft: `${(isFiltered.value ? 0 : row.depth) * 1.25}rem` }
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/menus')
    rows.value = data.data
    modules.value = data.modules ?? []
    sections.value = data.sections ?? []
    stats.value = data.stats
    selected.value = []
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Could not load the menu catalogue',
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    loading.value = false
  }
}

async function toggle(row: MenuRow, value: boolean) {
  // Optimistic: the switch has already moved under the operator's finger, so
  // reverting on failure reads better than a switch that lags every click.
  const previous = row.is_active
  row.is_active = value

  try {
    const { data } = await centralHttp.post(`/menus/${row.id}/toggle`, {
      is_active: value,
      apply_to_tenants: applyToTenants.value,
    })

    if (stats.value) stats.value.active += value ? 1 : -1

    toast.add({
      severity: 'success',
      summary: value ? `${row.label} activated` : `${row.label} switched off`,
      detail: data.tenants_applied
        ? `Applied to ${data.tenants_applied} workspace row(s).`
        : applyToTenants.value
          ? 'No workspace copies needed changing.'
          : 'Applies to new workspaces — existing ones keep their own menu.',
      life: 3500,
    })

    // The pushed-down state changes the "off in N" column, so re-read rather
    // than guess at it.
    if (data.tenants_applied) await load()
  } catch (e: any) {
    row.is_active = previous
    toast.add({
      severity: 'error',
      summary: 'Not changed',
      detail: e.response?.data?.message || 'Could not change it',
      life: 4000,
    })
  }
}

async function bulk(value: boolean) {
  const picked = selected.value
  if (!picked.length) return

  const groups = picked.filter((r) => r.is_group)
  const res = await Swal.fire({
    title: `${value ? 'Activate' : 'Switch off'} ${picked.length} menu item(s)?`,
    html: [
      groups.length
        ? `<p>${groups.length} of them are sections — switching a section off hides every screen under it.</p>`
        : '',
      applyToTenants.value
        ? '<p><strong>This will also be applied to workspaces that already have their own menu.</strong></p>'
        : '<p>Existing workspaces keep their own menu; this changes what new ones start with.</p>',
    ].join(''),
    icon: value ? 'question' : 'warning',
    showCancelButton: true,
    confirmButtonColor: value ? '#16a34a' : '#dc2626',
    confirmButtonText: value ? 'Activate' : 'Switch off',
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await centralHttp.post('/menus/bulk', {
      ids: picked.map((r) => r.id),
      is_active: value,
      apply_to_tenants: applyToTenants.value,
    })
    toast.add({
      severity: 'success',
      summary: `${data.updated} item(s) ${value ? 'activated' : 'switched off'}`,
      detail: data.tenants_applied ? `Applied to ${data.tenants_applied} workspace row(s).` : undefined,
      life: 3500,
    })
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Not changed',
      detail: e.response?.data?.message || 'Bulk update failed',
      life: 4000,
    })
  }
}

function clearFilters() {
  search.value = ''
  moduleFilter.value = null
  sectionFilter.value = null
  statusFilter.value = 'all'
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Menu Catalogue</h1>
      <Button label="Refresh" icon="pi pi-refresh" text :loading="loading" @click="load" />
    </div>

    <p class="intro">
      <i class="pi pi-info-circle" />
      Every screen the product ships, in the order the sidebar renders it. Switching a row
      off here removes it from what a <strong>new</strong> workspace is created with.
      Workspaces that already have their own menu keep it — turn on
      <em>apply to existing workspaces</em> below to push a change down to them as well, or
      use Tenants → Menu Builder to change one workspace on its own.
    </p>

    <div v-if="stats" class="stats">
      <div class="stat">
        <span class="stat-value">{{ stats.total }}</span>
        <span class="stat-label">catalogue rows</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ stats.screens }}</span>
        <span class="stat-label">screens</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ stats.groups }}</span>
        <span class="stat-label">sections</span>
      </div>
      <div class="stat">
        <span class="stat-value" :class="{ warn: inactiveCount > 0 }">{{ inactiveCount }}</span>
        <span class="stat-label">switched off</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ stats.tenants_with_own_menu }}/{{ stats.tenants_total }}</span>
        <span class="stat-label">workspaces with their own menu</span>
      </div>
    </div>

    <div class="toolbar">
      <span class="search">
        <InputText v-model="search" placeholder="Search label, route or permission" />
      </span>
      <Select
        v-model="sectionFilter"
        :options="sectionOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="All sections"
        showClear
        class="filter"
      />
      <Select
        v-model="moduleFilter"
        :options="moduleOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="All modules"
        showClear
        filter
        class="filter"
      />
      <Select
        v-model="statusFilter"
        :options="statusOptions"
        optionLabel="label"
        optionValue="value"
        class="filter narrow"
      />
      <Button v-if="isFiltered" label="Clear" icon="pi pi-filter-slash" text @click="clearFilters" />

      <div class="spacer" />

      <div v-if="canEdit" class="apply-switch" :class="{ armed: applyToTenants }">
        <ToggleSwitch v-model="applyToTenants" inputId="apply-tenants" />
        <label for="apply-tenants">
          Apply to existing workspaces
          <small>Pushes each change into workspaces that already have their own menu</small>
        </label>
      </div>
    </div>

    <div v-if="canEdit && selected.length" class="bulkbar">
      <span>{{ selected.length }} selected</span>
      <Button label="Activate" icon="pi pi-check" size="small" severity="success" @click="bulk(true)" />
      <Button label="Switch off" icon="pi pi-ban" size="small" severity="danger" outlined @click="bulk(false)" />
      <Button label="Clear selection" text size="small" @click="selected = []" />
    </div>

    <DataTable
      v-model:selection="selected"
      :value="filtered"
      :loading="loading"
      dataKey="id"
      class="p-datatable-sm"
    >
      <Column v-if="canEdit" selectionMode="multiple" style="width: 3rem" />

      <!-- Label, icon and (when filtering has flattened the tree) the section it
           came from. The indent lives on the whole cell, not just the label row,
           so the crumb underneath stays aligned with the name above it. -->
      <Column style="min-width: 20rem">
        <!-- Offset in the header content, not as th padding: it has to add to
             whatever horizontal padding the theme gives the cell, not replace it. -->
        <template #header><span class="th-offset">Menu item</span></template>
        <template #body="{ data }">
          <div class="name-cell" :style="indent(data)">
            <span class="node-icon" :class="{ group: data.is_group, off: !data.is_active }">
              <i :class="data.icon || (data.is_group ? 'pi pi-folder' : 'pi pi-circle-fill')" />
            </span>
            <span class="name-text">
              <span class="name-line">
                <span class="name-label" :class="{ off: !data.is_active }">{{ data.label }}</span>
                <Tag
                  v-if="data.is_group"
                  :value="`${data.children_count}`"
                  severity="secondary"
                  class="mini count"
                  :title="`${data.children_count} item(s) under this section`"
                />
              </span>
              <small v-if="isFiltered && data.section !== data.label" class="crumb">
                <i class="pi pi-angle-right" />{{ data.section }}
              </small>
            </span>
          </div>
        </template>
      </Column>

      <Column header="Route" style="width: 16rem">
        <template #body="{ data }">
          <code v-if="data.route" class="mono route" :title="data.route">{{ data.route }}</code>
          <span v-else class="badge-section">section</span>
        </template>
      </Column>

      <Column header="Permission" style="width: 15rem">
        <template #body="{ data }">
          <code v-if="data.permission" class="mono perm" :title="data.permission">{{ data.permission }}</code>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column header="Module" style="width: 11rem">
        <template #body="{ data }">
          <span v-if="data.module" class="chip">{{ moduleLabels.get(data.module) || data.module }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <!-- What the workspaces already holding a copy of this row have done with
           it — the honest counterweight to the switch on the right. -->
      <Column header="In workspaces" style="width: 10rem" headerClass="ta-center" bodyClass="ta-center">
        <template #body="{ data }">
          <span v-if="!data.tenants_with_row" class="muted">—</span>
          <span
            v-else
            class="ratio"
            :class="{ warn: data.tenants_off > 0 }"
            :title="`${data.tenants_off} workspace(s) have this switched off`"
          >
            {{ data.tenants_with_row - data.tenants_off }}<span class="of">/{{ data.tenants_with_row }}</span>
          </span>
        </template>
      </Column>

      <Column header="Active" style="width: 6.5rem" headerClass="ta-center" bodyClass="ta-center">
        <template #body="{ data }">
          <ToggleSwitch
            :modelValue="data.is_active"
            :disabled="!canEdit"
            :title="
              data.is_active
                ? 'Part of the catalogue new workspaces are created with'
                : 'Excluded from new workspaces'
            "
            @update:modelValue="(v: boolean) => toggle(data, v)"
          />
        </template>
      </Column>

      <template #empty>
        {{ isFiltered ? 'No menu items match these filters.' : 'The menu catalogue is empty.' }}
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.35rem;
}
h1 {
  margin: 0;
  font-size: 1.4rem;
}
.intro {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0 0 1rem;
  color: #6b7280;
  font-size: 0.85rem;
  line-height: 1.5;
  max-width: 68rem;
}
.intro i {
  margin-top: 0.15rem;
}
.stats {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-bottom: 1rem;
}
.stat {
  display: flex;
  flex-direction: column;
  min-width: 8rem;
  padding: 0.5rem 0.8rem;
  border-radius: 8px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
.stat-value {
  font-size: 1.15rem;
  font-weight: 700;
}
.stat-label {
  font-size: 0.72rem;
  color: #6b7280;
}
.warn {
  color: #b45309;
  font-weight: 600;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.75rem;
}
.spacer {
  flex: 1 1 auto;
}
.search :deep(.p-inputtext) {
  min-width: 18rem;
}
.filter {
  min-width: 11rem;
}
.filter.narrow {
  min-width: 8.5rem;
}
/* The one control on this page that can change a live customer's navigation, so
   it is visibly armed when it is on rather than sitting quietly in a toolbar. */
.apply-switch {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.4rem 0.7rem;
  border-radius: 8px;
  border: 1px dashed var(--border, #e3ded3);
}
.apply-switch.armed {
  border-style: solid;
  border-color: #b45309;
  background: rgba(180, 83, 9, 0.06);
}
.apply-switch label {
  display: flex;
  flex-direction: column;
  font-size: 0.82rem;
  cursor: pointer;
}
.apply-switch small {
  color: #6b7280;
  font-size: 0.7rem;
}
.bulkbar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.6rem;
  padding: 0.45rem 0.7rem;
  border-radius: 8px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
  font-size: 0.85rem;
}
/* ---------- Table chrome ----------
   The table has no scroll box of its own — all 300-odd rows render and the page
   scrolls with the rest of the screen.

   The header row is deliberately NOT viewport-sticky. Pinning it needs a hard
   offset for the layout's sticky topbar, and any drift in that number leaves the
   strip floating across the middle of the table, over the rows it is supposed to
   label. It is styled as a quiet label strip instead: at this row count the
   header is a ruler, and a ruler should not compete with the names it measures. */
:deep(.p-datatable-thead > tr > th) {
  padding-block: 0.65rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #6b7280;
  background: var(--surface-2, #f2f0ea);
  border-bottom: 2px solid var(--border, #e3ded3);
  white-space: nowrap;
}
/* Square the header into the card it sits in, so the strip reads as the top of
   the table rather than a band laid across it. */
:deep(.p-datatable-thead > tr > th:first-child) {
  border-top-left-radius: 8px;
}
:deep(.p-datatable-thead > tr > th:last-child) {
  border-top-right-radius: 8px;
}
:deep(.p-datatable-tbody > tr > td) {
  vertical-align: middle;
  padding-block: 0.45rem;
}
:deep(.ta-center) {
  text-align: center;
}
/* Top-level labels sit past the icon tile (1.35rem) and its gap (0.5rem); shift
   the header text by the same amount so "Menu item" starts over "Dashboard"
   rather than over the icons. */
.th-offset {
  padding-left: 1.85rem;
}
/* Centred header cells lay their sort/label content out with flex, so the text
   alignment above needs the flex axis moved too. */
:deep(th.ta-center .p-datatable-column-header-content) {
  justify-content: center;
}

/* ---------- Menu item cell ---------- */
.name-cell {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  /* Indent comes from :style; the transition keeps the jump to flat order
     (when a filter is applied) from being jarring. */
  transition: padding-left 0.12s ease;
}
.node-icon {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 1.35rem;
  height: 1.35rem;
  margin-top: 0.05rem;
  border-radius: 6px;
  background: transparent;
  color: #9ca3af;
  font-size: 0.7rem;
}
/* A section reads as a container, a screen as a point in it — one glance should
   separate the two even when the indent is deep. */
.node-icon.group {
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 16%, transparent);
  color: #b45309;
  font-size: 0.78rem;
}
.node-icon.off {
  opacity: 0.45;
}
.name-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 0.1rem;
}
.name-line {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}
.name-label {
  font-weight: 500;
  line-height: 1.35;
}
.name-label.off {
  color: #9ca3af;
  text-decoration: line-through;
}
.crumb {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  font-size: 0.7rem;
  color: #9ca3af;
}
.crumb i {
  font-size: 0.6rem;
}
.mini {
  font-size: 0.68rem;
}
.mini.count {
  min-width: 1.4rem;
  padding-inline: 0.35rem;
  text-align: center;
}
.muted {
  color: #9ca3af;
}

/* ---------- Route / permission ----------
   Both are identifiers, not prose: same monospace pill, same left edge, clipped
   to one line so a long permission name cannot push the column open and knock
   every row out of alignment. The full value is in the title attribute. */
.mono {
  display: inline-block;
  max-width: 100%;
  padding: 0.1rem 0.4rem;
  border-radius: 5px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.73rem;
  line-height: 1.5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: middle;
}
.mono.route {
  color: #1f2937;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
.mono.perm {
  color: #6b7280;
  background: transparent;
  border: 1px solid transparent;
  padding-inline: 0;
}
/* A group has no route, and "—" would read as "route missing" rather than
   "this row is not a link". */
.badge-section {
  display: inline-block;
  padding: 0.08rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: #9ca3af;
  border: 1px dashed var(--border, #e3ded3);
}

/* ---------- Counts ---------- */
.ratio {
  font-variant-numeric: tabular-nums;
  font-size: 0.82rem;
  font-weight: 600;
}
.ratio .of {
  font-weight: 400;
  color: #9ca3af;
}
.chip {
  display: inline-block;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.72rem;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
@media (max-width: 900px) {
  .apply-switch {
    width: 100%;
  }
  .search :deep(.p-inputtext) {
    min-width: 100%;
  }
}
</style>
