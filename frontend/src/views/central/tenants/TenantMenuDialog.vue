<script setup lang="ts">
/**
 * Per-tenant menu builder (/platform/tenants → Menu Builder).
 *
 * The Access panel next door decides *whether* a workspace may use a screen; this
 * decides where the screen sits. The operator drags rows to reorder them, drops a
 * row onto a group to nest it (to any depth the sidebar cascades), renames
 * anything, invents new groups and points new rows at real screens.
 *
 * Drag & drop is native HTML5 — no library. Each row exposes three drop zones:
 * the top and bottom edges reorder as a sibling, the middle nests as a child. The
 * zone under the pointer is drawn as you drag, so a nest never happens by
 * surprise.
 *
 * Two rules the tenant-side nav depends on are enforced here as well as on the
 * server, because the UI is where they can be explained:
 *   - a row with a screen link cannot hold children (the nav renders a linked row
 *     as a leaf and would drop them), so nesting into one asks first and clears
 *     the link;
 *   - a screen link and its permission come from the catalogue, never free text,
 *     so the tree can't grow an entry the SPA router refuses to open.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'

const props = defineProps<{ tenant: any | null; readonly?: boolean }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const toast = useToast()

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

type BuilderNode = {
  /** Stable client-side key. Survives re-parenting; new rows have no server id. */
  uid: string
  id: number | null
  label: string
  /** Empty string means "no icon" — a plain InputText can't hold null. */
  icon: string
  route: string | null
  permission: string | null
  module: string | null
  is_active: boolean
  children: BuilderNode[]
}

type Screen = {
  label: string
  icon: string | null
  route: string
  permission: string | null
  module: string | null
  section: string
  in_menu: boolean
}

const tree = ref<BuilderNode[]>([])
const screens = ref<Screen[]>([])
const permissions = ref<string[]>([])
const modules = ref<{ key: string; label: string }[]>([])
const maxDepth = ref(5)

const expanded = ref<Set<string>>(new Set())
const search = ref('')
const editingUid = ref<string | null>(null)

/** The saved tree, to tell an edited panel from an untouched one. */
const baseline = ref('')

let uidSeq = 0
function nextUid(): string {
  return `n${++uidSeq}`
}

watch(
  () => props.tenant,
  (tenant) => {
    if (tenant) {
      visible.value = true
      void load(tenant.id)
    }
  },
  { immediate: true },
)

watch(visible, (open) => {
  if (!open) emit('close')
})

function toBuilder(nodes: any[]): BuilderNode[] {
  return (nodes ?? []).map((n: any) => ({
    uid: nextUid(),
    id: n.id ?? null,
    label: n.label ?? '',
    icon: n.icon ?? '',
    route: n.route ?? null,
    permission: n.permission ?? null,
    module: n.module ?? null,
    is_active: n.is_active !== false,
    children: toBuilder(n.children ?? []),
  }))
}

/** The payload shape: the tree minus the client-only uid. */
function serialise(nodes: BuilderNode[]): any[] {
  return nodes.map((n) => ({
    id: n.id,
    label: n.label,
    icon: n.icon || null,
    route: n.route,
    permission: n.permission,
    module: n.module,
    is_active: n.is_active,
    children: serialise(n.children),
  }))
}

function applyPayload(data: any) {
  tree.value = toBuilder(data.tree)
  screens.value = data.screens ?? []
  permissions.value = data.permissions ?? []
  modules.value = data.modules ?? []
  maxDepth.value = data.max_depth ?? 5
  baseline.value = JSON.stringify(serialise(tree.value))
  // Top level open, everything below folded: 240-odd rows expanded at once is
  // not a tree anyone can drag around.
  expanded.value = new Set(tree.value.filter((n) => n.children.length).map((n) => n.uid))
  editingUid.value = null
}

async function load(tenantId: number) {
  loading.value = true
  search.value = ''
  try {
    const { data } = await centralHttp.get(`/tenants/${tenantId}/menu`)
    applyPayload(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the menu', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

const dirty = computed(() => baseline.value !== '' && JSON.stringify(serialise(tree.value)) !== baseline.value)

// --- tree helpers ---

type Located = { node: BuilderNode; siblings: BuilderNode[]; index: number }

function locate(uid: string, nodes: BuilderNode[] = tree.value): Located | null {
  for (let i = 0; i < nodes.length; i++) {
    if (nodes[i].uid === uid) return { node: nodes[i], siblings: nodes, index: i }
    const hit = locate(uid, nodes[i].children)
    if (hit) return hit
  }
  return null
}

/** Depth of every node, 0 for a top-level row. */
const depths = computed<Map<string, number>>(() => {
  const map = new Map<string, number>()
  const walk = (nodes: BuilderNode[], depth: number) => {
    for (const n of nodes) {
      map.set(n.uid, depth)
      walk(n.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return map
})

/** How many levels the node occupies, itself included. */
function height(node: BuilderNode): number {
  return node.children.length ? 1 + Math.max(...node.children.map(height)) : 1
}

function contains(node: BuilderNode, uid: string): boolean {
  return node.children.some((c) => c.uid === uid || contains(c, uid))
}

function isGroup(node: BuilderNode): boolean {
  return node.route === null
}

// --- search ---

const q = computed(() => search.value.trim().toLowerCase())
const filtering = computed(() => q.value.length > 0)

/** Rows matching the search, plus every ancestor, so a hit keeps its context. */
const visibleUids = computed<Set<string>>(() => {
  const keep = new Set<string>()
  if (!filtering.value) return keep

  const walk = (nodes: BuilderNode[], trail: string[]): void => {
    for (const n of nodes) {
      const hay = `${n.label} ${n.route ?? ''} ${n.permission ?? ''} ${n.module ?? ''}`.toLowerCase()
      if (hay.includes(q.value)) {
        keep.add(n.uid)
        for (const uid of trail) keep.add(uid)
      }
      walk(n.children, [...trail, n.uid])
    }
  }
  walk(tree.value, [])
  return keep
})

/** The flattened rows to render, honouring collapse state and the search. */
const rows = computed(() => {
  const out: { node: BuilderNode; depth: number }[] = []
  const walk = (nodes: BuilderNode[], depth: number) => {
    for (const n of nodes) {
      if (!filtering.value || visibleUids.value.has(n.uid)) out.push({ node: n, depth })
      // A search opens the whole tree; otherwise the chevron decides.
      if (n.children.length && (filtering.value || expanded.value.has(n.uid))) walk(n.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return out
})

const counts = computed(() => {
  let total = 0
  let hidden = 0
  const walk = (nodes: BuilderNode[]) => {
    for (const n of nodes) {
      total++
      if (!n.is_active) hidden++
      walk(n.children)
    }
  }
  walk(tree.value)
  return { total, hidden }
})

function toggleExpand(node: BuilderNode) {
  const next = new Set(expanded.value)
  if (next.has(node.uid)) next.delete(node.uid)
  else next.add(node.uid)
  expanded.value = next
}

function expandAll() {
  const all = new Set<string>()
  const walk = (nodes: BuilderNode[]) => {
    for (const n of nodes) {
      if (n.children.length) all.add(n.uid)
      walk(n.children)
    }
  }
  walk(tree.value)
  expanded.value = all
}

function collapseAll() {
  expanded.value = new Set()
}

// --- drag & drop ---

type Zone = 'before' | 'inside' | 'after'

const dragUid = ref<string | null>(null)
const dropTarget = ref<{ uid: string; zone: Zone } | null>(null)
/** The trailing "move to the end of the top level" strip. */
const dropAtRoot = ref(false)

function onDragStart(node: BuilderNode, event: DragEvent) {
  if (props.readonly) return
  dragUid.value = node.uid
  // Firefox ignores a drag with no payload; the uid also makes the intent
  // inspectable, and effectAllowed gets the move cursor rather than a copy.
  event.dataTransfer?.setData('text/plain', node.uid)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd() {
  dragUid.value = null
  dropTarget.value = null
  dropAtRoot.value = false
}

/** Which third of the row the pointer is over. */
function zoneFor(event: DragEvent): Zone {
  const box = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const y = event.clientY - box.top
  if (y < box.height * 0.3) return 'before'
  if (y > box.height * 0.7) return 'after'
  return 'inside'
}

function onDragOver(node: BuilderNode, event: DragEvent) {
  if (!dragUid.value || props.readonly) return
  const zone = zoneFor(event)
  if (!canDrop(dragUid.value, node, zone)) {
    dropTarget.value = null
    return
  }
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  dropAtRoot.value = false
  dropTarget.value = { uid: node.uid, zone }
}

/**
 * Is this a move we can make?
 *
 * Refuses a drop into the dragged row's own subtree (which would detach the
 * branch from the tree), and anything that would nest past the depth cap. A drop
 * into a linked row IS allowed — onDrop asks for confirmation, since it costs the
 * row its link.
 *
 * Takes the dragged uid explicitly rather than reading dragUid: onDrop clears the
 * drag state before it awaits the confirmation, and both callers must judge the
 * same move.
 */
function canDrop(uid: string, target: BuilderNode, zone: Zone): boolean {
  if (uid === target.uid) return false

  const dragged = locate(uid)?.node
  if (!dragged || contains(dragged, target.uid)) return false

  const targetDepth = depths.value.get(target.uid) ?? 0
  const landingDepth = zone === 'inside' ? targetDepth + 1 : targetDepth

  return landingDepth + height(dragged) <= maxDepth.value
}

async function onDrop(target: BuilderNode, event: DragEvent) {
  event.preventDefault()
  const uid = dragUid.value
  const zone = dropTarget.value?.zone ?? zoneFor(event)
  onDragEnd()
  if (!uid || !canDrop(uid, target, zone)) return

  // Nesting into a row that links to a screen: the nav renders a linked row as a
  // leaf and would never show the children, so the link has to go. Ask, because
  // the screen then disappears from the menu until it is added back.
  if (zone === 'inside' && target.route !== null) {
    const confirmed = await Swal.fire({
      title: 'Turn this into a group?',
      html: `<b>${escapeHtml(target.label)}</b> currently opens <code>${escapeHtml(target.route)}</code>.<br>A group cannot also be a link, so that screen link will be cleared — add it back as a sub-item if you still need it.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Make it a group',
      confirmButtonColor: '#e8a33d',
    })
    if (!confirmed.isConfirmed) return
    target.route = null
  }

  const from = locate(uid)
  if (!from) return
  const [moved] = from.siblings.splice(from.index, 1)

  if (zone === 'inside') {
    target.children.push(moved)
    expanded.value = new Set([...expanded.value, target.uid])
    return
  }

  // Re-locate the target: removing the dragged row may have shifted its index.
  const to = locate(target.uid)
  if (!to) {
    // Should not happen — put the row back rather than lose it.
    from.siblings.splice(from.index, 0, moved)
    return
  }
  to.siblings.splice(zone === 'before' ? to.index : to.index + 1, 0, moved)
}

function onRootDragOver(event: DragEvent) {
  if (!dragUid.value || props.readonly) return
  event.preventDefault()
  dropTarget.value = null
  dropAtRoot.value = true
}

function onRootDrop(event: DragEvent) {
  event.preventDefault()
  const uid = dragUid.value
  onDragEnd()
  if (!uid) return
  const from = locate(uid)
  if (!from) return
  const [moved] = from.siblings.splice(from.index, 1)
  tree.value.push(moved)
}

// --- row actions ---

function addGroup(parent: BuilderNode | null) {
  if (parent && parent.route !== null) {
    toast.add({
      severity: 'warn',
      summary: 'This row is a link',
      detail: 'Clear its screen first — a row that opens a screen cannot hold sub-items.',
      life: 4000,
    })
    return
  }

  const depth = parent ? (depths.value.get(parent.uid) ?? 0) + 1 : 0
  if (depth >= maxDepth.value) {
    toast.add({ severity: 'warn', summary: 'Too deep', detail: `The menu nests at most ${maxDepth.value} levels.`, life: 4000 })
    return
  }

  const node: BuilderNode = {
    uid: nextUid(),
    id: null,
    label: 'New group',
    icon: 'pi pi-folder',
    route: null,
    permission: null,
    module: null,
    is_active: true,
    children: [],
  }

  if (parent) {
    parent.children.push(node)
    expanded.value = new Set([...expanded.value, parent.uid])
  } else {
    tree.value.push(node)
  }

  search.value = ''
  editingUid.value = node.uid
}

/** Where the screen picker will insert (null = top level). */
const pickerParent = ref<BuilderNode | null>(null)
const pickerOpen = ref(false)
const pickerSearch = ref('')

function openPicker(parent: BuilderNode | null) {
  if (parent && parent.route !== null) {
    toast.add({
      severity: 'warn',
      summary: 'This row is a link',
      detail: 'Clear its screen first — a row that opens a screen cannot hold sub-items.',
      life: 4000,
    })
    return
  }
  pickerParent.value = parent
  pickerSearch.value = ''
  pickerOpen.value = true
}

const pickerRows = computed(() => {
  const needle = pickerSearch.value.trim().toLowerCase()
  const list = needle
    ? screens.value.filter((s) => `${s.label} ${s.route} ${s.section}`.toLowerCase().includes(needle))
    : screens.value
  // Grouped by the section the screen sits in by default, so 200-odd screens
  // read as the product's own structure rather than an alphabetical dump.
  const sections = new Map<string, Screen[]>()
  for (const s of list) {
    const bucket = sections.get(s.section) ?? []
    bucket.push(s)
    sections.set(s.section, bucket)
  }
  return [...sections.entries()].map(([section, items]) => ({ section, items }))
})

function addScreen(screen: Screen) {
  const parent = pickerParent.value
  const depth = parent ? (depths.value.get(parent.uid) ?? 0) + 1 : 0
  if (depth >= maxDepth.value) {
    toast.add({ severity: 'warn', summary: 'Too deep', detail: `The menu nests at most ${maxDepth.value} levels.`, life: 4000 })
    return
  }

  // The catalogue's own permission and module travel with the screen: a link
  // moved into a custom group must keep the gate it shipped with.
  const node: BuilderNode = {
    uid: nextUid(),
    id: null,
    label: screen.label,
    icon: screen.icon ?? '',
    route: screen.route,
    permission: screen.permission,
    module: screen.module,
    is_active: true,
    children: [],
  }

  if (parent) {
    parent.children.push(node)
    expanded.value = new Set([...expanded.value, parent.uid])
  } else {
    tree.value.push(node)
  }

  screen.in_menu = true
  toast.add({ severity: 'success', summary: `${screen.label} added`, detail: parent ? `Under ${parent.label}` : 'At the top level', life: 2000 })
}

async function removeRow(node: BuilderNode) {
  const kids = countSubtree(node) - 1
  const confirmed = await Swal.fire({
    title: `Remove "${node.label}"?`,
    html: kids
      ? `This also removes the <b>${kids}</b> item${kids === 1 ? '' : 's'} nested under it. Nothing is deleted until you save.`
      : 'Nothing is deleted until you save.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Remove',
    confirmButtonColor: '#d33',
  })
  if (!confirmed.isConfirmed) return

  const found = locate(node.uid)
  if (found) found.siblings.splice(found.index, 1)
  if (editingUid.value === node.uid) editingUid.value = null
}

function countSubtree(node: BuilderNode): number {
  return 1 + node.children.reduce((sum, c) => sum + countSubtree(c), 0)
}

function toggleActive(node: BuilderNode) {
  node.is_active = !node.is_active
}

function toggleEdit(node: BuilderNode) {
  editingUid.value = editingUid.value === node.uid ? null : node.uid
}

/**
 * Picking a screen for an existing row also adopts its gate, and a row that has
 * children can't become a link.
 */
function onRouteChange(node: BuilderNode, route: string | null) {
  if (route && node.children.length) {
    node.route = null
    toast.add({
      severity: 'warn',
      summary: 'This row has sub-items',
      detail: 'A group cannot also be a link. Move its sub-items out first.',
      life: 4000,
    })
    return
  }

  node.route = route
  const screen = screens.value.find((s) => s.route === route)
  if (screen) {
    node.permission = screen.permission
    node.module = screen.module
  }
}

const screenOptions = computed(() =>
  screens.value.map((s) => ({ label: `${s.section} › ${s.label}`, value: s.route })),
)

const moduleOptions = computed(() => modules.value.map((m) => ({ label: m.label, value: m.key })))

function moduleLabel(key: string | null): string {
  if (!key) return ''
  return modules.value.find((m) => m.key === key)?.label ?? key
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}

// --- save / reset ---

/** The labels behind a set of uids, for carrying the fold state across a save. */
function labelsOf(uids: Set<string>): string[] {
  const out: string[] = []
  const walk = (nodes: BuilderNode[]) => {
    for (const n of nodes) {
      if (uids.has(n.uid)) out.push(n.label)
      walk(n.children)
    }
  }
  walk(tree.value)
  return out
}

function restoreExpanded(labels: Set<string>) {
  const next = new Set(expanded.value)
  const walk = (nodes: BuilderNode[]) => {
    for (const n of nodes) {
      if (n.children.length && labels.has(n.label)) next.add(n.uid)
      walk(n.children)
    }
  }
  walk(tree.value)
  expanded.value = next
}

async function save() {
  if (!props.tenant) return
  saving.value = true
  try {
    const { data } = await centralHttp.put(`/tenants/${props.tenant.id}/menu`, { tree: serialise(tree.value) })

    // Remember what was unfolded *by label*: applyPayload rebuilds the tree and
    // mints fresh uids, so the old open set can't be carried over as-is.
    const openLabels = new Set(labelsOf(expanded.value))

    // Re-seed from the response: rows the operator added come back with their
    // real ids, so a second save edits them instead of creating duplicates.
    applyPayload(data)
    restoreExpanded(openLabels)

    toast.add({ severity: 'success', summary: 'Menu saved', detail: 'The workspace sees the new navigation on its next page load.', life: 3000 })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    const detail = errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Save failed'
    toast.add({ severity: 'error', summary: 'Could not save', detail, life: 6000 })
  } finally {
    saving.value = false
  }
}

async function resetMenu() {
  if (!props.tenant) return
  const confirmed = await Swal.fire({
    title: 'Reset to the default menu?',
    html: 'This workspace\'s custom navigation — order, groups and renames — is discarded and the standard menu is restored. Its users\' favourites are cleared too. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Reset menu',
    confirmButtonColor: '#d33',
  })
  if (!confirmed.isConfirmed) return

  saving.value = true
  try {
    const { data } = await centralHttp.post(`/tenants/${props.tenant.id}/menu/reset`)
    applyPayload(data)
    toast.add({ severity: 'success', summary: 'Menu reset', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Reset failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function requestClose() {
  if (dirty.value) {
    const confirmed = await Swal.fire({
      title: 'Discard your changes?',
      text: 'The menu has unsaved changes.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Discard',
      confirmButtonColor: '#d33',
    })
    if (!confirmed.isConfirmed) return
  }
  visible.value = false
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Menu Builder — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '980px' }"
    :closable="false"
    :closeOnEscape="false"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading menu…</div>

    <template v-else>
      <p class="intro">
        <i class="pi pi-arrows-alt" />
        Drag a row by its handle to reorder it. Drop on the <b>top or bottom edge</b>
        of a row to place it beside that row, or on its <b>middle</b> to nest it
        inside. Nesting goes {{ maxDepth }} levels deep and the workspace's sidebar
        cascades all of them.
      </p>

      <div class="toolbar">
        <Button
          label="Add group"
          icon="pi pi-folder-plus"
          size="small"
          outlined
          :disabled="props.readonly"
          @click="addGroup(null)"
        />
        <Button
          label="Add screen"
          icon="pi pi-plus-circle"
          size="small"
          outlined
          :disabled="props.readonly"
          @click="openPicker(null)"
        />
        <span class="sep" />
        <Button label="Expand all" icon="pi pi-angle-double-down" size="small" text @click="expandAll" />
        <Button label="Collapse all" icon="pi pi-angle-double-up" size="small" text @click="collapseAll" />
        <InputText v-model="search" placeholder="Find a screen…" class="tree-search" />
      </div>

      <div class="meta">
        <span>{{ counts.total }} rows</span>
        <span v-if="counts.hidden">· {{ counts.hidden }} switched off</span>
        <span v-if="dirty" class="unsaved"><i class="pi pi-circle-fill" /> unsaved changes</span>
      </div>

      <p v-if="filtering" class="note">
        <i class="pi pi-info-circle" />
        Dragging is off while a search is active — clear the box to rearrange.
      </p>

      <div class="tree">
        <div
          v-for="{ node, depth } in rows"
          :key="node.uid"
          class="row-wrap"
        >
          <div
            class="row"
            :class="{
              dragging: dragUid === node.uid,
              off: !node.is_active,
              'drop-before': dropTarget?.uid === node.uid && dropTarget?.zone === 'before',
              'drop-after': dropTarget?.uid === node.uid && dropTarget?.zone === 'after',
              'drop-inside': dropTarget?.uid === node.uid && dropTarget?.zone === 'inside',
            }"
            :style="{ paddingLeft: `${0.35 + depth * 1.35}rem` }"
            @dragover="onDragOver(node, $event)"
            @drop="onDrop(node, $event)"
          >
            <!-- Only the handle is draggable, so the row's own buttons and text
                 stay clickable and selectable. -->
            <span
              class="handle"
              :class="{ disabled: props.readonly || filtering }"
              :draggable="!props.readonly && !filtering"
              :title="filtering ? 'Clear the search to rearrange' : 'Drag to move'"
              @dragstart="onDragStart(node, $event)"
              @dragend="onDragEnd"
            >
              <i class="pi pi-bars" />
            </span>

            <button
              v-if="node.children.length"
              class="chev"
              :title="expanded.has(node.uid) ? 'Collapse' : 'Expand'"
              @click="toggleExpand(node)"
            >
              <i :class="expanded.has(node.uid) || filtering ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
            </button>
            <span v-else class="chev-spacer" />

            <i v-if="node.icon" :class="node.icon" class="row-icon" />
            <i v-else class="pi pi-minus row-icon faint" />

            <span class="row-label">{{ node.label }}</span>

            <Tag v-if="isGroup(node)" value="group" severity="secondary" class="chip" />
            <code v-else class="route">{{ node.route }}</code>
            <Tag v-if="node.module" :value="moduleLabel(node.module)" severity="info" class="chip" />
            <Tag v-if="!node.is_active" value="off" severity="danger" class="chip" />

            <span class="row-actions">
              <button
                v-if="isGroup(node)"
                class="act"
                title="Add a sub-group here"
                :disabled="props.readonly"
                @click="addGroup(node)"
              >
                <i class="pi pi-folder-plus" />
              </button>
              <button
                v-if="isGroup(node)"
                class="act"
                title="Add a screen here"
                :disabled="props.readonly"
                @click="openPicker(node)"
              >
                <i class="pi pi-plus" />
              </button>
              <button
                class="act"
                :title="node.is_active ? 'Switch this row off' : 'Switch this row on'"
                :disabled="props.readonly"
                @click="toggleActive(node)"
              >
                <i :class="node.is_active ? 'pi pi-eye' : 'pi pi-eye-slash'" />
              </button>
              <button
                class="act"
                :class="{ on: editingUid === node.uid }"
                title="Rename, re-icon, re-point"
                :disabled="props.readonly"
                @click="toggleEdit(node)"
              >
                <i class="pi pi-pencil" />
              </button>
              <button class="act danger" title="Remove from the menu" :disabled="props.readonly" @click="removeRow(node)">
                <i class="pi pi-trash" />
              </button>
            </span>
          </div>

          <!-- Inline editor, opened by the pencil. -->
          <div v-if="editingUid === node.uid" class="editor" :style="{ marginLeft: `${1.1 + depth * 1.35}rem` }">
            <div class="field">
              <label>Label</label>
              <InputText v-model="node.label" placeholder="Shown in the sidebar" />
            </div>
            <div class="field">
              <label>Icon</label>
              <div class="icon-field">
                <InputText v-model="node.icon" placeholder="pi pi-box" />
                <i :class="node.icon || 'pi pi-minus'" class="icon-preview" />
              </div>
            </div>
            <div class="field wide">
              <label>Screen</label>
              <Select
                :modelValue="node.route"
                :options="screenOptions"
                optionLabel="label"
                optionValue="value"
                filter
                showClear
                :disabled="node.children.length > 0"
                :placeholder="node.children.length ? 'A group cannot link to a screen' : 'None — this row is a group'"
                @update:modelValue="onRouteChange(node, $event)"
              />
            </div>
            <div class="field">
              <label>Permission gate</label>
              <Select
                v-model="node.permission"
                :options="permissions"
                filter
                showClear
                placeholder="Everyone in the workspace"
              />
            </div>
            <div class="field">
              <label>Module</label>
              <Select
                v-model="node.module"
                :options="moduleOptions"
                optionLabel="label"
                optionValue="value"
                filter
                showClear
                placeholder="Not module-gated"
              />
            </div>
            <p class="editor-note">
              <i class="pi pi-info-circle" />
              A custom label shows as typed in every language — the Hindi/Gujarati
              dictionary only covers the stock labels.
            </p>
          </div>
        </div>

        <!-- Trailing strip: drop here to move a row to the end of the top level. -->
        <div
          class="root-drop"
          :class="{ active: dropAtRoot, armed: !!dragUid }"
          @dragover="onRootDragOver"
          @drop="onRootDrop"
        >
          <i class="pi pi-arrow-down" /> Drop here to move to the end of the top level
        </div>

        <p v-if="!rows.length" class="empty">
          <template v-if="filtering">Nothing matches “{{ search }}”.</template>
          <template v-else>This menu is empty — add a group or a screen to start.</template>
        </p>
      </div>
    </template>

    <template #footer>
      <Button
        label="Reset to default"
        icon="pi pi-undo"
        text
        severity="danger"
        :disabled="props.readonly || saving || loading"
        @click="resetMenu"
      />
      <span class="footer-gap" />
      <Button label="Close" text :disabled="saving" @click="requestClose" />
      <Button
        label="Save Menu"
        icon="pi pi-check"
        :loading="saving"
        :disabled="props.readonly || loading || !dirty"
        @click="save"
      />
    </template>
  </Dialog>

  <!-- Screen picker: the real screens this product has, grouped as the stock
       catalogue groups them. -->
  <Dialog
    v-model:visible="pickerOpen"
    modal
    :header="pickerParent ? `Add a screen under “${pickerParent.label}”` : 'Add a screen at the top level'"
    :style="{ width: '620px' }"
  >
    <InputText v-model="pickerSearch" placeholder="Search screens…" class="picker-search" autofocus />
    <div class="picker">
      <div v-for="group in pickerRows" :key="group.section" class="picker-group">
        <div class="picker-section">{{ group.section }}</div>
        <button
          v-for="screen in group.items"
          :key="screen.route"
          class="picker-item"
          @click="addScreen(screen)"
        >
          <i :class="screen.icon || 'pi pi-file'" />
          <span>{{ screen.label }}</span>
          <code>{{ screen.route }}</code>
          <Tag v-if="screen.in_menu" value="already in menu" severity="secondary" class="chip" />
        </button>
      </div>
      <p v-if="!pickerRows.length" class="empty">No screen matches that.</p>
    </div>
    <template #footer>
      <Button label="Done" icon="pi pi-check" @click="pickerOpen = false" />
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
  margin: 0 0 0.9rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  padding: 0.45rem 0.55rem;
  border-radius: 10px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
.sep {
  width: 1px;
  align-self: stretch;
  background: var(--border, #e3ded3);
  margin: 0 0.25rem;
}
.tree-search {
  margin-left: auto;
  font-size: 0.8rem;
  padding: 0.25rem 0.5rem;
  width: 13rem;
}
.meta {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0.5rem 0 0.2rem;
  font-size: 0.72rem;
  color: var(--muted, #6f7680);
}
.unsaved {
  margin-left: auto;
  color: #b45309;
  font-weight: 600;
}
.unsaved i {
  font-size: 0.42rem;
  vertical-align: middle;
}
.note {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0.3rem 0;
  font-size: 0.75rem;
  color: var(--muted, #6b7280);
}
.tree {
  margin-top: 0.4rem;
  max-height: 52vh;
  overflow-y: auto;
  border: 1px solid var(--border, #e8e4da);
  border-radius: 10px;
  padding: 0.35rem;
}
.row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.3rem 0.4rem;
  border-radius: 6px;
  font-size: 0.84rem;
  /* The drop indicators are drawn as borders; a transparent one by default keeps
     the row from jumping 2px when the pointer arrives. */
  border-top: 2px solid transparent;
  border-bottom: 2px solid transparent;
}
.row:hover {
  background: #f9fafb;
}
.row.dragging {
  opacity: 0.4;
}
.row.off .row-label {
  text-decoration: line-through;
  color: var(--muted, #9ca3af);
}
.row.drop-before {
  border-top-color: var(--safety-amber, #e8a33d);
}
.row.drop-after {
  border-bottom-color: var(--safety-amber, #e8a33d);
}
.row.drop-inside {
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 16%, transparent);
  box-shadow: inset 3px 0 0 var(--safety-amber, #e8a33d);
}
.handle {
  cursor: grab;
  color: #b9c0c8;
  padding: 0 0.1rem;
  flex: 0 0 auto;
}
.handle:active {
  cursor: grabbing;
}
.handle.disabled {
  cursor: not-allowed;
  opacity: 0.35;
}
.chev,
.act {
  background: none;
  border: none;
  padding: 0.15rem 0.25rem;
  cursor: pointer;
  color: #6b7280;
  border-radius: 4px;
  font-size: 0.72rem;
}
.chev:hover,
.act:hover {
  background: #eef0f2;
  color: #111827;
}
.act:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}
.act.on {
  color: var(--safety-amber, #e8a33d);
}
.act.danger:hover {
  color: #b91c1c;
}
.chev-spacer {
  width: 1.1rem;
  flex: 0 0 auto;
}
.row-icon {
  width: 1.05rem;
  text-align: center;
  color: var(--safety-amber, #e8a33d);
  flex: 0 0 auto;
}
.row-icon.faint {
  color: #d1d5db;
}
.row-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.route {
  font-size: 0.68rem;
  color: #9ca3af;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.chip :deep(.p-tag-label),
.chip {
  font-size: 0.6rem;
}
.row-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.1rem;
  opacity: 0;
  transition: opacity 0.12s;
  flex: 0 0 auto;
}
.row:hover .row-actions,
.row:focus-within .row-actions {
  opacity: 1;
}
.editor {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem 0.8rem;
  margin: 0.2rem 0 0.5rem;
  padding: 0.6rem 0.7rem;
  border-radius: 8px;
  background: var(--surface-2, #f6f5f1);
  border: 1px solid var(--border, #e8e4da);
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}
.field.wide {
  grid-column: 1 / -1;
}
.field label {
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--muted, #6f7680);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.field :deep(.p-inputtext),
.field :deep(.p-select) {
  font-size: 0.8rem;
}
.icon-field {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.icon-field :deep(.p-inputtext) {
  flex: 1;
  min-width: 0;
}
.icon-preview {
  color: var(--safety-amber, #e8a33d);
  width: 1.1rem;
  text-align: center;
}
.editor-note {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.72rem;
  color: var(--muted, #6b7280);
}
.root-drop {
  margin-top: 0.35rem;
  padding: 0.5rem;
  border: 1px dashed transparent;
  border-radius: 8px;
  text-align: center;
  font-size: 0.72rem;
  color: transparent;
  transition: color 0.12s, border-color 0.12s, background 0.12s;
}
/* Only announced while something is actually being dragged. */
.root-drop.armed {
  border-color: var(--border, #d8d3c8);
  color: var(--muted, #9ca3af);
}
.root-drop.active {
  border-color: var(--safety-amber, #e8a33d);
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  color: #8a5a12;
}
.empty {
  padding: 1.2rem;
  text-align: center;
  font-size: 0.8rem;
  color: var(--muted, #9ca3af);
}
.footer-gap {
  flex: 1;
}
.picker-search {
  width: 100%;
  margin-bottom: 0.6rem;
}
.picker {
  max-height: 50vh;
  overflow-y: auto;
}
.picker-section {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted, #6f7680);
  padding: 0.5rem 0.3rem 0.25rem;
  border-bottom: 1px solid var(--border, #eee9de);
  margin-bottom: 0.2rem;
}
.picker-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.35rem 0.45rem;
  border: none;
  background: none;
  border-radius: 6px;
  cursor: pointer;
  font-family: inherit;
  font-size: 0.82rem;
  text-align: left;
}
.picker-item:hover {
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
}
.picker-item i {
  color: var(--safety-amber, #e8a33d);
  width: 1.05rem;
  text-align: center;
}
.picker-item code {
  margin-left: auto;
  font-size: 0.66rem;
  color: #9ca3af;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
:deep(.p-dialog-footer) {
  display: flex;
  align-items: center;
}
</style>
