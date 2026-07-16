import { defineStore } from 'pinia'
import centralHttp from '../api/centralHttp'

export interface Operator {
  id: number
  name: string
  email: string
  is_active: boolean
  roles: string[]
  permissions: string[]
}

export const useCentralAuthStore = defineStore('centralAuth', {
  state: () => ({
    token: localStorage.getItem('sims_central_token') as string | null,
    operator: null as Operator | null,
    loading: false,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    isSuperAdmin: (state) => !!state.operator?.roles.includes('super-admin'),
    can: (state) => (permission: string) => {
      if (!state.operator) return false
      // super-admin implicitly holds everything.
      if (state.operator.roles.includes('super-admin')) return true
      return state.operator.permissions.includes(permission)
    },
  },

  actions: {
    async login(email: string, password: string) {
      const { data } = await centralHttp.post('/login', { email, password })
      this.token = data.token
      this.operator = data.user
      localStorage.setItem('sims_central_token', data.token)
    },

    async fetchMe() {
      if (!this.token) return
      const { data } = await centralHttp.get('/me')
      this.operator = data
    },

    async logout() {
      try {
        await centralHttp.post('/logout')
      } catch {
        // ignore network errors on logout
      }
      this.token = null
      this.operator = null
      localStorage.removeItem('sims_central_token')
    },
  },
})
