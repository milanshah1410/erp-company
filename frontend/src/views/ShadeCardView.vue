<script setup lang="ts">
/**
 * The shade card.
 *
 * A beauty buyer opens this to answer one question — which shades of this range
 * are we out of — so the screen leads with that count and renders the range as
 * swatches rather than as a table of SKUs. A foundation ladder read in any order
 * but light-to-dark is unusable, which is why the server sorts by depth and this
 * screen does not offer to re-sort it.
 *
 * "Add shades" writes ordinary product variants (see ShadeCardService), so
 * everything downstream — the POS scan, stock, barcode labels — treats them as
 * exactly what they are. That is also why the action needs products.create as
 * well as the shade grant: it puts new sellable lines on the shelf.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const route = useRoute()
const router = useRouter()

const canGenerate = computed(() => auth.can('cosmetic-shades.create') && auth.can('products.create'))

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const productId = ref<number | null>(null)
const warehouseId = ref<number | null>(null)

const card = ref<any>(null)
const available = ref<any[]>([])
const loading = ref(false)

const summary = computed(() => card.value?.summary ?? { shades: 0, in_stock: 0, out_of_stock: 0, total_quantity: 0 })

async function loadPickers() {
  const [p, w] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 200 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
  warehouses.value = [
    { label: pt('All warehouses'), value: null },
    ...(w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id })),
  ]
}

async function load() {
  if (!productId.value) {
    card.value = null
    available.value = []
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/cosmetics/shade-card/${productId.value}`, {
      params: { warehouse_id: warehouseId.value || undefined },
    })
    card.value = data.data
    available.value = data.available
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not load the card.'), life: 4000 })
  } finally {
    loading.value = false
  }
}

// The product stays in the URL so a buyer can keep a range open in a tab and
// send the link to whoever is doing the ordering.
watch(productId, (id) => {
  router.replace({ query: id ? { product: String(id) } : {} })
  load()
})
watch(warehouseId, load)

// ---- Adding shades ---------------------------------------------------------

const dialogVisible = ref(false)
const picked = ref<number[]>([])
const overridePricing = ref(false)
const costPrice = ref<number | null>(null)
const sellingPrice = ref<number | null>(null)
const saving = ref(false)

function openAdd() {
  picked.value = []
  overridePricing.value = false
  costPrice.value = card.value?.product?.cost_price ?? null
  sellingPrice.value = card.value?.product?.selling_price ?? null
  dialogVisible.value = true
}

function toggle(id: number) {
  const i = picked.value.indexOf(id)
  if (i === -1) picked.value.push(id)
  else picked.value.splice(i, 1)
}

function pickAll() {
  picked.value = picked.value.length === available.value.length ? [] : available.value.map((s: any) => s.id)
}

async function generate() {
  if (!picked.value.length) return
  saving.value = true
  try {
    const { data } = await http.post('/cosmetics/shade-card', {
      product_id: productId.value,
      shade_ids: picked.value,
      cost_price: overridePricing.value ? costPrice.value : undefined,
      selling_price: overridePricing.value ? sellingPrice.value : undefined,
    })
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not add those shades.'), life: 4500 })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadPickers()
  const fromUrl = Number(route.query.product)
  if (fromUrl) productId.value = fromUrl
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Shade Card')"
      :subtitle="pt('A range, shade by shade, and which of them are off the shelf')"
    >
      <template #actions>
        <Button
          v-if="canGenerate && card"
          :label="pt('Add Shades')"
          icon="pi pi-plus"
          :disabled="!available.length"
          @click="openAdd"
        />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select
        v-model="productId"
        :options="products"
        option-label="label"
        option-value="value"
        filter
        show-clear
        class="product-pick"
        :placeholder="pt('Choose a range — foundation, lipstick, compact…')"
      />
      <Select
        v-model="warehouseId"
        :options="warehouses"
        option-label="label"
        option-value="value"
        class="wh-pick"
      />
    </div>

    <div v-if="!productId" class="empty-state">
      {{ pt('Pick a range to see its shade card.') }}
    </div>

    <template v-else-if="card">
      <div class="tiles">
        <div class="tile">
          <span class="t-label">{{ pt('Shades on the card') }}</span>
          <span class="t-value">{{ summary.shades }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('In stock') }}</span>
          <span class="t-value good">{{ summary.in_stock }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Out of stock') }}</span>
          <span class="t-value bad">{{ summary.out_of_stock }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Units on hand') }}</span>
          <span class="t-value">{{ summary.total_quantity }}</span>
        </div>
      </div>

      <Message v-if="!summary.shades" severity="info" :closable="false">
        {{ pt('This range has no shades on it yet. Add them and each one becomes a variant with its own barcode.') }}
      </Message>

      <!-- The card itself. Swatches, because a grid of shade names tells nobody
           anything, and in depth order because that is the only order a base
           range reads in. -->
      <div class="card-grid">
        <div
          v-for="row in card.rows"
          :key="row.variant_id"
          class="shade"
          :class="{ out: row.quantity <= 0, inactive: !row.is_active }"
        >
          <div class="swatch" :style="{ background: row.shade?.hex || '#e5e7eb' }" />
          <div class="shade-body">
            <div class="shade-top">
              <span class="shade-code">{{ row.shade?.code }}</span>
              <span class="qty" :class="{ zero: row.quantity <= 0 }">{{ row.quantity }}</span>
            </div>
            <div class="shade-name">{{ row.shade?.name }}</div>
            <div class="shade-meta">{{ row.shade?.descriptor }}</div>
            <div class="shade-sku mono">{{ row.sku }}</div>
          </div>
        </div>
      </div>

      <!-- Variants with no shade behind them. Listed rather than hidden: on a
           cosmetics range that is a data problem the buyer needs to see. -->
      <div v-if="card.unshaded.length" class="unshaded">
        <div class="unshaded-title">
          <i class="pi pi-exclamation-triangle" />
          {{ pt('{n} variant(s) on this range carry no shade', { n: card.unshaded.length }) }}
        </div>
        <div class="unshaded-rows">
          <Tag v-for="u in card.unshaded" :key="u.variant_id" :value="`${u.name || u.sku} · ${u.quantity}`" severity="secondary" />
        </div>
      </div>
    </template>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="pt('Add Shades to the Card')"
      :style="{ width: '46rem' }"
    >
      <div class="dlg-head">
        <span class="muted">
          {{ pt('Each shade picked becomes a variant of this range, with its own SKU and barcode.') }}
        </span>
        <Button
          :label="picked.length === available.length ? pt('Clear all') : pt('Select all')"
          text
          size="small"
          @click="pickAll"
        />
      </div>

      <div class="pick-grid">
        <label
          v-for="s in available"
          :key="s.id"
          class="pick"
          :class="{ on: picked.includes(s.id) }"
        >
          <Checkbox :model-value="picked.includes(s.id)" binary @update:model-value="toggle(s.id)" />
          <span class="swatch small" :style="{ background: s.hex || '#e5e7eb' }" />
          <span class="pick-body">
            <span class="shade-code">{{ s.code }} {{ s.name }}</span>
            <span class="shade-meta">{{ s.descriptor }}</span>
          </span>
        </label>
      </div>

      <div class="pricing">
        <label class="inline">
          <Checkbox v-model="overridePricing" binary />
          <span>{{ pt('Price these differently from the range') }}</span>
        </label>
        <div v-if="overridePricing" class="pricing-row">
          <div class="fld">
            <label>{{ pt('Cost') }}</label>
            <InputNumber v-model="costPrice" mode="decimal" :min-fraction-digits="2" class="w-full" />
          </div>
          <div class="fld">
            <label>{{ pt('Selling price') }}</label>
            <InputNumber v-model="sellingPrice" mode="decimal" :min-fraction-digits="2" class="w-full" />
          </div>
        </div>
        <small v-else class="hint">
          {{ pt('Every shade is priced from the range, which is how this trade sells.') }}
        </small>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button
          :label="pt('Add {n} shade(s)', { n: picked.length })"
          :loading="saving"
          :disabled="!picked.length"
          @click="generate"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.product-pick { min-width: 340px; }
.wh-pick { min-width: 200px; }

.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.good { color: #15803d; }
.t-value.bad { color: #b91c1c; }

.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.7rem; margin-top: 1rem; }
.shade { display: flex; gap: 0.6rem; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.6rem; }
.shade.out { border-color: #fca5a5; background: rgba(254, 226, 226, 0.35); }
.shade.inactive { opacity: 0.55; }
.swatch { width: 38px; height: 38px; border-radius: 8px; border: 1px solid rgba(0, 0, 0, 0.12); flex: 0 0 auto; }
.swatch.small { width: 22px; height: 22px; border-radius: 6px; }
.shade-body { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.shade-top { display: flex; align-items: baseline; gap: 0.5rem; justify-content: space-between; }
.shade-code { font-weight: 700; font-size: 0.82rem; }
.qty { font-size: 0.8rem; font-weight: 600; color: #15803d; }
.qty.zero { color: #b91c1c; }
.shade-name { font-size: 0.8rem; }
.shade-meta { font-size: 0.7rem; color: #6b7280; text-transform: capitalize; }
.shade-sku { font-size: 0.68rem; color: #9ca3af; overflow: hidden; text-overflow: ellipsis; }

.unshaded { margin-top: 1.2rem; border: 1px dashed #fbbf24; border-radius: 10px; padding: 0.75rem 0.9rem; }
.unshaded-title { font-size: 0.8rem; font-weight: 600; color: #b45309; margin-bottom: 0.5rem; display: flex; gap: 0.4rem; align-items: center; }
.unshaded-rows { display: flex; gap: 0.4rem; flex-wrap: wrap; }

.dlg-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.6rem; }
.pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.5rem; max-height: 22rem; overflow-y: auto; }
.pick { display: flex; align-items: center; gap: 0.5rem; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.45rem 0.55rem; cursor: pointer; }
.pick.on { border-color: var(--p-primary-color, #6366f1); background: rgba(99, 102, 241, 0.06); }
.pick-body { display: flex; flex-direction: column; min-width: 0; }

.pricing { margin-top: 0.9rem; border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.75rem; }
.pricing-row { display: grid; grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: 0.75rem; margin-top: 0.5rem; }
.inline { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; font-size: 0.8rem; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.hint { font-size: 0.72rem; color: #6b7280; display: block; margin-top: 0.4rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
