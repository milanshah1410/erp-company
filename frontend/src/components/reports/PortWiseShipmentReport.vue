<script setup lang="ts">
// Port-wise Shipment (SRS §24) — traffic per port. The discharge side (default)
// is the arrival view an importer watches; the loading side is the one an
// exporter ships from. GET /reports/port-wise-shipments.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'
import { humanize } from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const portSide = ref<'discharge' | 'loading'>('discharge')
const direction = ref<string | null>(null)
const meta = ref<any>({})

const sideOptions = [
  { label: 'Port of Discharge', value: 'discharge' },
  { label: 'Port of Loading', value: 'loading' },
]

const directionOptions = [
  { label: 'Both legs', value: null },
  { label: 'Import', value: 'import' },
  { label: 'Export', value: 'export' },
]

const { exporting, exportAs } = useReportExport('/reports/port-wise-shipments', 'port-wise-shipments')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    port_side: portSide.value,
    direction: direction.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/port-wise-shipments', { params: params() })
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
      <SelectButton v-model="portSide" :options="sideOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="load" />
      <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="load" />
      <span class="totals">
        {{ meta.ports ?? 0 }} ports · {{ meta.shipment_count ?? 0 }} shipments ·
        {{ meta.packages ?? 0 }} pkgs · Cargo {{ money(meta.invoice_value) }}
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="port_code" paginator :rows="25"
      :rowsPerPageOptions="[25, 50, 100]" scrollable class="p-datatable-sm">
      <Column field="port_code" header="Code" frozen />
      <Column field="port" header="Port" />
      <Column header="Type"><template #body="{ data }">{{ humanize(data.port_type) }}</template></Column>
      <Column field="country" header="Country" />
      <Column field="shipment_count" header="Shipments" />
      <Column field="import_count" header="Imports" />
      <Column field="export_count" header="Exports" />
      <Column field="packages" header="Pkgs" />
      <Column field="gross_weight_kg" header="Gross Wt" />
      <Column field="cbm" header="CBM" />
      <Column header="Cargo Value">
        <template #body="{ data }"><strong>{{ money(data.invoice_value) }}</strong></template>
      </Column>
      <Column header="Duty"><template #body="{ data }">{{ money(data.duty) }}</template></Column>
      <!-- Actuals only (ATD → ATA), so a lane's average is not skewed by
           shipments still at sea. -->
      <Column header="Avg Transit">
        <template #body="{ data }">
          <span v-if="data.avg_transit_days !== null">{{ data.avg_transit_days }}d</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <!-- Positive = arriving after ETA on this lane. -->
      <Column header="Avg ETA Var.">
        <template #body="{ data }">
          <span v-if="data.avg_eta_variance_days === null" class="muted">—</span>
          <span v-else :class="data.avg_eta_variance_days > 0 ? 'late' : 'early'">
            {{ data.avg_eta_variance_days > 0 ? `+${data.avg_eta_variance_days}` : data.avg_eta_variance_days }}d
          </span>
        </template>
      </Column>
      <template #empty>No port traffic recorded for the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.totals { font-weight: 600; color: #0f172a; }
.late { color: #b91c1c; font-weight: 600; }
.early { color: #15803d; }
.muted { color: #94a3b8; }
</style>
