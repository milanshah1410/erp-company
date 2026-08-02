<script setup lang="ts">
/**
 * The collection desk.
 *
 * A worklist, not a register. It shows only pairs that are ready and still on the
 * shelf, longest-waiting first — "ready eleven days, nobody rung" is the number
 * this screen exists to make visible, and no shop discovers it on its own.
 * Delivered pairs are history and live on the spectacle-order screen.
 *
 * Handing over is one act with three consequences: the order closes, the invoice
 * is raised, and the cover its frame and lens carry is granted. The dialog says
 * so before it is confirmed, because a counter hand should not learn afterwards
 * that they billed a customer.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canWork = computed(() => auth.can('optical-orders.edit'))

const MODES = [
  { label: 'Collected at the counter', value: 'counter' },
  { label: 'Delivered to the address', value: 'home' },
  { label: 'Sent by courier', value: 'courier' },
]

const rows = ref<any[]>([])
const summary = ref({ ready: 0, never_told: 0, waiting_over_week: 0, at_lab: 0, handed_over_today: 0 })
const loading = ref(false)
const search = ref('')
const neverTold = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/optical/delivery/board', {
      params: {
        search: search.value || undefined,
        never_told: neverTold.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function notify(row: any) {
  try {
    const { data } = await http.post(`/optical/delivery/${row.id}/notify`)
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not send the message'), life: 4000 })
  }
}

// ---- Handing over ----------------------------------------------------------

const handoverVisible = ref(false)
const handing = ref<any>(null)
const quote = ref<any>(null)
const saving = ref(false)
const form = ref({
  delivery_mode: 'counter',
  delivery_address: '',
  delivery_reference: '',
  collected_by_name: '',
  collected_by_phone: '',
  handover_notes: '',
})

async function openHandover(row: any) {
  handing.value = row
  quote.value = null
  form.value = {
    delivery_mode: 'counter',
    delivery_address: row.delivery_address ?? '',
    delivery_reference: '',
    // The common case pre-filled, so a blank field means somebody deliberately
    // changed it rather than that nobody was asked.
    collected_by_name: row.customer?.name ?? '',
    collected_by_phone: row.customer?.phone ?? '',
    handover_notes: '',
  }
  handoverVisible.value = true

  // What the shop is about to promise, said out loud before it promises it.
  try {
    const { data } = await http.get(`/optical/delivery/${row.id}/warranty-quote`)
    quote.value = data.data
  } catch {
    quote.value = null
  }
}

const sendingOut = computed(() => form.value.delivery_mode !== 'counter')

async function confirmHandover() {
  saving.value = true
  try {
    const { data } = await http.post(`/optical/delivery/${handing.value.id}/hand-over`, form.value)
    handoverVisible.value = false
    await load()
    toast.add({
      severity: 'success',
      summary: data.message,
      detail: [
        data.invoice_number ? `${pt('Invoice')} ${data.invoice_number}` : null,
        data.warranties ? pt('{n} warranty card(s) issued', { n: data.warranties }) : null,
      ]
        .filter(Boolean)
        .join(' · '),
      life: 5000,
    })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not hand it over'), life: 4000 })
  } finally {
    saving.value = false
  }
}

function readyLabel(row: any) {
  if (row.days_ready === null || row.days_ready === undefined) return pt('Ready')
  if (row.days_ready < 1) return pt('Ready today')
  return pt('{n} day(s) on the shelf', { n: Math.round(row.days_ready) })
}

function toldLabel(row: any) {
  if (!row.notified_at) return { label: pt('Never told'), severity: 'danger' }
  const when = new Date(row.notified_at).toLocaleDateString()
  return {
    label: row.notify_count > 1 ? `${when} (×${row.notify_count})` : when,
    severity: 'success',
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Collection Desk')"
      :subtitle="pt('What is ready, who has been told, and who took it')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Ready to collect') }}</span>
        <span class="t-value">{{ summary.ready }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Nobody told') }}</span>
        <span class="t-value" :class="{ danger: summary.never_told > 0 }">{{ summary.never_told }}</span>
        <small class="muted">{{ pt('ready, and no message sent') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Waiting over a week') }}</span>
        <span class="t-value" :class="{ warn: summary.waiting_over_week > 0 }">{{ summary.waiting_over_week }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Still at the lab') }}</span>
        <span class="t-value">{{ summary.at_lab }}</span>
        <small class="muted">{{ summary.handed_over_today }} {{ pt('handed over today') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Order no, customer or phone')" @keyup.enter="load" />
      <label class="chk">
        <Checkbox v-model="neverTold" binary @change="load" />
        <span>{{ pt('Only those nobody has told') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons
        title="Collection Desk"
        filenameBase="optical-collection"
        :rows="rows"
        :columns="[
          { key: 'order_number', label: 'Order' },
          { key: 'frame', label: 'Frame' },
          { key: 'lens', label: 'Lens' },
          { key: 'fitted_at', label: 'Ready since' },
          { key: 'notified_at', label: 'Told' },
          { key: 'total', label: 'Total' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" stripedRows>
      <Column :header="pt('Order')">
        <template #body="{ data }">
          <strong>{{ data.order_number }}</strong>
          <br /><small class="muted">{{ readyLabel(data) }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">
          {{ data.customer?.name ?? '—' }}
          <br /><small class="muted">{{ data.customer?.phone ?? pt('no phone on record') }}</small>
        </template>
      </Column>
      <Column :header="pt('The pair')">
        <template #body="{ data }">
          <div>{{ data.frame || pt('No frame') }}</div>
          <small class="muted">{{ data.lens || pt('No lens') }}</small>
        </template>
      </Column>
      <Column :header="pt('Told')" style="width: 9rem">
        <template #body="{ data }">
          <Tag :value="toldLabel(data).label" :severity="toldLabel(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Promised')" style="width: 8rem">
        <template #body="{ data }">
          <span :class="{ late: data.is_overdue }">{{ data.promised_on ?? '—' }}</span>
        </template>
      </Column>
      <Column :header="pt('Total')" style="width: 8rem">
        <template #body="{ data }">{{ formatCurrency(data.total) }}</template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button
            v-if="canWork"
            icon="pi pi-send"
            text
            rounded
            :title="pt('Tell the customer it is ready')"
            @click="notify(data)"
          />
          <Button
            v-if="canWork"
            :label="pt('Hand Over')"
            icon="pi pi-check"
            size="small"
            @click="openHandover(data)"
          />
        </template>
      </Column>
      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing is waiting to be collected. Pairs appear here once the lenses are fitted into the frame.') }}
        </div>
      </template>
    </DataTable>

    <!-- Handing over -->
    <Dialog
      v-model:visible="handoverVisible"
      modal
      :header="pt('Hand Over')"
      :style="{ width: '40rem' }"
    >
      <Message severity="warn" :closable="false" class="mb">
        {{ pt('This closes the order and raises the invoice. It cannot be undone from here — an invoiced pair is corrected with a credit note.') }}
      </Message>

      <div v-if="handing" class="head">
        <div><strong>{{ handing.order_number }}</strong> — {{ handing.customer?.name }}</div>
        <div class="muted small">{{ handing.frame }} · {{ handing.lens }} · {{ formatCurrency(handing.total) }}</div>
      </div>

      <!-- What the shop is about to promise. -->
      <div v-if="quote && (quote.frame || quote.lens)" class="cover">
        <div class="cover-title">{{ pt('Cover granted on handover') }}</div>
        <div v-if="quote.frame">
          <i class="pi pi-check-circle" /> {{ quote.frame.product }} — {{ quote.frame.months }} {{ pt('months') }}
        </div>
        <div v-if="quote.lens">
          <i class="pi pi-check-circle" /> {{ quote.lens.product }} — {{ quote.lens.months }} {{ pt('months') }}
        </div>
      </div>
      <div v-else-if="quote" class="cover muted">
        {{ pt('Neither the frame nor the lens carries a warranty policy, so no card will be issued.') }}
      </div>

      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('How it leaves the shop') }}</label>
          <Select v-model="form.delivery_mode" :options="MODES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div v-if="sendingOut" class="fld span-2">
          <label>{{ pt('Address') }} *</label>
          <Textarea v-model="form.delivery_address" rows="2" class="w-full" />
        </div>
        <div v-if="form.delivery_mode === 'courier'" class="fld span-2">
          <label>{{ pt('Docket / consignment number') }}</label>
          <InputText v-model="form.delivery_reference" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Collected by') }}</label>
          <InputText v-model="form.collected_by_name" class="w-full" />
          <small class="hint">{{ pt('Often not the patient — a son, a driver, a neighbour.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Their phone') }}</label>
          <InputText v-model="form.collected_by_phone" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.handover_notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="handoverVisible = false" />
        <Button :label="pt('Hand Over & Bill')" icon="pi pi-check" :loading="saving" @click="confirmHandover" />
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
.t-value.warn { color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.head { margin-bottom: 0.8rem; }
.cover { border: 1px solid #cfe6d4; background: #f2fbf4; border-radius: 8px; padding: 0.6rem 0.8rem; font-size: 0.82rem; margin-bottom: 0.9rem; }
.cover.muted { border-color: #e5e7eb; background: #f9fafb; color: #6b7280; }
.cover-title { font-weight: 600; margin-bottom: 0.3rem; }

.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.muted { color: #6b7280; }
.small { font-size: 0.8rem; }
.late { color: #b91c1c; font-weight: 600; }
.mb { margin-bottom: 0.9rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
