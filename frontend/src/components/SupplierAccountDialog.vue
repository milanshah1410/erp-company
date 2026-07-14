<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const props = defineProps<{
  supplier: any | null
}>()

const visible = defineModel<boolean>('visible', { required: true })
const toast = useToast()

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

type TabKey = 'ledger' | 'payments'
const tabs: { key: TabKey; label: string; icon: string }[] = [
  { key: 'ledger', label: 'Ledger', icon: 'pi pi-book' },
  { key: 'payments', label: 'Payments', icon: 'pi pi-wallet' },
]
const activeTab = ref<TabKey>('ledger')
const supId = computed(() => props.supplier?.id)

function toApiError(e: any, fallback: string) {
  toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || fallback, life: 4000 })
}

function ymd(d: Date | null): string | undefined {
  if (!d) return undefined
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

// ------------------------------------------------------------------ Ledger
const ledgerLoading = ref(false)
const ledgerRows = ref<any[]>([])
const ledgerSummary = ref<any>(null)
const ledgerFrom = ref<Date | null>(null)
const ledgerTo = ref<Date | null>(null)

async function loadLedger() {
  if (!supId.value) return
  ledgerLoading.value = true
  try {
    const params: Record<string, any> = {}
    if (ledgerFrom.value) params.from = ymd(ledgerFrom.value)
    if (ledgerTo.value) params.to = ymd(ledgerTo.value)
    const { data } = await http.get(`/suppliers/${supId.value}/ledger`, { params })
    ledgerRows.value = data.data ?? []
    ledgerSummary.value = data.summary ?? null
  } catch (e: any) {
    toApiError(e, 'Failed to load ledger')
  } finally {
    ledgerLoading.value = false
  }
}

const ledgerTypeColors: Record<string, string> = {
  opening: 'secondary',
  invoice: 'danger',
  payment: 'success',
}

// ---------------------------------------------------------------- Payments
const payLoading = ref(false)
const payRows = ref<any[]>([])
const paySummary = ref<any>(null)
const payTotal = ref(0)
const payPage = ref(1)
const payPerPage = ref(15)

async function loadPayments() {
  if (!supId.value) return
  payLoading.value = true
  try {
    const { data } = await http.get(`/suppliers/${supId.value}/payments`, {
      params: { page: payPage.value, per_page: payPerPage.value },
    })
    payRows.value = data.data ?? []
    payTotal.value = data.meta?.total ?? payRows.value.length
    paySummary.value = data.summary ?? null
  } catch (e: any) {
    toApiError(e, 'Failed to load payments')
  } finally {
    payLoading.value = false
  }
}

function onPayPage(e: any) {
  payPage.value = e.page + 1
  payPerPage.value = e.rows
  loadPayments()
}

const modeColors: Record<string, string> = {
  cash: 'success', bank: 'info', upi: 'warn', cheque: 'secondary', adjustment: 'contrast',
}

watch(activeTab, (t) => {
  if (t === 'ledger') loadLedger()
  else loadPayments()
})
watch(visible, (v) => {
  if (v) {
    activeTab.value = 'ledger'
    payPage.value = 1
    loadLedger()
  }
})
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Account — ${supplier?.name ?? ''}`"
    :style="{ width: '900px' }"
    :breakpoints="{ '960px': '95vw' }"
  >
    <div v-if="supplier?.gst_number" class="gstin-line">GSTIN: <strong>{{ supplier.gst_number }}</strong></div>

    <div class="tabstrip">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ active: activeTab === t.key }"
        @click="activeTab = t.key"
      >
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- LEDGER -->
    <section v-show="activeTab === 'ledger'">
      <div class="stat-grid">
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.opening_balance) }}</div>
          <div class="stat-label">Opening</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.total_debit) }}</div>
          <div class="stat-label">Total Billed</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.total_credit) }}</div>
          <div class="stat-label">Total Paid</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value" :class="Number(ledgerSummary?.closing_balance) > 0 ? 'due' : 'clear'">
            {{ money(ledgerSummary?.closing_balance) }}
          </div>
          <div class="stat-label">Outstanding Payable</div>
        </div>
      </div>

      <div class="filter-row">
        <DatePicker v-model="ledgerFrom" dateFormat="yy-mm-dd" placeholder="From" showIcon />
        <DatePicker v-model="ledgerTo" dateFormat="yy-mm-dd" placeholder="To" showIcon />
        <Button label="Apply" outlined size="small" @click="loadLedger" />
        <Button
          v-if="ledgerFrom || ledgerTo"
          label="Clear" text size="small"
          @click="ledgerFrom = null; ledgerTo = null; loadLedger()"
        />
      </div>

      <DataTable :value="ledgerRows" :loading="ledgerLoading" class="p-datatable-sm" scrollable scrollHeight="360px">
        <Column field="date" header="Date" style="width: 8rem" />
        <Column header="Type" style="width: 8rem">
          <template #body="{ data }">
            <Tag :severity="ledgerTypeColors[data.type]" :value="data.type.replace('_', ' ')" />
          </template>
        </Column>
        <Column field="reference" header="Reference" />
        <Column field="description" header="Description" />
        <Column header="Bill" style="text-align: right">
          <template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template>
        </Column>
        <Column header="Paid" style="text-align: right">
          <template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template>
        </Column>
        <Column header="Balance" style="text-align: right">
          <template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template>
        </Column>
        <template #empty>No ledger entries.</template>
      </DataTable>
    </section>

    <!-- PAYMENTS -->
    <section v-show="activeTab === 'payments'">
      <div class="stat-grid three">
        <div class="stat-tile">
          <div class="stat-value">{{ money(paySummary?.total_paid) }}</div>
          <div class="stat-label">Total Paid</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ paySummary?.payment_count ?? 0 }}</div>
          <div class="stat-label">Payments</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ paySummary?.last_payment_date || '—' }}</div>
          <div class="stat-label">Last Payment</div>
        </div>
      </div>

      <DataTable
        :value="payRows" :loading="payLoading" lazy paginator
        :rows="payPerPage" :totalRecords="payTotal" :rowsPerPageOptions="[15, 30, 50]"
        dataKey="id" class="p-datatable-sm" @page="onPayPage"
      >
        <Column field="payment_date" header="Date" style="width: 9rem" />
        <Column field="invoice_number" header="Invoice" />
        <Column header="Mode" style="width: 7rem">
          <template #body="{ data }">
            <Tag :severity="modeColors[data.mode]" :value="data.mode" />
          </template>
        </Column>
        <Column header="Amount" style="text-align: right">
          <template #body="{ data }">{{ money(data.amount) }}</template>
        </Column>
        <Column field="notes" header="Notes" />
        <template #empty>No payments recorded.</template>
      </DataTable>
    </section>

    <template #footer>
      <Button label="Close" text @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.gstin-line { font-size: 0.85rem; color: #374151; margin-bottom: 0.75rem; }
.tabstrip { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; flex-wrap: wrap; }
.tab {
  background: none; border: none; border-bottom: 2px solid transparent;
  padding: 0.6rem 0.9rem; font-size: 0.88rem; color: #6b7280; cursor: pointer;
  display: flex; align-items: center; gap: 0.4rem;
}
.tab:hover { color: #111827; }
.tab.active { color: var(--ink-blue, #2c4a5e); border-bottom-color: var(--safety-amber, #e8a33d); font-weight: 600; }
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; margin-bottom: 0.9rem; }
.stat-grid.three { grid-template-columns: repeat(3, 1fr); }
.stat-tile { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.7rem 0.85rem; }
.stat-value { font-size: 1rem; font-weight: 600; color: #111827; }
.stat-value.due { color: #dc2626; }
.stat-value.clear { color: #16a34a; }
.stat-label { color: #6b7280; font-size: 0.75rem; margin-top: 0.15rem; }
.filter-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; }
</style>
