<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../api/http'
import PlanPicker from '../../components/PlanPicker.vue'

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
}

const router = useRouter()

// Three steps on purpose: pick a package, say who you are, create the account.
// Everything optional (address, team invites, numbering/tax defaults) sits inside
// collapsible sections so the visible surface per step stays short.
const STEPS = ['Package', 'Company', 'Account']
const step = ref(1)

// Shop-type presets. Answering "what kind of business is this?" ticks the modules
// that trade normally runs on, so a kirana shop is never asked to reason about
// Embroidery. The real list comes from GET /shop-profiles (App\Support\ShopProfiles);
// this fallback only keeps the step usable if that call fails.
const FALLBACK_PROFILES: ShopProfile[] = [
  { key: 'grocery', label: 'Grocery / Kirana', icon: 'pi pi-shopping-basket', description: '', modules: ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices'] },
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
const logoUploading = ref(false)
const logoUrl = ref<string | null>(null)

const form = ref({
  plan_slug: 'starter',
  // Step 1 — company profile
  company_name: '',
  gstin: '',
  pan: '',
  logo_path: '' as string,
  address: '',
  city: '',
  state: '',
  country: 'India',
  pincode: '',
  // Step 2 — trade. `shop_profile` is the preset key; `industry` is its label,
  // kept in step because the tenant setting has always stored a readable string.
  shop_profile: 'garment',
  industry: 'Garment / Apparel',
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
const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const serverError = ref('')

onMounted(async () => {
  try {
    const [planRes, moduleRes, profileRes] = await Promise.all([
      http.get('/plans'),
      http.get('/modules'),
      http.get('/shop-profiles'),
    ])
    plans.value = planRes.data.data
    if (moduleRes.data.data?.length) moduleCatalogue.value = moduleRes.data.data
    if (profileRes.data.data?.length) shopProfiles.value = profileRes.data.data
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
  if (profile) form.value.industry = profile.label
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
      return !!form.value.company_name.trim()
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
  if (step.value < STEPS.length && stepValid.value) step.value++
}
function back() {
  if (step.value > 1) step.value--
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
      else if (errors.value.company_name) step.value = 2
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
  <div class="signup">
    <div class="panel">
      <div class="brand">
        <div class="brand-icon"><i class="pi pi-box" /></div>
        <h1>Set up your company</h1>
        <p>Step {{ step }} of {{ STEPS.length }} · {{ STEPS[step - 1] }}</p>
      </div>

      <!-- Stepper -->
      <div class="stepper">
        <div
          v-for="(s, i) in STEPS"
          :key="s"
          class="dot"
          :class="{ done: i + 1 < step, active: i + 1 === step }"
          :title="s"
        >
          <i v-if="i + 1 < step" class="pi pi-check" />
          <span v-else>{{ i + 1 }}</span>
        </div>
      </div>

      <!-- STEP 1: Package — what it unlocks, menu by menu -->
      <div v-if="step === 1" class="form">
        <label>Your package <span class="req">*</span></label>
        <p class="hint muted">Each package unlocks the menus listed below. You can change this later.</p>

        <PlanPicker v-model="form.plan_slug" :plans="plans" :modules="moduleCatalogue" />

        <label>What kind of business is this?</label>
        <p class="hint muted">
          We'll switch on the modules this trade usually runs on.
          {{ planAllowsAll ? 'Change any of them below.' : '' }}
        </p>
        <div class="shop-types">
          <button
            v-for="p in shopProfiles"
            :key="p.key"
            type="button"
            class="shop-type"
            :class="{ active: form.shop_profile === p.key }"
            @click="applyProfile(p.key)"
          >
            <i :class="p.icon" />
            <span class="st-label">{{ p.label }}</span>
            <span class="st-desc">{{ p.description }}</span>
          </button>
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

        <ul class="menu-listing">
          <li
            v-for="m in includedModules"
            :key="m.key"
            :class="{ picked: form.modules.includes(m.key), selectable: planAllowsAll }"
            @click="toggleModule(m.key)"
          >
            <div class="ml-head">
              <i :class="m.icon" class="ml-icon" />
              <span class="ml-title">{{ m.label }}</span>
              <i v-if="form.modules.includes(m.key)" class="pi pi-check-circle ml-state on" />
              <i v-else-if="planAllowsAll" class="pi pi-circle ml-state" />
              <span v-else class="ml-tag">Included</span>
            </div>
            <p class="ml-menus">{{ menuPreview(m) }}</p>
          </li>
        </ul>

        <template v-if="lockedModules.length">
          <div class="locked-head">
            <i class="pi pi-lock" />
            Not in {{ selectedPlan?.name ?? 'this package' }} — available on {{ enterprisePlanName }}
          </div>
          <ul class="menu-listing locked">
            <li v-for="m in lockedModules" :key="m.key">
              <div class="ml-head">
                <i :class="m.icon" class="ml-icon" />
                <span class="ml-title">{{ m.label }}</span>
                <i class="pi pi-lock ml-state" />
              </div>
              <p class="ml-menus">{{ menuPreview(m) }}</p>
            </li>
          </ul>
        </template>

        <p v-if="errors['config.modules']" class="err">{{ errors['config.modules'][0] }}</p>
      </div>

      <!-- STEP 2: Company — name + industry up front, the rest optional -->
      <div v-else-if="step === 2" class="form">
        <label>Company name <span class="req">*</span></label>
        <input v-model="form.company_name" type="text" placeholder="Acme Traders" />
        <p v-if="errors.company_name" class="err">{{ errors.company_name[0] }}</p>

        <label>What does your company do?</label>
        <p class="hint muted">Changing this re-ticks the module selection on step 1.</p>
        <div class="grid-choice">
          <button
            v-for="p in shopProfiles"
            :key="p.key"
            type="button"
            class="choice"
            :class="{ active: form.shop_profile === p.key }"
            @click="applyProfile(p.key)"
          >
            {{ p.label }}
          </button>
        </div>

        <details class="more">
          <summary>Tax IDs, logo &amp; address <span class="opt">(optional)</span></summary>
          <div class="more-body">
            <div class="row">
              <div class="col">
                <label>GSTIN</label>
                <input v-model="form.gstin" type="text" placeholder="24ABCDE1234F1Z5" />
              </div>
              <div class="col">
                <label>PAN</label>
                <input v-model="form.pan" type="text" placeholder="ABCDE1234F" />
              </div>
            </div>

            <label>Logo</label>
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

            <label>Address</label>
            <input v-model="form.address" type="text" placeholder="Street, area" />
            <div class="row">
              <div class="col"><label>City</label><input v-model="form.city" type="text" /></div>
              <div class="col"><label>State</label><input v-model="form.state" type="text" /></div>
            </div>
            <div class="row">
              <div class="col"><label>Country</label><input v-model="form.country" type="text" /></div>
              <div class="col"><label>Pincode</label><input v-model="form.pincode" type="text" /></div>
            </div>
          </div>
        </details>
      </div>

      <!-- STEP 3: Account — owner login, then optional extras + review -->
      <div v-else-if="step === 3" class="form">
        <label>Your full name <span class="req">*</span></label>
        <input v-model="form.admin_name" type="text" placeholder="Jane Doe" />

        <label>Work email <span class="req">*</span></label>
        <input v-model="form.email" type="email" placeholder="you@company.com" />
        <p class="hint" :class="emailState">
          <template v-if="emailState === 'checking'">Checking availability…</template>
          <template v-else-if="emailState === 'available'">✓ Available</template>
          <template v-else-if="emailState === 'taken'">{{ emailReason }}</template>
        </p>
        <p v-if="errors.email" class="err">{{ errors.email[0] }}</p>

        <div class="row">
          <div class="col">
            <label>Phone</label>
            <input v-model="form.phone" type="tel" placeholder="+91…" />
          </div>
          <div class="col">
            <label>Password <span class="req">*</span></label>
            <input v-model="form.password" type="password" placeholder="Min 8 characters" />
          </div>
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
              <div class="col"><label>Branch name</label><input v-model="form.branch_name" type="text" /></div>
              <div class="col"><label>Warehouse name</label><input v-model="form.warehouse_name" type="text" /></div>
            </div>
            <div class="row">
              <div class="col">
                <label>Financial year starts in</label>
                <select v-model.number="form.fy_start_month">
                  <option v-for="(mn, i) in MONTHS" :key="mn" :value="i + 1">{{ mn }}</option>
                </select>
              </div>
              <div class="col">
                <label>Currency</label>
                <select v-model="form.currency_code">
                  <option v-for="c in CURRENCIES" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>
              </div>
            </div>
            <p class="hint muted">{{ financialYear.start_date }} → {{ financialYear.end_date }}</p>

            <div class="row">
              <div class="col">
                <label>Invoice prefix</label>
                <input v-model="form.invoice_prefix" type="text" placeholder="INV" />
              </div>
              <div class="col">
                <label>Default GST rate</label>
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

        <div class="review">
          <div class="rev"><span>Package</span><b>{{ selectedPlan?.name ?? form.plan_slug }}</b></div>
          <div class="rev rev-wrap">
            <span>Modules ({{ form.modules.length }})</span>
            <b class="rev-list">{{ selectedModuleNames || '—' }}</b>
          </div>
          <div class="rev"><span>Company</span><b>{{ form.company_name || '—' }} · {{ form.industry }}</b></div>
        </div>

        <label class="check-inline">
          <input v-model="form.load_demo" type="checkbox" />
          <span>Load sample data so I can explore</span>
        </label>
        <div v-if="form.load_demo" class="row">
          <div class="col">
            <label>Sample records per type</label>
            <select v-model.number="form.demo_count">
              <option :value="100">100</option>
              <option :value="500">500</option>
              <option :value="1000">1,000</option>
            </select>
          </div>
        </div>

        <p class="hint muted">
          Next we'll verify your email, then generate your company, roles, permissions, menus, users and dashboard.
        </p>
      </div>

      <p v-if="serverError" class="err server">{{ serverError }}</p>

      <!-- Nav -->
      <div class="nav">
        <button v-if="step > 1" type="button" class="ghost" @click="back">Back</button>
        <span class="spacer" />
        <button v-if="step < STEPS.length" type="button" class="submit" :disabled="!stepValid" @click="next">
          Continue
        </button>
        <button v-else type="button" class="submit" :disabled="submitting || !stepValid" @click="submit">
          {{ submitting ? 'Creating…' : 'Create & verify' }}
        </button>
      </div>

      <p class="signin-hint">Already have an account? <a href="/login">Sign in</a></p>
    </div>
  </div>
</template>

<style scoped>
.signup { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #2c4a5e 0%, #1e2328 65%, #171a1e 100%); padding: 1.5rem; }
.panel { background: #fff; border-radius: 16px; padding: 2rem 2.25rem; width: 100%; max-width: 500px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35); }
.brand { text-align: center; }
.brand-icon { width: 48px; height: 48px; margin: 0 auto 0.5rem; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #eaad48, #c98730); box-shadow: 0 8px 20px rgba(232, 163, 61, 0.35); }
.brand-icon i { font-size: 1.4rem; color: #1e2328; }
.brand h1 { font-size: 1.4rem; margin-top: 0.2rem; }
.brand p { color: #6b7280; font-size: 0.85rem; }
.stepper { display: flex; justify-content: center; gap: 0.4rem; margin: 1.1rem 0 1.4rem; }
.dot { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 0.75rem; font-weight: 700; background: #eee; color: #9ca3af; }
.dot.active { background: #e8a33d; color: #1e2328; }
.dot.done { background: #4c8b6c; color: #fff; }
.form { display: flex; flex-direction: column; }
label { font-size: 0.82rem; font-weight: 600; color: #374151; margin: 0.7rem 0 0.25rem; }
.opt { font-weight: 400; color: #9ca3af; }
.req { color: #c1502e; }
input, select { width: 100%; box-sizing: border-box; height: 44px; border: 1px solid #d8d3c8; border-radius: 10px; padding: 0 0.85rem; font-size: 0.9rem; font-family: inherit; color: #1e2328; outline: none; background: #fbfaf7; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; }
input::placeholder { color: #a8a295; }
input:hover, select:hover { border-color: #c4bdae; }
input:focus, select:focus { border-color: #e8a33d; background: #fff; box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.15); }
select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.85rem center; padding-right: 2rem; cursor: pointer; }
.row { display: flex; gap: 0.75rem; }
.col { flex: 1; display: flex; flex-direction: column; }
/* Package cards live in components/PlanPicker.vue (used by step 1 and step 3). */
.logo-row { display: flex; align-items: center; gap: 0.75rem; }
.logo-preview { width: 52px; height: 52px; border-radius: 10px; border: 1px dashed #d8d3c8; display: grid; place-items: center; overflow: hidden; color: #b8b2a5; }
.logo-preview img { width: 100%; height: 100%; object-fit: cover; }
.upload-btn { display: inline-block; border: 1px solid #d8d3c8; border-radius: 8px; padding: 0.5rem 0.9rem; font-size: 0.85rem; font-weight: 600; color: #2c4a5e; cursor: pointer; margin: 0; }
.grid-choice { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-top: 0.4rem; }
.choice { border: 1px solid #e8e4da; background: #fbfaf7; border-radius: 10px; padding: 0.8rem 0.5rem; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: #374151; }
.choice.active { border-color: #e8a33d; background: rgba(232, 163, 61, 0.12); box-shadow: 0 0 0 1px #e8a33d inset; }
.choice.module { display: flex; flex-direction: column; align-items: center; gap: 0.3rem; position: relative; }
.choice.module i { font-size: 1.1rem; }
.choice .check { position: absolute; top: 0.35rem; right: 0.35rem; color: #4c8b6c; font-size: 0.7rem; }
/* Step 1 — shop-type presets. Auto-fit so the row reflows on a phone instead of
   squeezing seven cards into a fixed grid. */
.shop-types { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.5rem; margin-top: 0.4rem; }
.shop-type { display: flex; flex-direction: column; align-items: flex-start; gap: 0.2rem; text-align: left; border: 1px solid #e8e4da; background: #fbfaf7; border-radius: 10px; padding: 0.7rem 0.75rem; cursor: pointer; color: #374151; }
.shop-type i { font-size: 1.05rem; color: #2c4a5e; }
.shop-type.active { border-color: #e8a33d; background: rgba(232, 163, 61, 0.12); box-shadow: 0 0 0 1px #e8a33d inset; }
.st-label { font-size: 0.85rem; font-weight: 700; color: #1e2328; }
.st-desc { font-size: 0.72rem; line-height: 1.3; color: #6b7280; }
/* Step 3 — package summary + the menus each module unlocks. */
.rev.rev-wrap { flex-direction: column; align-items: flex-start; gap: 0.15rem; }
.rev-list { font-weight: 600; color: #374151; font-size: 0.8rem; line-height: 1.35; }
.pkg-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin: 0.9rem 0 0.5rem; padding: 0.55rem 0.75rem; border: 1px solid #e8e4da; border-radius: 10px; background: #fbfaf7; }
.pkg-title { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; color: #374151; }
.pkg-title strong { color: #1e2328; }
.pkg-fixed { font-size: 0.72rem; font-weight: 600; color: #9ca3af; }
.modules-tools { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.link-btn { background: none; border: none; padding: 0; font-size: 0.78rem; font-weight: 600; color: #2c4a5e; cursor: pointer; text-decoration: underline; }
.menu-listing { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; }
.menu-listing li { border: 1px solid #e8e4da; border-radius: 10px; background: #fbfaf7; padding: 0.55rem 0.7rem; }
.menu-listing li.selectable { cursor: pointer; }
.menu-listing li.selectable:hover { border-color: #e8c489; }
.menu-listing li.picked { border-color: #e8a33d; background: rgba(232, 163, 61, 0.1); box-shadow: 0 0 0 1px #e8a33d inset; }
.menu-listing.locked li { opacity: 0.6; border-style: dashed; background: #f6f4ef; }
.ml-head { display: flex; align-items: center; gap: 0.45rem; }
.ml-icon { font-size: 0.95rem; color: #8a5a12; }
.ml-title { font-size: 0.86rem; font-weight: 700; color: #1e2328; }
.ml-state { margin-left: auto; font-size: 0.85rem; color: #cfc8b8; }
.ml-state.on { color: #4c8b6c; }
.ml-tag { margin-left: auto; font-size: 0.68rem; font-weight: 700; color: #4c8b6c; background: rgba(76, 139, 108, 0.13); border-radius: 999px; padding: 0.12rem 0.45rem; }
.ml-menus { margin: 0.2rem 0 0 1.4rem; font-size: 0.74rem; color: #6b7280; line-height: 1.35; }
.locked-head { display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 700; color: #8a5a12; margin: 1rem 0 0.4rem; }
.section-label { font-size: 0.9rem; font-weight: 700; color: #1e2328; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #f0ede6; }
/* Optional groups: collapsed by default so each step stays short. */
.more { margin-top: 0.9rem; border: 1px solid #e8e4da; border-radius: 12px; background: #fbfaf7; }
.more > summary { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.65rem 0.85rem; font-size: 0.82rem; font-weight: 700; color: #374151; cursor: pointer; list-style: none; }
.more > summary::-webkit-details-marker { display: none; }
.more > summary::after { content: '\e902'; font-family: 'primeicons'; margin-left: auto; font-size: 0.7rem; font-weight: 400; color: #9ca3af; transition: transform 0.15s; }
.more[open] > summary::after { transform: rotate(180deg); }
.more[open] > summary { border-bottom: 1px solid #f0ede6; }
.more-body { padding: 0.2rem 0.85rem 0.85rem; display: flex; flex-direction: column; }
.more-body > label:first-child { margin-top: 0.5rem; }
.team-row { display: grid; grid-template-columns: 80px 1fr 1fr; gap: 0.5rem; align-items: center; margin-top: 0.5rem; }
.team-role { font-size: 0.8rem; font-weight: 600; color: #6b7280; }
.check-inline { display: flex; align-items: center; gap: 0.7rem; font-weight: 500; font-size: 0.88rem; color: #374151; margin-top: 1rem; padding: 0.85rem 1rem; border: 1px solid #e8e4da; border-radius: 12px; background: #fbfaf7; cursor: pointer; transition: border-color 0.15s, background 0.15s, box-shadow 0.15s; }
.check-inline:hover { border-color: #e8c489; background: #fdfbf5; }
.check-inline:has(input:checked) { border-color: #e8a33d; background: rgba(232, 163, 61, 0.1); box-shadow: 0 0 0 1px #e8a33d inset; }
.check-inline input { appearance: none; -webkit-appearance: none; flex: none; width: 22px; height: 22px; margin: 0; border: 1.5px solid #cfc8b8; border-radius: 3px; background: #fff; cursor: pointer; position: relative; transition: border-color 0.15s, background 0.15s; }
.check-inline input:checked { border-color: #c98730; background: linear-gradient(135deg, #eaad48, #c98730); }
.check-inline input:checked::after { content: ''; position: absolute; left: 7px; top: 3px; width: 5px; height: 10px; border: solid #fff; border-width: 0 2.5px 2.5px 0; transform: rotate(45deg); }
.check-inline input:focus-visible { box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.25); }
.review { background: #fbfaf7; border: 1px solid #f0ede6; border-radius: 10px; padding: 0.5rem 0.9rem; }
.rev { display: flex; justify-content: space-between; padding: 0.4rem 0; font-size: 0.85rem; border-bottom: 1px solid #f3f1ec; }
.rev:last-child { border-bottom: none; }
.rev span { color: #6b7280; }
.hint { font-size: 0.78rem; margin: 0.25rem 0 0; min-height: 1rem; }
.hint.muted { color: #9ca3af; }
.hint.available { color: #4c8b6c; }
.hint.taken { color: #c1502e; }
.hint.checking { color: #9ca3af; }
.err { color: #c1502e; font-size: 0.78rem; margin: 0.25rem 0 0; }
.err.server { margin-top: 0.75rem; }
.nav { display: flex; align-items: center; margin-top: 1.5rem; }
.spacer { flex: 1; }
.ghost { background: none; border: 1px solid #d8d3c8; border-radius: 8px; padding: 0.6rem 1.1rem; font-weight: 600; color: #374151; cursor: pointer; }
.submit { background: linear-gradient(135deg, #eaad48, #c98730); color: #1e2328; border: none; border-radius: 8px; padding: 0.65rem 1.4rem; font-weight: 700; font-size: 0.9rem; cursor: pointer; }
.submit:disabled { opacity: 0.5; cursor: not-allowed; }
.signin-hint { text-align: center; font-size: 0.82rem; color: #6b7280; margin-top: 1.1rem; }
.signin-hint a { color: #c98730; font-weight: 600; text-decoration: none; }
</style>
