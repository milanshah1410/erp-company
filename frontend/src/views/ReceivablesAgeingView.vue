<script setup lang="ts">
/**
 * Outstanding & Ageing — receivables by how late they are, party by party.
 *
 * The buckets are *days past due*, not days since billing, because a distributor
 * selling on 30-day terms has most of the ledger legitimately unpaid at any
 * moment. Ageing from the invoice date would drop all of it into "0-30" and hide
 * the chemist who is three weeks late on a 7-day account.
 *
 * The filters are the point of the screen as much as the buckets are: a
 * collection round follows a beat, so salesman-, beat- and town-wise are the
 * readings someone actually plans work from. The as-of date makes it re-runnable
 * for a closed month rather than only for today.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import { useReportExport } from '../composables/useReportExport'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const totals = ref<any>({})
const loading = ref(false)

const asOf = ref<Date>(new Date())
const search = ref('')
const salesmanId = ref<number | null>(null)
const beatId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const city = ref<string | null>(null)
const overdueOnly = ref(false)
const minAmount = ref<number | null>(null)

const filterOptions = ref<any>({ categories: [], salesmen: [], beats: [], cities: [] })

const { exporting, exportAs } = useReportExport('/reports/receivables-ageing', 'outstanding-ageing')

const BUCKETS = [
  { key: 'not_due', label: 'Not Due' },
  { key: 'due_0_30', label: '1-30' },
  { key: 'due_31_60', label: '31-60' },
  { key: 'due_61_90', label: '61-90' },
  { key: 'due_90_plus', label: '90+' },
]

function money(v: any) {
  return v === null || v === undefined ? '—' : formatCurrency(v)
}

function ymd(d: Date) {
  // Local date parts, not toISOString(): an as-of chosen late in the evening
  // would otherwise be sent as the following UTC day.
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')

  return `${d.getFullYear()}-${m}-${day}`
}

function params() {
  return {
    as_of: ymd(asOf.value),
    q: search.value || undefined,
    salesman_id: salesmanId.value ?? undefined,
    beat_id: beatId.value ?? undefined,
    customer_category_id: categoryId.value ?? undefined,
    city: city.value ?? undefined,
    overdue_only: overdueOnly.value ? 1 : undefined,
    min_amount: minAmount.value ?? undefined,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/receivables-ageing', { params: params() })
    rows.value = data.data
    totals.value = data.meta
  } finally {
    loading.value = false
  }
}

async function loadFilters() {
  const { data } = await http.get('/credit-control/filters')
  filterOptions.value = {
    categories: data.data.categories.map((c: any) => ({ label: c.name, value: c.id })),
    salesmen: data.data.salesmen.map((s: any) => ({ label: s.name, value: s.id })),
    beats: data.data.beats.map((b: any) => ({ label: b.name, value: b.id })),
    cities: data.data.cities.map((c: string) => ({ label: c, value: c })),
  }
}

/** Server-side export, so the file is the whole filtered book and not the page. */
function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

function resetFilters() {
  search.value = ''
  salesmanId.value = null
  beatId.value = null
  categoryId.value = null
  city.value = null
  overdueOnly.value = false
  minAmount.value = null
  asOf.value = new Date()
  load()
}

/** Share of the total that is overdue — the one number an owner reads first. */
const overduePercent = computed(() => {
  const total = Number(totals.value.total_outstanding ?? 0)
  if (!total) return 0

  return Math.round((Number(totals.value.overdue_total ?? 0) / total) * 100)
})

/*
| Party drill-down: the invoices behind one row.
*/

const detail = ref<any>(null)
const detailLoading = ref(false)

async function openParty(row: any) {
  detail.value = { customer: { name: row.customer }, ageing: null }
  detailLoading.value = true
  try {
    const { data } = await http.get(`/reports/receivables-ageing/${row.customer_id}`, {
      params: { as_of: ymd(asOf.value) },
    })
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

onMounted(() => {
  load()
  loadFilters()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Outstanding & Ageing')"
      :subtitle="pt('Party-wise receivables by days past due, sliced by salesman, beat and town')"
      icon="pi pi-clock"
      :count="totals.parties ?? 0"
      :count-label="pt('parties')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Total outstanding') }}</span>
        <span class="t-value">{{ money(totals.total_outstanding) }}</span>
      </div>
      <div class="tile" :class="{ accent: (totals.overdue_total ?? 0) > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value">{{ money(totals.overdue_total) }}</span>
        <span class="t-hint">{{ pt('{n}% of the book', { n: overduePercent }) }}</span>
      </div>
      <div class="tile" :class="{ danger: (totals.due_90_plus ?? 0) > 0 }">
        <span class="t-label">{{ pt('Over 90 days') }}</span>
        <span class="t-value">{{ money(totals.due_90_plus) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Not yet due') }}</span>
        <span class="t-value">{{ money(totals.not_due) }}</span>
      </div>
      <div class="tile" :class="{ danger: (totals.over_limit_parties ?? 0) > 0 }">
        <span class="t-label">{{ pt('Over limit') }}</span>
        <span class="t-value">{{ totals.over_limit_parties ?? 0 }}</span>
      </div>
      <div class="tile" :class="{ danger: (totals.on_hold_parties ?? 0) > 0 }">
        <span class="t-label">{{ pt('Stopped') }}</span>
        <span class="t-value">{{ totals.on_hold_parties ?? 0 }}</span>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="asOf" date-format="yy-mm-dd" show-icon :placeholder="pt('As of')" @date-select="load" />
      <InputText v-model="search" :placeholder="pt('Party name, code or phone')" @keyup.enter="load" />
      <Select
        v-model="salesmanId"
        :options="filterOptions.salesmen"
        option-label="label"
        option-value="value"
        :placeholder="pt('Salesman')"
        show-clear
        filter
        @change="load"
      />
      <Select
        v-model="beatId"
        :options="filterOptions.beats"
        option-label="label"
        option-value="value"
        :placeholder="pt('Beat / route')"
        show-clear
        filter
        @change="load"
      />
      <Select
        v-model="categoryId"
        :options="filterOptions.categories"
        option-label="label"
        option-value="value"
        :placeholder="pt('Category')"
        show-clear
        filter
        @change="load"
      />
      <Select
        v-model="city"
        :options="filterOptions.cities"
        option-label="label"
        option-value="value"
        :placeholder="pt('Town')"
        show-clear
        filter
        @change="load"
      />
      <InputNumber v-model="minAmount" :min="0" :placeholder="pt('Min amount')" :input-style="{ width: '8rem' }" />
      <span class="toggle">
        <ToggleSwitch v-model="overdueOnly" @change="load" />
        <label>{{ pt('Overdue only') }}</label>
      </span>
      <Button icon="pi pi-refresh" :label="pt('Run')" @click="load" />
      <Button icon="pi pi-filter-slash" :label="pt('Clear')" text @click="resetFilters" />
      <span class="spacer" />
      <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="doExport('csv')" />
      <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="doExport('xlsx')" />
      <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="doExport('pdf')" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="customer_id"
      striped-rows
      responsive-layout="scroll"
      paginator
      :rows="50"
      :rows-per-page-options="[25, 50, 100, 200]"
      sort-field="total_outstanding"
      :sort-order="-1"
    >
      <Column field="customer" :header="pt('Party')" sortable frozen>
        <template #body="{ data }">
          <div class="party">
            <button class="link" @click="openParty(data)">{{ data.customer }}</button>
            <span class="sub">
              {{ data.code }}<template v-if="data.city"> · {{ data.city }}</template>
            </span>
            <span v-if="data.salesman" class="sub">
              <i class="pi pi-user" /> {{ data.salesman }}
            </span>
          </div>
        </template>
      </Column>

      <Column field="payment_terms_days" :header="pt('Terms')" sortable>
        <template #body="{ data }">{{ data.payment_terms_days }} {{ pt('d') }}</template>
      </Column>

      <Column
        v-for="b in BUCKETS"
        :key="b.key"
        :field="b.key"
        :header="pt(b.label)"
        sortable
      >
        <template #body="{ data }">
          <span v-if="data[b.key] > 0" :class="{ late: b.key !== 'not_due' }">{{ money(data[b.key]) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="opening_balance" :header="pt('Opening')" sortable>
        <template #body="{ data }">
          <span v-if="data.opening_balance">{{ money(data.opening_balance) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="total_outstanding" :header="pt('Total')" sortable>
        <template #body="{ data }">
          <strong>{{ money(data.total_outstanding) }}</strong>
        </template>
      </Column>

      <Column field="overdue_days" :header="pt('Days late')" sortable>
        <template #body="{ data }">
          <span v-if="data.overdue_days > 0" class="late">{{ data.overdue_days }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column :header="pt('Flags')">
        <template #body="{ data }">
          <Tag v-if="data.on_hold" :value="pt('Stopped')" severity="danger" />
          <Tag v-else-if="data.over_limit" :value="pt('Over limit')" severity="warn" />
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <ColumnGroup type="footer">
        <Row>
          <Column :footer="pt('Totals')" :colspan="2" />
          <Column v-for="b in BUCKETS" :key="b.key" :footer="money(totals[b.key])" />
          <Column :footer="money(totals.opening_balance)" />
          <Column :footer="money(totals.total_outstanding)" />
          <Column :colspan="2" />
        </Row>
      </ColumnGroup>

      <template #empty>
        <div class="empty-state">{{ pt('Nothing outstanding for these filters.') }}</div>
      </template>
    </DataTable>

    <p class="footnote">
      {{ pt('Buckets count days past the invoice due date. An opening balance carries no invoice and therefore no age, so it is shown separately and only added into the total.') }}
    </p>

    <Dialog
      :visible="!!detail"
      modal
      :header="detail?.customer?.name ?? ''"
      :style="{ width: '48rem' }"
      @update:visible="detail = null"
    >
      <div v-if="detailLoading" class="empty-state">{{ pt('Loading…') }}</div>
      <template v-else-if="detail?.ageing">
        <div class="party-meta">
          <span>{{ pt('Credit limit') }}: <strong>{{ money(detail.customer.credit_limit) }}</strong></span>
          <span>{{ pt('Terms') }}: <strong>{{ detail.customer.payment_terms_days }} {{ pt('days') }}</strong></span>
          <span v-if="detail.customer.phone">{{ pt('Phone') }}: <strong>{{ detail.customer.phone }}</strong></span>
          <span>{{ pt('As of') }}: <strong>{{ detail.ageing.as_of }}</strong></span>
        </div>

        <DataTable :value="detail.ageing.invoices" data-key="invoice_id" class="p-datatable-sm" paginator :rows="10">
          <Column field="invoice_number" :header="pt('Invoice')" />
          <Column field="invoice_date" :header="pt('Date')" />
          <Column field="due_date" :header="pt('Due')" />
          <Column field="days_past_due" :header="pt('Days late')">
            <template #body="{ data }">
              <span :class="{ late: data.days_past_due > 0 }">{{ data.days_past_due || '—' }}</span>
            </template>
          </Column>
          <Column field="total" :header="pt('Invoice value')">
            <template #body="{ data }">{{ money(data.total) }}</template>
          </Column>
          <Column field="balance" :header="pt('Balance')">
            <template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template>
          </Column>
          <template #empty>
            <div class="empty-state">{{ pt('No open invoices — the balance is a carried-forward opening figure.') }}</div>
          </template>
        </DataTable>
      </template>
      <template #footer>
        <Button :label="pt('Close')" text @click="detail = null" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.danger { border-color: #c62828; background: rgba(198, 40, 40, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.2rem; font-weight: 700; }
.t-hint { font-size: 0.68rem; color: #9ca3af; }

.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.toolbar .spacer { flex: 1; }
.toggle { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.82rem; }

.party { display: flex; flex-direction: column; gap: 0.1rem; }
.link { background: none; border: 0; padding: 0; font: inherit; color: var(--p-primary-color, #2563eb); cursor: pointer; text-align: left; }
.sub { font-size: 0.72rem; color: #6b7280; }
.late { color: #c62828; font-weight: 600; }
.muted { color: #9ca3af; }

.party-meta { display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.82rem; color: #4b5563; margin-bottom: 1rem; }
.footnote { font-size: 0.72rem; color: #9ca3af; margin-top: 0.75rem; max-width: 60rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
