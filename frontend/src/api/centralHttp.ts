import axios from 'axios'

// Dedicated client for the SaaS-owner platform panel (Level-1 RBAC). It carries
// its own bearer token, separate from the staff app and the B2B portal, so a
// platform-operator session can coexist with the others in one browser.
const centralHttp = axios.create({
  baseURL: '/api/central',
  headers: {
    Accept: 'application/json',
  },
})

centralHttp.interceptors.request.use((config) => {
  const token = localStorage.getItem('sims_central_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

centralHttp.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('sims_central_token')
      if (window.location.pathname !== '/platform/login') {
        window.location.href = '/platform/login'
      }
    }
    return Promise.reject(error)
  },
)

export default centralHttp
