<script setup lang="ts">
// Shipment Profit (SRS §24) — per-consignment P&L. Exports carry revenue and so
// a margin; imports read as a landed-cost breakdown, because no sale happens on
// that leg. GET /reports/shipment-profit.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'
import { humanize, shipmentStatusSeverity } from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const direction = ref<string | null>(null)
const meta = ref<any>({})

const directionOptions = [
  { label: 'Both legs', value: null },
  { label: 'Import', value: 'import' },
  { label: 'Export', value: 'export' },
]

const { exporting, exportAs } = useReportExport('/reports/shipment-profit', 'shipment-profit')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    direction: direction.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/shipment-profit', {
      params: { ...params(), page: page.value, per_page: perPage.value },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
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
      @refresh="page = 1; load()"
      @export="doExport"
    >
      <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="page = 1; load()" />
      <span class="totals">
        Revenue {{ money(meta.revenue) }} · Cost {{ money(meta.total_cost) }} ·
        <span :class="(meta.profit ?? 0) < 0 ? 'loss' : 'gain'">Profit {{ money(meta.profit) }}</span>
        <span v-if="meta.margin_percent !== null && meta.margin_percent !== undefined">
          ({{ meta.margin_percent }}%)</span>
      </span>
    </ReportToolbar>

    <!-- Only the export leg books revenue, so the import rows show a landed cost
         where an export row shows a margin. -->
    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="shipment_number" @page="onPage"
      scrollable class="p-datatable-sm">
      <Column field="shipment_number" header="Shipment #" frozen />
      <Column field="shipment_date" header="Date" />
      <Column header="Leg">
        <template #body="{ data }">
          <Tag :value="humanize(data.direction)" :severity="data.direction === 'export' ? 'success' : 'info'" />
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :value="humanize(data.status)" :severity="shipmentStatusSeverity[data.status]" />
        </template>
      </Column>
      <Column field="party" header="Party" />
      <Column header="Invoice Value">
        <template #body="{ data }">{{ data.currency }} {{ Number(data.invoice_value).toFixed(2) }}</template>
      </Column>
      <Column header="Revenue"><template #body="{ data }">{{ money(data.revenue) }}</template></Column>
      <Column header="Goods Cost"><template #body="{ data }">{{ money(data.goods_cost) }}</template></Column>
      <Column header="Duty"><template #body="{ data }">{{ money(data.duty) }}</template></Column>
      <Column header="Logistics"><template #body="{ data }">{{ money(data.logistics_cost) }}</template></Column>
      <Column header="Total Cost"><template #body="{ data }">{{ money(data.total_cost) }}</template></Column>
      <!-- Costs recovered from the counterparty, so they never load the cargo. -->
      <Column header="Recoverable"><template #body="{ data }">{{ money(data.billable_cost) }}</template></Column>
      <Column header="Profit">
        <template #body="{ data }">
          <strong v-if="data.profit !== null" :class="data.profit < 0 ? 'loss' : 'gain'">
            {{ money(data.profit) }}
          </strong>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Margin %">
        <template #body="{ data }">
          <span v-if="data.margin_percent !== null">{{ data.margin_percent }}%</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Landed Cost">
        <template #body="{ data }">
          <strong v-if="data.landed_cost !== null">{{ money(data.landed_cost) }}</strong>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <template #empty>No shipments with reportable figures for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.totals { font-weight: 600; color: #0f172a; }
.gain { color: #15803d; }
.loss { color: #b91c1c; }
.muted { color: #94a3b8; }
</style>
