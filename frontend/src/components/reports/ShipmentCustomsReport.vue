<script setup lang="ts">
// Customs Report (SRS §24) — the customs register: the entry filed for each
// consignment, what it was assessed on, the duty heads that came out of it and
// how long clearance took. GET /reports/shipment-customs.
//
// The date range reads the customs entry date here, not the shipment's, so a
// month's duty ties back to the entries filed in that month.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'
import { customsStatusSeverity, humanize } from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const direction = ref<string | null>(null)
const customsStatus = ref<string | null>(null)
const filed = ref<string | null>(null)
const meta = ref<any>({})

const directionOptions = [
  { label: 'Both legs', value: null },
  { label: 'Import', value: 'import' },
  { label: 'Export', value: 'export' },
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

// "Not filed" is the chase list: consignments with no entry number against them.
const filedOptions = [
  { label: 'All', value: null },
  { label: 'Filed', value: '1' },
  { label: 'Not filed', value: '0' },
]

const { exporting, exportAs } = useReportExport('/reports/shipment-customs', 'customs-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    direction: direction.value ?? undefined,
    customs_status: customsStatus.value ?? undefined,
    filed: filed.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/shipment-customs', {
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
      <Select v-model="customsStatus" :options="customsOptions" optionLabel="label" optionValue="value"
        placeholder="All customs" class="filter" @change="page = 1; load()" />
      <SelectButton v-model="filed" :options="filedOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="page = 1; load()" />
      <span class="totals">
        {{ meta.filed ?? 0 }}/{{ meta.entries ?? 0 }} filed · Duty {{ money(meta.duty_total) }} ·
        Avg clearance
        {{ meta.avg_clearance_days !== null && meta.avg_clearance_days !== undefined
          ? `${meta.avg_clearance_days}d` : '—' }}
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="shipment_number" @page="onPage"
      scrollable class="p-datatable-sm">
      <Column field="shipment_number" header="Shipment #" frozen />
      <Column header="Leg">
        <template #body="{ data }">
          <Tag :value="humanize(data.direction)" :severity="data.direction === 'export' ? 'success' : 'info'" />
        </template>
      </Column>
      <Column field="party" header="Party" />
      <Column header="Entry Type"><template #body="{ data }">{{ humanize(data.entry_type) }}</template></Column>
      <Column field="entry_number" header="Entry #" />
      <Column field="entry_date" header="Entry Date" />
      <Column field="icegate_reference" header="ICEGATE Ref" />
      <Column field="port_of_discharge" header="Port" />
      <Column field="cha_agent" header="CHA" />
      <Column header="Assessable Value">
        <template #body="{ data }">{{ money(data.assessable_value) }}</template>
      </Column>
      <Column header="BCD"><template #body="{ data }">{{ money(data.duty_bcd) }}</template></Column>
      <Column header="IGST"><template #body="{ data }">{{ money(data.duty_igst) }}</template></Column>
      <Column header="Cess"><template #body="{ data }">{{ money(data.duty_cess) }}</template></Column>
      <Column header="Other"><template #body="{ data }">{{ money(data.duty_other) }}</template></Column>
      <Column header="Total Duty">
        <template #body="{ data }"><strong>{{ money(data.duty_total) }}</strong></template>
      </Column>
      <Column header="Drawback"><template #body="{ data }">{{ money(data.drawback_amount) }}</template></Column>
      <Column header="RoDTEP"><template #body="{ data }">{{ money(data.rodtep_amount) }}</template></Column>
      <Column field="duty_paid_at" header="Duty Paid" />
      <Column field="cleared_at" header="Cleared" />
      <Column header="Clearance">
        <template #body="{ data }">
          <span v-if="data.clearance_days !== null">{{ data.clearance_days }}d</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :value="humanize(data.customs_status)" :severity="customsStatusSeverity[data.customs_status]" />
        </template>
      </Column>
      <template #empty>No customs entries for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 11rem; }
.totals { font-weight: 600; color: #0f172a; }
.muted { color: #94a3b8; }
</style>
