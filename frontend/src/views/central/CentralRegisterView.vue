<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../api/http'
import PlanPicker from '../../components/PlanPicker.vue'
import AuthShell from '../../components/auth/AuthShell.vue'
import type { AuthStep } from '../../components/auth/types'

interface Plan {
  slug: string
  name: string
  price: number
  currency: string
  is_custom: boolean
  feature_limits: Record<string, unknown>
  // Module keys this plan sells; `modules_all` is true for Enterprise, which
  // unlocks the whole catalogue. Both come resolved from the plans API.
  modules?: string[]
  modules_all?: boolean
}

interface ModuleOption {
  key: string
  label: string
  icon: string
  description: string
  // Screen names this module unlocks, straight from the menu catalogue.
  menus?: string[]
}

interface ShopProfile {
  key: string
  label: string
  icon: string
  description: string
  // The modules this trade normally runs on — a starting tick, not a lock.
  modules: string[]
  // Industry-template key this trade sits under ('retail', 'restaurant', …).
  // Absent only in the offline fallback list below.
  industry?: string | null
  /**
   * What the trade gives you, as the industry plugin declares it:
   * 'kot' => 'available' | 'planned'. Only plugin-backed trades have one; a
   * hand-written preset sends nothing and the UI falls back to the description.
   */
  features?: Record<string, string>
}

const router = useRouter()

// Three steps on purpose: pick a package, say who you are, create the account.
// Everything optional (address, team invites, numbering/tax defaults) sits inside
// collapsible sections so the visible surface per step stays short.
const STEPS: AuthStep[] = [
  { key: 'package', label: 'Package', hint: 'Plan, trade and modules' },
  { key: 'company', label: 'Company', hint: 'Name and workspace address' },
  { key: 'account', label: 'Account', hint: 'Owner login and defaults' },
]
const step = ref(1)

// Shop-type presets. Answering "what kind of business is this?" ticks the modules
// that trade normally runs on, so a kirana shop is never asked to reason about
// Embroidery. The real list comes from GET /shop-profiles (App\Support\ShopProfiles);
// this fallback only keeps the step usable if that call fails.
const FALLBACK_PROFILES: ShopProfile[] = [
  { key: 'grocery', label: 'Grocery / Kirana', icon: 'pi pi-shop', description: '', modules: ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices'] },
  { key: 'garment', label: 'Garment / Apparel', icon: 'pi pi-shopping-bag', description: '', modules: ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'tailoring', 'devices'] },
  { key: 'custom', label: 'Something else', icon: 'pi pi-sliders-h', description: '', modules: ['sales', 'purchase', 'inventory', 'accounts'] },
]
// Fallback catalogue, used only if GET /modules fails — the real list comes from
// the backend (App\Support\Modules), which is also what tags the menu rows.
const FALLBACK_MODULES: ModuleOption[] = [
  { key: 'sales', label: 'Sales', icon: 'pi pi-shopping-cart', description: '' },
  { key: 'pos', label: 'POS', icon: 'pi pi-calculator', description: '' },
  { key: 'purchase', label: 'Purchase', icon: 'pi pi-shopping-bag', description: '' },
  { key: 'inventory', label: 'Inventory', icon: 'pi pi-warehouse', description: '' },
  { key: 'accounts', label: 'Accounts', icon: 'pi pi-wallet', description: '' },
]
const CURRENCIES = [
  { code: 'INR', symbol: '₹', label: 'Indian Rupee (₹)' },
  { code: 'USD', symbol: '$', label: 'US Dollar ($)' },
  { code: 'EUR', symbol: '€', label: 'Euro (€)' },
  { code: 'GBP', symbol: '£', label: 'Pound (£)' },
  { code: 'AED', symbol: 'د.إ', label: 'UAE Dirham (د.إ)' },
]
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

const plans = ref<Plan[]>([])
const moduleCatalogue = ref<ModuleOption[]>(FALLBACK_MODULES)
const shopProfiles = ref<ShopProfile[]>(FALLBACK_PROFILES)
const industries = ref<{ key: string; label: string; icon: string }[]>([])
const logoUploading = ref(false)
const logoUrl = ref<string | null>(null)

const form = ref({
  plan_slug: 'starter',
  // Step 1 — company profile
  company_name: '',
  // Workspace address: `{subdomain}.{root domain}`, reserved at signup.
  subdomain: '',
  gstin: '',
  pan: '',
  logo_path: '' as string,
  address: '',
  city: '',
  state: '',
  country: 'India',
  pincode: '',
  // Step 2 — trade. `shop_profile` is the preset key; `industry` is the key of
  // the industry template that trade sits under, which is what the platform
  // panel groups, filters and reports on. (Older builds sent the trade's label
  // here; the backend still resolves those, but new signups send a key.)
  shop_profile: 'garment',
  industry: 'retail',
  // Step 3 — modules
  modules: ['crm', 'sales', 'purchase', 'inventory', 'accounts'] as string[],
  // Step 4 — users
  admin_name: '',
  email: '',
  phone: '',
  password: '',
  team: [
    { label: 'Manager', role: 'general-manager', name: '', email: '' },
    { label: 'Sales', role: 'sales-executive', name: '', email: '' },
    { label: 'Accounts', role: 'accountant', name: '', email: '' },
  ],
  // Step 5 — branch / warehouse / FY / currency
  branch_name: 'Head Office',
  warehouse_name: 'Main Warehouse',
  fy_start_month: 4,
  currency_code: 'INR',
  // Step 6 — numbering & tax
  invoice_prefix: 'INV',
  gst_enabled: true,
  default_gst_rate: 18,
  // Step 7
  load_demo: false,
  demo_count: 100,
})

const emailState = ref<'idle' | 'checking' | 'available' | 'taken'>('idle')
const emailReason = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const serverError = ref('')

// --- Workspace address (§5) ---
const rootDomain = ref('yourapp.com')
const subState = ref<'idle' | 'checking' | 'available' | 'taken'>('idle')
const subReason = ref('')
const subAlternatives = ref<string[]>([])
// Once the buyer edits the address themselves we stop re-deriving it from the
// company name — nothing is ruder than a field that undoes your typing.
const subTouched = ref(false)

onMounted(async () => {
  try {
    const [planRes, moduleRes, profileRes, industryRes] = await Promise.all([
      http.get('/plans'),
      http.get('/modules'),
      http.get('/shop-profiles'),
      // Industry templates: only their names and order are needed here — the
      // trades themselves already arrive flat from /shop-profiles, each tagged
      // with the industry that claims it.
      http.get('/industries'),
    ])
    plans.value = planRes.data.data
    if (moduleRes.data.data?.length) moduleCatalogue.value = moduleRes.data.data
    if (profileRes.data.data?.length) shopProfiles.value = profileRes.data.data
    if (industryRes.data.data?.length) industries.value = industryRes.data.data
    pruneModules()
  } catch {
    /* pricing + catalogue are best-effort; the fallback list keeps step 3 usable */
  }
})

// Derive the financial year from the chosen start month relative to today.
const financialYear = computed(() => {
  const now = new Date()
  const m = form.value.fy_start_month // 1-12
  const startYear = now.getMonth() + 1 >= m ? now.getFullYear() : now.getFullYear() - 1
  const start = new Date(startYear, m - 1, 1)
  const end = new Date(startYear + 1, m - 1, 0) // day 0 of next-period start month = last day prior
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  return { name: `${startYear}-${startYear + 1}`, start_date: iso(start), end_date: iso(end) }
})

const currency = computed(() => CURRENCIES.find((c) => c.code === form.value.currency_code) ?? CURRENCIES[0])

// --- Modules gated by the chosen package (step 1 plan → step 3 grid) ---
const selectedPlan = computed(() => plans.value.find((p) => p.slug === form.value.plan_slug) ?? null)

// Until the plans API answers, treat everything as allowed rather than showing a
// grid of locks that the server would then contradict.
const allowedModules = computed<string[]>(() => {
  const plan = selectedPlan.value
  if (!plan || plan.modules_all || !plan.modules?.length) return moduleCatalogue.value.map((m) => m.key)
  return plan.modules
})
const planAllowsAll = computed(() => allowedModules.value.length >= moduleCatalogue.value.length)
const enterprisePlanName = computed(() => plans.value.find((p) => p.is_custom)?.name ?? 'Enterprise')

// What the chosen package includes, and what it does not.
const includedModules = computed(() => moduleCatalogue.value.filter((m) => moduleAllowed(m.key)))
const lockedModules = computed(() => moduleCatalogue.value.filter((m) => !moduleAllowed(m.key)))

function moduleAllowed(key: string) {
  return allowedModules.value.includes(key)
}

/** Module names for a package card / the review row, e.g. "Sales, POS, Purchase". */
function moduleNames(keys: string[]): string {
  const byKey = new Map(moduleCatalogue.value.map((m) => [m.key, m.label]))
  return keys.map((k) => byKey.get(k) ?? k).join(', ')
}

const selectedModuleNames = computed(() => moduleNames(form.value.modules))

/** First few screen names, e.g. "Leads · Follow-ups · Coupons +6 more". */
function menuPreview(m: ModuleOption): string {
  const menus = m.menus ?? []
  if (!menus.length) return m.description
  const head = menus.slice(0, 6).join(' · ')
  return menus.length > 6 ? `${head} +${menus.length - 6} more` : head
}

/**
 * Only Enterprise (a package with no module restriction) lets the buyer pick and
 * choose — on the smaller packages the module set *is* the package, so step 3
 * just lists what comes with it.
 */
function toggleModule(key: string) {
  if (!planAllowsAll.value || !moduleAllowed(key)) return
  const i = form.value.modules.indexOf(key)
  if (i >= 0) form.value.modules.splice(i, 1)
  else form.value.modules.push(key)
}

function selectAllModules() {
  form.value.modules = [...allowedModules.value]
}

function clearModules() {
  form.value.modules = []
}

const selectedProfile = computed(
  () => shopProfiles.value.find((p) => p.key === form.value.shop_profile) ?? null,
)

/**
 * Trades grouped under their industry, in the order the operator ordered the
 * catalogue. Twenty-odd chips in one wall are hard to scan; the same chips under
 * "Retail", "Restaurant & Food", "Healthcare" are not.
 *
 * Trades no template claims fall into a trailing "Other" group rather than
 * disappearing — the picker must always offer the full list, even mid-way through
 * an operator re-curating the catalogue.
 */
const tradeGroups = computed(() => {
  const groups: { key: string; label: string; icon: string; trades: ShopProfile[] }[] = []
  const index = new Map<string, number>()

  for (const industry of industries.value) {
    index.set(industry.key, groups.length)
    groups.push({ key: industry.key, label: industry.label, icon: industry.icon, trades: [] })
  }

  const orphans: ShopProfile[] = []
  for (const profile of shopProfiles.value) {
    const at = profile.industry ? index.get(profile.industry) : undefined
    if (at === undefined) orphans.push(profile)
    else groups[at].trades.push(profile)
  }

  if (orphans.length) {
    groups.push({ key: '_other', label: 'Other', icon: 'pi pi-sliders-h', trades: orphans })
  }

  return groups.filter((g) => g.trades.length)
})

/**
 * Which industry's trades are on screen.
 *
 * Rendering every group at once was ~675px of chips — the single biggest reason
 * this step had to be scrolled. One row of industry pills plus that industry's
 * trades says the same thing in a fifth of the height, and reads as a two-step
 * "vertical → trade" choice rather than a wall.
 */
const activeIndustry = ref<string>('')

/** The group whose chips are showing, defaulting to the one holding the trade. */
const activeGroup = computed(() => {
  const groups = tradeGroups.value
  if (!groups.length) return null

  return groups.find((g) => g.key === activeIndustry.value)
    ?? groups.find((g) => g.trades.some((t) => t.key === form.value.shop_profile))
    ?? groups[0]
})

const visibleTrades = computed(() => activeGroup.value?.trades ?? [])

/**
 * Whether the chosen trade's chip is one of the ones on screen. Browsing another
 * industry leaves no chip lit, so the strip below names the trade that is still
 * in effect instead of describing an invisible selection.
 */
const selectedTradeVisible = computed(() =>
  visibleTrades.value.some((t) => t.key === form.value.shop_profile),
)

/** How many screens a module unlocks — the count stands in for the full list. */
function menuCount(m: ModuleOption): number {
  return m.menus?.length ?? 0
}

/** Feature keys are snake_case; the pill wants "Cake orders", not "cake_orders". */
function featureLabel(key: string): string {
  const spaced = key.replace(/_/g, ' ')
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * What the chosen trade actually gives you, one pill per capability.
 *
 * Preferred source is the `features` map the industry plugin declares — the same
 * map the platform's industry screen reports coverage from, so what a buyer is
 * shown at signup and what the operator believes they are selling cannot drift.
 * Capabilities still on the roadmap are shown greyed rather than dropped: a trade
 * that lists nine things and quietly omits a tenth reads as a complete promise.
 *
 * Hand-written presets have no plugin behind them and so no feature map. For
 * those we fall back to the description, which in ShopProfiles.php is always a
 * comma list closing with "… and X" ("Counter billing, barcode, stock, purchase
 * and the daily cash book"), so splitting on commas plus a standalone "and" gives
 * clean items. A description that isn't a list still yields one usable pill.
 */
const profileCapabilities = computed<{ label: string; planned: boolean }[]>(() => {
  const features = selectedProfile.value?.features ?? {}
  const keys = Object.keys(features)

  if (keys.length) {
    return keys.map((key) => ({
      label: featureLabel(key),
      planned: features[key] !== 'available',
    }))
  }

  return (selectedProfile.value?.description ?? '')
    .split(/,|\band\b/)
    .map((s) => s.trim())
    .filter(Boolean)
    .map((label) => ({ label, planned: false }))
})

/**
 * The chosen trade's preset, scoped to the package. Falls back to everything the
 * package includes when the preset lands entirely outside it — a blank module
 * step is worse than an over-generous one.
 */
function profileModules(): string[] {
  const scoped = (selectedProfile.value?.modules ?? []).filter((k) => moduleAllowed(k))
  return scoped.length ? scoped : [...allowedModules.value]
}

/** Pick a trade: re-ticks the module grid and names the industry in one move. */
function applyProfile(key: string) {
  form.value.shop_profile = key
  const profile = shopProfiles.value.find((p) => p.key === key)
  // Keep the visible group in step with the trade, so jumping back to this step
  // (or picking a trade from a search/preset) opens on the right vertical.
  if (profile?.industry) activeIndustry.value = profile.industry
  // Each trade reports the industry template that claims it; a trade no template
  // curates yet sends nothing rather than a guess.
  if (profile) form.value.industry = profile.industry ?? ''
  // On the smaller packages the module set *is* the package, so the preset only
  // records the trade — there is nothing to re-tick.
  if (planAllowsAll.value) form.value.modules = profileModules()
}

/** Keep the selection inside the package; fall back to the trade's preset. */
function pruneModules() {
  form.value.modules = form.value.modules.filter((k) => moduleAllowed(k))
  if (!planAllowsAll.value) {
    form.value.modules = [...allowedModules.value]
    return
  }
  if (!form.value.modules.length) form.value.modules = profileModules()
}

// Switching package re-scopes the selection (a downgrade drops locked modules).
watch(() => form.value.plan_slug, pruneModules)

// Live owner-email availability check (the tenant owner's unique login).
let checkTimer: ReturnType<typeof setTimeout>
watch(
  () => form.value.email,
  (email) => {
    emailState.value = 'idle'
    clearTimeout(checkTimer)
    if (!email) return
    emailState.value = 'checking'
    checkTimer = setTimeout(async () => {
      try {
        const { data } = await http.get('/check-email', { params: { email } })
        emailState.value = data.available ? 'available' : 'taken'
        emailReason.value = data.reason || ''
      } catch {
        emailState.value = 'idle'
      }
    }, 400)
  },
)

// Company name → suggested workspace address, until the buyer edits it.
let suggestTimer: ReturnType<typeof setTimeout>
watch(
  () => form.value.company_name,
  (name) => {
    if (subTouched.value) return
    clearTimeout(suggestTimer)
    if (!name.trim()) {
      form.value.subdomain = ''
      subState.value = 'idle'
      return
    }
    suggestTimer = setTimeout(async () => {
      try {
        const { data } = await http.get('/check-subdomain', { params: { company: name } })
        if (subTouched.value) return
        rootDomain.value = data.root_domain || rootDomain.value
        // The server only ever suggests a free address, so the watcher below
        // confirms it rather than having to hunt for an alternative.
        if (data.suggestion) form.value.subdomain = data.suggestion
      } catch {
        /* suggestion is a nicety — the field still works typed by hand */
      }
    }, 450)
  },
)

// Live workspace-address availability.
let subTimer: ReturnType<typeof setTimeout>
watch(
  () => form.value.subdomain,
  (value) => {
    clearTimeout(subTimer)
    subAlternatives.value = []
    if (!value) {
      subState.value = 'idle'
      subReason.value = ''
      return
    }
    subState.value = 'checking'
    subTimer = setTimeout(async () => {
      try {
        const { data } = await http.get('/check-subdomain', { params: { subdomain: value } })
        rootDomain.value = data.root_domain || rootDomain.value
        subState.value = data.available ? 'available' : 'taken'
        subReason.value = data.reason || ''
        subAlternatives.value = data.alternatives || []
      } catch {
        subState.value = 'idle'
      }
    }, 400)
  },
)

/** Mirror the server's normalisation as you type: lowercase, a-z0-9 and hyphens. */
function onSubdomainInput(e: Event) {
  subTouched.value = true
  const el = e.target as HTMLInputElement
  const clean = el.value.toLowerCase().replace(/[^a-z0-9-]/g, '')
  el.value = clean
  form.value.subdomain = clean
}

function useAlternative(alt: string) {
  subTouched.value = true
  form.value.subdomain = alt
}

// Rough password strength — enough to nudge, not to gate (the server's rule is
// the 8-character minimum).
const passwordScore = computed(() => {
  const p = form.value.password
  if (!p) return 0
  let score = 0
  if (p.length >= 8) score++
  if (p.length >= 12) score++
  if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++
  if (/\d/.test(p) && /[^\w\s]/.test(p)) score++
  return Math.min(score, 4)
})
const passwordLabel = computed(() => ['', 'Weak', 'Fair', 'Good', 'Strong'][passwordScore.value])

async function onLogoChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  logoUploading.value = true
  try {
    const fd = new FormData()
    fd.append('logo', file)
    const { data } = await http.post('/register/logo', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    form.value.logo_path = data.logo_path
    logoUrl.value = data.logo_url
  } catch {
    serverError.value = 'Logo upload failed. You can add it later.'
  } finally {
    logoUploading.value = false
  }
}

// Per-step gating for the Next button.
const stepValid = computed(() => {
  switch (step.value) {
    case 1:
      return form.value.modules.length > 0
    case 2:
      return !!form.value.company_name.trim() && subState.value === 'available'
    case 3:
      return (
        !!form.value.admin_name.trim() &&
        form.value.password.length >= 8 &&
        emailState.value === 'available'
      )
    default:
      return true
  }
})

function next() {
  if (step.value < STEPS.length && stepValid.value) {
    step.value++
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}
function back() {
  if (step.value > 1) {
    step.value--
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  serverError.value = ''
  try {
    const team = form.value.team
      .filter((u) => u.name.trim() && u.email.trim())
      .map((u) => ({ name: u.name.trim(), email: u.email.trim(), role: u.role }))

    const payload = {
      plan_slug: form.value.plan_slug,
      company_name: form.value.company_name,
      subdomain: form.value.subdomain,
      admin_name: form.value.admin_name,
      email: form.value.email,
      phone: form.value.phone,
      password: form.value.password,
      config: {
        industry: form.value.industry,
        shop_profile: form.value.shop_profile,
        company: {
          gstin: form.value.gstin,
          pan: form.value.pan,
          address: form.value.address,
          city: form.value.city,
          state: form.value.state,
          country: form.value.country,
          pincode: form.value.pincode,
          logo_path: form.value.logo_path || undefined,
        },
        modules: form.value.modules,
        users: team,
        branch: { name: form.value.branch_name },
        warehouse: { name: form.value.warehouse_name },
        financial_year: financialYear.value,
        fy_start_month: form.value.fy_start_month,
        currency: { code: currency.value.code, symbol: currency.value.symbol },
        number_prefixes: { invoice_number_prefix: form.value.invoice_prefix },
        tax: { gst_enabled: form.value.gst_enabled, default_gst_rate: form.value.default_gst_rate, gstin: form.value.gstin },
        load_demo: form.value.load_demo,
        demo_count: form.value.demo_count,
      },
    }

    const { data } = await http.post('/register', payload)
    router.push({
      name: 'central-verify',
      query: { registration: data.registration_id, dev_code: data.dev_code ?? undefined },
    })
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      serverError.value = e.response.data.message || 'Please review the highlighted fields.'
      // Jump back to the step that owns the first error.
      if (errors.value['config.modules']) step.value = 1
      else if (errors.value.company_name || errors.value.subdomain) step.value = 2
      else if (errors.value.email || Object.keys(errors.value).some((k) => k.startsWith('config.users'))) step.value = 3
    } else {
      serverError.value = 'Something went wrong. Please try again.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthShell
    :steps="STEPS"
    :current="step"
    wide
    eyebrow="Start free"
    headline="Your whole business, running on one ledger."
    blurb="Pick the modules your trade actually uses. We generate the company, roles, menus and dashboard for you — usually in under a minute."
  >
    <!-- Card header -->
    <div class="head">
      <div>
        <p class="kicker">Step {{ step }} of {{ STEPS.length }}</p>
        <h1>
          {{ step === 1 ? 'Choose your package' : step === 2 ? 'Tell us about your company' : 'Create your account' }}
        </h1>
        <p class="sub">
          {{
            step === 1
              ? 'Each package unlocks the menus listed below. You can change this later.'
              : step === 2
                ? 'This names your workspace and appears on every invoice you print.'
                : 'You’ll be the owner. Everything else here has a sensible default.'
          }}
        </p>
      </div>
      <div class="ring" :style="{ '--pct': (step / STEPS.length) * 100 + '%' }">
        <span>{{ step }}<small>/{{ STEPS.length }}</small></span>
      </div>
    </div>

    <!-- STEP 1: Package — what it unlocks, menu by menu -->
    <div v-if="step === 1" class="form">
      <!-- Compact cards: each package's module chips repeat the full listing at
           the bottom of this same step, and cost ~120px of scrolling to do it. -->
      <PlanPicker v-model="form.plan_slug" :plans="plans" :modules="moduleCatalogue" compact />

      <label class="field-label">What kind of business is this?</label>
      <!-- Two moves, not a wall: pick the vertical, then the trade inside it.
           All nine groups rendered at once ran ~675px of chips — the single
           biggest reason this step had to be scrolled — and only one group is
           ever relevant. Descriptions stay in `title`; the chosen trade explains
           itself in the strip below. -->
      <div class="industry-row">
        <button
          v-for="group in tradeGroups"
          :key="group.key"
          type="button"
          class="industry-pill"
          :class="{ active: activeGroup?.key === group.key }"
          :aria-pressed="activeGroup?.key === group.key"
          @click="activeIndustry = group.key"
        >
          <i :class="group.icon" />
          <span>{{ group.label }}</span>
        </button>
      </div>
      <div class="shop-types">
        <button
          v-for="p in visibleTrades"
          :key="p.key"
          type="button"
          class="shop-type"
          :class="{ active: form.shop_profile === p.key }"
          :title="p.description"
          :aria-pressed="form.shop_profile === p.key"
          @click="applyProfile(p.key)"
        >
          <i :class="p.icon" />
          <span class="st-label">{{ p.label }}</span>
        </button>
      </div>

      <!-- One strip rather than a titled card: the chip above is already lit, so
           repeating the trade's name and icon here was 55px spent on nothing. -->
      <div v-if="selectedProfile" class="st-detail">
        <span class="std-tag"><i class="pi pi-check" /> Preset</span>
        <strong v-if="!selectedTradeVisible" class="std-name">{{ selectedProfile.label }}</strong>
        <ul v-if="profileCapabilities.length" class="std-pills">
          <li
            v-for="c in profileCapabilities"
            :key="c.label"
            :class="{ planned: c.planned }"
            :title="c.planned ? 'On the roadmap — not available yet' : undefined"
          >
            {{ c.label }}
            <span v-if="c.planned" class="soon">soon</span>
          </li>
        </ul>
      </div>

      <div class="pkg-bar">
        <span class="pkg-title">
          <i class="pi pi-box" />
          {{ selectedPlan?.name ?? 'Package' }} includes
          <strong>{{ includedModules.length }}</strong> of {{ moduleCatalogue.length }} modules
        </span>
        <span v-if="planAllowsAll" class="modules-tools">
          <button type="button" class="link-btn" @click="selectAllModules">Select all</button>
          <button type="button" class="link-btn" @click="clearModules">Clear</button>
        </span>
        <span v-else class="pkg-fixed">Fixed for this package</span>
      </div>

      <!-- One line per module in three columns, so even Enterprise's full
           catalogue fits without its own scrollbar — a scroll region inside a
           scrolling page is the worst of both. The screens each module unlocks
           moved into the count badge + `title`. -->
      <ul class="menu-listing">
        <li
          v-for="m in includedModules"
          :key="m.key"
          :class="{ picked: form.modules.includes(m.key), selectable: planAllowsAll }"
          :title="menuPreview(m)"
          @click="toggleModule(m.key)"
        >
          <i :class="m.icon" class="ml-icon" />
          <span class="ml-title">{{ m.label }}</span>
          <span v-if="menuCount(m)" class="ml-count">{{ menuCount(m) }}</span>
          <i v-if="form.modules.includes(m.key)" class="pi pi-check-circle ml-state on" />
          <i v-else-if="planAllowsAll" class="pi pi-circle ml-state" />
          <i v-else class="pi pi-check ml-state included" />
        </li>
      </ul>

      <!-- Folded shut: this is an upsell, not an input to the decision on this
           step, and open it cost 736px. -->
      <details v-if="lockedModules.length" class="locked-fold">
        <summary>
          <i class="pi pi-lock" />
          <span>
            <strong>{{ lockedModules.length }} more</strong>
            not in {{ selectedPlan?.name ?? 'this package' }} — available on {{ enterprisePlanName }}
          </span>
          <i class="pi pi-chevron-down fold-chev" />
        </summary>
        <ul class="menu-listing locked">
          <li v-for="m in lockedModules" :key="m.key" :title="menuPreview(m)">
            <i :class="m.icon" class="ml-icon" />
            <span class="ml-title">{{ m.label }}</span>
            <i class="pi pi-lock ml-state" />
          </li>
        </ul>
      </details>

      <p v-if="errors['config.modules']" class="err">{{ errors['config.modules'][0] }}</p>
    </div>

    <!-- STEP 2: Company — name + workspace address up front, the rest optional -->
    <div v-else-if="step === 2" class="form">
      <label class="field-label">Company name <span class="req">*</span></label>
      <input v-model="form.company_name" type="text" placeholder="Acme Traders" autofocus />
      <p v-if="errors.company_name" class="err">{{ errors.company_name[0] }}</p>

      <!-- Workspace address (§5): auto-suggested from the company name, checked live. -->
      <label class="field-label">Your workspace address <span class="req">*</span></label>
      <div class="url-field" :class="subState">
        <span class="url-scheme">https://</span>
        <input
          :value="form.subdomain"
          type="text"
          class="url-input"
          spellcheck="false"
          autocapitalize="off"
          autocomplete="off"
          :maxlength="30"
          placeholder="acme"
          @input="onSubdomainInput"
        />
        <span class="url-suffix">.{{ rootDomain }}</span>
        <i
          class="url-state pi"
          :class="{
            'pi-spin pi-spinner': subState === 'checking',
            'pi-check-circle ok': subState === 'available',
            'pi-times-circle bad': subState === 'taken',
            'pi-globe idle': subState === 'idle',
          }"
        />
      </div>
      <p class="hint" :class="subState">
        <template v-if="subState === 'checking'">Checking availability…</template>
        <template v-else-if="subState === 'available'">✓ {{ form.subdomain }}.{{ rootDomain }} is yours</template>
        <template v-else-if="subState === 'taken'">{{ subReason }}</template>
        <template v-else>3–30 characters: lowercase letters, numbers and hyphens.</template>
      </p>
      <div v-if="subAlternatives.length" class="alts">
        <span>Try:</span>
        <button v-for="a in subAlternatives" :key="a" type="button" class="alt" @click="useAlternative(a)">
          {{ a }}
        </button>
      </div>
      <p v-if="errors.subdomain" class="err">{{ errors.subdomain[0] }}</p>

      <!-- The trade was chosen on step 1 with a picker that groups it by
           industry; a second flat wall of all 19 here was ~260px of scrolling to
           ask the same question worse. Show the answer, offer the way back. -->
      <label class="field-label">What does your company do?</label>
      <div class="trade-summary">
        <span class="ts-icon"><i :class="selectedProfile?.icon ?? 'pi pi-sliders-h'" /></span>
        <span class="ts-body">
          <strong>{{ selectedProfile?.label ?? 'Not chosen yet' }}</strong>
          <small>{{ form.modules.length }} modules switched on</small>
        </span>
        <button type="button" class="link-btn" @click="step = 1">Change</button>
      </div>

      <details class="more">
        <summary>Tax IDs, logo &amp; address <span class="opt">(optional)</span></summary>
        <div class="more-body">
          <div class="row">
            <div class="col">
              <label class="field-label">GSTIN</label>
              <input v-model="form.gstin" type="text" placeholder="24ABCDE1234F1Z5" />
            </div>
            <div class="col">
              <label class="field-label">PAN</label>
              <input v-model="form.pan" type="text" placeholder="ABCDE1234F" />
            </div>
          </div>

          <label class="field-label">Logo</label>
          <div class="logo-row">
            <div class="logo-preview">
              <img v-if="logoUrl" :src="logoUrl" alt="logo" />
              <i v-else class="pi pi-image" />
            </div>
            <label class="upload-btn">
              <input type="file" accept="image/*" hidden @change="onLogoChange" />
              {{ logoUploading ? 'Uploading…' : (logoUrl ? 'Change logo' : 'Upload logo') }}
            </label>
          </div>

          <label class="field-label">Address</label>
          <input v-model="form.address" type="text" placeholder="Street, area" />
          <div class="row">
            <div class="col"><label class="field-label">City</label><input v-model="form.city" type="text" /></div>
            <div class="col"><label class="field-label">State</label><input v-model="form.state" type="text" /></div>
          </div>
          <div class="row">
            <div class="col"><label class="field-label">Country</label><input v-model="form.country" type="text" /></div>
            <div class="col"><label class="field-label">Pincode</label><input v-model="form.pincode" type="text" /></div>
          </div>
        </div>
      </details>
    </div>

    <!-- STEP 3: Account — owner login, then optional extras + review -->
    <div v-else-if="step === 3" class="form">
      <!-- Paired: two short fields on one line, so the owner's identity and the
           login it becomes read as one block instead of four stacked rows. -->
      <div class="row">
        <div class="col">
          <label class="field-label">Your full name <span class="req">*</span></label>
          <input v-model="form.admin_name" type="text" placeholder="Jane Doe" autofocus />
        </div>
        <div class="col">
          <label class="field-label">Work email <span class="req">*</span></label>
          <div class="input-wrap">
            <input v-model="form.email" type="email" placeholder="you@company.com" />
            <i
              v-if="emailState !== 'idle'"
              class="in-state pi"
              :class="{
                'pi-spin pi-spinner': emailState === 'checking',
                'pi-check-circle ok': emailState === 'available',
                'pi-times-circle bad': emailState === 'taken',
              }"
            />
          </div>
        </div>
      </div>
      <p v-if="emailState !== 'idle'" class="hint" :class="emailState">
        <template v-if="emailState === 'checking'">Checking availability…</template>
        <template v-else-if="emailState === 'available'">✓ Available — this becomes your login</template>
        <template v-else-if="emailState === 'taken'">{{ emailReason }}</template>
      </p>
      <p v-if="errors.email" class="err">{{ errors.email[0] }}</p>

      <div class="row">
        <div class="col">
          <label class="field-label">Phone</label>
          <input v-model="form.phone" type="tel" placeholder="+91…" />
        </div>
        <div class="col">
          <label class="field-label">Password <span class="req">*</span></label>
          <div class="input-wrap">
            <input
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Min 8 characters"
              autocomplete="new-password"
            />
            <button type="button" class="peek" :title="showPassword ? 'Hide' : 'Show'" @click="showPassword = !showPassword">
              <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" />
            </button>
          </div>
        </div>
      </div>
      <div v-if="form.password" class="pw-meter">
        <span v-for="i in 4" :key="i" :class="{ on: i <= passwordScore, [`lvl${passwordScore}`]: i <= passwordScore }" />
        <em>{{ passwordLabel }}</em>
      </div>
      <p v-if="errors.password" class="err">{{ errors.password[0] }}</p>

      <details class="more">
        <summary>Invite your team <span class="opt">(optional)</span></summary>
        <div class="more-body">
          <div v-for="u in form.team" :key="u.label" class="team-row">
            <span class="team-role">{{ u.label }}</span>
            <input v-model="u.name" type="text" placeholder="Name" />
            <input v-model="u.email" type="email" placeholder="email@company.com" />
          </div>
        </div>
      </details>

      <details class="more">
        <summary>
          Setup defaults
          <span class="opt">{{ form.branch_name }} · {{ currency.code }} · FY {{ financialYear.name }}</span>
        </summary>
        <div class="more-body">
          <div class="row">
            <div class="col"><label class="field-label">Branch name</label><input v-model="form.branch_name" type="text" /></div>
            <div class="col"><label class="field-label">Warehouse name</label><input v-model="form.warehouse_name" type="text" /></div>
          </div>
          <div class="row">
            <div class="col">
              <label class="field-label">Financial year starts in</label>
              <select v-model.number="form.fy_start_month">
                <option v-for="(mn, i) in MONTHS" :key="mn" :value="i + 1">{{ mn }}</option>
              </select>
            </div>
            <div class="col">
              <label class="field-label">Currency</label>
              <select v-model="form.currency_code">
                <option v-for="c in CURRENCIES" :key="c.code" :value="c.code">{{ c.label }}</option>
              </select>
            </div>
          </div>
          <p class="hint muted">{{ financialYear.start_date }} → {{ financialYear.end_date }}</p>

          <div class="row">
            <div class="col">
              <label class="field-label">Invoice prefix</label>
              <input v-model="form.invoice_prefix" type="text" placeholder="INV" />
            </div>
            <div class="col">
              <label class="field-label">Default GST rate</label>
              <select v-model.number="form.default_gst_rate" :disabled="!form.gst_enabled">
                <option :value="0">0%</option>
                <option :value="5">5%</option>
                <option :value="12">12%</option>
                <option :value="18">18%</option>
                <option :value="28">28%</option>
              </select>
            </div>
          </div>
          <label class="check-inline">
            <input v-model="form.gst_enabled" type="checkbox" />
            <span>Enable GST — invoices numbered {{ form.invoice_prefix || 'INV' }}-0001</span>
          </label>
        </div>
      </details>

      <!-- Two columns: the four facts fit in two rows instead of four, and the
           module list keeps the full width it needs underneath. -->
      <div class="review">
        <p class="review-head"><i class="pi pi-verified" /> Here's what we'll create</p>
        <div class="rev-grid">
          <div class="rev"><span>Package</span><b>{{ selectedPlan?.name ?? form.plan_slug }}</b></div>
          <div class="rev"><span>Trade</span><b>{{ selectedProfile?.label ?? '—' }}</b></div>
          <div class="rev"><span>Company</span><b>{{ form.company_name || '—' }}</b></div>
          <div class="rev">
            <span>Workspace</span>
            <b class="mono">{{ form.subdomain ? `${form.subdomain}.${rootDomain}` : '—' }}</b>
          </div>
        </div>
        <div class="rev rev-wrap">
          <span>Modules ({{ form.modules.length }})</span>
          <b class="rev-list">{{ selectedModuleNames || '—' }}</b>
        </div>
        <p class="review-foot">
          We'll verify your email, then generate the company, roles, permissions, menus and dashboard.
        </p>
      </div>

      <!-- Checkbox and its volume on one line: the select used to appear as a
           whole labelled field-row below, which pushed Continue off-screen the
           moment the box was ticked. -->
      <div class="demo-row">
        <label class="check-inline">
          <input v-model="form.load_demo" type="checkbox" />
          <span>Load sample data so I can explore</span>
        </label>
        <select v-if="form.load_demo" v-model.number="form.demo_count" class="demo-count" aria-label="Sample records per type">
          <option :value="100">100 records</option>
          <option :value="500">500 records</option>
          <option :value="1000">1,000 records</option>
        </select>
      </div>
    </div>

    <p v-if="serverError" class="err server">{{ serverError }}</p>

    <!-- Nav -->
    <div class="nav">
      <button v-if="step > 1" type="button" class="ghost" @click="back">
        <i class="pi pi-arrow-left" /> Back
      </button>
      <span class="spacer" />
      <button v-if="step < STEPS.length" type="button" class="submit" :disabled="!stepValid" @click="next">
        Continue <i class="pi pi-arrow-right" />
      </button>
      <button v-else type="button" class="submit" :disabled="submitting || !stepValid" @click="submit">
        <i v-if="submitting" class="pi pi-spin pi-spinner" />
        {{ submitting ? 'Creating…' : 'Create & verify' }}
      </button>
    </div>

    <p class="signin-hint">Already have an account? <a href="/login">Sign in</a></p>
  </AuthShell>
</template>

<style scoped>
/* ---------- Card header ---------- */
.head {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding-bottom: 0.8rem;
  margin-bottom: 0.85rem;
  border-bottom: 1px solid #f0ede6;
}
.kicker {
  margin: 0 0 0.3rem;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: #c98730;
}
.head h1 {
  font-size: 1.22rem;
  line-height: 1.25;
  letter-spacing: -0.01em;
}
.head .sub {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
  line-height: 1.45;
  color: #6b7280;
}
/* Conic progress ring — reads faster than a row of dots. */
.ring {
  flex: none;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: conic-gradient(#e8a33d var(--pct), #efece4 0);
  transition: background 0.4s ease;
}
.ring span {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #fff;
  display: grid;
  place-items: center;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.9rem;
  font-weight: 700;
  color: #1e2328;
}
.ring small {
  font-size: 0.62rem;
  color: #9ca3af;
}

/* ---------- Fields ---------- */
.form {
  display: flex;
  flex-direction: column;
}
.field-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #374151;
  /* 0.9rem before: with ~8 labels per step that rhythm alone was ~80px of
     scrolling. */
  margin: 0.7rem 0 0.28rem;
}
.form > .field-label:first-child {
  margin-top: 0;
}
.opt {
  font-weight: 400;
  color: #9ca3af;
}
.req {
  color: #c1502e;
}
input,
select {
  width: 100%;
  box-sizing: border-box;
  height: 44px;
  border: 1px solid #ddd7ca;
  border-radius: 10px;
  padding: 0 0.85rem;
  font-size: 0.9rem;
  font-family: inherit;
  color: #1e2328;
  outline: none;
  background: #fbfaf7;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
input::placeholder {
  color: #a8a295;
}
input:hover,
select:hover {
  border-color: #c4bdae;
}
input:focus,
select:focus {
  border-color: #e8a33d;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.15);
}
select {
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 0.85rem center;
  padding-right: 2rem;
  cursor: pointer;
}
.row {
  display: flex;
  gap: 0.75rem;
}
.col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.input-wrap input {
  padding-right: 2.5rem;
}
.in-state {
  position: absolute;
  right: 0.85rem;
  font-size: 0.95rem;
  color: #9ca3af;
  pointer-events: none;
}
.in-state.ok {
  color: #4c8b6c;
}
.in-state.bad {
  color: #c1502e;
}
.peek {
  position: absolute;
  right: 0.5rem;
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  padding: 0.35rem;
  line-height: 1;
}
.peek:hover {
  color: #6b7280;
}

/* ---------- Workspace address ---------- */
.url-field {
  display: flex;
  align-items: center;
  height: 46px;
  border: 1px solid #ddd7ca;
  border-radius: 10px;
  background: #fbfaf7;
  padding: 0 0.75rem 0 0.85rem;
  gap: 0.1rem;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  overflow: hidden;
}
.url-field:focus-within {
  border-color: #e8a33d;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.15);
}
.url-field.available {
  border-color: #9ac0ab;
  background: rgba(76, 139, 108, 0.05);
}
.url-field.taken {
  border-color: #e0a893;
  background: rgba(193, 80, 46, 0.04);
}
.url-scheme,
.url-suffix {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.85rem;
  color: #9b958a;
  white-space: nowrap;
  flex: none;
}
.url-input {
  flex: 1;
  min-width: 3rem;
  height: auto;
  border: none;
  background: none;
  padding: 0;
  border-radius: 0;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.92rem;
  font-weight: 600;
  color: #1e2328;
}
.url-input:hover,
.url-input:focus {
  border: none;
  background: none;
  box-shadow: none;
}
.url-state {
  flex: none;
  margin-left: 0.55rem;
  font-size: 0.95rem;
  color: #b8b2a5;
}
.url-state.ok {
  color: #4c8b6c;
}
.url-state.bad {
  color: #c1502e;
}
.alts {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.5rem;
  font-size: 0.78rem;
  color: #6b7280;
}
.alt {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.75rem;
  font-weight: 600;
  color: #2c4a5e;
  background: #f1eee6;
  border: 1px solid #e3ded3;
  border-radius: 999px;
  padding: 0.2rem 0.6rem;
  cursor: pointer;
  transition: all 0.15s;
}
.alt:hover {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.14);
  color: #8a5a12;
}

/* ---------- Password strength ---------- */
.pw-meter {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.5rem;
}
.pw-meter span {
  flex: 1;
  height: 4px;
  border-radius: 999px;
  background: #eeebe3;
  transition: background 0.2s;
}
.pw-meter span.on {
  background: #c1502e;
}
.pw-meter span.on.lvl2 {
  background: #e8a33d;
}
.pw-meter span.on.lvl3 {
  background: #8fb28c;
}
.pw-meter span.on.lvl4 {
  background: #4c8b6c;
}
.pw-meter em {
  flex: none;
  font-style: normal;
  font-size: 0.72rem;
  font-weight: 700;
  color: #6b7280;
  min-width: 3.2rem;
  text-align: right;
}

/* ---------- Step 1 pickers ---------- */
.logo-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.logo-preview {
  width: 52px;
  height: 52px;
  border-radius: 10px;
  border: 1px dashed #d8d3c8;
  display: grid;
  place-items: center;
  overflow: hidden;
  color: #b8b2a5;
  flex: none;
}
.logo-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.upload-btn {
  display: inline-block;
  border: 1px solid #ddd7ca;
  border-radius: 8px;
  padding: 0.5rem 0.9rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: #2c4a5e;
  cursor: pointer;
  margin: 0;
  transition: all 0.15s;
}
.upload-btn:hover {
  border-color: #e8a33d;
  background: #fdfbf5;
}
/* Step 2's read-back of the trade chosen on step 1. */
.trade-summary {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.35rem;
  padding: 0.5rem 0.7rem;
  border: 1px solid #e8e4da;
  border-radius: 11px;
  background: linear-gradient(180deg, #fff, #faf8f3);
}
.ts-icon {
  flex: none;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #f2c274, #e8a33d 60%, #c98730);
  color: #1e2328;
  font-size: 0.85rem;
}
.ts-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;
}
.ts-body strong {
  font-size: 0.86rem;
  color: #1e2328;
}
.ts-body small {
  font-size: 0.72rem;
  color: #9ca3af;
}
.trade-summary .link-btn {
  margin-left: auto;
}

/* Industry selector — one wrapping row of pills above the trade chips. */
.industry-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.35rem;
}
.industry-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  border: 1px solid #e8e4da;
  background: #fff;
  border-radius: 999px;
  padding: 0.3rem 0.65rem;
  font-family: inherit;
  font-size: 0.75rem;
  font-weight: 600;
  color: #6b7280;
  cursor: pointer;
  transition:
    border-color 0.15s,
    background 0.15s,
    color 0.15s;
}
.industry-pill i {
  font-size: 0.78rem;
  color: #b9b2a3;
  transition: color 0.15s;
}
.industry-pill:hover {
  border-color: #ddd5c4;
  color: #374151;
}
.industry-pill.active {
  border-color: #2c4a5e;
  background: #2c4a5e;
  color: #fff;
}
.industry-pill.active i {
  color: var(--safety-amber-light, #f2c274);
}
.shop-types {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(132px, 1fr));
  gap: 0.4rem;
  margin-top: 0.45rem;
  align-items: stretch;
}
.shop-type {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  min-width: 0;
  text-align: left;
  border: 1px solid #e8e4da;
  background: #fbfaf7;
  border-radius: 9px;
  padding: 0.5rem 0.6rem;
  cursor: pointer;
  font-family: inherit;
  color: #374151;
  transition:
    border-color 0.15s,
    background 0.15s,
    transform 0.15s;
}
.shop-type:hover {
  border-color: #ddd5c4;
  transform: translateY(-1px);
}
.shop-type i {
  flex: none;
  font-size: 0.95rem;
  color: #2c4a5e;
}
.shop-type.active {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.12);
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.shop-type.active i {
  color: #8a5a12;
}
.st-label {
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.25;
  color: #1e2328;
  /* Two lines, then ellipsis. One line clipped most of these labels to
     "Manufacturi…" / "Import / Exp…", which is worse than a taller chip. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}
/* Only the chosen trade explains itself — and it does so as one pill per
   capability rather than a run-on sentence, matching the chip language of
   the grid above. */
/* One strip: the "Preset" badge sits inline with the capability pills rather
   than owning a title row of its own. */
.st-detail {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0.5rem 0 0;
  padding: 0.5rem 0.7rem;
  border: 1px solid #f0dcb8;
  border-radius: 11px;
  background: linear-gradient(180deg, #fffdf8, rgba(232, 163, 61, 0.07));
}
.std-tag {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.15rem;
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #4c8b6c;
}
/* Only rendered while the chosen trade's chip is off screen (another industry
   is being browsed) — otherwise the lit chip already names it. */
.std-name {
  flex: none;
  margin-top: 0.1rem;
  font-size: 0.8rem;
  color: #1e2328;
}
.std-pills {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}
.std-pills li {
  border: 1px solid #ecd7ae;
  border-radius: 999px;
  background: #fff;
  padding: 0.2rem 0.6rem;
  font-size: 0.73rem;
  line-height: 1.35;
  color: #4b5563;
}
/* Sentence-shaped descriptions ("Start from the essentials …") produce long
   pills; keep them from looking like a broken row. */
.std-pills li:first-letter {
  text-transform: uppercase;
}
/* A capability the trade declares but has not shipped. Shown rather than hidden
   so the list is the whole promise, and dimmed so it cannot be mistaken for one
   of the things being bought today. */
.std-pills li.planned {
  border-style: dashed;
  border-color: #d8dde5;
  background: transparent;
  color: #9ca3af;
}
.std-pills li .soon {
  margin-left: 0.3rem;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.85;
}

/* ---------- Module listing ---------- */
.pkg-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin: 1.1rem 0 0.5rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid #e8e4da;
  border-radius: 10px;
  background: linear-gradient(180deg, #fff, #f8f6f0);
}
.pkg-title {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.82rem;
  color: #374151;
}
.pkg-title strong {
  color: #1e2328;
}
.pkg-fixed {
  font-size: 0.72rem;
  font-weight: 600;
  color: #9ca3af;
}
.modules-tools {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}
.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: 0.78rem;
  font-weight: 600;
  font-family: inherit;
  color: #2c4a5e;
  cursor: pointer;
  text-decoration: underline;
}
/* One line per module, three across at the card's full width: the whole
   catalogue then fits on screen without its own scrollbar. */
.menu-listing {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(178px, 1fr));
  gap: 0.35rem;
}
.menu-listing li {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  border: 1px solid #e8e4da;
  border-radius: 9px;
  background: #fbfaf7;
  padding: 0.42rem 0.55rem;
  transition: all 0.15s;
}
.menu-listing li.selectable {
  cursor: pointer;
}
.menu-listing li.selectable:hover {
  border-color: #e8c489;
  background: #fdfbf5;
}
.menu-listing li.picked {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.1);
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.menu-listing.locked li {
  opacity: 0.6;
  border-style: dashed;
  background: #f6f4ef;
}
.ml-icon {
  flex: none;
  font-size: 0.9rem;
  color: #8a5a12;
}
.ml-title {
  flex: 1;
  min-width: 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: #1e2328;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* How many screens the module unlocks — the count stands in for the list, which
   is on the row's tooltip. */
.ml-count {
  flex: none;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.64rem;
  font-weight: 700;
  color: #8a5a12;
  background: rgba(232, 163, 61, 0.16);
  border-radius: 999px;
  padding: 0.05rem 0.34rem;
}
.ml-state {
  flex: none;
  font-size: 0.8rem;
  color: #cfc8b8;
}
.ml-state.on,
.ml-state.included {
  color: #4c8b6c;
}

/* ---------- Locked modules (folded) ---------- */
.locked-fold {
  margin-top: 0.9rem;
  border: 1px dashed #ddd5c4;
  border-radius: 10px;
  background: #faf8f3;
}
.locked-fold summary {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.6rem 0.75rem;
  cursor: pointer;
  font-size: 0.76rem;
  color: #8a5a12;
  list-style: none;
}
.locked-fold summary::-webkit-details-marker {
  display: none;
}
.locked-fold summary strong {
  font-weight: 800;
}
.fold-chev {
  margin-left: auto;
  font-size: 0.7rem;
  transition: transform 0.2s;
}
.locked-fold[open] .fold-chev {
  transform: rotate(180deg);
}
.locked-fold .menu-listing {
  padding: 0 0.75rem 0.75rem;
}

/* ---------- Optional groups ---------- */
.more {
  margin-top: 0.9rem;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  background: #fbfaf7;
}
.more > summary {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  padding: 0.7rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: #374151;
  cursor: pointer;
  list-style: none;
}
.more > summary::-webkit-details-marker {
  display: none;
}
.more > summary::after {
  content: '\e902';
  font-family: 'primeicons';
  margin-left: auto;
  font-size: 0.7rem;
  font-weight: 400;
  color: #9ca3af;
  transition: transform 0.15s;
}
.more[open] > summary::after {
  transform: rotate(180deg);
}
.more[open] > summary {
  border-bottom: 1px solid #f0ede6;
}
.more-body {
  padding: 0.2rem 0.85rem 0.85rem;
  display: flex;
  flex-direction: column;
}
.team-row {
  display: grid;
  grid-template-columns: 80px 1fr 1fr;
  gap: 0.5rem;
  align-items: center;
  margin-top: 0.5rem;
}
.team-role {
  font-size: 0.8rem;
  font-weight: 600;
  color: #6b7280;
}

/* ---------- Checkbox card ---------- */
.check-inline {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  font-weight: 500;
  font-size: 0.88rem;
  color: #374151;
  margin-top: 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  background: #fbfaf7;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
}
.check-inline:hover {
  border-color: #e8c489;
  background: #fdfbf5;
}
.check-inline:has(input:checked) {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.1);
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.check-inline input {
  appearance: none;
  -webkit-appearance: none;
  flex: none;
  width: 22px;
  height: 22px;
  margin: 0;
  border: 1.5px solid #cfc8b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  position: relative;
  transition: border-color 0.15s, background 0.15s;
}
.check-inline input:checked {
  border-color: #c98730;
  background: linear-gradient(135deg, #eaad48, #c98730);
}
.check-inline input:checked::after {
  content: '';
  position: absolute;
  left: 7px;
  top: 3px;
  width: 5px;
  height: 10px;
  border: solid #fff;
  border-width: 0 2.5px 2.5px 0;
  transform: rotate(45deg);
}
.check-inline input:focus-visible {
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.25);
}

/* ---------- Review ---------- */
.review {
  margin-top: 1.1rem;
  background: linear-gradient(180deg, #fffdf8, #faf7f0);
  border: 1px solid #ece5d6;
  border-radius: 12px;
  padding: 0.35rem 0.95rem 0.6rem;
}
.review-head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0.6rem 0 0.4rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #8a5a12;
}
/* Two facts per row: four stacked rows was 60px of scrolling for four short
   values, and they compare better side by side anyway. */
.rev-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 1.1rem;
}
.rev {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.38rem 0;
  font-size: 0.83rem;
  border-top: 1px solid #f0ebdf;
  min-width: 0;
}
.rev span {
  color: #6b7280;
  flex: none;
}
.rev b {
  text-align: right;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.review-foot {
  margin: 0.5rem 0 0.1rem;
  font-size: 0.74rem;
  line-height: 1.45;
  color: #9ca3af;
}
.rev.rev-wrap {
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
}
.rev-list {
  font-weight: 600;
  color: #374151;
  font-size: 0.78rem;
  line-height: 1.35;
  text-align: left;
  /* Two lines of module names, then ellipsis: the full set is one line up in
     the count, and a 19-module list otherwise ran four lines. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  white-space: normal;
}
/* Checkbox + its volume on one line. */
.demo-row {
  display: flex;
  align-items: stretch;
  gap: 0.6rem;
  margin-top: 0.9rem;
}
.demo-row .check-inline {
  flex: 1;
  min-width: 0;
  margin-top: 0;
}
.demo-count {
  flex: none;
  width: 9.5rem;
}
.mono {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.8rem;
}

/* ---------- Feedback + nav ---------- */
.hint {
  font-size: 0.78rem;
  margin: 0.3rem 0 0;
  min-height: 1rem;
}
.hint.muted,
.hint.idle {
  color: #9ca3af;
}
.hint.available {
  color: #4c8b6c;
}
.hint.taken {
  color: #c1502e;
}
.hint.checking {
  color: #9ca3af;
}
.err {
  color: #c1502e;
  font-size: 0.78rem;
  margin: 0.25rem 0 0;
}
.err.server {
  margin-top: 0.9rem;
  padding: 0.65rem 0.8rem;
  border: 1px solid #eccfc4;
  border-radius: 10px;
  background: rgba(193, 80, 46, 0.07);
}
.nav {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 1.6rem;
  padding-top: 1.1rem;
  border-top: 1px solid #f0ede6;
}
.spacer {
  flex: 1;
}
.ghost {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  background: none;
  border: 1px solid #ddd7ca;
  border-radius: 10px;
  padding: 0.65rem 1.1rem;
  font-size: 0.88rem;
  font-weight: 600;
  font-family: inherit;
  color: #374151;
  cursor: pointer;
  transition: all 0.15s;
}
.ghost:hover {
  border-color: #c4bdae;
  background: #fbfaf7;
}
.submit {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 10px;
  padding: 0.7rem 1.5rem;
  font-weight: 700;
  font-size: 0.9rem;
  font-family: inherit;
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(201, 135, 48, 0.28);
  transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
}
.submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(201, 135, 48, 0.34);
}
.submit:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  box-shadow: none;
}
.signin-hint {
  text-align: center;
  font-size: 0.82rem;
  color: #6b7280;
  margin: 1.1rem 0 0;
}
.signin-hint a {
  color: #c98730;
  font-weight: 600;
  text-decoration: none;
}
.signin-hint a:hover {
  text-decoration: underline;
}

@media (max-width: 560px) {
  .row {
    flex-direction: column;
    gap: 0;
  }
  .team-row {
    grid-template-columns: 1fr;
    gap: 0.35rem;
  }
  .head h1 {
    font-size: 1.15rem;
  }
  .ring {
    display: none;
  }
  .nav .submit,
  .nav .ghost {
    flex: 1;
    justify-content: center;
  }
  /* Two review facts per row is fine at 620px, cramped on a phone. */
  .rev-grid {
    grid-template-columns: 1fr;
  }
  /* The sample-data volume drops under its checkbox rather than squeezing
     beside it. */
  .demo-row {
    flex-direction: column;
    align-items: stretch;
  }
  .demo-count {
    width: 100%;
  }
  /* One trade chip per line reads better than two clipped ones. */
  .shop-types {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
