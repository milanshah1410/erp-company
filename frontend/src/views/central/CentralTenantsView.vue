<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('tenants.create'))
const canSuspend = computed(() => auth.can('tenants.suspend'))
const canImpersonate = computed(() => auth.can('tenants.impersonate'))

const tenants = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)

const statusOptions = [
  { label: 'All statuses', value: null },
  { label: 'Trial', value: 'trial' },
  { label: 'Active', value: 'active' },
  { label: 'Suspended', value: 'suspended' },
]

const filteredTenants = computed(() => {
  const q = search.value.trim().toLowerCase()
  return tenants.value.filter((t) => {
    if (statusFilter.value && t.status !== statusFilter.value) return false
    if (!q) return true
    return `${t.company_name} ${t.email} ${t.phone ?? ''}`.toLowerCase().includes(q)
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
const form = ref<any>({ company_name: '', admin_name: '', email: '', phone: '', password: '', plan_slug: 'starter', load_demo: false })
const errors = ref<Record<string, string[]>>({})
let pollTimer: ReturnType<typeof setTimeout> | null = null

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

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

function openCreate() {
  form.value = { company_name: '', admin_name: '', email: '', phone: '', password: '', plan_slug: 'starter', load_demo: false }
  errors.value = {}
  provisioning.value = false
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
        provisioning.value = false
        toast.add({ severity: 'error', summary: 'Provisioning failed', detail: data.error || 'Check the queue worker logs.', life: 6000 })
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
  await Promise.all([load(), loadPlans()])
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
      <InputText v-model="search" placeholder="Search company / email / phone" class="search" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="All statuses" class="status-filter" />
    </div>

    <DataTable :value="filteredTenants" :loading="loading" dataKey="id" class="p-datatable-sm" paginator :rows="15" :rowsPerPageOptions="[15, 30, 50]">
      <Column field="company_name" header="Company" sortable />
      <Column field="email" header="Email" sortable />
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }">
          <Tag :severity="statusSeverity(data.status)" :value="data.status" />
          <small v-if="data.status === 'suspended' && data.suspended_by" class="muted"> by {{ data.suspended_by }}</small>
        </template>
      </Column>
      <Column field="plan" header="Plan" sortable>
        <template #body="{ data }">
          <span>{{ data.plan ?? '—' }}</span>
          <small v-if="data.subscription_status === 'trialing' && data.trial_ends_at" class="muted"> (trial → {{ data.trial_ends_at }})</small>
        </template>
      </Column>
      <Column field="users_count" header="Users" sortable style="width: 6rem" />
      <Column field="created_at" header="Created" sortable style="width: 11rem" />
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button v-if="canImpersonate" icon="pi pi-sign-in" text rounded size="small" title="Impersonate workspace admin" @click="confirmImpersonate(data)" />
          <Button v-if="canSuspend && data.status !== 'suspended'" icon="pi pi-ban" text rounded severity="danger" size="small" title="Suspend workspace" @click="confirmSuspend(data)" />
          <Button v-if="canSuspend && data.status === 'suspended'" icon="pi pi-check-circle" text rounded severity="success" size="small" title="Reactivate workspace" @click="confirmActivate(data)" />
        </template>
      </Column>
      <template #empty>No tenants found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal header="New Tenant Workspace" :style="{ width: '520px' }" :closable="!saving">
      <div v-if="provisioning" class="provisioning">
        <i class="pi pi-spin pi-spinner" />
        <p>Provisioning workspace… this seeds the company, roles and admin user.</p>
        <small class="hint">Requires a running queue worker (php artisan queue:work).</small>
      </div>

      <div v-else class="form-grid">
        <div class="form-field full">
          <label>Company Name <span class="req">*</span></label>
          <InputText v-model="form.company_name" :class="{ invalid: fieldError('company_name') }" />
          <small v-if="fieldError('company_name')" class="error-text">{{ fieldError('company_name') }}</small>
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
          <label>Sample Data</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.load_demo" binary inputId="t-demo" />
            <label for="t-demo">Load demo data</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text :disabled="saving" @click="dialogVisible = false" />
        <Button v-if="!provisioning" label="Create" :loading="saving" @click="create" />
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
.provisioning { display: flex; flex-direction: column; align-items: center; gap: 0.75rem; padding: 2rem 1rem; text-align: center; color: #374151; }
.provisioning i { font-size: 2rem; color: #e8a33d; }
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
