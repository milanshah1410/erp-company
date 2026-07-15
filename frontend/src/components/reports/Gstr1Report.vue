<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport, downloadGovtJson } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/gstr1', 'gstr1')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}
function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}
function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/gstr1', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

const totalTaxable = computed(() => money(meta.value.total_taxable ?? 0))
const totalTax = computed(() => money(meta.value.total_tax ?? 0))

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

const downloadingJson = ref(false)
async function downloadJson() {
  downloadingJson.value = true
  try {
    await downloadGovtJson('/reports/gstr1/json', 'gstr1', params())
  } finally {
    downloadingJson.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" class="p-datatable-sm">
      <Column header="Supply">
        <template #body="{ data }"><Tag :value="data.supply_type" :severity="data.supply_type === 'B2B' ? 'info' : 'secondary'" /></template>
      </Column>
      <Column field="hsn_code" header="HSN Code" />
      <Column header="Tax %"><template #body="{ data }">{{ data.tax_percent }}%</template></Column>
      <Column header="Taxable Value" style="text-align: right"><template #body="{ data }">{{ money(data.taxable_value) }}</template></Column>
      <Column header="CGST" style="text-align: right"><template #body="{ data }">{{ money(data.cgst) }}</template></Column>
      <Column header="SGST" style="text-align: right"><template #body="{ data }">{{ money(data.sgst) }}</template></Column>
      <Column header="IGST" style="text-align: right"><template #body="{ data }">{{ money(data.igst) }}</template></Column>
      <Column header="Total Tax" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.tax_amount) }}</strong></template></Column>
      <template #empty>No outward supplies in the selected range.</template>
    </DataTable>
    <div class="json-row">
      <Button label="Download GSTR-1 JSON (GSTN)" icon="pi pi-download" size="small" outlined
        :loading="downloadingJson" @click="downloadJson" />
      <span class="note">Government offline-tool upload format (HSN summary).</span>
    </div>
    <p class="note">GSTR-1 outward supplies. Total taxable {{ totalTaxable }} · total tax {{ totalTax }}. CGST/SGST vs IGST inferred from customer state.</p>
  </div>
</template>

<style scoped>
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
.json-row { display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; }
.json-row .note { margin-top: 0; }
</style>
