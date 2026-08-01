<script setup lang="ts">
/**
 * White label for one workspace (/platform/tenants → White Label, Volume 11).
 *
 * Four tabs, one per thing a reseller actually has to change to make the product
 * theirs: the Logo, the Domain it answers on, the Theme, and the SMTP account
 * its mail leaves from — branded screens sending from our address would give the
 * game away, which is why mail is here rather than filed under integrations.
 *
 * Each tab saves independently. That is deliberate: uploading a logo and typing
 * an SMTP password are different jobs done at different times, and one Save for
 * all four would make a half-filled form dangerous.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'

const props = defineProps<{ tenant: any | null; readonly?: boolean }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const toast = useToast()

const visible = ref(false)
const loading = ref(false)
const active = ref('logo')

type Branding = {
  app_name: string
  tagline: string
  logo_url: string | null
  primary_color: string
  accent_color: string
  dark_default: boolean
  powered_by: boolean
  white_labelled: boolean
}
type DomainRow = { id: number; domain: string; is_primary: boolean; url: string; created_at: string | null }
type Smtp = {
  mail_driver: string
  mail_host: string
  mail_port: string | number
  mail_username: string
  mail_encryption: string
  mail_from_address: string
  mail_from_name: string
  has_password: boolean
  configured: boolean
}

const branding = ref<Branding | null>(null)
const platform = ref<Branding | null>(null)
const domains = ref<DomainRow[]>([])
const smtp = ref<Smtp | null>(null)
const workspace = ref<{ subdomain: string | null; workspace_url: string | null }>({ subdomain: null, workspace_url: null })

// Working copies — the tabs edit these, not the loaded payload, so cancelling is
// just closing the dialog.
const theme = ref({
  brand_app_name: '',
  brand_tagline: '',
  brand_primary_color: '',
  brand_accent_color: '',
  brand_dark_default: false,
  brand_powered_by: true,
})
const mail = ref({
  mail_driver: 'log',
  mail_host: '',
  mail_port: 587,
  mail_username: '',
  mail_password: '',
  mail_encryption: 'tls',
  mail_from_address: '',
  mail_from_name: '',
})
const newDomain = ref('')
const testTo = ref('')

const savingTheme = ref(false)
const savingSmtp = ref(false)
const addingDomain = ref(false)
const uploading = ref(false)
const testing = ref(false)
const errors = ref<Record<string, string[]>>({})

const readonly = computed(() => !!props.readonly)

const driverOptions = [
  { label: 'Log only (no mail server)', value: 'log' },
  { label: 'SMTP', value: 'smtp' },
]
const encryptionOptions = [
  { label: 'TLS', value: 'tls' },
  { label: 'SSL', value: 'ssl' },
  { label: 'None', value: 'none' },
]

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

watch(
  () => props.tenant,
  (tenant) => {
    if (tenant) {
      visible.value = true
      active.value = 'logo'
      void load(tenant.id)
    }
  },
  { immediate: true },
)

watch(visible, (open) => {
  if (!open) emit('close')
})

async function load(tenantId: number) {
  loading.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.get(`/tenants/${tenantId}/branding`)
    apply(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load white-label settings', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

/** Fold a show() payload into the working state. */
function apply(data: any) {
  branding.value = data.branding
  platform.value = data.platform
  domains.value = data.domains ?? []
  smtp.value = data.smtp
  workspace.value = { subdomain: data.tenant?.subdomain ?? null, workspace_url: data.tenant?.workspace_url ?? null }

  // Seed the theme form from the raw overrides, not the effective values: a
  // field showing an inherited value must stay blank, or saving the form would
  // silently freeze today's platform brand (or the company name) into the
  // tenant. Blank switches take their documented defaults.
  const o = (data.overrides ?? {}) as Record<string, string>
  theme.value = {
    brand_app_name: o.brand_app_name ?? '',
    brand_tagline: o.brand_tagline ?? '',
    brand_primary_color: o.brand_primary_color ?? '',
    brand_accent_color: o.brand_accent_color ?? '',
    brand_dark_default: o.brand_dark_default === '1',
    brand_powered_by: o.brand_powered_by !== '0',
  }

  const s = data.smtp as Smtp
  mail.value = {
    mail_driver: s.mail_driver || 'log',
    mail_host: s.mail_host || '',
    mail_port: Number(s.mail_port) || 587,
    mail_username: s.mail_username || '',
    mail_password: '', // write-only: never shipped back to the panel
    mail_encryption: s.mail_encryption || 'tls',
    mail_from_address: s.mail_from_address || '',
    mail_from_name: s.mail_from_name || '',
  }
  testTo.value = testTo.value || s.mail_from_address || ''
}

// --- Logo -----------------------------------------------------------------
async function onLogoPicked(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !props.tenant) return

  const form = new FormData()
  form.append('logo', file)
  uploading.value = true
  try {
    const { data } = await centralHttp.post(`/tenants/${props.tenant.id}/branding/logo`, form)
    if (branding.value) branding.value = { ...branding.value, logo_url: data.logo_url, white_labelled: true }
    toast.add({ severity: 'success', summary: 'Logo updated', life: 2500 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Upload failed',
      detail: e.response?.data?.errors?.logo?.[0] || e.response?.data?.message || 'Could not upload the logo',
      life: 4500,
    })
  } finally {
    uploading.value = false
    input.value = '' // let the same file be re-picked after a failure
  }
}

async function removeLogo() {
  if (!props.tenant) return
  const res = await Swal.fire({
    title: 'Remove this logo?',
    text: 'The workspace goes back to the platform mark — on screen and on its invoices.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Remove',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/tenants/${props.tenant.id}/branding/logo`)
    if (branding.value) branding.value = { ...branding.value, logo_url: platform.value?.logo_url ?? null }
    toast.add({ severity: 'success', summary: 'Logo removed', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not remove the logo', life: 4000 })
  }
}

// --- Theme ----------------------------------------------------------------
async function saveTheme() {
  if (!props.tenant) return
  savingTheme.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.put(`/tenants/${props.tenant.id}/branding`, theme.value)
    apply(data)
    toast.add({ severity: 'success', summary: 'Theme saved', life: 2500 })
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not save the theme', life: 4000 })
  } finally {
    savingTheme.value = false
  }
}

// --- Domains --------------------------------------------------------------
async function addDomain() {
  if (!props.tenant || !newDomain.value.trim()) return
  addingDomain.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post(`/tenants/${props.tenant.id}/branding/domains`, { domain: newDomain.value })
    domains.value = data.domains
    newDomain.value = ''
    toast.add({ severity: 'success', summary: 'Domain mapped', detail: data.message, life: 3500 })
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not map the domain', life: 4000 })
  } finally {
    addingDomain.value = false
  }
}

async function removeDomain(row: DomainRow) {
  if (!props.tenant) return
  const res = await Swal.fire({
    title: `Unmap ${row.domain}?`,
    text: 'Requests on that host will no longer be recognised as this workspace.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Unmap',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.delete(`/tenants/${props.tenant.id}/branding/domains/${row.id}`)
    domains.value = data.domains
    toast.add({ severity: 'success', summary: 'Domain unmapped', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not unmap the domain', life: 4000 })
  }
}

// --- SMTP -----------------------------------------------------------------
async function saveSmtp() {
  if (!props.tenant) return
  savingSmtp.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.put(`/tenants/${props.tenant.id}/branding/smtp`, mail.value)
    smtp.value = data.smtp
    mail.value.mail_password = ''
    toast.add({ severity: 'success', summary: 'Email settings saved', life: 2500 })
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not save the email settings', life: 4000 })
  } finally {
    savingSmtp.value = false
  }
}

async function sendTest() {
  if (!props.tenant || !testTo.value) return
  testing.value = true
  try {
    const { data } = await centralHttp.post(`/tenants/${props.tenant.id}/branding/smtp/test`, { to: testTo.value })
    toast.add({ severity: 'success', summary: 'Test sent', detail: data.message, life: 5000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Test failed', detail: e.response?.data?.message || 'Could not send the test email', life: 6000 })
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`White Label — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '680px' }"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading white-label settings…</div>

    <template v-else>
      <p class="intro">
        <i class="pi pi-info-circle" />
        Anything left blank follows the platform brand, so a workspace that was
        never white labelled is unaffected. The sign-in, provisioning and B2B
        portal screens read these before anyone logs in.
      </p>

      <Tabs v-model:value="active">
        <TabList>
          <Tab value="logo"><i class="pi pi-image tab-icon" /> Logo</Tab>
          <Tab value="domain"><i class="pi pi-globe tab-icon" /> Domain</Tab>
          <Tab value="theme"><i class="pi pi-palette tab-icon" /> Theme</Tab>
          <Tab value="smtp"><i class="pi pi-envelope tab-icon" /> SMTP</Tab>
        </TabList>

        <TabPanels>
          <!-- ---------------------------------------------------------- Logo -->
          <TabPanel value="logo">
            <div class="logo-row">
              <div class="logo-frame">
                <img v-if="branding?.logo_url" :src="branding.logo_url" alt="Workspace logo" />
                <span v-else class="logo-empty"><i class="pi pi-image" /> Platform mark</span>
              </div>
              <div class="logo-actions">
                <label v-if="!readonly" class="upload">
                  <i :class="uploading ? 'pi pi-spin pi-spinner' : 'pi pi-upload'" />
                  {{ uploading ? 'Uploading…' : branding?.logo_url ? 'Replace logo' : 'Upload logo' }}
                  <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden :disabled="uploading" @change="onLogoPicked" />
                </label>
                <Button
                  v-if="!readonly && branding?.logo_url"
                  label="Remove"
                  icon="pi pi-trash"
                  severity="danger"
                  text
                  size="small"
                  @click="removeLogo"
                />
                <p class="note">
                  PNG, JPG or WEBP, up to 2&nbsp;MB (no SVG — it is served from the
                  app's own origin and can carry script). This is the same image
                  the workspace prints on its invoices — one logo, everywhere.
                </p>
              </div>
            </div>
          </TabPanel>

          <!-- -------------------------------------------------------- Domain -->
          <TabPanel value="domain">
            <div class="domain-list">
              <div v-for="d in domains" :key="d.id" class="domain">
                <div class="domain-name">
                  <span class="mono">{{ d.domain }}</span>
                  <Tag v-if="d.is_primary" value="workspace address" severity="info" class="mini-tag" />
                </div>
                <Button
                  v-if="!readonly && !d.is_primary"
                  icon="pi pi-times"
                  text
                  rounded
                  size="small"
                  severity="danger"
                  title="Unmap this domain"
                  @click="removeDomain(d)"
                />
              </div>
              <p v-if="!domains.length" class="note">No hosts recorded for this workspace.</p>
            </div>

            <div v-if="!readonly" class="add-domain">
              <InputText v-model="newDomain" placeholder="erp.acme.com" :class="{ invalid: fieldError('domain') }" @keyup.enter="addDomain" />
              <Button label="Map domain" icon="pi pi-plus" :loading="addingDomain" @click="addDomain" />
            </div>
            <small v-if="fieldError('domain')" class="error-text">{{ fieldError('domain') }}</small>

            <p class="note">
              Mapping a host records it as this workspace's — which is what makes an
              unauthenticated page served there wear this brand. Pointing the domain's
              DNS at the app and issuing its certificate are still infra steps
              (see the README's wildcard DNS &amp; SSL notes); tenancy itself is
              resolved from the signed-in user, not the host.
            </p>
          </TabPanel>

          <!-- --------------------------------------------------------- Theme -->
          <TabPanel value="theme">
            <div class="form-grid">
              <div class="form-field">
                <label>Display Name</label>
                <InputText v-model="theme.brand_app_name" :placeholder="branding?.app_name" :disabled="readonly" />
                <small class="hint">Blank = the workspace's company name.</small>
              </div>
              <div class="form-field">
                <label>Tagline</label>
                <InputText v-model="theme.brand_tagline" placeholder="One line under the logo" :disabled="readonly" />
              </div>

              <div class="form-field">
                <label>Primary Colour</label>
                <div class="color-row">
                  <input
                    type="color"
                    :value="theme.brand_primary_color || platform?.primary_color"
                    :disabled="readonly"
                    @input="theme.brand_primary_color = ($event.target as HTMLInputElement).value"
                  />
                  <InputText v-model="theme.brand_primary_color" :placeholder="platform?.primary_color" :disabled="readonly" />
                  <Button
                    v-if="!readonly && theme.brand_primary_color"
                    icon="pi pi-undo"
                    text
                    rounded
                    size="small"
                    title="Follow the platform colour"
                    @click="theme.brand_primary_color = ''"
                  />
                </div>
                <small v-if="fieldError('brand_primary_color')" class="error-text">{{ fieldError('brand_primary_color') }}</small>
              </div>

              <div class="form-field">
                <label>Accent Colour</label>
                <div class="color-row">
                  <input
                    type="color"
                    :value="theme.brand_accent_color || platform?.accent_color"
                    :disabled="readonly"
                    @input="theme.brand_accent_color = ($event.target as HTMLInputElement).value"
                  />
                  <InputText v-model="theme.brand_accent_color" :placeholder="platform?.accent_color" :disabled="readonly" />
                  <Button
                    v-if="!readonly && theme.brand_accent_color"
                    icon="pi pi-undo"
                    text
                    rounded
                    size="small"
                    title="Follow the platform colour"
                    @click="theme.brand_accent_color = ''"
                  />
                </div>
                <small v-if="fieldError('brand_accent_color')" class="error-text">{{ fieldError('brand_accent_color') }}</small>
              </div>

              <div class="form-field">
                <div class="checkbox-row">
                  <Checkbox v-model="theme.brand_dark_default" binary inputId="b-dark" :disabled="readonly" />
                  <label for="b-dark">Open in dark mode by default</label>
                </div>
                <small class="hint">A user who has picked a theme keeps theirs.</small>
              </div>
              <div class="form-field">
                <div class="checkbox-row">
                  <Checkbox v-model="theme.brand_powered_by" binary inputId="b-powered" :disabled="readonly" />
                  <label for="b-powered">Show the “powered by” credit</label>
                </div>
                <small class="hint">Switch off for a fully unbranded workspace.</small>
              </div>
            </div>

            <!-- Live preview of the two colours, so a hex typo is visible before
                 it reaches the customer's screens. -->
            <div class="preview" :style="{ '--pv-primary': theme.brand_primary_color || platform?.primary_color, '--pv-accent': theme.brand_accent_color || platform?.accent_color }">
              <span class="pv-label">Preview</span>
              <span class="pv-chip pv-primary">Primary</span>
              <span class="pv-chip pv-accent">Accent</span>
              <span class="pv-name">{{ theme.brand_app_name || platform?.app_name }}</span>
            </div>

            <div v-if="!readonly" class="tab-actions">
              <Button label="Save theme" icon="pi pi-check" :loading="savingTheme" @click="saveTheme" />
            </div>
          </TabPanel>

          <!-- ---------------------------------------------------------- SMTP -->
          <TabPanel value="smtp">
            <div class="smtp-status">
              <Tag :severity="smtp?.configured ? 'success' : 'warn'" :value="smtp?.configured ? 'Sending over SMTP' : 'Writing to the log'" />
              <small class="hint">
                With the log driver every message is written to the application log, so
                the workspace's email screens work with no mail server at all.
              </small>
            </div>

            <div class="form-grid">
              <div class="form-field full">
                <label>Driver</label>
                <Select v-model="mail.mail_driver" :options="driverOptions" optionLabel="label" optionValue="value" :disabled="readonly" />
              </div>

              <template v-if="mail.mail_driver === 'smtp'">
                <div class="form-field">
                  <label>Host <span class="req">*</span></label>
                  <InputText v-model="mail.mail_host" placeholder="smtp.acme.com" :class="{ invalid: fieldError('mail_host') }" :disabled="readonly" />
                  <small v-if="fieldError('mail_host')" class="error-text">{{ fieldError('mail_host') }}</small>
                </div>
                <div class="form-field">
                  <label>Port</label>
                  <InputNumber v-model="mail.mail_port" :useGrouping="false" :min="1" :max="65535" :disabled="readonly" />
                </div>
                <div class="form-field">
                  <label>Username</label>
                  <InputText v-model="mail.mail_username" :disabled="readonly" />
                </div>
                <div class="form-field">
                  <label>Password</label>
                  <Password
                    v-model="mail.mail_password"
                    :feedback="false"
                    toggleMask
                    :placeholder="smtp?.has_password ? '•••••••• (unchanged)' : ''"
                    :disabled="readonly"
                  />
                  <small class="hint">Leave blank to keep the stored password.</small>
                </div>
                <div class="form-field">
                  <label>Encryption</label>
                  <Select v-model="mail.mail_encryption" :options="encryptionOptions" optionLabel="label" optionValue="value" :disabled="readonly" />
                </div>
                <div class="form-field" />
              </template>

              <div class="form-field">
                <label>From Address <span v-if="mail.mail_driver === 'smtp'" class="req">*</span></label>
                <InputText
                  v-model="mail.mail_from_address"
                  placeholder="billing@acme.com"
                  :class="{ invalid: fieldError('mail_from_address') }"
                  :disabled="readonly"
                />
                <small v-if="fieldError('mail_from_address')" class="error-text">{{ fieldError('mail_from_address') }}</small>
              </div>
              <div class="form-field">
                <label>From Name</label>
                <InputText v-model="mail.mail_from_name" :placeholder="props.tenant?.company_name" :disabled="readonly" />
              </div>
            </div>

            <div v-if="!readonly" class="tab-actions">
              <div class="test-row">
                <InputText v-model="testTo" placeholder="you@example.com" />
                <Button label="Send test" icon="pi pi-send" outlined :loading="testing" :disabled="!testTo" @click="sendTest" />
              </div>
              <Button label="Save email settings" icon="pi pi-check" :loading="savingSmtp" @click="saveSmtp" />
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>

    <template #footer>
      <Button label="Close" text @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.loading { display: flex; align-items: center; gap: 0.5rem; padding: 2rem; justify-content: center; color: #6b7280; }
.intro {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 0.9rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.tab-icon { margin-right: 0.35rem; }
:deep(.p-tabpanels) { padding: 1rem 0 0; background: transparent; }
:deep(.p-tablist-tab-list) { background: transparent; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
.form-field { display: flex; flex-direction: column; gap: 0.3rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.82rem; font-weight: 500; color: var(--graphite, #374151); }
.form-field :deep(.p-inputtext),
.form-field :deep(.p-password),
.form-field :deep(.p-select),
.form-field :deep(.p-inputnumber) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.checkbox-row label { font-size: 0.82rem; }
.req { color: #dc2626; }
.hint { color: var(--muted, #6b7280); font-size: 0.74rem; }
.note { margin: 0.6rem 0 0; font-size: 0.76rem; line-height: 1.5; color: var(--muted, #6b7280); }
.error-text { color: #dc2626; font-size: 0.76rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }

.tab-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  margin-top: 1.1rem;
  flex-wrap: wrap;
}
.test-row { display: flex; align-items: center; gap: 0.5rem; }
.test-row :deep(.p-inputtext) { width: 13rem; }

/* Logo */
.logo-row { display: flex; gap: 1.1rem; align-items: flex-start; }
.logo-frame {
  flex: none;
  width: 180px;
  height: 110px;
  display: grid;
  place-items: center;
  padding: 0.6rem;
  border: 1px dashed var(--border, #e3ded3);
  border-radius: 12px;
  background: var(--surface-2, #f8f6f1);
}
.logo-frame img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo-empty { display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; color: var(--muted, #9ca3af); }
.logo-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem; }
.upload {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 0.9rem;
  border-radius: 8px;
  border: 1px solid var(--border, #e3ded3);
  background: var(--surface, #fff);
  font-size: 0.84rem;
  font-weight: 600;
  cursor: pointer;
}
.upload:hover { border-color: var(--safety-amber, #e8a33d); }

/* Domains */
.domain-list { display: flex; flex-direction: column; gap: 0.35rem; }
.domain {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border, #e3ded3);
  border-radius: 9px;
}
.domain-name { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 0.84rem; overflow-wrap: anywhere; }
.mini-tag { font-size: 0.66rem; }
.add-domain { display: flex; gap: 0.5rem; margin-top: 0.7rem; }
.add-domain :deep(.p-inputtext) { flex: 1; }

/* Theme preview */
.preview {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 1rem;
  padding: 0.6rem 0.8rem;
  border: 1px solid var(--border, #e3ded3);
  border-radius: 10px;
  flex-wrap: wrap;
}
.pv-label { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase; color: var(--muted, #9ca3af); }
.pv-chip { padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.76rem; font-weight: 700; color: #fff; }
.pv-primary { background: var(--pv-primary); color: #1e2328; }
.pv-accent { background: var(--pv-accent); }
.pv-name { font-weight: 700; font-size: 0.9rem; }

.color-row { display: flex; align-items: center; gap: 0.4rem; }
.color-row input[type='color'] {
  flex: none;
  width: 34px;
  height: 32px;
  padding: 0;
  border: 1px solid var(--border, #e3ded3);
  border-radius: 7px;
  background: none;
  cursor: pointer;
}
.color-row :deep(.p-inputtext) { width: 6.5rem; font-family: 'IBM Plex Mono', ui-monospace, monospace; }

.smtp-status { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.9rem; flex-wrap: wrap; }

@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
  .logo-row { flex-direction: column; }
}
</style>
