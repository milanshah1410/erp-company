<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import RadioButton from 'primevue/radiobutton'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()

const canEdit = computed(() => auth.can('settings.edit'))

const loading = ref(true)
const saving = ref(false)
const costingMethod = ref('weighted_average')
const approvalThreshold = ref<number | null>(0)
const paymentTermDays = ref<number | null>(30)
const financialYearStartMonth = ref<number>(4)
const currencyCode = ref('INR')
const currencySymbol = ref('₹')
const defaultTaxPercent = ref<number | null>(0)

const NUMBER_PREFIX_KEYS = [
  { key: 'po_number_prefix', label: 'Purchase Order' },
  { key: 'grn_number_prefix', label: 'Goods Receipt (GRN)' },
  { key: 'invoice_number_prefix', label: 'Sales Invoice' },
  { key: 'so_number_prefix', label: 'Sales Order' },
  { key: 'quote_number_prefix', label: 'Sales Quotation' },
  { key: 'transfer_number_prefix', label: 'Stock Transfer' },
  { key: 'return_number_prefix', label: 'Stock Return' },
  { key: 'credit_note_number_prefix', label: 'Sales Credit Note' },
  { key: 'adjustment_number_prefix', label: 'Stock Adjustment' },
  { key: 'cycle_count_number_prefix', label: 'Cycle Count' },
  { key: 'gate_entry_number_prefix', label: 'Gate Entry' },
]
const numberPrefixes = ref<Record<string, string>>({})

// --- SMS gateway (SRS §18) ---
const SMS_KEYS = [
  'sms_driver', 'sms_provider', 'sms_sender_id', 'sms_route', 'sms_country_code',
  'sms_auth_key', 'sms_otp_length', 'sms_otp_ttl',
  'sms_tpl_otp', 'sms_tpl_invoice', 'sms_tpl_dispatch', 'sms_tpl_payment_reminder', 'sms_tpl_festival',
]
const sms = ref<Record<string, any>>({})
const savingSms = ref(false)
const smsDriverOptions = [
  { label: 'Log only (no gateway account needed)', value: 'log' },
  { label: 'MSG91 (send for real)', value: 'msg91' },
]
const SMS_TEMPLATE_FIELDS = [
  { key: 'sms_tpl_otp', label: 'OTP', hint: 'Placeholders: {otp} {minutes}' },
  { key: 'sms_tpl_invoice', label: 'Invoice', hint: 'Placeholders: {name} {number} {symbol} {amount} {business}' },
  { key: 'sms_tpl_dispatch', label: 'Dispatch', hint: 'Placeholders: {name} {number} {date} {business}' },
  { key: 'sms_tpl_payment_reminder', label: 'Payment Reminder', hint: 'Placeholders: {name} {symbol} {amount} {business}' },
  { key: 'sms_tpl_festival', label: 'Festival Greeting', hint: 'Placeholders: {name} {festival} {business}' },
]

// --- Email / SMTP gateway (SRS §19) ---
const MAIL_KEYS = [
  'mail_driver', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
  'mail_encryption', 'mail_from_address', 'mail_from_name',
]
const mail = ref<Record<string, any>>({})
const savingMail = ref(false)
const testingMail = ref(false)
const testEmailTo = ref('')
const mailDriverOptions = [
  { label: 'Log only (no mail server needed)', value: 'log' },
  { label: 'SMTP (send for real)', value: 'smtp' },
]
const mailEncryptionOptions = [
  { label: 'TLS', value: 'tls' },
  { label: 'SSL', value: 'ssl' },
  { label: 'None', value: 'none' },
]

const monthOptions = [
  { label: 'January', value: 1 }, { label: 'February', value: 2 }, { label: 'March', value: 3 },
  { label: 'April', value: 4 }, { label: 'May', value: 5 }, { label: 'June', value: 6 },
  { label: 'July', value: 7 }, { label: 'August', value: 8 }, { label: 'September', value: 9 },
  { label: 'October', value: 10 }, { label: 'November', value: 11 }, { label: 'December', value: 12 },
]

const costingOptions = [
  {
    value: 'fifo',
    label: 'FIFO (First In, First Out)',
    hint: 'Issues are costed from the oldest receipt lots first. Stock value reflects the most recent purchase prices.',
  },
  {
    value: 'lifo',
    label: 'LIFO (Last In, First Out)',
    hint: 'Issues are costed from the newest receipt lots first. Stock value reflects the oldest purchase prices.',
  },
  {
    value: 'weighted_average',
    label: 'Weighted Average',
    hint: 'Issues are costed at the running average cost, recalculated on every receipt.',
  },
]

function applySettings(data: Record<string, any>) {
  costingMethod.value = data.costing_method ?? 'weighted_average'
  approvalThreshold.value = Number(data.purchase_approval_threshold ?? 0)
  paymentTermDays.value = Number(data.sales_payment_term_days ?? 30)
  financialYearStartMonth.value = Number(data.financial_year_start_month ?? 4)
  currencyCode.value = data.currency_code ?? 'INR'
  currencySymbol.value = data.currency_symbol ?? '₹'
  defaultTaxPercent.value = Number(data.default_tax_percent ?? 0)
  const prefixes: Record<string, string> = {}
  for (const { key } of NUMBER_PREFIX_KEYS) prefixes[key] = data[key] ?? ''
  numberPrefixes.value = prefixes
  const smsData: Record<string, any> = {}
  for (const key of SMS_KEYS) smsData[key] = data[key] ?? ''
  smsData.sms_otp_length = Number(data.sms_otp_length ?? 6)
  smsData.sms_otp_ttl = Number(data.sms_otp_ttl ?? 300)
  sms.value = smsData
  const mailData: Record<string, any> = {}
  for (const key of MAIL_KEYS) mailData[key] = data[key] ?? ''
  if (!mailData.mail_driver) mailData.mail_driver = 'log'
  if (!mailData.mail_encryption) mailData.mail_encryption = 'tls'
  if (!mailData.mail_port) mailData.mail_port = 587
  mail.value = mailData
}

async function saveMail() {
  savingMail.value = true
  try {
    const payload: Record<string, any> = {}
    for (const key of MAIL_KEYS) payload[key] = mail.value[key]
    const { data } = await http.put('/settings', payload)
    applySettings(data.data ?? {})
    toast.add({ severity: 'success', summary: 'Email settings saved', life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message ?? 'Unexpected error', life: 4000 })
  } finally {
    savingMail.value = false
  }
}

async function sendTestEmail() {
  if (!testEmailTo.value) {
    toast.add({ severity: 'warn', summary: 'Enter a recipient email first', life: 3000 })
    return
  }
  testingMail.value = true
  try {
    const { data } = await http.post('/email/test', { to: testEmailTo.value })
    toast.add({ severity: 'success', summary: 'Test email', detail: data.message, life: 5000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Test failed', detail: e.response?.data?.message ?? 'Unexpected error', life: 5000 })
  } finally {
    testingMail.value = false
  }
}

async function saveSms() {
  savingSms.value = true
  try {
    const payload: Record<string, any> = {}
    for (const key of SMS_KEYS) payload[key] = sms.value[key]
    const { data } = await http.put('/settings', payload)
    applySettings(data.data ?? {})
    toast.add({ severity: 'success', summary: 'SMS settings saved', life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message ?? 'Unexpected error', life: 4000 })
  } finally {
    savingSms.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/settings')
    applySettings(data.data ?? {})
  } catch {
    toast.add({ severity: 'error', summary: 'Failed to load settings', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/settings', {
      costing_method: costingMethod.value,
      purchase_approval_threshold: approvalThreshold.value ?? 0,
      sales_payment_term_days: paymentTermDays.value ?? 30,
      financial_year_start_month: financialYearStartMonth.value,
      currency_code: currencyCode.value,
      currency_symbol: currencySymbol.value,
      default_tax_percent: defaultTaxPercent.value ?? 0,
      ...numberPrefixes.value,
    })
    applySettings(data.data ?? {})
    toast.add({ severity: 'success', summary: 'Settings saved', life: 3000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Save failed',
      detail: e.response?.data?.message ?? 'Unexpected error',
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="settings-page">
    <h1>Company Settings</h1>

    <div class="card">
      <h2>Document Numbering</h2>
      <p class="card-note">
        Prefix used when generating each document's next number, e.g. a "PO" prefix produces PO-000001. Only affects
        numbers generated from now on — existing documents keep their numbers.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <div v-else class="form-grid">
        <div v-for="item in NUMBER_PREFIX_KEYS" :key="item.key" class="fld">
          <label>{{ item.label }}</label>
          <InputText v-model="numberPrefixes[item.key]" :disabled="!canEdit" />
        </div>
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>Default Tax Rate</h2>
      <p class="card-note">
        Suggested tax percentage pre-filled when creating a new product. Individual products can still override it.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <div v-else class="fld">
        <label>Default tax %</label>
        <InputNumber v-model="defaultTaxPercent" :min="0" :max="100" :minFractionDigits="0" :maxFractionDigits="2" suffix=" %" :disabled="!canEdit" />
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>Inventory Costing Method</h2>
      <p class="card-note">
        Determines how outward stock movements (sales, transfers, adjustments) are costed.
        Changing the method applies to future movements only; past ledger entries are never restated.
      </p>

      <div v-if="loading" class="loading">Loading…</div>

      <div v-else class="options">
        <label v-for="opt in costingOptions" :key="opt.value" class="option" :class="{ selected: costingMethod === opt.value }">
          <RadioButton v-model="costingMethod" :value="opt.value" :disabled="!canEdit" />
          <span class="option-text">
            <span class="option-label">{{ opt.label }}</span>
            <span class="option-hint">{{ opt.hint }}</span>
          </span>
        </label>
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>Purchase Order Approval</h2>
      <p class="card-note">
        Purchase orders at or below this amount finalize with a single approval, as before.
        Orders above it need a second approval from an admin, distinct from whoever gave the first.
        Set to 0 to disable multi-level approval.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <div v-else class="fld">
        <label>Second-approval threshold amount</label>
        <InputNumber v-model="approvalThreshold" :min="0" :minFractionDigits="2" :maxFractionDigits="2" :disabled="!canEdit" />
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>Sales Payment Terms</h2>
      <p class="card-note">
        New customer invoices are due this many days after the invoice date. Used by the "payment due" notification.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <div v-else class="fld">
        <label>Payment term (days)</label>
        <InputNumber v-model="paymentTermDays" :min="0" :disabled="!canEdit" />
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>Financial Year &amp; Currency</h2>
      <p class="card-note">
        The financial year start month drives "this financial year" filters in reports. Currency controls how amounts
        are formatted throughout the app.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <div v-else class="form-grid">
        <div class="fld">
          <label>Financial year starts in</label>
          <Select v-model="financialYearStartMonth" :options="monthOptions" optionLabel="label" optionValue="value" :disabled="!canEdit" />
        </div>
        <div class="fld">
          <label>Currency code</label>
          <InputText v-model="currencyCode" :disabled="!canEdit" placeholder="INR" />
        </div>
        <div class="fld">
          <label>Currency symbol</label>
          <InputText v-model="currencySymbol" :disabled="!canEdit" placeholder="₹" />
        </div>
      </div>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="saving" :disabled="loading" @click="save" />
      </div>
    </div>

    <div class="card">
      <h2>SMS Gateway</h2>
      <p class="card-note">
        Configure how the app sends SMS (OTP, invoice, dispatch, payment reminders, festival greetings).
        The <b>Log only</b> driver records messages without a gateway account — ideal for testing. Switch to
        <b>MSG91</b> and enter your auth key + sender id to send for real.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <template v-else>
        <div class="form-grid">
          <div class="fld">
            <label>Driver</label>
            <Select v-model="sms.sms_driver" :options="smsDriverOptions" optionLabel="label" optionValue="value" :disabled="!canEdit" />
          </div>
          <div class="fld">
            <label>Sender ID</label>
            <InputText v-model="sms.sms_sender_id" :disabled="!canEdit" placeholder="ERPTXT" />
          </div>
          <div class="fld">
            <label>MSG91 Auth Key</label>
            <InputText v-model="sms.sms_auth_key" :disabled="!canEdit" placeholder="Required for MSG91" />
          </div>
          <div class="fld">
            <label>Route</label>
            <InputText v-model="sms.sms_route" :disabled="!canEdit" placeholder="4" />
          </div>
          <div class="fld">
            <label>Country code</label>
            <InputText v-model="sms.sms_country_code" :disabled="!canEdit" placeholder="91" />
          </div>
          <div class="fld">
            <label>OTP length</label>
            <InputNumber v-model="sms.sms_otp_length" :min="4" :max="8" :disabled="!canEdit" />
          </div>
          <div class="fld">
            <label>OTP validity (seconds)</label>
            <InputNumber v-model="sms.sms_otp_ttl" :min="60" :max="1800" :disabled="!canEdit" />
          </div>
        </div>

        <h3 class="subhead">Message Templates</h3>
        <div class="tpl-grid">
          <div v-for="t in SMS_TEMPLATE_FIELDS" :key="t.key" class="fld">
            <label>{{ t.label }}</label>
            <Textarea v-model="sms[t.key]" rows="2" :disabled="!canEdit" autoResize />
            <small class="tpl-hint">{{ t.hint }}</small>
          </div>
        </div>
      </template>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="savingSms" :disabled="loading" @click="saveSms" />
      </div>
    </div>

    <div class="card">
      <h2>Email Gateway</h2>
      <p class="card-note">
        Configure how the app sends email (invoice / purchase order / quotation PDFs and marketing campaigns).
        The <b>Log only</b> driver records mail to the application log — ideal for testing with no mail server.
        Switch to <b>SMTP</b> and enter your mail account details to send for real.
      </p>

      <div v-if="loading" class="loading">Loading…</div>
      <template v-else>
        <div class="form-grid">
          <div class="fld">
            <label>Driver</label>
            <Select v-model="mail.mail_driver" :options="mailDriverOptions" optionLabel="label" optionValue="value" :disabled="!canEdit" />
          </div>
          <div class="fld">
            <label>Encryption</label>
            <Select v-model="mail.mail_encryption" :options="mailEncryptionOptions" optionLabel="label" optionValue="value" :disabled="!canEdit" />
          </div>
          <div class="fld">
            <label>SMTP Host</label>
            <InputText v-model="mail.mail_host" :disabled="!canEdit" placeholder="smtp.gmail.com" />
          </div>
          <div class="fld">
            <label>SMTP Port</label>
            <InputNumber v-model="mail.mail_port" :min="1" :max="65535" :useGrouping="false" :disabled="!canEdit" />
          </div>
          <div class="fld">
            <label>Username</label>
            <InputText v-model="mail.mail_username" :disabled="!canEdit" placeholder="you@company.com" />
          </div>
          <div class="fld">
            <label>Password</label>
            <InputText v-model="mail.mail_password" type="password" :disabled="!canEdit" placeholder="App password" />
          </div>
          <div class="fld">
            <label>From Address</label>
            <InputText v-model="mail.mail_from_address" :disabled="!canEdit" placeholder="no-reply@company.com" />
          </div>
          <div class="fld">
            <label>From Name</label>
            <InputText v-model="mail.mail_from_name" :disabled="!canEdit" placeholder="Your Company" />
          </div>
        </div>

        <h3 class="subhead">Send a Test Email</h3>
        <div class="test-row">
          <InputText v-model="testEmailTo" :disabled="!canEdit" placeholder="recipient@example.com" />
          <Button label="Send Test" icon="pi pi-send" outlined :loading="testingMail" :disabled="!canEdit" @click="sendTestEmail" />
        </div>
      </template>

      <div v-if="canEdit" class="actions">
        <Button label="Save" icon="pi pi-check" :loading="savingMail" :disabled="loading" @click="saveMail" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-page h1 {
  font-size: 1.4rem;
  margin-bottom: 1.25rem;
  column-span: all;
}
.card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.25rem 1.5rem;
  break-inside: avoid;
  margin-bottom: 1.25rem;
}
.card h2 {
  font-size: 1.05rem;
  margin-bottom: 0.35rem;
}
.card-note {
  font-size: 0.85rem;
  color: #6b7280;
  margin-bottom: 1rem;
}
.options {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}
.option {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--border, #e8e4da);
  border-radius: 8px;
  cursor: pointer;
}
.option.selected {
  border-color: var(--safety-amber, #e8a33d);
  background: rgba(232, 163, 61, 0.1);
}
.option-text {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.option-label {
  font-weight: 600;
  font-size: 0.92rem;
}
.option-hint {
  font-size: 0.8rem;
  color: #6b7280;
}
.loading {
  color: #6b7280;
  font-size: 0.9rem;
  padding: 0.5rem 0;
}
.actions {
  margin-top: 1.1rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  max-width: 260px;
}
.fld label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.form-grid .fld {
  max-width: none;
}
.settings-page {
  columns: 2;
  column-gap: 1.25rem;
}
@media (max-width: 900px) {
  .settings-page {
    columns: 1;
  }
}
.subhead {
  font-size: 0.95rem;
  font-weight: 600;
  margin: 1.1rem 0 0.75rem;
}
.test-row {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  flex-wrap: wrap;
}
.test-row :deep(.p-inputtext) {
  min-width: 260px;
}
</style>
