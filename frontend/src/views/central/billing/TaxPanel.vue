<script setup lang="ts">
/**
 * Billing → GST & Invoicing (Volume 11 §Billing → GST).
 *
 * The seller side of every subscription invoice: who we are, the GSTIN we collect
 * under, the rate, and the number series.
 *
 * Two things this screen makes explicit rather than hiding:
 *  - No GSTIN means no GST. An unregistered supplier cannot collect it, so the rate
 *    is ignored until a GSTIN is on file.
 *  - The state is read out of the GSTIN, because its first two digits *are* the
 *    state of registration. That is what decides CGST+SGST vs IGST per customer.
 */
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtPercent } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('settings.edit'))

const form = ref<any>({})
const series = ref<any>({})
const states = ref<any[]>([])
const chargesTax = ref(false)
const stateName = ref<string | null>(null)
const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/tax')
    form.value = {
      legal_name: data.data.legal_name ?? '',
      address: data.data.address ?? '',
      gstin: data.data.gstin ?? '',
      state_code: data.data.state_code ?? '',
      pan: data.data.pan ?? '',
      email: data.data.email ?? '',
      phone: data.data.phone ?? '',
      tax_rate: Number(data.data.tax_rate ?? 18),
      sac_code: data.data.sac_code ?? '',
      prices_include_tax: !!data.data.prices_include_tax,
      invoice_prefix: data.data.invoice_prefix ?? 'INV',
      invoice_terms: data.data.invoice_terms ?? '',
    }
    series.value = data.series ?? {}
    states.value = data.states ?? []
    chargesTax.value = !!data.data.charges_tax
    stateName.value = data.data.state_name ?? null
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the billing profile', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    await centralHttp.put('/billing/tax', {
      ...form.value,
      gstin: String(form.value.gstin || '').trim().toUpperCase() || null,
      // The state is derived from the GSTIN when one is present; only send the
      // manual code for an unregistered deployment.
      state_code: form.value.gstin ? null : (form.value.state_code || null),
    })
    toast.add({ severity: 'success', summary: 'Billing profile saved', life: 2500 })
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not save', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    saving.value = false
  }
}

/** What an invoice for a ₹1,000 plan would look like under the current settings. */
const preview = computed(() => {
  const rate = chargesTax.value ? Number(form.value.tax_rate ?? 0) : 0
  const base = 1000
  const taxable = form.value.prices_include_tax && rate > 0 ? +(base / (1 + rate / 100)).toFixed(2) : base
  const tax = +(taxable * rate / 100).toFixed(2)
  return {
    taxable,
    half: +(tax / 2).toFixed(2),
    tax,
    total: +(taxable + tax).toFixed(2),
    rate,
  }
})

onMounted(load)
</script>

<template>
  <div class="wrap">
    <Message v-if="!form.gstin" severity="warn" :closable="false" class="banner">
      No GSTIN on file, so invoices are raised zero-rated and titled "Subscription Invoice" rather than "Tax Invoice".
      Add one to start charging GST.
    </Message>
    <Message v-else-if="!chargesTax" severity="info" :closable="false" class="banner">
      A GSTIN is on file but the rate is 0% — invoices carry no tax.
    </Message>

    <div class="columns">
      <section class="panel">
        <h3>Seller identity</h3>
        <p class="section-note">Printed on every subscription invoice, and captured onto each invoice as it is raised — editing this never rewrites an invoice already issued.</p>

        <div class="form-grid">
          <div class="form-field full">
            <label>Registered legal name</label>
            <InputText v-model="form.legal_name" :disabled="!canEdit" placeholder="Acme Software Private Limited"
              :class="{ invalid: fieldError('legal_name') }" />
            <small class="hint">Falls back to the app name when blank.</small>
          </div>
          <div class="form-field full">
            <label>Registered address</label>
            <Textarea v-model="form.address" :disabled="!canEdit" rows="3" autoResize />
          </div>
          <div class="form-field">
            <label>GSTIN</label>
            <InputText v-model="form.gstin" :disabled="!canEdit" maxlength="15" placeholder="24ABCDE1234F1Z5"
              class="mono" :class="{ invalid: fieldError('gstin') }"
              @input="form.gstin = String(form.gstin).toUpperCase()" />
            <small v-if="fieldError('gstin')" class="error-text">{{ fieldError('gstin') }}</small>
            <small v-else-if="stateName" class="hint">Registered in {{ stateName }} ({{ form.state_code }}).</small>
          </div>
          <div class="form-field">
            <label>PAN</label>
            <InputText v-model="form.pan" :disabled="!canEdit" maxlength="10" class="mono"
              @input="form.pan = String(form.pan).toUpperCase()" />
          </div>
          <div v-if="!form.gstin" class="form-field">
            <label>Place of supply (state)</label>
            <Select v-model="form.state_code" :options="states" optionLabel="name" optionValue="code"
              :disabled="!canEdit" showClear placeholder="Not set" filter />
            <small class="hint">Only used when there is no GSTIN to read it from.</small>
          </div>
          <div class="form-field">
            <label>Billing email</label>
            <InputText v-model="form.email" :disabled="!canEdit" :class="{ invalid: fieldError('email') }" />
            <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
          </div>
          <div class="form-field">
            <label>Billing phone</label>
            <InputText v-model="form.phone" :disabled="!canEdit" />
          </div>
        </div>
      </section>

      <section class="panel">
        <h3>Tax &amp; numbering</h3>
        <p class="section-note">GST is destination-based: a customer in your own state is charged CGST + SGST, one in another state IGST. The split is decided per invoice from the customer's GSTIN.</p>

        <div class="form-grid">
          <div class="form-field">
            <label>GST rate</label>
            <InputNumber v-model="form.tax_rate" :disabled="!canEdit" :min="0" :max="28" suffix=" %"
              :minFractionDigits="0" :maxFractionDigits="2" :class="{ invalid: fieldError('tax_rate') }" />
            <small class="hint">18% on SaaS in India.</small>
          </div>
          <div class="form-field">
            <label>SAC code</label>
            <InputText v-model="form.sac_code" :disabled="!canEdit" maxlength="10" class="mono" />
            <small class="hint">997331 — licensing of software.</small>
          </div>
          <div class="form-field full">
            <div class="checkbox-row">
              <Checkbox v-model="form.prices_include_tax" binary inputId="tp-incl" :disabled="!canEdit" />
              <label for="tp-incl">Plan prices already include GST</label>
            </div>
            <small class="hint">Off = "₹999 + GST", the usual Indian B2B quote. On = the listed price is the final total.</small>
          </div>

          <div class="form-field">
            <label>Invoice prefix</label>
            <InputText v-model="form.invoice_prefix" :disabled="!canEdit" maxlength="12" class="mono"
              :class="{ invalid: fieldError('invoice_prefix') }" />
            <small v-if="fieldError('invoice_prefix')" class="error-text">{{ fieldError('invoice_prefix') }}</small>
            <small v-else-if="series.locked" class="hint">This year's series is already open on "{{ series.prefix }}" — a change applies from the next financial year.</small>
          </div>
          <div class="form-field">
            <label>Next number</label>
            <div class="series-box">
              <span class="mono">{{ series.next_preview ?? '—' }}</span>
              <Tag :value="series.financial_year" severity="secondary" />
            </div>
            <small class="hint">One gap-free series per financial year, as GST requires.</small>
          </div>

          <div class="form-field full">
            <label>Invoice terms / footer</label>
            <Textarea v-model="form.invoice_terms" :disabled="!canEdit" rows="2" autoResize
              placeholder="Payment due within 7 days. Subject to Ahmedabad jurisdiction." />
          </div>
        </div>

        <div class="preview">
          <h4>On a {{ preview.rate ? '' : 'zero-rated ' }}₹1,000 plan</h4>
          <div class="preview-row"><span>Taxable value</span><span>₹{{ preview.taxable.toFixed(2) }}</span></div>
          <template v-if="preview.rate">
            <div class="preview-row"><span>CGST {{ fmtPercent(preview.rate / 2) }} + SGST {{ fmtPercent(preview.rate / 2) }} <span class="muted">(same state)</span></span><span>₹{{ preview.half.toFixed(2) }} + ₹{{ preview.half.toFixed(2) }}</span></div>
            <div class="preview-row"><span>or IGST {{ fmtPercent(preview.rate) }} <span class="muted">(other state)</span></span><span>₹{{ preview.tax.toFixed(2) }}</span></div>
          </template>
          <div class="preview-row total"><span>Invoice total</span><span>₹{{ preview.total.toFixed(2) }}</span></div>
        </div>
      </section>
    </div>

    <div class="footer">
      <Button v-if="canEdit" label="Save billing profile" icon="pi pi-check" :loading="saving" @click="save" />
      <span v-else class="hint">You have read-only access to platform settings.</span>
    </div>
  </div>
</template>

<style scoped>
.wrap { display: flex; flex-direction: column; gap: 1rem; }
.banner { margin: 0; }
.columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1rem; align-items: start; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.1rem; }
.panel h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.2rem; }
.section-note { font-size: 0.78rem; color: #6b7280; margin-bottom: 1rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
.form-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(.p-select),
.form-field :deep(textarea) { width: 100%; }
.mono :deep(input), .mono { font-family: ui-monospace, monospace; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.series-box { display: flex; align-items: center; gap: 0.5rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.45rem 0.6rem; }
.preview { margin-top: 1.1rem; border-top: 1px solid #f3f4f6; padding-top: 0.85rem; }
.preview h4 { font-size: 0.82rem; font-weight: 600; color: #374151; margin-bottom: 0.5rem; }
.preview-row { display: flex; justify-content: space-between; font-size: 0.8rem; color: #4b5563; padding: 0.15rem 0; gap: 1rem; }
.preview-row.total { border-top: 1px solid #e5e7eb; margin-top: 0.35rem; padding-top: 0.4rem; font-weight: 600; color: #111827; }
.footer { display: flex; align-items: center; gap: 0.75rem; }
.muted { color: #9ca3af; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
