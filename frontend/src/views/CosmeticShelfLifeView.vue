<script setup lang="ts">
/**
 * The shelf-life board.
 *
 * Two clocks run in a cosmetics shop and this screen is where both are read: the
 * printed expiry on a batch, and the period after opening on a tester. The
 * second exists nowhere else in the ERP, which is why the overdue testers sit on
 * the same page as the dated batches instead of behind their own menu row —
 * they are the same job on the same morning.
 *
 * Expired leads. Not because there is more of it, but because it is the only
 * list on the page with no decision in it: short-dated stock gets marked down,
 * an overdue tester gets replaced, and expired stock comes off the shelf today.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

// The policy is on `.delete` deliberately: whether the till refuses an expired
// line is a decision about the shop's licence to trade, not a preference.
const canSetPolicy = computed(() => auth.can('cosmetic-shelf-life.delete'))

const board = ref<any>(null)
const loading = ref(false)
const withinDays = ref<number | null>(null)
const warehouseId = ref<number | null>(null)
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const summary = computed(() => board.value?.summary ?? {})
const policy = computed(() => board.value?.policy ?? {})

const HORIZONS = [
  { label: '30 days', value: 30 },
  { label: '60 days', value: 60 },
  { label: '90 days', value: 90 },
  { label: '180 days', value: 180 },
]

function money(v: number) {
  return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(v ?? 0)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/cosmetics/shelf-life', {
      params: {
        within_days: withinDays.value || undefined,
        warehouse_id: warehouseId.value || undefined,
      },
    })
    board.value = data.data
    withinDays.value = data.data.within_days
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [w, p] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 200 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  warehouses.value = [
    { label: pt('All warehouses'), value: null },
    ...(w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id })),
  ]
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
}

// ---- "May we sell this?" ---------------------------------------------------
// The same check the till runs, asked one article at a time — so a doubt at the
// counter is settled before the customer has queued to pay.

const checkProduct = ref<number | null>(null)
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkProduct.value) return
  checking.value = true
  try {
    const { data } = await http.post('/cosmetics/shelf-life/check', {
      items: [{ product_id: checkProduct.value, quantity: 1 }],
      warehouse_id: warehouseId.value || undefined,
    })
    checkResult.value = data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    checking.value = false
  }
}

const checkSeverity = computed(() => {
  if (!checkResult.value) return 'info'
  if (checkResult.value.blocked) return 'error'
  return checkResult.value.issues.length ? 'warn' : 'success'
})

const checkMessage = computed(() => {
  if (!checkResult.value) return ''
  if (checkResult.value.blocked) return checkResult.value.blocking[0].message
  if (checkResult.value.issues.length) return checkResult.value.issues[0].message
  return pt('In date. Nothing on the shelf for this article is short.')
})

// ---- Policy ----------------------------------------------------------------

const policyVisible = ref(false)
const policyForm = ref({ block_expired: true, min_shelf_life_days: 0, near_expiry_days: 90 })
const savingPolicy = ref(false)

function openPolicy() {
  policyForm.value = {
    block_expired: !!policy.value.block_expired,
    min_shelf_life_days: policy.value.min_shelf_life_days ?? 0,
    near_expiry_days: policy.value.near_expiry_days ?? 90,
  }
  policyVisible.value = true
}

async function savePolicy() {
  savingPolicy.value = true
  try {
    const { data } = await http.put('/cosmetics/shelf-life/policy', policyForm.value)
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    policyVisible.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    savingPolicy.value = false
  }
}

function daysTag(days: number | null) {
  if (days === null) return { value: pt('No date'), severity: 'secondary' }
  if (days < 0) return { value: pt('{n} days ago', { n: Math.abs(days) }), severity: 'danger' }
  if (days <= 30) return { value: pt('{n} days left', { n: days }), severity: 'warn' }
  return { value: pt('{n} days left', { n: days }), severity: 'success' }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Shelf Life')"
      :subtitle="pt('What has gone off, what is going off, and which testers should already be in the bin')"
    >
      <template #actions>
        <Button
          v-if="canSetPolicy"
          :label="pt('Counter Policy')"
          icon="pi pi-sliders-h"
          outlined
          @click="openPolicy"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Expired batches') }}</span>
        <span class="t-value bad">{{ summary.expired_batches ?? 0 }}</span>
        <span class="t-note">₹{{ money(summary.expired_value) }} {{ pt('written off') }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Short dated') }}</span>
        <span class="t-value warn">{{ summary.expiring_batches ?? 0 }}</span>
        <span class="t-note">₹{{ money(summary.expiring_value) }} {{ pt('at risk') }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Testers past PAO') }}</span>
        <span class="t-value bad">{{ summary.overdue_units ?? 0 }}</span>
        <span class="t-note">{{ pt('of {n} open', { n: summary.open_units ?? 0 }) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Till refuses expired') }}</span>
        <span class="t-value">{{ policy.block_expired ? pt('Yes') : pt('No') }}</span>
        <span class="t-note">
          {{ policy.min_shelf_life_days ? pt('warns under {n} days', { n: policy.min_shelf_life_days }) : pt('no short-date warning') }}
        </span>
      </div>
    </div>

    <Message v-if="!policy.block_expired" severity="warn" :closable="false">
      {{ pt('The till is set to allow expired stock through. Selling cosmetics past their date is an offence, not a markdown decision.') }}
    </Message>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('May we sell this?') }}</div>
      <div class="check-row">
        <Select
          v-model="checkProduct"
          :options="products"
          option-label="label"
          option-value="value"
          filter
          show-clear
          class="check-product"
          :placeholder="pt('Article name or SKU')"
          @change="checkResult = null"
        />
        <Button
          :label="pt('Check')"
          icon="pi pi-clock"
          :loading="checking"
          :disabled="!checkProduct"
          @click="runCheck"
        />
      </div>
      <div v-if="checkResult" class="check-result">
        <Message :severity="checkSeverity" :closable="false">{{ checkMessage }}</Message>
        <div v-for="(issue, i) in checkResult.issues.slice(1)" :key="i" class="check-line">
          <i class="pi pi-exclamation-triangle" /> {{ issue.message }}
        </div>
      </div>
    </div>

    <div class="toolbar">
      <Select
        v-model="withinDays"
        :options="HORIZONS"
        option-label="label"
        option-value="value"
        class="horizon"
        @change="load"
      />
      <Select
        v-model="warehouseId"
        :options="warehouses"
        option-label="label"
        option-value="value"
        class="wh-pick"
        @change="load"
      />
      <Button icon="pi pi-refresh" text :loading="loading" @click="load" />
      <div class="spacer" />
      <TableExportButtons
        title="Cosmetics Shelf Life"
        filenameBase="cosmetics-shelf-life"
        :rows="[...(board?.expired ?? []), ...(board?.expiring ?? [])]"
        :columns="[
          { key: 'product', label: 'Article' },
          { key: 'sku', label: 'SKU' },
          { key: 'batch_number', label: 'Batch' },
          { key: 'expiry_date', label: 'Expiry' },
          { key: 'days_left', label: 'Days left' },
          { key: 'quantity', label: 'Qty' },
          { key: 'value', label: 'Value' },
          { key: 'warehouse', label: 'Warehouse' },
        ]"
      />
    </div>

    <!-- Expired: no decision in it, so it leads. -->
    <section class="block">
      <h3 class="block-title bad">{{ pt('Expired — off the shelf today') }}</h3>
      <DataTable :value="board?.expired ?? []" :loading="loading" size="small" data-key="batch_id">
        <template #empty><div class="empty-state">{{ pt('Nothing on the shelf has expired.') }}</div></template>
        <Column field="product" :header="pt('Article')">
          <template #body="{ data }">
            <div class="strong">{{ data.product }}</div>
            <div class="muted mono">{{ data.sku }}<template v-if="data.variant"> · {{ data.variant }}</template></div>
          </template>
        </Column>
        <Column field="batch_number" :header="pt('Batch')" />
        <Column field="expiry_date" :header="pt('Expired on')" />
        <Column :header="pt('Since')">
          <template #body="{ data }"><Tag v-bind="daysTag(data.days_left)" /></template>
        </Column>
        <Column field="quantity" :header="pt('Qty')" />
        <Column :header="pt('Value')">
          <template #body="{ data }">₹{{ money(data.value) }}</template>
        </Column>
        <Column field="warehouse" :header="pt('Warehouse')" />
      </DataTable>
    </section>

    <!-- Short dated: legal to sell, so this is a markdown list. -->
    <section class="block">
      <h3 class="block-title warn">
        {{ pt('Short dated — within {n} days', { n: board?.within_days ?? 90 }) }}
      </h3>
      <DataTable :value="board?.expiring ?? []" :loading="loading" size="small" data-key="batch_id">
        <template #empty><div class="empty-state">{{ pt('Nothing is close to its date.') }}</div></template>
        <Column field="product" :header="pt('Article')">
          <template #body="{ data }">
            <div class="strong">{{ data.product }}</div>
            <div class="muted mono">{{ data.sku }}</div>
          </template>
        </Column>
        <Column field="batch_number" :header="pt('Batch')" />
        <Column field="expiry_date" :header="pt('Expires')" />
        <Column :header="pt('Left')">
          <template #body="{ data }"><Tag v-bind="daysTag(data.days_left)" /></template>
        </Column>
        <Column field="quantity" :header="pt('Qty')" />
        <Column :header="pt('Value')">
          <template #body="{ data }">₹{{ money(data.value) }}</template>
        </Column>
        <Column field="warehouse" :header="pt('Warehouse')" />
      </DataTable>
    </section>

    <!-- The second clock. -->
    <section class="block">
      <h3 class="block-title">{{ pt('Open units — period after opening') }}</h3>
      <DataTable :value="board?.open_units ?? []" :loading="loading" size="small" data-key="id">
        <template #empty><div class="empty-state">{{ pt('No tester is near its date.') }}</div></template>
        <Column field="product" :header="pt('Article')">
          <template #body="{ data }">
            <div class="strong">{{ data.product }}</div>
            <div class="muted">{{ data.variant }}</div>
          </template>
        </Column>
        <Column field="purpose" :header="pt('Opened as')">
          <template #body="{ data }"><Tag :value="data.purpose" severity="secondary" /></template>
        </Column>
        <Column field="opened_on" :header="pt('Opened')" />
        <Column :header="pt('PAO')">
          <template #body="{ data }">{{ data.pao_months ? `${data.pao_months}M` : '—' }}</template>
        </Column>
        <Column field="expires_on" :header="pt('Due')" />
        <Column :header="pt('Left')">
          <template #body="{ data }"><Tag v-bind="daysTag(data.days_left)" /></template>
        </Column>
        <Column field="warehouse" :header="pt('Warehouse')" />
      </DataTable>
    </section>

    <Dialog
      v-model:visible="policyVisible"
      modal
      :header="pt('Counter Policy')"
      :style="{ width: '34rem' }"
    >
      <div class="fld">
        <label class="inline">
          <Checkbox v-model="policyForm.block_expired" binary />
          <span>{{ pt('Refuse a bill carrying expired stock') }}</span>
        </label>
        <small class="hint">
          {{ pt('Checked, the till stops the sale before anything is written. Unchecked, it only warns — which is a choice a shop should make on purpose.') }}
        </small>
      </div>

      <div class="fld">
        <label>{{ pt('Warn when a batch has fewer days left than') }}</label>
        <InputNumber v-model="policyForm.min_shelf_life_days" :min="0" :max="1095" class="w-full" />
        <small class="hint">
          {{ pt('Never a refusal — short-dated stock is legal to sell and the customer decides. Zero turns the warning off.') }}
        </small>
      </div>

      <div class="fld">
        <label>{{ pt('Board looks ahead') }}</label>
        <InputNumber v-model="policyForm.near_expiry_days" :min="1" :max="1095" suffix=" days" class="w-full" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="policyVisible = false" />
        <Button :label="pt('Save')" :loading="savingPolicy" @click="savePolicy" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }
.t-value.bad { color: #b91c1c; }
.t-note { font-size: 0.7rem; color: #9ca3af; }

.check-panel { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.9rem 1rem; margin: 1rem 0; }
.check-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.6rem; }
.check-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.check-product { min-width: 320px; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.35rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.horizon { min-width: 140px; }
.wh-pick { min-width: 200px; }
.spacer { flex: 1 1 auto; }

.block { margin-bottom: 1.6rem; }
.block-title { font-size: 0.9rem; font-weight: 600; margin: 0 0 0.5rem; }
.block-title.bad { color: #b91c1c; }
.block-title.warn { color: #b45309; }

.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.9rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline { display: flex; gap: 0.5rem; align-items: center; }
.hint { font-size: 0.72rem; color: #6b7280; }
.muted { color: #6b7280; font-size: 0.75rem; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.w-full { width: 100%; }
.empty-state { padding: 1.25rem; text-align: center; color: #6b7280; }
</style>
