import { defineStore } from 'pinia'
import portalHttp from '../api/portalHttp'

export interface PortalCustomer {
  id: number
  code: string
  name: string
  company_name: string | null
  email: string
  phone: string | null
  type: string
  price_level: string
  credit_limit: number
  outstanding_balance: number
  available_credit: number | null
  business_name: string | null
  currency_symbol: string
  currency_code: string
}

export const usePortalAuthStore = defineStore('portalAuth', {
  state: () => ({
    token: localStorage.getItem('sims_portal_token') as string | null,
    customer: null as PortalCustomer | null,
    loading: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
    symbol: (state) => state.customer?.currency_symbol ?? '₹',
  },
  actions: {
    async login(email: string, password: string) {
      const { data } = await portalHttp.post('/login', { email, password })
      this.token = data.token
      this.customer = data.customer
      localStorage.setItem('sims_portal_token', data.token)
    },
    async fetchMe() {
      if (!this.token) return
      const { data } = await portalHttp.get('/me')
      this.customer = data
    },
    async logout() {
      try {
        await portalHttp.post('/logout')
      } catch {
        /* ignore */
      }
      this.token = null
      this.customer = null
      localStorage.removeItem('sims_portal_token')
    },
    /** Format an amount in the storefront's currency. */
    money(value: number | string | null | undefined): string {
      const n = Number(value ?? 0)
      try {
        return n.toLocaleString('en-IN', { style: 'currency', currency: this.customer?.currency_code || 'INR' })
      } catch {
        return `${this.symbol}${n.toFixed(2)}`
      }
    },
  },
})
