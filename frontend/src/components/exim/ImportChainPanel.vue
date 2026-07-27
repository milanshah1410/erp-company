<script setup lang="ts">
// The import workflow behind one shipment, as a checklist the desk works down:
//   Purchase Order → Supplier Invoice → Bill of Lading → Container → Vessel →
//   Freight → Customs → Warehouse Receipt
//
// Each step's state is read off `shipment.chain` (Shipment::importChain) rather
// than re-derived here, so what the panel calls "done" is exactly what the API
// says. The actions call ShipmentIntegrationController, which raises the ERP
// documents through their own controllers — the warehouse receipt is an ordinary
// GRN with batch / serial / put-away and landed cost, not a second stock path.
import { ref, computed } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../../api/http'
import { useAuthStore } from '../../stores/auth'
import { humanize, statusOptions } from '../../composables/useTradeCatalogue'

const props = defineProps<{ shipment: any }>()
const emit = defineEmits<{
  // The parent owns the shipment, so it reloads and re-renders after a write.
  (e: 'done', message: string): void
  // Steps whose editing already lives on another tab of the detail dialog.
  (e: 'tab', name: string): void
}>()

const toast = useToast()
const auth = useAuthStore()

const s = computed(() => props.shipment)
const isAir = computed(() => s.value.mode === 'air')
const blLabel = computed(() => (isAir.value ? 'Air waybill' : 'Bill of lading'))

const canEdit = computed(() => auth.can('shipments.edit'))
const canSeeOrders = computed(() => auth.can('purchases.view'))
const canSeeBills = computed(() => auth.can('purchase-invoices.view'))
const canReceive = computed(() => auth.can('purchases.create'))
const canBookCost = computed(() => auth.can('shipment-expenses.create'))
const canAddBoxes = computed(() => auth.can('shipment-containers.create'))

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function today(): string {
  return new Date().toISOString().slice(0, 10)
}

// A validation message from the API says far more than "Failed" — the chain
// actions reject for reasons the desk can act on (wrong supplier, nothing
// outstanding, cargo not arrived yet).
function fail(e: any, fallback = 'Failed') {
  const errors = e.response?.data?.errors
  const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null
  toast.add({ severity: 'error', summary: 'Error', detail: first || e.response?.data?.message || fallback, life: 5000 })
}

// `notes` is how the integration endpoints report what they could not carry
// over — a currency mismatch, a party that is not flagged overseas, a receipt
// posted into the order's warehouse rather than the shipment's.
function report(message: string, data: any) {
  const notes: string[] = data?.notes ?? []
  if (notes.length) {
    toast.add({ severity: 'warn', summary: 'Check this', detail: notes.join('\n'), life: 8000 })
  }
  emit('done', message)
}

/**
 * PUT /shipments/{id} validates the whole header and defaults the money columns
 * to 0 when they are absent (ShipmentRequest::payload), so a one-field change
 * has to send the header back as it stands. The panel has no header form of its
 * own — this is only used to link or unlink the order.
 */
async function patchHeader(changes: Record<string, any>) {
  const h = s.value
  await http.put(`/shipments/${h.id}`, {
    shipment_date: h.shipment_date,
    supplier_id: h.supplier_id,
    customer_id: h.customer_id,
    purchase_order_id: h.purchase_order_id,
    sales_order_id: h.sales_order_id,
    warehouse_id: h.warehouse_id,
    mode: h.mode,
    incoterm: h.incoterm,
    currency: h.currency,
    exchange_rate: Number(h.exchange_rate),
    packages: h.packages,
    invoice_value: Number(h.invoice_value),
    freight_amount: Number(h.freight_amount),
    insurance_amount: Number(h.insurance_amount),
    goods_cost: Number(h.goods_cost),
    ...changes,
  })
}

// ------------------------------------------------------------- 1. purchase order
const orderPicker = ref<any[] | null>(null)
const pickerLoading = ref(false)

async function openOrderPicker() {
  orderPicker.value = []
  pickerLoading.value = true
  try {
    const { data } = await http.get('/purchase-orders', {
      params: { per_page: 100, ...(s.value.supplier_id ? { supplier_id: s.value.supplier_id } : {}) },
    })
    // Only orders a shipment may be raised against — the same rule
    // ShipmentIntegrationController::fromPurchaseOrder enforces.
    orderPicker.value = (data.data ?? data).filter(
      (po: any) => !['draft', 'cancelled', 'rejected'].includes(po.status),
    )
  } catch (e) {
    fail(e, 'Could not load purchase orders')
    orderPicker.value = null
  } finally {
    pickerLoading.value = false
  }
}

async function linkOrder(po: any) {
  try {
    await patchHeader({ purchase_order_id: po.id })
    orderPicker.value = null
    emit('done', `Purchase order ${po.po_number} linked`)
  } catch (e) {
    fail(e)
  }
}

async function unlinkOrder() {
  const res = await Swal.fire({
    title: 'Unlink the purchase order?',
    text: 'The shipment stays as it is, but it can no longer be received into stock.',
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Unlink',
  })
  if (!res.isConfirmed) return
  try {
    await patchHeader({ purchase_order_id: null })
    emit('done', 'Purchase order unlinked')
  } catch (e) {
    fail(e)
  }
}

// ----------------------------------------------------------- 2. supplier invoice
const billPicker = ref<any[] | null>(null)

async function openBillPicker() {
  billPicker.value = []
  pickerLoading.value = true
  try {
    const { data } = await http.get('/purchase-invoices', {
      params: { per_page: 100, ...(s.value.supplier_id ? { supplier_id: s.value.supplier_id } : {}) },
    })
    billPicker.value = data.data ?? data
  } catch (e) {
    fail(e, 'Could not load supplier invoices')
    billPicker.value = null
  } finally {
    pickerLoading.value = false
  }
}

async function linkBill(bill: any) {
  try {
    const { data } = await http.post(`/shipments/${s.value.id}/supplier-invoice`, { purchase_invoice_id: bill.id })
    billPicker.value = null
    report(`Supplier invoice ${bill.invoice_number} linked`, data)
  } catch (e) {
    fail(e)
  }
}

async function unlinkBill() {
  try {
    const { data } = await http.post(`/shipments/${s.value.id}/supplier-invoice`, { purchase_invoice_id: null })
    report('Supplier invoice unlinked', data)
  } catch (e) {
    fail(e)
  }
}

// ------------------------------------------------------------- 3–5. B/L & vessel
const blForm = ref<any>(null)
const blSaving = ref(false)
const carrierOptions = ref<any[]>([])
const containerTypes = ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'].map(v => ({ value: v, label: v.toUpperCase() }))

async function openBl() {
  const h = s.value
  blForm.value = {
    bl_awb_number: h.bl_awb_number || '',
    bl_awb_date: h.bl_awb_date || '',
    vessel_name: h.vessel_name || '',
    voyage_number: h.voyage_number || '',
    carrier_id: (isAir.value ? h.airline_id : h.shipping_line_id) ?? null,
    atd: h.atd || '',
    eta: h.eta || '',
    freight_amount: Number(h.freight_amount ?? 0),
    insurance_amount: Number(h.insurance_amount ?? 0),
    containers: [] as any[],
  }
  try {
    const { data } = await http.get(isAir.value ? '/airlines' : '/shipping-lines', { params: { per_page: 100 } })
    carrierOptions.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {
    carrierOptions.value = []
  }
}

function addBlContainer() {
  blForm.value.containers.push({
    container_number: '', container_type: isAir.value ? 'uld' : '20ft',
    seal_number: '', packages: 0, gross_weight_kg: null, free_days: 0,
  })
}

async function saveBl() {
  blSaving.value = true
  try {
    const f = blForm.value
    const body: Record<string, any> = {
      bl_awb_number: f.bl_awb_number,
      bl_awb_date: f.bl_awb_date || null,
      vessel_name: f.vessel_name || null,
      voyage_number: f.voyage_number || null,
      atd: f.atd || null,
      eta: f.eta || null,
      freight_amount: f.freight_amount,
      insurance_amount: f.insurance_amount,
      [isAir.value ? 'airline_id' : 'shipping_line_id']: f.carrier_id,
    }
    // Boxes named on the B/L, if any — blank rows are dropped rather than
    // rejected, since the desk adds rows as it reads the paper.
    const boxes = f.containers.filter((c: any) => (c.container_number || '').trim() !== '')
    if (boxes.length) body.containers = boxes

    const { data } = await http.post(`/shipments/${s.value.id}/bill-of-lading`, body)
    blForm.value = null
    const created = data.containers_created ?? 0
    report(data.message + (created ? ` ${created} container(s) opened.` : ''), data)
  } catch (e) {
    fail(e)
  } finally {
    blSaving.value = false
  }
}

// -------------------------------------------------------------------- 6. freight
const freightForm = ref<any>(null)
const freightVendors = ref<any[]>([])
const freightHeads = computed(() => [
  { value: isAir.value ? 'air_freight' : 'ocean_freight', label: isAir.value ? 'Air Freight' : 'Ocean Freight' },
  { value: 'local_charges', label: 'Local Charges' },
  { value: 'thc', label: 'Terminal Handling (THC)' },
])
const vendorKinds = computed(() => [
  { value: isAir.value ? 'airline' : 'shipping_line', label: isAir.value ? 'Airline' : 'Shipping Line' },
  { value: 'freight_forwarder', label: 'Freight Forwarder' },
  { value: 'other', label: 'Other' },
])
const vendorEndpoints: Record<string, string> = {
  shipping_line: '/shipping-lines',
  airline: '/airlines',
  freight_forwarder: '/freight-forwarders',
}

function openFreight() {
  const h = s.value
  // The forwarder bills the freight when there is one, otherwise the carrier
  // does — and freight on the B/L is quoted in the shipment's currency.
  const kind = h.freight_forwarder_id ? 'freight_forwarder' : (isAir.value ? 'airline' : 'shipping_line')
  freightForm.value = {
    expense_type: freightHeads.value[0].value,
    expense_date: today(),
    vendor_type: kind,
    vendor_id: kind === 'freight_forwarder' ? h.freight_forwarder_id : (isAir.value ? h.airline_id : h.shipping_line_id),
    vendor_name: '',
    invoice_number: '',
    currency: h.currency,
    exchange_rate: Number(h.exchange_rate),
    amount: Number(h.freight_amount ?? 0) || 0,
    due_date: '',
    is_billable: false,
    remarks: '',
  }
  loadFreightVendors(kind)
}

async function loadFreightVendors(kind: string) {
  freightVendors.value = []
  const endpoint = vendorEndpoints[kind]
  if (!endpoint) return
  try {
    const { data } = await http.get(endpoint, { params: { per_page: 100 } })
    freightVendors.value = (data.data ?? data).map((v: any) => ({ label: v.name, value: v.id }))
  } catch {
    freightVendors.value = []
  }
}

function onFreightVendorKind(kind: string) {
  freightForm.value.vendor_id = null
  loadFreightVendors(kind)
}

async function saveFreight() {
  try {
    await http.post('/shipment-expenses', { ...freightForm.value, shipment_id: s.value.id })
    freightForm.value = null
    emit('done', 'Freight booked')
  } catch (e) {
    fail(e)
  }
}

// --------------------------------------------------------- 8. warehouse receipt
const receiptForm = ref<any>(null)
const receiptSaving = ref(false)

async function openReceipt() {
  const h = s.value
  receiptForm.value = null
  pickerLoading.value = true
  try {
    const { data } = await http.get(`/purchase-orders/${h.purchase_order_id}`)
    const po = data.data ?? data
    receiptForm.value = {
      po,
      received_date: h.ata || today(),
      notes: `Received against shipment ${h.shipment_number}`,
      // Everything still outstanding, which is what the endpoint would default
      // to on its own; the desk trims it when a consignment lands short.
      lines: (po.items ?? []).map((item: any) => ({
        purchase_item_id: item.id,
        label: item.product?.name ?? `Item #${item.id}`,
        sku: item.product?.sku,
        track_batch: !!item.product?.track_batch,
        track_expiry: !!item.product?.track_expiry,
        track_serial: !!item.product?.track_serial,
        ordered: Number(item.quantity),
        outstanding: Number(item.outstanding_quantity),
        include: Number(item.outstanding_quantity) > 0,
        quantity: Number(item.outstanding_quantity),
        batch_number: '',
        expiry_date: '',
        serials: '',
      })),
      // What the shipment actually cost to land, which is the endpoint's own
      // default — freight + insurance in base currency, plus the duty assessed.
      landed_cost: {
        freight_amount: Number(h.freight_insurance_base ?? 0),
        duty_amount: Number(h.duty_total ?? 0),
        other_amount: 0,
        allocation_method: 'value',
      },
    }
  } catch (e) {
    fail(e, 'Could not load the purchase order')
  } finally {
    pickerLoading.value = false
  }
}

const receiptLines = computed(() => receiptForm.value?.lines.filter((l: any) => l.include && l.quantity > 0) ?? [])

async function saveReceipt() {
  receiptSaving.value = true
  try {
    const f = receiptForm.value
    const { data } = await http.post(`/shipments/${s.value.id}/warehouse-receipt`, {
      received_date: f.received_date,
      notes: f.notes || null,
      items: receiptLines.value.map((l: any) => ({
        purchase_item_id: l.purchase_item_id,
        quantity: l.quantity,
        ...(l.batch_number ? { batch_number: l.batch_number } : {}),
        ...(l.expiry_date ? { expiry_date: l.expiry_date } : {}),
        // Serial-tracked lines need exactly one serial per unit; the desk pastes
        // them in and the separator can be a comma, a space or a new line.
        ...(l.serials.trim() ? { serials: l.serials.split(/[\s,;]+/).filter(Boolean) } : {}),
      })),
      landed_cost: f.landed_cost,
    })
    receiptForm.value = null
    report(`Goods receipt ${data.goods_receipt?.grn_number ?? ''} posted`.trim(), data)
  } catch (e) {
    fail(e)
  } finally {
    receiptSaving.value = false
  }
}

// ----------------------------------------------------------------- the checklist
type Action = { label: string; icon?: string; run: () => void; outlined?: boolean; severity?: string }

/** Why a step cannot be actioned yet — the same rules the endpoints enforce. */
function blockedReason(key: string): string | null {
  const h = s.value
  if (['closed', 'cancelled'].includes(h.status) && key !== 'customs') {
    return 'The shipment is closed.'
  }
  if (key === 'warehouse_receipt') {
    if (h.goods_receipt_id) return null
    if (!h.purchase_order_id) return 'Link a purchase order first.'
    if (!['arrived', 'customs', 'cleared', 'delivered'].includes(h.status)) {
      return `Cargo can only be received once the shipment has arrived (it is ${humanize(h.status).toLowerCase()}).`
    }
  }
  return null
}

function actionsFor(key: string, done: boolean): Action[] {
  const h = s.value
  const blocked = blockedReason(key)

  switch (key) {
    case 'purchase_order':
      if (!canEdit.value || !canSeeOrders.value || blocked) return []
      return done
        ? [
          { label: 'Change order', icon: 'pi pi-sync', outlined: true, run: openOrderPicker },
          { label: 'Unlink', icon: 'pi pi-times', outlined: true, severity: 'danger', run: unlinkOrder },
        ]
        : [{ label: 'Link purchase order', icon: 'pi pi-link', run: openOrderPicker }]

    case 'supplier_invoice':
      if (!canEdit.value || !canSeeBills.value || blocked) return []
      return done
        ? [
          { label: 'Change bill', icon: 'pi pi-sync', outlined: true, run: openBillPicker },
          { label: 'Unlink', icon: 'pi pi-times', outlined: true, severity: 'danger', run: unlinkBill },
        ]
        : [{ label: 'Link supplier invoice', icon: 'pi pi-link', run: openBillPicker }]

    case 'bill_of_lading':
    case 'vessel':
      if (!canEdit.value || blocked) return []
      return [{
        label: done ? `Update ${blLabel.value.toLowerCase()}` : `Capture ${blLabel.value.toLowerCase()}`,
        icon: done ? 'pi pi-pencil' : 'pi pi-file-import',
        outlined: key === 'vessel',
        run: openBl,
      }]

    case 'containers':
      return [{
        label: h.containers?.length ? 'Work the containers' : 'Add containers',
        icon: 'pi pi-box', outlined: true,
        run: () => emit('tab', 'containers'),
      }]

    case 'freight': {
      const out: Action[] = []
      if (canBookCost.value && !blocked) {
        out.push({ label: done ? 'Book another cost' : 'Book freight', icon: 'pi pi-plus', run: openFreight })
      }
      out.push({ label: 'All costs', icon: 'pi pi-wallet', outlined: true, run: () => emit('tab', 'expenses') })
      return out
    }

    case 'customs':
      return [{ label: 'Bill of entry & duty', icon: 'pi pi-shield', outlined: !!done, run: () => emit('tab', 'customs') }]

    case 'warehouse_receipt':
      if (done || !canEdit.value || !canReceive.value || blocked) return []
      return [{ label: 'Receive into stock', icon: 'pi pi-download', run: openReceipt }]

    default:
      return []
  }
}

const steps = computed(() =>
  ((s.value.chain ?? []) as any[]).map((step, index) => ({
    ...step,
    index: index + 1,
    blocked: blockedReason(step.key),
    actions: actionsFor(step.key, step.done),
  })),
)

const doneCount = computed(() => steps.value.filter(step => step.done).length)
</script>

<template>
  <div>
    <div class="chain-head">
      <span class="muted">
        Purchase order to stock — <strong>{{ doneCount }} of {{ steps.length }}</strong> steps done
      </span>
      <div class="bar"><span :style="{ width: `${steps.length ? (doneCount / steps.length) * 100 : 0}%` }" /></div>
    </div>

    <ol v-if="steps.length" class="chain">
      <li v-for="step in steps" :key="step.key" :class="{ done: step.done }">
        <span class="marker"><i :class="step.done ? 'pi pi-check' : 'pi pi-circle'" /></span>
        <div class="body">
          <div class="row">
            <strong>{{ step.index }}. {{ step.label }}</strong>
            <Tag :severity="step.done ? 'success' : 'secondary'" :value="step.done ? 'Done' : 'Pending'" class="mini" />
            <span v-if="step.detail" class="detail">{{ step.detail }}</span>
          </div>
          <div v-if="step.blocked" class="blocked"><i class="pi pi-info-circle" /> {{ step.blocked }}</div>
          <div v-if="step.actions.length" class="acts">
            <Button v-for="a in step.actions" :key="a.label" :label="a.label" :icon="a.icon" size="small"
              :outlined="a.outlined" :severity="a.severity" @click="a.run()" />
          </div>
        </div>
      </li>
    </ol>
    <p v-else class="muted">The chain is only available on a shipment loaded in full — reopen this shipment.</p>

    <!-- ------------------------------------------------------ purchase order -->
    <Dialog :visible="orderPicker !== null" modal header="Link a purchase order" :style="{ width: '760px' }"
      :breakpoints="{ '820px': '95vw' }" @update:visible="v => { if (!v) orderPicker = null }">
      <p class="muted hint">
        Live orders{{ shipment.supplier_id ? ` for ${shipment.party}` : '' }}. Draft, cancelled and rejected
        orders cannot carry a shipment.
      </p>
      <DataTable :value="orderPicker ?? []" :loading="pickerLoading" class="p-datatable-sm" dataKey="id">
        <Column field="po_number" header="PO #" />
        <Column header="Supplier"><template #body="{ data }">{{ data.supplier?.name || '—' }}</template></Column>
        <Column field="order_date" header="Date" />
        <Column field="expected_date" header="Expected" />
        <Column header="Status"><template #body="{ data }"><Tag :value="humanize(data.status)" severity="info" /></template></Column>
        <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
        <Column header="GRNs"><template #body="{ data }">{{ data.goods_receipts_count ?? 0 }}</template></Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button label="Link" size="small" :disabled="data.id === shipment.purchase_order_id" @click="linkOrder(data)" />
          </template>
        </Column>
        <template #empty>No live purchase orders to link.</template>
      </DataTable>
      <template #footer><Button label="Close" text @click="orderPicker = null" /></template>
    </Dialog>

    <!-- ----------------------------------------------------- supplier invoice -->
    <Dialog :visible="billPicker !== null" modal header="Link the supplier invoice" :style="{ width: '760px' }"
      :breakpoints="{ '820px': '95vw' }" @update:visible="v => { if (!v) billPicker = null }">
      <p class="muted hint">
        The bill's number, date and total carry over to the shipment; the total becomes the goods cost the
        landed-cost figure is built on.
      </p>
      <DataTable :value="billPicker ?? []" :loading="pickerLoading" class="p-datatable-sm" dataKey="id">
        <Column field="invoice_number" header="Invoice #" />
        <Column header="Against PO"><template #body="{ data }">{{ data.purchase_order?.po_number || '—' }}</template></Column>
        <Column field="invoice_date" header="Date" />
        <Column field="due_date" header="Due" />
        <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
        <Column header="Payment"><template #body="{ data }"><Tag :value="humanize(data.payment_status)" severity="secondary" class="mini" /></template></Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button label="Link" size="small" :disabled="data.id === shipment.purchase_invoice_id" @click="linkBill(data)" />
          </template>
        </Column>
        <template #empty>No supplier invoices booked for this party yet.</template>
      </DataTable>
      <template #footer><Button label="Close" text @click="billPicker = null" /></template>
    </Dialog>

    <!-- ------------------------------------------------------ bill of lading -->
    <Dialog :visible="!!blForm" modal :header="`${blLabel} — carrier, vessel & boxes`" :style="{ width: '860px' }"
      :breakpoints="{ '900px': '95vw' }" @update:visible="v => { if (!v) blForm = null }">
      <div v-if="blForm">
        <p class="muted hint">
          What the carrier releases once the cargo is on board. Blank fields leave the shipment as it is, and a
          container already on the shipment is left as the desk has it — so an amended {{ blLabel.toLowerCase() }}
          can be re-keyed safely.
        </p>
        <div class="form-grid">
          <div class="fld">
            <label>{{ blLabel }} no. *</label>
            <InputText v-model="blForm.bl_awb_number" />
          </div>
          <div class="fld"><label>{{ blLabel }} date</label><input type="date" v-model="blForm.bl_awb_date" class="date-input" /></div>
          <div class="fld">
            <label>{{ isAir ? 'Airline' : 'Shipping line' }}</label>
            <Select v-model="blForm.carrier_id" :options="carrierOptions" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld" v-if="!isAir"><label>Vessel</label><InputText v-model="blForm.vessel_name" /></div>
          <div class="fld"><label>{{ isAir ? 'Flight no.' : 'Voyage no.' }}</label><InputText v-model="blForm.voyage_number" /></div>
          <div class="fld">
            <label>Sailed on (ATD)</label>
            <input type="date" v-model="blForm.atd" class="date-input" />
            <small class="muted">Stamps the departure on the timeline.</small>
          </div>
          <div class="fld">
            <label>Revised ETA</label>
            <input type="date" v-model="blForm.eta" class="date-input" />
          </div>
          <div class="fld">
            <label>Freight ({{ shipment.currency }})</label>
            <InputNumber v-model="blForm.freight_amount" :minFractionDigits="2" />
          </div>
          <div class="fld">
            <label>Insurance ({{ shipment.currency }})</label>
            <InputNumber v-model="blForm.insurance_amount" :minFractionDigits="2" />
          </div>
        </div>

        <div class="sub-head">
          <h4 class="sec">Containers named on the {{ blLabel.toLowerCase() }}</h4>
          <Button v-if="canAddBoxes" label="Add row" icon="pi pi-plus" size="small" text @click="addBlContainer" />
        </div>
        <p v-if="!canAddBoxes" class="muted">
          You can file the {{ blLabel.toLowerCase() }}, but not open containers — the container desk adds the boxes.
        </p>
        <table v-else-if="blForm.containers.length" class="box-table">
          <thead>
            <tr>
              <th>Container no.</th><th>Type</th><th>Seal</th><th>Pkgs</th><th>Gross kg</th><th>Free days</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(box, i) in blForm.containers" :key="i">
              <td><InputText v-model="box.container_number" /></td>
              <td><Select v-model="box.container_type" :options="containerTypes" optionLabel="label" optionValue="value" /></td>
              <td><InputText v-model="box.seal_number" /></td>
              <td><InputNumber v-model="box.packages" :min="0" /></td>
              <td><InputNumber v-model="box.gross_weight_kg" :minFractionDigits="0" :maxFractionDigits="3" /></td>
              <td><InputNumber v-model="box.free_days" :min="0" /></td>
              <td><Button icon="pi pi-trash" text rounded size="small" severity="danger" @click="blForm.containers.splice(i, 1)" /></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">
          No rows — add one per box on the paper. They are opened as loaded, with the free days the carrier allows,
          so the detention clock starts on gate-in.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="blForm = null" />
        <Button label="Save" :loading="blSaving" :disabled="!blForm?.bl_awb_number" @click="saveBl" />
      </template>
    </Dialog>

    <!-- -------------------------------------------------------------- freight -->
    <Dialog :visible="!!freightForm" modal header="Book freight" :style="{ width: '680px' }"
      @update:visible="v => { if (!v) freightForm = null }">
      <div v-if="freightForm" class="form-grid">
        <div class="fld">
          <label>Head *</label>
          <Select v-model="freightForm.expense_type" :options="freightHeads" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Date *</label><input type="date" v-model="freightForm.expense_date" class="date-input" /></div>
        <div class="fld">
          <label>Billed by</label>
          <Select v-model="freightForm.vendor_type" :options="vendorKinds" optionLabel="label" optionValue="value"
            @change="onFreightVendorKind(freightForm.vendor_type)" />
        </div>
        <div class="fld" v-if="freightForm.vendor_type !== 'other'">
          <label>Vendor *</label>
          <Select v-model="freightForm.vendor_id" :options="freightVendors" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld" v-else><label>Vendor name</label><InputText v-model="freightForm.vendor_name" /></div>
        <div class="fld"><label>Invoice no.</label><InputText v-model="freightForm.invoice_number" /></div>
        <div class="fld"><label>Currency</label><InputText v-model="freightForm.currency" maxlength="3" /></div>
        <div class="fld"><label>Exchange rate</label><InputNumber v-model="freightForm.exchange_rate" :minFractionDigits="2" :maxFractionDigits="6" /></div>
        <div class="fld"><label>Amount *</label><InputNumber v-model="freightForm.amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>Due date</label><input type="date" v-model="freightForm.due_date" class="date-input" /></div>
        <div class="fld chk">
          <Checkbox v-model="freightForm.is_billable" :binary="true" inputId="fr-billable" />
          <label for="fr-billable">Recoverable from the supplier</label>
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="freightForm.remarks" rows="2" /></div>
        <p class="total-hint">
          Books <strong>{{ money(Number(freightForm.amount || 0) * Number(freightForm.exchange_rate || 1)) }}</strong>
          of landed cost — unless it is marked recoverable, which keeps it out of the cost side.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="freightForm = null" />
        <Button label="Book" :disabled="!freightForm?.amount" @click="saveFreight" />
      </template>
    </Dialog>

    <!-- ---------------------------------------------------- warehouse receipt -->
    <Dialog :visible="!!receiptForm" modal header="Receive the cargo into stock" :style="{ width: '1000px' }"
      :breakpoints="{ '1050px': '95vw' }" @update:visible="v => { if (!v) receiptForm = null }">
      <div v-if="receiptForm">
        <p class="muted hint">
          Posts an ordinary goods receipt against {{ receiptForm.po.po_number }}, so batch, expiry, serial and
          put-away rules apply exactly as on the GRN screen. Put-away to a sub-location is left to that screen.
        </p>
        <div class="form-grid two">
          <div class="fld"><label>Received on *</label><input type="date" v-model="receiptForm.received_date" class="date-input" /></div>
          <div class="fld"><label>Note</label><InputText v-model="receiptForm.notes" /></div>
        </div>

        <h4 class="sec">Lines</h4>
        <table class="box-table">
          <thead>
            <tr>
              <th style="width: 2.5rem"></th><th>Item</th><th>Ordered</th><th>Outstanding</th><th>Receiving</th>
              <th>Batch</th><th>Expiry</th><th>Serials</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in receiptForm.lines" :key="line.purchase_item_id" :class="{ dim: !line.include }">
              <td><Checkbox v-model="line.include" :binary="true" :disabled="line.outstanding <= 0" /></td>
              <td>
                {{ line.label }}
                <small v-if="line.sku" class="muted">{{ line.sku }}</small>
                <Tag v-if="line.track_batch" value="batch" severity="secondary" class="mini" />
                <Tag v-if="line.track_serial" value="serial" severity="secondary" class="mini" />
              </td>
              <td>{{ line.ordered }}</td>
              <td :class="{ good: line.outstanding > 0 }">{{ line.outstanding }}</td>
              <td><InputNumber v-model="line.quantity" :min="0" :maxFractionDigits="3" :disabled="!line.include" class="qty" /></td>
              <td><InputText v-model="line.batch_number" :disabled="!line.include || !line.track_batch" class="narrow" /></td>
              <td><input type="date" v-model="line.expiry_date" class="date-input narrow" :disabled="!line.include || !line.track_expiry" /></td>
              <td>
                <InputText v-model="line.serials" :disabled="!line.include || !line.track_serial"
                  :placeholder="line.track_serial ? `${line.quantity} serial(s)` : ''" class="narrow" />
              </td>
            </tr>
          </tbody>
        </table>

        <h4 class="sec">Landed cost</h4>
        <div class="form-grid">
          <div class="fld">
            <label>Freight &amp; insurance (base)</label>
            <InputNumber v-model="receiptForm.landed_cost.freight_amount" :minFractionDigits="2" />
            <small class="muted">From the shipment, converted at {{ shipment.exchange_rate }}.</small>
          </div>
          <div class="fld"><label>Duty</label><InputNumber v-model="receiptForm.landed_cost.duty_amount" :minFractionDigits="2" /></div>
          <div class="fld"><label>Other</label><InputNumber v-model="receiptForm.landed_cost.other_amount" :minFractionDigits="2" /></div>
          <div class="fld">
            <label>Apportion by</label>
            <Select v-model="receiptForm.landed_cost.allocation_method"
              :options="statusOptions(['value', 'quantity'])" optionLabel="label" optionValue="value" />
          </div>
          <p class="total-hint">
            {{ receiptLines.length }} line(s) selected.
          </p>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="receiptForm = null" />
        <Button label="Post receipt" :loading="receiptSaving" :disabled="!receiptLines.length" @click="saveReceipt" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.chain-head { display: flex; align-items: center; gap: 1rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
.bar { flex: 1; min-width: 140px; height: 6px; border-radius: 3px; background: var(--border, #e8e4da); overflow: hidden; }
.bar span { display: block; height: 100%; background: #16a34a; transition: width 0.25s ease; }

.chain { list-style: none; margin: 0; padding: 0; }
.chain li { display: grid; grid-template-columns: 2rem 1fr; gap: 0.6rem; padding: 0.65rem 0; border-top: 1px solid var(--border, #e8e4da); }
.chain li:first-child { border-top: 0; }
.marker { display: flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 50%;
  background: var(--border, #e8e4da); color: #6b7280; font-size: 0.7rem; }
.chain li.done .marker { background: #16a34a; color: #fff; }
.body { display: flex; flex-direction: column; gap: 0.35rem; }
.row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.detail { font-size: 0.84rem; color: #4b5563; }
.blocked { font-size: 0.8rem; color: #b45309; }
.acts { display: flex; gap: 0.4rem; flex-wrap: wrap; }

.hint { margin: 0 0 0.8rem; }
.sub-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.sec { margin: 1.1rem 0 0.5rem; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.85rem; }
.form-grid.two { grid-template-columns: repeat(2, 1fr); }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld.chk { flex-direction: row; align-items: center; gap: 0.5rem; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; color: #374151; }

.box-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.box-table th { text-align: left; font-weight: 500; color: #6b7280; font-size: 0.78rem; padding: 0.3rem 0.35rem; }
.box-table td { padding: 0.25rem 0.35rem; vertical-align: middle; }
.box-table tr.dim { opacity: 0.55; }
.box-table :deep(.p-inputtext), .box-table :deep(.p-inputnumber), .box-table :deep(.p-select) { width: 100%; }
.box-table :deep(.qty input) { width: 5.5rem; }
.narrow { max-width: 9rem; }
.date-input.narrow { padding: 0.4rem 0.5rem; max-width: 9rem; }

.muted { color: #9ca3af; font-size: 0.82rem; }
.good { color: #16a34a; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

@media (max-width: 900px) {
  .form-grid, .form-grid.two { grid-template-columns: 1fr; }
}
</style>
