<script setup lang="ts">
/**
 * The self-checkout bank, seen from the supervisor's stool.
 *
 * One person covers six lanes, so the screen is ordered by what has *stopped*
 * rather than by lane number: a basket waiting on an age check is the only thing
 * on this screen that needs a human, and it must be the first thing on it. The
 * board polls, because the supervisor is not going to press refresh while a queue
 * builds.
 *
 * What this screen deliberately cannot do is take money. The lane builds a basket;
 * the ordinary POS checkout bills it and the cart records which invoice it became.
 * There is exactly one path from goods to money in this product, and adding a
 * second one here would give a supermarket two checkouts that price, tax and post
 * stock almost the same way.
 *
 * "Send to till" therefore hands the basket to the POS screen with its lines
 * pre-loaded, rather than settling it here.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()

const carts = ref<any[]>([])
const summary = ref({ live: 0, awaiting_approval: 0, baskets_value: 0 })
const lanes = ref<any[]>([])
const laneFilter = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)

const canApprove = computed(() => auth.can('self-checkout.approve'))
const canScan = computed(() => auth.can('self-checkout.create'))
const canBill = computed(() => auth.can('sales.create'))

let poll: number | undefined

async function loadLanes() {
  const { data } = await http.get('/pos-counters', { params: { mode: 'self_checkout' } })
  lanes.value = data.data
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/self-checkout/carts', {
      params: { counter_id: laneFilter.value || undefined },
    })
    carts.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadLanes(), load()])
  // Every ten seconds. Frequent enough that a stopped basket is noticed before
  // the customer looks round for someone, cheap enough that a shift-long window
  // left open costs nothing.
  poll = window.setInterval(load, 10000)
})

onUnmounted(() => {
  if (poll) window.clearInterval(poll)
})

// --- Actions -----------------------------------------------------------------

async function approve(cart: any) {
  saving.value = true
  try {
    await http.post(`/self-checkout/carts/${cart.id}/approve`)
    await load()
    if (detail.value?.id === cart.id) await openDetail(cart.id)
    toast.add({ severity: 'success', summary: pt('Basket cleared'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not clear the basket'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

async function voidLine(cart: any, item: any) {
  saving.value = true
  try {
    await http.post(`/self-checkout/carts/${cart.id}/items/${item.id}/void`)
    await load()
    await openDetail(cart.id)
    toast.add({ severity: 'success', summary: pt('Line removed'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not remove the line'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

async function abandon(cart: any) {
  saving.value = true
  try {
    await http.post(`/self-checkout/carts/${cart.id}/abandon`)
    detailVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Basket cleared down'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not clear the basket'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

/**
 * Hand the basket to the till.
 *
 * The cart's own `checkout_payload` is what goes across, so the shape of a POS
 * cart is stated in exactly one place on the server rather than reassembled here.
 * The POS screen bills it and posts back to `/complete`.
 */
function sendToTill(cart: any) {
  sessionStorage.setItem('pos.self_checkout_cart', JSON.stringify({ cart_id: cart.id, ...cart.checkout_payload }))
  router.push('/pos')
}

// --- Detail ------------------------------------------------------------------

const detailVisible = ref(false)
const detail = ref<any>(null)

async function openDetail(id: number) {
  const { data } = await http.get(`/self-checkout/carts/${id}`)
  detail.value = data.data
  detailVisible.value = true
}

// --- A lane, for testing the bank without a kiosk ------------------------------

const laneVisible = ref(false)
const laneCart = ref<any>(null)
const scanCode = ref('')

/**
 * Opens a basket from this screen and scans into it.
 *
 * A convenience for setting the bank up and proving a lane works, not the real
 * lane UI — a real lane runs this same API on a kiosk under its own thin login,
 * which is exactly the point of `self-checkout.create` being separate from
 * `.approve`.
 */
async function startBasket(laneId: number) {
  saving.value = true
  try {
    const { data } = await http.post('/self-checkout/carts', { counter_id: laneId })
    laneCart.value = data.data
    scanCode.value = ''
    laneVisible.value = true
    await load()
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not start a basket'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

async function scan() {
  if (!scanCode.value || !laneCart.value) return
  try {
    const { data } = await http.post(`/self-checkout/carts/${laneCart.value.id}/scan`, { code: scanCode.value })
    laneCart.value = data.data
    scanCode.value = ''
    await load()
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'warn',
      summary: pt('Cannot scan that'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 4000,
    })
    scanCode.value = ''
  }
}

// --- Presentation -------------------------------------------------------------

function statusSeverity(status: string) {
  return status === 'awaiting_approval' ? 'danger' : status === 'ready' ? 'success' : 'info'
}

function statusLabel(status: string) {
  return status === 'awaiting_approval'
    ? pt('Waiting for an assistant')
    : status === 'ready'
      ? pt('Cleared — ready to pay')
      : status === 'scanning'
        ? pt('Scanning')
        : status === 'completed'
          ? pt('Billed')
          : pt('Abandoned')
}

const laneOptions = computed(() => lanes.value.map((l) => ({ label: l.name, value: l.id })))
const openLanes = computed(() => lanes.value.filter((l) => l.is_open))
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Self-Checkout')"
      :subtitle="pt('The lane bank — baskets waiting for an assistant come first')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Lanes open') }}</span>
        <span class="t-value">{{ openLanes.length }} / {{ lanes.length }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Live baskets') }}</span>
        <span class="t-value">{{ summary.live }}</span>
      </div>
      <div class="tile" :class="{ danger: summary.awaiting_approval > 0 }">
        <span class="t-label">{{ pt('Waiting on you') }}</span>
        <span class="t-value">{{ summary.awaiting_approval }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value in baskets') }}</span>
        <span class="t-value">{{ summary.baskets_value }}</span>
      </div>
    </div>

    <div v-if="!lanes.length" class="empty-state">
      {{ pt('No self-checkout lanes yet. Create a counter of type "Self-checkout" on the Tills screen.') }}
    </div>

    <div v-else class="toolbar">
      <Select
        v-model="laneFilter"
        :options="laneOptions"
        option-label="label"
        option-value="value"
        :placeholder="pt('All lanes')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
      <span class="muted poll-note">{{ pt('Refreshes itself every 10 seconds') }}</span>
    </div>

    <div v-if="lanes.length" class="lane-strip">
      <div v-for="l in lanes" :key="l.id" class="lane" :class="{ shut: !l.is_open }">
        <span class="l-name">{{ l.name }}</span>
        <span class="l-meta">{{ l.code }}<template v-if="l.location"> · {{ l.location }}</template></span>
        <Tag :severity="l.is_open ? 'success' : 'secondary'" :value="l.is_open ? pt('Open') : pt('Closed')" />
        <Button
          v-if="l.is_open && canScan"
          class="l-action"
          icon="pi pi-plus"
          text
          size="small"
          :label="pt('Start a basket')"
          @click="startBasket(l.id)"
        />
        <small v-else-if="!l.is_open" class="muted">{{ pt('Open the till to use this lane') }}</small>
      </div>
    </div>

    <DataTable :value="carts" :loading="loading" striped-rows responsive-layout="scroll">
      <Column field="status" :header="pt('State')" sortable>
        <template #body="{ data }">
          <Tag :severity="statusSeverity(data.status)" :value="statusLabel(data.status)" />
        </template>
      </Column>
      <Column field="cart_number" :header="pt('Basket')" sortable />
      <Column :header="pt('Lane')" sortable>
        <template #body="{ data }">{{ data.counter?.name }}</template>
      </Column>
      <Column field="item_count" :header="pt('Lines')" sortable />
      <Column field="units" :header="pt('Units')" sortable />
      <Column field="estimated_total" :header="pt('Basket value')" sortable>
        <template #body="{ data }">
          <span>{{ data.estimated_total }}</span>
          <small class="muted tiny">{{ pt('est.') }}</small>
        </template>
      </Column>
      <Column field="pending_approvals" :header="pt('Flagged')" sortable>
        <template #body="{ data }">
          <Tag v-if="data.pending_approvals" severity="danger" :value="String(data.pending_approvals)" />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="min-width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text :label="pt('View')" @click="openDetail(data.id)" />
          <Button
            v-if="data.pending_approvals && canApprove"
            icon="pi pi-check"
            text
            severity="success"
            :label="pt('Approve')"
            :loading="saving"
            @click="approve(data)"
          />
          <Button
            v-if="!data.pending_approvals && data.item_count && canBill"
            icon="pi pi-send"
            text
            :label="pt('Send to Till')"
            @click="sendToTill(data)"
          />
        </template>
      </Column>
    </DataTable>

    <div v-if="lanes.length && !carts.length && !loading" class="empty-state">
      {{ pt('No baskets on the lanes right now.') }}
    </div>

    <!-- ================= Basket detail ================= -->
    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? `${pt('Basket')} ${detail.cart_number}` : pt('Basket')"
      :style="{ width: '34rem' }"
    >
      <div v-if="detail" class="form">
        <div class="detail-head">
          <Tag :severity="statusSeverity(detail.status)" :value="statusLabel(detail.status)" />
          <span class="muted">{{ detail.counter?.name }}</span>
          <span v-if="detail.approved_by" class="muted">
            {{ pt('Cleared by') }} {{ detail.approved_by }} · {{ detail.approved_at }}
          </span>
        </div>

        <DataTable :value="detail.items" size="small">
          <Column field="name" :header="pt('Item')">
            <template #body="{ data }">
              <div class="stack" :class="{ struck: data.is_voided }">
                <strong>{{ data.name }}</strong>
                <small class="muted">{{ data.sku }}</small>
                <small v-if="data.needs_approval" class="flagged">{{ data.approval_reason }}</small>
              </div>
            </template>
          </Column>
          <Column field="quantity" :header="pt('Qty')" style="width: 5rem" />
          <Column field="line_total" :header="pt('Value')" style="width: 6rem" />
          <Column v-if="canApprove" :header="''" style="width: 4rem">
            <template #body="{ data }">
              <Button
                v-if="!data.is_voided && detail.status !== 'completed'"
                icon="pi pi-times"
                text
                severity="danger"
                :loading="saving"
                @click="voidLine(detail, data)"
              />
            </template>
          </Column>
        </DataTable>

        <div class="detail-total">
          <span class="muted">{{ pt('Estimated total — the till re-prices the basket when it bills it') }}</span>
          <strong class="big">{{ detail.estimated_total }}</strong>
        </div>

        <p v-if="detail.invoice_id" class="hint">
          {{ pt('Billed') }} · {{ pt('Invoice') }} #{{ detail.invoice_id }} · {{ detail.completed_at }}
        </p>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
        <Button
          v-if="detail && detail.status !== 'completed' && canApprove"
          :label="pt('Clear Down')"
          severity="danger"
          text
          :loading="saving"
          @click="abandon(detail)"
        />
        <Button
          v-if="detail?.pending_approvals && canApprove"
          :label="pt('Approve')"
          severity="success"
          :loading="saving"
          @click="approve(detail)"
        />
        <Button
          v-if="detail && !detail.pending_approvals && detail.item_count && detail.status !== 'completed' && canBill"
          :label="pt('Send to Till')"
          @click="sendToTill(detail)"
        />
      </template>
    </Dialog>

    <!-- ================= The lane itself ================= -->
    <Dialog
      v-model:visible="laneVisible"
      modal
      :header="laneCart ? `${pt('Lane')} — ${laneCart.cart_number}` : pt('Lane')"
      :style="{ width: '30rem' }"
    >
      <div v-if="laneCart" class="form">
        <p class="hint">
          {{ pt('Scan a barcode, SKU or RFID tag. A restricted item stops the basket until an assistant clears it — which is what the lane cannot do for itself.') }}
        </p>

        <div class="field">
          <label>{{ pt('Scan') }}</label>
          <InputText v-model="scanCode" autofocus :placeholder="pt('Barcode')" @keyup.enter="scan" />
        </div>

        <Tag :severity="statusSeverity(laneCart.status)" :value="statusLabel(laneCart.status)" />

        <DataTable :value="laneCart.items" size="small">
          <Column field="name" :header="pt('Item')">
            <template #body="{ data }">
              <div class="stack" :class="{ struck: data.is_voided }">
                <span>{{ data.name }}</span>
                <small v-if="data.needs_approval" class="flagged">{{ data.approval_reason }}</small>
              </div>
            </template>
          </Column>
          <Column field="quantity" :header="pt('Qty')" style="width: 5rem" />
          <Column field="line_total" :header="pt('Value')" style="width: 6rem" />
        </DataTable>

        <div class="detail-total">
          <span class="muted">{{ pt('Basket total') }}</span>
          <strong class="big">{{ laneCart.estimated_total }}</strong>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Done')" text @click="laneVisible = false" />
        <Button v-if="laneCart" :label="pt('Scan')" :disabled="!scanCode" @click="scan" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.danger { border-color: #dc2626; background: rgba(220, 38, 38, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.poll-note { font-size: 0.7rem; }
.lane-strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.lane { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.7rem 0.8rem; display: flex; flex-direction: column; gap: 0.3rem; align-items: flex-start; }
.lane.shut { opacity: 0.6; }
.l-name { font-weight: 600; }
.l-meta { font-size: 0.7rem; color: #6b7280; }
.l-action { padding-left: 0; }
.stack { display: flex; flex-direction: column; }
.stack.struck { text-decoration: line-through; opacity: 0.55; }
.muted { color: #6b7280; font-size: 0.75rem; }
.tiny { font-size: 0.65rem; margin-left: 0.3rem; }
.flagged { color: #dc2626; font-size: 0.7rem; font-weight: 600; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.hint { font-size: 0.72rem; color: #9ca3af; line-height: 1.5; }
.detail-head { display: flex; gap: 0.7rem; align-items: center; flex-wrap: wrap; }
.detail-total { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.detail-total .big { font-size: 1.4rem; }
</style>
