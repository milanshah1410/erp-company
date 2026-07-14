<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const customers = ref<{ label: string; value: number }[]>([])
const customerId = ref<number | null>(null)
const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/customer-ledger', 'customer-ledger')

function money(v: any) {
  return formatCurrency(v)
}

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    customer_id: customerId.value ?? undefined,
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
  }
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 200 } })
  customers.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
}

async function load() {
  if (!customerId.value) {
    rows.value = []
    meta.value = {}
    return
  }
  loading.value = true
  try {
    const { data } = await http.get('/reports/customer-ledger', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  if (!customerId.value) return
  return exportAs(format, params())
}

onMounted(loadCustomers)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport">
      <Select v-model="customerId" :options="customers" optionLabel="label" optionValue="value"
        placeholder="Select a customer" showClear filter class="sel" @change="load" />
      <span v-if="meta.closing_balance !== undefined" class="grand-total">Balance Due: {{ money(meta.closing_balance) }}</span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="reference" class="p-datatable-sm" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]">
      <Column field="date" header="Date"><template #body="{ data }">{{ data.date || '—' }}</template></Column>
      <Column header="Type"><template #body="{ data }"><Tag :value="data.type" :severity="data.credit > 0 ? 'success' : 'info'" /></template></Column>
      <Column field="reference" header="Reference"><template #body="{ data }">{{ data.reference || '—' }}</template></Column>
      <Column header="Debit"><template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template></Column>
      <Column header="Credit"><template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template></Column>
      <Column header="Balance"><template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template></Column>
      <template #empty>{{ customerId ? 'No ledger entries in the selected range.' : 'Select a customer to view their ledger.' }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.sel { min-width: 18rem; }
.grand-total { font-weight: 600; color: #0f172a; }
</style>
