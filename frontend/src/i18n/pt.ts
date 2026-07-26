import i18n from './index'
import { PHRASES } from './phrases'

/**
 * Phrase translation for view screens: the English text itself is the key.
 * `pt('Sales Orders')` looks the phrase up in the hi/gu dictionaries under
 * src/i18n/phrases/; anything missing falls back to the English phrase, so an
 * untranslated string can never break a screen. Supports `{var}` placeholders:
 * `pt('{n} items selected', { n: 5 })`.
 *
 * Usage in a component:
 *   import { usePt } from '../i18n/pt'
 *   const pt = usePt()
 * Templates re-render on language switch because the returned function reads
 * the reactive i18n locale ref on every call.
 */
export function usePt() {
  return (phrase: string, vars?: Record<string, string | number>): string => {
    const loc = i18n.global.locale.value as string
    let out = (loc !== 'en' && PHRASES[loc]?.[phrase]) || phrase
    if (vars) {
      for (const [k, v] of Object.entries(vars)) {
        out = out.split(`{${k}}`).join(String(v))
      }
    }
    return out
  }
}
