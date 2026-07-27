<script setup lang="ts">
// The export workflow behind one shipment, as a checklist the desk works down:
//   Sales Order → Packing List → Container Booking → Shipping Bill → ETD →
//   Customer Invoice → Dispatch
//
// The counterpart of ImportChainPanel. Each step's state is read off
// `shipment.chain` (Shipment::exportChain) rather than re-derived here, so what
// the panel calls "done" is exactly what the API says, and every action goes
// through ShipmentIntegrationController — the dispatch it raises is an ordinary
// draft dispatch plan the transport desk then works, not a second delivery path.
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
import TableExportButtons from '../TableExportButtons.vue'
import { useTradeCatalogue, humanize } from '../../composables/useTradeCatalogue'

const props = defineProps<{ shipment: any }>()
const emit = defineEmits<{
  // The parent owns the shipment, so it reloads and re-renders after a write.
  (e: 'done', message: string): void
  // Steps whose editing already lives on another tab of the detail dialog.
  (e: 'tab', name: string): void
}>()

const toast = useToast()
const auth = useAuthStore()
const { catalogue } = useTradeCatalogue()

const s = computed(() => props.shipment)
const isAir = computed(() => s.value.mode === 'air')

const canEdit = computed(() => auth.can('shipments.edit'))
const canSeeSales = computed(() => auth.can('sales.view'))
const canCustoms = computed(() => auth.can('shipment-customs.edit'))
const canDispatch = computed(() => auth.can('dispatch-plans.create'))
const canAddBoxes = computed(() => auth.can('shipment-containers.create'))
const canStuff = computed(() => auth.can('shipment-containers.edit'))

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function today(): string {
  return new Date().toISOString().slice(0, 10)
}

// A validation message from the API says far more than "Failed" — the chain
// actions reject for reasons the desk can act on (no order linked, a closed
// shipment, an invoice raised for another customer).
function fail(e: any, fallback = 'Failed') {
  const errors = e.response?.data?.errors
  const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null
  toast.add({ severity: 'error', summary: 'Error', detail: first || e.response?.data?.message || fallback, life: 5000 })
}

// `notes` is how the integration endpoints report what they could not carry
// over — a currency mismatch, a FOB derived from CIF terms, placeholder boxes
// that still have to be renumbered.
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
 * has to send the header back as it stands. Used for linking the order and for
 * revising the schedule, neither of which has an action of its own.
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
    etd: h.etd,
    eta: h.eta,
    invoice_value: Number(h.invoice_value),
    freight_amount: Number(h.freight_amount),
    insurance_amount: Number(h.insurance_amount),
    goods_cost: Number(h.goods_cost),
    ...changes,
  })
}

const pickerLoading = ref(false)

// ---------------------------------------------------------------- 1. sales order
const orderPicker = ref<any[] | null>(null)

async function openOrderPicker() {
  orderPicker.value = []
  pickerLoading.value = true
  try {
    const { data } = await http.get('/sales-orders', {
      params: { per_page: 100, ...(s.value.customer_id ? { customer_id: s.value.customer_id } : {}) },
    })
    // The same rule ShipmentIntegrationController::fromSalesOrder enforces.
    orderPicker.value = (data.data ?? data).filter(
      (so: any) => !['cancelled', 'rejected'].includes(so.status),
    )
  } catch (e) {
    fail(e, 'Could not load sales orders')
    orderPicker.value = null
  } finally {
    pickerLoading.value = false
  }
}

async function linkOrder(so: any) {
  try {
    await patchHeader({ sales_order_id: so.id })
    orderPicker.value = null
    emit('done', `Sales order ${so.so_number} linked`)
  } catch (e) {
    fail(e)
  }
}

async function unlinkOrder() {
  const res = await Swal.fire({
    title: 'Unlink the sales order?',
    text: 'The shipment stays as it is, but the packing list can no longer be built from the order.',
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Unlink',
  })
  if (!res.isConfirmed) return
  try {
    await patchHeader({ sales_order_id: null })
    emit('done', 'Sales order unlinked')
  } catch (e) {
    fail(e)
  }
}

// --------------------------------------------------------------- 2. packing list
// The list is edited in its own dialog: the cargo is declared line by line
// (marks, package count, weights, dimensions, HS code), and the header's
// packages / weight / CBM are the roll-up the API keeps in step.
const packingOpen = ref(false)
const packing = ref<any[]>([])
const packingTotals = ref<any>(null)
const packingLoading = ref(false)
const generating = ref(false)

const packageTypes = computed(() => catalogue.value.package_types ?? [])
const boxOptions = computed(() =>
  (s.value.containers ?? []).map((c: any) => ({ label: c.container_number, value: c.id })))

async function loadPacking() {
  packingLoading.value = true
  try {
    const { data } = await http.get(`/shipments/${s.value.id}/packing-list`)
    packing.value = data.data ?? []
    packingTotals.value = data.meta?.totals ?? null
  } catch (e) {
    fail(e, 'Could not load the packing list')
  } finally {
    packingLoading.value = false
  }
}

async function openPacking() {
  packingOpen.value = true
  await loadPacking()
}

/** Rebuild from the order — adds only what is missing unless `replace`. */
async function generatePacking(replace = false) {
  if (replace) {
    const res = await Swal.fire({
      title: 'Rebuild the packing list?',
      text: 'Every line, including the package counts and weights already keyed, is replaced by the order lines.',
      icon: 'warning', showCancelButton: true, confirmButtonText: 'Rebuild',
    })
    if (!res.isConfirmed) return
  }
  generating.value = true
  try {
    const { data } = await http.post(`/shipments/${s.value.id}/packing-list/generate`, { replace })
    packing.value = data.data ?? []
    packingTotals.value = null
    if (data.notes?.length) {
      toast.add({ severity: 'warn', summary: 'Check this', detail: data.notes.join('\n'), life: 7000 })
    }
    toast.add({ severity: 'success', summary: data.message, life: 2500 })
    await loadPacking()
    emit('done', data.message)
  } catch (e) {
    fail(e)
  } finally {
    generating.value = false
  }
}

const lineForm = ref<any>(null)
const lineSaving = ref(false)

function openLine(row: any = null) {
  lineForm.value = row
    ? {
      ...row,
      quantity: Number(row.quantity ?? 0),
      net_weight_kg: Number(row.net_weight_kg ?? 0),
      gross_weight_kg: Number(row.gross_weight_kg ?? 0),
      length_cm: row.length_cm != null ? Number(row.length_cm) : null,
      width_cm: row.width_cm != null ? Number(row.width_cm) : null,
      height_cm: row.height_cm != null ? Number(row.height_cm) : null,
      cbm: Number(row.cbm ?? 0),
    }
    : {
      description: '', marks_numbers: '', package_type: 'carton', package_count: 1,
      quantity: 0, uom: '', net_weight_kg: 0, gross_weight_kg: 0,
      length_cm: null, width_cm: null, height_cm: null, cbm: 0,
      hs_code: '', shipment_container_id: null, remarks: '',
    }
}

// The volume the API will store: measured from the box when all three
// dimensions are known, otherwise whatever the desk typed.
const linePreviewCbm = computed(() => {
  const f = lineForm.value
  if (!f) return 0
  const [l, w, h] = [Number(f.length_cm), Number(f.width_cm), Number(f.height_cm)]
  if (l > 0 && w > 0 && h > 0) {
    return Math.round((l * w * h) / 1_000_000 * Math.max(1, Number(f.package_count || 1)) * 1000) / 1000
  }
  return Number(f.cbm || 0)
})

async function saveLine() {
  lineSaving.value = true
  try {
    const f = lineForm.value
    const body = {
      shipment_container_id: f.shipment_container_id || null,
      sales_item_id: f.sales_item_id || null,
      product_id: f.product_id || null,
      description: f.description,
      marks_numbers: f.marks_numbers || null,
      package_type: f.package_type,
      package_count: f.package_count,
      quantity: f.quantity,
      uom: f.uom || null,
      net_weight_kg: f.net_weight_kg,
      gross_weight_kg: f.gross_weight_kg,
      length_cm: f.length_cm,
      width_cm: f.width_cm,
      height_cm: f.height_cm,
      cbm: f.cbm,
      hs_code: f.hs_code || null,
      remarks: f.remarks || null,
    }
    if (f.id) await http.put(`/shipment-packing-items/${f.id}`, body)
    else await http.post(`/shipments/${s.value.id}/packing-items`, body)
    lineForm.value = null
    await loadPacking()
    emit('done', 'Packing line saved')
  } catch (e) {
    fail(e)
  } finally {
    lineSaving.value = false
  }
}

async function removeLine(row: any) {
  const res = await Swal.fire({
    title: `Remove "${row.description}"?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipment-packing-items/${row.id}`)
    await loadPacking()
    emit('done', 'Packing line removed')
  } catch (e) {
    fail(e)
  }
}

/** Stuff a line into a box (or take it out again) without opening the form. */
async function stuffLine(row: any, containerId: number | null) {
  try {
    await http.post(`/shipments/${s.value.id}/packing-items/assign`, {
      item_ids: [row.id],
      shipment_container_id: containerId,
    })
    await loadPacking()
    emit('done', containerId ? 'Line stuffed' : 'Line unstuffed')
  } catch (e) {
    fail(e)
  }
}

// ----------------------------------------------------------- 3. container booking
const bookingForm = ref<any>(null)
const bookingSaving = ref(false)
const carrierOptions = ref<any[]>([])
const portOptions = ref<any[]>([])
const containerTypes = ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'].map(v => ({ value: v, label: v.toUpperCase() }))

async function openBooking() {
  const h = s.value
  bookingForm.value = {
    booking_number: h.booking_number || '',
    booking_date: h.booking_date || today(),
    carrier_id: (isAir.value ? h.airline_id : h.shipping_line_id) ?? null,
    vessel_name: h.vessel_name || '',
    voyage_number: h.voyage_number || '',
    port_of_loading_id: h.port_of_loading_id ?? null,
    port_of_discharge_id: h.port_of_discharge_id ?? null,
    si_cutoff: h.si_cutoff || '',
    cargo_cutoff: h.cargo_cutoff || '',
    etd: h.etd || '',
    eta: h.eta || '',
    confirm: h.status === 'draft',
    containers: [] as any[],
    remarks: '',
  }
  try {
    const [carriers, ports] = await Promise.all([
      http.get(isAir.value ? '/airlines' : '/shipping-lines', { params: { per_page: 100 } }),
      http.get('/ports', { params: { per_page: 200 } }),
    ])
    carrierOptions.value = (carriers.data.data ?? carriers.data).map((c: any) => ({ label: c.name, value: c.id }))
    portOptions.value = (ports.data.data ?? ports.data).map((p: any) => ({ label: `${p.name} (${p.code})`, value: p.id }))
  } catch {
    carrierOptions.value = []
    portOptions.value = []
  }
}

function addEquipment() {
  bookingForm.value.containers.push({
    container_type: isAir.value ? 'uld' : '40hc', quantity: 1, free_days: 14,
  })
}

async function saveBooking() {
  bookingSaving.value = true
  try {
    const f = bookingForm.value
    const body: Record<string, any> = {
      booking_number: f.booking_number,
      booking_date: f.booking_date || null,
      vessel_name: f.vessel_name || null,
      voyage_number: f.voyage_number || null,
      port_of_loading_id: f.port_of_loading_id,
      port_of_discharge_id: f.port_of_discharge_id,
      si_cutoff: f.si_cutoff || null,
      cargo_cutoff: f.cargo_cutoff || null,
      etd: f.etd || null,
      eta: f.eta || null,
      confirm: f.confirm,
      remarks: f.remarks || null,
      [isAir.value ? 'airline_id' : 'shipping_line_id']: f.carrier_id,
    }
    if (f.containers.length) body.containers = f.containers
    const { data } = await http.post(`/shipments/${s.value.id}/booking`, body)
    bookingForm.value = null
    report(`Booking ${f.booking_number} recorded`, data)
  } catch (e) {
    fail(e)
  } finally {
    bookingSaving.value = false
  }
}

// -------------------------------------------------------------- 4. shipping bill
const sbForm = ref<any>(null)
const sbSaving = ref(false)
const chaOptions = ref<any[]>([])

async function openShippingBill() {
  const h = s.value
  // FOB is the value at the port of loading, so freight and insurance come out
  // of a CIF-type invoice — the same default the endpoint applies.
  const cifTerms = ['CFR', 'CIF', 'CIP', 'DAP', 'DDP'].includes(h.incoterm)
  const suggested = Number(h.fob_value) > 0
    ? Number(h.fob_value)
    : Math.max(0, Number(h.invoice_value ?? 0) - (cifTerms ? Number(h.freight_amount ?? 0) + Number(h.insurance_amount ?? 0) : 0))

  sbForm.value = {
    shipping_bill_number: h.customs_entry_type === 'shipping_bill' ? (h.customs_entry_number || '') : '',
    shipping_bill_date: h.customs_entry_date || today(),
    icegate_reference: h.icegate_reference || '',
    cha_agent_id: h.cha_agent_id ?? null,
    fob_value: suggested,
    drawback_amount: Number(h.drawback_amount ?? 0),
    rodtep_amount: Number(h.rodtep_amount ?? 0),
    leo_date: h.leo_date || '',
    remarks: '',
  }
  try {
    const { data } = await http.get('/cha-agents', { params: { per_page: 100 } })
    chaOptions.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {
    chaOptions.value = []
  }
}

async function saveShippingBill() {
  sbSaving.value = true
  try {
    const f = sbForm.value
    const { data } = await http.post(`/shipments/${s.value.id}/shipping-bill`, {
      ...f,
      leo_date: f.leo_date || null,
      icegate_reference: f.icegate_reference || null,
      remarks: f.remarks || null,
    })
    sbForm.value = null
    report(f.leo_date ? 'Shipping bill cleared — LEO recorded' : 'Shipping bill filed', data)
  } catch (e) {
    fail(e)
  } finally {
    sbSaving.value = false
  }
}

// -------------------------------------------------------------------- 5. schedule
const scheduleForm = ref<any>(null)

function openSchedule() {
  scheduleForm.value = { etd: s.value.etd || '', eta: s.value.eta || '' }
}

async function saveSchedule() {
  try {
    await patchHeader({ etd: scheduleForm.value.etd || null, eta: scheduleForm.value.eta || null })
    scheduleForm.value = null
    emit('done', 'Schedule revised')
  } catch (e) {
    fail(e)
  }
}

// ------------------------------------------------------------- 6. customer invoice
const invoicePicker = ref<any[] | null>(null)

async function openInvoicePicker() {
  invoicePicker.value = []
  pickerLoading.value = true
  try {
    const { data } = await http.get('/invoices', {
      params: { per_page: 100, ...(s.value.customer_id ? { customer_id: s.value.customer_id } : {}) },
    })
    invoicePicker.value = data.data ?? data
  } catch (e) {
    fail(e, 'Could not load invoices')
    invoicePicker.value = null
  } finally {
    pickerLoading.value = false
  }
}

async function linkInvoice(invoice: any) {
  try {
    const { data } = await http.post(`/shipments/${s.value.id}/customer-invoice`, { invoice_id: invoice.id })
    invoicePicker.value = null
    report(`Customer invoice ${invoice.invoice_number} linked`, data)
  } catch (e) {
    fail(e)
  }
}

async function unlinkInvoice() {
  try {
    const { data } = await http.post(`/shipments/${s.value.id}/customer-invoice`, { invoice_id: null })
    report('Customer invoice unlinked', data)
  } catch (e) {
    fail(e)
  }
}

// -------------------------------------------------------------------- 7. dispatch
const dispatchForm = ref<any>(null)
const dispatchSaving = ref(false)
const transportOptions = ref<Record<string, any[]>>({ vehicles: [], drivers: [], routes: [] })

async function openDispatch() {
  const h = s.value
  dispatchForm.value = {
    // Export cargo leaves for the port of loading on the sailing date.
    dispatch_date: h.etd || today(),
    vehicle_id: null, driver_id: null, route_id: null,
    delivery_address: h.final_place || h.port_of_loading || '',
    remarks: `Export shipment ${h.shipment_number}`,
  }
  try {
    const [vehicles, drivers, routes] = await Promise.all([
      http.get('/vehicles', { params: { per_page: 100 } }),
      http.get('/drivers', { params: { per_page: 100 } }),
      http.get('/delivery-routes', { params: { per_page: 100 } }),
    ])
    const rows = (r: any) => r.data.data ?? r.data
    transportOptions.value = {
      vehicles: rows(vehicles).map((v: any) => ({ label: v.registration_number ?? v.name, value: v.id })),
      drivers: rows(drivers).map((d: any) => ({ label: d.name, value: d.id })),
      routes: rows(routes).map((r: any) => ({ label: r.name, value: r.id })),
    }
  } catch {
    // The plan can be raised without them; the transport desk assigns later.
    transportOptions.value = { vehicles: [], drivers: [], routes: [] }
  }
}

async function saveDispatch() {
  dispatchSaving.value = true
  try {
    const f = dispatchForm.value
    const { data } = await http.post(`/shipments/${s.value.id}/dispatch`, {
      dispatch_date: f.dispatch_date || null,
      vehicle_id: f.vehicle_id,
      driver_id: f.driver_id,
      route_id: f.route_id,
      delivery_address: f.delivery_address || null,
      remarks: f.remarks || null,
    })
    dispatchForm.value = null
    report(`Dispatch ${data.dispatch_plan?.dispatch_number ?? ''} raised`.trim(), data)
  } catch (e) {
    fail(e)
  } finally {
    dispatchSaving.value = false
  }
}

// ----------------------------------------------------------------- the checklist
type Action = { label: string; icon?: string; run: () => void; outlined?: boolean; severity?: string }

/** Why a step cannot be actioned yet — the same rules the endpoints enforce. */
function blockedReason(key: string): string | null {
  const h = s.value
  const closed = ['closed', 'cancelled'].includes(h.status)
  if (closed && key !== 'shipping_bill') {
    return 'The shipment is closed.'
  }
  if (key === 'packing_list' && !h.sales_order_id && !h.packing_items?.length) {
    return 'Link a sales order to build the list from, or add the lines by hand.'
  }
  if (key === 'dispatch') {
    if (h.dispatch_plan_id) return null
    if (!h.customer_id) return 'Set the overseas customer on the shipment before dispatching it.'
  }
  return null
}

function actionsFor(key: string, done: boolean): Action[] {
  const h = s.value
  const blocked = blockedReason(key)

  switch (key) {
    case 'sales_order':
      if (!canEdit.value || !canSeeSales.value || blocked) return []
      return done
        ? [
          { label: 'Change order', icon: 'pi pi-sync', outlined: true, run: openOrderPicker },
          { label: 'Unlink', icon: 'pi pi-times', outlined: true, severity: 'danger', run: unlinkOrder },
        ]
        : [{ label: 'Link sales order', icon: 'pi pi-link', run: openOrderPicker }]

    case 'packing_list': {
      if (!canEdit.value) return [{ label: 'View packing list', icon: 'pi pi-list', outlined: true, run: openPacking }]
      const out: Action[] = []
      if (!done && h.sales_order_id && !blocked) {
        out.push({ label: 'Build from sales order', icon: 'pi pi-bolt', run: () => generatePacking(false) })
      }
      out.push({ label: done ? 'Work the packing list' : 'Add lines by hand', icon: 'pi pi-list', outlined: done, run: openPacking })
      return out
    }

    case 'booking':
      if (!canEdit.value || blocked) return []
      return [{
        label: done ? 'Update booking' : 'Record booking',
        icon: done ? 'pi pi-pencil' : 'pi pi-calendar-plus',
        run: openBooking,
      }]

    case 'shipping_bill': {
      const out: Action[] = []
      if (canCustoms.value && !['closed', 'cancelled'].includes(h.status)) {
        out.push({
          label: done ? (h.leo_date ? 'Amend shipping bill' : 'Record LEO') : 'File shipping bill',
          icon: done ? 'pi pi-pencil' : 'pi pi-shield',
          run: openShippingBill,
        })
      }
      out.push({ label: 'Customs track', icon: 'pi pi-flag', outlined: true, run: () => emit('tab', 'customs') })
      return out
    }

    case 'etd': {
      if (!canEdit.value || blocked) return []
      const out: Action[] = [{ label: 'Revise ETD / ETA', icon: 'pi pi-calendar', outlined: done, run: openSchedule }]
      if (!done) {
        out.push({
          label: 'Containers', icon: 'pi pi-box', outlined: true, run: () => emit('tab', 'containers'),
        })
      }
      return out
    }

    case 'customer_invoice':
      if (!canEdit.value || !canSeeSales.value || blocked) return []
      return done
        ? [
          { label: 'Change invoice', icon: 'pi pi-sync', outlined: true, run: openInvoicePicker },
          { label: 'Unlink', icon: 'pi pi-times', outlined: true, severity: 'danger', run: unlinkInvoice },
        ]
        : [{ label: 'Link customer invoice', icon: 'pi pi-link', run: openInvoicePicker }]

    case 'dispatch':
      if (done || !canEdit.value || !canDispatch.value || blocked) return []
      return [{ label: 'Raise dispatch', icon: 'pi pi-truck', run: openDispatch }]

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

// The packing list is a document in its own right — the buyer and the CHA both
// want it on paper, so it exports and prints from the rows already loaded.
const packingColumns = [
  { key: 'description', label: 'Description' },
  { key: 'marks_numbers', label: 'Marks & numbers' },
  { key: 'package_count', label: 'Packages' },
  { key: 'package_label', label: 'Package type' },
  { key: 'quantity', label: 'Quantity' },
  { key: 'uom', label: 'Unit' },
  { key: 'net_weight_kg', label: 'Net kg' },
  { key: 'gross_weight_kg', label: 'Gross kg' },
  { key: 'cbm', label: 'CBM' },
  { key: 'hs_code', label: 'HS code' },
  { key: 'container_number', label: 'Container' },
]

// The ETD step reads as pending until the shipment actually sails, and that is
// stamped by the status track rather than here — say so instead of leaving the
// desk hunting for a button.
const etdHint = computed(() => {
  const h = s.value
  if (h.atd) return null
  return h.etd
    ? 'Departure is stamped when the shipment moves to In transit (the buttons above the tabs).'
    : 'Set the ETD from the booking, or revise the schedule here.'
})
</script>

<template>
  <div>
    <div class="chain-head">
      <span class="muted">
        Sales order to dispatch — <strong>{{ doneCount }} of {{ steps.length }}</strong> steps done
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
          <div v-else-if="step.key === 'etd' && etdHint" class="muted">{{ etdHint }}</div>
          <div v-if="step.actions.length" class="acts">
            <Button v-for="a in step.actions" :key="a.label" :label="a.label" :icon="a.icon" size="small"
              :outlined="a.outlined" :severity="a.severity" @click="a.run()" />
          </div>
        </div>
      </li>
    </ol>
    <p v-else class="muted">The chain is only available on a shipment loaded in full — reopen this shipment.</p>

    <!-- --------------------------------------------------------- sales order -->
    <Dialog :visible="orderPicker !== null" modal header="Link a sales order" :style="{ width: '760px' }"
      :breakpoints="{ '820px': '95vw' }" @update:visible="v => { if (!v) orderPicker = null }">
      <p class="muted hint">
        Live orders{{ shipment.customer_id ? ` for ${shipment.party}` : '' }}. Cancelled and rejected orders
        cannot carry a shipment.
      </p>
      <DataTable :value="orderPicker ?? []" :loading="pickerLoading" class="p-datatable-sm" dataKey="id">
        <Column field="so_number" header="SO #" />
        <Column header="Customer"><template #body="{ data }">{{ data.customer?.name || '—' }}</template></Column>
        <Column field="order_date" header="Date" />
        <Column header="Status"><template #body="{ data }"><Tag :value="humanize(data.status)" severity="info" /></template></Column>
        <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button label="Link" size="small" :disabled="data.id === shipment.sales_order_id" @click="linkOrder(data)" />
          </template>
        </Column>
        <template #empty>No live sales orders to link.</template>
      </DataTable>
      <template #footer><Button label="Close" text @click="orderPicker = null" /></template>
    </Dialog>

    <!-- -------------------------------------------------------- packing list -->
    <Dialog v-model:visible="packingOpen" modal header="Packing list" :style="{ width: '1080px' }"
      :breakpoints="{ '1120px': '95vw' }">
      <div class="sub-head">
        <p class="muted hint">
          What customs, the carrier and the buyer read the cargo from. The shipment's packages, weight and CBM
          are the roll-up of these lines, so the header follows whatever is keyed here.
        </p>
        <div class="acts">
          <TableExportButtons v-if="packing.length" :title="`Packing List — ${shipment.shipment_number}`"
            :rows="packing" :columns="packingColumns" size="small"
            :filenameBase="`packing-list-${shipment.shipment_number}`" />
          <Button v-if="canEdit && shipment.sales_order_id" label="Build from order" icon="pi pi-bolt" size="small"
            outlined :loading="generating" @click="generatePacking(false)" />
          <Button v-if="canEdit && shipment.sales_order_id && packing.length" label="Rebuild" icon="pi pi-refresh"
            size="small" outlined severity="danger" :loading="generating" @click="generatePacking(true)" />
          <Button v-if="canEdit" label="Add line" icon="pi pi-plus" size="small" @click="openLine()" />
        </div>
      </div>

      <DataTable :value="packing" :loading="packingLoading" class="p-datatable-sm" dataKey="id">
        <Column header="Description">
          <template #body="{ data }">
            {{ data.description }}
            <small v-if="data.sku" class="muted">{{ data.sku }}</small>
          </template>
        </Column>
        <Column field="marks_numbers" header="Marks" />
        <Column header="Packages">
          <template #body="{ data }">{{ data.package_count }} × {{ data.package_label }}</template>
        </Column>
        <Column header="Quantity">
          <template #body="{ data }">{{ Number(data.quantity) }} {{ data.uom || '' }}</template>
        </Column>
        <Column header="Net / Gross kg">
          <template #body="{ data }">{{ Number(data.net_weight_kg) }} / {{ Number(data.gross_weight_kg) }}</template>
        </Column>
        <Column header="CBM">
          <template #body="{ data }">
            {{ Number(data.cbm) }}
            <i v-if="data.cbm_is_measured" class="pi pi-calculator muted" title="Measured from the dimensions" />
          </template>
        </Column>
        <Column field="hs_code" header="HS code" />
        <Column header="Stuffed into">
          <template #body="{ data }">
            <Select v-if="canStuff && boxOptions.length" :modelValue="data.shipment_container_id"
              :options="boxOptions" optionLabel="label" optionValue="value" showClear placeholder="—"
              class="box-select" @update:modelValue="v => stuffLine(data, v ?? null)" />
            <span v-else>{{ data.container_number || '—' }}</span>
          </template>
        </Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openLine(data)" />
            <Button v-if="canEdit" icon="pi pi-trash" text rounded size="small" severity="danger" @click="removeLine(data)" />
          </template>
        </Column>
        <template #empty>
          Nothing packed yet.
          {{ shipment.sales_order_id ? 'Build the list from the sales order, or add the lines by hand.' : 'Add the lines by hand.' }}
        </template>
      </DataTable>

      <p v-if="packingTotals" class="totals">
        <strong>{{ packingTotals.lines }}</strong> line(s) ·
        <strong>{{ packingTotals.packages }}</strong> package(s) ·
        net <strong>{{ packingTotals.net_weight_kg }}</strong> kg ·
        gross <strong>{{ packingTotals.gross_weight_kg }}</strong> kg ·
        <strong>{{ packingTotals.cbm }}</strong> CBM
      </p>

      <template #footer><Button label="Close" text @click="packingOpen = false" /></template>
    </Dialog>

    <Dialog :visible="!!lineForm" modal :header="lineForm?.id ? 'Edit packing line' : 'Add packing line'"
      :style="{ width: '760px' }" :breakpoints="{ '800px': '95vw' }"
      @update:visible="v => { if (!v) lineForm = null }">
      <div v-if="lineForm" class="form-grid">
        <div class="fld full"><label>Description *</label><InputText v-model="lineForm.description" /></div>
        <div class="fld">
          <label>Shipping marks</label>
          <InputText v-model="lineForm.marks_numbers" placeholder="ACME / 1-10" />
        </div>
        <div class="fld">
          <label>Package type *</label>
          <Select v-model="lineForm.package_type" :options="packageTypes" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Packages *</label><InputNumber v-model="lineForm.package_count" :min="1" /></div>
        <div class="fld"><label>Quantity</label><InputNumber v-model="lineForm.quantity" :maxFractionDigits="3" /></div>
        <div class="fld"><label>Unit</label><InputText v-model="lineForm.uom" placeholder="PCS" /></div>
        <div class="fld"><label>HS code</label><InputText v-model="lineForm.hs_code" /></div>
        <div class="fld"><label>Net weight (kg)</label><InputNumber v-model="lineForm.net_weight_kg" :maxFractionDigits="3" /></div>
        <div class="fld"><label>Gross weight (kg)</label><InputNumber v-model="lineForm.gross_weight_kg" :maxFractionDigits="3" /></div>
        <div class="fld">
          <label>Stuffed into</label>
          <Select v-model="lineForm.shipment_container_id" :options="boxOptions" optionLabel="label"
            optionValue="value" showClear placeholder="Not stuffed yet" />
        </div>
        <div class="fld"><label>Length (cm)</label><InputNumber v-model="lineForm.length_cm" :maxFractionDigits="2" /></div>
        <div class="fld"><label>Width (cm)</label><InputNumber v-model="lineForm.width_cm" :maxFractionDigits="2" /></div>
        <div class="fld"><label>Height (cm)</label><InputNumber v-model="lineForm.height_cm" :maxFractionDigits="2" /></div>
        <div class="fld">
          <label>CBM</label>
          <InputNumber v-model="lineForm.cbm" :maxFractionDigits="3"
            :disabled="!!(lineForm.length_cm && lineForm.width_cm && lineForm.height_cm)" />
          <small class="muted">Measured from the box when all three dimensions are given.</small>
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="lineForm.remarks" rows="2" /></div>
        <p class="total-hint">
          This line will carry <strong>{{ lineForm.package_count }}</strong> package(s)
          at <strong>{{ linePreviewCbm }}</strong> CBM.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="lineForm = null" />
        <Button label="Save" :loading="lineSaving" :disabled="!lineForm?.description" @click="saveLine" />
      </template>
    </Dialog>

    <!-- ----------------------------------------------------- container booking -->
    <Dialog :visible="!!bookingForm" modal header="Container booking" :style="{ width: '860px' }"
      :breakpoints="{ '900px': '95vw' }" @update:visible="v => { if (!v) bookingForm = null }">
      <div v-if="bookingForm">
        <p class="muted hint">
          What the carrier confirms against the booking: the sailing, the cut-offs the desk works backwards from,
          and the equipment allotted. Confirming a draft shipment moves it to Booked.
        </p>
        <div class="form-grid">
          <div class="fld"><label>Booking no. *</label><InputText v-model="bookingForm.booking_number" /></div>
          <div class="fld"><label>Booking date</label><input type="date" v-model="bookingForm.booking_date" class="date-input" /></div>
          <div class="fld">
            <label>{{ isAir ? 'Airline' : 'Shipping line' }}</label>
            <Select v-model="bookingForm.carrier_id" :options="carrierOptions" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld" v-if="!isAir"><label>Vessel</label><InputText v-model="bookingForm.vessel_name" /></div>
          <div class="fld"><label>{{ isAir ? 'Flight no.' : 'Voyage no.' }}</label><InputText v-model="bookingForm.voyage_number" /></div>
          <div class="fld">
            <label>Port of loading</label>
            <Select v-model="bookingForm.port_of_loading_id" :options="portOptions" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld">
            <label>Port of discharge</label>
            <Select v-model="bookingForm.port_of_discharge_id" :options="portOptions" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld">
            <label>SI cut-off</label>
            <input type="date" v-model="bookingForm.si_cutoff" class="date-input" />
            <small class="muted">Shipping instructions to the carrier.</small>
          </div>
          <div class="fld">
            <label>Cargo cut-off</label>
            <input type="date" v-model="bookingForm.cargo_cutoff" class="date-input" />
            <small class="muted">Gate-in deadline at the terminal.</small>
          </div>
          <div class="fld"><label>ETD</label><input type="date" v-model="bookingForm.etd" class="date-input" /></div>
          <div class="fld"><label>ETA</label><input type="date" v-model="bookingForm.eta" class="date-input" /></div>
          <div class="fld chk" v-if="shipment.status === 'draft'">
            <Checkbox v-model="bookingForm.confirm" :binary="true" inputId="bk-confirm" />
            <label for="bk-confirm">Move the shipment to Booked</label>
          </div>
          <div class="fld full"><label>Remarks</label><Textarea v-model="bookingForm.remarks" rows="2" /></div>
        </div>

        <div class="sub-head">
          <h4 class="sec">Equipment allotted</h4>
          <Button v-if="canAddBoxes" label="Add row" icon="pi pi-plus" size="small" text @click="addEquipment" />
        </div>
        <p v-if="!canAddBoxes" class="muted">
          You can record the booking, but not open containers — the container desk plans the boxes.
        </p>
        <table v-else-if="bookingForm.containers.length" class="box-table">
          <thead><tr><th>Type</th><th>Boxes</th><th>Free days</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in bookingForm.containers" :key="i">
              <td><Select v-model="row.container_type" :options="containerTypes" optionLabel="label" optionValue="value" /></td>
              <td><InputNumber v-model="row.quantity" :min="1" :max="50" /></td>
              <td><InputNumber v-model="row.free_days" :min="0" /></td>
              <td><Button icon="pi pi-trash" text rounded size="small" severity="danger" @click="bookingForm.containers.splice(i, 1)" /></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">
          No rows. Add one per equipment type and the boxes are opened as planned, numbered under the booking
          reference until the carrier releases the real container numbers.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="bookingForm = null" />
        <Button label="Save booking" :loading="bookingSaving" :disabled="!bookingForm?.booking_number" @click="saveBooking" />
      </template>
    </Dialog>

    <!-- -------------------------------------------------------- shipping bill -->
    <Dialog :visible="!!sbForm" modal header="Shipping bill" :style="{ width: '760px' }"
      :breakpoints="{ '800px': '95vw' }" @update:visible="v => { if (!v) sbForm = null }">
      <div v-if="sbForm" class="form-grid">
        <div class="fld"><label>Shipping bill no. *</label><InputText v-model="sbForm.shipping_bill_number" /></div>
        <div class="fld"><label>Filed on</label><input type="date" v-model="sbForm.shipping_bill_date" class="date-input" /></div>
        <div class="fld"><label>ICEGATE reference</label><InputText v-model="sbForm.icegate_reference" /></div>
        <div class="fld">
          <label>CHA</label>
          <Select v-model="sbForm.cha_agent_id" :options="chaOptions" optionLabel="label" optionValue="value" filter showClear />
        </div>
        <div class="fld">
          <label>FOB value ({{ shipment.currency }})</label>
          <InputNumber v-model="sbForm.fob_value" :minFractionDigits="2" />
          <small class="muted">Value at the port of loading — freight and insurance come out of a CIF invoice.</small>
        </div>
        <div class="fld"><label>Drawback (base)</label><InputNumber v-model="sbForm.drawback_amount" :minFractionDigits="2" /></div>
        <div class="fld"><label>RoDTEP (base)</label><InputNumber v-model="sbForm.rodtep_amount" :minFractionDigits="2" /></div>
        <div class="fld">
          <label>Let Export Order</label>
          <input type="date" v-model="sbForm.leo_date" class="date-input" />
          <small class="muted">Clears customs outright — LEO is the out-of-charge for an export.</small>
        </div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="sbForm.remarks" rows="2" /></div>
        <p class="total-hint">
          FOB in base currency <strong>{{ money(Number(sbForm.fob_value || 0) * Number(shipment.exchange_rate || 1)) }}</strong>,
          incentives <strong>{{ money(Number(sbForm.drawback_amount || 0) + Number(sbForm.rodtep_amount || 0)) }}</strong>.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="sbForm = null" />
        <Button :label="sbForm?.leo_date ? 'File & clear' : 'File'" :loading="sbSaving"
          :disabled="!sbForm?.shipping_bill_number" @click="saveShippingBill" />
      </template>
    </Dialog>

    <!-- ------------------------------------------------------------- schedule -->
    <Dialog :visible="!!scheduleForm" modal header="Revise the schedule" :style="{ width: '460px' }"
      @update:visible="v => { if (!v) scheduleForm = null }">
      <div v-if="scheduleForm" class="form-grid two">
        <div class="fld"><label>ETD</label><input type="date" v-model="scheduleForm.etd" class="date-input" /></div>
        <div class="fld"><label>ETA</label><input type="date" v-model="scheduleForm.eta" class="date-input" /></div>
        <p class="total-hint muted">A moved ETD or ETA is recorded on the timeline as a schedule revision.</p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="scheduleForm = null" />
        <Button label="Save" @click="saveSchedule" />
      </template>
    </Dialog>

    <!-- ----------------------------------------------------- customer invoice -->
    <Dialog :visible="invoicePicker !== null" modal header="Link the customer invoice" :style="{ width: '760px' }"
      :breakpoints="{ '820px': '95vw' }" @update:visible="v => { if (!v) invoicePicker = null }">
      <p class="muted hint">
        The invoice's number and date carry over to the shipment; its total becomes the foreign invoice value only
        when the invoice and the shipment are in the same currency.
      </p>
      <DataTable :value="invoicePicker ?? []" :loading="pickerLoading" class="p-datatable-sm" dataKey="id">
        <Column field="invoice_number" header="Invoice #" />
        <Column header="Against SO"><template #body="{ data }">{{ data.so_number || '—' }}</template></Column>
        <Column field="invoice_date" header="Date" />
        <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
        <Column header="Status"><template #body="{ data }"><Tag :value="humanize(data.status)" severity="secondary" class="mini" /></template></Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button label="Link" size="small" :disabled="data.id === shipment.customer_invoice_id" @click="linkInvoice(data)" />
          </template>
        </Column>
        <template #empty>No invoices raised for this customer yet — an invoice is created when the sales order is delivered.</template>
      </DataTable>
      <template #footer><Button label="Close" text @click="invoicePicker = null" /></template>
    </Dialog>

    <!-- ------------------------------------------------------------- dispatch -->
    <Dialog :visible="!!dispatchForm" modal header="Raise the dispatch" :style="{ width: '680px' }"
      @update:visible="v => { if (!v) dispatchForm = null }">
      <div v-if="dispatchForm">
        <p class="muted hint">
          Raises a draft dispatch plan carrying this consignment to the port, which the transport desk then
          schedules and marks delivered as usual. The vehicle and driver can be left to them.
        </p>
        <div class="form-grid">
          <div class="fld"><label>Dispatch date</label><input type="date" v-model="dispatchForm.dispatch_date" class="date-input" /></div>
          <div class="fld">
            <label>Vehicle</label>
            <Select v-model="dispatchForm.vehicle_id" :options="transportOptions.vehicles" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld">
            <label>Driver</label>
            <Select v-model="dispatchForm.driver_id" :options="transportOptions.drivers" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld">
            <label>Route</label>
            <Select v-model="dispatchForm.route_id" :options="transportOptions.routes" optionLabel="label" optionValue="value" filter showClear />
          </div>
          <div class="fld full"><label>Deliver to</label><InputText v-model="dispatchForm.delivery_address" /></div>
          <div class="fld full"><label>Remarks</label><Textarea v-model="dispatchForm.remarks" rows="2" /></div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dispatchForm = null" />
        <Button label="Raise dispatch" :loading="dispatchSaving" @click="saveDispatch" />
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
.sub-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
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
.totals { margin: 0.8rem 0 0; font-size: 0.85rem; color: #374151; }

.box-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.box-table th { text-align: left; font-weight: 500; color: #6b7280; font-size: 0.78rem; padding: 0.3rem 0.35rem; }
.box-table td { padding: 0.25rem 0.35rem; vertical-align: middle; }
.box-table :deep(.p-inputtext), .box-table :deep(.p-inputnumber), .box-table :deep(.p-select) { width: 100%; }
.box-select { min-width: 9rem; }
.box-select :deep(.p-select-label) { padding: 0.3rem 0.5rem; font-size: 0.82rem; }

.muted { color: #9ca3af; font-size: 0.82rem; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

@media (max-width: 900px) {
  .form-grid, .form-grid.two { grid-template-columns: 1fr; }
}
</style>
