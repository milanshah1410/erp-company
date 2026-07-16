<script setup lang="ts">
/**
 * Retail POS terminal. One screen drives three counter flows — Sell, Return
 * and Exchange — plus the surrounding hardware integration:
 *   - Barcode scanner: the search box stays focused so a USB (keyboard-wedge)
 *     scanner types into it; Enter resolves an exact code via /pos/lookup.
 *   - Thermal printer + cash drawer: see utils/posReceipt.ts (browser HTML
 *     print or raw ESC/POS to a local print agent that also kicks the drawer).
 *   - Customer display: a second window mirrors the cart over a BroadcastChannel.
 *   - Fast billing: keyboard shortcuts (F2 scan, F4 exact cash, F9 complete).
 */
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import { priceDocument, type DiscountType } from '../utils/pricing'
import {
  loadDeviceSettings,
  saveDeviceSettings,
  createDisplayChannel,
  type PosDeviceSettings,
  type CustomerDisplayState,
} from '../utils/posDevice'
import { printReceipt, openCashDrawer, type ReceiptData } from '../utils/posReceipt'

const toast = useToast()
const auth = useAuthStore()

const modeOptions = [
  { label: 'Sell', value: 'sell', icon: 'pi pi-shopping-bag' },
  { label: 'Return', value: 'return', icon: 'pi pi-replay' },
  { label: 'Exchange', value: 'exchange', icon: 'pi pi-sync' },
]
const mode = ref<'sell' | 'return' | 'exchange'>('sell')

const discountTypes = [
  { label: '%', value: 'percent' },
  { label: '₹', value: 'flat' },
]
const paymentModes = [
  { label: 'Cash', value: 'cash' },
  { label: 'Card', value: 'card' },
  { label: 'UPI', value: 'upi' },
  { label: 'Credit', value: 'credit' },
]

const warehouses = ref<any[]>([])
const customers = ref<any[]>([])
const brokers = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const warehouseId = ref<number | null>(null)
const customerId = ref<number | null>(null)
const brokerId = ref<number | null>(null)

// New-sale cart (Sell + Exchange)
const cart = ref<any[]>([])
const overallDiscountType = ref<DiscountType>(null)
const overallDiscountValue = ref<number>(0)
const payments = ref<any[]>([{ mode: 'cash', amount: 0 }])
const checkingOut = ref(false)
const errors = ref<Record<string, string[]>>({})

// Return / Exchange invoice context
const invoiceSearch = ref('')
const invoiceResults = ref<any[]>([])
const searchingInvoices = ref(false)
const loadedInvoice = ref<any>(null)
const returnLines = ref<any[]>([])
const refundMode = ref<'cash' | 'card' | 'upi' | 'credit'>('cash')

// Device + display
const device = ref<PosDeviceSettings>(loadDeviceSettings())
const settingsVisible = ref(false)
const draftSettings = ref<PosDeviceSettings>({ ...device.value })
let displayChannel: BroadcastChannel | null = null
let audioCtx: AudioContext | null = null

// ---- Pricing ----------------------------------------------------------------

const filteredProducts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return products.value
  return products.value.filter(
    (p: any) => p.name?.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q) || p.barcode?.toLowerCase().includes(q),
  )
})

const pricing = computed(() => priceDocument(cart.value, overallDiscountType.value, overallDiscountValue.value))
const total = computed(() => pricing.value.header.total)
const paidTotal = computed(() => payments.value.reduce((s, p) => s + (Number(p.amount) || 0), 0))
const balance = computed(() => round2(total.value - paidTotal.value))
const change = computed(() => Math.max(0, round2(paidTotal.value - total.value)))

const selectedReturnItems = computed(() =>
  returnLines.value
    .filter((l) => Number(l.return_qty) > 0)
    .map((l) => ({
      product_id: l.product_id,
      variant_id: l.variant_id,
      quantity: Number(l.return_qty),
      unit_price: Number(l.unit_price),
      tax_percent: Number(l.tax_percent),
      discount_type: l.discount_type,
      discount_value: Number(l.discount_value) || 0,
      reason: l.reason || null,
    })),
)
const returnPricing = computed(() => priceDocument(selectedReturnItems.value))
const returnTotal = computed(() => returnPricing.value.header.total)

// Exchange net: new sale total minus returned value.
const exchangeNet = computed(() => round2(total.value - returnTotal.value))
const netPayable = computed(() => Math.max(0, exchangeNet.value))
const netRefund = computed(() => Math.max(0, -exchangeNet.value))

function round2(v: number) {
  return Math.round((v + Number.EPSILON) * 100) / 100
}
function money(v: number) {
  return formatCurrency(v)
}

// ---- Cart operations --------------------------------------------------------

function addToCart(product: any, qty = 1) {
  const pid = product.product_id ?? product.id
  const variantId = product.variant_id ?? null
  const match = cart.value.find((l) => l.product_id === pid && l.variant_id === variantId)
  if (match) {
    match.quantity = Number(match.quantity) + qty
    return
  }
  cart.value.push({
    product_id: pid,
    variant_id: variantId,
    name: product.name,
    sku: product.sku,
    quantity: qty,
    unit_price: Number(product.unit_price ?? product.selling_price) || 0,
    tax_percent: Number(product.tax_percent) || 0,
    discount_type: null,
    discount_value: 0,
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
function exactCash() {
  const cashLine = payments.value.find((p) => p.mode === 'cash')
  const others = payments.value.filter((p) => p.mode !== 'cash').reduce((s, p) => s + (Number(p.amount) || 0), 0)
  const need = round2(total.value - others)
  if (cashLine) cashLine.amount = Math.max(0, need)
  else payments.value.push({ mode: 'cash', amount: Math.max(0, need) })
}
function addCash(amount: number) {
  const cashLine = payments.value.find((p) => p.mode === 'cash')
  if (cashLine) cashLine.amount = round2((Number(cashLine.amount) || 0) + amount)
  else payments.value.push({ mode: 'cash', amount })
}

// ---- Barcode scan + product load --------------------------------------------

function focusScan() {
  nextTick(() => document.getElementById('pos-scan')?.focus())
}

function beep() {
  if (!device.value.beepOnScan) return
  try {
    audioCtx = audioCtx || new (window.AudioContext || (window as any).webkitAudioContext)()
    const o = audioCtx.createOscillator()
    const g = audioCtx.createGain()
    o.connect(g)
    g.connect(audioCtx.destination)
    o.frequency.value = 880
    g.gain.value = 0.08
    o.start()
    o.stop(audioCtx.currentTime + 0.07)
  } catch {
    /* audio not available */
  }
}

async function loadProducts() {
  try {
    const { data } = await http.get('/pos/products', {
      params: { warehouse_id: warehouseId.value, search: search.value || undefined },
    })
    products.value = (data.data ?? []).map((p: any) => ({ ...p, unit_price: p.selling_price }))
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load products', life: 3000 })
  }
}

/** Enter in the scan/search box: resolve an exact code and add one unit. */
async function onScanEnter() {
  const code = search.value.trim()
  if (!code) return
  try {
    const { data } = await http.get('/pos/lookup', { params: { code, warehouse_id: warehouseId.value } })
    addToCart(data.data, 1)
    beep()
    search.value = ''
    await loadProducts()
  } catch (e: any) {
    // No exact match — if the visible grid narrowed to one product, take it.
    if (filteredProducts.value.length === 1) {
      addToCart(filteredProducts.value[0], 1)
      beep()
      search.value = ''
      await loadProducts()
    } else {
      toast.add({ severity: 'warn', summary: 'Not found', detail: e.response?.data?.message || `No match for "${code}"`, life: 2500 })
    }
  }
  focusScan()
}

// ---- Invoice lookup (return / exchange) -------------------------------------

async function findInvoices() {
  searchingInvoices.value = true
  try {
    const { data } = await http.get('/pos/invoices', { params: { search: invoiceSearch.value || undefined } })
    invoiceResults.value = data.data ?? []
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoices', life: 3000 })
  } finally {
    searchingInvoices.value = false
  }
}

async function loadInvoice(id: number) {
  try {
    const { data } = await http.get(`/pos/invoices/${id}`)
    loadedInvoice.value = data.data
    invoiceResults.value = []
    invoiceSearch.value = data.data.invoice_number
    returnLines.value = (data.data.lines ?? [])
      .filter((l: any) => l.returnable_quantity > 0)
      .map((l: any) => ({ ...l, return_qty: 0 }))
    if (data.data.warehouse_id) warehouseId.value = data.data.warehouse_id
    if (data.data.customer_id) customerId.value = data.data.customer_id
    if (!returnLines.value.length) {
      toast.add({ severity: 'info', summary: 'Nothing returnable', detail: 'All lines on this invoice are already returned.', life: 4000 })
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Could not load invoice', detail: e.response?.data?.message, life: 4000 })
  }
}

function returnAll() {
  returnLines.value.forEach((l) => (l.return_qty = l.returnable_quantity))
}

// ---- Complete: sell / return / exchange -------------------------------------

async function completeSale() {
  errors.value = {}
  if (!warehouseId.value) return toast.add({ severity: 'warn', summary: 'Select a warehouse first', life: 3000 })
  if (!cart.value.length) return toast.add({ severity: 'warn', summary: 'Cart is empty', life: 3000 })

  const snapshot = snapshotSale()
  checkingOut.value = true
  try {
    const { data } = await http.post('/pos/checkout', {
      warehouse_id: warehouseId.value,
      customer_id: customerId.value,
      broker_id: brokerId.value,
      discount_type: overallDiscountType.value,
      discount_value: overallDiscountValue.value,
      items: cartPayload(),
      payments: payments.value.filter((p) => Number(p.amount) > 0),
    })
    await onSaleComplete(buildSaleReceipt('TAX INVOICE', data, snapshot), snapshot.hasCash)
    resetSale()
  } catch (e: any) {
    handleError(e, 'Checkout failed')
  } finally {
    checkingOut.value = false
  }
}

async function completeReturn() {
  errors.value = {}
  if (!loadedInvoice.value) return toast.add({ severity: 'warn', summary: 'Load an invoice first', life: 3000 })
  if (!selectedReturnItems.value.length) return toast.add({ severity: 'warn', summary: 'Set a return quantity', life: 3000 })

  checkingOut.value = true
  try {
    const { data } = await http.post('/pos/return', {
      invoice_id: loadedInvoice.value.id,
      refund_mode: refundMode.value,
      items: selectedReturnItems.value,
    })
    const receipt = buildReturnReceipt(data.data)
    await onSaleComplete(receipt, refundMode.value === 'cash')
    toast.add({ severity: 'success', summary: 'Return processed', detail: `${data.data.credit_note_number} · refund ${money(data.data.refund_amount)}`, life: 4000 })
    resetReturn()
  } catch (e: any) {
    handleError(e, 'Return failed')
  } finally {
    checkingOut.value = false
  }
}

async function completeExchange() {
  errors.value = {}
  if (!loadedInvoice.value) return toast.add({ severity: 'warn', summary: 'Load an invoice first', life: 3000 })
  if (!selectedReturnItems.value.length) return toast.add({ severity: 'warn', summary: 'Choose items to return', life: 3000 })
  if (!cart.value.length) return toast.add({ severity: 'warn', summary: 'Add the new items', life: 3000 })
  if (!warehouseId.value) return toast.add({ severity: 'warn', summary: 'Select a warehouse first', life: 3000 })

  const snapshot = snapshotSale()
  checkingOut.value = true
  try {
    const { data } = await http.post('/pos/exchange', {
      warehouse_id: warehouseId.value,
      original_invoice_id: loadedInvoice.value.id,
      customer_id: customerId.value,
      broker_id: brokerId.value,
      refund_mode: refundMode.value,
      return_items: selectedReturnItems.value,
      items: cartPayload(),
      discount_type: overallDiscountType.value,
      discount_value: overallDiscountValue.value,
      payments: payments.value.filter((p) => Number(p.amount) > 0),
    })
    const receipt = buildSaleReceipt('EXCHANGE', data, snapshot)
    receipt.extra = [
      { label: 'Credit Note', value: data.exchange?.credit_note_number ?? '' },
      { label: 'Return Value', value: money(data.exchange?.return_total ?? 0) },
      { label: 'New Sale', value: money(data.exchange?.new_total ?? 0) },
      data.exchange?.net_refund > 0
        ? { label: 'Net Refund', value: money(data.exchange.net_refund) }
        : { label: 'Net Payable', value: money(data.exchange?.net_payable ?? 0) },
    ]
    await onSaleComplete(receipt, snapshot.hasCash)
    toast.add({ severity: 'success', summary: 'Exchange complete', detail: data.exchange?.net_refund > 0 ? `Refund ${money(data.exchange.net_refund)}` : `Collected ${money(data.exchange?.net_payable ?? 0)}`, life: 4500 })
    resetSale()
    resetReturn()
  } catch (e: any) {
    handleError(e, 'Exchange failed')
  } finally {
    checkingOut.value = false
  }
}

function cartPayload() {
  return cart.value.map((l) => ({
    product_id: l.product_id,
    variant_id: l.variant_id,
    quantity: l.quantity,
    unit_price: l.unit_price,
    tax_percent: l.tax_percent,
    discount_type: l.discount_type,
    discount_value: l.discount_value,
  }))
}

function handleError(e: any, summary: string) {
  if (e.response?.status === 422) errors.value = e.response.data.errors || {}
  toast.add({ severity: 'error', summary, detail: firstError(e) || e.response?.data?.message || 'Failed', life: 6000 })
}
function firstError(e: any): string | null {
  const errs = e.response?.data?.errors
  if (!errs) return null
  const first = Object.values(errs)[0]
  return Array.isArray(first) ? (first[0] as string) : null
}

// ---- Receipt building -------------------------------------------------------

function snapshotSale() {
  const lines = pricing.value.lines.map((pl, i) => ({
    name: cart.value[i]?.name ?? '',
    qty: pl.quantity,
    unit_price: pl.unit_price,
    line_total: pl.line_total,
  }))
  const activePayments = payments.value.filter((p) => Number(p.amount) > 0).map((p) => ({ mode: p.mode, amount: Number(p.amount) }))
  return {
    lines,
    header: { ...pricing.value.header },
    payments: activePayments,
    paidTotal: paidTotal.value,
    change: change.value,
    hasCash: activePayments.some((p) => p.mode === 'cash'),
  }
}

function buildSaleReceipt(title: string, data: any, snap: ReturnType<typeof snapshotSale>): ReceiptData {
  const inv = data.invoice ?? {}
  return {
    title,
    docNumber: inv.invoice_number ?? data.data?.so_number ?? '',
    soNumber: data.data?.so_number,
    date: new Date().toLocaleString('en-IN'),
    cashier: auth.user?.name,
    customer: data.data?.customer?.name,
    lines: snap.lines,
    subtotal: snap.header.subtotal,
    itemDiscount: snap.header.item_discount_total,
    invoiceDiscount: snap.header.discount_amount,
    tax: snap.header.tax_amount,
    roundOff: snap.header.round_off,
    total: snap.header.total,
    payments: snap.payments,
    paid: inv.paid_amount,
    balance: inv.balance_due,
    change: snap.change,
  }
}

function buildReturnReceipt(d: any): ReceiptData {
  const rp = returnPricing.value
  const lines = rp.lines.map((pl, i) => ({
    name: returnLines.value.filter((l) => Number(l.return_qty) > 0)[i]?.name ?? '',
    qty: pl.quantity,
    unit_price: pl.unit_price,
    line_total: pl.line_total,
  }))
  return {
    title: 'CREDIT NOTE',
    docNumber: d.credit_note_number,
    soNumber: d.invoice_number,
    date: new Date().toLocaleString('en-IN'),
    cashier: auth.user?.name,
    customer: loadedInvoice.value?.customer,
    lines,
    subtotal: rp.header.subtotal,
    itemDiscount: rp.header.item_discount_total,
    invoiceDiscount: rp.header.discount_amount,
    tax: rp.header.tax_amount,
    roundOff: rp.header.round_off,
    total: rp.header.total,
    extra: [
      { label: 'Refund Mode', value: (d.refund_mode || '').toUpperCase() },
      { label: 'Refund', value: money(d.refund_amount) },
    ],
  }
}

// ---- Post-completion: print, drawer, display, dialog ------------------------

const receiptVisible = ref(false)
const lastReceipt = ref<ReceiptData | null>(null)

async function onSaleComplete(receipt: ReceiptData, hasCash: boolean) {
  lastReceipt.value = receipt
  receiptVisible.value = true
  broadcastDone(receipt)

  if (device.value.autoPrint) {
    try {
      await printReceipt(receipt, device.value, { shopName: shopName(), symbol: auth.currencySymbol, hasCash })
    } catch {
      toast.add({ severity: 'warn', summary: 'Print failed', detail: 'Could not reach the printer/agent.', life: 4000 })
    }
  } else if (device.value.openDrawerOnCash && hasCash) {
    openCashDrawer(device.value).catch(() => {})
  }
}

async function reprint() {
  if (!lastReceipt.value) return
  try {
    await printReceipt(lastReceipt.value, device.value, {
      shopName: shopName(),
      symbol: auth.currencySymbol,
      hasCash: (lastReceipt.value.payments || []).some((p) => p.mode === 'cash'),
    })
  } catch {
    toast.add({ severity: 'warn', summary: 'Print failed', life: 3000 })
  }
}

async function kickDrawer() {
  const ok = await openCashDrawer(device.value).catch(() => false)
  if (!ok) toast.add({ severity: 'info', summary: 'Cash drawer', detail: 'Drawer kick needs a print agent (see device settings).', life: 4000 })
}

// ---- Customer display -------------------------------------------------------

function shopName() {
  return auth.user?.current_company?.name || 'Point of Sale'
}

function openCustomerDisplay() {
  window.open('/pos-display', 'pos-customer-display', 'width=800,height=600')
  setTimeout(broadcastCart, 400)
}

function displayState(status: CustomerDisplayState['status']): CustomerDisplayState {
  const h = pricing.value.header
  return {
    shopName: shopName(),
    currencySymbol: auth.currencySymbol,
    lines: pricing.value.lines.map((pl, i) => ({
      name: cart.value[i]?.name ?? '',
      qty: pl.quantity,
      unit_price: pl.unit_price,
      line_total: pl.line_total,
    })),
    subtotal: h.subtotal,
    discount: round2(h.item_discount_total + h.discount_amount),
    tax: h.tax_amount,
    total: h.total,
    balance: balance.value > 0 ? balance.value : 0,
    change: change.value,
    status,
  }
}

function broadcastCart() {
  if (!displayChannel) return
  displayChannel.postMessage({ type: 'update', payload: displayState(cart.value.length ? 'selling' : 'idle') })
}

function broadcastDone(receipt: ReceiptData) {
  if (!displayChannel) return
  displayChannel.postMessage({
    type: 'update',
    payload: {
      shopName: shopName(),
      currencySymbol: auth.currencySymbol,
      lines: receipt.lines,
      subtotal: receipt.subtotal,
      discount: round2((receipt.itemDiscount || 0) + (receipt.invoiceDiscount || 0)),
      tax: receipt.tax,
      total: receipt.total,
      balance: receipt.balance,
      change: receipt.change,
      status: 'done',
      message: 'Thank you!',
    } as CustomerDisplayState,
  })
  setTimeout(() => displayChannel?.postMessage({ type: 'update', payload: displayState('idle') }), 8000)
}

// ---- Device settings --------------------------------------------------------

function openSettings() {
  draftSettings.value = { ...device.value }
  settingsVisible.value = true
}
function saveSettings() {
  device.value = { ...draftSettings.value }
  saveDeviceSettings(device.value)
  settingsVisible.value = false
  toast.add({ severity: 'success', summary: 'Device settings saved', life: 2500 })
}

// ---- Reset ------------------------------------------------------------------

function resetSale() {
  cart.value = []
  overallDiscountType.value = null
  overallDiscountValue.value = 0
  payments.value = [{ mode: 'cash', amount: 0 }]
  if (mode.value === 'sell') {
    customerId.value = null
    brokerId.value = null
  }
  loadProducts()
  focusScan()
}
function resetReturn() {
  loadedInvoice.value = null
  returnLines.value = []
  invoiceResults.value = []
  invoiceSearch.value = ''
  refundMode.value = 'cash'
}

watch(mode, () => {
  errors.value = {}
  if (mode.value === 'sell') resetReturn()
  broadcastCart()
})

// Keep the customer display in sync with the cart / payments.
watch([cart, pricing, payments], broadcastCart, { deep: true })

// ---- Keyboard shortcuts (fast billing) --------------------------------------

function onKeydown(e: KeyboardEvent) {
  if (settingsVisible.value) return
  if (e.key === 'F2') {
    e.preventDefault()
    focusScan()
  } else if (e.key === 'F4') {
    e.preventDefault()
    exactCash()
  } else if (e.key === 'F9') {
    e.preventDefault()
    if (mode.value === 'sell') completeSale()
    else if (mode.value === 'return') completeReturn()
    else completeExchange()
  } else if (e.key === 'Escape') {
    if (receiptVisible.value) receiptVisible.value = false
    else if (cart.value.length) resetSale()
  }
}

// ---- Lifecycle --------------------------------------------------------------

watch(warehouseId, loadProducts)
let searchTimer: any = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadProducts, 250)
})

onMounted(async () => {
  displayChannel = createDisplayChannel()
  if (displayChannel) {
    displayChannel.onmessage = (e: MessageEvent) => {
      if (e.data?.type === 'display-ready') broadcastCart()
    }
  }
  window.addEventListener('keydown', onKeydown)

  const [w, c, b] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 100 } }),
    http.get('/brokers', { params: { per_page: 100, is_active: true } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  brokers.value = (b.data.data ?? b.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  if (warehouses.value.length === 1) warehouseId.value = warehouses.value[0].value
  loadProducts()
  focusScan()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  displayChannel?.close()
})
</script>

<template>
  <div class="pos-wrap">
    <!-- Top bar -->
    <div class="pos-bar">
      <SelectButton v-model="mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" class="mode-switch">
        <template #option="{ option }"><i :class="option.icon" style="margin-right: 0.4rem" />{{ option.label }}</template>
      </SelectButton>
      <Select v-model="warehouseId" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Warehouse" class="wh-select" />
      <div class="bar-spacer" />
      <Button icon="pi pi-desktop" text rounded severity="secondary" title="Open customer display" @click="openCustomerDisplay" />
      <Button icon="pi pi-inbox" text rounded severity="secondary" title="Open cash drawer" @click="kickDrawer" />
      <Button icon="pi pi-cog" text rounded severity="secondary" title="Device settings" @click="openSettings" />
    </div>

    <!-- SELL / EXCHANGE: product grid + cart -->
    <div v-if="mode !== 'return'" class="pos">
      <div class="pos-left">
        <div class="pos-toolbar">
          <span class="p-input-icon-left scan-wrap">
            <i class="pi pi-qrcode" />
            <InputText id="pos-scan" v-model="search" placeholder="Scan barcode or search — Enter to add" class="search-input" @keyup.enter="onScanEnter" autocomplete="off" />
          </span>
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
        <!-- Exchange: returning panel -->
        <div v-if="mode === 'exchange'" class="exch-return">
          <h3 class="lines-title">Returning</h3>
          <div v-if="!loadedInvoice" class="inv-lookup">
            <InputText v-model="invoiceSearch" placeholder="Invoice # or customer" class="w-full" @keyup.enter="findInvoices" />
            <Button icon="pi pi-search" :loading="searchingInvoices" @click="findInvoices" />
          </div>
          <ul v-if="invoiceResults.length" class="inv-results">
            <li v-for="inv in invoiceResults" :key="inv.id" @click="loadInvoice(inv.id)">
              <strong>{{ inv.invoice_number }}</strong> · {{ inv.customer || 'Walk-in' }} · {{ money(inv.total) }}
              <Tag :value="inv.status" class="ml" />
            </li>
          </ul>
          <template v-if="loadedInvoice">
            <div class="inv-head">
              {{ loadedInvoice.invoice_number }} · {{ loadedInvoice.customer || 'Walk-in' }}
              <Button label="Change" text size="small" @click="resetReturn" />
            </div>
            <table class="lines">
              <thead><tr><th>Item</th><th>Ret. Qty</th><th>Max</th></tr></thead>
              <tbody>
                <tr v-for="(l, i) in returnLines" :key="i">
                  <td>{{ l.name }}</td>
                  <td><InputNumber v-model="l.return_qty" :min="0" :max="l.returnable_quantity" :maxFractionDigits="3" class="num" /></td>
                  <td class="muted">{{ l.returnable_quantity }}</td>
                </tr>
              </tbody>
            </table>
            <div class="ret-total"><span>Return Value</span><strong>{{ money(returnTotal) }}</strong></div>
          </template>
        </div>

        <div class="fld">
          <label>Customer</label>
          <Select v-model="customerId" :options="customers" optionLabel="label" optionValue="value" filter showClear placeholder="Walk-in Customer" class="w-full" />
        </div>
        <div class="fld">
          <label>Broker</label>
          <Select v-model="brokerId" :options="brokers" optionLabel="label" optionValue="value" filter showClear placeholder="No broker" class="w-full" />
        </div>

        <h3 class="lines-title">{{ mode === 'exchange' ? 'New Items' : 'Cart' }}</h3>
        <table class="lines">
          <thead><tr><th>Item</th><th>Qty</th><th>Disc</th><th>Total</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in cart" :key="i">
              <td>{{ l.name }}</td>
              <td><InputNumber v-model="l.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
              <td class="disc-cell">
                <Select v-model="l.discount_type" :options="discountTypes" optionLabel="label" optionValue="value" showClear placeholder="—" class="disc-type" />
                <InputNumber v-model="l.discount_value" :minFractionDigits="0" :maxFractionDigits="2" class="disc-val" :disabled="!l.discount_type" />
              </td>
              <td>{{ money(pricing.lines[i]?.line_total ?? 0) }}</td>
              <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
            </tr>
            <tr v-if="!cart.length"><td colspan="5" class="muted" style="text-align: center">Scan or tap a product.</td></tr>
          </tbody>
        </table>
        <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

        <div class="overall-disc">
          <label>Invoice Discount</label>
          <Select v-model="overallDiscountType" :options="discountTypes" optionLabel="label" optionValue="value" showClear placeholder="—" class="disc-type" />
          <InputNumber v-model="overallDiscountValue" :minFractionDigits="0" :maxFractionDigits="2" class="disc-val" :disabled="!overallDiscountType" />
        </div>

        <div class="totals">
          <div><span>Subtotal</span><span>{{ money(pricing.header.subtotal) }}</span></div>
          <div v-if="pricing.header.item_discount_total"><span>Item Discount</span><span>−{{ money(pricing.header.item_discount_total) }}</span></div>
          <div v-if="pricing.header.discount_amount"><span>Invoice Discount</span><span>−{{ money(pricing.header.discount_amount) }}</span></div>
          <div><span>GST</span><span>{{ money(pricing.header.tax_amount) }}</span></div>
          <div v-if="pricing.header.round_off"><span>Round Off</span><span>{{ money(pricing.header.round_off) }}</span></div>
          <div class="grand"><span>{{ mode === 'exchange' ? 'New Sale Total' : 'Grand Total' }}</span><span>{{ money(total) }}</span></div>
        </div>

        <!-- Exchange net settlement -->
        <div v-if="mode === 'exchange'" class="exch-net" :class="{ refund: netRefund > 0 }">
          <div><span>Less Return Value</span><span>−{{ money(returnTotal) }}</span></div>
          <div class="net-grand">
            <span>{{ netRefund > 0 ? 'Net Refund' : 'Net Payable' }}</span>
            <strong>{{ money(netRefund > 0 ? netRefund : netPayable) }}</strong>
          </div>
        </div>

        <h3 class="lines-title">{{ mode === 'exchange' && netRefund > 0 ? 'Refund' : 'Payment' }}</h3>
        <template v-if="!(mode === 'exchange' && netRefund > 0)">
          <div v-for="(p, i) in payments" :key="i" class="pay-line">
            <Select v-model="p.mode" :options="paymentModes" optionLabel="label" optionValue="value" class="pay-mode" />
            <InputNumber v-model="p.amount" :minFractionDigits="2" :maxFractionDigits="2" class="pay-amount" />
            <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removePaymentLine(i)" :disabled="payments.length === 1" />
          </div>
          <div class="quick-cash">
            <Button label="Exact" size="small" outlined @click="exactCash" />
            <Button label="+100" size="small" outlined @click="addCash(100)" />
            <Button label="+500" size="small" outlined @click="addCash(500)" />
            <Button label="+2000" size="small" outlined @click="addCash(2000)" />
            <Button icon="pi pi-plus" label="Line" size="small" text @click="addPaymentLine" />
          </div>
        </template>
        <div v-else class="fld">
          <label>Refund Mode</label>
          <Select v-model="refundMode" :options="paymentModes" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <small v-if="errors.payments" class="err block">{{ errors.payments[0] }}</small>

        <div v-if="!(mode === 'exchange' && netRefund > 0)" class="balance" :class="{ due: balance > 0.005, over: change > 0.005 }">
          <template v-if="change > 0.005">Change: <strong>{{ money(change) }}</strong></template>
          <template v-else>Balance: <strong>{{ money(balance) }}</strong></template>
        </div>

        <Button v-if="mode === 'sell'" label="Complete Sale (F9)" icon="pi pi-check" class="complete-btn" :loading="checkingOut" @click="completeSale" />
        <Button v-else label="Complete Exchange (F9)" icon="pi pi-sync" class="complete-btn" :loading="checkingOut" :disabled="!loadedInvoice" @click="completeExchange" />
      </div>
    </div>

    <!-- RETURN: invoice-driven credit note -->
    <div v-else class="pos-return">
      <div class="return-panel">
        <h3 class="lines-title">Find Invoice</h3>
        <div class="inv-lookup">
          <InputText v-model="invoiceSearch" placeholder="Invoice # or customer name" class="w-full" @keyup.enter="findInvoices" autofocus />
          <Button icon="pi pi-search" label="Search" :loading="searchingInvoices" @click="findInvoices" />
        </div>
        <ul v-if="invoiceResults.length" class="inv-results">
          <li v-for="inv in invoiceResults" :key="inv.id" @click="loadInvoice(inv.id)">
            <strong>{{ inv.invoice_number }}</strong> · {{ inv.invoice_date }} · {{ inv.customer || 'Walk-in' }} · {{ money(inv.total) }}
            <Tag :value="inv.status" class="ml" />
            <Tag v-if="inv.channel === 'pos'" value="POS" severity="info" class="ml" />
          </li>
        </ul>

        <template v-if="loadedInvoice">
          <div class="inv-head">
            <div>
              <strong>{{ loadedInvoice.invoice_number }}</strong> · {{ loadedInvoice.customer || 'Walk-in' }}
              <span class="muted"> · {{ loadedInvoice.invoice_date }} · Total {{ money(loadedInvoice.total) }}</span>
            </div>
            <div>
              <Button label="Return All" text size="small" @click="returnAll" />
              <Button label="Change Invoice" text size="small" severity="secondary" @click="resetReturn" />
            </div>
          </div>
          <table class="lines return-lines">
            <thead><tr><th>Item</th><th>Sold</th><th>Returned</th><th>Return Qty</th><th>Unit</th><th>Reason</th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in returnLines" :key="i">
                <td>{{ l.name }}<br /><small class="muted">{{ l.sku }}</small></td>
                <td>{{ l.sold_quantity }}</td>
                <td>{{ l.returned_quantity }}</td>
                <td><InputNumber v-model="l.return_qty" :min="0" :max="l.returnable_quantity" :maxFractionDigits="3" class="num" /></td>
                <td>{{ money(l.unit_price) }}</td>
                <td><InputText v-model="l.reason" placeholder="—" class="reason-input" /></td>
              </tr>
              <tr v-if="!returnLines.length"><td colspan="6" class="muted" style="text-align: center">No returnable lines.</td></tr>
            </tbody>
          </table>
          <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

          <div class="return-foot">
            <div class="totals">
              <div class="grand"><span>Credit / Refund</span><span>{{ money(returnTotal) }}</span></div>
            </div>
            <div class="refund-controls">
              <label>Refund via</label>
              <Select v-model="refundMode" :options="paymentModes" optionLabel="label" optionValue="value" class="refund-select" />
              <small class="muted">Choose <strong>Credit</strong> to leave the amount on the customer's account instead of refunding cash.</small>
            </div>
            <Button label="Process Return (F9)" icon="pi pi-replay" class="complete-btn" :loading="checkingOut" @click="completeReturn" />
          </div>
        </template>
      </div>
    </div>

    <!-- Receipt dialog -->
    <Dialog v-model:visible="receiptVisible" modal header="Complete" :style="{ width: '440px' }">
      <template v-if="lastReceipt">
        <p class="rcpt-head"><strong>{{ lastReceipt.title }}</strong> — {{ lastReceipt.docNumber }}</p>
        <div class="inv-summary">
          <div class="inv-cell"><span class="inv-label">Total</span><span class="inv-value">{{ money(lastReceipt.total) }}</span></div>
          <div class="inv-cell" v-if="lastReceipt.change"><span class="inv-label">Change</span><span class="inv-value">{{ money(lastReceipt.change) }}</span></div>
          <div class="inv-cell" v-if="lastReceipt.balance"><span class="inv-label">Balance Due</span><span class="inv-value due">{{ money(lastReceipt.balance) }}</span></div>
          <div class="inv-cell" v-for="(x, i) in lastReceipt.extra" :key="i"><span class="inv-label">{{ x.label }}</span><span class="inv-value">{{ x.value }}</span></div>
        </div>
      </template>
      <template #footer>
        <Button label="Reprint" icon="pi pi-print" text @click="reprint" />
        <Button label="Done" icon="pi pi-check" @click="receiptVisible = false" />
      </template>
    </Dialog>

    <!-- Device settings dialog -->
    <Dialog v-model:visible="settingsVisible" modal header="POS Device Settings" :style="{ width: '480px' }">
      <div class="settings">
        <div class="fld">
          <label>Paper Width</label>
          <Select v-model="draftSettings.paperWidth" :options="[{ label: '58 mm', value: '58' }, { label: '80 mm', value: '80' }]" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>Print Method</label>
          <Select v-model="draftSettings.printMode" :options="[{ label: 'Browser print dialog', value: 'browser' }, { label: 'Local print agent (raw ESC/POS)', value: 'agent' }]" optionLabel="label" optionValue="value" class="w-full" />
          <small class="muted">The cash drawer can only be kicked via a local print agent.</small>
        </div>
        <div class="fld" v-if="draftSettings.printMode === 'agent'">
          <label>Print Agent URL</label>
          <InputText v-model="draftSettings.agentUrl" class="w-full" placeholder="http://localhost:8080/print" />
        </div>
        <label class="chk"><input type="checkbox" v-model="draftSettings.autoPrint" /> Auto-print receipt on completion</label>
        <label class="chk"><input type="checkbox" v-model="draftSettings.openDrawerOnCash" /> Open cash drawer on cash sale</label>
        <label class="chk"><input type="checkbox" v-model="draftSettings.beepOnScan" /> Beep on successful scan</label>
        <div class="fld">
          <label>Receipt Header Lines (address, GSTIN, phone)</label>
          <Textarea v-model="draftSettings.headerLines" rows="3" class="w-full" autoResize />
        </div>
        <div class="fld">
          <label>Footer Note</label>
          <InputText v-model="draftSettings.footerNote" class="w-full" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="settingsVisible = false" />
        <Button label="Save" icon="pi pi-check" @click="saveSettings" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.pos-wrap { display: flex; flex-direction: column; height: calc(100vh - 6rem); gap: 0.75rem; }
.pos-bar { display: flex; align-items: center; gap: 0.75rem; }
.mode-switch :deep(.p-togglebutton) { padding: 0.5rem 1rem; }
.wh-select { width: 220px; }
.bar-spacer { flex: 1; }

.pos { display: grid; grid-template-columns: 1fr 400px; gap: 1rem; flex: 1; min-height: 0; }
@media (max-width: 820px) {
  .pos-wrap { height: auto; }
  .pos { grid-template-columns: 1fr; }
  .pos-right { border-left: none; padding-left: 0; border-top: 1px solid #e5e7eb; padding-top: 1rem; }
}
.pos-left { display: flex; flex-direction: column; min-height: 0; }
.pos-toolbar { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
.scan-wrap { flex: 1; position: relative; display: flex; align-items: center; }
.scan-wrap i { position: absolute; left: 0.6rem; color: #94a3b8; z-index: 1; }
.search-input { flex: 1; width: 100%; padding-left: 2rem; }
.product-grid { flex: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.6rem; align-content: start; }
.product-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.7rem; cursor: pointer; transition: box-shadow 0.15s; user-select: none; }
.product-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-color: #94a3b8; }
.product-card:active { transform: scale(0.97); }
.p-name { font-weight: 600; font-size: 0.85rem; }
.p-sku { font-size: 0.7rem; color: #6b7280; }
.p-price { margin-top: 0.4rem; font-weight: 600; }
.p-stock { font-size: 0.7rem; color: #6b7280; }

.pos-right { border-left: 1px solid #e5e7eb; padding-left: 1rem; display: flex; flex-direction: column; overflow-y: auto; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.5rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.w-full { width: 100%; }
.w-full :deep(.p-select), .w-full :deep(.p-inputtext) { width: 100%; }
.lines-title { font-size: 0.95rem; margin: 0.75rem 0 0.4rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.75rem; color: #6b7280; padding: 0.3rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.3rem; vertical-align: middle; }
.num :deep(input) { width: 64px; }
.disc-cell { display: flex; gap: 0.25rem; align-items: center; }
.disc-type { width: 52px; }
.disc-type :deep(.p-select) { width: 52px; }
.disc-type :deep(.p-select-label) { padding: 0.35rem 0.2rem; }
.disc-val :deep(input) { width: 56px; }
.overall-disc { display: flex; align-items: center; gap: 0.4rem; margin-top: 0.75rem; }
.overall-disc label { font-size: 0.85rem; font-weight: 500; color: #374151; margin-right: auto; }
.totals { margin-top: 0.75rem; font-size: 0.9rem; }
.totals div { display: flex; justify-content: space-between; padding: 0.15rem 0; }
.totals .grand { font-size: 1.05rem; font-weight: 600; border-top: 1px solid #e5e7eb; margin-top: 0.25rem; padding-top: 0.4rem; }

.exch-return { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 0.5rem; background: #fafafa; }
.ret-total { display: flex; justify-content: space-between; font-weight: 600; padding-top: 0.4rem; }
.exch-net { margin-top: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 8px; background: #eff6ff; }
.exch-net.refund { background: #fef2f2; }
.exch-net div { display: flex; justify-content: space-between; padding: 0.15rem 0; font-size: 0.9rem; }
.net-grand { font-size: 1.15rem; font-weight: 700; border-top: 1px solid #cbd5e1; margin-top: 0.25rem; padding-top: 0.35rem; }

.pay-line { display: flex; gap: 0.4rem; margin-bottom: 0.4rem; align-items: center; }
.pay-mode { width: 120px; }
.pay-amount { flex: 1; }
.pay-amount :deep(input) { width: 100%; }
.quick-cash { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
.balance { margin-top: 0.75rem; font-size: 1rem; padding: 0.5rem; border-radius: 6px; background: #f8fafc; }
.balance.due { color: #b45309; background: #fffbeb; }
.balance.over { color: #047857; background: #ecfdf5; }
.complete-btn { margin-top: 1rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.3rem; }
.muted { color: #6b7280; }
.ml { margin-left: 0.4rem; }

.inv-lookup { display: flex; gap: 0.5rem; }
.inv-results { list-style: none; margin: 0.5rem 0; padding: 0; border: 1px solid #e5e7eb; border-radius: 8px; max-height: 240px; overflow-y: auto; }
.inv-results li { padding: 0.55rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.88rem; }
.inv-results li:hover { background: #f8fafc; }
.inv-head { display: flex; justify-content: space-between; align-items: center; margin: 0.75rem 0 0.5rem; padding-bottom: 0.4rem; border-bottom: 1px solid #e5e7eb; }

.pos-return { flex: 1; overflow-y: auto; }
.return-panel { max-width: 900px; margin: 0 auto; }
.return-lines .reason-input :deep(input) { width: 100%; }
.return-foot { margin-top: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 2rem; align-items: end; }
.return-foot .complete-btn { grid-column: 1 / -1; }
.refund-controls { display: flex; flex-direction: column; gap: 0.3rem; }
.refund-select { width: 160px; }

.settings .chk { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; margin: 0.35rem 0; }
.rcpt-head { margin: 0 0 0.5rem; }
.inv-summary { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.inv-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.inv-label { font-size: 0.75rem; color: #6b7280; }
.inv-value { font-weight: 600; font-size: 0.95rem; }
.inv-value.due { color: #dc2626; }
</style>
