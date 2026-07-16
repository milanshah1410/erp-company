<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../api/http'

interface Plan {
  slug: string
  name: string
  price: number
  currency: string
  is_custom: boolean
  feature_limits: Record<string, unknown>
}

const router = useRouter()

const STEPS = ['Company', 'Industry', 'Modules', 'Users', 'Setup', 'Tax & Numbering', 'Generate']
const step = ref(1)

const INDUSTRIES = ['Garment', 'FMCG', 'Manufacturing', 'Retail', 'Wholesale', 'Pharma', 'Custom']
const MODULES = [
  { key: 'crm', label: 'CRM', icon: 'pi pi-heart' },
  { key: 'sales', label: 'Sales', icon: 'pi pi-dollar' },
  { key: 'purchase', label: 'Purchase', icon: 'pi pi-shopping-bag' },
  { key: 'inventory', label: 'Inventory', icon: 'pi pi-warehouse' },
  { key: 'manufacturing', label: 'Manufacturing', icon: 'pi pi-cog' },
  { key: 'accounts', label: 'Accounts', icon: 'pi pi-wallet' },
  { key: 'hrms', label: 'HRMS', icon: 'pi pi-id-card' },
  { key: 'pos', label: 'POS', icon: 'pi pi-calculator' },
  { key: 'broker', label: 'Broker', icon: 'pi pi-user-plus' },
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
  // Step 2 — industry
  industry: 'Garment',
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
    const { data } = await http.get('/plans')
    plans.value = data.data
  } catch {
    /* pricing is best-effort */
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

function toggleModule(key: string) {
  const i = form.value.modules.indexOf(key)
  if (i >= 0) form.value.modules.splice(i, 1)
  else form.value.modules.push(key)
}

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
      return !!form.value.company_name.trim()
    case 3:
      return form.value.modules.length > 0
    case 4:
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
      if (errors.value.email || Object.keys(errors.value).some((k) => k.startsWith('config.users'))) step.value = 4
      else if (errors.value.company_name) step.value = 1
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

      <!-- STEP 1: Company -->
      <div v-if="step === 1" class="form">
        <div class="plans">
          <button
            v-for="p in plans"
            :key="p.slug"
            type="button"
            class="plan"
            :class="{ active: form.plan_slug === p.slug }"
            @click="form.plan_slug = p.slug"
          >
            <span class="plan-name">{{ p.name }}</span>
            <span class="plan-price">
              <template v-if="p.is_custom">Custom</template>
              <template v-else>₹{{ p.price.toLocaleString('en-IN') }}<small>/mo</small></template>
            </span>
          </button>
        </div>

        <label>Company name <span class="req">*</span></label>
        <input v-model="form.company_name" type="text" placeholder="Acme Traders" />
        <p v-if="errors.company_name" class="err">{{ errors.company_name[0] }}</p>

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

      <!-- STEP 2: Industry -->
      <div v-else-if="step === 2" class="form">
        <label>What does your company do?</label>
        <div class="grid-choice">
          <button
            v-for="ind in INDUSTRIES"
            :key="ind"
            type="button"
            class="choice"
            :class="{ active: form.industry === ind }"
            @click="form.industry = ind"
          >
            {{ ind }}
          </button>
        </div>
      </div>

      <!-- STEP 3: Modules -->
      <div v-else-if="step === 3" class="form">
        <label>Which modules do you need? <span class="req">*</span></label>
        <p class="hint muted">Unselected modules stay hidden from the menu. You can change this later.</p>
        <div class="grid-choice modules">
          <button
            v-for="m in MODULES"
            :key="m.key"
            type="button"
            class="choice module"
            :class="{ active: form.modules.includes(m.key) }"
            @click="toggleModule(m.key)"
          >
            <i :class="m.icon" />
            <span>{{ m.label }}</span>
            <i v-if="form.modules.includes(m.key)" class="pi pi-check check" />
          </button>
        </div>
      </div>

      <!-- STEP 4: Users -->
      <div v-else-if="step === 4" class="form">
        <div class="section-label">Company Owner <span class="req">*</span></div>
        <label>Full name <span class="req">*</span></label>
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

        <div class="section-label">Invite your team <span class="opt">(optional)</span></div>
        <div v-for="u in form.team" :key="u.label" class="team-row">
          <span class="team-role">{{ u.label }}</span>
          <input v-model="u.name" type="text" placeholder="Name" />
          <input v-model="u.email" type="email" placeholder="email@company.com" />
        </div>
      </div>

      <!-- STEP 5: Branch / Warehouse / FY / Currency -->
      <div v-else-if="step === 5" class="form">
        <div class="row">
          <div class="col"><label>Branch name</label><input v-model="form.branch_name" type="text" /></div>
          <div class="col"><label>Warehouse name</label><input v-model="form.warehouse_name" type="text" /></div>
        </div>
        <label>Financial year starts in</label>
        <select v-model.number="form.fy_start_month">
          <option v-for="(mn, i) in MONTHS" :key="mn" :value="i + 1">{{ mn }}</option>
        </select>
        <p class="hint muted">Financial year: {{ financialYear.name }} ({{ financialYear.start_date }} → {{ financialYear.end_date }})</p>

        <label>Currency</label>
        <select v-model="form.currency_code">
          <option v-for="c in CURRENCIES" :key="c.code" :value="c.code">{{ c.label }}</option>
        </select>
      </div>

      <!-- STEP 6: Numbering & Tax -->
      <div v-else-if="step === 6" class="form">
        <label>Invoice number prefix</label>
        <input v-model="form.invoice_prefix" type="text" placeholder="INV" />
        <p class="hint muted">Invoices will be numbered like {{ form.invoice_prefix || 'INV' }}-0001.</p>

        <label class="check-inline">
          <input v-model="form.gst_enabled" type="checkbox" />
          <span>Enable GST</span>
        </label>

        <div v-if="form.gst_enabled">
          <label>Default GST rate (%)</label>
          <select v-model.number="form.default_gst_rate">
            <option :value="0">0%</option>
            <option :value="5">5%</option>
            <option :value="12">12%</option>
            <option :value="18">18%</option>
            <option :value="28">28%</option>
          </select>
        </div>
      </div>

      <!-- STEP 7: Generate / Review -->
      <div v-else-if="step === 7" class="form">
        <div class="review">
          <div class="rev"><span>Company</span><b>{{ form.company_name || '—' }}</b></div>
          <div class="rev"><span>Industry</span><b>{{ form.industry }}</b></div>
          <div class="rev"><span>Modules</span><b>{{ form.modules.length }} selected</b></div>
          <div class="rev"><span>Owner</span><b>{{ form.admin_name || '—' }}</b></div>
          <div class="rev"><span>Team invites</span><b>{{ form.team.filter((u) => u.name && u.email).length }}</b></div>
          <div class="rev"><span>Currency</span><b>{{ currency.code }}</b></div>
          <div class="rev"><span>Financial year</span><b>{{ financialYear.name }}</b></div>
          <div class="rev"><span>GST</span><b>{{ form.gst_enabled ? form.default_gst_rate + '%' : 'Off' }}</b></div>
        </div>

        <label class="check-inline">
          <input v-model="form.load_demo" type="checkbox" />
          <span>Load sample data (customers, products, invoices…) so I can explore</span>
        </label>
        <div v-if="form.load_demo">
          <label>How many sample records per type?</label>
          <select v-model.number="form.demo_count">
            <option :value="100">100</option>
            <option :value="500">500</option>
            <option :value="1000">1,000</option>
          </select>
        </div>

        <p class="hint muted">
          On the next screen we'll verify your email, then generate your company, roles, permissions, menus,
          users, settings and dashboard.
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
.plans { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }
.plan { flex: 1; border: 1px solid #e8e4da; background: #fbfaf7; border-radius: 10px; padding: 0.6rem 0.5rem; cursor: pointer; display: flex; flex-direction: column; gap: 0.1rem; }
.plan.active { border-color: #e8a33d; background: rgba(232, 163, 61, 0.1); box-shadow: 0 0 0 1px #e8a33d inset; }
.plan-name { font-weight: 600; font-size: 0.82rem; }
.plan-price { font-weight: 700; color: #1e2328; }
.plan-price small { font-weight: 400; color: #9ca3af; font-size: 0.68rem; }
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
.section-label { font-size: 0.9rem; font-weight: 700; color: #1e2328; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #f0ede6; }
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
