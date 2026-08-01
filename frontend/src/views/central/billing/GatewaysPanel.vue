<script setup lang="ts">
/**
 * Billing → Payment Gateways (Volume 11 §Billing → Payment Gateways).
 *
 * The platform's own merchant accounts — where subscription money is collected —
 * not the tenant-side gateways a workspace uses to collect from its customers.
 *
 * Stored secrets are never sent back to the browser: a secret field shows whether
 * one is on file and submitting it blank leaves the stored value alone. That is why
 * the placeholder reads "unchanged" rather than showing dots.
 */
import { ref, computed, onMounted } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Textarea from 'primevue/textarea'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canManage = computed(() => auth.can('gateways.manage'))

const gateways = ref<any[]>([])
const activeSource = ref<any>({})
const loading = ref(false)
const busy = ref<string | null>(null)

const modeOptions = [
  { label: 'Test', value: 'test' },
  { label: 'Live', value: 'live' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/gateways')
    gateways.value = data.data ?? []
    activeSource.value = data.active_source ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load gateways', life: 4000 })
  } finally {
    loading.value = false
  }
}

// ---- Configure ----------------------------------------------------------------
const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({ label: '', mode: 'test', notes: '', credentials: {} })
const errors = ref<Record<string, string[]>>({})

function openEdit(row: any) {
  editing.value = row
  form.value = {
    label: row.label ?? '',
    mode: row.mode ?? 'test',
    notes: row.notes ?? '',
    // Visible (non-secret) values come back from the API; secrets start blank and
    // stay stored unless something is typed.
    credentials: { ...(row.credentials ?? {}) },
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    // Strip blanks for secret fields so the server keeps what it has; a blank
    // visible field is sent through, because clearing it is a real intent.
    const credentials: Record<string, string> = {}
    for (const field of editing.value.fields) {
      const value = form.value.credentials[field.name]
      if (field.secret && !String(value ?? '').trim()) continue
      credentials[field.name] = String(value ?? '')
    }

    await centralHttp.put(`/billing/gateways/${editing.value.gateway}`, {
      label: form.value.label || null,
      mode: form.value.mode,
      notes: form.value.notes || null,
      credentials,
    })
    toast.add({ severity: 'success', summary: `${editing.value.label} saved`, life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not save', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    saving.value = false
  }
}

async function toggle(row: any) {
  // Switching a gateway to live is the moment real cards get charged, so it is
  // confirmed rather than a one-click toggle.
  if (!row.is_active && row.mode === 'live') {
    const res = await Swal.fire({
      title: `Enable ${row.label} in LIVE mode?`,
      text: 'Subscription payments will be charged against real cards immediately.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Go live',
    })
    if (!res.isConfirmed) return
  }

  busy.value = row.gateway
  try {
    await centralHttp.post(`/billing/gateways/${row.gateway}/toggle`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    busy.value = null
  }
}

async function makeDefault(row: any) {
  busy.value = row.gateway
  try {
    await centralHttp.post(`/billing/gateways/${row.gateway}/default`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  } finally {
    busy.value = null
  }
}

async function verify(row: any) {
  busy.value = row.gateway
  try {
    const { data } = await centralHttp.post(`/billing/gateways/${row.gateway}/verify`)
    toast.add({ severity: 'success', summary: 'Verified', detail: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'warn', summary: 'Not verified', detail: e.response?.data?.message || 'Request failed', life: 6000 })
  } finally {
    busy.value = null
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: `Remove the ${row.label} configuration?`,
    text: 'Stored credentials are deleted. Collection falls back to the .env values, if any are set.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Remove',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.delete(`/billing/gateways/${row.gateway}`)
    toast.add({ severity: 'success', summary: 'Removed', detail: data.message, life: 4500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  }
}

const razorpay = computed(() => activeSource.value.razorpay ?? {})

onMounted(load)
</script>

<template>
  <div>
    <Message v-if="razorpay.configured" :severity="razorpay.mode === 'live' ? 'success' : 'info'" :closable="false" class="banner">
      Subscription checkout is using <strong>Razorpay</strong> in <strong>{{ razorpay.mode }}</strong> mode,
      from {{ razorpay.source === 'panel' ? 'this panel' : 'the .env configuration' }}.
    </Message>
    <Message v-else severity="warn" :closable="false" class="banner">
      No payment gateway is collecting subscription money. Plan changes fall back to activating without payment —
      fine locally, not in production.
    </Message>

    <div class="cards">
      <div v-for="g in gateways" :key="g.gateway" class="card" :class="{ inactive: !g.is_active }">
        <div class="card-head">
          <div>
            <div class="name">
              {{ g.label }}
              <Tag v-if="g.is_default" value="Default" severity="info" class="tag" />
            </div>
            <div class="meta">
              <Tag :value="g.is_active ? 'Enabled' : 'Disabled'" :severity="g.is_active ? 'success' : 'secondary'" />
              <Tag :value="g.mode" :severity="g.mode === 'live' ? 'danger' : 'warn'" class="tag cap" />
              <span v-if="!g.recurring" class="muted">manual collection</span>
            </div>
          </div>
          <a :href="g.docs" target="_blank" rel="noopener" class="docs" title="Open the gateway dashboard">
            <i class="pi pi-external-link" />
          </a>
        </div>

        <div class="creds">
          <div v-for="f in g.fields" :key="f.name" class="cred">
            <span class="cred-label">{{ f.label }}</span>
            <span v-if="g.credentials_set?.[f.name]" class="cred-set">
              <i class="pi pi-check-circle" />
              {{ f.secret ? 'stored' : (g.credentials?.[f.name] || 'stored') }}
            </span>
            <span v-else class="cred-missing">{{ f.required ? 'required' : 'not set' }}</span>
          </div>
        </div>

        <p v-if="!g.recurring" class="note">
          Credentials are stored, but recurring collection through {{ g.label }} has no driver yet — record those payments
          against the invoice instead.
        </p>
        <p v-if="g.notes" class="note">{{ g.notes }}</p>
        <p v-if="g.last_verified_at" class="verified">Verified {{ g.last_verified_at.slice(0, 16) }}</p>

        <div class="actions">
          <Button v-if="canManage" label="Configure" icon="pi pi-cog" size="small" outlined @click="openEdit(g)" />
          <Button v-if="canManage && g.configured" :label="g.is_active ? 'Disable' : 'Enable'"
            :icon="g.is_active ? 'pi pi-pause' : 'pi pi-play'" size="small" text
            :severity="g.is_active ? 'warn' : 'success'" :loading="busy === g.gateway" @click="toggle(g)" />
          <Button v-if="canManage && g.is_active && !g.is_default" label="Make default" icon="pi pi-star" size="small" text
            :loading="busy === g.gateway" @click="makeDefault(g)" />
          <Button v-if="canManage && g.configured && g.recurring" label="Test keys" icon="pi pi-bolt" size="small" text
            :loading="busy === g.gateway" @click="verify(g)" />
          <Button v-if="canManage && g.id" icon="pi pi-trash" text rounded severity="danger" size="small"
            title="Remove configuration" @click="remove(g)" />
        </div>
      </div>
    </div>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? `${editing.label} configuration` : 'Gateway'" :style="{ width: '520px' }">
      <div v-if="editing" class="form-grid">
        <div class="form-field">
          <label>Mode</label>
          <SelectButton v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
          <small class="hint">Test keys never move real money; live keys always do.</small>
        </div>

        <div v-for="f in editing.fields" :key="f.name" class="form-field">
          <label>{{ f.label }} <span v-if="f.required" class="req">*</span></label>
          <Password v-if="f.secret" v-model="form.credentials[f.name]" :feedback="false" toggleMask
            :placeholder="editing.credentials_set?.[f.name] ? 'Stored — leave blank to keep' : ''" />
          <InputText v-else v-model="form.credentials[f.name]" :placeholder="f.hint ?? ''" />
          <small v-if="f.hint && !f.secret" class="hint">{{ f.hint }}</small>
          <small v-if="f.secret && editing.credentials_set?.[f.name]" class="hint">A value is on file. Type a new one to replace it.</small>
        </div>

        <div class="form-field">
          <label>Label</label>
          <InputText v-model="form.label" :placeholder="editing.label" />
        </div>
        <div class="form-field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" autoResize placeholder="Which merchant account this is, who owns it…" />
        </div>

        <small v-if="errors.credentials" class="error-text">{{ errors.credentials[0] }}</small>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.banner { margin-bottom: 1rem; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem; display: flex; flex-direction: column; gap: 0.6rem; }
.card.inactive { background: #fafafa; }
.card-head { display: flex; justify-content: space-between; align-items: flex-start; }
.name { font-weight: 600; font-size: 1rem; }
.meta { display: flex; align-items: center; gap: 0.35rem; margin-top: 0.35rem; flex-wrap: wrap; }
.tag { margin-left: 0.25rem; }
.cap { text-transform: capitalize; }
.docs { color: #9ca3af; }
.docs:hover { color: #4f46e5; }
.creds { display: flex; flex-direction: column; gap: 0.25rem; border-top: 1px solid #f3f4f6; padding-top: 0.6rem; }
.cred { display: flex; justify-content: space-between; font-size: 0.8rem; gap: 0.75rem; }
.cred-label { color: #6b7280; }
.cred-set { color: #059669; display: flex; align-items: center; gap: 0.25rem; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cred-missing { color: #9ca3af; }
.note { font-size: 0.78rem; color: #6b7280; margin: 0; }
.verified { font-size: 0.75rem; color: #059669; margin: 0; }
.muted { color: #9ca3af; font-size: 0.78rem; }
.actions { display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap; margin-top: auto; padding-top: 0.4rem; }
.form-grid { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-password), .form-field :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
</style>
