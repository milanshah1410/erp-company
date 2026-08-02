<script setup lang="ts">
/**
 * Supplier Credit — the khata, from the side that owes.
 *
 * Two tabs because the screen answers two questions. "Suppliers" is the standing
 * position: who is owed what, whose limit is nearly used up, whose bills are
 * late. "To Pay" is the instruction: the bills falling due inside the horizon,
 * oldest first, which is the list somebody actually works down.
 *
 * Nothing here blocks anything, and that is deliberate — a shop does not get to
 * stop its own distributor. The only thing this screen writes is the limit and
 * the credit period the distributor has granted, recorded while looking at what
 * is already owed against them.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()

const tab = ref('suppliers')

const rows = ref<any[]>([])
const meta = ref<any>({ summary: {}, default_terms_days: 30, statuses: [] })
const loading = ref(false)
const search = ref('')
const status = ref<string | null>(null)
const includeClear = ref(false)

const plan = ref<any[]>([])
const planMeta = ref<any>({ days: 7, total: 0, overdue_total: 0 })
const planDays = ref(7)
const planLoading = ref(false)

const detail = ref<any>(null)
const detailVisible = ref(false)
const detailLoading = ref(false)

const termsVisible = ref(false)
const termsSaving = ref(false)
const termsForm = ref({ supplier_id: 0, supplier: '', credit_limit: 0, payment_terms_days: 0 })

const STATUS_SEVERITY: Record<string, string> = {
  over_limit: 'danger',
  overdue: 'warn',
  watch: 'info',
  ok: 'success',
}

const STATUS_LABEL: Record<string, string> = {
  over_limit: 'Over limit',
  overdue: 'Overdue',
  watch: 'Near limit',
  ok: 'Clear',
}

const statusOptions = computed(() => [
  { label: pt('Any standing'), value: null },
  ...(meta.value.statuses ?? []).map((s: string) => ({ label: pt(STATUS_LABEL[s] ?? s), value: s })),
])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/supplier-credit', {
      params: {
        search: search.value || undefined,
        status: status.value || undefined,
        include_clear: includeClear.value ? 1 : undefined,
      },
    })
    rows.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

async function loadPlan() {
  planLoading.value = true
  try {
    const { data } = await http.get('/supplier-credit/payment-plan', {
      params: { days: planDays.value },
    })
    plan.value = data.data
    planMeta.value = data.meta
  } finally {
    planLoading.value = false
  }
}

async function openDetail(row: any) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await http.get(`/supplier-credit/${row.supplier_id}`)
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

function openTerms(row: any) {
  termsForm.value = {
    supplier_id: row.supplier_id,
    supplier: row.supplier,
    credit_limit: Number(row.credit_limit ?? 0),
    // Blank own terms are stored as 0, which means "follow the workspace
    // default" — shown as 0 rather than as the resolved number so saving does
    // not silently pin a supplier to today's default.
    payment_terms_days: row.has_own_terms ? Number(row.payment_terms_days) : 0,
  }
  termsVisible.value = true
}

async function saveTerms() {
  termsSaving.value = true
  try {
    await http.put(`/supplier-credit/${termsForm.value.supplier_id}/terms`, {
      credit_limit: termsForm.value.credit_limit,
      payment_terms_days: termsForm.value.payment_terms_days,
    })
    termsVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Credit terms saved'), life: 2500 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Could not save the terms'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    termsSaving.value = false
  }
}

/** "9 days late" / "in 4 days" / "today" — the phrasing a shopkeeper uses. */
function ageLabel(days: number): string {
  if (days > 0) return `${days} ${pt('days late')}`
  if (days === 0) return pt('due today')
  return `${pt('in')} ${Math.abs(days)} ${pt('days')}`
}

onMounted(() => {
  load()
  loadPlan()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Supplier Credit')"
      :subtitle="pt('What the shop owes each distributor, and what has to be paid today')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Outstanding') }}</span>
        <span class="t-value">{{ formatCurrency(meta.summary?.outstanding ?? 0) }}</span>
      </div>
      <div class="tile" :class="{ accent: (meta.summary?.overdue ?? 0) > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value">{{ formatCurrency(meta.summary?.overdue ?? 0) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('On the way (unbilled)') }}</span>
        <span class="t-value">{{ formatCurrency(meta.summary?.pipeline ?? 0) }}</span>
      </div>
      <div class="tile" :class="{ danger: (meta.summary?.over_limit ?? 0) > 0 }">
        <span class="t-label">{{ pt('Over limit') }}</span>
        <span class="t-value">{{ meta.summary?.over_limit ?? 0 }}</span>
      </div>
    </div>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="suppliers">{{ pt('Suppliers') }}</Tab>
        <Tab value="plan">{{ pt('To Pay') }}</Tab>
      </TabList>

      <TabPanels>
        <TabPanel value="suppliers">
          <div class="toolbar">
            <InputText v-model="search" :placeholder="pt('Search suppliers')" @keyup.enter="load" />
            <Select
              v-model="status"
              :options="statusOptions"
              option-label="label"
              option-value="value"
              class="w-14"
              @change="load"
            />
            <div class="field-inline">
              <Checkbox v-model="includeClear" binary input-id="include-clear" @change="load" />
              <label for="include-clear">{{ pt('Show suppliers with nothing owed') }}</label>
            </div>
            <Button icon="pi pi-search" text @click="load" />
            <TableExportButtons
              title="Supplier Credit"
              filenameBase="supplier-credit"
              :rows="rows"
              :columns="[
                { key: 'code', label: 'Code' },
                { key: 'supplier', label: 'Supplier' },
                { key: 'credit_limit', label: 'Limit' },
                { key: 'outstanding', label: 'Outstanding' },
                { key: 'pipeline', label: 'Unbilled' },
                { key: 'exposure', label: 'Exposure' },
                { key: 'available', label: 'Headroom' },
                { key: 'overdue', label: 'Overdue' },
                { key: 'overdue_days', label: 'Days late' },
                { key: 'next_due_date', label: 'Next due' },
              ]"
            />
          </div>

          <DataTable
            :value="rows"
            :loading="loading"
            data-key="supplier_id"
            striped-rows
            responsive-layout="scroll"
          >
            <Column field="supplier" :header="pt('Supplier')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <strong>{{ data.supplier }}</strong>
                  <small class="muted">
                    {{ data.code }}
                    <template v-if="data.phone"> · {{ data.phone }}</template>
                    · {{ data.payment_terms_days }} {{ pt('days') }}
                    <template v-if="!data.has_own_terms"> ({{ pt('default') }})</template>
                  </small>
                </div>
              </template>
            </Column>
            <Column field="outstanding" :header="pt('Outstanding')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.outstanding) }}</template>
            </Column>
            <Column field="pipeline" :header="pt('Unbilled')" sortable>
              <template #body="{ data }">
                <span :class="{ muted: !data.pipeline }">{{ formatCurrency(data.pipeline) }}</span>
              </template>
            </Column>
            <Column field="credit_limit" :header="pt('Limit')" sortable>
              <template #body="{ data }">
                <span v-if="data.credit_limit > 0">{{ formatCurrency(data.credit_limit) }}</span>
                <span v-else class="muted">{{ pt('not set') }}</span>
              </template>
            </Column>
            <Column field="available" :header="pt('Headroom')" sortable>
              <template #body="{ data }">
                <span v-if="data.available === null" class="muted">—</span>
                <span v-else :class="data.available < 0 ? 'bad' : 'good'">
                  {{ formatCurrency(data.available) }}
                </span>
              </template>
            </Column>
            <Column field="overdue" :header="pt('Overdue')" sortable>
              <template #body="{ data }">
                <span v-if="data.overdue > 0" class="bad">
                  {{ formatCurrency(data.overdue) }} · {{ data.overdue_days }} {{ pt('days') }}
                </span>
                <span v-else class="muted">—</span>
              </template>
            </Column>
            <Column field="next_due_date" :header="pt('Next due')" sortable>
              <template #body="{ data }">
                <div v-if="data.next_due_date" class="stack">
                  <span>{{ data.next_due_date }}</span>
                  <small class="muted">{{ formatCurrency(data.next_due_amount) }}</small>
                </div>
                <span v-else class="muted">—</span>
              </template>
            </Column>
            <Column field="status" :header="pt('Standing')" sortable>
              <template #body="{ data }">
                <Tag
                  :value="pt(STATUS_LABEL[data.status] ?? data.status)"
                  :severity="STATUS_SEVERITY[data.status] ?? 'secondary'"
                />
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button icon="pi pi-list" text size="small" v-tooltip.top="pt('Bills')" @click="openDetail(data)" />
                <Button icon="pi pi-pencil" text size="small" v-tooltip.top="pt('Credit terms')" @click="openTerms(data)" />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('Nothing owed to anybody.') }}</div>
            </template>
          </DataTable>
        </TabPanel>

        <TabPanel value="plan">
          <div class="toolbar">
            <label class="inline-label">{{ pt('Falling due within') }}</label>
            <InputNumber v-model="planDays" :min="0" :max="120" show-buttons class="w-8" @update:model-value="loadPlan" />
            <label class="inline-label">{{ pt('days') }}</label>
            <Button icon="pi pi-refresh" text @click="loadPlan" />
            <span class="plan-total">
              {{ pt('Total') }}: <strong>{{ formatCurrency(planMeta.total) }}</strong>
              <template v-if="planMeta.overdue_total > 0">
                · <span class="bad">{{ pt('already late') }}: {{ formatCurrency(planMeta.overdue_total) }}</span>
              </template>
            </span>
            <TableExportButtons
              title="Payments Due"
              filenameBase="payments-due"
              :rows="plan"
              :columns="[
                { key: 'due_date', label: 'Due' },
                { key: 'supplier', label: 'Supplier' },
                { key: 'invoice_number', label: 'Bill' },
                { key: 'balance', label: 'Amount' },
                { key: 'days_past_due', label: 'Days late' },
              ]"
            />
          </div>

          <DataTable
            :value="plan"
            :loading="planLoading"
            data-key="invoice_id"
            striped-rows
            responsive-layout="scroll"
          >
            <Column field="due_date" :header="pt('Due')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <span>{{ data.due_date }}</span>
                  <small :class="data.days_past_due > 0 ? 'bad' : 'muted'">
                    {{ ageLabel(data.days_past_due) }}
                  </small>
                </div>
              </template>
            </Column>
            <Column field="supplier" :header="pt('Supplier')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <strong>{{ data.supplier }}</strong>
                  <small class="muted">{{ data.code }}<template v-if="data.phone"> · {{ data.phone }}</template></small>
                </div>
              </template>
            </Column>
            <Column field="invoice_number" :header="pt('Bill')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <span>{{ data.invoice_number }}</span>
                  <small class="muted">{{ data.invoice_date }}</small>
                </div>
              </template>
            </Column>
            <Column field="balance" :header="pt('Amount')" sortable>
              <template #body="{ data }"><strong>{{ formatCurrency(data.balance) }}</strong></template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('Nothing falls due in this window.') }}</div>
            </template>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <!-- Bill-wise drill-down -->
    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? detail.snapshot.supplier : pt('Supplier')"
      :style="{ width: '54rem' }"
    >
      <div v-if="detailLoading" class="empty-state">{{ pt('Loading…') }}</div>
      <div v-else-if="detail" class="detail">
        <div v-if="detail.snapshot.reasons.length" class="reasons">
          <div v-for="(reason, i) in detail.snapshot.reasons" :key="i" class="reason">
            <i class="pi pi-exclamation-triangle" /> {{ reason }}
          </div>
        </div>

        <div class="buckets">
          <div class="bucket">
            <span class="t-label">{{ pt('Not Due') }}</span>
            <span class="t-value">{{ formatCurrency(detail.ageing.buckets.not_due) }}</span>
          </div>
          <div class="bucket">
            <span class="t-label">{{ pt('Overdue 1-30') }}</span>
            <span class="t-value">{{ formatCurrency(detail.ageing.buckets.due_0_30) }}</span>
          </div>
          <div class="bucket">
            <span class="t-label">{{ pt('Overdue 31-60') }}</span>
            <span class="t-value">{{ formatCurrency(detail.ageing.buckets.due_31_60) }}</span>
          </div>
          <div class="bucket">
            <span class="t-label">{{ pt('Overdue 61-90') }}</span>
            <span class="t-value">{{ formatCurrency(detail.ageing.buckets.due_61_90) }}</span>
          </div>
          <div class="bucket">
            <span class="t-label">{{ pt('Overdue 90+') }}</span>
            <span class="t-value">{{ formatCurrency(detail.ageing.buckets.due_90_plus) }}</span>
          </div>
        </div>

        <p v-if="detail.ageing.opening_balance" class="hint">
          {{ pt('Plus an opening balance of') }}
          <strong>{{ formatCurrency(detail.ageing.opening_balance) }}</strong>
          {{ pt('— carried outside the buckets because it has no bill behind it and therefore no age.') }}
        </p>

        <h4>{{ pt('Open bills') }}</h4>
        <DataTable :value="detail.ageing.invoices" data-key="invoice_id" striped-rows size="small">
          <Column field="invoice_number" :header="pt('Bill')" />
          <Column field="invoice_date" :header="pt('Date')" />
          <Column field="due_date" :header="pt('Due')" />
          <Column field="days_past_due" :header="pt('Age')">
            <template #body="{ data }">
              <span :class="data.days_past_due > 0 ? 'bad' : 'muted'">{{ ageLabel(data.days_past_due) }}</span>
            </template>
          </Column>
          <Column field="balance" :header="pt('Balance')">
            <template #body="{ data }">{{ formatCurrency(data.balance) }}</template>
          </Column>
          <template #empty>
            <div class="empty-state">{{ pt('No open bills.') }}</div>
          </template>
        </DataTable>

        <template v-if="detail.open_orders.length">
          <h4>{{ pt('Ordered, not yet billed') }}</h4>
          <DataTable :value="detail.open_orders" data-key="id" striped-rows size="small">
            <Column field="po_number" :header="pt('Order')" />
            <Column field="order_date" :header="pt('Date')" />
            <Column field="status" :header="pt('Status')" />
            <Column field="total" :header="pt('Value')">
              <template #body="{ data }">{{ formatCurrency(data.total) }}</template>
            </Column>
          </DataTable>
        </template>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
      </template>
    </Dialog>

    <!-- Credit terms -->
    <Dialog
      v-model:visible="termsVisible"
      modal
      :header="pt('Credit terms')"
      :style="{ width: '28rem' }"
    >
      <div class="form">
        <p class="hint">
          {{ pt('What this distributor allows') }} — <strong>{{ termsForm.supplier }}</strong>
        </p>
        <div class="field">
          <label>{{ pt('Credit limit') }}</label>
          <InputNumber v-model="termsForm.credit_limit" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt('0 = no limit recorded. It is not the same as no credit.') }}</small>
        </div>
        <div class="field">
          <label>{{ pt('Credit period (days)') }}</label>
          <InputNumber v-model="termsForm.payment_terms_days" :min="0" :max="365" show-buttons />
          <small class="hint">
            {{ pt('0 = follow the workspace default of') }} {{ meta.default_terms_days }} {{ pt('days') }}
          </small>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="termsVisible = false" />
        <Button :label="pt('Save')" :loading="termsSaving" @click="saveTerms" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.danger { border-color: #dc2626; background: rgba(220, 38, 38, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.15rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
.inline-label { font-size: 0.8rem; color: #6b7280; }
.plan-total { font-size: 0.85rem; margin-left: auto; }
.w-8 { width: 8rem; }
.w-14 { width: 14rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.good { color: #16a34a; }
.bad { color: #dc2626; font-weight: 600; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: #6b7280; }
.hint { color: #6b7280; font-size: 0.75rem; }
.err { color: #dc2626; font-size: 0.75rem; }
.detail { display: flex; flex-direction: column; gap: 0.9rem; }
.detail h4 { margin: 0.4rem 0 0; font-size: 0.9rem; }
.reasons { display: flex; flex-direction: column; gap: 0.35rem; }
.reason { background: rgba(220, 38, 38, 0.08); border: 1px solid rgba(220, 38, 38, 0.35); color: #b91c1c; border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.8rem; }
.buckets { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.5rem; }
.bucket { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.5rem 0.6rem; display: flex; flex-direction: column; gap: 0.15rem; }
.bucket .t-value { font-size: 0.95rem; }
</style>
