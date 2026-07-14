<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import http from '../api/http'
import { useReportExport } from '../composables/useReportExport'
import { formatCurrency } from '../utils/currency'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const from = ref<string>(yearStart())
const to = ref<string>(today())

const { exporting, exportAs } = useReportExport('/accounts/profit-loss', 'profit-and-loss')

function today() {
  return new Date().toISOString().slice(0, 10)
}
function yearStart() {
  return new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10)
}
function money(v: any) {
  return v === null || v === undefined ? '' : formatCurrency(v)
}
function params() {
  return { date_from: from.value || undefined, date_to: to.value || undefined }
}
function isTotalRow(section: string) {
  return section === 'Gross Profit' || section === 'Net Profit'
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/accounts/profit-loss', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header"><h1>Profit &amp; Loss</h1></div>

    <div class="filters">
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
      <span class="spacer" />
      <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="doExport('csv')" />
      <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="doExport('xlsx')" />
      <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="doExport('pdf')" />
    </div>

    <div class="tiles">
      <div class="tile"><span class="tile-label">Revenue</span><span class="tile-value">{{ money(meta.revenue) }}</span></div>
      <div class="tile"><span class="tile-label">Gross Profit</span><span class="tile-value">{{ money(meta.gross_profit) }}</span></div>
      <div class="tile"><span class="tile-label">Total Expenses</span><span class="tile-value out">{{ money(meta.total_expenses) }}</span></div>
      <div class="tile net"><span class="tile-label">Net Profit</span><span class="tile-value" :class="(meta.net_profit ?? 0) < 0 ? 'out' : 'in'">{{ money(meta.net_profit) }}</span></div>
    </div>

    <DataTable :value="rows" :loading="loading" class="p-datatable-sm" :rowClass="(d) => isTotalRow(d.section) ? 'total-row' : ''">
      <Column field="section" header="Section" />
      <Column field="particulars" header="Particulars" />
      <Column header="Amount" style="text-align: right"><template #body="{ data }"><strong v-if="isTotalRow(data.section)">{{ money(data.amount) }}</strong><span v-else>{{ money(data.amount) }}</span></template></Column>
      <template #empty>No income or expense in this period.</template>
    </DataTable>
    <p class="note">Derived (indicative) statement: revenue and COGS from sales &amp; the stock ledger; expenses from approved bookings.</p>
  </div>
</template>

<style scoped>
.header h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; }
.filters { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.spacer { flex: 1; }
.tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
.tile.net { background: #eff6ff; border-color: #bfdbfe; }
.tile-label { font-size: 0.8rem; color: #64748b; }
.tile-value { font-size: 1.25rem; font-weight: 600; color: #0f172a; }
.tile-value.in { color: #16a34a; }
.tile-value.out { color: #dc2626; }
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
:deep(.total-row) { background: #f1f5f9; font-weight: 600; }
</style>
