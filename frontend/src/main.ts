import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import { definePreset } from '@primevue/themes'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'
import 'primeicons/primeicons.css'
import './style.css'
// Shared listing-screen design (page head / toolbar card / table card / cell
// atoms). Loaded after style.css so every module's list view inherits it.
import './styles/list-design.css'

import App from './App.vue'
import router from './router'
import { initTheme } from './composables/useTheme'
import { initBranding } from './composables/useBranding'
import i18n, { getStoredLocale } from './i18n'

// Apply the saved light/dark preference before mount to avoid a flash.
initTheme()

// White label (Volume 11): fetch the brand for this host and push its colours
// into the CSS variables the theme reads. Deliberately not awaited — the app
// boots on the platform brand and re-tints as soon as the lookup lands, so a
// slow (or failed) branding call can never hold the first paint.
void initBranding()

// Reflect the saved UI language (en/hi/gu) on the document element.
document.documentElement.setAttribute('lang', getStoredLocale())

// "Warehouse Ledger" theme — safety-amber (#E8A33D) as the primary accent,
// ink-blue as the secondary/highlight tone.
const WarehouseLedgerPreset = definePreset(Aura, {
  semantic: {
    primary: {
      50: '#fdf7ec',
      100: '#fbecce',
      200: '#f6d89c',
      300: '#f0c069',
      400: '#eaad48',
      500: '#e8a33d',
      600: '#c98730',
      700: '#a06a27',
      800: '#7a4f22',
      900: '#5b3c1d',
      950: '#33200f',
    },
  },
  components: {
    button: {
      colorScheme: {
        light: {
          root: {
            secondary: {
              background: '#2c4a5e',
              hoverBackground: '#233c4b',
              color: '#ffffff',
              hoverColor: '#ffffff',
            },
          },
        },
      },
    },
  },
})

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)
app.use(PrimeVue, {
  theme: {
    preset: WarehouseLedgerPreset,
    options: {
      darkModeSelector: '.dark-mode',
    },
  },
})
app.use(ToastService)

app.mount('#app')
