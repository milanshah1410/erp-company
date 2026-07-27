<script setup lang="ts">
// ETA / ETD board: the sailing schedule with the slippage against it. The leg
// toggle switches between watching departures (has it actually sailed?) and
// arrivals (is it going to land when it said it would?), which are two different
// conversations with two different people.
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { useCrud } from '../composables/useCrud'
import { humanize, statusOptions, shipmentStatusSeverity } from '../composables/useTradeCatalogue'

const crud = useCrud('/import-export/eta-etd')

const leg = ref<'arrival' | 'departure'>('arrival')
const legChoices = [
  { label: 'Arrivals (ETA)', value: 'arrival' },
  { label: 'Departures (ETD)', value: 'departure' },
]
const directionFilter = ref<string | null>(null)
const directionChoices = statusOptions(['import', 'export'])
const modeFilter = ref<string | null>(null)
const modeChoices = statusOptions(['sea', 'air', 'road', 'rail', 'courier'])
const lateOnly = ref(false)
const search = ref('')
const dateFrom = ref('')
const dateTo = ref('')

function params(): Record<string, any> {
  return {
    leg: leg.value,
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(modeFilter.value ? { mode: modeFilter.value } : {}),
    ...(lateOnly.value ? { late_only: 1 } : {}),
    ...(search.value ? { search: search.value } : {}),
    ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
    ...(dateTo.value ? { date_to: dateTo.value } : {}),
  }
}
function reload() {
  crud.fetch(params())
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

const isArrival = computed(() => leg.value === 'arrival')

// Slippage is only meaningful once the estimate exists; a shipment that has
// already landed is judged on its actual, one still sailing on today.
function variance(row: any): { text: string; tone: string } {
  const v = row.eta_variance_days
  const settled = isArrival.value ? row.ata : row.atd
  if (v === null || v === undefined) return { text: '—', tone: 'muted' }
  if (v > 0) return { text: `${v} days late`, tone: 'bad' }
  if (v === 0) return { text: settled ? 'on time' : 'due today', tone: 'good' }
  return { text: `${-v} days to go`, tone: 'muted' }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openDetail(row: any) {
  detailId.value = row.id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'party', label: 'Party' },
  { key: 'mode', label: 'Mode' },
  { key: 'carrier', label: 'Carrier' },
  { key: 'vessel_name', label: 'Vessel / Flight' },
  { key: 'port_of_loading', label: 'POL' },
  { key: 'port_of_discharge', label: 'POD' },
  { key: 'etd', label: 'ETD' },
  { key: 'atd', label: 'ATD' },
  { key: 'eta', label: 'ETA' },
  { key: 'ata', label: 'ATA' },
  { key: 'status', label: 'Status' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="ETA / ETD" :count="crud.total.value" countLabel="shipments"
      subtitle="Estimated against actual departure and arrival, with the slippage on each lane.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Number, vessel, B/L…" @keyup.enter="reload" />
          </span>
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value"
            showClear placeholder="Both legs" class="filter-sm" @change="reload" />
          <Select v-model="modeFilter" :options="modeChoices" optionLabel="label" optionValue="value"
            showClear placeholder="Any mode" class="filter-sm" @change="reload" />
          <TableExportButtons title="ETA ETD" endpoint="/import-export/eta-etd" :params="params" :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div class="toolbar">
      <SelectButton v-model="leg" :options="legChoices" optionLabel="label" optionValue="value" :allowEmpty="false"
        @change="reload" />
      <label class="switch">
        <ToggleSwitch v-model="lateOnly" @change="reload" />
        <span>Late only</span>
      </label>
      <span class="dates">
        <label>From</label><input type="date" v-model="dateFrom" class="date-input" @change="reload" />
        <label>To</label><input type="date" v-model="dateTo" class="date-input" @change="reload" />
      </span>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[25, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="shipment_number" header="Shipment #" />
      <Column header="Leg">
        <template #body="{ data }">
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="Carrier">
        <template #body="{ data }">
          {{ data.carrier || '—' }}
          <small v-if="data.vessel_name" class="muted">· {{ data.vessel_name }}</small>
        </template>
      </Column>
      <Column header="Lane">
        <template #body="{ data }">{{ data.port_of_loading || '—' }} → {{ data.port_of_discharge || '—' }}</template>
      </Column>
      <Column :header="isArrival ? 'ETA' : 'ETD'">
        <template #body="{ data }">{{ (isArrival ? data.eta : data.etd) || '—' }}</template>
      </Column>
      <Column :header="isArrival ? 'ATA' : 'ATD'">
        <template #body="{ data }">{{ (isArrival ? data.ata : data.atd) || '—' }}</template>
      </Column>
      <Column header="Slippage">
        <template #body="{ data }">
          <span :class="variance(data).tone">{{ variance(data).text }}</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="shipmentStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
        </template>
      </Column>
      <template #empty>Nothing scheduled on this leg.</template>
    </DataTable>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 150px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.toolbar { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1rem; flex-wrap: wrap; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
.dates { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
.date-input { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

.muted { color: #9ca3af; font-size: 0.8rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; font-weight: 600; }
.mini { font-size: 0.62rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }
</style>
