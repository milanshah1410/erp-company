<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/gst-summary', 'gst-summary')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}
function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}
function money(v: any) {
  return v === null || v === undefined ? '—' : formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/gst-summary', { params: params() })
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
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport" />

    <div class="tiles">
      <div class="tile">
        <span class="tile-label">Output Tax (Sales)</span>
        <span class="tile-value out">{{ money(meta.output_tax) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Input Tax Credit (Purchases)</span>
        <span class="tile-value in">{{ money(meta.input_tax) }}</span>
      </div>
      <div class="tile net">
        <span class="tile-label">{{ (meta.net_payable ?? 0) >= 0 ? 'Net GST Payable' : 'Net ITC Carried Forward' }}</span>
        <span class="tile-value">{{ money(Math.abs(meta.net_payable ?? 0)) }}</span>
      </div>
    </div>

    <DataTable :value="rows" :loading="loading" class="p-datatable-sm">
      <Column field="particulars" header="Particulars" />
      <Column header="Taxable Value" style="text-align: right"><template #body="{ data }">{{ money(data.taxable_value) }}</template></Column>
      <Column header="Tax Amount" style="text-align: right"><template #body="{ data }">{{ money(data.tax_amount) }}</template></Column>
      <template #empty>No GST transactions in the selected range.</template>
    </DataTable>
    <p class="note">GSTR-3B style summary, derived from sales &amp; purchase invoice lines.</p>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
.tile.net { background: #eff6ff; border-color: #bfdbfe; }
.tile-label { font-size: 0.8rem; color: #64748b; }
.tile-value { font-size: 1.25rem; font-weight: 600; color: #0f172a; }
.tile-value.in { color: #16a34a; }
.tile-value.out { color: #dc2626; }
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
</style>
