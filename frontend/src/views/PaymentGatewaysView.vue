<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import SelectButton from 'primevue/selectbutton'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'

/**
 * Admin surface for the tenant's online payment gateways (SRS §16): which of
 * the six supported providers are enabled, their test/live mode, the default
 * one, and their (encrypted, write-only) API credentials.
 */
const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('payment-gateways.create'))
const canEdit = computed(() => auth.can('payment-gateways.edit'))
const canDelete = computed(() => auth.can('payment-gateways.delete'))

const loading = ref(true)
const catalog = ref<any[]>([])
const accounts = ref<any[]>([])

const modeOptions = [
  { label: 'Test', value: 'test' },
  { label: 'Live', value: 'live' },
]

function accountFor(gateway: string) {
  return accounts.value.find((a) => a.gateway === gateway) || null
}

async function load() {
  loading.value = true
  try {
    const [cat, acc] = await Promise.all([
      http.get('/payment-gateways/catalog'),
      http.get('/payment-gateways'),
    ])
    catalog.value = cat.data.data ?? []
    accounts.value = acc.data.data ?? []
  } catch {
    toast.add({ severity: 'error', summary: 'Failed to load payment gateways', life: 4000 })
  } finally {
    loading.value = false
  }
}

// --- Configure dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const editing = ref<any>(null) // the catalog entry being configured
const existing = ref<any>(null) // the saved account, if any
const form = ref<any>({ label: '', mode: 'test', is_active: true, is_default: false, credentials: {} })

function openConfigure(entry: any) {
  editing.value = entry
  existing.value = accountFor(entry.gateway)
  errors.value = {}
  form.value = {
    label: existing.value?.label ?? '',
    mode: existing.value?.mode ?? 'test',
    is_active: existing.value ? existing.value.is_active : true,
    is_default: existing.value ? existing.value.is_default : accounts.value.length === 0,
    credentials: {},
  }
  dialogVisible.value = true
}

/** Whether a stored secret exists for this field (so we can show "unchanged"). */
function isStored(key: string): boolean {
  return !!existing.value?.credential_status?.[key]
}

async function save() {
  saving.value = true
  errors.value = {}
  const gateway = editing.value.gateway
  // Only send credential fields the user actually filled in.
  const credentials: Record<string, string> = {}
  for (const [k, v] of Object.entries(form.value.credentials)) {
    if (v !== null && v !== undefined && String(v).trim() !== '') credentials[k] = String(v)
  }
  const payload: any = {
    label: form.value.label || null,
    mode: form.value.mode,
    is_active: form.value.is_active,
    is_default: form.value.is_default,
    credentials,
  }
  try {
    if (existing.value) {
      await http.put(`/payment-gateways/${existing.value.id}`, payload)
    } else {
      await http.post('/payment-gateways', { gateway, ...payload })
    }
    toast.add({ severity: 'success', summary: 'Gateway saved', life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function toggleActive(entry: any) {
  const acc = accountFor(entry.gateway)
  if (!acc) return
  try {
    await http.put(`/payment-gateways/${acc.id}`, { is_active: !acc.is_active })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Update failed', life: 4000 })
  }
}

async function remove(entry: any) {
  const acc = accountFor(entry.gateway)
  if (!acc) return
  const res = await Swal.fire({
    title: `Remove ${entry.label}?`,
    text: 'Its saved credentials will be deleted.',
    icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Remove',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/payment-gateways/${acc.id}`)
    toast.add({ severity: 'success', summary: 'Removed', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? errors.value[`credentials.${key}`]?.[0] ?? null
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Payment Gateways" />
    <p class="hint">
      <i class="pi pi-info-circle" />
      Enable the providers you collect online payments through. Each uses your own merchant account; credentials are
      stored encrypted and never shown again. Customers pick from your enabled gateways on the hosted pay page.
    </p>

    <div v-if="loading" class="loading">Loading…</div>

    <div v-else class="grid">
      <div v-for="entry in catalog" :key="entry.gateway" class="gw-card">
        <div class="gw-head">
          <div class="gw-title">
            <span class="gw-name">{{ entry.label }}</span>
            <Tag v-if="entry.export" value="Export / Intl" severity="info" />
          </div>
          <div class="gw-badges">
            <Tag v-if="accountFor(entry.gateway)?.is_default" value="Default" severity="success" />
            <Tag
              v-if="accountFor(entry.gateway)"
              :value="accountFor(entry.gateway).mode === 'live' ? 'Live' : 'Test'"
              :severity="accountFor(entry.gateway).mode === 'live' ? 'warn' : 'secondary'"
            />
            <Tag
              :value="accountFor(entry.gateway)?.configured ? 'Configured' : (accountFor(entry.gateway) ? 'Incomplete' : 'Not set up')"
              :severity="accountFor(entry.gateway)?.configured ? 'success' : (accountFor(entry.gateway) ? 'warn' : 'secondary')"
            />
          </div>
        </div>

        <p class="gw-cur">Currencies: {{ (entry.currencies || []).join(', ') }}</p>

        <div class="gw-actions">
          <div v-if="accountFor(entry.gateway)" class="active-toggle">
            <ToggleSwitch
              :modelValue="accountFor(entry.gateway).is_active"
              :disabled="!canEdit"
              @update:modelValue="toggleActive(entry)"
            />
            <span>{{ accountFor(entry.gateway).is_active ? 'Enabled' : 'Disabled' }}</span>
          </div>
          <span v-else class="active-toggle muted">Not enabled</span>

          <div class="btns">
            <Button
              v-if="(accountFor(entry.gateway) && canEdit) || (!accountFor(entry.gateway) && canCreate)"
              :label="accountFor(entry.gateway) ? 'Edit' : 'Set up'"
              :icon="accountFor(entry.gateway) ? 'pi pi-pencil' : 'pi pi-plus'"
              size="small" outlined
              @click="openConfigure(entry)"
            />
            <Button
              v-if="accountFor(entry.gateway) && canDelete"
              icon="pi pi-trash" size="small" text severity="danger" title="Remove"
              @click="remove(entry)"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Configure dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="`${editing?.label} settings`" :style="{ width: '480px' }">
      <div v-if="editing" class="form">
        <div class="fld">
          <label>Label <small>(optional)</small></label>
          <InputText v-model="form.label" placeholder="e.g. Razorpay – main account" />
        </div>

        <div class="fld">
          <label>Mode</label>
          <SelectButton v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
        </div>

        <div class="toggles">
          <label class="tg"><ToggleSwitch v-model="form.is_active" /> <span>Enabled</span></label>
          <label class="tg"><ToggleSwitch v-model="form.is_default" /> <span>Default gateway</span></label>
        </div>

        <div class="creds">
          <h4>Credentials</h4>
          <div v-for="f in editing.fields" :key="f.key" class="fld">
            <label>
              {{ f.label }}
              <span v-if="f.required" class="req">*</span>
              <small v-if="f.secret && isStored(f.key)" class="stored">— saved, leave blank to keep</small>
            </label>
            <Password
              v-if="f.secret"
              v-model="form.credentials[f.key]" toggleMask :feedback="false"
              :placeholder="isStored(f.key) ? '•••••••• (unchanged)' : ''"
              :inputStyle="{ width: '100%' }" style="width: 100%"
              :class="{ invalid: fieldError(f.key) }"
            />
            <InputText
              v-else
              v-model="form.credentials[f.key]"
              :placeholder="existing?.credential_status?.[f.key] ? '(saved — enter to replace)' : ''"
              :class="{ invalid: fieldError(f.key) }"
            />
            <small v-if="fieldError(f.key)" class="error-text">{{ fieldError(f.key) }}</small>
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1.25rem; max-width: 760px; }
.loading { color: #6b7280; font-size: 0.9rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; max-width: 1000px; }
.gw-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.1rem 1.25rem; }
.gw-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.gw-title { display: flex; align-items: center; gap: 0.5rem; }
.gw-name { font-size: 1.05rem; font-weight: 700; }
.gw-badges { display: flex; flex-wrap: wrap; gap: 0.3rem; justify-content: flex-end; }
.gw-cur { color: #6b7280; font-size: 0.78rem; margin: 0.6rem 0 1rem; }
.gw-actions { display: flex; justify-content: space-between; align-items: center; }
.active-toggle { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #374151; }
.active-toggle.muted { color: #9ca3af; }
.btns { display: flex; align-items: center; gap: 0.25rem; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(.p-password) { width: 100%; }
.toggles { display: flex; gap: 1.5rem; }
.tg { display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; color: #374151; cursor: pointer; }
.creds { border-top: 1px solid #f1f5f9; padding-top: 0.75rem; display: flex; flex-direction: column; gap: 0.85rem; }
.creds h4 { font-size: 0.9rem; font-weight: 600; }
.req { color: #dc2626; }
.stored { color: #059669; font-weight: 400; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
