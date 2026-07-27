<script setup lang="ts">
// Shipment Profit. Per shipment: revenue, the goods cost behind it, duty, and the
// logistics cost the company absorbed (billable costs are recovered from the
// counterparty, so they are shown separately and left out of margin).
//
// Only exports carry revenue, so an import shows a landed cost instead of a
// margin — and the footer keeps the two apart rather than reporting an import's
// landed cost as a loss.
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import http from '../api/http'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { humanize } from '../composables/useTradeCatalogue'

const rows = ref<any[]>([])
const meta = ref<any>({ total: 0, per_page: 25, current_page: 1 })
const totals = ref<any>(null)
const loading = ref(false)

const legFilter = ref<'' | 'import' | 'export'>('export')
const legChoices = [
  { label: 'Exports', value: 'export' },
  { label: 'Imports', value: 'import' },
  { label: 'Both', value: '' },
]
const search = ref('')
const dateFrom = ref('')
const dateTo = ref('')

function params(): Record<string, any> {
  return {
    per_page: meta.value.per_page,
    page: meta.value.current_page,
    ...(legFilter.value ? { direction: legFilter.value } : {}),
    ...(search.value ? { search: search.value } : {}),
    ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
    ...(dateTo.value ? { date_to: dateTo.value } : {}),
  }
}
async function reload() {
  loading.value = true
  try {
    const { data } = await http.get('/import-export/shipment-profit', { params: params() })
    rows.value = data.data
    meta.value = data.meta
    totals.value = data.totals
  } finally {
    loading.value = false
  }
}
function onPage(e: any) {
  meta.value.current_page = e.page + 1
  meta.value.per_page = e.rows
  reload()
}

function money(value: any): string {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 })
}

const showsExports = computed(() => legFilter.value !== 'import')

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(row: any) {
  detailId.value = row.id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'shipment_date', label: 'Date' },
  { key: 'party', label: 'Party' },
  { key: 'revenue', label: 'Revenue' },
  { key: 'goods_cost', label: 'Goods cost' },
  { key: 'duty', label: 'Duty' },
  { key: 'logistics_cost', label: 'Logistics (absorbed)' },
  { key: 'billable_cost', label: 'Logistics (billable)' },
  { key: 'total_cost', label: 'Total cost' },
  { key: 'profit', label: 'Profit' },
  { key: 'margin_percent', label: 'Margin %' },
  { key: 'landed_cost', label: 'Landed cost' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Shipment Profit" :count="meta.total" countLabel="shipments"
      subtitle="What each consignment earned or what it landed at — goods, duty and freight against the invoice.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Shipment number…" @keyup.enter="reload" />
          </span>
          <span class="dates">
            <label>From</label><input type="date" v-model="dateFrom" class="date-input" @change="reload" />
            <label>To</label><input type="date" v-model="dateTo" class="date-input" @change="reload" />
          </span>
          <TableExportButtons title="Shipment Profit" endpoint="/import-export/shipment-profit" :params="params"
            :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div class="toolbar">
      <SelectButton v-model="legFilter" :options="legChoices" optionLabel="label" optionValue="value" :allowEmpty="false"
        @change="reload" />
    </div>

    <div v-if="totals" class="summary-row">
      <div class="sum"><span class="sum-value">{{ money(totals.revenue) }}</span><span class="sum-label">Export revenue</span></div>
      <div class="sum"><span class="sum-value">{{ money(totals.goods_cost) }}</span><span class="sum-label">Goods cost</span></div>
      <div class="sum"><span class="sum-value">{{ money(totals.duty) }}</span><span class="sum-label">Duty</span></div>
      <div class="sum"><span class="sum-value">{{ money(totals.logistics_cost) }}</span><span class="sum-label">Logistics absorbed</span></div>
      <div class="sum" :class="totals.profit >= 0 ? 'good' : 'bad'">
        <span class="sum-value">{{ money(totals.profit) }}</span>
        <span class="sum-label">Export profit{{ totals.margin_percent !== null ? ` · ${totals.margin_percent}%` : '' }}</span>
      </div>
      <div class="sum"><span class="sum-value">{{ money(totals.import_landed_cost) }}</span><span class="sum-label">Import landed cost</span></div>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="meta.per_page" :totalRecords="meta.total"
      :rowsPerPageOptions="[25, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column field="shipment_date" header="Date" />
      <Column header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="Revenue" class="num">
        <template #body="{ data }">
          <span v-if="data.direction === 'export'">{{ money(data.revenue) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Goods cost" class="num"><template #body="{ data }">{{ money(data.goods_cost) }}</template></Column>
      <Column header="Duty" class="num"><template #body="{ data }">{{ money(data.duty) }}</template></Column>
      <Column header="Logistics" class="num">
        <template #body="{ data }">
          {{ money(data.logistics_cost) }}
          <br v-if="data.billable_cost > 0" />
          <small v-if="data.billable_cost > 0" class="muted">+{{ money(data.billable_cost) }} billable</small>
        </template>
      </Column>
      <Column header="Total cost" class="num"><template #body="{ data }"><strong>{{ money(data.total_cost) }}</strong></template></Column>
      <Column header="Profit / Landed" class="num">
        <template #body="{ data }">
          <span v-if="data.direction === 'export'" :class="data.profit >= 0 ? 'good' : 'bad'">
            <strong>{{ money(data.profit) }}</strong>
            <small v-if="data.margin_percent !== null"> ({{ data.margin_percent }}%)</small>
          </span>
          <span v-else>
            <strong>{{ money(data.landed_cost) }}</strong>
            <small class="muted"> landed</small>
          </span>
        </template>
      </Column>
      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openShipment(data)" />
        </template>
      </Column>

      <ColumnGroup type="footer" v-if="totals">
        <Row>
          <Column footer="Totals (whole filtered set)" :colspan="3" />
          <Column :footer="money(totals.revenue)" />
          <Column :footer="money(totals.goods_cost)" />
          <Column :footer="money(totals.duty)" />
          <Column :footer="money(totals.logistics_cost)" />
          <Column :footer="money(totals.total_cost)" />
          <Column :footer="showsExports ? money(totals.profit) : money(totals.import_landed_cost)" />
          <Column footer="" />
        </Row>
      </ColumnGroup>

      <template #empty>No shipments in this period.</template>
    </DataTable>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.dates { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
.date-input { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

.toolbar { margin-bottom: 0.9rem; }
.summary-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.sum { display: flex; flex-direction: column; border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.6rem 0.9rem; min-width: 140px; }
.sum-value { font-size: 1.2rem; font-weight: 700; line-height: 1.2; }
.sum-label { font-size: 0.72rem; color: #6b7280; }
.sum.good .sum-value { color: #16a34a; }
.sum.bad .sum-value { color: #dc2626; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.78rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }
:deep(.num) { text-align: right; }
</style>
