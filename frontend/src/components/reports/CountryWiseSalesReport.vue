<script setup lang="ts">
// Country-wise Sales (SRS §24) — trade rolled up by country. Exports (the
// default) group on the destination country and show what was sold into it with
// its margin; imports group on the origin country and show what was bought from
// it, where a margin would be meaningless. GET /reports/country-wise-sales.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const direction = ref<'export' | 'import'>('export')
const meta = ref<any>({})

const directionOptions = [
  { label: 'Exports (sales)', value: 'export' },
  { label: 'Imports (purchases)', value: 'import' },
]

const { exporting, exportAs } = useReportExport('/reports/country-wise-sales', 'country-wise-sales')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    direction: direction.value,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/country-wise-sales', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar
      v-model:date-from="dateFrom"
      v-model:date-to="dateTo"
      :exporting="exporting"
      @refresh="load"
      @export="doExport"
    >
      <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="load" />
      <span class="totals">
        {{ meta.countries ?? 0 }} countries · {{ meta.shipment_count ?? 0 }} shipments ·
        {{ direction === 'export' ? 'Sales' : 'Purchases' }} {{ money(meta.invoice_value) }}
        <span v-if="direction === 'export'">
          · <span :class="(meta.profit ?? 0) < 0 ? 'loss' : 'gain'">Profit {{ money(meta.profit) }}</span>
        </span>
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="country_code" paginator :rows="25"
      :rowsPerPageOptions="[25, 50, 100]" scrollable class="p-datatable-sm">
      <Column field="country_code" header="Code" frozen />
      <Column field="country" header="Country" />
      <Column field="region" header="Region" />
      <Column field="shipment_count" header="Shipments" />
      <Column field="packages" header="Pkgs" />
      <Column field="gross_weight_kg" header="Gross Wt" />
      <Column field="cbm" header="CBM" />
      <Column :header="direction === 'export' ? 'Sales Value' : 'Purchase Value'">
        <template #body="{ data }"><strong>{{ money(data.invoice_value) }}</strong></template>
      </Column>
      <Column v-if="direction === 'export'" header="FOB Value">
        <template #body="{ data }">{{ money(data.fob_value) }}</template>
      </Column>
      <Column header="Goods Cost"><template #body="{ data }">{{ money(data.goods_cost) }}</template></Column>
      <Column header="Duty"><template #body="{ data }">{{ money(data.duty) }}</template></Column>
      <Column header="Logistics"><template #body="{ data }">{{ money(data.logistics_cost) }}</template></Column>
      <Column :header="direction === 'export' ? 'Total Cost' : 'Landed Cost'">
        <template #body="{ data }">{{ money(data.total_cost) }}</template>
      </Column>
      <Column v-if="direction === 'export'" header="Profit">
        <template #body="{ data }">
          <strong :class="data.profit < 0 ? 'loss' : 'gain'">{{ money(data.profit) }}</strong>
        </template>
      </Column>
      <Column v-if="direction === 'export'" header="Margin %">
        <template #body="{ data }">
          <span v-if="data.margin_percent !== null">{{ data.margin_percent }}%</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Settled"><template #body="{ data }">{{ money(data.amount_settled) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <template #empty>No trade recorded against any country for the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.totals { font-weight: 600; color: #0f172a; }
.gain { color: #15803d; }
.loss { color: #b91c1c; }
.muted { color: #94a3b8; }
</style>
