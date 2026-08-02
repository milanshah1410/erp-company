<script setup lang="ts">
/**
 * The size × colour matrix.
 *
 * A garment buyer does not think in SKUs. They think "SS-2214, six sizes, five
 * colours" and they read stock as a grid — rows of colour, columns of size —
 * because the only questions that matter are which cells are empty and which
 * column is running out across the whole range. So this screen is a grid first
 * and a form second: the table *is* the interface, and generating variants is a
 * dialog you open from it rather than the thing you land on.
 *
 * Two deliberate choices:
 *
 *  - **Column totals are shown, row totals too.** "We are out of M everywhere"
 *    is the sentence a merchandiser is looking for, and no per-product screen in
 *    the ERP can say it.
 *  - **Empty cells are visibly empty**, not zero. A cell with no variant behind
 *    it has never been made; a cell with a variant and no stock has sold out.
 *    Those are different problems and the grid must not blur them.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const route = useRoute()
const router = useRouter()

const canEdit = computed(() => auth.can('products.edit'))

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const productId = ref<number | null>(null)
const warehouseId = ref<number | null>(null)

const grid = ref<any>(null)
const style = ref<any>(null)
const sizeSets = ref<any[]>([])
const colours = ref<any[]>([])
const loading = ref(false)

// ---- Build dialog ---------------------------------------------------------

const buildVisible = ref(false)
const buildSizeSetId = ref<number | null>(null)
const buildColourIds = ref<number[]>([])
const buildCost = ref<number | null>(null)
const buildPrice = ref<number | null>(null)
const building = ref(false)
const buildError = ref<string | null>(null)

const buildSizes = computed<string[]>(
  () => sizeSets.value.find((s) => s.id === buildSizeSetId.value)?.sizes ?? [],
)
const buildCells = computed(() => buildSizes.value.length * buildColourIds.value.length)

/** Stable :key for a cell — colour and size together identify it. */
function cellKey(colour: string, size: string) {
  return `${colour}|${size}`
}

async function loadPickers() {
  const [p, w] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

async function load() {
  if (!productId.value) {
    grid.value = null
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/garment/products/${productId.value}/matrix`, {
      params: { warehouse_id: warehouseId.value ?? undefined },
    })
    grid.value = data
    style.value = data.style
    sizeSets.value = data.size_sets ?? []
    colours.value = data.colours ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not load the grid'), life: 4000 })
  } finally {
    loading.value = false
  }
}

function openBuild() {
  buildError.value = null
  // Default to the style's own size run and to whatever colours are already in
  // the grid — the commonest reason to reopen this dialog is to add one more
  // colour to a range that already exists, not to start from nothing.
  buildSizeSetId.value = style.value?.size_set_id ?? sizeSets.value[0]?.id ?? null
  const inGrid = new Set((grid.value?.rows ?? []).map((r: any) => (r.code ?? r.name)))
  buildColourIds.value = colours.value.filter((c) => inGrid.has(c.code) || inGrid.has(c.name)).map((c) => c.id)
  buildCost.value = style.value?.cost_price ?? grid.value?.product?.cost_price ?? null
  buildPrice.value = style.value?.mrp ?? grid.value?.product?.selling_price ?? null
  buildVisible.value = true
}

async function build() {
  building.value = true
  buildError.value = null
  try {
    const { data } = await http.post(`/garment/products/${productId.value}/matrix`, {
      size_set_id: buildSizeSetId.value,
      colour_ids: buildColourIds.value,
      cost_price: buildCost.value ?? undefined,
      selling_price: buildPrice.value ?? undefined,
    })
    buildVisible.value = false
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    buildError.value =
      e.response?.data?.errors?.sizes?.[0] ?? e.response?.data?.message ?? pt('Could not build the grid')
  } finally {
    building.value = false
  }
}

watch(productId, () => {
  // Keep the chosen product in the URL so the grid can be linked to from the
  // style master and reloaded without picking again.
  router.replace({ query: { ...route.query, product: productId.value ?? undefined } })
  load()
})
watch(warehouseId, load)

onMounted(async () => {
  await loadPickers()
  const fromUrl = Number(route.query.product)
  if (fromUrl) productId.value = fromUrl
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Size & Colour Matrix')"
      :subtitle="pt('One style, every size in every colour — and the holes in between')"
    >
      <template #actions>
        <Button
          v-if="canEdit && productId"
          :label="pt('Build Grid')"
          icon="pi pi-th-large"
          @click="openBuild"
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
        showClear
        class="picker"
        :placeholder="pt('Choose a product')"
      />
      <Select
        v-model="warehouseId"
        :options="warehouses"
        option-label="label"
        option-value="value"
        showClear
        class="wh"
        :placeholder="pt('All warehouses')"
      />
    </div>

    <div v-if="loading" class="centered"><ProgressSpinner style="width: 42px; height: 42px" /></div>

    <div v-else-if="!productId" class="empty-state">
      {{ pt('Pick a product to see its grid. Every cell is one size in one colour, with its own barcode and stock.') }}
    </div>

    <template v-else-if="grid">
      <div v-if="style" class="style-strip">
        <span class="strong">{{ style.code }}</span>
        <span class="muted">{{ style.name }}</span>
        <span class="muted">·</span>
        <span class="muted">{{ pt('MRP') }} {{ formatCurrency(style.mrp) }}</span>
      </div>
      <Message v-else severity="info" :closable="false" class="mb">
        {{ pt('This product is not linked to a style. Attach it from the Style Master to price the grid from the style.') }}
      </Message>

      <div v-if="!grid.rows.length" class="empty-state">
        {{ pt('No size/colour variants yet. Build the grid to create them — it only ever adds cells, so you can come back and add a colour later.') }}
      </div>

      <div v-else class="matrix-wrap">
        <table class="matrix">
          <thead>
            <tr>
              <th class="corner">{{ pt('Colour') }}</th>
              <th v-for="size in grid.sizes" :key="size">{{ size }}</th>
              <th class="total-col">{{ pt('Total') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in grid.rows" :key="row.name">
              <th class="colour-head">
                <span class="swatch" :style="{ background: row.hex || 'transparent' }" />
                <span>{{ row.name }}</span>
                <small v-if="row.code" class="muted">{{ row.code }}</small>
              </th>
              <td
                v-for="cell in row.cells"
                :key="cellKey(row.name, cell.size)"
                :class="{ missing: !cell.variant_id, out: cell.variant_id && cell.quantity <= 0 }"
                :title="cell.sku || pt('Not made in this size')"
              >
                <!-- Empty and never-made are different problems, and the grid
                     must not blur them: a dash means no such variant exists. -->
                <span v-if="!cell.variant_id" class="dash">—</span>
                <span v-else class="qty">{{ cell.quantity }}</span>
                <small v-if="cell.variant_id" class="sku">{{ cell.sku }}</small>
              </td>
              <td class="total-col">{{ row.quantity }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th class="colour-head">{{ pt('Total') }}</th>
              <!-- The line a merchandiser reads: which size is gone across the
                   whole range, not just in one colour. -->
              <td v-for="(total, i) in grid.size_totals" :key="i">{{ total }}</td>
              <td class="total-col">{{ grid.total_quantity }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>

    <!-- Build dialog -->
    <Dialog v-model:visible="buildVisible" modal :header="pt('Build Size & Colour Grid')" :style="{ width: '620px' }">
      <Message severity="info" :closable="false" class="mb">
        {{ pt('This only adds the cells that are missing. Existing variants, their barcodes and their stock are never touched.') }}
      </Message>

      <div class="grid-form">
        <div class="fld span-2">
          <label>{{ pt('Size Run') }} *</label>
          <Select
            v-model="buildSizeSetId"
            :options="sizeSets"
            option-label="name"
            option-value="id"
            class="w-full"
            :placeholder="pt('Choose a size run')"
          />
          <small v-if="buildSizes.length" class="hint">{{ buildSizes.join(' · ') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Colours') }} *</label>
          <MultiSelect
            v-model="buildColourIds"
            :options="colours"
            option-label="name"
            option-value="id"
            filter
            display="chip"
            class="w-full"
            :placeholder="pt('Choose colours')"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Cost Price') }}</label>
          <InputNumber v-model="buildCost" :minFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Selling Price') }}</label>
          <InputNumber v-model="buildPrice" :minFractionDigits="2" class="w-full" />
        </div>
      </div>

      <p class="hint mt">
        {{ pt('{n} cells in this grid.', { n: buildCells }) }}
      </p>
      <p v-if="buildError" class="err">{{ buildError }}</p>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="buildVisible = false" />
        <Button
          :label="pt('Build')"
          icon="pi pi-check"
          :loading="building"
          :disabled="!buildSizeSetId || !buildColourIds.length"
          @click="build"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.picker { min-width: 320px; }
.wh { min-width: 200px; }
.centered { display: flex; justify-content: center; padding: 2.5rem; }

.style-strip {
  display: flex; gap: 0.5rem; align-items: baseline; flex-wrap: wrap;
  border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px;
  padding: 0.6rem 0.9rem; margin-bottom: 1rem;
}

.matrix-wrap { overflow-x: auto; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; }
.matrix { border-collapse: collapse; width: 100%; min-width: 520px; }
.matrix th, .matrix td {
  border-bottom: 1px solid var(--surface-border, #e5e7eb);
  border-right: 1px solid var(--surface-border, #e5e7eb);
  padding: 0.5rem 0.6rem; text-align: center; font-size: 0.85rem;
}
.matrix thead th { background: var(--surface-100, #f3f4f6); font-weight: 600; white-space: nowrap; }
.matrix tfoot td, .matrix tfoot th { font-weight: 700; background: var(--surface-50, #f9fafb); }
.corner, .colour-head { text-align: left; white-space: nowrap; }
.colour-head { display: table-cell; }
.colour-head .swatch {
  display: inline-block; width: 12px; height: 12px; border-radius: 3px;
  border: 1px solid var(--surface-border, #d1d5db); margin-right: 0.4rem; vertical-align: middle;
}
.colour-head small { margin-left: 0.35rem; }
.total-col { font-weight: 700; }

/* A cell with no variant behind it was never made; a cell with a variant and no
   stock has sold out. Different problems, different treatment. */
.missing { background: repeating-linear-gradient(45deg, transparent, transparent 4px, rgba(0,0,0,0.03) 4px, rgba(0,0,0,0.03) 8px); }
.out .qty { color: #b91c1c; font-weight: 700; }
.qty { display: block; font-weight: 600; }
.sku { display: block; font-size: 0.62rem; color: #9ca3af; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.dash { color: #d1d5db; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.mt { margin-top: 0.7rem; }
.w-full { width: 100%; }
.empty-state { padding: 2rem; text-align: center; color: #6b7280; }
</style>
