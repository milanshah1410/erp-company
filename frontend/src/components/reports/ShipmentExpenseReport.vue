<script setup lang="ts">
// Shipment Expense (SRS §24) — the logistics cost register: every charge booked
// against a shipment, who billed it, and whether it is recovered from the
// counterparty or absorbed into the cargo. GET /reports/shipment-expenses.
//
// The date range reads the expense date here, not the shipment's, so a month's
// freight bills line up with the month they were raised in.
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
import { humanize, useTradeCatalogue } from '../../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const direction = ref<string | null>(null)
const expenseType = ref<string | null>(null)
const recovery = ref<string | null>(null)
const meta = ref<any>({})

const { catalogue, load: loadCatalogue } = useTradeCatalogue()

const directionOptions = [
  { label: 'Both legs', value: null },
  { label: 'Import', value: 'import' },
  { label: 'Export', value: 'export' },
]

// Absorbed cost loads the cargo; billable cost is recovered, so the two answer
// different questions and are worth filtering apart.
const recoveryOptions = [
  { label: 'All', value: null },
  { label: 'Absorbed', value: '0' },
  { label: 'Billable', value: '1' },
]

const { exporting, exportAs } = useReportExport('/reports/shipment-expenses', 'shipment-expenses')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    direction: direction.value ?? undefined,
    expense_type: expenseType.value ?? undefined,
    is_billable: recovery.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/shipment-expenses', {
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

onMounted(() => {
  loadCatalogue()
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
      <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="page = 1; load()" />
      <Select v-model="expenseType" :options="catalogue.expense_types" optionLabel="label" optionValue="value"
        placeholder="All cost heads" showClear class="filter" @change="page = 1; load()" />
      <SelectButton v-model="recovery" :options="recoveryOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="page = 1; load()" />
      <span class="totals">
        {{ meta.lines ?? 0 }} lines · Total {{ money(meta.total_cost) }} · Absorbed
        {{ money(meta.absorbed_cost) }} · Outstanding {{ money(meta.outstanding) }}
      </span>
    </ReportToolbar>

    <!-- What the cost heads add up to across the whole filtered set, so the desk
         can see where a lane's money actually goes without exporting first. -->
    <div v-if="meta.by_head?.length" class="by-head">
      <span v-for="head in meta.by_head" :key="head.expense_type" class="chip">
        {{ head.label }} <strong>{{ money(head.amount) }}</strong>
      </span>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" @page="onPage" scrollable class="p-datatable-sm">
      <Column field="expense_date" header="Date" />
      <Column field="shipment_number" header="Shipment #" />
      <Column header="Leg">
        <template #body="{ data }">
          <Tag :value="humanize(data.direction)" :severity="data.direction === 'export' ? 'success' : 'info'" />
        </template>
      </Column>
      <Column field="container_number" header="Container" />
      <Column field="expense_type" header="Cost Head" />
      <Column field="vendor_type" header="Vendor Type" />
      <Column field="vendor_name" header="Vendor" />
      <Column field="invoice_number" header="Bill #" />
      <Column header="Amount">
        <template #body="{ data }">{{ data.currency }} {{ Number(data.amount).toFixed(2) }}</template>
      </Column>
      <Column header="Amount (base)">
        <template #body="{ data }"><strong>{{ money(data.base_amount) }}</strong></template>
      </Column>
      <Column header="Recovery">
        <template #body="{ data }">
          <Tag :value="data.is_billable" :severity="data.is_billable === 'Billable' ? 'info' : 'secondary'" />
        </template>
      </Column>
      <Column header="Payment">
        <template #body="{ data }">
          <Tag :value="humanize(data.payment_status)"
            :severity="data.payment_status === 'paid' ? 'success' : data.payment_status === 'partial' ? 'warn' : 'danger'" />
        </template>
      </Column>
      <Column header="Paid"><template #body="{ data }">{{ money(data.paid_amount) }}</template></Column>
      <Column header="Outstanding"><template #body="{ data }">{{ money(data.outstanding) }}</template></Column>
      <Column field="due_date" header="Due" />
      <template #empty>No logistics costs for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 12rem; }
.totals { font-weight: 600; color: #0f172a; }
.by-head {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.chip {
  background: var(--p-surface-100, #f1f5f9);
  border-radius: 999px;
  padding: 0.25rem 0.75rem;
  font-size: 0.85rem;
}
</style>
