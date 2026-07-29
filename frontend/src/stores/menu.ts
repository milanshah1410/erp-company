import { defineStore } from 'pinia'
import http from '../api/http'
import { useAuthStore } from './auth'

/** localStorage namespace for the persisted menu (per user/company/branch). */
const CACHE_PREFIX = 'sims_menu_cache'

/** A clickable navigation leaf. */
export interface MenuLeaf {
  id?: number
  label: string
  icon: string | null
  to: string
  permission?: string | null
  is_favorite?: boolean
  children: MenuNode[]
}

/** A group/section header holding children (its `to` is null). */
export interface MenuNode {
  id?: number
  label: string
  icon: string | null
  to: string | null
  permission?: string | null
  is_favorite?: boolean
  children: MenuNode[]
}

/** The shape persisted to / read from localStorage. */
interface MenuPayload {
  menu: MenuNode[]
  favorites: MenuLeaf[]
  recents: MenuLeaf[]
  deniedRoutes: string[]
}

/**
 * Dynamic navigation (project-stack.md §3). The sidebar/top-menu, favorites and
 * recents all come from the backend `/menu` endpoint — nothing is hard-coded.
 */
export const useMenuStore = defineStore('menu', {
  state: () => ({
    menu: [] as MenuNode[],
    favorites: [] as MenuLeaf[],
    recents: [] as MenuLeaf[],
    // Catalogue screens this workspace may not open — the rows /menu filtered out
    // because the operator switched their module off or revoked them.
    deniedRoutes: [] as string[],
    loaded: false,
    loading: false,
    // Cache key for the context currently held in memory; used to detect a
    // user/company/branch switch and to write the correct localStorage entry.
    cacheKey: '' as string,
  }),

  getters: {
    /** Flat map of route → leaf, for resolving the active screen's label/icon. */
    leavesByRoute(state): Record<string, MenuNode> {
      const map: Record<string, MenuNode> = {}
      const walk = (nodes: MenuNode[]) => {
        for (const n of nodes) {
          if (n.to) map[n.to] = n
          if (n.children?.length) walk(n.children)
        }
      }
      walk(state.menu)
      return map
    },
    /**
     * Flat map of route → top-level module label ("Masters", "Sales", …), used
     * by PageHeader to show the module name above a screen's title without
     * every view hard-coding it.
     */
    moduleByRoute(state): Record<string, string> {
      const map: Record<string, string> = {}
      const walk = (nodes: MenuNode[], root: string) => {
        for (const n of nodes) {
          if (n.to) map[n.to] = root
          if (n.children?.length) walk(n.children, root)
        }
      }
      for (const top of state.menu) {
        if (top.to) map[top.to] = top.label
        walk(top.children ?? [], top.label)
      }
      return map
    },
    isFavorite(state) {
      return (id?: number) => !!id && state.favorites.some((f) => f.id === id)
    },
    /**
     * Is this path a screen the workspace is not granted?
     *
     * The menu is the authority: hiding a row from the nav has to mean the screen
     * cannot be opened at all, otherwise a typed URL, a bookmark or the back
     * button walks around it. Paths beneath a denied screen
     * (/sales-quotations/12) are denied with it.
     */
    isRouteDenied(state) {
      return (path: string) => {
        const clean = (path.split('?')[0].replace(/\/+$/, '') || '/').toLowerCase()

        return state.deniedRoutes.some((route) => {
          const r = route.toLowerCase()

          return clean === r || clean.startsWith(`${r}/`)
        })
      }
    },
  },

  actions: {
    /** localStorage key scoped to the current user + company + branch context. */
    _cacheKey(): string {
      const u = useAuthStore().user
      return `${CACHE_PREFIX}:${u?.id ?? 0}:${u?.current_company?.id ?? 0}:${u?.current_branch?.id ?? 0}`
    },

    /** Load the persisted copy for `key` into state. Returns true if it painted. */
    _hydrateFromCache(key: string): boolean {
      try {
        const raw = localStorage.getItem(key)
        if (!raw) return false
        const data = JSON.parse(raw) as MenuPayload
        // A copy written before per-screen denials existed carries no denied list,
        // and the router guard reads that list to refuse hidden screens. Treating
        // it as a miss costs one slower load after an upgrade; trusting it would
        // let a denied screen through until the background refresh landed.
        if (!Array.isArray(data.deniedRoutes)) return false

        this.menu = data.menu ?? []
        this.favorites = data.favorites ?? []
        this.recents = data.recents ?? []
        this.deniedRoutes = data.deniedRoutes
        return this.menu.length > 0
      } catch {
        return false
      }
    },

    /** Persist the current menu so the next page load can paint instantly. */
    _writeCache(): void {
      try {
        const payload: MenuPayload = {
          menu: this.menu,
          favorites: this.favorites,
          recents: this.recents,
          deniedRoutes: this.deniedRoutes,
        }
        localStorage.setItem(this.cacheKey || this._cacheKey(), JSON.stringify(payload))
      } catch {
        /* quota exceeded / storage disabled — the cache is best-effort */
      }
    },

    /**
     * Stale-while-revalidate. The `/menu` API can be slow, so instead of
     * blocking the sidebar on the network every page load, we paint instantly
     * from the last persisted copy and refresh in the background. A cached menu
     * survives full page reloads; the in-memory copy survives SPA navigation
     * (project-stack.md §3).
     */
    async fetchMenu(opts: { force?: boolean } = {}): Promise<void> {
      if (this.loading) return
      const key = this._cacheKey()

      // User/company/branch changed → discard the previous context's menu.
      if (key !== this.cacheKey) {
        this.cacheKey = key
        this.loaded = false
        this.menu = []
        this.favorites = []
        this.recents = []
        this.deniedRoutes = []
      }

      // Already have this context's menu in memory this session — done.
      if (this.loaded && !opts.force) return

      // Instant paint from the persisted copy while we revalidate over the wire.
      if (this._hydrateFromCache(key)) this.loaded = true

      this.loading = true
      try {
        const { data } = await http.get('/menu')
        this.menu = data.menu
        this.favorites = data.favorites
        this.recents = data.recents
        this.deniedRoutes = data.denied_routes ?? []
        this.loaded = true
        this._writeCache()
      } catch (e) {
        // API/network failure: keep whatever we hydrated from cache; only
        // surface the error if there was nothing to fall back on.
        if (!this.loaded) throw e
      } finally {
        this.loading = false
      }
    },

    async toggleFavorite(menuItemId: number) {
      const { data } = await http.post('/menu/favorites', { menu_item_id: menuItemId })
      this.favorites = data.favorites
      // Reflect the new state on the matching node so the star updates in place.
      const favIds = new Set(this.favorites.map((f) => f.id))
      const walk = (nodes: MenuNode[]) => {
        for (const n of nodes) {
          if (n.id) n.is_favorite = favIds.has(n.id)
          if (n.children?.length) walk(n.children)
        }
      }
      walk(this.menu)
      this._writeCache()
    },

    async trackRecent(route: string, label: string, icon: string | null) {
      try {
        const { data } = await http.post('/menu/recents', { route, label, icon })
        this.recents = data.recents
        this._writeCache()
      } catch {
        /* recents are best-effort; never block navigation */
      }
    },

    reset() {
      this.menu = []
      this.favorites = []
      this.recents = []
      this.deniedRoutes = []
      this.loaded = false
      this.cacheKey = ''
    },
  },
})
