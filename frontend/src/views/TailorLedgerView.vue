<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const tailors = ref<{ label: string; value: number }[]>([])
const tailorId = ref<number | null>(null)
const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

function money(v: any) {
  return formatCurrency(v)
}

async function loadTailors() {
  const { data } = await http.get('/tailors', { params: { per_page: 100, is_active: true } })
  tailors.value = (data.data ?? data).map((t: any) => ({ label: `${t.code} — ${t.name}`, value: t.id }))
}

async function loadLedger() {
  if (!tailorId.value) {
    entries.value = []
    summary.value = null
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/tailors/${tailorId.value}/ledger`)
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(loadTailors)
</script>

<template>
  <div>
    <div class="header">
      <h1>Tailor Ledger</h1>
    </div>

    <div class="toolbar">
      <Select v-model="tailorId" :options="tailors" optionLabel="label" optionValue="value"
        placeholder="Select a tailor" showClear filter class="tailor-select" @change="loadLedger" />
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
        <span class="tile-label">Total Paid</span>
        <span class="tile-value">{{ money(summary.total_paid) }}</span>
      </div>
      <div class="tile pending">
        <span class="tile-label">Balance Due</span>
        <span class="tile-value">{{ money(summary.outstanding_balance) }}</span>
      </div>
    </div>

    <DataTable :value="entries" :loading="loading" dataKey="reference" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column header="Type">
        <template #body="{ data }">
          <Tag :value="data.type" :severity="data.credit > 0 ? 'success' : 'info'" />
        </template>
      </Column>
      <Column field="reference" header="Reference" />
      <Column header="Earned (Credit)">
        <template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template>
      </Column>
      <Column header="Paid (Debit)">
        <template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template>
      </Column>
      <Column header="Balance">
        <template #body="{ data }">{{ money(data.balance) }}</template>
      </Column>
      <template #empty>{{ tailorId ? 'No ledger entries yet.' : 'Select a tailor to view their ledger.' }}</template>
    </DataTable>
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
.tailor-select {
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
</style>
