<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const suppliers = ref<{ label: string; value: number }[]>([])
const supplierId = ref<number | null>(null)
const entries = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)

function money(v: any) {
  return formatCurrency(v)
}

async function loadSuppliers() {
  const { data } = await http.get('/suppliers', { params: { per_page: 100 } })
  suppliers.value = (data.data ?? data).map((s: any) => ({ label: s.name, value: s.id }))
}

async function loadLedger() {
  if (!supplierId.value) {
    entries.value = []
    summary.value = null
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/suppliers/${supplierId.value}/ledger`)
    entries.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(loadSuppliers)
</script>

<template>
  <div>
    <PageHeader title="Supplier Ledger" />

    <div class="toolbar">
      <Select v-model="supplierId" :options="suppliers" optionLabel="label" optionValue="value"
        placeholder="Select a supplier" showClear filter class="sel" @change="loadLedger" />
      <TableExportButtons title="Supplier Ledger" :rows="entries"
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
        <span class="tile-label">Total Paid</span>
        <span class="tile-value">{{ money(summary.total_debit) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Total Billed</span>
        <span class="tile-value">{{ money(summary.total_credit) }}</span>
      </div>
      <div class="tile pending">
        <span class="tile-label">Balance Payable</span>
        <span class="tile-value">{{ money(summary.outstanding_balance ?? summary.closing_balance) }}</span>
      </div>
    </div>

    <DataTable :value="entries" :loading="loading" dataKey="reference" class="p-datatable-sm" paginator :rows="25">
      <Column field="date" header="Date" />
      <Column header="Type"><template #body="{ data }"><Tag :value="data.type" :severity="data.debit > 0 ? 'success' : 'info'" /></template></Column>
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column header="Debit"><template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template></Column>
      <Column header="Credit"><template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template></Column>
      <Column header="Balance"><template #body="{ data }">{{ money(data.balance) }}</template></Column>
      <template #empty>{{ supplierId ? 'No ledger entries yet.' : 'Select a supplier to view their ledger.' }}</template>
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
