<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { openPdfBlob } from '../utils/blob'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()

// The four label formats from SRS §21.
const TEMPLATES = computed(() => [
  { value: 'barcode', label: pt('Barcode Printing'), icon: 'pi pi-barcode', desc: pt('Compact CODE128 barcode label.'), symbology: 'code128' as string | null },
  { value: 'qr', label: pt('QR Label'), icon: 'pi pi-qrcode', desc: pt('Square QR-code label.'), symbology: 'qr' as string | null },
  { value: 'sticker', label: pt('Product Sticker'), icon: 'pi pi-tag', desc: pt('Name, brand/category, code & price.'), symbology: null as string | null },
  { value: 'price_tag', label: pt('Price Tag'), icon: 'pi pi-indian-rupee', desc: pt('Retail tag with a bold price.'), symbology: null as string | null },
])

const search = ref('')
const products = ref<any[]>([])
const searching = ref(false)

const cart = ref<any[]>([])
const template = ref('barcode')
const symbology = ref('code128') // used by sticker / price_tag
const showCompany = ref(true)
const showPrice = ref(true)
const printing = ref(false)

const currentTemplate = computed(() => TEMPLATES.value.find((t) => t.value === template.value)!)
// The barcode/qr templates imply their symbology; sticker/price_tag let the user pick.
const allowsSymbologyChoice = computed(() => currentTemplate.value.symbology === null)
const effectiveSymbology = computed(() => currentTemplate.value.symbology ?? symbology.value)
const showsPriceOption = computed(() => ['sticker', 'price_tag'].includes(template.value))

async function searchProducts() {
  searching.value = true
  try {
    const { data } = await http.get('/products', { params: { search: search.value || undefined, per_page: 20 } })
    products.value = data.data ?? []
  } finally {
    searching.value = false
  }
}

function addToCart(product: any) {
  if (!product.barcode) {
    toast.add({ severity: 'warn', summary: pt('No barcode'), detail: pt('{name} has no barcode value yet.', { name: product.name }), life: 3000 })
    return
  }
  const existing = cart.value.find((l) => l.type === 'product' && l.id === product.id)
  if (existing) {
    existing.quantity += 1
    return
  }
  cart.value.push({
    type: 'product',
    id: product.id,
    name: product.name,
    sku: product.sku,
    barcode: product.barcode,
    price: product.selling_price,
    category: product.category?.name ?? null,
    brand: product.brand?.name ?? null,
    quantity: 1,
  })
}

function removeLine(i: number) {
  cart.value.splice(i, 1)
}

const totalLabels = computed(() => cart.value.reduce((s, l) => s + (Number(l.quantity) || 0), 0))

// ---- Live preview of the first cart item in the chosen template ----
const previewItem = computed(() => cart.value[0] ?? null)
const previewImg = ref<string | null>(null)
let previewUrl: string | null = null

async function refreshPreview() {
  if (previewUrl) {
    URL.revokeObjectURL(previewUrl)
    previewUrl = null
  }
  previewImg.value = null
  if (!previewItem.value) return
  try {
    const { data } = await http.get('/barcode/render', {
      params: { value: previewItem.value.barcode, type: effectiveSymbology.value },
      responseType: 'blob',
    })
    previewUrl = URL.createObjectURL(data)
    previewImg.value = previewUrl
  } catch {
    /* preview is best-effort */
  }
}

watch([previewItem, effectiveSymbology], refreshPreview, { deep: true })

async function generatePdf() {
  if (!cart.value.length) {
    toast.add({ severity: 'warn', summary: pt('No items selected'), life: 3000 })
    return
  }
  printing.value = true
  try {
    const { data } = await http.post(
      '/barcode/print-labels',
      {
        template: template.value,
        label_type: effectiveSymbology.value,
        show_company: showCompany.value,
        show_price: showPrice.value,
        items: cart.value.map((l) => ({ type: l.type, id: l.id, quantity: l.quantity })),
      },
      { responseType: 'blob' },
    )
    openPdfBlob(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not generate labels'), life: 4000 })
  } finally {
    printing.value = false
  }
}

let debounce: any = null
watch(search, () => {
  clearTimeout(debounce)
  debounce = setTimeout(searchProducts, 300)
})

onMounted(searchProducts)
</script>

<template>
  <div>
    <PageHeader :title="pt('Barcode & Label Printing')" :subtitle="pt('Pick a label format, add products with a quantity, then print a ready-to-cut sheet.')" />

    <!-- Format picker -->
    <div class="templates">
      <button
        v-for="t in TEMPLATES"
        :key="t.value"
        class="template-card"
        :class="{ active: template === t.value }"
        type="button"
        @click="template = t.value"
      >
        <i :class="t.icon" />
        <span class="tpl-label">{{ t.label }}</span>
        <span class="tpl-desc">{{ t.desc }}</span>
      </button>
    </div>

    <div class="layout">
      <div class="panel">
        <span class="search">
          <i class="pi pi-search" />
          <InputText v-model="search" :placeholder="pt('Search products by name / SKU / barcode...')" />
        </span>

        <DataTable :value="products" :loading="searching" class="p-datatable-sm" dataKey="id">
          <Column field="sku" :header="pt('SKU')" style="width: 8rem" />
          <Column field="name" :header="pt('Name')" />
          <Column field="barcode" :header="pt('Barcode')" style="width: 9rem" />
          <Column header="" style="width: 4rem">
            <template #body="{ data }">
              <Button icon="pi pi-plus" text rounded size="small" @click="addToCart(data)" />
            </template>
          </Column>
          <template #empty>{{ pt('No products found.') }}</template>
        </DataTable>
      </div>

      <div class="panel cart-panel">
        <h2>{{ pt('Labels to print ({n})', { n: totalLabels }) }}</h2>

        <table class="cart-table" v-if="cart.length">
          <thead>
            <tr><th>{{ pt('Product') }}</th><th>{{ pt('Qty') }}</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in cart" :key="`${l.type}-${l.id}`">
              <td>
                <div class="cart-name">{{ l.name }}</div>
                <small class="cart-sub">{{ l.sku }}</small>
              </td>
              <td><InputNumber v-model="l.quantity" :min="1" :max="500" showButtons buttonLayout="horizontal" style="width: 8rem" /></td>
              <td><Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="empty-cart">{{ pt('No items added yet.') }}</p>

        <!-- Live preview -->
        <div class="preview-wrap" v-if="previewItem">
          <label class="section-label">{{ pt('Preview') }}</label>
          <div class="preview" :class="`prev-${template}`">
            <div v-if="showCompany && (template === 'sticker' || template === 'price_tag')" class="p-company">{{ pt('Your Company') }}</div>
            <div class="p-name">{{ previewItem.name }}</div>
            <div v-if="template === 'sticker'" class="p-sub">{{ previewItem.sku }}</div>
            <div v-if="template === 'sticker' && (previewItem.category || previewItem.brand)" class="p-meta">
              {{ [previewItem.category, previewItem.brand].filter(Boolean).join(' · ') }}
            </div>
            <div
              v-if="template === 'price_tag' && showPrice && previewItem.price != null"
              class="p-price big"
            >₹ {{ Number(previewItem.price).toFixed(2) }}</div>
            <img v-if="previewImg" :src="previewImg" :class="{ qr: effectiveSymbology === 'qr' }" alt="preview" />
            <div class="p-value">{{ previewItem.barcode }}</div>
            <div
              v-if="template === 'sticker' && showPrice && previewItem.price != null"
              class="p-price"
            >₹ {{ Number(previewItem.price).toFixed(2) }}</div>
          </div>
        </div>

        <div class="print-controls">
          <template v-if="allowsSymbologyChoice">
            <label class="section-label">{{ pt('Code Type') }}</label>
            <Select v-model="symbology"
              :options="[{ label: pt('CODE128 Barcode'), value: 'code128' }, { label: pt('QR Code'), value: 'qr' }]"
              optionLabel="label" optionValue="value" />
          </template>

          <div v-if="showsPriceOption" class="toggle-row">
            <ToggleSwitch v-model="showCompany" inputId="tg-company" />
            <label for="tg-company">{{ pt('Show company name') }}</label>
          </div>
          <div v-if="showsPriceOption" class="toggle-row">
            <ToggleSwitch v-model="showPrice" inputId="tg-price" />
            <label for="tg-price">{{ pt('Show price') }}</label>
          </div>

          <Button :label="pt('Generate PDF')" icon="pi pi-print" :loading="printing" :disabled="!cart.length" @click="generatePdf" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }

.templates {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}
.template-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.85rem 1rem;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.template-card:hover { border-color: var(--safety-amber-light, #f2c274); }
.template-card.active { border-color: var(--safety-amber, #e8a33d); box-shadow: 0 0 0 1px var(--safety-amber, #e8a33d) inset; }
.template-card i { font-size: 1.15rem; color: var(--safety-amber, #e8a33d); }
.tpl-label { font-weight: 600; font-size: 0.92rem; }
.tpl-desc { font-size: 0.75rem; color: #6b7280; }

.layout {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 1.25rem;
  align-items: start;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.panel {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem;
}
.search { position: relative; display: block; margin-bottom: 0.75rem; }
.search i { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search :deep(input) { width: 100%; padding-left: 2.25rem; }
.cart-panel h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: 0.75rem; }
.cart-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
.cart-table th { text-align: left; font-size: 0.78rem; color: #6b7280; padding: 0.35rem; border-bottom: 1px solid #e5e7eb; }
.cart-table td { padding: 0.4rem 0.35rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.cart-name { font-size: 0.9rem; font-weight: 500; }
.cart-sub { color: #6b7280; }
.empty-cart { color: #6b7280; margin-bottom: 1rem; }

.section-label { font-size: 0.8rem; color: #374151; }

/* Preview */
.preview-wrap { border-top: 1px solid #f3f4f6; padding-top: 0.85rem; margin-bottom: 0.5rem; }
.preview {
  margin-top: 0.4rem;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  padding: 0.6rem;
  text-align: center;
  background: #fafafa;
}
.preview img { display: block; margin: 0.35rem auto; max-height: 46px; max-width: 100%; }
.preview img.qr { max-height: 84px; }
.preview .p-company { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #374151; }
.preview .p-name { font-size: 0.82rem; font-weight: 600; }
.preview .p-sub { font-size: 0.72rem; color: #4b5563; }
.preview .p-meta { font-size: 0.66rem; color: #6b7280; }
.preview .p-value { font-size: 0.66rem; letter-spacing: 0.4px; color: #374151; }
.preview .p-price { font-size: 0.85rem; font-weight: 700; }
.preview .p-price.big { font-size: 1.5rem; font-weight: 800; }
.prev-sticker { text-align: left; }

.print-controls {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
}
.toggle-row { display: flex; align-items: center; gap: 0.5rem; }
.toggle-row label { font-size: 0.85rem; color: #374151; }
</style>
