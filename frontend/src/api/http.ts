import axios from 'axios'

const http = axios.create({
  baseURL: '/api',
  headers: {
    Accept: 'application/json',
  },
})

// Attach bearer token from localStorage on every request.
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('sims_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// On 401, clear the session and bounce to login. On a 403 tenant_suspended
// (workspace hard-locked by the SaaS operator) land on the suspended screen —
// the token is kept so the user can still sign out from there.
http.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('sims_token')
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    if (
      error.response?.status === 403 &&
      error.response?.data?.error === 'tenant_suspended' &&
      window.location.pathname !== '/suspended'
    ) {
      window.location.href = '/suspended'
    }
    return Promise.reject(error)
  },
)

export default http
