<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const brokerId = ref<number | null>(null)
const status = ref<string | null>(null)
const meta = ref<any>({})
const brokers = ref<{ label: string; value: number }[]>([])

const statusOptions = [
  { label: 'All', value: null },
  { label: 'Pending', value: 'pending' },
  { label: 'Partial', value: 'partial' },
  { label: 'Paid', value: 'paid' },
]

const { exporting, exportAs } = useReportExport('/reports/broker-commissions', 'broker-commissions')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    broker_id: brokerId.value ?? undefined,
    status: status.value ?? undefined,
  }
}

async function loadBrokers() {
  const { data } = await http.get('/brokers', { params: { per_page: 100 } })
  brokers.value = (data.data ?? data).map((b: any) => ({ label: `${b.code} — ${b.name}`, value: b.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/broker-commissions', {
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

function severity(s: string) {
  return s === 'paid' ? 'success' : s === 'partial' ? 'warn' : 'danger'
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(() => {
  loadBrokers()
  load()
})
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
      <Select v-model="brokerId" :options="brokers" optionLabel="label" optionValue="value"
        placeholder="All brokers" showClear class="filter" @change="page = 1; load()" />
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value"
        placeholder="All statuses" class="filter" @change="page = 1; load()" />
      <span class="totals">
        Commission: {{ money(meta.total_commission) }} · Outstanding: {{ money(meta.total_outstanding) }}
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="invoice_number" @page="onPage" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column field="broker" header="Broker" />
      <Column field="invoice_number" header="Invoice" />
      <Column header="Type"><template #body="{ data }"><Tag :value="data.type" :severity="data.type === 'reversal' ? 'warn' : 'info'" /></template></Column>
      <Column header="Base Value"><template #body="{ data }">{{ money(data.base_amount) }}</template></Column>
      <Column field="commission_percent" header="Rate %" />
      <Column header="Commission"><template #body="{ data }">{{ money(data.commission_amount) }}</template></Column>
      <Column header="Settled"><template #body="{ data }">{{ money(data.settled_amount) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="severity(data.status)" /></template></Column>
      <template #empty>No commission records for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 12rem; }
.totals { font-weight: 600; color: #0f172a; }
</style>
