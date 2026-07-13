import { defineStore } from 'pinia'
import http from '../api/http'

interface CompanyRef {
  id: number
  name: string
}

interface BranchRef {
  id: number
  name: string
  company_id: number
}

interface CurrentCompany extends CompanyRef {
  currency_code: string
  currency_symbol: string
  financial_year_start_month: number
}

interface User {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
  current_company: CurrentCompany | null
  current_branch: CompanyRef | null
  companies: CompanyRef[]
  branches: BranchRef[]
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('sims_token') as string | null,
    user: null as User | null,
    loading: false,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    can: (state) => (permission: string) => {
      if (!state.user) return false
      // super-admin / admin implicitly have everything
      if (state.user.roles.includes('super-admin') || state.user.roles.includes('admin')) {
        return true
      }
      return state.user.permissions.includes(permission)
    },
    currencySymbol: (state) => state.user?.current_company?.currency_symbol ?? '₹',
    currencyCode: (state) => state.user?.current_company?.currency_code ?? 'INR',
  },

  actions: {
    async login(email: string, password: string) {
      const { data } = await http.post('/login', { email, password })
      this.token = data.token
      this.user = data.user
      localStorage.setItem('sims_token', data.token)
    },

    async fetchUser() {
      if (!this.token) return
      const { data } = await http.get('/me')
      this.user = data
    },

    async switchContext(companyId: number, branchId: number | null) {
      const { data } = await http.post('/context/switch', { company_id: companyId, branch_id: branchId })
      this.user = data
    },

    async logout() {
      try {
        await http.post('/logout')
      } catch {
        // ignore network errors on logout
      }
      this.token = null
      this.user = null
      localStorage.removeItem('sims_token')
    },
  },
})
