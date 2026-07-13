<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()

const brokers = ref<{ label: string; value: number }[]>([])
const brokerId = ref<number | null>(null)
const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<{ amount: number | null; mode: string; settlement_date: Date | null; notes: string }>({
  amount: null,
  mode: 'cash',
  settlement_date: new Date(),
  notes: '',
})

const modeOptions = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank', value: 'bank' },
  { label: 'UPI', value: 'upi' },
  { label: 'Adjustment', value: 'adjustment' },
]

const canSettle = computed(() => auth.can('brokers.create'))

function money(v: any) {
  return formatCurrency(v)
}

async function loadBrokers() {
  const { data } = await http.get('/brokers', { params: { per_page: 100, is_active: true } })
  brokers.value = (data.data ?? data).map((b: any) => ({ label: `${b.code} — ${b.name}`, value: b.id }))
}

async function loadLedger() {
  if (!brokerId.value) {
    entries.value = []
    summary.value = null
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/brokers/${brokerId.value}/ledger`)
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

function openSettle() {
  form.value = { amount: null, mode: 'cash', settlement_date: new Date(), notes: '' }
  errors.value = {}
  dialogVisible.value = true
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function saveSettlement() {
  if (!brokerId.value) return
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      amount: form.value.amount,
      mode: form.value.mode,
      settlement_date: form.value.settlement_date
        ? form.value.settlement_date.toISOString().slice(0, 10)
        : undefined,
      notes: form.value.notes || undefined,
    }
    await http.post(`/brokers/${brokerId.value}/settlements`, payload)
    toast.add({ severity: 'success', summary: 'Settlement recorded', life: 2500 })
    dialogVisible.value = false
    await loadLedger()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

onMounted(loadBrokers)
</script>

<template>
  <div>
    <div class="header">
      <h1>Broker Ledger &amp; Settlement</h1>
      <Button v-if="canSettle" label="Record Settlement" icon="pi pi-wallet"
        :disabled="!brokerId" @click="openSettle" />
    </div>

    <div class="toolbar">
      <Select v-model="brokerId" :options="brokers" optionLabel="label" optionValue="value"
        placeholder="Select a broker" showClear filter class="broker-select" @change="loadLedger" />
    </div>

    <div v-if="summary" class="tiles">
      <div class="tile">
        <span class="tile-label">Opening Balance</span>
        <span class="tile-value">{{ money(summary.opening_balance) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Earned</span>
        <span class="tile-value">{{ money(summary.total_earned) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Settled</span>
        <span class="tile-value">{{ money(summary.total_settled) }}</span>
      </div>
      <div class="tile pending">
        <span class="tile-label">Pending Commission</span>
        <span class="tile-value">{{ money(summary.pending_commission) }}</span>
      </div>
    </div>

    <DataTable :value="entries" :loading="loading" dataKey="reference" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column header="Type">
        <template #body="{ data }">
          <Tag :value="data.type" :severity="data.debit > 0 ? 'success' : 'info'" />
        </template>
      </Column>
      <Column field="reference" header="Reference" />
      <Column header="Commission (Credit)">
        <template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template>
      </Column>
      <Column header="Settled (Debit)">
        <template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template>
      </Column>
      <Column header="Balance">
        <template #body="{ data }">{{ money(data.balance) }}</template>
      </Column>
      <template #empty>{{ brokerId ? 'No ledger entries yet.' : 'Select a broker to view their ledger.' }}</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal header="Record Settlement" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>Amount <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN"
            :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>Mode <span class="req">*</span></label>
          <Select v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="field">
          <label>Settlement Date</label>
          <DatePicker v-model="form.settlement_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" :loading="saving" @click="saveSettlement" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  margin-bottom: 1rem;
}
.broker-select {
  min-width: 22rem;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1.25rem;
}
.tile {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  padding: 0.85rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.tile.pending {
  background: #fff7ed;
  border-color: #fed7aa;
}
.tile-label {
  font-size: 0.8rem;
  color: #64748b;
}
.tile-value {
  font-size: 1.25rem;
  font-weight: 600;
  color: #0f172a;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
