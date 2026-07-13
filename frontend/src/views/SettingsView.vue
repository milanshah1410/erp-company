<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import RadioButton from 'primevue/radiobutton'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
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
  </div>
</template>

<style scoped>
.settings-page h1 {
  font-size: 1.4rem;
  margin-bottom: 1rem;
}
.card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1.25rem 1.5rem;
  max-width: 720px;
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
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  cursor: pointer;
}
.option.selected {
  border-color: #2563eb;
  background: #eff6ff;
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
  display: flex;
  flex-direction: column;
}
.settings-page .card + .card {
  margin-top: 1.25rem;
}
</style>
