<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const props = defineProps<{
  customer: any | null
  canEdit: boolean
}>()

const visible = defineModel<boolean>('visible', { required: true })
const toast = useToast()

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

type TabKey = 'ledger' | 'payments' | 'addresses' | 'pricing'
const tabs: { key: TabKey; label: string; icon: string }[] = [
  { key: 'ledger', label: 'Ledger', icon: 'pi pi-book' },
  { key: 'payments', label: 'Payments', icon: 'pi pi-wallet' },
  { key: 'addresses', label: 'Addresses', icon: 'pi pi-map-marker' },
  { key: 'pricing', label: 'Price List', icon: 'pi pi-tags' },
]
const activeTab = ref<TabKey>('ledger')
const custId = computed(() => props.customer?.id)

function toApiError(e: any, fallback: string) {
  toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || fallback, life: 4000 })
}

// ------------------------------------------------------------------ Ledger
const ledgerLoading = ref(false)
const ledgerRows = ref<any[]>([])
const ledgerSummary = ref<any>(null)
const ledgerFrom = ref<Date | null>(null)
const ledgerTo = ref<Date | null>(null)

function ymd(d: Date | null): string | undefined {
  if (!d) return undefined
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

async function loadLedger() {
  if (!custId.value) return
  ledgerLoading.value = true
  try {
    const params: Record<string, any> = {}
    if (ledgerFrom.value) params.from = ymd(ledgerFrom.value)
    if (ledgerTo.value) params.to = ymd(ledgerTo.value)
    const { data } = await http.get(`/customers/${custId.value}/ledger`, { params })
    ledgerRows.value = data.data ?? []
    ledgerSummary.value = data.summary ?? null
  } catch (e: any) {
    toApiError(e, 'Failed to load ledger')
  } finally {
    ledgerLoading.value = false
  }
}

const ledgerTypeColors: Record<string, string> = {
  opening: 'secondary',
  invoice: 'danger',
  payment: 'success',
  credit_note: 'info',
}

// ---------------------------------------------------------------- Payments
const payLoading = ref(false)
const payRows = ref<any[]>([])
const paySummary = ref<any>(null)
const payTotal = ref(0)
const payPage = ref(1)
const payPerPage = ref(15)

async function loadPayments() {
  if (!custId.value) return
  payLoading.value = true
  try {
    const { data } = await http.get(`/customers/${custId.value}/payments`, {
      params: { page: payPage.value, per_page: payPerPage.value },
    })
    payRows.value = data.data ?? []
    payTotal.value = data.meta?.total ?? payRows.value.length
    paySummary.value = data.summary ?? null
  } catch (e: any) {
    toApiError(e, 'Failed to load payments')
  } finally {
    payLoading.value = false
  }
}

function onPayPage(e: any) {
  payPage.value = e.page + 1
  payPerPage.value = e.rows
  loadPayments()
}

const modeColors: Record<string, string> = {
  cash: 'success', card: 'info', upi: 'warn', credit: 'secondary',
}

// --------------------------------------------------------------- Addresses
const addrLoading = ref(false)
const addresses = ref<any[]>([])
const billing = computed(() => addresses.value.filter((a) => a.type === 'billing'))
const shipping = computed(() => addresses.value.filter((a) => a.type === 'shipping'))

async function loadAddresses() {
  if (!custId.value) return
  addrLoading.value = true
  try {
    const { data } = await http.get(`/customers/${custId.value}/addresses`)
    addresses.value = data.data ?? []
  } catch (e: any) {
    toApiError(e, 'Failed to load addresses')
  } finally {
    addrLoading.value = false
  }
}

const addrDialog = ref(false)
const addrSaving = ref(false)
const addrEditing = ref<any>(null)
const addrForm = ref<any>({})
const addrErrors = ref<Record<string, string[]>>({})

function blankAddress(type: 'billing' | 'shipping' = 'shipping') {
  return {
    type, label: '', is_default: false, contact_name: '', phone: '',
    line1: '', line2: '', city: '', state: '', country: '', pincode: '', gstin: '',
  }
}

function openAddrCreate(type: 'billing' | 'shipping') {
  addrEditing.value = null
  addrForm.value = blankAddress(type)
  addrErrors.value = {}
  addrDialog.value = true
}

function openAddrEdit(row: any) {
  addrEditing.value = row
  addrForm.value = { ...blankAddress(row.type), ...row }
  addrErrors.value = {}
  addrDialog.value = true
}

async function saveAddress() {
  if (!custId.value) return
  addrSaving.value = true
  addrErrors.value = {}
  try {
    if (addrEditing.value) {
      await http.put(`/customers/${custId.value}/addresses/${addrEditing.value.id}`, addrForm.value)
      toast.add({ severity: 'success', summary: 'Address updated', life: 2000 })
    } else {
      await http.post(`/customers/${custId.value}/addresses`, addrForm.value)
      toast.add({ severity: 'success', summary: 'Address added', life: 2000 })
    }
    addrDialog.value = false
    loadAddresses()
  } catch (e: any) {
    if (e.response?.status === 422) addrErrors.value = e.response.data.errors || {}
    else toApiError(e, 'Failed to save address')
  } finally {
    addrSaving.value = false
  }
}

async function deleteAddress(row: any) {
  const res = await Swal.fire({
    title: 'Delete this address?', icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/customers/${custId.value}/addresses/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
    loadAddresses()
  } catch (e: any) {
    toApiError(e, 'Failed to delete address')
  }
}

// ------------------------------------------------------------- Price list
const priceLoading = ref(false)
const priceRows = ref<any[]>([])
const productOptions = ref<any[]>([])

async function loadProducts() {
  if (productOptions.value.length) return
  try {
    const { data } = await http.get('/products', { params: { per_page: 100 } })
    productOptions.value = (data.data ?? []).map((p: any) => ({
      label: p.sku ? `${p.name} (${p.sku})` : p.name,
      value: p.id,
      selling_price: Number(p.selling_price ?? 0),
    }))
  } catch {
    /* non-blocking */
  }
}

async function loadPriceList() {
  if (!custId.value) return
  priceLoading.value = true
  try {
    const { data } = await http.get(`/customers/${custId.value}/price-list`)
    priceRows.value = data.data ?? []
  } catch (e: any) {
    toApiError(e, 'Failed to load price list')
  } finally {
    priceLoading.value = false
  }
}

const priceDialog = ref(false)
const priceSaving = ref(false)
const priceEditing = ref<any>(null)
const priceForm = ref<any>({})
const priceErrors = ref<Record<string, string[]>>({})

function openPriceCreate() {
  priceEditing.value = null
  priceForm.value = { product_id: null, price: 0, min_quantity: 1 }
  priceErrors.value = {}
  loadProducts()
  priceDialog.value = true
}

function openPriceEdit(row: any) {
  priceEditing.value = row
  priceForm.value = {
    product_id: row.product_id,
    price: Number(row.price ?? 0),
    min_quantity: Number(row.min_quantity ?? 1),
  }
  priceErrors.value = {}
  loadProducts()
  priceDialog.value = true
}

async function savePrice() {
  if (!custId.value) return
  priceSaving.value = true
  priceErrors.value = {}
  try {
    if (priceEditing.value) {
      await http.put(`/customers/${custId.value}/price-list/${priceEditing.value.id}`, priceForm.value)
      toast.add({ severity: 'success', summary: 'Price updated', life: 2000 })
    } else {
      await http.post(`/customers/${custId.value}/price-list`, priceForm.value)
      toast.add({ severity: 'success', summary: 'Price added', life: 2000 })
    }
    priceDialog.value = false
    loadPriceList()
  } catch (e: any) {
    if (e.response?.status === 422) priceErrors.value = e.response.data.errors || {}
    else toApiError(e, 'Failed to save price')
  } finally {
    priceSaving.value = false
  }
}

async function deletePrice(row: any) {
  const res = await Swal.fire({
    title: 'Delete this price override?', icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/customers/${custId.value}/price-list/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
    loadPriceList()
  } catch (e: any) {
    toApiError(e, 'Failed to delete price')
  }
}

// ----------------------------------------------------------- tab loading
function loadActiveTab() {
  if (activeTab.value === 'ledger') loadLedger()
  else if (activeTab.value === 'payments') loadPayments()
  else if (activeTab.value === 'addresses') loadAddresses()
  else if (activeTab.value === 'pricing') loadPriceList()
}

watch(activeTab, loadActiveTab)
watch(visible, (v) => {
  if (v) {
    activeTab.value = 'ledger'
    payPage.value = 1
    loadLedger()
  }
})
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Account — ${customer?.name ?? ''}`"
    :style="{ width: '920px' }"
    :breakpoints="{ '960px': '95vw' }"
  >
    <div class="tabstrip">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ active: activeTab === t.key }"
        @click="activeTab = t.key"
      >
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- ============================================ LEDGER -->
    <section v-show="activeTab === 'ledger'">
      <div class="stat-grid">
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.opening_balance) }}</div>
          <div class="stat-label">Opening</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.total_debit) }}</div>
          <div class="stat-label">Total Debit</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ money(ledgerSummary?.total_credit) }}</div>
          <div class="stat-label">Total Credit</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value" :class="Number(ledgerSummary?.closing_balance) > 0 ? 'due' : 'clear'">
            {{ money(ledgerSummary?.closing_balance) }}
          </div>
          <div class="stat-label">Closing / Outstanding</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">
            {{ ledgerSummary?.available_credit == null ? 'Unlimited' : money(ledgerSummary?.available_credit) }}
          </div>
          <div class="stat-label">Available Credit</div>
        </div>
      </div>

      <div class="filter-row">
        <DatePicker v-model="ledgerFrom" dateFormat="yy-mm-dd" placeholder="From" showIcon />
        <DatePicker v-model="ledgerTo" dateFormat="yy-mm-dd" placeholder="To" showIcon />
        <Button label="Apply" outlined size="small" @click="loadLedger" />
        <Button
          v-if="ledgerFrom || ledgerTo"
          label="Clear" text size="small"
          @click="ledgerFrom = null; ledgerTo = null; loadLedger()"
        />
      </div>

      <DataTable :value="ledgerRows" :loading="ledgerLoading" class="p-datatable-sm" scrollable scrollHeight="360px">
        <Column field="date" header="Date" style="width: 8rem" />
        <Column header="Type" style="width: 8rem">
          <template #body="{ data }">
            <Tag :severity="ledgerTypeColors[data.type]" :value="data.type.replace('_', ' ')" />
          </template>
        </Column>
        <Column field="reference" header="Reference" />
        <Column field="description" header="Description" />
        <Column header="Debit" style="text-align: right">
          <template #body="{ data }">{{ data.debit ? money(data.debit) : '—' }}</template>
        </Column>
        <Column header="Credit" style="text-align: right">
          <template #body="{ data }">{{ data.credit ? money(data.credit) : '—' }}</template>
        </Column>
        <Column header="Balance" style="text-align: right">
          <template #body="{ data }"><strong>{{ money(data.balance) }}</strong></template>
        </Column>
        <template #empty>No ledger entries.</template>
      </DataTable>
    </section>

    <!-- ============================================ PAYMENTS -->
    <section v-show="activeTab === 'payments'">
      <div class="stat-grid three">
        <div class="stat-tile">
          <div class="stat-value">{{ money(paySummary?.total_paid) }}</div>
          <div class="stat-label">Total Received</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ paySummary?.payment_count ?? 0 }}</div>
          <div class="stat-label">Payments</div>
        </div>
        <div class="stat-tile">
          <div class="stat-value">{{ paySummary?.last_payment_date || '—' }}</div>
          <div class="stat-label">Last Payment</div>
        </div>
      </div>

      <DataTable
        :value="payRows" :loading="payLoading" lazy paginator
        :rows="payPerPage" :totalRecords="payTotal" :rowsPerPageOptions="[15, 30, 50]"
        dataKey="id" class="p-datatable-sm" @page="onPayPage"
      >
        <Column field="payment_date" header="Date" style="width: 9rem" />
        <Column field="invoice_number" header="Invoice" />
        <Column header="Mode" style="width: 7rem">
          <template #body="{ data }">
            <Tag :severity="modeColors[data.mode]" :value="data.mode" />
          </template>
        </Column>
        <Column header="Amount" style="text-align: right">
          <template #body="{ data }">{{ money(data.amount) }}</template>
        </Column>
        <Column field="notes" header="Notes" />
        <template #empty>No payments recorded.</template>
      </DataTable>
    </section>

    <!-- ============================================ ADDRESSES -->
    <section v-show="activeTab === 'addresses'">
      <div class="addr-cols">
        <div class="addr-col">
          <div class="addr-head">
            <h3>Billing</h3>
            <Button v-if="canEdit" icon="pi pi-plus" label="Add" size="small" outlined @click="openAddrCreate('billing')" />
          </div>
          <div v-if="addrLoading" class="muted">Loading…</div>
          <div v-else-if="!billing.length" class="muted">No billing addresses.</div>
          <div v-for="a in billing" :key="a.id" class="addr-card">
            <div class="addr-card-head">
              <span class="addr-label">{{ a.label || 'Address' }}</span>
              <Tag v-if="a.is_default" value="Default" severity="success" />
            </div>
            <div class="addr-body">{{ a.formatted }}</div>
            <div v-if="a.gstin" class="addr-gstin">GSTIN: {{ a.gstin }}</div>
            <div v-if="a.contact_name || a.phone" class="addr-contact">{{ [a.contact_name, a.phone].filter(Boolean).join(' · ') }}</div>
            <div v-if="canEdit" class="addr-actions">
              <Button icon="pi pi-pencil" text rounded size="small" @click="openAddrEdit(a)" />
              <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="deleteAddress(a)" />
            </div>
          </div>
        </div>

        <div class="addr-col">
          <div class="addr-head">
            <h3>Shipping</h3>
            <Button v-if="canEdit" icon="pi pi-plus" label="Add" size="small" outlined @click="openAddrCreate('shipping')" />
          </div>
          <div v-if="addrLoading" class="muted">Loading…</div>
          <div v-else-if="!shipping.length" class="muted">No shipping addresses.</div>
          <div v-for="a in shipping" :key="a.id" class="addr-card">
            <div class="addr-card-head">
              <span class="addr-label">{{ a.label || 'Address' }}</span>
              <Tag v-if="a.is_default" value="Default" severity="success" />
            </div>
            <div class="addr-body">{{ a.formatted }}</div>
            <div v-if="a.gstin" class="addr-gstin">GSTIN: {{ a.gstin }}</div>
            <div v-if="a.contact_name || a.phone" class="addr-contact">{{ [a.contact_name, a.phone].filter(Boolean).join(' · ') }}</div>
            <div v-if="canEdit" class="addr-actions">
              <Button icon="pi pi-pencil" text rounded size="small" @click="openAddrEdit(a)" />
              <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="deleteAddress(a)" />
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================ PRICE LIST -->
    <section v-show="activeTab === 'pricing'">
      <div class="filter-row between">
        <p class="muted small">
          Customer-specific unit prices. These override the tier price
          (<strong>{{ customer?.effective_price_level || customer?.default_price_level || 'retail' }}</strong>).
        </p>
        <Button v-if="canEdit" icon="pi pi-plus" label="Add Price" size="small" @click="openPriceCreate" />
      </div>
      <DataTable :value="priceRows" :loading="priceLoading" class="p-datatable-sm" scrollable scrollHeight="360px">
        <Column header="Product">
          <template #body="{ data }">{{ data.product?.name ?? ('#' + data.product_id) }}</template>
        </Column>
        <Column header="Base Price" style="text-align: right">
          <template #body="{ data }">{{ data.product ? money(data.product.selling_price) : '—' }}</template>
        </Column>
        <Column header="Min Qty" style="text-align: right">
          <template #body="{ data }">{{ Number(data.min_quantity) }}</template>
        </Column>
        <Column header="Your Price" style="text-align: right">
          <template #body="{ data }"><strong>{{ money(data.price) }}</strong></template>
        </Column>
        <Column v-if="canEdit" header="" style="width: 6rem">
          <template #body="{ data }">
            <Button icon="pi pi-pencil" text rounded size="small" @click="openPriceEdit(data)" />
            <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="deletePrice(data)" />
          </template>
        </Column>
        <template #empty>No customer-specific prices.</template>
      </DataTable>
    </section>

    <template #footer>
      <Button label="Close" text @click="visible = false" />
    </template>
  </Dialog>

  <!-- Address create/edit -->
  <Dialog v-model:visible="addrDialog" modal :header="addrEditing ? 'Edit Address' : 'New Address'" :style="{ width: '560px' }">
    <div class="form-grid">
      <div class="fld">
        <label>Type</label>
        <Select
          v-model="addrForm.type"
          :options="[{ label: 'Billing', value: 'billing' }, { label: 'Shipping', value: 'shipping' }]"
          optionLabel="label" optionValue="value"
        />
      </div>
      <div class="fld">
        <label>Label</label>
        <InputText v-model="addrForm.label" placeholder="e.g. Head Office" />
      </div>
      <div class="fld full">
        <label>Address Line 1 <span class="req">*</span></label>
        <InputText v-model="addrForm.line1" :class="{ invalid: addrErrors.line1 }" />
        <small v-if="addrErrors.line1" class="error-text">{{ addrErrors.line1[0] }}</small>
      </div>
      <div class="fld full">
        <label>Address Line 2</label>
        <InputText v-model="addrForm.line2" />
      </div>
      <div class="fld"><label>City</label><InputText v-model="addrForm.city" /></div>
      <div class="fld"><label>State</label><InputText v-model="addrForm.state" /></div>
      <div class="fld"><label>Pincode</label><InputText v-model="addrForm.pincode" /></div>
      <div class="fld"><label>Country</label><InputText v-model="addrForm.country" /></div>
      <div class="fld"><label>Contact Name</label><InputText v-model="addrForm.contact_name" /></div>
      <div class="fld"><label>Phone</label><InputText v-model="addrForm.phone" /></div>
      <div class="fld"><label>GSTIN</label><InputText v-model="addrForm.gstin" /></div>
      <div class="fld">
        <label>Default</label>
        <div class="checkbox-row">
          <Checkbox v-model="addrForm.is_default" binary inputId="addr_default" />
          <label for="addr_default">Default for this type</label>
        </div>
      </div>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="addrDialog = false" />
      <Button :label="addrEditing ? 'Update' : 'Add'" :loading="addrSaving" @click="saveAddress" />
    </template>
  </Dialog>

  <!-- Price create/edit -->
  <Dialog v-model:visible="priceDialog" modal :header="priceEditing ? 'Edit Price' : 'New Price'" :style="{ width: '460px' }">
    <div class="form-grid">
      <div class="fld full">
        <label>Product <span class="req">*</span></label>
        <Select
          v-model="priceForm.product_id"
          :options="productOptions"
          optionLabel="label" optionValue="value"
          filter :disabled="!!priceEditing"
          placeholder="Select product"
          :class="{ invalid: priceErrors.product_id }"
        />
        <small v-if="priceErrors.product_id" class="error-text">{{ priceErrors.product_id[0] }}</small>
      </div>
      <div class="fld">
        <label>Min Quantity</label>
        <InputNumber v-model="priceForm.min_quantity" :min="0" :minFractionDigits="0" :maxFractionDigits="3" />
      </div>
      <div class="fld">
        <label>Unit Price <span class="req">*</span></label>
        <InputNumber v-model="priceForm.price" :min="0" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: priceErrors.price }" />
        <small v-if="priceErrors.price" class="error-text">{{ priceErrors.price[0] }}</small>
      </div>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="priceDialog = false" />
      <Button :label="priceEditing ? 'Update' : 'Add'" :loading="priceSaving" @click="savePrice" />
    </template>
  </Dialog>
</template>

<style scoped>
.tabstrip {
  display: flex;
  gap: 0.25rem;
  border-bottom: 1px solid #e5e7eb;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.tab {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 0.6rem 0.9rem;
  font-size: 0.88rem;
  color: #6b7280;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.tab:hover { color: #111827; }
.tab.active {
  color: var(--ink-blue, #2c4a5e);
  border-bottom-color: var(--safety-amber, #e8a33d);
  font-weight: 600;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 0.6rem;
  margin-bottom: 0.9rem;
}
.stat-grid.three { grid-template-columns: repeat(3, 1fr); }
.stat-tile {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 0.7rem 0.85rem;
}
.stat-value { font-size: 1rem; font-weight: 600; color: #111827; }
.stat-value.due { color: #dc2626; }
.stat-value.clear { color: #16a34a; }
.stat-label { color: #6b7280; font-size: 0.75rem; margin-top: 0.15rem; }
.filter-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 0.75rem;
  flex-wrap: wrap;
}
.filter-row.between { justify-content: space-between; }
.muted { color: #6b7280; font-size: 0.85rem; }
.muted.small { font-size: 0.8rem; margin: 0; }
.addr-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.addr-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem; }
.addr-head h3 { font-size: 0.95rem; font-weight: 600; }
.addr-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 0.7rem 0.85rem;
  margin-bottom: 0.6rem;
  position: relative;
}
.addr-card-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem; }
.addr-label { font-weight: 600; font-size: 0.85rem; }
.addr-body { font-size: 0.83rem; color: #374151; }
.addr-gstin, .addr-contact { font-size: 0.78rem; color: #6b7280; margin-top: 0.2rem; }
.addr-actions { position: absolute; top: 0.4rem; right: 0.4rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber),
.fld :deep(.p-datepicker) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; padding-top: 0.3rem; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.76rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
