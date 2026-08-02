<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'
import TenantAccessDialog from './tenants/TenantAccessDialog.vue'
import TenantMenuDialog from './tenants/TenantMenuDialog.vue'
import TenantProfileDialog from './tenants/TenantProfileDialog.vue'
import TenantLimitsDialog from './tenants/TenantLimitsDialog.vue'
import TenantPlanDialog from './tenants/TenantPlanDialog.vue'
import TenantBrandingDialog from './tenants/TenantBrandingDialog.vue'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('tenants.create'))
const canEdit = computed(() => auth.can('tenants.edit'))
// Plan management acts on the subscription, so it is gated by the billing
// vocabulary rather than tenants.* — the same permission Billing → Subscriptions
// uses. Viewing is enough to open it; the dialog hides its own Save otherwise.
const canViewPlan = computed(() => auth.can('subscriptions.view'))
const canSuspend = computed(() => auth.can('tenants.suspend'))
const canImpersonate = computed(() => auth.can('tenants.impersonate'))
// White label has its own vocabulary: re-skinning a customer's workspace and
// taking over the address its mail is sent from is not the same job as editing
// their company profile.
const canViewBranding = computed(() => auth.can('white-label.view'))
const canEditBranding = computed(() => auth.can('white-label.edit'))
// Module + menu entitlement is granted per operator (can_manage_tenant_access),
// not by a platform role — the API gates both its endpoints on the same flag.
const canEditAccess = computed(() => auth.canManageTenantAccess)

// The tenant whose access panel is open (null = closed).
const accessTenant = ref<any | null>(null)
// …and the menu builder, which shares the same can_manage_tenant_access gate:
// re-parenting a screen is the same power over a workspace as revoking it.
const menuTenant = ref<any | null>(null)
// …and the same for the profile and limits dialogs. Read-only operators still
// open them; the dialogs hide their Save button rather than the whole screen.
const profileTenant = ref<any | null>(null)
const limitsTenant = ref<any | null>(null)
const planTenant = ref<any | null>(null)
const brandingTenant = ref<any | null>(null)

const tenants = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const industryFilter = ref<string | null>(null)

const statusOptions = [
  { label: 'All statuses', value: null },
  { label: 'Trial', value: 'trial' },
  { label: 'Active', value: 'active' },
  { label: 'Suspended', value: 'suspended' },
]

// Industries that tenants are actually classified under, so the filter never
// offers a vertical with nothing behind it.
const industryFilterOptions = computed(() => {
  const seen = new Map<string, string>()
  for (const t of tenants.value) {
    if (t.industry) seen.set(t.industry, t.industry_label ?? t.industry)
  }
  const options = [...seen].map(([value, label]) => ({ label, value }))
  options.sort((a, b) => a.label.localeCompare(b.label))
  return [{ label: 'All industries', value: null }, ...options]
})

const filteredTenants = computed(() => {
  const q = search.value.trim().toLowerCase()
  return tenants.value.filter((t) => {
    if (statusFilter.value && t.status !== statusFilter.value) return false
    if (industryFilter.value && t.industry !== industryFilter.value) return false
    if (!q) return true
    return `${t.company_name} ${t.subdomain ?? ''} ${t.email} ${t.phone ?? ''} ${t.industry_label ?? ''} ${t.business_type_label ?? ''}`
      .toLowerCase()
      .includes(q)
  })
})

function statusSeverity(status: string) {
  return status === 'active' ? 'success' : status === 'trial' ? 'warn' : 'danger'
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/tenants')
    tenants.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load tenants', life: 4000 })
  } finally {
    loading.value = false
  }
}

// --- Create workspace (provisioned by the same pipeline as self-serve signup) ---
const dialogVisible = ref(false)
const saving = ref(false)
const provisioning = ref(false)
const planOptions = ref<any[]>([])
type Trade = { key: string; label: string; description: string }
type Industry = { key: string; label: string; icon: string; description: string; trades: Trade[] }
const industries = ref<Industry[]>([])
type ModuleOption = { key: string; label: string; icon: string; description: string }
const moduleCatalogue = ref<ModuleOption[]>([])
const blankForm = () => ({
  company_name: '',
  subdomain: '',
  admin_name: '',
  email: '',
  phone: '',
  password: '',
  plan_slug: 'starter',
  industry: null as string | null,
  business_type: null as string | null,
  // null = let the trade's preset decide (what self-serve signup does); an array
  // = the operator hand-picked, and the API records anything beyond the plan as
  // a comped grant.
  modules: null as string[] | null,
  load_demo: false,
  demo_count: 100,
})
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})
let pollTimer: ReturnType<typeof setTimeout> | null = null

// Server-tracked onboarding automation (Volume 11): the eight phases with what
// each one actually did, polled off the registration row while the job runs.
type ProvisionStep = { key: string; label: string; status: string; note: string | null; at: string | null }
const provisionSteps = ref<ProvisionStep[]>([])
const provisionFailed = ref(false)
const provisionError = ref('')

const STEP_ICONS: Record<string, string> = {
  tenant: 'pi pi-building',
  industry: 'pi pi-briefcase',
  plan: 'pi pi-box',
  schema: 'pi pi-database',
  seed: 'pi pi-sparkles',
  modules: 'pi pi-sliders-h',
  admin: 'pi pi-user',
  demo: 'pi pi-table',
}

function stepIcon(step: ProvisionStep): string {
  if (step.status === 'done') return 'pi pi-check'
  if (step.status === 'running') return 'pi pi-spin pi-spinner'
  if (step.status === 'failed') return 'pi pi-times'
  if (step.status === 'skipped') return 'pi pi-minus'
  return STEP_ICONS[step.key] ?? 'pi pi-circle'
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

const industryOptions = computed(() => industries.value.map((i) => ({ label: i.label, value: i.key })))

// Trades of the chosen industry — the trade is what decides which modules the new
// workspace starts with, so offering all of them before an industry is picked
// would let the two disagree.
const tradeOptions = computed(() => {
  const selected = industries.value.find((i) => i.key === form.value.industry)
  return (selected?.trades ?? []).map((t) => ({ label: t.label, value: t.key }))
})

async function loadPlans() {
  try {
    // Public pricing catalogue (no central auth needed).
    const res = await fetch('/api/plans', { headers: { Accept: 'application/json' } })
    const data = await res.json()
    planOptions.value = (data.data ?? data).map((p: any) => ({ label: p.name, value: p.slug }))
  } catch {
    planOptions.value = [{ label: 'Starter', value: 'starter' }]
  }
}

async function loadIndustries() {
  try {
    // Same public catalogue the signup wizard renders — each trade carries the
    // module preset, which is what pre-ticks the module picker below.
    const res = await fetch('/api/industries', { headers: { Accept: 'application/json' } })
    const data = await res.json()
    industries.value = data.data ?? []
  } catch {
    industries.value = []
  }
}

async function loadModules() {
  try {
    const res = await fetch('/api/modules', { headers: { Accept: 'application/json' } })
    const data = await res.json()
    moduleCatalogue.value = data.data ?? []
  } catch {
    moduleCatalogue.value = []
  }
}

/** The chosen trade's module preset — what a self-serve signup would get. */
const tradePreset = computed<string[]>(() => {
  const industry = industries.value.find((i) => i.key === form.value.industry)
  const trade = industry?.trades.find((t: any) => t.key === form.value.business_type) as any
  return trade?.modules ?? []
})

/** Switch from "follow the trade preset" to hand-picking, seeded from it. */
function customiseModules() {
  form.value.modules = [...tradePreset.value]
}

function toggleModule(key: string) {
  const current: string[] = form.value.modules ?? []
  form.value.modules = current.includes(key) ? current.filter((k) => k !== key) : [...current, key]
}

// Switching industry drops a trade that no longer belongs to it.
watch(
  () => form.value.industry,
  (industry) => {
    if (!industry || !form.value.business_type) return
    const selected = industries.value.find((i) => i.key === industry)
    if (selected && !selected.trades.some((t) => t.key === form.value.business_type)) {
      form.value.business_type = null
    }
  },
)

// A new trade brings a new preset, so a hand-picked selection made against the
// old one is dropped rather than silently carried over.
watch(() => form.value.business_type, () => {
  form.value.modules = null
})

function openCreate() {
  form.value = blankForm()
  errors.value = {}
  provisioning.value = false
  provisionFailed.value = false
  provisionError.value = ''
  provisionSteps.value = []
  dialogVisible.value = true
}

async function create() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post('/tenants', form.value)
    provisioning.value = true
    pollProvisioning(data.registration_id)
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
    saving.value = false
  }
}

function pollProvisioning(registrationId: number) {
  pollTimer = setTimeout(async () => {
    try {
      const { data } = await centralHttp.get(`/tenants/provisioning/${registrationId}`)
      // Real server-side phase progress, not a timed animation: the job writes
      // each phase back to the registration row as it finishes.
      provisionSteps.value = data.steps ?? []

      if (data.status === 'active') {
        saving.value = false
        provisioning.value = false
        dialogVisible.value = false
        toast.add({ severity: 'success', summary: 'Workspace ready', detail: 'Tenant provisioned successfully.', life: 3500 })
        await load()
        return
      }
      if (data.status === 'failed') {
        saving.value = false
        // The step list stays on screen with the failed phase marked, which is
        // the whole point of tracking them — a toast alone loses where it died.
        provisionFailed.value = true
        provisionError.value = data.error || 'Check the queue worker logs.'
        return
      }
      pollProvisioning(registrationId) // still provisioning — keep polling
    } catch {
      saving.value = false
      provisioning.value = false
      toast.add({ severity: 'error', summary: 'Error', detail: 'Lost track of provisioning; refresh the list.', life: 5000 })
    }
  }, 2000)
}

// --- Suspend / reactivate ---
async function confirmSuspend(row: any) {
  const res = await Swal.fire({
    title: `Suspend "${row.company_name}"?`,
    text: 'The workspace is fully locked: logins are rejected and all API access is blocked until reactivated.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Suspend',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.post(`/tenants/${row.id}/suspend`)
    toast.add({ severity: 'success', summary: 'Suspended', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Suspend failed', life: 4000 })
  }
}

async function confirmActivate(row: any) {
  const res = await Swal.fire({
    title: `Reactivate "${row.company_name}"?`,
    text: 'The workspace becomes fully usable again.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Reactivate',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.post(`/tenants/${row.id}/activate`)
    toast.add({ severity: 'success', summary: 'Reactivated', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Activate failed', life: 4000 })
  }
}

// --- Impersonation ---
async function confirmImpersonate(row: any) {
  const res = await Swal.fire({
    title: `Impersonate "${row.company_name}"?`,
    html: 'Opens the workspace as its admin user in a new tab.<br><small>Any staff login already open in this browser will be signed out.</small>',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Impersonate',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.post(`/tenants/${row.id}/impersonate`)
    // Hand the staff token to the tenant app: same-origin localStorage slot the
    // staff auth store reads on boot (frontend/src/stores/auth.ts).
    localStorage.setItem('sims_token', data.token)
    window.open('/', '_blank')
    toast.add({ severity: 'info', summary: 'Impersonating', detail: `Signed in as ${data.user?.name}`, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Impersonation failed', life: 4000 })
  }
}

onMounted(async () => {
  await Promise.all([load(), loadPlans(), loadIndustries(), loadModules()])
})

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer)
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Tenants</h1>
      <Button v-if="canCreate" label="New Tenant" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="filters">
      <InputText v-model="search" placeholder="Search company / address / email / industry" class="search" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="All statuses" class="status-filter" />
      <Select
        v-model="industryFilter"
        :options="industryFilterOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="All industries"
        class="status-filter"
      />
    </div>

    <DataTable :value="filteredTenants" :loading="loading" dataKey="id" class="p-datatable-sm" paginator :rows="15" :rowsPerPageOptions="[15, 30, 50]">
      <Column field="company_name" header="Company" sortable>
        <template #body="{ data }">
          <div>{{ data.company_name }}</div>
          <small v-if="data.subdomain" class="mono muted">{{ data.subdomain }}</small>
        </template>
      </Column>
      <Column field="email" header="Email" sortable />
      <Column field="industry_label" header="Industry" sortable style="width: 11rem">
        <template #body="{ data }">
          <div>{{ data.industry_label ?? '—' }}</div>
          <small v-if="data.business_type_label" class="muted">{{ data.business_type_label }}</small>
        </template>
      </Column>
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }">
          <Tag :severity="statusSeverity(data.status)" :value="data.status" />
          <small v-if="data.status === 'suspended' && data.suspended_by" class="muted"> by {{ data.suspended_by }}</small>
        </template>
      </Column>
      <Column field="plan" header="Plan" sortable>
        <template #body="{ data }">
          <!-- The plan cell is where anyone looks for the plan, so it is also the
               way in to changing it — not only the icon row at the far right. -->
          <button
            v-if="canViewPlan && data.subscription_id"
            type="button"
            class="plan-link"
            title="Manage this tenant's plan"
            @click="planTenant = data"
          >
            {{ data.plan ?? 'No plan' }}
          </button>
          <span v-else>{{ data.plan ?? '—' }}</span>
          <small v-if="data.subscription_status === 'trialing' && data.trial_ends_at" class="muted"> (trial → {{ data.trial_ends_at }})</small>
        </template>
      </Column>
      <Column field="users_count" header="Users" sortable style="width: 6rem" />
      <Column field="created_at" header="Created" sortable style="width: 11rem" />
      <Column header="Actions" style="width: 15rem">
        <template #body="{ data }">
          <Button icon="pi pi-id-card" text rounded size="small" title="Company profile, industry & business type" @click="profileTenant = data" />
          <Button v-if="canViewPlan" icon="pi pi-box" text rounded size="small" title="Plan management" @click="planTenant = data" />
          <Button
            icon="pi pi-chart-bar"
            text
            rounded
            size="small"
            :severity="data.has_limit_overrides ? 'info' : undefined"
            :title="data.has_limit_overrides ? 'Limits & usage (has overrides)' : 'Limits & usage'"
            @click="limitsTenant = data"
          />
          <Button v-if="canEditAccess" icon="pi pi-sliders-h" text rounded size="small" title="Module & menu access" @click="accessTenant = data" />
          <Button
            v-if="canEditAccess"
            icon="pi pi-sitemap"
            text
            rounded
            size="small"
            title="Menu builder — drag & drop this workspace's navigation"
            @click="menuTenant = data"
          />
          <Button
            v-if="canViewBranding"
            icon="pi pi-palette"
            text
            rounded
            size="small"
            title="White label — logo, domain, theme & SMTP"
            @click="brandingTenant = data"
          />
          <Button v-if="canImpersonate" icon="pi pi-sign-in" text rounded size="small" title="Impersonate workspace admin" @click="confirmImpersonate(data)" />
          <Button v-if="canSuspend && data.status !== 'suspended'" icon="pi pi-ban" text rounded severity="danger" size="small" title="Suspend workspace" @click="confirmSuspend(data)" />
          <Button v-if="canSuspend && data.status === 'suspended'" icon="pi pi-check-circle" text rounded severity="success" size="small" title="Reactivate workspace" @click="confirmActivate(data)" />
        </template>
      </Column>
      <template #empty>No tenants found.</template>
    </DataTable>

    <!-- Per-tenant module + menu entitlement. Keyed so switching tenants
         remounts with fresh state instead of reusing the previous panel's. -->
    <TenantAccessDialog
      v-if="accessTenant"
      :key="accessTenant.id"
      :tenant="accessTenant"
      @close="accessTenant = null"
    />

    <!-- The same workspace's navigation, rearranged: order, nesting, labels and
         icons by drag & drop. Sibling of the Access panel above — that one says
         whether a screen is allowed, this one says where it lives. -->
    <TenantMenuDialog
      v-if="menuTenant"
      :key="`menu-${menuTenant.id}`"
      :tenant="menuTenant"
      @close="menuTenant = null"
    />

    <!-- Company profile: contact details + industry/business type. Reloads the
         list on save so the Industry column reflects the change immediately. -->
    <TenantProfileDialog
      v-if="profileTenant"
      :key="`profile-${profileTenant.id}`"
      :tenant="profileTenant"
      :readonly="!canEdit"
      @saved="load"
      @close="profileTenant = null"
    />

    <!-- Plan management: what this tenant is on, and move them. Reloads the list
         so the Plan column and limit meters reflect the new plan. -->
    <TenantPlanDialog
      v-if="planTenant"
      :key="`plan-${planTenant.id}`"
      :tenant="planTenant"
      @saved="load"
      @close="planTenant = null"
    />

    <!-- White label: logo, custom domains, theme and the SMTP account this
         workspace's mail goes out through. -->
    <TenantBrandingDialog
      v-if="brandingTenant"
      :key="`brand-${brandingTenant.id}`"
      :tenant="brandingTenant"
      :readonly="!canEditBranding"
      @close="brandingTenant = null"
    />

    <!-- Plan caps, per-tenant overrides and current usage. -->
    <TenantLimitsDialog
      v-if="limitsTenant"
      :key="`limits-${limitsTenant.id}`"
      :tenant="limitsTenant"
      :readonly="!canEdit"
      @saved="load"
      @close="limitsTenant = null"
    />

    <Dialog v-model:visible="dialogVisible" modal header="New Tenant Workspace" :style="{ width: '560px' }" :closable="!saving || provisionFailed">
      <!-- Onboarding automation (Volume 11): the eight phases as the job reports
           them, so a failure names the phase it died in instead of just saying
           "provisioning failed". -->
      <div v-if="provisioning || provisionFailed" class="provisioning">
        <p v-if="!provisionFailed" class="prov-head">
          <i class="pi pi-spin pi-spinner" /> Provisioning workspace…
        </p>
        <p v-else class="prov-head failed"><i class="pi pi-times-circle" /> Provisioning failed</p>

        <ul class="steps">
          <li v-for="s in provisionSteps" :key="s.key" :class="s.status">
            <span class="tick"><i :class="stepIcon(s)" /></span>
            <span class="step-body">
              <span class="step-label">{{ s.label }}</span>
              <small v-if="s.note" class="step-note">{{ s.note }}</small>
            </span>
          </li>
        </ul>

        <p v-if="provisionFailed" class="error-text">{{ provisionError }}</p>
        <small v-else class="hint">Requires a running queue worker (php artisan queue:work).</small>
      </div>

      <div v-else class="form-grid">
        <div class="form-field full">
          <label>Company Name <span class="req">*</span></label>
          <InputText v-model="form.company_name" :class="{ invalid: fieldError('company_name') }" />
          <small v-if="fieldError('company_name')" class="error-text">{{ fieldError('company_name') }}</small>
        </div>
        <div class="form-field full">
          <label>Workspace Address</label>
          <InputText v-model="form.subdomain" placeholder="acme" :class="{ invalid: fieldError('subdomain') }" />
          <small v-if="fieldError('subdomain')" class="error-text">{{ fieldError('subdomain') }}</small>
          <small v-else class="hint">Leave blank to derive one from the company name.</small>
        </div>
        <div class="form-field">
          <label>Admin Name <span class="req">*</span></label>
          <InputText v-model="form.admin_name" :class="{ invalid: fieldError('admin_name') }" />
          <small v-if="fieldError('admin_name')" class="error-text">{{ fieldError('admin_name') }}</small>
        </div>
        <div class="form-field">
          <label>Phone</label>
          <InputText v-model="form.phone" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>
        <div class="form-field">
          <label>Admin Email <span class="req">*</span></label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>
        <div class="form-field">
          <label>Admin Password <span class="req">*</span></label>
          <Password v-model="form.password" :feedback="false" toggleMask :class="{ invalid: fieldError('password') }" />
          <small v-if="fieldError('password')" class="error-text">{{ fieldError('password') }}</small>
        </div>
        <div class="form-field">
          <label>Plan</label>
          <Select v-model="form.plan_slug" :options="planOptions" optionLabel="label" optionValue="value" :class="{ invalid: fieldError('plan_slug') }" />
          <small v-if="fieldError('plan_slug')" class="error-text">{{ fieldError('plan_slug') }}</small>
        </div>
        <div class="form-field">
          <label>Industry</label>
          <Select
            v-model="form.industry"
            :options="industryOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Optional"
            showClear
            :class="{ invalid: fieldError('industry') }"
          />
          <small v-if="fieldError('industry')" class="error-text">{{ fieldError('industry') }}</small>
        </div>
        <div class="form-field">
          <label>Business Type</label>
          <Select
            v-model="form.business_type"
            :options="tradeOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="form.industry ? 'Pick a trade' : 'Pick an industry first'"
            :disabled="!form.industry"
            showClear
            :class="{ invalid: fieldError('business_type') }"
          />
          <small v-if="fieldError('business_type')" class="error-text">{{ fieldError('business_type') }}</small>
          <small v-else class="hint">Pre-ticks the modules this trade runs on.</small>
        </div>
        <div class="form-field">
          <label>Sample Data</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.load_demo" binary inputId="t-demo" />
            <label for="t-demo">Load demo data</label>
          </div>
          <div v-if="form.load_demo" class="demo-count">
            <InputNumber v-model="form.demo_count" :min="10" :max="1000" :useGrouping="false" showButtons />
            <small class="hint">rows per module</small>
          </div>
        </div>

        <!-- Onboarding step 6. Left alone, the trade's preset decides (exactly
             what a self-serve signup gets); hand-picking records anything beyond
             the plan as a comped grant, so it survives the next read. -->
        <div class="form-field full">
          <label>
            Modules
            <button v-if="form.modules === null" type="button" class="link-btn" @click="customiseModules">Choose manually</button>
            <button v-else type="button" class="link-btn" @click="form.modules = null">Use trade defaults</button>
          </label>

          <small v-if="form.modules === null" class="hint">
            <template v-if="form.business_type">
              {{ tradePreset.length }} module{{ tradePreset.length === 1 ? '' : 's' }} from the
              chosen trade, narrowed to what the plan sells.
            </template>
            <template v-else>Pick a business type, or choose modules manually.</template>
          </small>

          <div v-else class="module-grid">
            <label v-for="m in moduleCatalogue" :key="m.key" class="module-chip" :class="{ on: form.modules.includes(m.key) }">
              <Checkbox :modelValue="form.modules.includes(m.key)" binary @update:modelValue="toggleModule(m.key)" />
              <span :title="m.description">{{ m.label }}</span>
            </label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button v-if="provisionFailed" label="Close" text @click="dialogVisible = false" />
        <template v-else>
          <Button label="Cancel" text :disabled="saving" @click="dialogVisible = false" />
          <Button v-if="!provisioning" label="Create" :loading="saving" @click="create" />
        </template>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.filters .search { width: 20rem; max-width: 100%; }
.filters .status-filter { width: 11rem; }
.muted { color: #9ca3af; }
.plan-link {
  background: none;
  border: 0;
  padding: 0;
  font: inherit;
  color: #2563eb;
  cursor: pointer;
  text-align: left;
}
.plan-link:hover { text-decoration: underline; }
.plan-link:focus-visible { outline: 2px solid var(--safety-amber, #e8a33d); outline-offset: 2px; border-radius: 3px; }
/* Live onboarding-automation progress (Volume 11). */
.provisioning { padding: 0.75rem 0.25rem; color: #374151; }
.prov-head { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.9rem; font-weight: 600; }
.prov-head i { color: #e8a33d; }
.prov-head.failed { color: #b91c1c; }
.prov-head.failed i { color: #dc2626; }
.steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.steps li { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.3rem 0; color: #9ca3af; font-size: 0.86rem; }
.steps li.running { color: #1f2937; font-weight: 600; }
.steps li.done { color: #4b5563; }
.steps li.failed { color: #b91c1c; font-weight: 600; }
.steps .tick {
  flex: none;
  width: 20px;
  height: 20px;
  margin-top: 0.1rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 0.6rem;
  background: #f2f0ea;
  color: #b8b2a5;
}
.steps li.done .tick { background: #4c8b6c; color: #fff; }
.steps li.running .tick { background: rgba(232, 163, 61, 0.2); color: #c98730; }
.steps li.failed .tick { background: #dc2626; color: #fff; }
.steps li.skipped { font-style: italic; }
.step-body { display: flex; flex-direction: column; min-width: 0; }
.step-note { color: #9ca3af; font-size: 0.74rem; font-weight: 400; font-style: normal; }
.demo-count { display: flex; align-items: center; gap: 0.4rem; }
.demo-count :deep(.p-inputnumber) { width: 8.5rem; }
.link-btn {
  background: none;
  border: 0;
  padding: 0;
  margin-left: 0.5rem;
  font: inherit;
  font-weight: 400;
  font-size: 0.78rem;
  color: #2563eb;
  cursor: pointer;
}
.link-btn:hover { text-decoration: underline; }
.module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: 0.25rem 0.6rem; margin-top: 0.3rem; }
.module-chip { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; font-weight: 400; cursor: pointer; }
.module-chip.on { font-weight: 600; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-password), .form-field :deep(.p-select) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
