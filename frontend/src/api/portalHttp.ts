import axios from 'axios'

// Dedicated client for the B2B customer portal. It carries its own bearer
// token (separate from the staff app) so a customer session and a staff
// session can coexist in the same browser without clobbering each other.
const portalHttp = axios.create({
  baseURL: '/api/portal',
  headers: {
    Accept: 'application/json',
  },
})

portalHttp.interceptors.request.use((config) => {
  const token = localStorage.getItem('sims_portal_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  config.headers['X-Locale'] = localStorage.getItem('sims_locale') || 'en'
  return config
})

portalHttp.interceptors.response.use(
  (response) => response,
  (error) => {
    // 403 tenant_suspended: the supplier's workspace was hard-locked by the
    // SaaS operator — end the portal session (login is blocked server-side
    // with an explanatory message).
    if (
      error.response?.status === 401 ||
      (error.response?.status === 403 && error.response?.data?.error === 'tenant_suspended')
    ) {
      localStorage.removeItem('sims_portal_token')
      if (window.location.pathname !== '/portal/login') {
        window.location.href = '/portal/login'
      }
    }
    return Promise.reject(error)
  },
)

export default portalHttp
