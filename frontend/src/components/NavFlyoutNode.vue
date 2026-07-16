<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { useMenuStore, type MenuNode } from '../stores/menu'

/**
 * One node inside the top-menu flyout. Renders recursively so the menu can be
 * arbitrarily deep (Masters → Products → Barcode → Barcode Scan): a leaf (has a
 * route) becomes a link with a favourite star; a group (no route, has children)
 * becomes a collapsible accordion section holding more NavFlyoutNodes.
 *
 * `depth` drives indentation and the default open state — the first level of
 * sub-groups inside a flyout starts expanded so the common items are one glance
 * away; anything deeper starts collapsed to keep the panel tidy.
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

const open = ref(props.depth === 0 || containsActiveRoute(props.node))

async function toggleFavorite(e: MouseEvent, item: MenuNode) {
  e.preventDefault()
  e.stopPropagation()
  if (item.id) await menuStore.toggleFavorite(item.id)
}
</script>

<template>
  <!-- Leaf: a clickable screen -->
  <div v-if="!isGroup(node)" class="flyout-row" :style="{ paddingLeft: depth * 0.75 + 'rem' }">
    <RouterLink :to="node.to!" class="flyout-item" @click="emit('navigate')">
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

  <!-- Group: a collapsible section -->
  <div v-else class="flyout-group">
    <button
      class="flyout-group-head"
      :class="{ open }"
      :style="{ paddingLeft: depth * 0.75 + 0.6 + 'rem' }"
      @click="open = !open"
    >
      <i :class="node.icon" class="group-icon" />
      <span>{{ node.label }}</span>
      <i class="pi pi-chevron-down chev" />
    </button>
    <div v-show="open" class="flyout-group-body">
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
  gap: 0.6rem;
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  color: #a9b2bc;
  text-decoration: none;
  font-size: 0.85rem;
  white-space: nowrap;
  flex: 1;
}
.flyout-item i {
  width: 1.1rem;
  text-align: center;
}
.flyout-item:hover {
  background: rgba(61, 99, 124, 0.3);
  color: #fff;
}
.flyout-item.router-link-exact-active {
  color: var(--safety-amber, #e8a33d);
  background: rgba(232, 163, 61, 0.12);
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

/* Group header */
.flyout-group-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: none;
  background: none;
  color: #cdd4db;
  font-family: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  cursor: pointer;
  border-radius: 6px;
}
.flyout-group-head:hover {
  background: rgba(255, 255, 255, 0.05);
  color: #fff;
}
.flyout-group-head .group-icon {
  width: 1.1rem;
  text-align: center;
  font-size: 0.9rem;
  color: var(--safety-amber, #e8a33d);
}
.flyout-group-head .chev {
  margin-left: auto;
  font-size: 0.62rem;
  opacity: 0.7;
  transition: transform 0.15s;
}
.flyout-group-head.open .chev {
  transform: rotate(180deg);
}
.flyout-group-body {
  display: flex;
  flex-direction: column;
  gap: 1px;
  border-left: 1px solid rgba(255, 255, 255, 0.06);
  margin-left: 0.85rem;
}
</style>
