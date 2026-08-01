<script setup lang="ts">
/**
 * Waiter-wise performance over a date range.
 *
 * Average bill leads after sales, because it is the number that separates a
 * waiter who turns tables from one who sells to them. Voids sit in the same row
 * rather than on their own screen: a high void count either explains a low total
 * or undermines a high one, and splitting them apart hides that.
 */
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const totals = ref({ orders: 0, covers: 0, sales: 0, voids: 0 })
const loading = ref(false)

const startOfMonth = new Date()
startOfMonth.setDate(1)

const from = ref<Date>(startOfMonth)
const to = ref<Date>(new Date())

function money(v: any) {
  return formatCurrency(v)
}

function ymd(d: Date) {
  // Local date parts, not toISOString(): a shift settled at 11pm IST would
  // otherwise be reported against the following UTC day.
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/waiter-performance', {
      params: { from: ymd(from.value), to: ymd(to.value) },
    })
    rows.value = data.data
    totals.value = data.meta.totals
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Waiter Performance')"
      :subtitle="pt('Settled tickets only — an open table has no final value to count')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Tickets') }}</span>
        <span class="t-value">{{ totals.orders }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Covers') }}</span>
        <span class="t-value">{{ totals.covers }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Sales') }}</span>
        <span class="t-value">{{ money(totals.sales) }}</span>
      </div>
      <div class="tile" :class="{ accent: totals.voids > 0 }">
        <span class="t-label">{{ pt('Voided dishes') }}</span>
        <span class="t-value">{{ totals.voids }}</span>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="from" date-format="yy-mm-dd" show-icon :placeholder="pt('From')" />
      <DatePicker v-model="to" date-format="yy-mm-dd" show-icon :placeholder="pt('To')" />
      <Button icon="pi pi-refresh" :label="pt('Run')" @click="load" />
      <TableExportButtons
        title="Waiter Performance"
        filenameBase="waiter-performance"
        :rows="rows"
        :columns="[
          { key: 'waiter', label: 'Waiter' },
          { key: 'orders', label: 'Tickets' },
          { key: 'covers', label: 'Covers' },
          { key: 'sales', label: 'Sales' },
          { key: 'avg_bill', label: 'Avg bill' },
          { key: 'avg_per_cover', label: 'Avg / cover' },
          { key: 'avg_minutes', label: 'Avg table time (min)' },
          { key: 'voids', label: 'Voids' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="waiter_id"
      striped-rows
      responsive-layout="scroll"
    >
      <Column field="waiter" :header="pt('Waiter')" sortable>
        <template #body="{ data }">
          <span :class="{ muted: !data.waiter_id }">{{ pt(data.waiter) }}</span>
        </template>
      </Column>
      <Column field="orders" :header="pt('Tickets')" sortable />
      <Column field="covers" :header="pt('Covers')" sortable />
      <Column field="sales" :header="pt('Sales')" sortable>
        <template #body="{ data }">{{ money(data.sales) }}</template>
      </Column>
      <Column field="avg_bill" :header="pt('Avg bill')" sortable>
        <template #body="{ data }">{{ money(data.avg_bill) }}</template>
      </Column>
      <Column field="avg_per_cover" :header="pt('Avg / cover')" sortable>
        <template #body="{ data }">
          <span v-if="data.avg_per_cover !== null">{{ money(data.avg_per_cover) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="avg_minutes" :header="pt('Avg table time')" sortable>
        <template #body="{ data }">{{ data.avg_minutes }} {{ pt('min') }}</template>
      </Column>
      <Column field="service_charge" :header="pt('Service charge')" sortable>
        <template #body="{ data }">{{ money(data.service_charge) }}</template>
      </Column>
      <Column field="voids" :header="pt('Voids')" sortable>
        <template #body="{ data }">
          <Tag
            v-if="data.voids > 0"
            :value="String(data.voids)"
            :severity="data.voids > data.orders * 0.1 ? 'danger' : 'warn'"
          />
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No tickets were settled in that range.') }}</div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.muted { color: #6b7280; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
