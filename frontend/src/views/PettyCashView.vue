<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

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
    const { data } = await http.get('/accounts/petty-cash', {
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
      <h1>Petty Cash Book</h1>
    </div>

    <div class="filters">
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
    </div>

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
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column field="party" header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="In" style="text-align: right"><template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template></Column>
      <Column header="Out" style="text-align: right"><template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template></Column>
      <Column header="Balance" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template></Column>
      <template #empty>No petty cash movements in this period. Flag a cash account as "petty cash" and book vouchers against it.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.filters { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
.tile.closing { background: #eff6ff; border-color: #bfdbfe; }
.tile-label { font-size: 0.8rem; color: #64748b; }
.tile-value { font-size: 1.25rem; font-weight: 600; color: #0f172a; }
.tile-value.in { color: #16a34a; }
.tile-value.out { color: #dc2626; }
</style>
