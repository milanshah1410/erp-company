<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useMenuStore, type MenuNode } from '../stores/menu'

/**
 * One node inside the top-menu flyout. Renders recursively so the menu can be
 * arbitrarily deep (Masters → Products → Barcode → Barcode Scan): a leaf (has a
 * route) becomes a link with a favourite star; a group (no route, has children)
 * opens a cascading submenu panel on hover. The panel flies out to the RIGHT of
 * the parent menu and flips to the left only when the viewport edge leaves no
 * room; sub-sub-groups cascade the same way. Click toggles too, for touch
 * devices where hover isn't available.
 *
 * Panels use position:fixed so the parent flyout's overflow-y scroll never
 * clips them; because they stay DOM descendants of the row, the top-level
 * flyout's mouseenter/mouseleave grace period keeps the whole cascade open
 * while the pointer travels across the small gap between panels.
 */
const props = defineProps<{ node: MenuNode; depth: number }>()
const emit = defineEmits<{ (e: 'navigate'): void }>()

const route = useRoute()
const menuStore = useMenuStore()

function isGroup(n: MenuNode): boolean {
  return n.to === null && Array.isArray(n.children) && n.children.length > 0
}

// A group is "active" when it holds the currently-open route anywhere below it.
function containsActiveRoute(n: MenuNode): boolean {
  if (n.to && (route.path === n.to || route.path.startsWith(n.to + '/'))) return true
  return (n.children ?? []).some(containsActiveRoute)
}

// --- Cascading submenu state ---
const open = ref(false)
const side = ref<'left' | 'right'>('right')
const panelStyle = ref<Record<string, string>>({})
const panelEl = ref<HTMLElement | null>(null)

// The popup is position:fixed, so scrolling the parent panel (or the page)
// would leave it floating at a stale spot. Close it on any outside scroll;
// scrolling INSIDE this popup (or a nested one, which is a DOM descendant)
// is fine and keeps the cascade open.
function onAnyScroll(e: Event) {
  const t = e.target as Node | null
  if (panelEl.value && t instanceof Node && panelEl.value.contains(t)) return
  cancelClose()
  open.value = false
}

watch(open, (isOpen) => {
  if (isOpen) window.addEventListener('scroll', onAnyScroll, true)
  else window.removeEventListener('scroll', onAnyScroll, true)
})
onBeforeUnmount(() => window.removeEventListener('scroll', onAnyScroll, true))

// Small delay so the mouse can travel from the row into the submenu panel
// (across the gap between panels) without the cascade collapsing.
let closeTimer: ReturnType<typeof setTimeout> | null = null

function cancelClose() {
  if (closeTimer) {
    clearTimeout(closeTimer)
    closeTimer = null
  }
}

function scheduleClose() {
  cancelClose()
  closeTimer = setTimeout(() => {
    open.value = false
    closeTimer = null
  }, 200)
}

const PANEL_WIDTH = 240

function openSubmenu(e: MouseEvent) {
  cancelClose()
  const row = e.currentTarget as HTMLElement
  const rect = row.getBoundingClientRect()
  // Join the popup to the parent panel's OUTER edge (rows sit inside the
  // panel's padding, so the row rect alone would leave the panels overlapping).
  const panelRect = row.closest('.flyout-panel, .nav-flyout')?.getBoundingClientRect() ?? rect
  const style: Record<string, string> = {}

  // Prefer the right side of the parent panel; flip left at the screen edge.
  // NOTE: CSS `right` on a fixed element measures from the layout viewport,
  // which EXCLUDES the page scrollbar — window.innerWidth includes it and
  // would leave a scrollbar-wide gap, so use clientWidth. The 1px overlap
  // keeps the two panels reading as one joined surface.
  const viewportWidth = document.documentElement.clientWidth
  if (panelRect.right + PANEL_WIDTH <= viewportWidth - 8) {
    side.value = 'right'
    style.left = `${panelRect.right - 1}px`
  } else {
    side.value = 'left'
    style.right = `${viewportWidth - panelRect.left - 1}px`
  }

  // Align with the row, clamped so a long panel never starts off-screen.
  const top = Math.max(8, Math.min(rect.top - 6, window.innerHeight - 64))
  style.top = `${top}px`
  style.maxHeight = `calc(100vh - ${top + 12}px)`

  panelStyle.value = style
  open.value = true
}

// A modified click (Ctrl/Cmd/Shift/Alt or non-left button) opens the link in
// another tab/window — the browser handles it and this tab doesn't navigate,
// so the menu must stay open. Only a plain left-click closes the cascade.
function onLeafClick(e: MouseEvent) {
  if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return
  emit('navigate')
}

function toggleSubmenu(e: MouseEvent) {
  if (open.value) {
    cancelClose()
    open.value = false
    return
  }
  openSubmenu(e)
}

async function toggleFavorite(e: MouseEvent, item: MenuNode) {
  e.preventDefault()
  e.stopPropagation()
  if (item.id) await menuStore.toggleFavorite(item.id)
}
</script>

<template>
  <!-- Leaf: a clickable screen -->
  <div v-if="!isGroup(node)" class="flyout-row">
    <RouterLink :to="node.to!" class="flyout-item" @click="onLeafClick">
      <i :class="node.icon" />
      <span>{{ node.label }}</span>
    </RouterLink>
    <button
      v-if="node.id"
      class="fav-toggle"
      :class="{ on: menuStore.isFavorite(node.id) }"
      :title="menuStore.isFavorite(node.id) ? 'Remove from favorites' : 'Add to favorites'"
      @click="toggleFavorite($event, node)"
    >
      <i :class="menuStore.isFavorite(node.id) ? 'pi pi-star-fill' : 'pi pi-star'" />
    </button>
  </div>

  <!-- Group: opens its children in a side panel on hover -->
  <div v-else class="flyout-group" @mouseenter="cancelClose" @mouseleave="scheduleClose">
    <button
      class="flyout-group-head"
      :class="{ open, active: containsActiveRoute(node) }"
      @mouseenter="openSubmenu"
      @click="toggleSubmenu"
    >
      <i :class="node.icon" class="group-icon" />
      <span>{{ node.label }}</span>
      <i :class="side === 'left' && open ? 'pi pi-chevron-left' : 'pi pi-chevron-right'" class="chev" />
    </button>
    <div v-if="open" ref="panelEl" class="flyout-panel" :class="side === 'right' ? 'join-left' : 'join-right'" :style="panelStyle">
      <div class="flyout-panel-title">{{ node.label }}</div>
      <NavFlyoutNode
        v-for="child in node.children"
        :key="child.id ?? child.to ?? child.label"
        :node="child"
        :depth="depth + 1"
        @navigate="emit('navigate')"
      />
    </div>
  </div>
</template>

<style scoped>
.flyout-row {
  display: flex;
  align-items: center;
}
.flyout-item {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.75rem;
  color: #b6bfc9;
  text-decoration: none;
  font-size: 0.85rem;
  white-space: nowrap;
  flex: 1;
  transition: background 0.15s, color 0.15s, box-shadow 0.15s, padding-left 0.15s;
}
.flyout-item i {
  width: 1.1rem;
  text-align: center;
  color: var(--safety-amber, #e8a33d);
  transition: transform 0.15s;
}
.flyout-item:hover {
  background: linear-gradient(90deg, rgba(232, 163, 61, 0.16), rgba(232, 163, 61, 0.04));
  color: #fff;
  box-shadow: inset 3px 0 0 var(--safety-amber, #e8a33d);
  padding-left: 0.9rem;
}
.flyout-item:hover i {
  transform: scale(1.12);
}
.flyout-item.router-link-exact-active {
  color: var(--safety-amber, #e8a33d);
  background: rgba(232, 163, 61, 0.12);
  box-shadow: inset 3px 0 0 var(--safety-amber, #e8a33d);
  font-weight: 600;
}
.fav-toggle {
  background: none;
  border: none;
  cursor: pointer;
  color: #5f6b76;
  padding: 0.35rem;
  border-radius: 6px;
  font-size: 0.8rem;
  opacity: 0;
  transition: opacity 0.15s, color 0.15s;
}
.flyout-row:hover .fav-toggle {
  opacity: 1;
}
.fav-toggle:hover {
  color: var(--safety-amber, #e8a33d);
}
.fav-toggle.on {
  opacity: 1;
  color: var(--safety-amber, #e8a33d);
}

/* Group header row */
.flyout-group-head {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  padding: 0.55rem 0.75rem;
  border: none;
  background: none;
  color: #d3dae1;
  font-family: inherit;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s, color 0.15s, box-shadow 0.15s, padding-left 0.15s;
}
.flyout-group-head:hover,
.flyout-group-head.open {
  background: linear-gradient(90deg, rgba(232, 163, 61, 0.16), rgba(232, 163, 61, 0.04));
  color: #fff;
  box-shadow: inset 3px 0 0 var(--safety-amber, #e8a33d);
  padding-left: 0.9rem;
}
.flyout-group-head.active {
  color: var(--safety-amber, #e8a33d);
}
.flyout-group-head .group-icon {
  width: 1.1rem;
  text-align: center;
  font-size: 0.9rem;
  color: var(--safety-amber, #e8a33d);
  transition: transform 0.15s;
}
.flyout-group-head:hover .group-icon {
  transform: scale(1.12);
}
.flyout-group-head .chev {
  margin-left: auto;
  font-size: 0.62rem;
  opacity: 0.7;
  transition: transform 0.15s, opacity 0.15s;
}
.flyout-group-head:hover .chev,
.flyout-group-head.open .chev {
  opacity: 1;
  transform: translateX(2px);
  color: var(--safety-amber, #e8a33d);
}

/* Cascading submenu panel — mirrors .nav-flyout in AppLayout.vue */
.flyout-panel {
  position: fixed;
  min-width: 220px;
  max-width: 300px;
  overflow-y: auto;
  overflow-x: hidden;
  /* NOTE: no backdrop-filter here — it would turn this panel into the
     containing block for its fixed-position sub-panels, which then get
     clipped by this panel's overflow and never show up. */
  background: linear-gradient(180deg, rgba(38, 45, 52, 0.98), rgba(30, 36, 42, 0.98));
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 0;
  padding: 0.5rem;
  box-shadow: 0 18px 42px rgba(0, 0, 0, 0.5), 0 3px 10px rgba(0, 0, 0, 0.35);
  z-index: 50;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
/* Slide in from the parent panel's edge, matching the side it opened on.
   Animated via margin (not transform) — a transform would briefly make this
   panel the containing block for its own fixed-position sub-panels. */
.flyout-panel.join-left {
  animation: panel-in-right 0.16s ease-out;
}
.flyout-panel.join-right {
  animation: panel-in-left 0.16s ease-out;
}
@keyframes panel-in-right {
  from { opacity: 0; margin-left: -8px; }
  to { opacity: 1; margin-left: 0; }
}
@keyframes panel-in-left {
  from { opacity: 0; margin-right: -8px; }
  to { opacity: 1; margin-right: 0; }
}
/* ONLY the edge that touches the parent panel loses its border, so the two
   popups join into one surface; the other sides keep the border. */
.flyout-panel.join-left {
  border-left: 0;
}
.flyout-panel.join-right {
  border-right: 0;
}
.flyout-panel::-webkit-scrollbar {
  width: 6px;
}
.flyout-panel::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
.flyout-panel-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #8b96a2;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 0.35rem 0.75rem 0.5rem;
  margin-bottom: 0.25rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.flyout-panel-title::before {
  content: '';
  width: 14px;
  height: 2px;
  background: var(--safety-amber, #e8a33d);
  flex-shrink: 0;
}
</style>
