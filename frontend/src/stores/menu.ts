import { defineStore } from 'pinia'
import http from '../api/http'

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
    async fetchMenu() {
      if (this.loading) return
      this.loading = true
      try {
        const { data } = await http.get('/menu')
        this.menu = data.menu
        this.favorites = data.favorites
        this.recents = data.recents
        this.loaded = true
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
    },

    async trackRecent(route: string, label: string, icon: string | null) {
      try {
        const { data } = await http.post('/menu/recents', { route, label, icon })
        this.recents = data.recents
      } catch {
        /* recents are best-effort; never block navigation */
      }
    },

    reset() {
      this.menu = []
      this.favorites = []
      this.recents = []
      this.loaded = false
    },
  },
})
