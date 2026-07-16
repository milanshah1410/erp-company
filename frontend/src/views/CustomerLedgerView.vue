<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const customers = ref<{ label: string; value: number }[]>([])
const customerId = ref<number | null>(null)
const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

function money(v: any) {
  return formatCurrency(v)
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 100 } })
  customers.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
}

async function loadLedger() {
  if (!customerId.value) {
    entries.value = []
    summary.value = null
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/customers/${customerId.value}/ledger`)
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(loadCustomers)
</script>

<template>
  <div>
    <div class="header">
      <h1>Customer Ledger</h1>
    </div>

    <div class="toolbar">
      <Select v-model="customerId" :options="customers" optionLabel="label" optionValue="value"
        placeholder="Select a customer" showClear filter class="sel" @change="loadLedger" />
      <TableExportButtons title="Customer Ledger" :rows="entries"
        :columns="[
          { key: 'date', label: 'Date' },
          { key: 'type', label: 'Type' },
          { key: 'reference', label: 'Reference' },
          { key: 'debit', label: 'Debit' },
          { key: 'credit', label: 'Credit' },
          { key: 'balance', label: 'Balance' },
        ]" />
    </div>

    <div v-if="summary" class="tiles">
      <div class="tile">
        <span class="tile-label">Opening Balance</span>
        <span class="tile-value">{{ money(summary.opening_balance) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Invoiced</span>
        <span class="tile-value">{{ money(summary.total_debit) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Received</span>
        <span class="tile-value">{{ money(summary.total_credit) }}</span>
      </div>
      <div class="tile pending">
        <span class="tile-label">Balance Due</span>
        <span class="tile-value">{{ money(summary.outstanding_balance ?? summary.closing_balance) }}</span>
      </div>
    </div>

    <DataTable :value="entries" :loading="loading" dataKey="reference" class="p-datatable-sm" paginator :rows="25">
      <Column field="date" header="Date" />
      <Column header="Type"><template #body="{ data }"><Tag :value="data.type" :severity="data.credit > 0 ? 'success' : 'info'" /></template></Column>
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column header="Debit"><template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template></Column>
      <Column header="Credit"><template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template></Column>
      <Column header="Balance"><template #body="{ data }">{{ money(data.balance) }}</template></Column>
      <template #empty>{{ customerId ? 'No ledger entries yet.' : 'Select a customer to view their ledger.' }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { margin-bottom: 1rem; }
.sel { min-width: 22rem; }
.tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
.tile.pending { background: #fff7ed; border-color: #fed7aa; }
.tile-label { font-size: 0.8rem; color: #64748b; }
.tile-value { font-size: 1.25rem; font-weight: 600; color: #0f172a; }
</style>
