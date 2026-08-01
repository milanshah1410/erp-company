<script setup lang="ts">
/**
 * Credit Control — the party book a distributor runs the morning on.
 *
 * Built around one question: does this party get goods today? So the table leads
 * with what is at stake (exposure against limit, and how late they are) and the
 * two acts that answer it — hold and release — are one click in the row, not a
 * form. Editing a limit is a dialog, because that is a decision someone should
 * have to stop and type.
 *
 * The status chip is the summary of all of it, and it comes from the server: the
 * same rule refuses the despatch, so a green row here cannot mean a blocked lorry
 * there.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import ProgressBar from 'primevue/progressbar'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canEdit = computed(() => auth.can('credit-control.edit'))

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const sort = ref<'exposure' | 'overdue' | 'available' | 'name' | 'oldest'>('exposure')
const dir = ref<'asc' | 'desc'>('desc')

const summary = ref<any>({
  parties: 0, outstanding: 0, overdue: 0, pipeline: 0,
  on_hold: 0, over_limit: 0, age_blocked: 0,
})
const policy = ref<any>({ mode: 'block', overdue_block_days: 0, include_pipeline: false, default_terms_days: 30 })

// Filters
const search = ref('')
const status = ref<string | null>(null)
const salesmanId = ref<number | null>(null)
const beatId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const city = ref<string | null>(null)
const includeClear = ref(false)

const filterOptions = ref<any>({ categories: [], salesmen: [], beats: [], cities: [] })

/**
 * Every standing a party can be in, worst first. The labels say what to *do*
 * about it rather than naming the arithmetic — "Stopped" is what a despatch clerk
 * needs to read, not "hold".
 */
const STATUSES = [
  { value: 'hold', label: 'Stopped', severity: 'danger' },
  { value: 'over_limit', label: 'Over limit', severity: 'danger' },
  { value: 'overdue_block', label: 'Too old', severity: 'danger' },
  { value: 'overdue', label: 'Overdue', severity: 'warn' },
  { value: 'watch', label: 'Near limit', severity: 'warn' },
  { value: 'ok', label: 'OK', severity: 'success' },
]

const MODES = [
  { value: 'block', label: 'Block the despatch' },
  { value: 'warn', label: 'Warn only' },
  { value: 'off', label: 'Off (holds still apply)' },
]

function money(v: any) {
  return v === null || v === undefined ? '—' : formatCurrency(v)
}

function statusMeta(value: string) {
  const found = STATUSES.find((s) => s.value === value)

  return { label: pt(found?.label ?? value), severity: found?.severity ?? 'secondary' }
}

/** How much of the limit is used, for the bar in the exposure column. */
function usedPercent(row: any) {
  if (!row.credit_limit) return null

  return Math.min(100, Math.round((row.counted_exposure / row.credit_limit) * 100))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/credit-control', {
      params: {
        q: search.value || undefined,
        status: status.value ?? undefined,
        salesman_id: salesmanId.value ?? undefined,
        beat_id: beatId.value ?? undefined,
        customer_category_id: categoryId.value ?? undefined,
        city: city.value ?? undefined,
        include_clear: includeClear.value ? 1 : undefined,
        sort: sort.value,
        dir: dir.value,
        page: page.value,
        per_page: perPage.value,
      },
    })
    rows.value = data.data
    total.value = data.meta.total
    summary.value = data.meta.summary
    policy.value = data.meta.policy
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

function onPage(event: any) {
  page.value = event.page + 1
  perPage.value = event.rows
  load()
}

function onSort(event: any) {
  const map: Record<string, typeof sort.value> = {
    counted_exposure: 'exposure',
    overdue: 'overdue',
    available: 'available',
    customer: 'name',
    oldest_due_date: 'oldest',
  }
  sort.value = map[event.sortField] ?? 'exposure'
  dir.value = event.sortOrder === 1 ? 'asc' : 'desc'
  load()
}

function resetFilters() {
  search.value = ''
  status.value = null
  salesmanId.value = null
  beatId.value = null
  categoryId.value = null
  city.value = null
  includeClear.value = false
  page.value = 1
  load()
}

/*
| Actions
*/

const holdRow = ref<any>(null)
const holdReason = ref('')
const saving = ref(false)

function askHold(row: any) {
  holdRow.value = row
  holdReason.value = ''
}

async function confirmHold() {
  if (!holdReason.value.trim()) return
  saving.value = true
  try {
    await http.post(`/credit-control/${holdRow.value.customer_id}/hold`, { reason: holdReason.value })
    toast.add({
      severity: 'warn',
      summary: pt('Supply stopped'),
      detail: holdRow.value.customer,
      life: 3000,
    })
    holdRow.value = null
    await load()
  } finally {
    saving.value = false
  }
}

async function release(row: any) {
  await http.post(`/credit-control/${row.customer_id}/release`)
  toast.add({ severity: 'success', summary: pt('Supply resumed'), detail: row.customer, life: 3000 })
  await load()
}

const termsRow = ref<any>(null)
const termsForm = ref({ credit_limit: 0, payment_terms_days: 0 })

function askTerms(row: any) {
  termsRow.value = row
  termsForm.value = {
    credit_limit: Number(row.credit_limit ?? 0),
    // Only the party's *own* terms are edited here; 0 means "follow the
    // workspace default", which the dialog says in words.
    payment_terms_days: row.has_own_terms ? Number(row.payment_terms_days) : 0,
  }
}

async function saveTerms() {
  saving.value = true
  try {
    await http.put(`/credit-control/${termsRow.value.customer_id}/terms`, termsForm.value)
    toast.add({ severity: 'success', summary: pt('Terms updated'), detail: termsRow.value.customer, life: 3000 })
    termsRow.value = null
    await load()
  } finally {
    saving.value = false
  }
}

/*
| Party detail
*/

const detail = ref<any>(null)
const detailLoading = ref(false)

async function openDetail(row: any) {
  detail.value = { customer: row.customer, snapshot: null, ageing: null, open_orders: [] }
  detailLoading.value = true
  try {
    const { data } = await http.get(`/credit-control/${row.customer_id}`)
    detail.value = { customer: row.customer, ...data.data }
  } finally {
    detailLoading.value = false
  }
}

const BUCKETS = [
  { key: 'not_due', label: 'Not due' },
  { key: 'due_0_30', label: '1-30 days' },
  { key: 'due_31_60', label: '31-60 days' },
  { key: 'due_61_90', label: '61-90 days' },
  { key: 'due_90_plus', label: '90+ days' },
]

/*
| Policy
*/

const policyOpen = ref(false)
const policyForm = ref({ mode: 'block', overdue_block_days: 0, include_pipeline: false })

function openPolicy() {
  policyForm.value = {
    mode: policy.value.mode,
    overdue_block_days: policy.value.overdue_block_days,
    include_pipeline: policy.value.include_pipeline,
  }
  policyOpen.value = true
}

async function savePolicy() {
  saving.value = true
  try {
    const { data } = await http.put('/credit-control/policy', policyForm.value)
    policy.value = data.data
    policyOpen.value = false
    toast.add({ severity: 'success', summary: pt('Credit policy saved'), life: 3000 })
    await load()
  } finally {
    saving.value = false
  }
}

const EXPORT_COLUMNS = [
  { key: 'code', label: 'Code' },
  { key: 'customer', label: 'Party' },
  { key: 'city', label: 'City' },
  { key: 'payment_terms_days', label: 'Terms (days)' },
  { key: 'credit_limit', label: 'Credit limit' },
  { key: 'outstanding', label: 'Outstanding' },
  { key: 'pipeline', label: 'Confirmed orders' },
  { key: 'counted_exposure', label: 'Exposure' },
  { key: 'available', label: 'Available' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'overdue_days', label: 'Days overdue' },
  { key: 'status', label: 'Status' },
]

onMounted(() => {
  load()
  loadFilters()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Credit Control')"
      :subtitle="pt('Party limits against live exposure — and who does not get goods today')"
      icon="pi pi-shield"
      :count="total"
      :count-label="pt('parties')"
    >
      <template #actions>
        <Button
          v-if="canEdit"
          :label="pt('Credit policy')"
          icon="pi pi-cog"
          severity="secondary"
          outlined
          @click="openPolicy"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Parties') }}</span>
        <span class="t-value">{{ summary.parties }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Outstanding') }}</span>
        <span class="t-value">{{ money(summary.outstanding) }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.overdue > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value">{{ money(summary.overdue) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Confirmed, not invoiced') }}</span>
        <span class="t-value">{{ money(summary.pipeline) }}</span>
        <span v-if="!policy.include_pipeline" class="t-hint">{{ pt('not counted against limits') }}</span>
      </div>
      <div class="tile" :class="{ danger: summary.over_limit > 0 }">
        <span class="t-label">{{ pt('Over limit') }}</span>
        <span class="t-value">{{ summary.over_limit }}</span>
      </div>
      <div class="tile" :class="{ danger: summary.on_hold > 0 }">
        <span class="t-label">{{ pt('Stopped') }}</span>
        <span class="t-value">{{ summary.on_hold }}</span>
      </div>
    </div>

    <div class="policy-line">
      <i class="pi pi-info-circle" />
      <span v-if="policy.mode === 'block'">
        {{ pt('Despatch is blocked when a party is over their limit.') }}
      </span>
      <span v-else-if="policy.mode === 'warn'">
        {{ pt('Limits are advisory: over-limit parties are flagged but still supplied.') }}
      </span>
      <span v-else>{{ pt('Automatic limit checks are off. Manual holds still stop supply.') }}</span>
      <span v-if="policy.overdue_block_days > 0">
        {{ pt('Supply also stops past {n} days overdue.', { n: policy.overdue_block_days }) }}
      </span>
      <span class="muted">{{ pt('Default terms: {n} days', { n: policy.default_terms_days }) }}</span>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Party name, code or phone')" @keyup.enter="load" />
      <Select
        v-model="status"
        :options="STATUSES.map((s) => ({ label: pt(s.label), value: s.value }))"
        option-label="label"
        option-value="value"
        :placeholder="pt('Standing')"
        show-clear
        @change="load"
      />
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
      <span class="toggle">
        <ToggleSwitch v-model="includeClear" @change="load" />
        <label>{{ pt('Include settled parties') }}</label>
      </span>
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <Button icon="pi pi-filter-slash" :label="pt('Clear')" text @click="resetFilters" />
      <span class="spacer" />
      <TableExportButtons
        title="Credit Control"
        filenameBase="credit-control"
        :rows="rows"
        :columns="EXPORT_COLUMNS"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="customer_id"
      lazy
      paginator
      :rows="perPage"
      :total-records="total"
      :rows-per-page-options="[25, 50, 100]"
      striped-rows
      responsive-layout="scroll"
      @page="onPage"
      @sort="onSort"
    >
      <Column field="customer" :header="pt('Party')" sortable>
        <template #body="{ data }">
          <div class="party">
            <button class="link" @click="openDetail(data)">{{ data.customer }}</button>
            <span class="sub">
              {{ data.code }}<template v-if="data.city"> · {{ data.city }}</template>
            </span>
            <span v-if="data.on_hold && data.hold_reason" class="hold-reason">
              <i class="pi pi-ban" /> {{ data.hold_reason }}
            </span>
          </div>
        </template>
      </Column>

      <Column field="payment_terms_days" :header="pt('Terms')">
        <template #body="{ data }">
          <span :class="{ muted: !data.has_own_terms }">{{ data.payment_terms_days }} {{ pt('d') }}</span>
        </template>
      </Column>

      <Column field="credit_limit" :header="pt('Limit')">
        <template #body="{ data }">
          <span v-if="data.credit_limit > 0">{{ money(data.credit_limit) }}</span>
          <span v-else class="muted">{{ pt('No limit') }}</span>
        </template>
      </Column>

      <Column field="counted_exposure" :header="pt('Exposure')" sortable>
        <template #body="{ data }">
          <div class="exposure">
            <strong>{{ money(data.counted_exposure) }}</strong>
            <ProgressBar
              v-if="usedPercent(data) !== null"
              :value="usedPercent(data) ?? 0"
              :show-value="false"
              :class="{ over: data.counted_exposure > data.credit_limit }"
              style="height: 5px"
            />
            <span v-if="data.pipeline > 0" class="sub">
              {{ pt('incl. orders') }} {{ money(data.pipeline) }}
            </span>
          </div>
        </template>
      </Column>

      <Column field="available" :header="pt('Available')" sortable>
        <template #body="{ data }">
          <span v-if="data.available === null" class="muted">—</span>
          <span v-else :class="{ negative: data.available < 0 }">{{ money(data.available) }}</span>
        </template>
      </Column>

      <Column field="overdue" :header="pt('Overdue')" sortable>
        <template #body="{ data }">
          <div v-if="data.overdue > 0">
            <strong class="negative">{{ money(data.overdue) }}</strong>
            <span class="sub">{{ pt('{n} days late', { n: data.overdue_days }) }}</span>
          </div>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Standing')">
        <template #body="{ data }">
          <Tag :value="statusMeta(data.status).label" :severity="statusMeta(data.status).severity" />
          <i
            v-if="data.blocked"
            class="pi pi-ban blocked-mark"
            :title="pt('Despatch is blocked for this party')"
          />
        </template>
      </Column>

      <Column v-if="canEdit" :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <div class="actions">
            <Button
              v-if="!data.on_hold"
              icon="pi pi-ban"
              severity="danger"
              text
              :title="pt('Stop supply')"
              @click="askHold(data)"
            />
            <Button
              v-else
              icon="pi pi-check-circle"
              severity="success"
              text
              :title="pt('Resume supply')"
              @click="release(data)"
            />
            <Button
              icon="pi pi-pencil"
              text
              :title="pt('Edit limit and terms')"
              @click="askTerms(data)"
            />
            <Button icon="pi pi-eye" text :title="pt('Ageing detail')" @click="openDetail(data)" />
          </div>
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No party has anything outstanding, committed or held.') }}
        </div>
      </template>
    </DataTable>

    <!-- Stop supply -->
    <Dialog
      :visible="!!holdRow"
      modal
      :header="pt('Stop supply')"
      :style="{ width: '30rem' }"
      @update:visible="holdRow = null"
    >
      <p class="dialog-lead">
        {{ pt('No goods will be despatched to {name} until this is released — at the order desk, the counter and the despatch door alike.', { name: holdRow?.customer ?? '' }) }}
      </p>
      <label class="field-label">{{ pt('Reason') }}</label>
      <Textarea v-model="holdReason" rows="3" auto-resize class="w-full" />
      <span class="field-hint">{{ pt('Shown to whoever tries to supply them.') }}</span>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="holdRow = null" />
        <Button
          :label="pt('Stop supply')"
          icon="pi pi-ban"
          severity="danger"
          :disabled="!holdReason.trim()"
          :loading="saving"
          @click="confirmHold"
        />
      </template>
    </Dialog>

    <!-- Limit and terms -->
    <Dialog
      :visible="!!termsRow"
      modal
      :header="pt('Limit and terms')"
      :style="{ width: '30rem' }"
      @update:visible="termsRow = null"
    >
      <p class="dialog-lead">{{ termsRow?.customer }}</p>
      <label class="field-label">{{ pt('Credit limit') }}</label>
      <InputNumber v-model="termsForm.credit_limit" :min="0" mode="decimal" :max-fraction-digits="2" class="w-full" />
      <span class="field-hint">{{ pt('0 means no limit is enforced for this party.') }}</span>

      <label class="field-label">{{ pt('Credit period (days)') }}</label>
      <InputNumber v-model="termsForm.payment_terms_days" :min="0" :max="365" class="w-full" />
      <span class="field-hint">
        {{ pt('0 follows the workspace default of {n} days. Applies to invoices raised from now on.', { n: policy.default_terms_days }) }}
      </span>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="termsRow = null" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="saveTerms" />
      </template>
    </Dialog>

    <!-- Party ageing detail -->
    <Dialog
      :visible="!!detail"
      modal
      :header="detail?.customer ?? ''"
      :style="{ width: '52rem' }"
      @update:visible="detail = null"
    >
      <div v-if="detailLoading" class="empty-state">{{ pt('Loading…') }}</div>
      <template v-else-if="detail?.snapshot">
        <div v-if="detail.snapshot.reasons.length" class="reasons">
          <div v-for="(reason, i) in detail.snapshot.reasons" :key="i" class="reason">
            <i class="pi pi-exclamation-triangle" /> {{ reason }}
          </div>
        </div>

        <div class="buckets">
          <div v-for="b in BUCKETS" :key="b.key" class="bucket" :class="{ late: b.key !== 'not_due' && detail.ageing.buckets[b.key] > 0 }">
            <span class="t-label">{{ pt(b.label) }}</span>
            <span class="t-value">{{ money(detail.ageing.buckets[b.key]) }}</span>
          </div>
          <div v-if="detail.ageing.opening_balance" class="bucket">
            <span class="t-label">{{ pt('Opening balance') }}</span>
            <span class="t-value">{{ money(detail.ageing.opening_balance) }}</span>
          </div>
          <div class="bucket total">
            <span class="t-label">{{ pt('Total outstanding') }}</span>
            <span class="t-value">{{ money(detail.ageing.total_outstanding) }}</span>
          </div>
        </div>

        <h4>{{ pt('Open invoices') }}</h4>
        <DataTable :value="detail.ageing.invoices" data-key="invoice_id" class="p-datatable-sm" :rows="8" paginator>
          <Column field="invoice_number" :header="pt('Invoice')" />
          <Column field="invoice_date" :header="pt('Date')" />
          <Column field="due_date" :header="pt('Due')" />
          <Column field="days_past_due" :header="pt('Days late')">
            <template #body="{ data }">
              <span :class="{ negative: data.days_past_due > 0 }">{{ data.days_past_due || '—' }}</span>
            </template>
          </Column>
          <Column field="balance" :header="pt('Balance')">
            <template #body="{ data }">{{ money(data.balance) }}</template>
          </Column>
          <template #empty>
            <div class="empty-state">{{ pt('Nothing unpaid.') }}</div>
          </template>
        </DataTable>

        <template v-if="detail.open_orders?.length">
          <h4>{{ pt('Confirmed, not yet invoiced') }}</h4>
          <DataTable :value="detail.open_orders" data-key="id" class="p-datatable-sm">
            <Column field="so_number" :header="pt('Order')" />
            <Column field="order_date" :header="pt('Date')" />
            <Column field="status" :header="pt('Status')" />
            <Column field="total" :header="pt('Value')">
              <template #body="{ data }">{{ money(data.total) }}</template>
            </Column>
          </DataTable>
        </template>
      </template>
      <template #footer>
        <Button :label="pt('Close')" text @click="detail = null" />
      </template>
    </Dialog>

    <!-- Workspace credit policy -->
    <Dialog v-model:visible="policyOpen" modal :header="pt('Credit policy')" :style="{ width: '34rem' }">
      <label class="field-label">{{ pt('When a party is over their limit') }}</label>
      <Select
        v-model="policyForm.mode"
        :options="MODES.map((m) => ({ label: pt(m.label), value: m.value }))"
        option-label="label"
        option-value="value"
        class="w-full"
      />
      <span class="field-hint">
        {{ pt('A manual hold always stops supply, whatever this is set to.') }}
      </span>

      <label class="field-label">{{ pt('Also stop supply past this many days overdue') }}</label>
      <InputNumber v-model="policyForm.overdue_block_days" :min="0" :max="365" class="w-full" />
      <span class="field-hint">{{ pt('0 turns the age check off.') }}</span>

      <span class="toggle policy-toggle">
        <ToggleSwitch v-model="policyForm.include_pipeline" />
        <label>{{ pt('Count confirmed, uninvoiced orders against the limit') }}</label>
      </span>
      <span class="field-hint">
        {{ pt('On for a distributor releasing lorries daily; off for a counter where the order and the bill are the same moment.') }}
      </span>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="policyOpen = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="savePolicy" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.danger { border-color: #c62828; background: rgba(198, 40, 40, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.2rem; font-weight: 700; }
.t-hint { font-size: 0.68rem; color: #9ca3af; }

.policy-line { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; font-size: 0.8rem; color: #4b5563; margin-bottom: 1rem; }
.policy-line .muted { color: #9ca3af; }

.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.toolbar .spacer { flex: 1; }
.toggle { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.82rem; }
.policy-toggle { margin-top: 1rem; }

.party { display: flex; flex-direction: column; gap: 0.1rem; }
.link { background: none; border: 0; padding: 0; font: inherit; color: var(--p-primary-color, #2563eb); cursor: pointer; text-align: left; }
.sub { font-size: 0.72rem; color: #6b7280; }
.hold-reason { font-size: 0.72rem; color: #c62828; }
.exposure { display: flex; flex-direction: column; gap: 0.25rem; min-width: 8rem; }
.negative { color: #c62828; }
.muted { color: #9ca3af; }
.blocked-mark { color: #c62828; margin-left: 0.4rem; }
.actions { display: flex; gap: 0.1rem; }

.dialog-lead { margin: 0 0 1rem; color: #4b5563; }
.field-label { display: block; font-size: 0.78rem; font-weight: 600; margin: 0.9rem 0 0.3rem; }
.field-hint { display: block; font-size: 0.72rem; color: #6b7280; margin-top: 0.25rem; }
.w-full { width: 100%; }

.reasons { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1rem; }
.reason { background: rgba(198, 40, 40, 0.08); border: 1px solid rgba(198, 40, 40, 0.3); border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.82rem; color: #a11414; }
.buckets { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.5rem; margin-bottom: 1rem; }
.bucket { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.5rem 0.6rem; display: flex; flex-direction: column; }
.bucket.late { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.bucket.total { border-color: var(--p-primary-color, #2563eb); }
h4 { margin: 1.1rem 0 0.5rem; font-size: 0.9rem; }

.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }

:deep(.p-progressbar.over .p-progressbar-value) { background: #c62828; }
</style>
