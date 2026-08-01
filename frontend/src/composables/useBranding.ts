import { ref, computed } from 'vue'
import http from '../api/http'
import { applyBrandDefaultDark } from './useTheme'

/**
 * White label (Volume 11 §"White Label"). The brand the app wears — name, logo,
 * two colours — fetched once on boot from the public `GET /api/branding`, which
 * resolves it from the host that asked (App\Support\Branding).
 *
 * Nothing here is authenticated: sign-in, provisioning and the suspended screen
 * all have to be branded before anyone has a token, which is exactly why the
 * endpoint is public and why this runs before the router.
 *
 * The platform's own brand is the fallback for every field, so a workspace that
 * was never white labelled renders precisely as it did before this existed.
 */
export type Branding = {
  tenant_id: number | null
  company_name: string | null
  app_name: string
  tagline: string
  logo_url: string | null
  primary_color: string
  accent_color: string
  dark_default: boolean
  powered_by: boolean
  white_labelled: boolean
}

const PLATFORM: Branding = {
  tenant_id: null,
  company_name: null,
  app_name: 'Kosha',
  tagline: '',
  logo_url: null,
  primary_color: '#e8a33d',
  accent_color: '#2c4a5e',
  dark_default: false,
  powered_by: true,
  white_labelled: false,
}

// Module-level singleton: one fetch per page load, shared by every component.
const branding = ref<Branding>({ ...PLATFORM })
const loaded = ref(false)

/**
 * Push the brand colours into the CSS variables the app already themes off.
 *
 * `--safety-amber` / `--ink-blue` are ours (style.css); `--p-primary-*` are
 * PrimeVue's Aura tokens, so overriding them re-tints buttons, links, focus
 * rings and the rest of the component set without rebuilding the preset. The
 * ramp is derived with color-mix() rather than computed in JS — one source of
 * truth for the hue, and it keeps working if the value changes at runtime.
 */
function applyTheme(b: Branding) {
  const root = document.documentElement.style
  const mix = (pct: number, towards: 'white' | 'black') =>
    `color-mix(in srgb, ${b.primary_color} ${pct}%, ${towards})`

  root.setProperty('--brand-primary', b.primary_color)
  root.setProperty('--brand-accent', b.accent_color)
  root.setProperty('--safety-amber', b.primary_color)
  root.setProperty('--safety-amber-light', mix(70, 'white'))
  root.setProperty('--ink-blue', b.accent_color)

  root.setProperty('--p-primary-50', mix(10, 'white'))
  root.setProperty('--p-primary-100', mix(22, 'white'))
  root.setProperty('--p-primary-200', mix(40, 'white'))
  root.setProperty('--p-primary-300', mix(62, 'white'))
  root.setProperty('--p-primary-400', mix(84, 'white'))
  root.setProperty('--p-primary-500', b.primary_color)
  root.setProperty('--p-primary-600', mix(86, 'black'))
  root.setProperty('--p-primary-700', mix(70, 'black'))
  root.setProperty('--p-primary-800', mix(54, 'black'))
  root.setProperty('--p-primary-900', mix(42, 'black'))
  root.setProperty('--p-primary-950', mix(24, 'black'))
}

/** Reflect the brand in the tab: title, and the logo as the favicon. */
function applyDocument(b: Branding) {
  const suffix = 'ERP'
  document.title = b.white_labelled ? `${b.app_name} ${suffix}` : document.title

  if (!b.logo_url) return
  const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
  if (link) link.href = b.logo_url
}

/** Adopt a payload from either endpoint and re-tint everything. */
function adopt(data: Partial<Branding>) {
  branding.value = { ...PLATFORM, ...data }
  applyTheme(branding.value)
  applyDocument(branding.value)

  // Dark-by-default is a brand decision; applyBrandDefaultDark leaves a user
  // who has already chosen a theme exactly where they are.
  if (branding.value.dark_default) {
    applyBrandDefaultDark(true)
  }
}

/**
 * Load the brand for this host. Called once from main.ts before mount; failures
 * are swallowed on purpose — a branding lookup must never be what stops the app
 * from starting, and the platform defaults are already in place.
 */
export async function initBranding(): Promise<void> {
  try {
    // Plain fetch, not the axios client: this runs before any store is set up
    // and must not carry (or trip over) a bearer token.
    const res = await fetch('/api/branding', { headers: { Accept: 'application/json' } })
    if (!res.ok) return
    const body = await res.json()
    if (body?.data) adopt(body.data)
  } catch {
    /* keep the platform brand */
  } finally {
    loaded.value = true
  }
}

/**
 * Re-read the brand now that a staff session exists.
 *
 * The host lookup above is all an unauthenticated screen can do, and on a
 * single-host deployment — which this is, since tenancy comes from the signed-in
 * user — it can only ever answer with the platform brand. So the app calls this
 * as soon as it has a session: same shape, authoritative tenant.
 */
export async function refreshTenantBranding(): Promise<void> {
  try {
    const { data } = await http.get('/my-branding')
    if (data?.data) adopt(data.data)
  } catch {
    /* keep whatever the host lookup produced */
  }
}

export function useBranding() {
  return {
    branding,
    loaded,
    /** The tenant's own logo, or null when it is wearing the platform mark. */
    logoUrl: computed(() => branding.value.logo_url),
    appName: computed(() => branding.value.app_name),
    /** Whether anything was actually overridden for this workspace. */
    isWhiteLabelled: computed(() => branding.value.white_labelled),
  }
}
