<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useRoute } from 'vue-router'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const route = useRoute()

const canEdit = computed(() => auth.can('restaurant-orders.edit'))
const canSettle = computed(() => auth.can('restaurant-orders.delete'))

const rows = ref<any[]>([])
const summary = ref({ open: 0, open_value: 0, settled_today: 0, sales_today: 0 })
const loading = ref(false)
const openOnly = ref(true)
const search = ref('')

const statusColors: Record<string, string> = {
  open: 'info',
  settled: 'success',
  cancelled: 'danger',
}
const itemColors: Record<string, string> = {
  pending: 'secondary',
  sent: 'warn',
  served: 'success',
  void: 'danger',
}

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/restaurant-orders', {
      params: {
        search: search.value || undefined,
        open_only: openOnly.value ? 1 : undefined,
        per_page: 50,
      },
    })
    rows.value = data.data
    if (data.summary) summary.value = data.summary
  } finally {
    loading.value = false
  }
}

// ---- The ticket ------------------------------------------------------------------

const ticketVisible = ref(false)
const ticket = ref<any>(null)
const menu = ref<any[]>([])
const busy = ref(false)

/**
 * Parcel / delivery fields (Food & Beverage plugin).
 *
 * Held in their own form object rather than bound straight to `ticket`, because
 * the ticket is re-read from the server after every line change — binding to it
 * would wipe a half-typed address the moment a waiter added a dish.
 */
const charges = ref<any>({
  packaging_charge: 0,
  delivery_charge: 0,
  delivery_address: '',
  delivery_phone: '',
  rider_id: null,
})
const riders = ref<any[]>([])

async function loadRiders() {
  const { data } = await http.get('/employees', { params: { per_page: 200, is_active: 1 } })
  riders.value = (data.data ?? data).map((e: any) => ({
    label: e.name ?? `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim(),
    value: e.id,
  }))
}

async function saveCharges() {
  if (!ticket.value) return
  busy.value = true
  try {
    const { data } = await http.put(`/restaurant-orders/${ticket.value.id}`, charges.value)
    ticket.value = data.data
    await load()
    toast.add({ severity: 'success', summary: pt('Charges updated'), life: 2000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not update the ticket'),
      life: 3000,
    })
  } finally {
    busy.value = false
  }
}

async function openTicket(id: number) {
  ticketVisible.value = true
  const { data } = await http.get(`/restaurant-orders/${id}`)
  ticket.value = data.data
  charges.value = {
    packaging_charge: Number(ticket.value.packaging_charge) || 0,
    delivery_charge: Number(ticket.value.delivery_charge) || 0,
    delivery_address: ticket.value.delivery_address ?? '',
    delivery_phone: ticket.value.delivery_phone ?? '',
    rider_id: ticket.value.rider_id ?? null,
  }
  if (!menu.value.length) await loadMenu()
  if (ticket.value.order_type === 'delivery' && !riders.value.length) await loadRiders()
}

async function loadMenu() {
  const { data } = await http.get('/products', { params: { per_page: 300, is_active: true } })
  menu.value = (data.data ?? data).map((x: any) => ({
    label: x.name,
    value: x.id,
    price: Number(x.selling_price) || 0,
    station: x.kitchen_station,
  }))
}

const newLine = ref({ product_id: null as number | null, quantity: 1, instructions: '' })

async function addLine() {
  if (!newLine.value.product_id || !ticket.value) return
  busy.value = true
  try {
    const { data } = await http.post(`/restaurant-orders/${ticket.value.id}/items`, {
      items: [{ ...newLine.value }],
    })
    ticket.value = data.data
    newLine.value = { product_id: null, quantity: 1, instructions: '' }
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not add the dish'), life: 4000 })
  } finally {
    busy.value = false
  }
}

async function removeLine(item: any) {
  try {
    const { data } = await http.delete(`/restaurant-orders/${ticket.value.id}/items/${item.id}`)
    ticket.value = data.data
    await load()
  } catch (e: any) {
    toast.add({ severity: 'warn', summary: e.response?.data?.message ?? pt('Could not remove the dish'), life: 4000 })
  }
}

/** A cooked dish leaves the bill only with a reason attached. */
async function voidLine(item: any) {
  const { value: reason } = await Swal.fire({
    title: pt('Void this dish?'),
    input: 'text',
    inputLabel: pt('Reason'),
    inputPlaceholder: pt('e.g. sent back, wrong order'),
    showCancelButton: true,
    confirmButtonText: pt('Void'),
    cancelButtonText: pt('Cancel'),
    inputValidator: (v) => (!v ? pt('A reason is required.') : null),
  })
  if (!reason) return
  try {
    const { data } = await http.post(
      `/restaurant-orders/${ticket.value.id}/items/${item.id}/void`,
      { reason },
    )
    ticket.value = data.data
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not void the dish'), life: 4000 })
  }
}

async function fire() {
  busy.value = true
  try {
    const { data } = await http.post(`/restaurant-orders/${ticket.value.id}/fire`)
    ticket.value = data.data
    const n = data.tickets?.length ?? 0
    toast.add({
      severity: n ? 'success' : 'info',
      summary: n ? pt('{n} KOT(s) fired', { n }) : pt('Nothing new to fire'),
      detail: (data.tickets ?? []).map((t: any) => `${t.kot_number} · ${t.station}`).join(', '),
      life: 3500,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not fire'), life: 4000 })
  } finally {
    busy.value = false
  }
}

async function settle() {
  const ok = await Swal.fire({
    title: pt('Settle this table?'),
    text: pt('The bill is raised and the table is freed for cleaning.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Settle'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    const { data } = await http.post(`/restaurant-orders/${ticket.value.id}/settle`)
    toast.add({
      severity: 'success',
      summary: pt('Settled'),
      detail: `${data.invoice.invoice_number} · ${money(data.invoice.total)}`,
      life: 4000,
    })
    ticketVisible.value = false
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.order?.[0] ?? e.response?.data?.message ?? pt('Could not settle'),
      life: 4000,
    })
  }
}

async function cancelTicket() {
  const ok = await Swal.fire({
    title: pt('Cancel this ticket?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Yes'),
    cancelButtonText: pt('No'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.post(`/restaurant-orders/${ticket.value.id}/cancel`, {})
    ticketVisible.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not cancel'), life: 4000 })
  }
}

onMounted(async () => {
  await load()
  // The floor screen links straight into a ticket.
  if (route.query.order) await openTicket(Number(route.query.order))
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Restaurant Orders')"
      :subtitle="pt('Open tickets — dishes are added in rounds and fired to the kitchen as they are ordered')"
    />

    <div class="tiles">
      <div class="tile"><span class="t-label">{{ pt('Open tickets') }}</span><strong class="t-value">{{ summary.open }}</strong></div>
      <div class="tile accent"><span class="t-label">{{ pt('On the floor') }}</span><strong class="t-value">{{ money(summary.open_value) }}</strong></div>
      <div class="tile"><span class="t-label">{{ pt('Settled today') }}</span><strong class="t-value">{{ summary.settled_today }}</strong></div>
      <div class="tile"><span class="t-label">{{ pt('Sales today') }}</span><strong class="t-value">{{ money(summary.sales_today) }}</strong></div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Order number')" @keyup.enter="load" />
      <label class="inline-check">
        <input v-model="openOnly" type="checkbox" @change="load" /> {{ pt('Open only') }}
      </label>
      <Button :label="pt('Refresh')" icon="pi pi-refresh" outlined @click="load" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Ticket')">
        <template #body="{ data }">
          <a class="link" @click="openTicket(data.id)">{{ data.order_number }}</a>
          <br /><small class="muted">{{ data.order_type }}</small>
        </template>
      </Column>
      <Column :header="pt('Table')">
        <template #body="{ data }">{{ data.table ?? '—' }}</template>
      </Column>
      <Column :header="pt('Guests')">
        <template #body="{ data }">{{ data.guest_count }}</template>
      </Column>
      <Column :header="pt('Waiter')">
        <template #body="{ data }">{{ data.waiter ?? '—' }}</template>
      </Column>
      <Column :header="pt('Seated')">
        <template #body="{ data }">{{ data.seated_minutes !== null ? data.seated_minutes + 'm' : '—' }}</template>
      </Column>
      <Column :header="pt('Total')">
        <template #body="{ data }">
          {{ money(data.total) }}
          <br v-if="data.invoice" /><small v-if="data.invoice" class="muted">{{ data.invoice }}</small>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusColors[data.status]" /></template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button icon="pi pi-external-link" text size="small" @click="openTicket(data.id)" />
        </template>
      </Column>
      <template #empty>{{ pt('No tickets match this view.') }}</template>
    </DataTable>

    <!-- The ticket -->
    <Dialog
      v-model:visible="ticketVisible"
      modal
      :header="ticket ? `${ticket.order_number}${ticket.table ? ' · ' + ticket.table : ''}` : pt('Ticket')"
      :style="{ width: '46rem' }"
    >
      <template v-if="ticket">
        <!--
          Parcel / delivery (Food & Beverage plugin). Only shown for the ticket
          types it applies to: a dine-in table has no address and no rider, and
          rendering empty fields for it would just be noise on a busy screen.
        -->
        <div v-if="ticket.order_type !== 'dine_in'" class="delivery-panel">
          <div class="dp-head">
            <i :class="ticket.order_type === 'delivery' ? 'pi pi-send' : 'pi pi-shopping-bag'" />
            <strong>{{ ticket.order_type === 'delivery' ? pt('Delivery') : pt('Parcel') }}</strong>
          </div>

          <div class="dp-grid">
            <div class="fld">
              <label>{{ pt('Packaging charge') }}</label>
              <InputNumber
                v-model="charges.packaging_charge"
                :min="0"
                :disabled="!canEdit || ticket.status !== 'open'"
                class="w-full"
              />
            </div>

            <template v-if="ticket.order_type === 'delivery'">
              <div class="fld">
                <label>{{ pt('Delivery charge') }}</label>
                <InputNumber
                  v-model="charges.delivery_charge"
                  :min="0"
                  :disabled="!canEdit || ticket.status !== 'open'"
                  class="w-full"
                />
              </div>
              <div class="fld">
                <label>{{ pt('Rider') }}</label>
                <Select
                  v-model="charges.rider_id"
                  :options="riders"
                  optionLabel="label"
                  optionValue="value"
                  filter
                  show-clear
                  :disabled="!canEdit || ticket.status !== 'open'"
                  :placeholder="pt('Not assigned')"
                  class="w-full"
                />
              </div>
              <div class="fld dp-wide">
                <label>{{ pt('Address') }}</label>
                <Textarea
                  v-model="charges.delivery_address"
                  rows="2"
                  auto-resize
                  :disabled="!canEdit || ticket.status !== 'open'"
                  class="w-full"
                />
              </div>
              <div class="fld">
                <label>{{ pt('Phone') }}</label>
                <InputText
                  v-model="charges.delivery_phone"
                  :disabled="!canEdit || ticket.status !== 'open'"
                  class="w-full"
                />
              </div>
            </template>

            <div v-if="canEdit && ticket.status === 'open'" class="fld dp-save">
              <label>&nbsp;</label>
              <Button
                :label="pt('Save charges')"
                icon="pi pi-check"
                size="small"
                outlined
                :loading="busy"
                @click="saveCharges"
              />
            </div>
          </div>
        </div>

        <div v-if="canEdit && ticket.status === 'open'" class="add-row">
          <Select
            v-model="newLine.product_id"
            :options="menu"
            optionLabel="label"
            optionValue="value"
            filter
            :placeholder="pt('Add a dish')"
            class="al-dish"
          />
          <InputNumber v-model="newLine.quantity" :min="1" :max="99" showButtons class="al-qty" />
          <InputText v-model="newLine.instructions" :placeholder="pt('less spicy…')" class="al-note" />
          <Button icon="pi pi-plus" :loading="busy" :disabled="!newLine.product_id" @click="addLine" />
        </div>

        <table class="lines">
          <thead>
            <tr>
              <th>{{ pt('Dish') }}</th>
              <th>{{ pt('Qty') }}</th>
              <th>{{ pt('Amount') }}</th>
              <th>{{ pt('Status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in ticket.items" :key="i.id" :class="{ voided: i.status === 'void' }">
              <td>
                {{ i.name }}
                <small v-if="i.instructions" class="instr">{{ i.instructions }}</small>
                <small v-if="i.void_reason" class="instr void">{{ i.void_reason }}</small>
              </td>
              <td>{{ i.quantity }}</td>
              <td>{{ money(i.line_total) }}</td>
              <td><Tag :value="i.status" :severity="itemColors[i.status]" /></td>
              <td class="act">
                <Button
                  v-if="canEdit && i.is_editable"
                  icon="pi pi-times"
                  text
                  size="small"
                  severity="danger"
                  :title="pt('Remove')"
                  @click="removeLine(i)"
                />
                <Button
                  v-else-if="canSettle && i.status !== 'void' && ticket.status === 'open'"
                  icon="pi pi-ban"
                  text
                  size="small"
                  severity="danger"
                  :title="pt('Void')"
                  @click="voidLine(i)"
                />
              </td>
            </tr>
            <tr v-if="!ticket.items?.length">
              <td colspan="5" class="empty-row">{{ pt('Nothing ordered yet.') }}</td>
            </tr>
          </tbody>
        </table>

        <div class="totals">
          <div><span>{{ pt('Subtotal') }}</span><span>{{ money(ticket.subtotal) }}</span></div>
          <div v-if="ticket.discount_amount"><span>{{ pt('Discount') }}</span><span>−{{ money(ticket.discount_amount) }}</span></div>
          <div v-if="ticket.service_charge">
            <span>{{ pt('Service charge') }} ({{ ticket.service_charge_percent }}%)</span>
            <span>{{ money(ticket.service_charge) }}</span>
          </div>
          <div v-if="ticket.packaging_charge">
            <span>{{ pt('Packaging') }}</span><span>{{ money(ticket.packaging_charge) }}</span>
          </div>
          <div v-if="ticket.delivery_charge">
            <span>{{ pt('Delivery') }}</span><span>{{ money(ticket.delivery_charge) }}</span>
          </div>
          <div><span>{{ pt('Tax') }}</span><span>{{ money(ticket.tax_amount) }}</span></div>
          <div v-if="ticket.round_off"><span>{{ pt('Round off') }}</span><span>{{ money(ticket.round_off) }}</span></div>
          <div class="grand"><span>{{ pt('Total') }}</span><span>{{ money(ticket.total) }}</span></div>
        </div>

        <div v-if="ticket.tickets?.length" class="kots">
          <span class="kot-label">{{ pt('KOTs') }}:</span>
          <Tag v-for="k in ticket.tickets" :key="k.id" :value="`${k.kot_number} · ${k.station}`" severity="secondary" />
        </div>
      </template>

      <template #footer>
        <Button :label="pt('Close')" text @click="ticketVisible = false" />
        <template v-if="ticket?.status === 'open'">
          <Button
            v-if="canSettle"
            :label="pt('Cancel ticket')"
            text
            severity="danger"
            @click="cancelTicket"
          />
          <Button v-if="canEdit" :label="pt('Fire to kitchen')" icon="pi pi-send" outlined :loading="busy" @click="fire" />
          <Button v-if="canSettle" :label="pt('Settle')" icon="pi pi-check" @click="settle" />
        </template>
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
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.link { color: #2c4a5e; font-weight: 600; cursor: pointer; text-decoration: underline; }
/* Parcel / delivery panel (Food & Beverage plugin). */
.delivery-panel {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.7rem 0.85rem;
  margin-bottom: 0.9rem;
  background: rgba(44, 74, 94, 0.04);
}
.dp-head { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; margin-bottom: 0.6rem; }
.dp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.6rem; }
.dp-wide { grid-column: 1 / -1; }
.dp-save { justify-content: flex-end; }
.fld { display: flex; flex-direction: column; gap: 0.25rem; }
.fld label { font-size: 0.72rem; color: #374151; }
.w-full { width: 100%; }

.add-row { display: flex; gap: 0.4rem; align-items: center; margin-bottom: 0.8rem; flex-wrap: wrap; }
.al-dish { flex: 1 1 14rem; min-width: 10rem; }
.al-qty { width: 7rem; }
.al-note { flex: 1 1 8rem; min-width: 7rem; }
.lines { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.lines th { text-align: left; font-size: 0.72rem; color: #6b7280; padding: 0.3rem 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem 0.4rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.lines tr.voided td { opacity: 0.55; text-decoration: line-through; }
.lines tr.voided td:last-child { text-decoration: none; }
.instr { display: block; font-size: 0.7rem; color: #7a5b1c; }
.instr.void { color: #b91c1c; text-decoration: none; }
.act { text-align: right; }
.empty-row { color: #6b7280; text-align: center; padding: 1rem; }
.totals { margin-top: 0.9rem; font-size: 0.88rem; }
.totals div { display: flex; justify-content: space-between; padding: 0.12rem 0; }
.totals .grand { font-size: 1.05rem; font-weight: 700; border-top: 1px solid #e5e7eb; margin-top: 0.3rem; padding-top: 0.4rem; }
.kots { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.9rem; }
.kot-label { font-size: 0.75rem; color: #6b7280; }
</style>
