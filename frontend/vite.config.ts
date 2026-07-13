import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    // Accept tenant subdomains locally (acme.localhost:5173, etc.).
    host: true,
    allowedHosts: true,
    proxy: {
      // Proxy API calls to the Laravel backend during development.
      // changeOrigin:false keeps the original Host header (e.g.
      // acme.localhost:5173) so stancl/tenancy can resolve the tenant
      // subdomain on the backend. On the plain localhost host it stays
      // central.
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
      // Product images and other public-disk files.
      '/storage': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
    },
  },
})
