import { createI18n } from 'vue-i18n'
import en from './locales/en'
import hi from './locales/hi'
import gu from './locales/gu'

export type AppLocale = 'en' | 'hi' | 'gu'

export const SUPPORTED_LOCALES: { code: AppLocale; label: string }[] = [
  { code: 'en', label: 'English' },
  { code: 'hi', label: 'हिन्दी' },
  { code: 'gu', label: 'ગુજરાતી' },
]

const STORAGE_KEY = 'sims_locale'

export function getStoredLocale(): AppLocale {
  const saved = localStorage.getItem(STORAGE_KEY)
  return saved === 'hi' || saved === 'gu' || saved === 'en' ? saved : 'en'
}

const i18n = createI18n({
  legacy: false,
  locale: getStoredLocale(),
  fallbackLocale: 'en',
  messages: { en, hi, gu },
  // English is the source language; untranslated keys silently fall back.
  missingWarn: false,
  fallbackWarn: false,
})

// Switch the UI language everywhere: vue-i18n, <html lang>, localStorage
// (the http clients read the stored value to send X-Locale to the backend).
export function setLocale(code: AppLocale) {
  i18n.global.locale.value = code
  localStorage.setItem(STORAGE_KEY, code)
  document.documentElement.setAttribute('lang', code)
}

export default i18n
