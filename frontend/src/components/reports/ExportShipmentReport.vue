<script setup lang="ts">
// Export Report (SRS §24) — the sell-side register: every export consignment over
// the range with its lane, the shipping bill it moved under and the incentives
// claimed on it. GET /reports/export-shipments.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'
import { humanize, paymentStatusSeverity, shipmentStatusSeverity } from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const status = ref<string | null>(null)
const paymentStatus = ref<string | null>(null)
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

const paymentOptions = [
  { label: 'All payments', value: null },
  { label: 'Unpaid', value: 'unpaid' },
  { label: 'Partial', value: 'partial' },
  { label: 'Paid', value: 'paid' },
]

const modeOptions = [
  { label: 'All modes', value: null },
  { label: 'Sea', value: 'sea' },
  { label: 'Air', value: 'air' },
  { label: 'Road', value: 'road' },
  { label: 'Rail', value: 'rail' },
  { label: 'Courier', value: 'courier' },
]

const { exporting, exportAs } = useReportExport('/reports/export-shipments', 'export-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    status: status.value ?? undefined,
    payment_status: paymentStatus.value ?? undefined,
    mode: mode.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/export-shipments', {
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
      <Select v-model="paymentStatus" :options="paymentOptions" optionLabel="label" optionValue="value"
        placeholder="All payments" class="filter" @change="page = 1; load()" />
      <Select v-model="mode" :options="modeOptions" optionLabel="label" optionValue="value"
        placeholder="All modes" class="filter" @change="page = 1; load()" />
      <span class="totals">
        {{ meta.shipments ?? 0 }} shipments · Value {{ money(meta.invoice_value) }} · Outstanding
        {{ money(meta.outstanding) }}
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
      <Column field="customer" header="Customer" />
      <Column field="so_number" header="SO" />
      <Column field="destination_country" header="Destination" />
      <Column field="port_of_loading" header="POL" />
      <Column field="port_of_discharge" header="POD" />
      <Column field="final_place" header="Final Place" />
      <Column header="Mode"><template #body="{ data }">{{ humanize(data.mode) }}</template></Column>
      <Column field="incoterm" header="Incoterm" />
      <Column field="carrier" header="Carrier" />
      <Column field="bl_awb_number" header="B/L — AWB" />
      <Column field="shipping_bill_number" header="Shipping Bill" />
      <Column field="leo_date" header="LEO" />
      <Column field="etd" header="ETD" />
      <Column field="atd" header="ATD" />
      <Column field="packages" header="Pkgs" />
      <Column field="gross_weight_kg" header="Gross Wt" />
      <Column header="Invoice Value">
        <template #body="{ data }">{{ data.currency }} {{ Number(data.invoice_value).toFixed(2) }}</template>
      </Column>
      <Column header="Value (base)">
        <template #body="{ data }"><strong>{{ money(data.invoice_value_base) }}</strong></template>
      </Column>
      <Column header="FOB"><template #body="{ data }">{{ money(data.fob_value_base) }}</template></Column>
      <Column header="Drawback"><template #body="{ data }">{{ money(data.drawback_amount) }}</template></Column>
      <Column header="RoDTEP"><template #body="{ data }">{{ money(data.rodtep_amount) }}</template></Column>
      <Column header="Payment">
        <template #body="{ data }">
          <Tag :value="humanize(data.payment_status)" :severity="paymentStatusSeverity[data.payment_status]" />
        </template>
      </Column>
      <Column header="Realised"><template #body="{ data }">{{ money(data.amount_settled) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <template #empty>No export shipments for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 11rem; }
.totals { font-weight: 600; color: #0f172a; }
</style>
