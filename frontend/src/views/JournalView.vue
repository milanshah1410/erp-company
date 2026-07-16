<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

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

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/accounts/journal', {
      params: { from: from.value || undefined, to: to.value || undefined },
    })
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Journal</h1>
    </div>

    <div class="filters">
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
      <TableExportButtons title="Journal" :rows="entries"
        :columns="[
          { key: 'date', label: 'Date' },
          { key: 'reference', label: 'Reference' },
          { key: 'type', label: 'Type' },
          { key: 'debit_ledger', label: 'Debit (Dr)' },
          { key: 'credit_ledger', label: 'Credit (Cr)' },
          { key: 'narration', label: 'Narration' },
          { key: 'amount', label: 'Amount' },
        ]" />
    </div>

    <p class="note">Non-cash entries: journal vouchers plus adjustment-mode transactions.</p>

    <DataTable :value="entries" :loading="loading" class="p-datatable-sm" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]">
      <Column field="date" header="Date" />
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column field="type" header="Type" />
      <Column header="Debit (Dr)"><template #body="{ data }">{{ data.debit_ledger || '—' }}</template></Column>
      <Column header="Credit (Cr)"><template #body="{ data }">{{ data.credit_ledger || '—' }}</template></Column>
      <Column field="narration" header="Narration"><template #body="{ data }">{{ data.narration || '—' }}</template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template></Column>
      <template #empty>No journal entries in this period.</template>
      <template v-if="summary" #footer>
        <div class="rpt-total">Total: {{ money(summary.total) }} · {{ summary.count }} entries</div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; gap: 1rem; margin-bottom: 0.75rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.note { font-size: 0.8rem; color: #64748b; margin: 0 0 1rem; }
.rpt-total { text-align: right; font-weight: 600; font-size: 0.95rem; padding: 0.25rem 0.5rem; }
</style>
