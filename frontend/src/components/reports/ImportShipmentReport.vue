<script setup lang="ts">
// Import Report (SRS §24) — the buy-side register: every import consignment over
// the range with its lane, carrier, cargo and what it cost to land. GET
// /reports/import-shipments; see App\Http\Controllers\Api\ShipmentReportController.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'
import {
  customsStatusSeverity,
  humanize,
  paymentStatusSeverity,
  shipmentStatusSeverity,
} from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const status = ref<string | null>(null)
const customsStatus = ref<string | null>(null)
const mode = ref<string | null>(null)
const meta = ref<any>({})

const statusOptions = [
  { label: 'All statuses', value: null },
  { label: 'Draft', value: 'draft' },
  { label: 'Booked', value: 'booked' },
  { label: 'In Transit', value: 'in_transit' },
  { label: 'Arrived', value: 'arrived' },
  { label: 'Customs', value: 'customs' },
  { label: 'Cleared', value: 'cleared' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Closed', value: 'closed' },
  { label: 'Cancelled', value: 'cancelled' },
]

const customsOptions = [
  { label: 'All customs', value: null },
  { label: 'Pending', value: 'pending' },
  { label: 'Filed', value: 'filed' },
  { label: 'Assessed', value: 'assessed' },
  { label: 'Duty Paid', value: 'duty_paid' },
  { label: 'Cleared', value: 'cleared' },
  { label: 'On Hold', value: 'on_hold' },
]

const modeOptions = [
  { label: 'All modes', value: null },
  { label: 'Sea', value: 'sea' },
  { label: 'Air', value: 'air' },
  { label: 'Road', value: 'road' },
  { label: 'Rail', value: 'rail' },
  { label: 'Courier', value: 'courier' },
]

const { exporting, exportAs } = useReportExport('/reports/import-shipments', 'import-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    status: status.value ?? undefined,
    customs_status: customsStatus.value ?? undefined,
    mode: mode.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/import-shipments', {
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
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value"
        placeholder="All statuses" class="filter" @change="page = 1; load()" />
      <Select v-model="customsStatus" :options="customsOptions" optionLabel="label" optionValue="value"
        placeholder="All customs" class="filter" @change="page = 1; load()" />
      <Select v-model="mode" :options="modeOptions" optionLabel="label" optionValue="value"
        placeholder="All modes" class="filter" @change="page = 1; load()" />
      <span class="totals">
        {{ meta.shipments ?? 0 }} shipments · Goods {{ money(meta.invoice_value) }} · Duty
        {{ money(meta.duty) }} · Landed {{ money(meta.landed_cost) }}
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="shipment_number" @page="onPage"
      scrollable class="p-datatable-sm">
      <Column field="shipment_number" header="Shipment #" frozen />
      <Column field="shipment_date" header="Date" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :value="humanize(data.status)" :severity="shipmentStatusSeverity[data.status]" />
        </template>
      </Column>
      <Column field="supplier" header="Supplier" />
      <Column field="po_number" header="PO" />
      <Column field="origin_country" header="Origin" />
      <Column field="port_of_loading" header="POL" />
      <Column field="port_of_discharge" header="POD" />
      <Column header="Mode"><template #body="{ data }">{{ humanize(data.mode) }}</template></Column>
      <Column field="incoterm" header="Incoterm" />
      <Column field="carrier" header="Carrier" />
      <Column field="bl_awb_number" header="B/L — AWB" />
      <Column field="eta" header="ETA" />
      <Column field="ata" header="ATA" />
      <!-- Positive = arrived after ETA, which is the number the desk chases. -->
      <Column header="ETA Var.">
        <template #body="{ data }">
          <span v-if="data.eta_variance_days === null">—</span>
          <span v-else :class="data.eta_variance_days > 0 ? 'late' : 'early'">
            {{ data.eta_variance_days > 0 ? `+${data.eta_variance_days}` : data.eta_variance_days }}d
          </span>
        </template>
      </Column>
      <Column field="packages" header="Pkgs" />
      <Column field="gross_weight_kg" header="Gross Wt" />
      <Column header="Invoice Value">
        <template #body="{ data }">{{ data.currency }} {{ Number(data.invoice_value).toFixed(2) }}</template>
      </Column>
      <Column header="Goods Value"><template #body="{ data }">{{ money(data.invoice_value_base) }}</template></Column>
      <Column header="Freight + Ins."><template #body="{ data }">{{ money(data.freight_insurance) }}</template></Column>
      <Column header="Duty"><template #body="{ data }">{{ money(data.duty) }}</template></Column>
      <Column header="Other"><template #body="{ data }">{{ money(data.other_cost) }}</template></Column>
      <Column header="Landed Cost">
        <template #body="{ data }"><strong>{{ money(data.landed_cost) }}</strong></template>
      </Column>
      <Column header="Customs">
        <template #body="{ data }">
          <Tag :value="humanize(data.customs_status)" :severity="customsStatusSeverity[data.customs_status]" />
        </template>
      </Column>
      <Column header="Payment">
        <template #body="{ data }">
          <Tag :value="humanize(data.payment_status)" :severity="paymentStatusSeverity[data.payment_status]" />
        </template>
      </Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <template #empty>No import shipments for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 11rem; }
.totals { font-weight: 600; color: #0f172a; }
.late { color: #b91c1c; font-weight: 600; }
.early { color: #15803d; }
</style>
