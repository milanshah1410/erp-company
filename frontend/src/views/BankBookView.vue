<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

const accounts = ref<{ label: string; value: number }[]>([])
const bankAccountId = ref<number | null>(null)
const from = ref<string>(monthStart())
const to = ref<string>(today())

function today() {
  return new Date().toISOString().slice(0, 10)
}
function monthStart() {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10)
}
function money(v: any) {
  return formatCurrency(v)
}

async function loadAccounts() {
  const { data } = await http.get('/bank-accounts', { params: { per_page: 100, type: 'bank', is_active: true } })
  accounts.value = (data.data ?? data).map((a: any) => ({ label: a.name, value: a.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/accounts/bank-book', {
      params: {
        from: from.value || undefined,
        to: to.value || undefined,
        bank_account_id: bankAccountId.value || undefined,
      },
    })
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadAccounts()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Bank Book</h1>
    </div>

    <div class="filters">
      <Select v-model="bankAccountId" :options="accounts" optionLabel="label" optionValue="value"
        placeholder="All bank accounts" showClear class="acc-select" @change="load" />
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
      <TableExportButtons title="Bank Book" :rows="entries"
        :columns="[
          { key: 'date', label: 'Date' },
          { key: 'source', label: 'Type' },
          { key: 'mode', label: 'Mode' },
          { key: 'reference', label: 'Reference' },
          { key: 'party', label: 'Party' },
          { key: 'debit', label: 'In' },
          { key: 'credit', label: 'Out' },
          { key: 'balance', label: 'Balance' },
        ]" />
    </div>

    <p v-if="!bankAccountId" class="note">
      Showing all bank-mode movements. Historical transactions aren't tied to a specific account —
      pick an account to narrow to its vouchers.
    </p>

    <div v-if="summary" class="tiles">
      <div class="tile">
        <span class="tile-label">Opening Balance</span>
        <span class="tile-value">{{ money(summary.opening_balance) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total In</span>
        <span class="tile-value in">{{ money(summary.total_in) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Out</span>
        <span class="tile-value out">{{ money(summary.total_out) }}</span>
      </div>
      <div class="tile closing">
        <span class="tile-label">Closing Balance</span>
        <span class="tile-value">{{ money(summary.closing_balance) }}</span>
      </div>
    </div>

    <DataTable :value="entries" :loading="loading" class="p-datatable-sm" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]">
      <Column field="date" header="Date" />
      <Column header="Type">
        <template #body="{ data }"><Tag :value="data.source" :severity="data.debit > 0 ? 'success' : 'info'" /></template>
      </Column>
      <Column field="mode" header="Mode"><template #body="{ data }">{{ data.mode || '—' }}</template></Column>
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column field="party" header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="In" style="text-align: right"><template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template></Column>
      <Column header="Out" style="text-align: right"><template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template></Column>
      <Column header="Balance" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template></Column>
      <template #empty>No bank movements in this period.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; gap: 1rem; margin-bottom: 0.75rem; align-items: center; flex-wrap: wrap; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.acc-select { min-width: 18rem; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.note { font-size: 0.8rem; color: #64748b; margin: 0 0 1rem; }
.tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
.tile.closing { background: #eff6ff; border-color: #bfdbfe; }
.tile-label { font-size: 0.8rem; color: #64748b; }
.tile-value { font-size: 1.25rem; font-weight: 600; color: #0f172a; }
.tile-value.in { color: #16a34a; }
.tile-value.out { color: #dc2626; }
</style>
