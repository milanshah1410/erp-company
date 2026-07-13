<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const toast = useToast()

const warehouses = ref<any[]>([])
const customers = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const warehouseId = ref<number | null>(null)
const customerId = ref<number | null>(null)

const cart = ref<any[]>([])
const payments = ref<any[]>([{ mode: 'cash', amount: 0 }])
const checkingOut = ref(false)
const errors = ref<Record<string, string[]>>({})

const paymentModes = [
  { label: 'Cash', value: 'cash' },
  { label: 'Card', value: 'card' },
  { label: 'UPI', value: 'upi' },
  { label: 'Credit', value: 'credit' },
]

const filteredProducts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return products.value
  return products.value.filter((p: any) =>
    p.name?.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q) || p.barcode?.toLowerCase().includes(q),
  )
})

const subtotal = computed(() => cart.value.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0))
const taxTotal = computed(() =>
  cart.value.reduce((s, l) => s + ((Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * (Number(l.tax_percent) || 0)) / 100, 0),
)
const total = computed(() => subtotal.value + taxTotal.value)
const paidTotal = computed(() => payments.value.reduce((s, p) => s + (Number(p.amount) || 0), 0))
const balance = computed(() => Math.round((total.value - paidTotal.value) * 100) / 100)

function addToCart(product: any) {
  const existing = cart.value.find((l) => l.product_id === product.id)
  if (existing) {
    existing.quantity = Number(existing.quantity) + 1
    return
  }
  cart.value.push({
    product_id: product.id,
    name: product.name,
    sku: product.sku,
    quantity: 1,
    unit_price: Number(product.selling_price) || 0,
    tax_percent: Number(product.tax_percent) || 0,
  })
}
function removeLine(i: number) {
  cart.value.splice(i, 1)
}
function addPaymentLine() {
  payments.value.push({ mode: 'cash', amount: Math.max(balance.value, 0) })
}
function removePaymentLine(i: number) {
  payments.value.splice(i, 1)
}
function money(v: number) {
  return formatCurrency(v)
}

async function loadProducts() {
  try {
    const { data } = await http.get('/pos/products', { params: { warehouse_id: warehouseId.value, search: search.value || undefined } })
    products.value = data.data ?? []
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load products', life: 3000 })
  }
}

// --- Receipt dialog ---
const receiptVisible = ref(false)
const receipt = ref<any>(null)

async function completeSale() {
  errors.value = {}
  if (!warehouseId.value) {
    toast.add({ severity: 'warn', summary: 'Select a warehouse first', life: 3000 })
    return
  }
  if (!cart.value.length) {
    toast.add({ severity: 'warn', summary: 'Cart is empty', life: 3000 })
    return
  }

  checkingOut.value = true
  try {
    const { data } = await http.post('/pos/checkout', {
      warehouse_id: warehouseId.value,
      customer_id: customerId.value,
      items: cart.value.map((l) => ({
        product_id: l.product_id,
        quantity: l.quantity,
        unit_price: l.unit_price,
        tax_percent: l.tax_percent,
      })),
      payments: payments.value,
    })
    receipt.value = { ...data.data, invoice: data.invoice }
    receiptVisible.value = true
    toast.add({ severity: 'success', summary: 'Sale completed', life: 2500 })
    resetSale()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    toast.add({ severity: 'error', summary: 'Checkout failed', detail: e.response?.data?.message || 'Failed', life: 6000 })
  } finally {
    checkingOut.value = false
  }
}

function resetSale() {
  cart.value = []
  payments.value = [{ mode: 'cash', amount: 0 }]
  customerId.value = null
  loadProducts()
}

watch(warehouseId, loadProducts)
watch(search, loadProducts)

onMounted(async () => {
  const [w, c] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 100 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  if (warehouses.value.length === 1) warehouseId.value = warehouses.value[0].value
  loadProducts()
})
</script>

<template>
  <div class="pos">
    <div class="pos-left">
      <div class="pos-toolbar">
        <Select v-model="warehouseId" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Warehouse" class="wh-select" />
        <InputText v-model="search" placeholder="Search product by name, SKU or barcode" class="search-input" />
      </div>
      <div class="product-grid">
        <div v-for="p in filteredProducts" :key="p.id" class="product-card" @click="addToCart(p)">
          <div class="p-name">{{ p.name }}</div>
          <div class="p-sku">{{ p.sku }}</div>
          <div class="p-price">{{ money(p.selling_price) }}</div>
          <div class="p-stock" v-if="p.available_qty !== undefined">Avail: {{ p.available_qty }}</div>
        </div>
        <div v-if="!filteredProducts.length" class="muted">No products found.</div>
      </div>
    </div>

    <div class="pos-right">
      <div class="fld">
        <label>Customer</label>
        <Select v-model="customerId" :options="customers" optionLabel="label" optionValue="value" filter showClear placeholder="Walk-in Customer" class="w-full" />
      </div>

      <h3 class="lines-title">Cart</h3>
      <table class="lines">
        <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in cart" :key="i">
            <td>{{ l.name }}</td>
            <td><InputNumber v-model="l.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td>{{ money(l.quantity * l.unit_price * (1 + l.tax_percent / 100)) }}</td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
          <tr v-if="!cart.length"><td colspan="4" class="muted" style="text-align: center">Cart is empty.</td></tr>
        </tbody>
      </table>
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="totals">
        <div><span>Subtotal</span><span>{{ money(subtotal) }}</span></div>
        <div><span>Tax</span><span>{{ money(taxTotal) }}</span></div>
        <div class="grand"><span>Total</span><span>{{ money(total) }}</span></div>
      </div>

      <h3 class="lines-title">Payment</h3>
      <div v-for="(p, i) in payments" :key="i" class="pay-line">
        <Select v-model="p.mode" :options="paymentModes" optionLabel="label" optionValue="value" class="pay-mode" />
        <InputNumber v-model="p.amount" :minFractionDigits="2" :maxFractionDigits="2" class="pay-amount" />
        <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removePaymentLine(i)" :disabled="payments.length === 1" />
      </div>
      <Button label="Add Payment Line" icon="pi pi-plus" text size="small" @click="addPaymentLine" />
      <small v-if="errors.payments" class="err block">{{ errors.payments[0] }}</small>

      <div class="balance" :class="{ due: balance > 0.005, over: balance < -0.005 }">
        Balance: <strong>{{ money(balance) }}</strong>
      </div>

      <Button label="Complete Sale" icon="pi pi-check" class="complete-btn" :loading="checkingOut" @click="completeSale" />
    </div>

    <!-- Receipt dialog -->
    <Dialog v-model:visible="receiptVisible" modal header="Sale Complete" :style="{ width: '460px' }">
      <template v-if="receipt">
        <p><strong>{{ receipt.so_number }}</strong> — Invoice <strong>{{ receipt.invoice?.invoice_number }}</strong></p>
        <div class="inv-summary">
          <div class="inv-cell"><span class="inv-label">Total</span><span class="inv-value">{{ money(receipt.invoice?.total) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Paid</span><span class="inv-value">{{ money(receipt.invoice?.paid_amount) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Balance Due</span><span class="inv-value due">{{ money(receipt.invoice?.balance_due) }}</span></div>
          <div class="inv-cell"><span class="inv-label">Status</span><Tag :value="receipt.invoice?.status" /></div>
        </div>
      </template>
      <template #footer>
        <Button label="Close" @click="receiptVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.pos { display: grid; grid-template-columns: 1fr 380px; gap: 1rem; height: calc(100vh - 6rem); }
.pos-left { display: flex; flex-direction: column; min-height: 0; }
.pos-toolbar { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
.wh-select { width: 220px; }
.search-input { flex: 1; }
.product-grid { flex: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.6rem; align-content: start; }
.product-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem; cursor: pointer; transition: box-shadow 0.15s; }
.product-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-color: #94a3b8; }
.p-name { font-weight: 600; font-size: 0.85rem; }
.p-sku { font-size: 0.7rem; color: #6b7280; }
.p-price { margin-top: 0.4rem; font-weight: 600; }
.p-stock { font-size: 0.7rem; color: #6b7280; }
.pos-right { border-left: 1px solid #e5e7eb; padding-left: 1rem; display: flex; flex-direction: column; overflow-y: auto; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.5rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.w-full :deep(.p-select) { width: 100%; }
.lines-title { font-size: 0.95rem; margin: 0.75rem 0 0.4rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.75rem; color: #6b7280; padding: 0.3rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.3rem; }
.num :deep(input) { width: 60px; }
.totals { margin-top: 0.75rem; font-size: 0.9rem; }
.totals div { display: flex; justify-content: space-between; padding: 0.15rem 0; }
.totals .grand { font-size: 1.05rem; font-weight: 600; border-top: 1px solid #e5e7eb; margin-top: 0.25rem; padding-top: 0.4rem; }
.pay-line { display: flex; gap: 0.4rem; margin-bottom: 0.4rem; align-items: center; }
.pay-mode { width: 120px; }
.pay-amount { flex: 1; }
.pay-amount :deep(input) { width: 100%; }
.balance { margin-top: 0.75rem; font-size: 1rem; padding: 0.5rem; border-radius: 6px; background: #f8fafc; }
.balance.due { color: #b45309; background: #fffbeb; }
.balance.over { color: #dc2626; background: #fef2f2; }
.complete-btn { margin-top: 1rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.3rem; }
.muted { color: #6b7280; grid-column: 1 / -1; text-align: center; padding: 2rem 0; }
.inv-summary { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 0.75rem; }
.inv-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.inv-label { font-size: 0.75rem; color: #6b7280; }
.inv-value { font-weight: 600; font-size: 0.95rem; }
.inv-value.due { color: #dc2626; }
</style>
