<script setup lang="ts">
/**
 * Household standing orders.
 *
 * The daily-value column is the number a dairy plans on, so it counts a
 * both-shifts subscription twice — that household really does get two deliveries.
 *
 * Deleting is offered but rarely happens: a subscription with delivery history is
 * cancelled instead, because those rows are either billed or about to be.
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
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()
const confirm = useConfirm()

const rows = ref<any[]>([])
const summary = ref({ total: 0, active: 0, paused: 0, daily_litres: 0, daily_value: 0, unbilled_amount: 0 })
const customers = ref<any[]>([])
const products = ref<any[]>([])
const routes = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)

const dialogVisible = ref(false)
const billVisible = ref(false)
const editing = ref<any>(null)
const errors = ref<Record<string, string[]>>({})

const DAYS = computed(() => [
  { label: pt('Mon'), value: 1 },
  { label: pt('Tue'), value: 2 },
  { label: pt('Wed'), value: 3 },
  { label: pt('Thu'), value: 4 },
  { label: pt('Fri'), value: 5 },
  { label: pt('Sat'), value: 6 },
  { label: pt('Sun'), value: 7 },
])

const SHIFTS = computed(() => [
  { label: pt('Morning'), value: 'morning' },
  { label: pt('Evening'), value: 'evening' },
  { label: pt('Both'), value: 'both' },
])

const STATUSES = computed(() => [
  { label: pt('Active'), value: 'active' },
  { label: pt('Paused'), value: 'paused' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

const SEVERITY: Record<string, string> = { active: 'success', paused: 'warn', cancelled: 'danger' }

function ymd(d: Date) {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** Defaults to the month that just ended — what a dairy bills on the 1st. */
function lastMonthStart() {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return d
}

function lastMonthEnd() {
  const d = new Date()
  d.setDate(0)
  return d
}

const blank = () => ({
  customer_id: null as number | null,
  product_id: null as number | null,
  quantity_per_day: 0.5,
  rate: 0,
  shift: 'morning',
  days: [1, 2, 3, 4, 5, 6, 7],
  delivery_route_id: null as number | null,
  start_date: new Date(),
  end_date: null as Date | null,
  status: 'active',
  notes: '',
})

const form = ref<any>(blank())

const billForm = ref({ from: lastMonthStart(), to: lastMonthEnd(), customer_id: null as number | null })

function dayLabels(days: number[]) {
  if (!days?.length || days.length === 7) return pt('Every day')
  return days
    .slice()
    .sort()
    .map((d) => DAYS.value.find((x) => x.value === d)?.label ?? d)
    .join(', ')
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/milk-subscriptions', {
      params: { search: search.value || undefined, status: statusFilter.value || undefined },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [cust, prod, rt] = await Promise.all([
    http.get('/customers', { params: { per_page: 500 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/delivery-routes', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
  ])
  customers.value = cust.data.data
  products.value = prod.data.data
  routes.value = rt.data.data
}

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    customer_id: row.customer_id,
    product_id: row.product_id,
    variant_id: row.variant_id,
    quantity_per_day: Number(row.quantity_per_day),
    rate: Number(row.rate),
    shift: row.shift,
    days: row.days ?? [],
    delivery_route_id: row.delivery_route_id,
    start_date: new Date(row.start_date),
    end_date: row.end_date ? new Date(row.end_date) : null,
    status: row.status,
    notes: row.notes ?? '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      start_date: ymd(form.value.start_date),
      end_date: form.value.end_date ? ymd(form.value.end_date) : null,
    }

    if (editing.value) {
      await http.put(`/milk-subscriptions/${editing.value.id}`, payload)
    } else {
      await http.post('/milk-subscriptions', payload)
    }

    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Subscription saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
  } finally {
    saving.value = false
  }
}

async function setStatus(row: any, status: string) {
  try {
    await http.put(`/milk-subscriptions/${row.id}`, {
      customer_id: row.customer_id,
      product_id: row.product_id,
      quantity_per_day: row.quantity_per_day,
      rate: row.rate,
      shift: row.shift,
      days: row.days,
      delivery_route_id: row.delivery_route_id,
      start_date: row.start_date,
      end_date: row.end_date,
      status,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not update'), detail: e.response?.data?.message, life: 4000 })
  }
}

function remove(row: any) {
  confirm.require({
    message: pt('Delete this subscription? One with delivery history is cancelled instead.'),
    header: pt('Delete subscription'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      const { data } = await http.delete(`/milk-subscriptions/${row.id}`)
      await load()
      toast.add({ severity: 'success', summary: data.message, life: 3500 })
    },
  })
}

async function runBilling() {
  saving.value = true
  try {
    const { data } = await http.post('/milk-subscriptions/bill', {
      from: ymd(billForm.value.from),
      to: ymd(billForm.value.to),
      customer_id: billForm.value.customer_id || undefined,
    })
    billVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 5000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Nothing billed'),
      detail: e.response?.data?.message,
      life: 4500,
    })
  } finally {
    saving.value = false
  }
}

const customerOptions = computed(() =>
  customers.value.map((c) => ({ label: `${c.name}${c.phone ? ' · ' + c.phone : ''}`, value: c.id })),
)

const productOptions = computed(() =>
  products.value.map((p) => ({ label: `${p.sku} — ${p.name}`, value: p.id })),
)

const routeOptions = computed(() => routes.value.map((r) => ({ label: r.name, value: r.id })))

onMounted(() => {
  load()
  loadRefs()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Milk Subscriptions')"
      :subtitle="pt('The standing instruction. The month is billed from what was actually delivered, never from this')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Paused') }}</span>
        <span class="t-value">{{ summary.paused }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Litres a day') }}</span>
        <span class="t-value">{{ summary.daily_litres }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value a day') }}</span>
        <span class="t-value">{{ formatCurrency(summary.daily_value) }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.unbilled_amount > 0 }">
        <span class="t-label">{{ pt('Unbilled') }}</span>
        <span class="t-value">{{ formatCurrency(summary.unbilled_amount) }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Number, name or phone')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        :placeholder="pt('All statuses')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-search" text @click="load" />
      <Button icon="pi pi-plus" :label="pt('New Subscription')" @click="openCreate" />
      <Button icon="pi pi-file-export" severity="secondary" :label="pt('Run Month-end Billing')" @click="billVisible = true" />
      <TableExportButtons
        title="Milk Subscriptions"
        filenameBase="milk-subscriptions"
        :rows="rows"
        :columns="[
          { key: 'subscription_number', label: 'No.' },
          { key: 'customer', label: 'Customer' },
          { key: 'phone', label: 'Phone' },
          { key: 'product', label: 'Product' },
          { key: 'quantity_per_day', label: 'Qty/day' },
          { key: 'rate', label: 'Rate' },
          { key: 'shift', label: 'Shift' },
          { key: 'route', label: 'Route' },
          { key: 'daily_value', label: 'Value/day' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="subscription_number" :header="pt('No.')" sortable />
      <Column field="customer" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.customer }}</span>
            <small class="muted">{{ data.phone }}</small>
          </div>
        </template>
      </Column>
      <Column :header="pt('Order')">
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.quantity_per_day }} × {{ formatCurrency(data.rate) }} · {{ pt(data.shift) }}</span>
            <small class="muted">{{ data.product }} · {{ dayLabels(data.days) }}</small>
          </div>
        </template>
      </Column>
      <Column field="route" :header="pt('Route')" sortable>
        <template #body="{ data }">
          <span v-if="data.route">{{ data.route }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="daily_value" :header="pt('Value / day')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.daily_value) }}</template>
      </Column>
      <Column :header="pt('Due today')">
        <template #body="{ data }">
          <i :class="data.due_today ? 'pi pi-check text-ok' : 'pi pi-minus muted'" />
        </template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="SEVERITY[data.status]" />
        </template>
      </Column>
      <Column :header="pt('Actions')">
        <template #body="{ data }">
          <Button
            v-if="data.status === 'active'"
            icon="pi pi-pause"
            text
            size="small"
            v-tooltip.top="pt('Pause')"
            @click="setStatus(data, 'paused')"
          />
          <Button
            v-if="data.status === 'paused'"
            icon="pi pi-play"
            text
            size="small"
            v-tooltip.top="pt('Resume')"
            @click="setStatus(data, 'active')"
          />
          <Button icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button icon="pi pi-trash" text severity="danger" size="small" @click="remove(data)" />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No standing orders yet.') }}</div>
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Subscription') : pt('New Subscription')"
      :style="{ width: '38rem' }"
    >
      <div class="form">
        <div class="field">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customerOptions"
            option-label="label"
            option-value="value"
            filter
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Product') }}</label>
          <Select
            v-model="form.product_id"
            :options="productOptions"
            option-label="label"
            option-value="value"
            filter
          />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Quantity a day') }}</label>
            <InputNumber v-model="form.quantity_per_day" :min="0.001" :max-fraction-digits="3" />
          </div>
          <div class="field">
            <label>{{ pt('Agreed rate') }}</label>
            <InputNumber v-model="form.rate" :min="0" :max-fraction-digits="2" />
            <small class="hint">{{ pt('Held here, not read off the product') }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Shift') }}</label>
            <Select v-model="form.shift" :options="SHIFTS" option-label="label" option-value="value" />
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Days') }}</label>
          <MultiSelect
            v-model="form.days"
            :options="DAYS"
            option-label="label"
            option-value="value"
            display="chip"
          />
          <small v-if="errors.days" class="err">{{ errors.days[0] }}</small>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Route') }}</label>
            <Select
              v-model="form.delivery_route_id"
              :options="routeOptions"
              option-label="label"
              option-value="value"
              show-clear
            />
          </div>
          <div class="field">
            <label>{{ pt('Status') }}</label>
            <Select v-model="form.status" :options="STATUSES" option-label="label" option-value="value" />
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Starts') }}</label>
            <DatePicker v-model="form.start_date" date-format="yy-mm-dd" show-icon />
          </div>
          <div class="field">
            <label>{{ pt('Ends') }}</label>
            <DatePicker v-model="form.end_date" date-format="yy-mm-dd" show-icon show-button-bar />
            <small class="hint">{{ pt('Blank means open-ended') }}</small>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog v-model:visible="billVisible" modal :header="pt('Month-end Billing')" :style="{ width: '30rem' }">
      <div class="form">
        <p class="muted">
          {{ pt('Only unbilled deliveries are picked up, so this is safe to run twice.') }}
        </p>

        <div class="row">
          <div class="field">
            <label>{{ pt('From') }}</label>
            <DatePicker v-model="billForm.from" date-format="yy-mm-dd" show-icon />
          </div>
          <div class="field">
            <label>{{ pt('To') }}</label>
            <DatePicker v-model="billForm.to" date-format="yy-mm-dd" show-icon />
          </div>
        </div>

        <div class="field">
          <label>{{ pt('One customer only') }}</label>
          <Select
            v-model="billForm.customer_id"
            :options="customerOptions"
            option-label="label"
            option-value="value"
            filter
            show-clear
            :placeholder="pt('Everybody with unbilled deliveries')"
          />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="billVisible = false" />
        <Button :label="pt('Raise Invoices')" :loading="saving" @click="runBilling" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.text-ok { color: #16a34a; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.row3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.hint { font-size: 0.7rem; color: #9ca3af; }
.err { color: #dc2626; font-size: 0.75rem; }
</style>
