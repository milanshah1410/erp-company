<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import Button from 'primevue/button'
import http from '../api/http'
import { useReportExport } from '../composables/useReportExport'
import { formatCurrency } from '../utils/currency'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const from = ref<string>('')
const to = ref<string>(today())

const { exporting, exportAs } = useReportExport('/accounts/trial-balance', 'trial-balance')

function today() {
  return new Date().toISOString().slice(0, 10)
}
function money(v: any) {
  return v ? formatCurrency(v) : '—'
}
function params() {
  return { date_from: from.value || undefined, date_to: to.value || undefined }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/accounts/trial-balance', { params: params() })
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
    <div class="header"><h1>Trial Balance</h1></div>

    <div class="filters">
      <label>From <input type="date" v-model="from" class="date-input" @change="load" /></label>
      <label>To <input type="date" v-model="to" class="date-input" @change="load" /></label>
      <span class="spacer" />
      <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="doExport('csv')" />
      <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="doExport('xlsx')" />
      <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="doExport('pdf')" />
    </div>

    <DataTable :value="rows" :loading="loading" class="p-datatable-sm">
      <Column field="account" header="Account" />
      <Column header="Debit" style="text-align: right"><template #body="{ data }">{{ money(data.debit) }}</template></Column>
      <Column header="Credit" style="text-align: right"><template #body="{ data }">{{ money(data.credit) }}</template></Column>
      <template #empty>No balances for this period.</template>
      <ColumnGroup type="footer">
        <Row>
          <Column footer="Total" footerStyle="text-align:right; font-weight:600" />
          <Column :footer="money(meta.total_debit)" footerStyle="text-align:right; font-weight:600" />
          <Column :footer="money(meta.total_credit)" footerStyle="text-align:right; font-weight:600" />
        </Row>
      </ColumnGroup>
    </DataTable>
    <p class="note">Derived (indicative) trial balance. A suspense line absorbs any difference so debit and credit totals agree.</p>
  </div>
</template>

<style scoped>
.header h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; }
.filters { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; }
.filters label { font-size: 0.85rem; color: #374151; display: flex; gap: 0.4rem; align-items: center; }
.date-input { padding: 0.5rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.spacer { flex: 1; }
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
</style>
