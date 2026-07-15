<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import portalHttp from '../../api/portalHttp'
import { usePortalAuthStore } from '../../stores/portalAuth'
import { usePortalCartStore } from '../../stores/portalCart'

const toast = useToast()
const auth = usePortalAuthStore()
const cart = usePortalCartStore()

const products = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const inStockOnly = ref(false)
const page = ref(1)
const lastPage = ref(1)

// Per-card UI state (selected variant + quantity), keyed by product id.
const qty = reactive<Record<number, number>>({})
const variant = reactive<Record<number, number | null>>({})

const stockSeverity: Record<string, string> = { in: 'success', low: 'warn', out: 'danger' }
const stockLabel: Record<string, string> = { in: 'In stock', low: 'Low stock', out: 'Out of stock' }

function money(v: number) {
  return auth.money(v)
}

/** The active variant object for a product, or null when none is selected. */
function activeVariant(product: any) {
  if (!product.variants?.length) return null
  const vid = variant[product.id]
  return product.variants.find((v: any) => v.id === vid) ?? null
}

function currentPrice(product: any) {
  return activeVariant(product)?.price ?? product.price
}
function currentStatus(product: any) {
  return activeVariant(product)?.stock_status ?? product.stock_status
}
function currentAvailable(product: any) {
  const v = activeVariant(product)
  return v ? v.available_quantity : product.available_quantity
}

async function load(reset = true) {
  loading.value = true
  try {
    const { data } = await portalHttp.get('/catalogue', {
      params: { search: search.value || undefined, in_stock: inStockOnly.value || undefined, page: page.value, per_page: 24 },
    })
    const rows = data.data ?? []
    products.value = reset ? rows : [...products.value, ...rows]
    lastPage.value = data.meta?.last_page ?? 1
    // Seed per-card defaults.
    for (const p of rows) {
      if (qty[p.id] === undefined) qty[p.id] = 1
      if (variant[p.id] === undefined && p.variants?.length) variant[p.id] = p.variants[0].id
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load catalogue', life: 3000 })
  } finally {
    loading.value = false
  }
}

function doSearch() {
  page.value = 1
  load(true)
}
function loadMore() {
  page.value += 1
  load(false)
}

function addToCart(product: any) {
  const v = activeVariant(product)
  const status = currentStatus(product)
  if (status === 'out') {
    toast.add({ severity: 'warn', summary: 'Out of stock', detail: product.name, life: 2500 })
    return
  }
  const quantity = Number(qty[product.id]) || 1
  cart.add({
    product_id: product.id,
    variant_id: v?.id ?? null,
    name: product.name,
    variant_name: v?.name ?? null,
    unit: product.unit ?? null,
    price: currentPrice(product),
    available: currentAvailable(product),
  }, quantity)
  toast.add({ severity: 'success', summary: 'Added to cart', detail: `${quantity} × ${product.name}`, life: 2000 })
}

onMounted(() => load(true))
</script>

<template>
  <div>
    <div class="head">
      <div>
        <h1>Catalogue</h1>
        <p class="sub">Your prices ({{ auth.customer?.price_level }} tier) and live stock.</p>
      </div>
      <div class="tools">
        <span class="search">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search products..." @keyup.enter="doSearch" />
        </span>
        <label class="toggle">
          <ToggleSwitch v-model="inStockOnly" @change="doSearch" /> In stock only
        </label>
        <Button label="Search" outlined @click="doSearch" />
      </div>
    </div>

    <div v-if="!products.length && !loading" class="empty">No products found.</div>

    <div class="grid">
      <div v-for="p in products" :key="p.id" class="prod">
        <div class="prod-top">
          <div class="prod-name">{{ p.name }}</div>
          <div class="prod-sku">{{ p.sku }}<span v-if="p.category"> · {{ p.category }}</span></div>
        </div>

        <div v-if="p.variants?.length" class="fld">
          <Select v-model="variant[p.id]" :options="p.variants" optionLabel="name" optionValue="id" class="w-full" />
        </div>

        <div class="prod-mid">
          <div class="price">{{ money(currentPrice(p)) }}<span v-if="p.unit" class="unit"> / {{ p.unit }}</span></div>
          <Tag :severity="stockSeverity[currentStatus(p)]" :value="stockLabel[currentStatus(p)]" />
        </div>
        <div class="avail">{{ currentAvailable(p) }} {{ p.unit || 'units' }} available</div>

        <div class="prod-actions">
          <InputNumber v-model="qty[p.id]" :min="1" :minFractionDigits="0" :maxFractionDigits="3" showButtons buttonLayout="horizontal"
            class="qty" :disabled="currentStatus(p) === 'out'" />
          <Button label="Add" icon="pi pi-cart-plus" size="small" :disabled="currentStatus(p) === 'out'" @click="addToCart(p)" />
        </div>
      </div>
    </div>

    <div v-if="page < lastPage" class="more">
      <Button label="Load more" text :loading="loading" @click="loadMore" />
    </div>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.head h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.85rem; text-transform: capitalize; }
.tools { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.7rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.2rem; width: 240px; }
.toggle { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #374151; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 1rem; }
.prod { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; gap: 0.6rem; }
.prod-name { font-weight: 600; font-size: 0.98rem; }
.prod-sku { color: #6b7280; font-size: 0.78rem; }
.fld :deep(.p-select) { width: 100%; }
.prod-mid { display: flex; justify-content: space-between; align-items: center; }
.price { font-size: 1.1rem; font-weight: 700; color: #111827; }
.unit { font-size: 0.75rem; font-weight: 400; color: #6b7280; }
.avail { font-size: 0.78rem; color: #6b7280; margin-top: -0.35rem; }
.prod-actions { display: flex; gap: 0.5rem; align-items: center; margin-top: auto; }
.qty { flex: 1; }
.qty :deep(input) { width: 100%; }
.w-full { width: 100%; }
.more { text-align: center; margin-top: 1.5rem; }
.empty { text-align: center; color: #6b7280; padding: 3rem; }
</style>
