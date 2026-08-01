<script setup lang="ts">
/**
 * The bakery's order book.
 *
 * Defaults to the board — open orders, soonest first — because that is what a
 * bakery opens this screen to see. Overdue rows are flagged rather than hidden: a
 * cake that was due at four and is still sitting there is the most important row
 * on the screen.
 *
 * Handing a cake over raises the invoice and carries the advance across as a
 * payment, so it is a deliberate action with its own button rather than a status
 * anybody can tap through.
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
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
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
const summary = ref({ due_today: 0, due_tomorrow: 0, overdue: 0, in_production: 0, ready: 0 })
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)

const dialogVisible = ref(false)
const editing = ref<any>(null)
const errors = ref<Record<string, string[]>>({})

const SHAPES = computed(() => [
  { label: pt('Round'), value: 'round' },
  { label: pt('Square'), value: 'square' },
  { label: pt('Heart'), value: 'heart' },
  { label: pt('Rectangle'), value: 'rectangle' },
  { label: pt('Tiered'), value: 'tiered' },
  { label: pt('Custom'), value: 'custom' },
])

const STATUSES = computed(() => [
  { label: pt('Booked'), value: 'booked' },
  { label: pt('In production'), value: 'in_production' },
  { label: pt('Ready'), value: 'ready' },
  { label: pt('Delivered'), value: 'delivered' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

const SEVERITY: Record<string, string> = {
  booked: 'secondary',
  in_production: 'warn',
  ready: 'success',
  delivered: 'info',
  cancelled: 'danger',
}

/** Tomorrow at 11am — a sensible default for a cake booked over the counter today. */
function defaultRequiredAt() {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  d.setHours(11, 0, 0, 0)
  return d
}

const blank = () => ({
  customer_name: '',
  customer_phone: '',
  flavour: '',
  weight_kg: 1,
  shape: 'round',
  tiers: 1,
  is_eggless: false,
  message_on_cake: '',
  design_notes: '',
  required_at: defaultRequiredAt(),
  fulfilment: 'pickup',
  delivery_address: '',
  delivery_charge: 0,
  price: 0,
  advance_amount: 0,
  notes: '',
})

const form = ref<any>(blank())

const balancePreview = computed(() =>
  Math.max(0, Number(form.value.price || 0) + Number(form.value.delivery_charge || 0) - Number(form.value.advance_amount || 0)),
)

function isoLocal(d: Date) {
  // Local parts, not toISOString(): a cake due at 9am IST must not be stored as
  // the previous day in UTC.
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/cake-orders', {
      params: { search: search.value || undefined, status: statusFilter.value || undefined },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
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
    customer_name: row.customer_name,
    customer_phone: row.customer_phone ?? '',
    product_id: row.product_id,
    flavour: row.flavour ?? '',
    weight_kg: Number(row.weight_kg),
    shape: row.shape,
    tiers: row.tiers,
    is_eggless: row.is_eggless,
    message_on_cake: row.message_on_cake ?? '',
    design_notes: row.design_notes ?? '',
    required_at: new Date(row.required_at),
    fulfilment: row.fulfilment,
    delivery_address: row.delivery_address ?? '',
    delivery_charge: Number(row.delivery_charge),
    price: Number(row.price),
    advance_amount: Number(row.advance_amount),
    notes: row.notes ?? '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, required_at: isoLocal(form.value.required_at) }

    if (editing.value) {
      await http.put(`/cake-orders/${editing.value.id}`, payload)
    } else {
      await http.post('/cake-orders', payload)
    }

    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Cake order saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not save the order'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function setStatus(row: any, status: string) {
  try {
    await http.post(`/cake-orders/${row.id}/status`, { status })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not update'), detail: e.response?.data?.message, life: 4000 })
  }
}

function deliver(row: any) {
  confirm.require({
    message: pt('Hand this cake over? This raises the invoice and records the advance against it.'),
    header: pt('Deliver cake'),
    icon: 'pi pi-check-circle',
    accept: async () => {
      try {
        const { data } = await http.post(`/cake-orders/${row.id}/deliver`, { advance_mode: 'cash' })
        await load()
        toast.add({ severity: 'success', summary: data.message, life: 3500 })
      } catch (e: any) {
        toast.add({ severity: 'error', summary: pt('Could not bill the cake'), detail: e.response?.data?.message, life: 4000 })
      }
    },
  })
}

function remove(row: any) {
  confirm.require({
    message: pt('Delete this cake order?'),
    header: pt('Delete order'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      try {
        await http.delete(`/cake-orders/${row.id}`)
        await load()
      } catch (e: any) {
        toast.add({ severity: 'error', summary: pt('Could not delete'), detail: e.response?.data?.message, life: 4000 })
      }
    },
  })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Cake Orders')"
      :subtitle="pt('Booked now, made later, billed on handover — the advance rides on the order until then')"
    />

    <div class="tiles">
      <div class="tile" :class="{ accent: summary.overdue > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value">{{ summary.overdue }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Due today') }}</span>
        <span class="t-value">{{ summary.due_today }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Due tomorrow') }}</span>
        <span class="t-value">{{ summary.due_tomorrow }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('In production') }}</span>
        <span class="t-value">{{ summary.in_production }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Ready') }}</span>
        <span class="t-value">{{ summary.ready }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Name, phone or inscription')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        :placeholder="pt('Open orders')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-search" text @click="load" />
      <Button icon="pi pi-plus" :label="pt('Book Cake')" @click="openCreate" />
      <TableExportButtons
        title="Cake Orders"
        filenameBase="cake-orders"
        :rows="rows"
        :columns="[
          { key: 'order_number', label: 'Order' },
          { key: 'customer_name', label: 'Customer' },
          { key: 'customer_phone', label: 'Phone' },
          { key: 'required_at', label: 'Needed' },
          { key: 'weight_kg', label: 'Weight (kg)' },
          { key: 'flavour', label: 'Flavour' },
          { key: 'message_on_cake', label: 'Inscription' },
          { key: 'payable', label: 'Payable' },
          { key: 'advance_amount', label: 'Advance' },
          { key: 'balance', label: 'Balance' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="required_at" :header="pt('Needed')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong :class="{ late: data.is_overdue }">{{ data.required_at }}</strong>
            <small v-if="data.hours_remaining !== null" class="muted">
              {{ data.hours_remaining >= 0 ? pt('in {h}h').replace('{h}', String(data.hours_remaining)) : pt('{h}h late').replace('{h}', String(Math.abs(data.hours_remaining))) }}
            </small>
          </div>
        </template>
      </Column>
      <Column field="order_number" :header="pt('Order')" sortable />
      <Column field="customer_name" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.customer_name }}</span>
            <small class="muted">{{ data.customer_phone }}</small>
          </div>
        </template>
      </Column>
      <Column :header="pt('Cake')">
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.weight_kg }} {{ pt('kg') }} · {{ pt(data.shape) }}<span v-if="data.tiers > 1"> · {{ data.tiers }} {{ pt('tiers') }}</span></span>
            <small class="muted">
              {{ data.flavour || '—' }}
              <Tag v-if="data.is_eggless" :value="pt('eggless')" severity="success" class="tiny" />
            </small>
          </div>
        </template>
      </Column>
      <Column field="message_on_cake" :header="pt('Inscription')">
        <template #body="{ data }">
          <span v-if="data.message_on_cake" class="inscription">“{{ data.message_on_cake }}”</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Money')">
        <template #body="{ data }">
          <div class="stack">
            <span>{{ formatCurrency(data.payable) }}</span>
            <small class="muted">
              {{ pt('adv') }} {{ formatCurrency(data.advance_amount) }} ·
              {{ pt('bal') }} {{ formatCurrency(data.balance) }}
            </small>
          </div>
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
            v-if="data.status === 'booked'"
            icon="pi pi-play"
            text
            size="small"
            v-tooltip.top="pt('Start baking')"
            @click="setStatus(data, 'in_production')"
          />
          <Button
            v-if="data.status === 'in_production'"
            icon="pi pi-check"
            text
            size="small"
            v-tooltip.top="pt('Mark ready')"
            @click="setStatus(data, 'ready')"
          />
          <Button
            v-if="['booked', 'in_production', 'ready'].includes(data.status)"
            icon="pi pi-shopping-bag"
            text
            severity="success"
            size="small"
            v-tooltip.top="pt('Hand over & bill')"
            @click="deliver(data)"
          />
          <Button v-if="!data.invoice_id" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="!data.invoice_id" icon="pi pi-trash" text severity="danger" size="small" @click="remove(data)" />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No cake orders on the board.') }}</div>
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Cake Order') : pt('Book a Cake')"
      :style="{ width: '44rem' }"
    >
      <div class="form">
        <div class="row">
          <div class="field">
            <label>{{ pt('Customer name') }}</label>
            <InputText v-model="form.customer_name" />
            <small v-if="errors.customer_name" class="err">{{ errors.customer_name[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Phone') }}</label>
            <InputText v-model="form.customer_phone" />
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Needed by') }}</label>
            <DatePicker v-model="form.required_at" show-time hour-format="24" date-format="yy-mm-dd" show-icon />
            <small v-if="errors.required_at" class="err">{{ errors.required_at[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Flavour') }}</label>
            <InputText v-model="form.flavour" />
          </div>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Weight (kg)') }}</label>
            <InputNumber v-model="form.weight_kg" :min="0.1" :max-fraction-digits="3" />
            <small v-if="errors.weight_kg" class="err">{{ errors.weight_kg[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Shape') }}</label>
            <Select v-model="form.shape" :options="SHAPES" option-label="label" option-value="value" />
          </div>
          <div class="field">
            <label>{{ pt('Tiers') }}</label>
            <InputNumber v-model="form.tiers" :min="1" :max="20" />
          </div>
        </div>

        <div class="field-inline">
          <Checkbox v-model="form.is_eggless" binary input-id="cake-eggless" />
          <label for="cake-eggless">{{ pt('Eggless') }}</label>
        </div>

        <div class="field">
          <label>{{ pt('Message on the cake') }}</label>
          <InputText v-model="form.message_on_cake" :placeholder="pt('Exactly as it should be piped')" />
        </div>

        <div class="field">
          <label>{{ pt('Design notes') }}</label>
          <Textarea v-model="form.design_notes" rows="2" />
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Collection') }}</label>
            <Select
              v-model="form.fulfilment"
              :options="[
                { label: pt('Pickup'), value: 'pickup' },
                { label: pt('Delivery'), value: 'delivery' },
              ]"
              option-label="label"
              option-value="value"
            />
          </div>
          <div v-if="form.fulfilment === 'delivery'" class="field">
            <label>{{ pt('Delivery charge') }}</label>
            <InputNumber v-model="form.delivery_charge" :min="0" :max-fraction-digits="2" />
          </div>
        </div>

        <div v-if="form.fulfilment === 'delivery'" class="field">
          <label>{{ pt('Delivery address') }}</label>
          <Textarea v-model="form.delivery_address" rows="2" />
          <small v-if="errors.delivery_address" class="err">{{ errors.delivery_address[0] }}</small>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Price') }}</label>
            <InputNumber v-model="form.price" :min="0" :max-fraction-digits="2" />
            <small v-if="errors.price" class="err">{{ errors.price[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Advance taken') }}</label>
            <InputNumber v-model="form.advance_amount" :min="0" :max-fraction-digits="2" />
          </div>
          <div class="field">
            <label>{{ pt('Balance on handover') }}</label>
            <div class="readout">{{ formatCurrency(balancePreview) }}</div>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #dc2626; background: rgba(220, 38, 38, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.late { color: #dc2626; }
.tiny { font-size: 0.65rem; margin-left: 0.3rem; }
.inscription { font-style: italic; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.row3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.readout { padding: 0.5rem 0.75rem; border: 1px dashed var(--surface-border, #e5e7eb); border-radius: 8px; font-weight: 700; }
.err { color: #dc2626; font-size: 0.75rem; }
</style>
