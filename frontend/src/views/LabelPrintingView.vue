<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { openPdfBlob } from '../utils/blob'

const toast = useToast()

const search = ref('')
const products = ref<any[]>([])
const searching = ref(false)

const cart = ref<any[]>([])
const labelType = ref('code128')
const printing = ref(false)

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
    toast.add({ severity: 'warn', summary: 'No barcode', detail: `${product.name} has no barcode value yet.`, life: 3000 })
    return
  }
  const existing = cart.value.find((l) => l.type === 'product' && l.id === product.id)
  if (existing) {
    existing.quantity += 1
    return
  }
  cart.value.push({ type: 'product', id: product.id, name: product.name, sku: product.sku, barcode: product.barcode, quantity: 1 })
}

function removeLine(i: number) {
  cart.value.splice(i, 1)
}

const totalLabels = computed(() => cart.value.reduce((s, l) => s + (Number(l.quantity) || 0), 0))

async function generatePdf() {
  if (!cart.value.length) {
    toast.add({ severity: 'warn', summary: 'No items selected', life: 3000 })
    return
  }
  printing.value = true
  try {
    const { data } = await http.post(
      '/barcode/print-labels',
      {
        label_type: labelType.value,
        items: cart.value.map((l) => ({ type: l.type, id: l.id, quantity: l.quantity })),
      },
      { responseType: 'blob' },
    )
    openPdfBlob(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Could not generate labels', life: 4000 })
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
    <h1>Bulk Label Printing</h1>
    <p class="subtitle">Search products, add them with a quantity, then print a sheet of barcode/QR labels.</p>

    <div class="layout">
      <div class="panel">
        <span class="search">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search products by name / SKU / barcode..." />
        </span>

        <DataTable :value="products" :loading="searching" class="p-datatable-sm" dataKey="id">
          <Column field="sku" header="SKU" style="width: 8rem" />
          <Column field="name" header="Name" />
          <Column field="barcode" header="Barcode" style="width: 9rem" />
          <Column header="" style="width: 4rem">
            <template #body="{ data }">
              <Button icon="pi pi-plus" text rounded size="small" @click="addToCart(data)" />
            </template>
          </Column>
          <template #empty>No products found.</template>
        </DataTable>
      </div>

      <div class="panel cart-panel">
        <h2>Labels to print ({{ totalLabels }})</h2>

        <table class="cart-table" v-if="cart.length">
          <thead>
            <tr><th>Product</th><th>Qty</th><th></th></tr>
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
        <p v-else class="empty-cart">No items added yet.</p>

        <div class="print-controls">
          <label>Label Type</label>
          <Select v-model="labelType" :options="[{ label: 'CODE128 Barcode', value: 'code128' }, { label: 'QR Code', value: 'qr' }]"
            optionLabel="label" optionValue="value" />
          <Button label="Generate PDF" icon="pi pi-print" :loading="printing" :disabled="!cart.length" @click="generatePdf" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.layout {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 1.25rem;
  align-items: start;
}
.panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1rem;
}
.search {
  position: relative;
  display: block;
  margin-bottom: 0.75rem;
}
.search i {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
}
.search :deep(input) {
  width: 100%;
  padding-left: 2.25rem;
}
.cart-panel h2 {
  font-size: 1.05rem;
  font-weight: 600;
  margin-bottom: 0.75rem;
}
.cart-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1rem;
}
.cart-table th {
  text-align: left;
  font-size: 0.78rem;
  color: #6b7280;
  padding: 0.35rem;
  border-bottom: 1px solid #e5e7eb;
}
.cart-table td {
  padding: 0.4rem 0.35rem;
  border-bottom: 1px solid #f3f4f6;
  vertical-align: middle;
}
.cart-name { font-size: 0.9rem; font-weight: 500; }
.cart-sub { color: #6b7280; }
.empty-cart { color: #6b7280; margin-bottom: 1rem; }
.print-controls {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
}
.print-controls label {
  font-size: 0.8rem;
  color: #374151;
}
</style>
