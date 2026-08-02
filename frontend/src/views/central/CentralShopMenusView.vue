<script setup lang="ts">
/**
 * Shop Menus (/platform/shop-menus) — the menu each *trade* starts on.
 *
 * The platform panel now has three menu surfaces, and they answer three
 * different questions:
 *
 *   Menu Catalogue         — is this screen part of the product at all?
 *   Shop Menus (this one)  — what does a Grocery workspace open on?
 *   Tenants → Menu Builder — where does it sit in one particular workspace?
 *
 * This is the one whose edits are free: a template is nobody's live navigation,
 * so the tree can be dragged into any shape without a confirmation. It steers
 * every *future* signup of that trade and nothing else. Reaching the workspaces
 * already on the trade is its own button — "Apply to workspaces" — because that
 * overwrites whatever their operators arranged.
 *
 * Drag & drop is native HTML5, no library, and works exactly as the per-tenant
 * builder does: the top and bottom edges of a row reorder as a sibling, the
 * middle nests as a child, and the live zone is drawn as you drag so a nest never
 * happens by surprise.
 */
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('menus.edit'))

type Shop = {
  key: string
  label: string
  icon: string
  description: string
  industry: string | null
  rows: number
  screens: number
  hidden: number
  has_template: boolean
  ships_default: boolean
  tenants: number
}

type BuilderNode = {
  /** Stable client-side key. Survives re-parenting; new rows have no server id. */
  uid: string
  id: number | null
  label: string
  /** Empty string means "no icon" — a plain InputText can't hold null. */
  icon: string
  route: string | null
  permission: string | null
  feature: string | null
  module: string | null
  is_active: boolean
  children: BuilderNode[]
}

type Screen = {
  label: string
  icon: string | null
  route: string
  permission: string | null
  feature: string | null
  module: string | null
  section: string
  in_menu: boolean
}

// --- shop list -------------------------------------------------------------

const shops = ref<Shop[]>([])
const stats = ref<{ trades: number; with_template: number; catalogue_screens: number } | null>(null)
const listLoading = ref(false)
const shopSearch = ref('')
const selected = ref<string | null>(null)

/** Trades grouped by the vertical they belong to, so sixty rows read as ten. */
const shopGroups = computed(() => {
  const needle = shopSearch.value.trim().toLowerCase()
  const list = needle
    ? shops.value.filter((s) => `${s.label} ${s.key} ${s.industry ?? ''}`.toLowerCase().includes(needle))
    : shops.value

  const groups = new Map<string, Shop[]>()
  for (const shop of list) {
    const key = shop.industry ?? 'other'
    const bucket = groups.get(key) ?? []
    bucket.push(shop)
    groups.set(key, bucket)
  }
  return [...groups.entries()].map(([industry, items]) => ({ industry, items }))
})

function industryLabel(key: string): string {
  if (key === 'other') return 'Other'
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

async function loadShops() {
  listLoading.value = true
  try {
    const { data } = await centralHttp.get('/shop-menus')
    shops.value = data.data ?? []
    stats.value = data.stats ?? null
    // Open on the first trade that actually has something to look at.
    if (!selected.value) {
      const first = shops.value.find((s) => s.has_template) ?? shops.value[0]
      if (first) void selectShop(first.key)
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load shop types', life: 4000 })
  } finally {
    listLoading.value = false
  }
}

// --- the tree --------------------------------------------------------------

const shop = ref<{ key: string; label: string; icon: string; description: string; industry: string | null } | null>(null)
const tree = ref<BuilderNode[]>([])
const screens = ref<Screen[]>([])
const permissions = ref<string[]>([])
const modules = ref<{ key: string; label: string }[]>([])
const tradeModules = ref<string[]>([])
const maxDepth = ref(5)
const hasTemplate = ref(false)
const shipsDefault = ref(false)
const tenantCount = ref(0)

const loading = ref(false)
const saving = ref(false)

const expanded = ref<Set<string>>(new Set())
const search = ref('')
const editingUid = ref<string | null>(null)

/** The saved tree, to tell an edited panel from an untouched one. */
const baseline = ref('')

let uidSeq = 0
function nextUid(): string {
  return `n${++uidSeq}`
}

function toBuilder(nodes: any[]): BuilderNode[] {
  return (nodes ?? []).map((n: any) => ({
    uid: nextUid(),
    id: n.id ?? null,
    label: n.label ?? '',
    icon: n.icon ?? '',
    route: n.route ?? null,
    permission: n.permission ?? null,
    feature: n.feature ?? null,
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
    feature: n.feature,
    module: n.module,
    is_active: n.is_active,
    children: serialise(n.children),
  }))
}

function applyPayload(data: any) {
  shop.value = data.shop
  tree.value = toBuilder(data.tree)
  screens.value = data.screens ?? []
  permissions.value = data.permissions ?? []
  modules.value = data.modules ?? []
  tradeModules.value = data.trade_modules ?? []
  maxDepth.value = data.max_depth ?? 5
  hasTemplate.value = !!data.has_template
  shipsDefault.value = !!data.ships_default
  tenantCount.value = data.tenants ?? 0
  baseline.value = JSON.stringify(serialise(tree.value))
  // Top level open, everything below folded: ninety rows expanded at once is not
  // a tree anyone can drag around.
  expanded.value = new Set(tree.value.filter((n) => n.children.length).map((n) => n.uid))
  editingUid.value = null
}

async function selectShop(key: string) {
  if (dirty.value && !(await confirmDiscard())) return

  selected.value = key
  loading.value = true
  search.value = ''
  try {
    const { data } = await centralHttp.get(`/shop-menus/${key}`)
    applyPayload(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load that menu', life: 4000 })
  } finally {
    loading.value = false
  }
}

const dirty = computed(() => baseline.value !== '' && JSON.stringify(serialise(tree.value)) !== baseline.value)

async function confirmDiscard(): Promise<boolean> {
  const confirmed = await Swal.fire({
    title: 'Discard your changes?',
    text: 'This shop menu has unsaved changes.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Discard',
    confirmButtonColor: '#d33',
  })
  return confirmed.isConfirmed
}

// --- tree helpers ----------------------------------------------------------

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

/**
 * A screen whose module this trade does not switch on.
 *
 * Not an error — the preset stays editable at signup and an operator may comp the
 * module later — but it is worth flagging, because such a row is in the template
 * and invisible in the workspace, which reads as a bug from the tenant's side.
 */
function offTrade(node: BuilderNode): boolean {
  return !!node.module && tradeModules.value.length > 0 && !tradeModules.value.includes(node.module)
}

// --- search ----------------------------------------------------------------

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
  let offModule = 0
  const walk = (nodes: BuilderNode[]) => {
    for (const n of nodes) {
      total++
      if (!n.is_active) hidden++
      if (offTrade(n)) offModule++
      walk(n.children)
    }
  }
  walk(tree.value)
  return { total, hidden, offModule }
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

// --- drag & drop -----------------------------------------------------------

type Zone = 'before' | 'inside' | 'after'

const dragUid = ref<string | null>(null)
const dropTarget = ref<{ uid: string; zone: Zone } | null>(null)
/** The trailing "move to the end of the top level" strip. */
const dropAtRoot = ref(false)

function onDragStart(node: BuilderNode, event: DragEvent) {
  if (!canEdit.value) return
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
  if (!dragUid.value || !canEdit.value) return
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
 * into a linked row IS allowed — onDrop asks first, since it costs the row its
 * link.
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
  if (!dragUid.value || !canEdit.value) return
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

// --- row actions -----------------------------------------------------------

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
    feature: null,
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
  // Grouped by the section the screen sits in by default, so 200-odd screens read
  // as the product's own structure rather than an alphabetical dump.
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

  // The catalogue's own permission, plan feature and module travel with the
  // screen: a link moved into a bespoke group must keep the gate it shipped with.
  const node: BuilderNode = {
    uid: nextUid(),
    id: null,
    label: screen.label,
    icon: screen.icon ?? '',
    route: screen.route,
    permission: screen.permission,
    feature: screen.feature,
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
      ? `This also removes the <b>${kids}</b> item${kids === 1 ? '' : 's'} nested under it. Nothing is saved until you save.`
      : 'Nothing is saved until you save.',
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
    node.feature = screen.feature
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

// --- save / reset / apply --------------------------------------------------

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
  if (!selected.value) return
  saving.value = true
  try {
    const { data } = await centralHttp.put(`/shop-menus/${selected.value}`, { tree: serialise(tree.value) })

    // Remember what was unfolded *by label*: applyPayload rebuilds the tree and
    // mints fresh uids, so the old open set can't be carried over as-is.
    const openLabels = new Set(labelsOf(expanded.value))

    // Re-seed from the response: rows just added come back with their real ids,
    // so a second save edits them instead of creating duplicates.
    applyPayload(data)
    restoreExpanded(openLabels)
    await loadShops()

    toast.add({
      severity: 'success',
      summary: 'Shop menu saved',
      detail: 'Every new workspace on this trade will be created with it.',
      life: 3000,
    })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    const detail = errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Save failed'
    toast.add({ severity: 'error', summary: 'Could not save', detail, life: 6000 })
  } finally {
    saving.value = false
  }
}

async function resetTemplate(source: 'default' | 'catalogue') {
  if (!selected.value) return

  const confirmed = await Swal.fire({
    title: source === 'default' ? 'Restore the shipped menu?' : 'Start from the full catalogue?',
    html:
      source === 'default'
        ? 'Whatever is arranged here is discarded and the menu this trade ships with comes back. Workspaces already created keep the menu they have.'
        : `Every screen the product has — <b>${stats.value?.catalogue_screens ?? '…'}</b> of them — is copied in as a starting tree for you to prune. Whatever is arranged here is discarded.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: source === 'default' ? 'Restore default' : 'Copy the catalogue',
    confirmButtonColor: '#d33',
  })
  if (!confirmed.isConfirmed) return

  saving.value = true
  try {
    const { data } = await centralHttp.post(`/shop-menus/${selected.value}/reset`, { source })
    applyPayload(data)
    await loadShops()
    toast.add({ severity: 'success', summary: 'Shop menu reset', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Reset failed', life: 5000 })
  } finally {
    saving.value = false
  }
}

async function removeTemplate() {
  if (!selected.value) return

  const confirmed = await Swal.fire({
    title: 'Remove this shop menu?',
    html: 'New workspaces on this trade will be created with the <b>full catalogue</b> again, as they were before this template existed. Existing workspaces are untouched.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Remove template',
    confirmButtonColor: '#d33',
  })
  if (!confirmed.isConfirmed) return

  saving.value = true
  try {
    const { data } = await centralHttp.delete(`/shop-menus/${selected.value}`)
    applyPayload(data)
    await loadShops()
    toast.add({ severity: 'success', summary: 'Template removed', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not remove it', life: 5000 })
  } finally {
    saving.value = false
  }
}

/**
 * Push this template onto the live workspaces already on the trade.
 *
 * Deliberately loud: this is the one action on the screen that reaches a running
 * business, and it throws away whatever their operator arranged in Tenants → Menu
 * Builder along with their users' favourites.
 */
async function applyToTenants() {
  if (!selected.value || tenantCount.value === 0) return

  if (dirty.value) {
    toast.add({ severity: 'warn', summary: 'Save first', detail: 'Save the menu before pushing it to workspaces.', life: 4000 })
    return
  }

  const confirmed = await Swal.fire({
    title: `Apply to ${tenantCount.value} workspace${tenantCount.value === 1 ? '' : 's'}?`,
    html: `Every workspace on <b>${escapeHtml(shop.value?.label ?? '')}</b> has its navigation <b>replaced</b> with this menu. Any rearranging their operator did — custom groups, renames, order — is lost, and their users' favourites are cleared. This cannot be undone.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Replace their menus',
    confirmButtonColor: '#d33',
  })
  if (!confirmed.isConfirmed) return

  saving.value = true
  try {
    const { data } = await centralHttp.post(`/shop-menus/${selected.value}/apply`)
    toast.add({ severity: 'success', summary: 'Applied', detail: data.message, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not apply it', life: 5000 })
  } finally {
    saving.value = false
  }
}

onMounted(loadShops)
</script>

<template>
  <div>
    <div class="header">
      <h1>Shop Menus</h1>
      <Button label="Refresh" icon="pi pi-refresh" text :loading="listLoading" @click="loadShops" />
    </div>

    <p class="intro">
      <i class="pi pi-info-circle" />
      The menu each <strong>shop type</strong> starts on. A workspace signing up as a Grocery
      store is created with the Grocery menu here rather than the full product catalogue —
      so a kirana shop never opens on Embroidery Vendors and Container Tracking.
      Editing a menu here steers <strong>future</strong> signups only; use
      <em>Apply to workspaces</em> to push it onto the shops already on that trade, or
      Tenants → Menu Builder to change one workspace on its own.
    </p>

    <div v-if="stats" class="stats">
      <div class="stat">
        <span class="stat-value">{{ stats.trades }}</span>
        <span class="stat-label">shop types</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ stats.with_template }}</span>
        <span class="stat-label">with a menu</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ stats.catalogue_screens }}</span>
        <span class="stat-label">screens available</span>
      </div>
    </div>

    <div class="layout">
      <!-- Left: the trades. -->
      <aside class="shops">
        <InputText v-model="shopSearch" placeholder="Find a shop type…" class="shop-search" />

        <div class="shop-list">
          <div v-for="group in shopGroups" :key="group.industry" class="shop-group">
            <div class="shop-section">{{ industryLabel(group.industry) }}</div>
            <button
              v-for="s in group.items"
              :key="s.key"
              class="shop-item"
              :class="{ on: selected === s.key, bare: !s.has_template }"
              @click="selectShop(s.key)"
            >
              <i :class="s.icon || 'pi pi-shop'" />
              <span class="shop-label">{{ s.label }}</span>
              <span v-if="s.has_template" class="shop-count">{{ s.screens }}</span>
              <Tag v-else value="none" severity="secondary" class="chip" />
            </button>
          </div>
          <p v-if="!shopGroups.length" class="empty">No shop type matches that.</p>
        </div>
      </aside>

      <!-- Right: the tree. -->
      <section class="builder">
        <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading menu…</div>

        <template v-else-if="shop">
          <div class="builder-head">
            <div class="builder-title">
              <i :class="shop.icon || 'pi pi-shop'" />
              <div>
                <h2>{{ shop.label }}</h2>
                <p>{{ shop.description }}</p>
              </div>
            </div>
            <div class="builder-actions">
              <Button
                label="Apply to workspaces"
                icon="pi pi-send"
                size="small"
                outlined
                severity="danger"
                :disabled="!canEdit || !hasTemplate || tenantCount === 0 || saving"
                :title="tenantCount === 0 ? 'No workspace is on this trade yet' : `Replace the menu of ${tenantCount} workspace(s)`"
                @click="applyToTenants"
              />
              <Button
                label="Save Menu"
                icon="pi pi-check"
                size="small"
                :loading="saving"
                :disabled="!canEdit || !dirty"
                @click="save"
              />
            </div>
          </div>

          <!-- A trade nobody has curated: say what happens today, and offer the
               two honest ways to start. -->
          <div v-if="!hasTemplate && !tree.length" class="blank">
            <i class="pi pi-inbox" />
            <h3>No menu for this shop type yet</h3>
            <p>
              A workspace signing up as <b>{{ shop.label }}</b> is created with the full
              product catalogue — every screen we ship, whether or not this trade uses it.
              Give it a starting menu:
            </p>
            <div class="blank-actions">
              <Button
                v-if="shipsDefault"
                label="Use the shipped menu"
                icon="pi pi-bolt"
                :disabled="!canEdit || saving"
                @click="resetTemplate('default')"
              />
              <Button
                label="Start from the full catalogue"
                icon="pi pi-clone"
                outlined
                :disabled="!canEdit || saving"
                @click="resetTemplate('catalogue')"
              />
              <Button
                label="Build it from scratch"
                icon="pi pi-plus"
                text
                :disabled="!canEdit"
                @click="addGroup(null)"
              />
            </div>
          </div>

          <template v-else>
            <p class="hint">
              <i class="pi pi-arrows-alt" />
              Drag a row by its handle to reorder it. Drop on the <b>top or bottom edge</b> of a
              row to place it beside that row, or on its <b>middle</b> to nest it inside.
              Nesting goes {{ maxDepth }} levels deep and the workspace's sidebar cascades all
              of them.
            </p>

            <div class="toolbar">
              <Button label="Add group" icon="pi pi-folder-plus" size="small" outlined :disabled="!canEdit" @click="addGroup(null)" />
              <Button label="Add screen" icon="pi pi-plus-circle" size="small" outlined :disabled="!canEdit" @click="openPicker(null)" />
              <span class="sep" />
              <Button label="Expand all" icon="pi pi-angle-double-down" size="small" text @click="expandAll" />
              <Button label="Collapse all" icon="pi pi-angle-double-up" size="small" text @click="collapseAll" />
              <InputText v-model="search" placeholder="Find a screen…" class="tree-search" />
            </div>

            <div class="meta">
              <span>{{ counts.total }} rows</span>
              <span v-if="counts.hidden">· {{ counts.hidden }} switched off</span>
              <span v-if="tenantCount">· {{ tenantCount }} workspace{{ tenantCount === 1 ? '' : 's' }} on this trade</span>
              <span v-if="dirty" class="unsaved"><i class="pi pi-circle-fill" /> unsaved changes</span>
            </div>

            <p v-if="counts.offModule" class="warn">
              <i class="pi pi-exclamation-triangle" />
              {{ counts.offModule }} row{{ counts.offModule === 1 ? '' : 's' }} belong to a module this
              trade does not switch on by default — they will sit in the menu invisibly unless the
              workspace enables the module. They are marked below.
            </p>

            <p v-if="filtering" class="note">
              <i class="pi pi-info-circle" />
              Dragging is off while a search is active — clear the box to rearrange.
            </p>

            <div class="tree">
              <div v-for="{ node, depth } in rows" :key="node.uid" class="row-wrap">
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
                  <!-- Only the handle is draggable, so the row's own buttons and
                       text stay clickable and selectable. -->
                  <span
                    class="handle"
                    :class="{ disabled: !canEdit || filtering }"
                    :draggable="canEdit && !filtering"
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
                  <Tag v-if="node.module" :value="moduleLabel(node.module)" :severity="offTrade(node) ? 'warn' : 'info'" class="chip" />
                  <Tag v-if="!node.is_active" value="off" severity="danger" class="chip" />

                  <span class="row-actions">
                    <button v-if="isGroup(node)" class="act" title="Add a sub-group here" :disabled="!canEdit" @click="addGroup(node)">
                      <i class="pi pi-folder-plus" />
                    </button>
                    <button v-if="isGroup(node)" class="act" title="Add a screen here" :disabled="!canEdit" @click="openPicker(node)">
                      <i class="pi pi-plus" />
                    </button>
                    <button
                      class="act"
                      :title="node.is_active ? 'Switch this row off' : 'Switch this row on'"
                      :disabled="!canEdit"
                      @click="toggleActive(node)"
                    >
                      <i :class="node.is_active ? 'pi pi-eye' : 'pi pi-eye-slash'" />
                    </button>
                    <button
                      class="act"
                      :class="{ on: editingUid === node.uid }"
                      title="Rename, re-icon, re-point"
                      :disabled="!canEdit"
                      @click="toggleEdit(node)"
                    >
                      <i class="pi pi-pencil" />
                    </button>
                    <button class="act danger" title="Remove from the menu" :disabled="!canEdit" @click="removeRow(node)">
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
                    <Select v-model="node.permission" :options="permissions" filter showClear placeholder="Everyone in the workspace" />
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
              <div class="root-drop" :class="{ active: dropAtRoot, armed: !!dragUid }" @dragover="onRootDragOver" @drop="onRootDrop">
                <i class="pi pi-arrow-down" /> Drop here to move to the end of the top level
              </div>

              <p v-if="!rows.length" class="empty">
                <template v-if="filtering">Nothing matches “{{ search }}”.</template>
                <template v-else>This menu is empty — add a group or a screen to start.</template>
              </p>
            </div>

            <div class="footer">
              <Button
                v-if="shipsDefault"
                label="Reset to shipped menu"
                icon="pi pi-undo"
                text
                severity="danger"
                :disabled="!canEdit || saving"
                @click="resetTemplate('default')"
              />
              <Button
                label="Start from the full catalogue"
                icon="pi pi-clone"
                text
                :disabled="!canEdit || saving"
                @click="resetTemplate('catalogue')"
              />
              <Button
                label="Remove template"
                icon="pi pi-trash"
                text
                severity="danger"
                :disabled="!canEdit || saving || !hasTemplate"
                @click="removeTemplate"
              />
            </div>
          </template>
        </template>

        <p v-else class="empty">Pick a shop type on the left.</p>
      </section>
    </div>
  </div>

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
        <button v-for="screen in group.items" :key="screen.route" class="picker-item" @click="addScreen(screen)">
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
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.25rem;
}
.header h1 {
  margin: 0;
  font-size: 1.35rem;
}
.intro {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 0.9rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.5;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.stats {
  display: flex;
  gap: 0.6rem;
  margin-bottom: 0.9rem;
  flex-wrap: wrap;
}
.stat {
  display: flex;
  flex-direction: column;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
  min-width: 7rem;
}
.stat-value {
  font-size: 1.15rem;
  font-weight: 700;
}
.stat-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted, #6f7680);
}

/* Two columns: the trades, then the tree. Collapses to one on a narrow screen —
   the tree is the part that needs the width. */
.layout {
  display: grid;
  grid-template-columns: 17rem minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}

.shops {
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.6rem;
  background: var(--surface-1, #fff);
  position: sticky;
  top: 0.5rem;
}
.shop-search {
  width: 100%;
  font-size: 0.8rem;
  margin-bottom: 0.45rem;
}
.shop-list {
  max-height: 68vh;
  overflow-y: auto;
}
.shop-section {
  font-size: 0.64rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted, #6f7680);
  padding: 0.55rem 0.3rem 0.25rem;
  border-bottom: 1px solid var(--border, #eee9de);
  margin-bottom: 0.2rem;
}
.shop-item {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  padding: 0.35rem 0.45rem;
  border: none;
  background: none;
  border-radius: 6px;
  cursor: pointer;
  font-family: inherit;
  font-size: 0.8rem;
  text-align: left;
  color: inherit;
}
.shop-item:hover {
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 10%, transparent);
}
.shop-item.on {
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 20%, transparent);
  font-weight: 600;
}
/* A trade with no template — the state most worth noticing. */
.shop-item.bare .shop-label {
  color: var(--muted, #9ca3af);
}
.shop-item i {
  color: var(--safety-amber, #e8a33d);
  width: 1.05rem;
  text-align: center;
  flex: 0 0 auto;
}
.shop-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.shop-count {
  margin-left: auto;
  font-size: 0.66rem;
  color: var(--muted, #9ca3af);
  font-variant-numeric: tabular-nums;
}
.shop-item .chip {
  margin-left: auto;
}

.builder {
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.85rem;
  background: var(--surface-1, #fff);
  min-height: 20rem;
}
.builder-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 0.7rem;
}
.builder-title {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}
.builder-title > i {
  font-size: 1.35rem;
  color: var(--safety-amber, #e8a33d);
  margin-top: 0.15rem;
}
.builder-title h2 {
  margin: 0;
  font-size: 1.05rem;
}
.builder-title p {
  margin: 0.1rem 0 0;
  font-size: 0.76rem;
  color: var(--muted, #6f7680);
  max-width: 34rem;
}
.builder-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.blank {
  text-align: center;
  padding: 2.4rem 1rem;
}
.blank i {
  font-size: 2rem;
  color: #d1d5db;
}
.blank h3 {
  margin: 0.6rem 0 0.3rem;
  font-size: 1rem;
}
.blank p {
  margin: 0 auto 1rem;
  max-width: 32rem;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--muted, #6f7680);
}
.blank-actions {
  display: flex;
  gap: 0.45rem;
  justify-content: center;
  flex-wrap: wrap;
}

.loading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem;
  justify-content: center;
  color: #6b7280;
}
.hint {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 0.7rem;
  font-size: 0.76rem;
  line-height: 1.45;
  color: var(--muted, #6f7680);
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
  flex-wrap: wrap;
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
.warn,
.note {
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
  margin: 0.3rem 0;
  font-size: 0.74rem;
  line-height: 1.45;
  color: var(--muted, #6b7280);
}
.warn {
  color: #92400e;
}
.tree {
  margin-top: 0.4rem;
  max-height: 56vh;
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
.footer {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.6rem;
  flex-wrap: wrap;
}
.empty {
  padding: 1.2rem;
  text-align: center;
  font-size: 0.8rem;
  color: var(--muted, #9ca3af);
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
</style>
