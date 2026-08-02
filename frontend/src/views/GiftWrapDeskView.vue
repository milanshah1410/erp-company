<script setup lang="ts">
/**
 * The wrap desk.
 *
 * A queue screen, not a register. In the fortnight that decides a gift shop's
 * year this is the bottleneck, so it is built to be *worked*: the default view is
 * everything still to do with the soonest promise first, late parcels are red,
 * and the two tiles that matter are "waiting" and "late" rather than a lifetime
 * count of parcels wrapped.
 *
 * Two rules of the trade are visible in the form. The gift-receipt switch defaults
 * to on, because the person receiving a gift must not be able to read what it
 * cost. And the charge is not typed here — it comes from the wrapping chosen and
 * is billed as an ordinary line at the till, so this screen never touches money it
 * would then have to reconcile.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('gift-wraps.create'))
const canEdit = computed(() => auth.can('gift-wraps.edit'))
const canDelete = computed(() => auth.can('gift-wraps.delete'))

const STATUSES = [
  { label: 'Waiting', value: 'pending' },
  { label: 'Wrapped', value: 'wrapped' },
  { label: 'Collected', value: 'collected' },
  { label: 'Cancelled', value: 'cancelled' },
]

const rows = ref<any[]>([])
const summary = ref({ pending: 0, late: 0, due_today: 0, wrapped: 0, collected_today: 0, charged_today: 0 })
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const lateOnly = ref(false)

const options = ref<any[]>([])
const customers = ref<any[]>([])
const occasions = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/gift/wraps', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        late: lateOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [o, c] = await Promise.all([
    http.get('/gift/wrap-options', { params: { per_page: 100, is_active: 1 } }),
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
  ])
  options.value = (o.data.data ?? o.data).map((x: any) => ({
    label: x.charge > 0 ? `${x.name} — ${formatCurrency(x.charge)}` : `${x.name} — ${pt('free')}`,
    value: x.id,
    charge: Number(x.charge ?? 0),
    turnaround: x.turnaround_minutes,
  }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: `${x.name} — ${x.phone ?? ''}`.trim(),
    value: x.id,
  }))

  // Occasions are optional and only readable by desks that hold the register, so
  // a counter hand without it simply gets no picker rather than an error.
  if (auth.can('gift-occasions.view')) {
    const { data } = await http.get('/gift/occasions', { params: { within_days: 60, is_active: 1 } })
    occasions.value = (data.data ?? []).map((x: any) => ({
      label: `${x.person} — ${x.occasion_label} (${x.next_occurrence})`,
      value: x.id,
      customer_id: x.customer_id,
      recipient: x.person_name,
    }))
  }
}

// ---- Take a parcel in -----------------------------------------------------

const formVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blank())
const editingId = ref<number | null>(null)

function blank() {
  return {
    wrap_option_id: null,
    customer_id: null,
    occasion_id: null,
    recipient_name: '',
    recipient_phone: '',
    sender_name: '',
    message: '',
    // The trade's default, and the reason it is a switch rather than a checkbox
    // buried in a panel: turning it off is the unusual decision.
    hide_prices: true,
    promised_at: null as Date | null,
    notes: '',
  }
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  formVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  http.get(`/gift/wraps/${row.id}`).then(({ data }) => {
    const d = data.data
    form.value = {
      wrap_option_id: d.wrap_option_id,
      customer_id: d.customer_id,
      occasion_id: d.occasion_id,
      recipient_name: d.recipient_name ?? '',
      recipient_phone: d.recipient_phone ?? '',
      sender_name: d.sender_name ?? '',
      message: d.message ?? '',
      hide_prices: !!d.hide_prices,
      promised_at: d.promised_at ? new Date(d.promised_at) : null,
      notes: d.notes ?? '',
    }
    formVisible.value = true
  })
}

/** Promise a time off the wrapping's own turnaround, so nothing joins the queue undated. */
function onOptionPicked() {
  const picked = options.value.find((o) => o.value === form.value.wrap_option_id)
  if (picked?.turnaround && !form.value.promised_at) {
    const at = new Date()
    at.setMinutes(at.getMinutes() + Number(picked.turnaround))
    form.value.promised_at = at
  }
}

/** Filling the occasion in fills the recipient — that is why it was recorded. */
function onOccasionPicked() {
  const picked = occasions.value.find((o) => o.value === form.value.occasion_id)
  if (!picked) return
  if (!form.value.customer_id) form.value.customer_id = picked.customer_id
  if (!form.value.recipient_name && picked.recipient) form.value.recipient_name = picked.recipient
}

const chargeQuote = computed(() => {
  const picked = options.value.find((o) => o.value === form.value.wrap_option_id)
  return picked ? picked.charge : 0
})

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, promised_at: asDateTime(form.value.promised_at) }
    editingId.value
      ? await http.put(`/gift/wraps/${editingId.value}`, payload)
      : await http.post('/gift/wraps', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Parcel updated' : 'Parcel taken in'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the parcel'), life: 3500 })
  } finally {
    saving.value = false
  }
}

// ---- Working the queue ----------------------------------------------------

async function act(row: any, action: 'wrap' | 'collect') {
  try {
    const { data } = await http.post(`/gift/wraps/${row.id}/${action}`)
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('That did not work'), life: 4000 })
  }
}

async function cancel(row: any) {
  const res = await Swal.fire({
    title: pt('Cancel this parcel?'),
    text: pt('Anything already used to wrap it stays consumed — the paper was really cut.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Cancel Parcel'),
    cancelButtonText: pt('Keep It'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/gift/wraps/${row.id}/cancel`, { reason: res.value || undefined })
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this parcel?'),
    text: pt('Only a parcel that has consumed no material can be deleted. Cancel the others.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/gift/wraps/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// ---- The card ------------------------------------------------------------

const cardVisible = ref(false)
const detail = ref<any>(null)

async function openCard(row: any) {
  const { data } = await http.get(`/gift/wraps/${row.id}`)
  detail.value = data.data
  cardVisible.value = true
}

/**
 * Prints the gift card — the slip that goes inside the parcel. Deliberately the
 * card and nothing else: no prices, whatever the parcel's own flag says, because
 * a price on the thing tucked under the ribbon is the one mistake in this trade
 * that cannot be taken back.
 */
function printCard() {
  const d = detail.value
  if (!d) return
  const win = window.open('', '_blank', 'width=520,height=420')
  if (!win) return
  win.document.write(`
    <html><head><title>${d.wrap_number}</title>
    <style>
      body { font-family: Georgia, 'Times New Roman', serif; padding: 32px; }
      .card { border: 1px solid #d4b483; border-radius: 8px; padding: 28px; }
      .to { font-size: 1.1rem; margin-bottom: 12px; }
      .msg { font-size: 1rem; line-height: 1.6; white-space: pre-wrap; margin: 18px 0; }
      .from { text-align: right; font-size: 1rem; }
      .ref { margin-top: 18px; font-size: 0.7rem; color: #999; font-family: sans-serif; }
    </style></head><body onload="window.print()">
      <div class="card">
        <div class="to">${escapeHtml(pt('To'))}: ${escapeHtml(d.recipient_name ?? '')}</div>
        <div class="msg">${escapeHtml(d.message ?? '')}</div>
        <div class="from">${escapeHtml(pt('From'))}: ${escapeHtml(d.sender_name ?? '')}</div>
        <div class="ref">${escapeHtml(d.wrap_number)}</div>
      </div>
    </body></html>`)
  win.document.close()
}

function escapeHtml(v: string) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string,
  )
}

function asDateTime(v: any): string | undefined {
  if (!v) return undefined
  const d = v instanceof Date ? v : new Date(v)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

function statusTag(row: any) {
  if (row.status === 'collected') return { label: pt('Collected'), severity: 'success' }
  if (row.status === 'cancelled') return { label: pt('Cancelled'), severity: 'contrast' }
  if (row.status === 'wrapped') return { label: pt('Wrapped'), severity: 'info' }
  return row.is_late ? { label: pt('Late'), severity: 'danger' } : { label: pt('Waiting'), severity: 'warn' }
}

function promisedLabel(row: any) {
  if (!row.promised_at) return '—'
  return new Date(row.promised_at).toLocaleString(undefined, {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}

const exportColumns: ExportColumn[] = [
  { key: 'wrap_number', label: 'Wrap #' },
  { key: 'wrap_label', label: 'Wrapping' },
  { key: 'customer', label: 'Customer' },
  { key: 'recipient_name', label: 'For' },
  { key: 'promised_at', label: 'Promised' },
  { key: 'charge', label: 'Charge' },
  { key: 'status', label: 'Status' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Wrap Desk')" :subtitle="pt('What is waiting, and what we are late on')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('Take Parcel In')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Waiting') }}</span>
        <span class="t-value">{{ summary.pending }}</span>
        <small class="muted">{{ summary.due_today }} {{ pt('due today') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Late') }}</span>
        <span class="t-value" :class="{ danger: summary.late > 0 }">{{ summary.late }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Wrapped, not collected') }}</span>
        <span class="t-value">{{ summary.wrapped }}</span>
        <small class="muted">{{ summary.collected_today }} {{ pt('handed over today') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Wrapping charged today') }}</span>
        <span class="t-value">{{ formatCurrency(summary.charged_today) }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search wrap no., customer or recipient')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        showClear
        :placeholder="pt('The queue')"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="lateOnly" binary @change="load" />
        <span>{{ pt('Late only') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="gift-wraps" :title="pt('Wrap Desk')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="wrap_number" :header="pt('Wrap #')" sortable />
      <Column field="wrap_label" :header="pt('Wrapping')" sortable>
        <template #body="{ data }">
          <div>{{ data.wrap_label }}</div>
          <small class="muted">{{ data.charge > 0 ? formatCurrency(data.charge) : pt('free') }}</small>
        </template>
      </Column>
      <Column field="recipient_name" :header="pt('For')" sortable>
        <template #body="{ data }">
          <div>{{ data.recipient_name || '—' }}</div>
          <small class="muted">{{ data.customer }}</small>
        </template>
      </Column>
      <Column field="promised_at" :header="pt('Promised')" sortable>
        <template #body="{ data }">
          <span :class="{ late: data.is_late }">{{ promisedLabel(data) }}</span>
        </template>
      </Column>
      <Column :header="pt('Gift receipt')" style="width: 8rem">
        <template #body="{ data }">
          <i v-if="data.hide_prices" class="pi pi-eye-slash" :title="pt('Prices hidden')" />
          <span v-else class="muted">{{ pt('Prices shown') }}</span>
        </template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 14rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.status === 'pending'"
            icon="pi pi-check"
            text
            rounded
            severity="success"
            :title="pt('Wrapped')"
            @click="act(data, 'wrap')"
          />
          <Button
            v-if="canEdit && (data.status === 'pending' || data.status === 'wrapped')"
            icon="pi pi-shopping-bag"
            text
            rounded
            :title="pt('Collected')"
            @click="act(data, 'collect')"
          />
          <Button icon="pi pi-envelope" text rounded :title="pt('Gift card')" @click="openCard(data)" />
          <Button
            v-if="canEdit && data.status === 'pending'"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && data.status !== 'collected' && data.status !== 'cancelled'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="cancel(data)"
          />
          <Button
            v-if="canDelete && !data.material_posted && data.status === 'cancelled'"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing at the desk. Taking a parcel in gives it a number, a promised time and a place in the queue.') }}
        </div>
      </template>
    </DataTable>

    <!-- Take in / edit -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Edit Parcel') : pt('Take Parcel In')"
      :style="{ width: '760px' }"
    >
      <Message severity="info" :closable="false" class="mb">
        {{ pt('The wrapping charge is billed at the till as an ordinary line, so ring it up with the sale. This screen only records the parcel.') }}
      </Message>

      <div class="grid-form">
        <div class="fld">
          <label>{{ pt('Wrapping') }} *</label>
          <Select
            v-model="form.wrap_option_id"
            :options="options"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('What is it being wrapped in?')"
            @change="onOptionPicked"
          />
          <small v-if="chargeQuote > 0" class="hint">
            {{ pt('Charge') }}: {{ formatCurrency(chargeQuote) }}
          </small>
          <small v-if="errors.wrap_option_id" class="err">{{ errors.wrap_option_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Promised for') }}</label>
          <DatePicker v-model="form.promised_at" show-time hour-format="24" date-format="dd/mm/yy" show-icon class="w-full" />
          <small class="hint">{{ pt('Defaults to the wrapping’s own turnaround. A parcel with no promised time is the one that gets forgotten.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            showClear
            class="w-full"
            :placeholder="pt('Who is buying it?')"
          />
        </div>
        <div v-if="occasions.length" class="fld">
          <label>{{ pt('For which occasion') }}</label>
          <Select
            v-model="form.occasion_id"
            :options="occasions"
            option-label="label"
            option-value="value"
            filter
            showClear
            class="w-full"
            :placeholder="pt('From the occasion board')"
            @change="onOccasionPicked"
          />
        </div>

        <div class="fld">
          <label>{{ pt('For (recipient)') }}</label>
          <InputText v-model="form.recipient_name" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Recipient phone') }}</label>
          <InputText v-model="form.recipient_phone" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('From (sender)') }}</label>
          <InputText v-model="form.sender_name" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Hide prices (gift receipt)') }}</label>
          <div class="switch-row">
            <ToggleSwitch v-model="form.hide_prices" />
            <small class="hint">{{ pt('On by default — the person opening it should not see what it cost.') }}</small>
          </div>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Message on the card') }}</label>
          <Textarea v-model="form.message" rows="3" class="w-full" :maxlength="500" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes for the bench') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="formVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- The card -->
    <Dialog v-model:visible="cardVisible" modal :header="pt('Gift Card')" :style="{ width: '560px' }">
      <div v-if="detail">
        <div class="detail-strip">
          <Tag :value="detail.wrap_number" severity="secondary" />
          <Tag :value="detail.wrap_label" />
          <Tag v-if="detail.hide_prices" :value="pt('Prices hidden')" severity="info" />
        </div>
        <div class="card-preview">
          <div class="cp-to">{{ pt('To') }}: {{ detail.recipient_name || '—' }}</div>
          <div class="cp-msg">{{ detail.message || pt('(no message)') }}</div>
          <div class="cp-from">{{ pt('From') }}: {{ detail.sender_name || '—' }}</div>
        </div>
        <div class="mt small">
          <div v-if="detail.material">{{ pt('Material') }}: {{ detail.material }}</div>
          <div v-if="detail.invoice_number">{{ pt('Billed on') }}: {{ detail.invoice_number }}</div>
          <div v-if="detail.occasion">
            {{ pt('Occasion') }}: {{ detail.occasion.person }} — {{ detail.occasion.label }}
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="cardVisible = false" />
        <Button :label="pt('Print Card')" icon="pi pi-print" @click="printCard" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.switch-row { display: flex; gap: 0.6rem; align-items: center; }

.detail-strip { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.8rem; }
.card-preview { border: 1px solid #d4b483; border-radius: 8px; padding: 1.2rem; font-family: Georgia, serif; }
.cp-to { font-size: 0.95rem; margin-bottom: 0.6rem; }
.cp-msg { white-space: pre-wrap; line-height: 1.6; margin: 0.8rem 0; }
.cp-from { text-align: right; }

.muted { color: #6b7280; }
.late { color: #b91c1c; font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.small { font-size: 0.8rem; color: #374151; }
.mb { margin-bottom: 0.9rem; }
.mt { margin-top: 0.8rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
