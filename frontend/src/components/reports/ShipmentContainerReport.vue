<script setup lang="ts">
// Container Report (SRS §24) — every box moved over the range, with its cargo,
// its position in the physical chain, damage, and the detention/demurrage
// exposure the free-days window creates. GET /reports/shipment-containers.
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import ToggleSwitch from 'primevue/toggleswitch'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { containerStatusSeverity, humanize } from '../../composables/useTradeCatalogue'
import { useReportExport } from '../../composables/useReportExport'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const direction = ref<string | null>(null)
const status = ref<string | null>(null)
const detentionOnly = ref(false)
const meta = ref<any>({})

const directionOptions = [
  { label: 'Both legs', value: null },
  { label: 'Import', value: 'import' },
  { label: 'Export', value: 'export' },
]

// The physical chain, in the order a box walks it (ShipmentContainer::STATUSES).
const statusOptions = [
  { label: 'All statuses', value: null },
  { label: 'Planned', value: 'planned' },
  { label: 'Stuffed', value: 'stuffed' },
  { label: 'Gated In', value: 'gated_in' },
  { label: 'Loaded', value: 'loaded' },
  { label: 'In Transit', value: 'in_transit' },
  { label: 'Discharged', value: 'discharged' },
  { label: 'Gated Out', value: 'gated_out' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Empty Returned', value: 'empty_returned' },
]

const { exporting, exportAs } = useReportExport('/reports/shipment-containers', 'container-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    direction: direction.value ?? undefined,
    status: status.value ?? undefined,
    detention: detentionOnly.value ? 1 : undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/shipment-containers', {
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
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value"
        placeholder="All statuses" class="filter" @change="page = 1; load()" />
      <label class="toggle">
        <ToggleSwitch v-model="detentionOnly" @change="page = 1; load()" />
        <span>Past free days</span>
      </label>
      <span class="totals">
        {{ meta.containers ?? 0 }} boxes · {{ meta.returned ?? 0 }} returned ·
        <span v-if="meta.accruing_detention" class="alert">{{ meta.accruing_detention }} accruing detention</span>
        <span v-else>none accruing</span>
        <span v-if="meta.damaged"> · {{ meta.damaged }} damaged</span>
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="container_number" @page="onPage"
      scrollable class="p-datatable-sm">
      <Column field="container_number" header="Container #" frozen />
      <Column field="container_type" header="Type" />
      <Column field="seal_number" header="Seal #" />
      <Column field="shipment_number" header="Shipment #" />
      <Column header="Leg">
        <template #body="{ data }">
          <Tag :value="humanize(data.direction)" :severity="data.direction === 'export' ? 'success' : 'info'" />
        </template>
      </Column>
      <Column field="party" header="Party" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :value="humanize(data.status)" :severity="containerStatusSeverity[data.status]" />
        </template>
      </Column>
      <Column field="current_location" header="Location" />
      <Column field="status_updated_at" header="Updated" />
      <Column field="packages" header="Pkgs" />
      <Column field="gross_weight_kg" header="Gross Wt" />
      <Column field="cbm" header="CBM" />
      <Column field="free_days" header="Free Days" />
      <Column field="detention_free_until" header="Free Until" />
      <!-- Days past the carrier's free-days window, so the desk can act before
           the detention invoice arrives. -->
      <Column header="Detention">
        <template #body="{ data }">
          <strong v-if="data.detention_days > 0" class="alert">{{ data.detention_days }}d</strong>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="empty_returned_on" header="Empty Returned" />
      <Column header="Damaged">
        <template #body="{ data }">
          <Tag v-if="data.is_damaged === 'Yes'" value="Damaged" severity="danger" />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="damage_remarks" header="Damage Remarks" />
      <template #empty>No containers for the selected filters.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.filter { min-width: 11rem; }
.totals { font-weight: 600; color: #0f172a; }
.toggle { display: flex; align-items: center; gap: 0.4rem; }
.alert { color: #b91c1c; }
.muted { color: #94a3b8; }
</style>
