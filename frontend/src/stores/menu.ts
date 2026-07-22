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
    isFavorite(state) {
      return (id?: number) => !!id && state.favorites.some((f) => f.id === id)
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
        this.menu = data.menu ?? []
        this.favorites = data.favorites ?? []
        this.recents = data.recents ?? []
        return this.menu.length > 0
      } catch {
        return false
      }
    },

    /** Persist the current menu so the next page load can paint instantly. */
    _writeCache(): void {
      try {
        const payload: MenuPayload = { menu: this.menu, favorites: this.favorites, recents: this.recents }
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
      this.loaded = false
      this.cacheKey = ''
    },
  },
})
