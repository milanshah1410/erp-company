<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import BrokerRatesPanel from '../components/broker/BrokerRatesPanel.vue'
import BrokerWalletPanel from '../components/broker/BrokerWalletPanel.vue'
import BrokerMonthlyStatement from '../components/broker/BrokerMonthlyStatement.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()

const brokers = ref<{ label: string; value: number; tds: number }[]>([])
const brokerId = ref<number | null>(null)
const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)
const refreshKey = ref(0)

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<{ amount: number | null; settleFull: boolean; tds_percent: number | null; mode: string; settlement_date: Date | null; period_month: string; notes: string }>({
  amount: null,
  settleFull: false,
  tds_percent: null,
  mode: 'cash',
  settlement_date: new Date(),
  period_month: '',
  notes: '',
})

const modeOptions = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank', value: 'bank' },
  { label: 'UPI', value: 'upi' },
  { label: 'Adjustment', value: 'adjustment' },
]

const canSettle = computed(() => auth.can('brokers.create'))

// Live preview: gross being settled, TDS withheld and net cash paid.
const gross = computed(() => (form.value.settleFull ? Number(summary.value?.pending_commission ?? 0) : Number(form.value.amount ?? 0)))
const tdsPreview = computed(() => Math.round(gross.value * Number(form.value.tds_percent ?? 0)) / 100)
const netPreview = computed(() => Math.round((gross.value - tdsPreview.value) * 100) / 100)

function money(v: any) {
  return formatCurrency(v)
}

async function loadBrokers() {
  const { data } = await http.get('/brokers', { params: { per_page: 100, is_active: true } })
  brokers.value = (data.data ?? data).map((b: any) => ({ label: `${b.code} — ${b.name}`, value: b.id, tds: Number(b.tds_percent ?? 0) }))
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

function onBrokerChange() {
  refreshKey.value++
  loadLedger()
}

function openSettle() {
  const broker = brokers.value.find((b) => b.value === brokerId.value)
  form.value = {
    amount: null,
    settleFull: false,
    tds_percent: broker?.tds ? broker.tds : null,
    mode: 'cash',
    settlement_date: new Date(),
    period_month: '',
    notes: '',
  }
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
    const payload: any = { mode: form.value.mode }
    if (!form.value.settleFull) payload.amount = form.value.amount
    if (form.value.tds_percent !== null && form.value.tds_percent !== undefined) payload.tds_percent = form.value.tds_percent
    if (form.value.settlement_date) payload.settlement_date = form.value.settlement_date.toISOString().slice(0, 10)
    if (form.value.period_month) payload.period_month = form.value.period_month
    if (form.value.notes) payload.notes = form.value.notes
    await http.post(`/brokers/${brokerId.value}/settlements`, payload)
    toast.add({ severity: 'success', summary: 'Settlement recorded', life: 2500 })
    dialogVisible.value = false
    refreshKey.value++
    await Promise.all([loadLedger(), loadBrokers()])
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
    <PageHeader title="Brokers (Dalal)" />

    <div class="toolbar">
      <Select v-model="brokerId" :options="brokers" optionLabel="label" optionValue="value"
        placeholder="Select a broker" showClear filter class="broker-select" @change="onBrokerChange" />
    </div>

    <Tabs value="ledger" v-if="brokerId">
      <TabList>
        <Tab value="ledger">Ledger &amp; Settlement</Tab>
        <Tab value="statement">Monthly Statement</Tab>
        <Tab value="rates">Commission Rates</Tab>
        <Tab value="wallet">Wallet</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="ledger">
          <div class="ledger-actions">
            <TableExportButtons title="Broker Ledger" :rows="entries"
              :columns="[
                { key: 'date', label: 'Date' },
                { key: 'type', label: 'Type' },
                { key: 'reference', label: 'Reference' },
                { key: 'credit', label: 'Commission (Credit)' },
                { key: 'debit', label: 'Settled (Debit)' },
                { key: 'balance', label: 'Balance' },
              ]" />
            <Button v-if="canSettle" label="Record Settlement" icon="pi pi-wallet" @click="openSettle" />
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
            <template #empty>No ledger entries yet.</template>
          </DataTable>
        </TabPanel>

        <TabPanel value="statement">
          <BrokerMonthlyStatement :key="'stmt-' + refreshKey" :brokerId="brokerId" />
        </TabPanel>
        <TabPanel value="rates">
          <BrokerRatesPanel :brokerId="brokerId" />
        </TabPanel>
        <TabPanel value="wallet">
          <BrokerWalletPanel :key="'wallet-' + refreshKey" :brokerId="brokerId" @changed="loadLedger" />
        </TabPanel>
      </TabPanels>
    </Tabs>

    <p v-else class="empty-hint">Select a broker to view their ledger, settlements, commission rates and wallet.</p>

    <Dialog v-model:visible="dialogVisible" modal header="Record Settlement" :style="{ width: '480px' }">
      <div class="form">
        <div class="field-check">
          <Checkbox v-model="form.settleFull" :binary="true" inputId="settleFull" />
          <label for="settleFull">Settle full pending balance ({{ money(summary?.pending_commission ?? 0) }})</label>
        </div>
        <div class="field" v-if="!form.settleFull">
          <label>Amount (gross) <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN"
            :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>TDS %</label>
          <InputNumber v-model="form.tds_percent" :minFractionDigits="0" :maxFractionDigits="2" suffix=" %" placeholder="Broker default" />
        </div>
        <div class="preview">
          <span>Gross: <strong>{{ money(gross) }}</strong></span>
          <span>TDS: <strong>{{ money(tdsPreview) }}</strong></span>
          <span>Net paid: <strong>{{ money(netPreview) }}</strong></span>
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
          <label>Statement Month <span class="hint">(optional — tags a monthly settlement)</span></label>
          <input type="month" v-model="form.period_month" class="month-input" />
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
.ledger-actions {
  display: flex;
  justify-content: flex-end;
  margin: 0.75rem 0;
}
.empty-hint {
  color: #64748b;
  padding: 1.5rem 0;
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
.field-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
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
.month-input {
  padding: 0.5rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
}
.preview {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  background: #f1f5f9;
  border-radius: 0.4rem;
  padding: 0.5rem 0.75rem;
  font-size: 0.85rem;
  color: #334155;
}
.req {
  color: #dc2626;
}
.hint {
  font-weight: 400;
  color: #94a3b8;
  font-size: 0.75rem;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
