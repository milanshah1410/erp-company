<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useMenuStore, type MenuNode } from '../stores/menu'
import { useTheme } from '../composables/useTheme'
import { SUPPORTED_LOCALES, setLocale, type AppLocale } from '../i18n'
import { useMenuLabel } from '../i18n/menuLabels'
import http from '../api/http'
import NotificationBell from '../components/NotificationBell.vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import NavFlyoutNode from '../components/NavFlyoutNode.vue'
import BrandLogo from '../components/BrandLogo.vue'
import { useBranding } from '../composables/useBranding'

// Subscription banner (trial countdown / suspended read-only notice).
const subscription = ref<{ status: string; on_trial: boolean; trial_days_left: number } | null>(null)
onMounted(async () => {
  try {
    const { data } = await http.get('/subscription')
    subscription.value = data.data
  } catch {
    /* non-blocking */
  }
})

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { isDark, toggle: toggleTheme } = useTheme()
// White label: a branded workspace wears its own mark + name in the top bar.
const { isWhiteLabelled } = useBranding()

// Initials stand in for a missing profile photo in the top bar.
const userInitials = computed(() =>
  (auth.user?.name || '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join(''),
)

// --- UI language (en / hi / gu) ---
const { t, locale } = useI18n()
const tMenu = useMenuLabel()
const localeOptions = SUPPORTED_LOCALES
const currentLocale = ref<AppLocale>(locale.value as AppLocale)
function onLocaleChange(code: AppLocale) {
  setLocale(code)
}

// --- Company / branch context switcher ---
const switchingCompany = ref<number | null>(auth.user?.current_company?.id ?? null)
const switchingBranch = ref<number | null>(auth.user?.current_branch?.id ?? null)

const branchOptionsForCompany = computed(() =>
  (auth.user?.branches ?? []).filter((b) => b.company_id === switchingCompany.value),
)

async function onCompanyChange(companyId: number) {
  switchingBranch.value = null
  await applyContext(companyId, null)
}

async function onBranchChange(branchId: number | null) {
  if (!switchingCompany.value) return
  await applyContext(switchingCompany.value, branchId)
}

async function applyContext(companyId: number, branchId: number | null) {
  try {
    await auth.switchContext(companyId, branchId)
    switchingCompany.value = auth.user?.current_company?.id ?? null
    switchingBranch.value = auth.user?.current_branch?.id ?? null
    // The menu is filtered per company/branch (roles + plan features), so the
    // context switch changes the cache key and reloads the correct menu.
    await menuStore.fetchMenu()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: t('layout.couldNotSwitch'), detail: e.response?.data?.message || t('layout.unexpectedError'), life: 4000 })
  }
}

// Navigation is fully database-driven (project-stack.md §3). The backend
// already filters it for this user by role (permission) and subscription
// (plan feature); the store just holds what it returns.
const menuStore = useMenuStore()

onMounted(() => {
  // Paints instantly from the localStorage cache, then revalidates in the
  // background; a no-op if the menu is already in memory this session.
  menuStore.fetchMenu()
})

// A group has no route of its own and carries children.
function isGroup(entry: MenuNode): boolean {
  return entry.to === null && Array.isArray(entry.children) && entry.children.length > 0
}

// Dashboard + the virtual Favorites group are pinned to the left as
// static, icon-only buttons (label hidden, hover tooltip); the rest of the menu
// scrolls horizontally beside them.
// 'Recent' was dropped from the nav — keep the label here if it is ever restored.
const PINNED_LABELS = new Set(['Favorites' /*, 'Recent' */])
function isPinned(entry: MenuNode): boolean {
  return entry.to === '/' || PINNED_LABELS.has(entry.label)
}
const pinnedNav = computed(() => nav.value.filter(isPinned))
const scrollNav = computed(() => nav.value.filter((e) => !isPinned(e)))

// Favorites is surfaced as a virtual group (project-stack.md §3), inserted
// right after Dashboard when non-empty. The Recent group is disabled.
const nav = computed<MenuNode[]>(() => {
  const base = [...menuStore.menu]
  const extras: MenuNode[] = []
  if (menuStore.favorites.length) {
    extras.push({ label: 'Favorites', icon: 'pi pi-star-fill', to: null, children: menuStore.favorites })
  }
  // Recent menu group disabled — the backend still returns recents, we just
  // no longer render them.
  // if (menuStore.recents.length) {
  //   extras.push({ label: 'Recent', icon: 'pi pi-clock', to: null, children: menuStore.recents })
  // }
  if (base.length && base[0].to === '/') {
    return [base[0], ...extras, ...base.slice(1)]
  }
  return [...extras, ...base]
})

// --- Top-menu dropdown state ---
// A single flyout, teleported to <body> and positioned below the clicked group,
// so the horizontally-scrolling menu bar never clips it.
const route = useRoute()
const openGroup = ref<string | null>(null)
const flyoutPos = ref<{ top: number; left: number } | null>(null)
const flyoutEl = ref<HTMLElement | null>(null)

const openChildren = computed<MenuNode[]>(() => {
  const entry = nav.value.find((e) => isGroup(e) && e.label === openGroup.value)
  return entry ? entry.children : []
})


// Small delay so the mouse can travel from the menu button into the flyout
// without the dropdown closing in the gap.
let closeTimer: ReturnType<typeof setTimeout> | null = null

function cancelClose() {
  if (closeTimer) {
    clearTimeout(closeTimer)
    closeTimer = null
  }
}

function openGroupHover(e: MouseEvent, label: string) {
  cancelClose()
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  // Clamp so the dropdown (and the submenus cascading from it) never opens
  // off the right edge of the viewport (clientWidth: excludes the scrollbar).
  const left = Math.max(8, Math.min(rect.left, document.documentElement.clientWidth - 260))
  flyoutPos.value = { top: rect.bottom, left }
  openGroup.value = label
}

// Click toggles too, for touch devices where hover isn't available.
function toggleGroup(e: MouseEvent, label: string) {
  if (openGroup.value === label) {
    closeMenu()
    return
  }
  openGroupHover(e, label)
}

function scheduleClose() {
  cancelClose()
  closeTimer = setTimeout(() => {
    openGroup.value = null
    closeTimer = null
  }, 150)
}

function closeMenu() {
  cancelClose()
  openGroup.value = null
}

// A modified click (Ctrl/Cmd/Shift/Alt or non-left button) opens the link in
// another tab/window — this tab doesn't navigate, so keep the menu open.
function onNavLinkClick(e: MouseEvent) {
  if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return
  closeMenu()
}

// A top-level group is active when the current route lives anywhere in its
// subtree — the tree is now several levels deep, so this recurses.
function isGroupActive(entry: MenuNode): boolean {
  const hit = (n: MenuNode): boolean => {
    if (n.to && (route.path === n.to || route.path.startsWith(n.to + '/'))) return true
    return (n.children ?? []).some(hit)
  }
  return (entry.children ?? []).some(hit)
}

// Close the dropdown whenever the route changes (e.g. after a click).
// Recent-screen tracking is disabled along with the Recent menu group.
watch(
  () => route.path,
  () => {
    closeMenu()
    // const leaf = menuStore.leavesByRoute[path]
    // if (leaf) menuStore.trackRecent(path, leaf.label, leaf.icon)
  },
)

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') closeMenu()
}

// The dropdown is position:fixed, so scrolling the page or the menu bar moves
// its anchor button out from under it. Close it on any outside scroll —
// scrolling inside the dropdown itself (or a cascading sub-panel, which is a
// DOM descendant of it) stays open.
function onAnyScroll(e: Event) {
  if (!openGroup.value) return
  const t = e.target as Node | null
  if (flyoutEl.value && t instanceof Node && flyoutEl.value.contains(t)) return
  closeMenu()
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onAnyScroll, true)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onAnyScroll, true)
})

async function logout() {
  await auth.logout()
  menuStore.reset()
  router.push('/login')
}
</script>

<template>
  <div class="layout">
    <!-- Brand + context/user row -->
    <header class="topbar">
      <!-- The workspace's own logo + name when it has been white labelled,
           the platform mark otherwise (components/BrandLogo.vue). -->
      <RouterLink to="/" class="logo">
        <BrandLogo :height="26" variant="mark" with-name />
        <span v-if="!isWhiteLabelled">kosha</span>
      </RouterLink>
      <GlobalSearch />
      <div class="spacer" />
      <div v-if="auth.user?.companies?.length" class="context-switcher">
        <Select
          v-model="switchingCompany"
          :options="auth.user.companies"
          optionLabel="name"
          optionValue="id"
          :placeholder="t('layout.company')"
          class="context-select"
          @update:modelValue="onCompanyChange"
        />
        <Select
          v-if="branchOptionsForCompany.length"
          v-model="switchingBranch"
          :options="branchOptionsForCompany"
          optionLabel="name"
          optionValue="id"
          :placeholder="t('layout.branch')"
          showClear
          class="context-select"
          @update:modelValue="onBranchChange"
        />
      </div>
      <div class="lang-switcher" :title="t('common.language')">
        <i class="pi pi-globe" />
        <Select
          v-model="currentLocale"
          :options="localeOptions"
          optionLabel="label"
          optionValue="code"
          class="lang-select"
          @update:modelValue="onLocaleChange"
        />
      </div>
      <button
        class="theme-toggle"
        :title="isDark ? t('layout.switchToLight') : t('layout.switchToDark')"
        @click="toggleTheme"
      >
        <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" />
      </button>
      <NotificationBell />
      <div class="user">
        <!-- Avatar + name open the profile screen (name/phone/photo/password). -->
        <RouterLink to="/profile" class="user-link" :title="t('layout.profile')">
          <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="user-avatar" />
          <span v-else class="user-avatar user-avatar-fallback">{{ userInitials }}</span>
          <span class="user-info">
            <span class="user-name">{{ auth.user?.name }}</span>
            <span class="user-role">{{ auth.user?.roles?.[0] }}</span>
          </span>
        </RouterLink>
        <button class="logout" @click="logout" :title="t('layout.logout')"><i class="pi pi-sign-out" /></button>
      </div>
    </header>

    <!-- Horizontal top menu bar -->
    <nav class="topnav">
      <!-- Pinned, static, icon-only cluster: Dashboard / Favorites. -->
      <div class="topnav-pinned">
        <template v-for="entry in pinnedNav" :key="entry.label">
          <RouterLink
            v-if="!isGroup(entry)"
            :to="entry.to!"
            class="topnav-item icon-only"
            :title="tMenu(entry.label)"
            @click="onNavLinkClick"
          >
            <i :class="entry.icon" />
          </RouterLink>

          <button
            v-else
            class="topnav-item icon-only"
            :class="{ active: isGroupActive(entry), open: openGroup === entry.label }"
            :title="tMenu(entry.label)"
            @mouseenter="openGroupHover($event, entry.label)"
            @mouseleave="scheduleClose"
            @click="toggleGroup($event, entry.label)"
          >
            <i :class="entry.icon" />
          </button>
        </template>
      </div>

      <!-- Scrolling cluster: the workspace groups (icon + label). -->
      <div class="topnav-scroll">
        <template v-for="entry in scrollNav" :key="entry.label">
          <RouterLink v-if="!isGroup(entry)" :to="entry.to!" class="topnav-item" @click="onNavLinkClick">
            <i :class="entry.icon" />
            <span>{{ tMenu(entry.label) }}</span>
          </RouterLink>

          <button
            v-else
            class="topnav-item"
            :class="{ active: isGroupActive(entry), open: openGroup === entry.label }"
            @mouseenter="openGroupHover($event, entry.label)"
            @mouseleave="scheduleClose"
            @click="toggleGroup($event, entry.label)"
          >
            <i :class="entry.icon" />
            <span>{{ tMenu(entry.label) }}</span>
            <i class="pi pi-chevron-down chev" />
          </button>
        </template>
      </div>
    </nav>

    <!-- Dropdown flyout for the open group -->
    <Teleport to="body">
      <div
        v-if="openGroup && flyoutPos"
        ref="flyoutEl"
        class="nav-flyout"
        :style="{ top: flyoutPos.top + 'px', left: flyoutPos.left + 'px' }"
        @mouseenter="cancelClose"
        @mouseleave="scheduleClose"
      >
        <div class="flyout-title">{{ tMenu(openGroup) }}</div>
        <NavFlyoutNode
          v-for="child in openChildren"
          :key="child.id ?? child.to ?? child.label"
          :node="child"
          :depth="0"
          @navigate="closeMenu"
        />
      </div>
    </Teleport>

    <div class="main">
      <div
        v-if="subscription && (subscription.on_trial || subscription.status === 'suspended')"
        class="sub-banner"
        :class="subscription.status === 'suspended' ? 'danger' : 'trial'"
      >
        <i :class="subscription.status === 'suspended' ? 'pi pi-lock' : 'pi pi-clock'" />
        <span v-if="subscription.status === 'suspended'">
          {{ t('layout.suspendedBanner') }}
        </span>
        <span v-else>
          {{ t('layout.trialBanner') }} · <strong>{{ t('layout.daysLeft', { n: subscription.trial_days_left }) }}</strong>
        </span>
        <RouterLink to="/billing" class="banner-cta">{{ t('layout.choosePlan') }}</RouterLink>
      </div>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--paper, #f7f5f0);
}

/* --- Brand / context row --- */
.topbar {
  height: 60px;
  background: linear-gradient(180deg, #23292f 0%, #1e2328 100%);
  display: flex;
  align-items: center;
  padding: 0 1.25rem;
  gap: 0.5rem;
  flex-shrink: 0;
  box-shadow: 0 1px 8px rgba(0, 0, 0, 0.15);
}
.logo {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.3rem;
  font-weight: 700;
  color: #fff;
  text-decoration: none;
}
.logo span {
  letter-spacing: -0.01em;
}
.spacer {
  flex: 1;
}
.context-switcher {
  display: flex;
  gap: 0.5rem;
  margin-right: 1rem;
}
.context-select {
  width: 10rem;
}
.context-select :deep(.p-select) {
  font-size: 0.85rem;
}
.topbar :deep(.bell-wrap) {
  margin-right: 1rem;
}
.user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.user-link {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.25rem 0.4rem 0.25rem 0.25rem;
  border-radius: 999px;
  text-decoration: none;
  transition: background 0.15s;
}
.user-link:hover {
  background: rgba(255, 255, 255, 0.08);
}
.user-avatar {
  width: 32px;
  height: 32px;
  flex: 0 0 32px;
  border-radius: 50%;
  object-fit: cover;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.user-avatar-fallback {
  font-size: 0.78rem;
  font-weight: 700;
  color: #23292f;
  background: linear-gradient(135deg, var(--safety-amber-light, #f2c274), var(--safety-amber, #e8a33d));
}
.user-info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.2;
}
.user-name {
  font-weight: 600;
  font-size: 0.9rem;
  color: #fff;
}
.user-role {
  font-size: 0.75rem;
  color: var(--safety-amber, #e8a33d);
  font-weight: 600;
  text-transform: capitalize;
}
.logout {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  color: #a9b2bc;
  padding: 0.4rem;
  border-radius: 6px;
}
.logout:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}
.lang-switcher {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-right: 0.75rem;
}
.lang-switcher > i {
  color: #a9b2bc;
  font-size: 1rem;
}
.lang-select {
  width: 7.5rem;
}
.lang-select :deep(.p-select-label) {
  font-size: 0.85rem;
  padding-top: 0.35rem;
  padding-bottom: 0.35rem;
}
.theme-toggle {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.05rem;
  color: #a9b2bc;
  padding: 0.4rem;
  border-radius: 6px;
  margin-right: 0.75rem;
}
.theme-toggle:hover {
  background: rgba(255, 255, 255, 0.1);
  color: var(--safety-amber, #e8a33d);
}

/* --- Horizontal menu bar --- */
.topnav {
  display: flex;
  align-items: stretch;
  padding: 0 0.75rem;
  background: linear-gradient(180deg, #2b333a 0%, #262c32 100%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 30;
}
/* Static, non-scrolling cluster (Dashboard / Favorites), always
   visible on the left with a divider between it and the scrolling menu. */
.topnav-pinned {
  display: flex;
  align-items: stretch;
  gap: 2px;
  flex-shrink: 0;
  padding-right: 6px;
  margin-right: 4px;
  border-right: 1px solid rgba(255, 255, 255, 0.08);
}
/* The workspace groups scroll horizontally beside the pinned cluster. */
.topnav-scroll {
  display: flex;
  align-items: stretch;
  gap: 2px;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
}
.topnav-scroll::-webkit-scrollbar {
  height: 5px;
}
.topnav-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.topnav-scroll::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
/* Icon-only pinned items: hide the label, center the glyph. */
.topnav-item.icon-only {
  padding: 0.7rem 0.8rem;
  justify-content: center;
}
.topnav-item {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.7rem 0.9rem;
  border: none;
  border-bottom: 3px solid transparent;
  background: none;
  color: #a9b2bc;
  font-size: 0.88rem;
  font-family: inherit;
  white-space: nowrap;
  cursor: pointer;
  text-decoration: none;
  transition: background 0.15s, color 0.15s;
}
.topnav-item i:first-child {
  font-size: 0.95rem;
  color: var(--safety-amber, #e8a33d);
}
.topnav-item .chev {
  font-size: 0.65rem;
  opacity: 0.7;
  transition: transform 0.15s;
}
.topnav-item.open .chev {
  transform: rotate(180deg);
}
.topnav-item:hover {
  background: linear-gradient(180deg, rgba(232, 163, 61, 0.14), rgba(232, 163, 61, 0.04));
  color: #fff;
  border-bottom-color: rgba(232, 163, 61, 0.45);
}
.topnav-item.open {
  background: linear-gradient(180deg, rgba(232, 163, 61, 0.2), rgba(232, 163, 61, 0.06));
  color: #fff;
  border-bottom-color: var(--safety-amber, #e8a33d);
}
.topnav-item.active,
.topnav-item.router-link-exact-active {
  color: #fff;
  border-bottom-color: var(--safety-amber, #e8a33d);
}
.topnav-item.active i:first-child,
.topnav-item.router-link-exact-active i:first-child {
  color: var(--safety-amber, #e8a33d);
}

/* --- Dropdown flyout --- */
.nav-flyout {
  position: fixed;
  min-width: 220px;
  max-height: calc(100vh - 130px);
  overflow-y: auto;
  /* NOTE: no backdrop-filter here — it would turn this dropdown into the
     containing block for the fixed-position submenu panels nested inside it,
     which then get clipped by this panel's overflow and never show up. */
  background: linear-gradient(180deg, rgba(38, 45, 52, 0.98), rgba(30, 36, 42, 0.98));
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-top: 2px solid var(--safety-amber, #e8a33d);
  border-radius: 0;
  padding: 0.5rem;
  box-shadow: 0 18px 42px rgba(0, 0, 0, 0.5), 0 3px 10px rgba(0, 0, 0, 0.35);
  z-index: 41;
  display: flex;
  flex-direction: column;
  gap: 2px;
  animation: flyout-drop 0.16s ease-out;
}
/* Margin-based (not transform) so the dropdown never becomes the containing
   block for the fixed-position submenu panels nested inside it. */
@keyframes flyout-drop {
  from { opacity: 0; margin-top: -6px; }
  to { opacity: 1; margin-top: 0; }
}
.nav-flyout::-webkit-scrollbar {
  width: 6px;
}
.nav-flyout::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
.flyout-title {
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
.flyout-title::before {
  content: '';
  width: 14px;
  height: 2px;
  background: var(--safety-amber, #e8a33d);
  flex-shrink: 0;
}
/* Flyout body rows/links now live in NavFlyoutNode.vue. */

/* --- Content --- */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.content {
  padding: 1.5rem;
  flex: 1;
}
.sub-banner {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 1.25rem;
  font-size: 0.87rem;
}
.sub-banner.trial {
  background: rgba(232, 163, 61, 0.14);
  color: #8a5a12;
  border-bottom: 1px solid rgba(232, 163, 61, 0.3);
}
.sub-banner.danger {
  background: rgba(193, 80, 46, 0.12);
  color: #a03d1f;
  border-bottom: 1px solid rgba(193, 80, 46, 0.3);
}
.banner-cta {
  margin-left: auto;
  font-weight: 700;
  text-decoration: none;
  color: inherit;
}
</style>
