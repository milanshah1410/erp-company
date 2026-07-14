<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const rows = ref<any[]>([])
const total = ref(0)
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

const statusColors: Record<string, string> = { draft: 'secondary', pending: 'warn', approved: 'success', rejected: 'danger' }

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/expenses/ledger', {
      params: { date_from: from.value || undefined, date_to: to.value || undefined },
    })
    rows.value = data.data ?? []
    total.value = data.total ?? 0
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Expense Ledger</h1>
    </div>

    <div class="filters">
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]">
      <Column field="expense_date" header="Date" />
      <Column field="expense_number" header="Expense #" />
      <Column header="Category"><template #body="{ data }">{{ data.category }}</template></Column>
      <Column header="Branch"><template #body="{ data }">{{ data.branch || '—' }}</template></Column>
      <Column header="Payee"><template #body="{ data }">{{ data.payee || '—' }}</template></Column>
      <Column field="payment_method" header="Method" />
      <Column header="Status"><template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template></Column>
      <Column header="Amount" style="text-align: right"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
      <Column header="Running Total" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.running_total) }}</strong></template></Column>
      <template #empty>No expenses match the filters.</template>
      <template #footer><div class="rpt-total">Total: {{ money(total) }}</div></template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.rpt-total { text-align: right; font-weight: 600; font-size: 0.95rem; padding: 0.25rem 0.5rem; }
</style>
