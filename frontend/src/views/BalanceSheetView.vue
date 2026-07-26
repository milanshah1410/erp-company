<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import http from '../api/http'
import { useReportExport } from '../composables/useReportExport'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const to = ref<string>(today())

const { exporting, exportAs } = useReportExport('/accounts/balance-sheet', 'balance-sheet')

function today() {
  return new Date().toISOString().slice(0, 10)
}
function money(v: any) {
  return formatCurrency(v)
}
function params() {
  return { date_to: to.value || undefined }
}

const assets = computed(() => rows.value.filter((r) => r.side === 'Assets'))
const liabilities = computed(() => rows.value.filter((r) => r.side === 'Liabilities & Equity'))

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/accounts/balance-sheet', { params: params() })
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
    <PageHeader title="Balance Sheet" />

    <div class="filters">
      <label>As of <input type="date" v-model="to" class="date-input" @change="load" /></label>
      <span class="spacer" />
      <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="doExport('csv')" />
      <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="doExport('xlsx')" />
      <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="doExport('pdf')" />
    </div>

    <div class="columns">
      <div class="col">
        <h2>Assets</h2>
        <DataTable :value="assets" :loading="loading" class="p-datatable-sm">
          <Column field="group" header="Group" />
          <Column field="particulars" header="Particulars" />
          <Column header="Amount" style="text-align: right"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
        </DataTable>
        <div class="total-line"><span>Total Assets</span><strong>{{ money(meta.total_assets) }}</strong></div>
      </div>

      <div class="col">
        <h2>Liabilities &amp; Equity</h2>
        <DataTable :value="liabilities" :loading="loading" class="p-datatable-sm">
          <Column field="group" header="Group" />
          <Column field="particulars" header="Particulars" />
          <Column header="Amount" style="text-align: right"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
        </DataTable>
        <div class="total-line"><span>Total Liabilities &amp; Equity</span><strong>{{ money(meta.total_liabilities_equity) }}</strong></div>
      </div>
    </div>
    <p class="note">Derived (indicative) statement. The Capital Account line is a balancing figure so both sides agree.</p>
  </div>
</template>

<style scoped>
.header h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; }
.filters { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.spacer { flex: 1; }
.columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; }
.col h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: 0.5rem; }
.total-line { display: flex; justify-content: space-between; padding: 0.75rem 1rem; margin-top: 0.5rem; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.5rem; font-size: 0.95rem; }
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
@media (max-width: 900px) { .columns { grid-template-columns: 1fr; } }
</style>
