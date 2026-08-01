import { ref } from 'vue'

/**
 * Light / dark theme (project-stack.md §20). Toggling adds/removes the
 * `.dark-mode` class on <html>, which drives both PrimeVue (configured with
 * `darkModeSelector: '.dark-mode'` in main.ts) and our own CSS-variable
 * overrides in style.css. The choice is persisted in localStorage.
 */
const STORAGE_KEY = 'sims_theme'

// Module-level singleton so every component shares one reactive flag.
const isDark = ref(false)

function applyClass(dark: boolean) {
  document.documentElement.classList.toggle('dark-mode', dark)
}

/**
 * Open in dark mode because the workspace's brand says so (white label —
 * see composables/useBranding.ts), without recording it as a choice: a user who
 * has picked a theme keeps it, and one who has not stays free to be moved back
 * if the brand default changes.
 */
export function applyBrandDefaultDark(dark: boolean) {
  const saved = localStorage.getItem(STORAGE_KEY)
  if (saved === 'dark' || saved === 'light') {
    return // the user's own choice always wins
  }

  isDark.value = dark
  applyClass(dark)
}

/** Read the saved preference (falling back to the OS setting) and apply it. */
export function initTheme() {
  const saved = localStorage.getItem(STORAGE_KEY)
  if (saved === 'dark' || saved === 'light') {
    isDark.value = saved === 'dark'
  } else {
    isDark.value = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
  }
  applyClass(isDark.value)
}

export function useTheme() {
  function setDark(dark: boolean) {
    isDark.value = dark
    localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light')
    applyClass(dark)
  }

  function toggle() {
    setDark(!isDark.value)
  }

  return { isDark, toggle, setDark }
}
