<script setup lang="ts">
/**
 * The trial room — garments out of the shop and not yet paid for.
 *
 * Every question this screen answers is a custody question, so it is built to be
 * *worked* rather than browsed: open slips sort to the top with the oldest
 * promise first, overdue ones are flagged, and the tiles are denominated in
 * money rather than row counts. "₹18,400 out, ₹4,100 of it late" is the sentence
 * the owner wants; "7 open trials" is not.
 *
 * Settling is the other half. A slip is closed line by line — this came back,
 * that one was kept — because that is how a customer actually returns: with two
 * of the four pieces. Keeping a garment releases the reservation and leaves it to
 * be billed at the till like any other sale; the trial then records which
 * invoice settled it. The screen deliberately cannot ring the sale up itself.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('garment-trials.create'))
const canEdit = computed(() => auth.can('garment-trials.edit'))
const canDelete = computed(() => auth.can('garment-trials.delete'))

const STATUSES = [
  { label: 'Open', value: 'open' },
  { label: 'Returned', value: 'closed' },
  { label: 'Converted to sale', value: 'converted' },
  { label: 'Cancelled', value: 'cancelled' },
]

const rows = ref<any[]>([])
const summary = ref({ open: 0, overdue: 0, value_out: 0, value_overdue: 0, converted: 0, conversion_percent: 0 })
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const overdueOnly = ref(false)

const customers = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/garment/trials', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        overdue: overdueOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [c, w, p] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: `${x.name} — ${x.phone ?? ''}`.trim(), value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    price: Number(x.selling_price ?? 0),
  }))
}

// ---- Issue ----------------------------------------------------------------

const issueVisible = ref(false)
const issuing = ref(false)
const issueErrors = ref<Record<string, string[]>>({})
const issueForm = ref<any>(blankIssue())

function blankIssue() {
  const due = new Date()
  // Two days is the promise a shop actually makes. Defaulted rather than left
  // empty because a trial with no due date cannot be chased, and the chasing is
  // the entire point of the register.
  due.setDate(due.getDate() + 2)
  return {
    customer_id: null,
    warehouse_id: null,
    due_at: due,
    deposit_amount: 0,
    deposit_mode: null,
    notes: '',
    items: [{ product_id: null, quantity: 1, unit_price: 0, remarks: '' }],
  }
}

function openIssue() {
  issueForm.value = blankIssue()
  issueForm.value.warehouse_id = warehouses.value[0]?.value ?? null
  issueErrors.value = {}
  issueVisible.value = true
}

function addLine() {
  issueForm.value.items.push({ product_id: null, quantity: 1, unit_price: 0, remarks: '' })
}

function removeLine(i: number | string) {
  issueForm.value.items.splice(Number(i), 1)
}

/** Price the line off the product so the slip carries what the customer was quoted. */
function onProductPicked(line: any) {
  const p = products.value.find((x) => x.value === line.product_id)
  if (p && !line.unit_price) line.unit_price = p.price
}

const issueValue = computed(() =>
  issueForm.value.items.reduce(
    (sum: number, l: any) => sum + Number(l.quantity || 0) * Number(l.unit_price || 0),
    0,
  ),
)

async function saveIssue() {
  issuing.value = true
  issueErrors.value = {}
  try {
    await http.post('/garment/trials', {
      ...issueForm.value,
      due_at: asDate(issueForm.value.due_at),
      items: issueForm.value.items.filter((l: any) => l.product_id),
    })
    issueVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Trial issued'), life: 2500 })
  } catch (e: any) {
    issueErrors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not issue the trial'),
      life: 3500,
    })
  } finally {
    issuing.value = false
  }
}

// ---- Settle ---------------------------------------------------------------

const settleVisible = ref(false)
const settling = ref(false)
const detail = ref<any>(null)
const settleLines = ref<any[]>([])
const linkInvoiceId = ref<number | null>(null)
const settleError = ref<string | null>(null)

async function openSettle(row: any) {
  settleError.value = null
  linkInvoiceId.value = null
  const { data } = await http.get(`/garment/trials/${row.id}`)
  detail.value = data.data
  // Pre-filled with the whole outstanding quantity as *returned*, because that
  // is what happens on most slips — a customer who kept nothing is the common
  // case, and the counter only has to change the lines that were bought.
  settleLines.value = data.data.items.map((i: any) => ({
    item_id: i.id,
    label: [i.product_name, i.variant_name].filter(Boolean).join(' · '),
    sku: i.sku,
    outstanding: i.outstanding_quantity,
    returned_quantity: i.outstanding_quantity,
    sold_quantity: 0,
    remarks: i.remarks ?? '',
  }))
  settleVisible.value = true
}

function lineOver(line: any) {
  return Number(line.returned_quantity || 0) + Number(line.sold_quantity || 0) > line.outstanding + 0.0005
}

const settleInvalid = computed(() => settleLines.value.some(lineOver))

async function saveSettle() {
  settling.value = true
  settleError.value = null
  try {
    await http.post(`/garment/trials/${detail.value.id}/settle`, {
      lines: settleLines.value
        .filter((l) => Number(l.returned_quantity || 0) + Number(l.sold_quantity || 0) > 0)
        .map((l) => ({
          item_id: l.item_id,
          returned_quantity: Number(l.returned_quantity || 0),
          sold_quantity: Number(l.sold_quantity || 0),
          remarks: l.remarks || undefined,
        })),
    })
    if (linkInvoiceId.value) {
      await http.post(`/garment/trials/${detail.value.id}/link-invoice`, { invoice_id: linkInvoiceId.value })
    }
    settleVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Trial settled'), life: 2500 })
  } catch (e: any) {
    settleError.value =
      e.response?.data?.errors?.lines?.[0] ??
      e.response?.data?.errors?.invoice_id?.[0] ??
      e.response?.data?.message ??
      pt('Could not settle the trial')
  } finally {
    settling.value = false
  }
}

async function cancel(row: any) {
  const res = await Swal.fire({
    title: pt('Cancel this trial?'),
    text: pt('Everything still out is released back to available stock. The record stays for the audit trail.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Cancel Trial'),
    cancelButtonText: pt('Keep It'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/garment/trials/${row.id}/cancel`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this trial?'),
    text: pt('Only closed trials can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/garment/trials/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

function asDate(v: any): string | undefined {
  if (!v) return undefined
  const d = v instanceof Date ? v : new Date(v)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function statusTag(row: any) {
  if (row.status === 'converted') return { label: pt('Converted'), severity: 'success' }
  if (row.status === 'closed') return { label: pt('Returned'), severity: 'secondary' }
  if (row.status === 'cancelled') return { label: pt('Cancelled'), severity: 'contrast' }
  return row.is_overdue
    ? { label: pt('Overdue'), severity: 'danger' }
    : { label: pt('Out on trial'), severity: 'warn' }
}

const exportColumns: ExportColumn[] = [
  { key: 'trial_number', label: 'Trial #' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'customer_phone', label: 'Phone' },
  { key: 'due_at', label: 'Due back' },
  { key: 'pieces', label: 'Pieces' },
  { key: 'outstanding_quantity', label: 'Still out' },
  { key: 'outstanding_value', label: 'Value out' },
  { key: 'status', label: 'Status' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Trial Room')"
      :subtitle="pt('Garments out of the shop and not yet paid for')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Issue Trial')" icon="pi pi-plus" @click="openIssue" />
      </template>
    </PageHeader>

    <!-- Denominated in money, because the exposure is the point. -->
    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open trials') }}</span>
        <span class="t-value">{{ summary.open }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value out') }}</span>
        <span class="t-value">{{ formatCurrency(summary.value_out) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <span class="t-value danger">{{ summary.overdue }}</span>
        <small class="muted">{{ formatCurrency(summary.value_overdue) }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Turn into sales') }}</span>
        <span class="t-value">{{ summary.conversion_percent }}%</span>
        <small class="muted">{{ summary.converted }} {{ pt('converted') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search trial no. or customer')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        showClear
        :placeholder="pt('All statuses')"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="overdueOnly" binary @change="load" />
        <span>{{ pt('Overdue only') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="garment-trials" :title="pt('Trial Room')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="trial_number" :header="pt('Trial #')" sortable />
      <Column field="customer_name" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div>{{ data.customer_name }}</div>
          <small class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>
      <Column field="due_at" :header="pt('Due back')" sortable>
        <template #body="{ data }">
          <span :class="{ late: data.is_overdue }">{{ data.due_at }}</span>
        </template>
      </Column>
      <Column field="pieces" :header="pt('Pieces')" sortable />
      <Column field="outstanding_quantity" :header="pt('Still out')" sortable>
        <template #body="{ data }">
          <span class="strong">{{ data.outstanding_quantity }}</span>
        </template>
      </Column>
      <Column field="outstanding_value" :header="pt('Value out')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.outstanding_value) }}</template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.outstanding_quantity > 0 && data.status === 'open'"
            icon="pi pi-check-square"
            text
            rounded
            :title="pt('Settle')"
            @click="openSettle(data)"
          />
          <Button
            v-if="canDelete && data.status === 'open'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="cancel(data)"
          />
          <Button
            v-if="canDelete && data.status !== 'open'"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing out on trial. Issuing one reserves the stock, so the counter stops offering to sell what has left the shop.') }}
        </div>
      </template>
    </DataTable>

    <!-- Issue -->
    <Dialog v-model:visible="issueVisible" modal :header="pt('Issue Trial')" :style="{ width: '820px' }">
      <Message severity="info" :closable="false" class="mb">
        {{ pt('Issuing reserves the stock rather than moving it: availability drops at the counter, and the sale still posts the stock properly if the customer keeps it.') }}
      </Message>

      <div class="grid-form">
        <div class="fld span-2">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="issueForm.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('Who is taking these?')"
          />
          <small class="hint">{{ pt('A named customer, not a walk-in — this is somebody you may have to telephone.') }}</small>
          <small v-if="issueErrors.customer_id" class="err">{{ issueErrors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('From warehouse') }} *</label>
          <Select
            v-model="issueForm.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Due back') }} *</label>
          <DatePicker v-model="issueForm.due_at" date-format="dd/mm/yy" show-icon class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Deposit taken') }}</label>
          <InputNumber v-model="issueForm.deposit_amount" :minFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Deposit mode') }}</label>
          <InputText v-model="issueForm.deposit_mode" :placeholder="pt('Cash / UPI / Card')" />
        </div>
      </div>

      <div class="lines">
        <div class="lines-head">
          <span>{{ pt('Garments') }}</span>
          <Button :label="pt('Add line')" icon="pi pi-plus" text size="small" @click="addLine" />
        </div>
        <div v-for="(line, i) in issueForm.items" :key="i" class="line">
          <Select
            v-model="line.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            class="line-product"
            :placeholder="pt('Product')"
            @change="onProductPicked(line)"
          />
          <InputNumber v-model="line.quantity" :min="0.001" :minFractionDigits="0" :maxFractionDigits="3" class="line-qty" />
          <InputNumber v-model="line.unit_price" :minFractionDigits="2" class="line-price" />
          <InputText v-model="line.remarks" :placeholder="pt('Remarks')" class="line-remarks" />
          <Button
            icon="pi pi-times"
            text
            rounded
            severity="danger"
            :disabled="issueForm.items.length === 1"
            @click="removeLine(i)"
          />
        </div>
        <small v-if="issueErrors.items" class="err">{{ issueErrors.items[0] }}</small>
        <div class="lines-total">
          {{ pt('Value going out') }}: <span class="strong">{{ formatCurrency(issueValue) }}</span>
        </div>
      </div>

      <div class="fld span-2 mt">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="issueForm.notes" rows="2" class="w-full" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="issueVisible = false" />
        <Button :label="pt('Issue')" icon="pi pi-check" :loading="issuing" @click="saveIssue" />
      </template>
    </Dialog>

    <!-- Settle -->
    <Dialog v-model:visible="settleVisible" modal :header="pt('Settle Trial')" :style="{ width: '820px' }">
      <template v-if="detail">
        <div class="detail-strip">
          <span class="strong">{{ detail.trial_number }}</span>
          <span class="muted">{{ detail.customer_name }}</span>
          <span class="muted">·</span>
          <span class="muted">{{ pt('due') }} {{ detail.due_at }}</span>
          <Tag v-if="detail.is_overdue" :value="pt('Overdue')" severity="danger" />
        </div>

        <Message severity="info" :closable="false" class="mb">
          {{ pt('Anything kept is released for the till to bill normally — this screen does not ring up the sale. Link the invoice below once it has been billed.') }}
        </Message>

        <table class="settle">
          <thead>
            <tr>
              <th>{{ pt('Garment') }}</th>
              <th>{{ pt('Still out') }}</th>
              <th>{{ pt('Returned') }}</th>
              <th>{{ pt('Kept') }}</th>
              <th>{{ pt('Remarks') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in settleLines" :key="line.item_id" :class="{ bad: lineOver(line) }">
              <td>
                <div>{{ line.label }}</div>
                <small class="muted mono">{{ line.sku }}</small>
              </td>
              <td class="num">{{ line.outstanding }}</td>
              <td>
                <InputNumber v-model="line.returned_quantity" :min="0" :maxFractionDigits="3" class="cell-num" />
              </td>
              <td>
                <InputNumber v-model="line.sold_quantity" :min="0" :maxFractionDigits="3" class="cell-num" />
              </td>
              <td><InputText v-model="line.remarks" class="w-full" /></td>
            </tr>
          </tbody>
        </table>

        <p v-if="settleInvalid" class="err">
          {{ pt('Returned plus kept cannot be more than what is still out on a line.') }}
        </p>

        <div class="fld mt">
          <label>{{ pt('Invoice for the kept pieces') }}</label>
          <InputNumber v-model="linkInvoiceId" :useGrouping="false" :placeholder="pt('Invoice ID (optional)')" class="w-full" />
          <small class="hint">{{ pt('Optional now — the slip can be linked to its bill later.') }}</small>
        </div>

        <p v-if="settleError" class="err">{{ settleError }}</p>
      </template>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="settleVisible = false" />
        <Button
          :label="pt('Settle')"
          icon="pi pi-check"
          :loading="settling"
          :disabled="settleInvalid"
          @click="saveSettle"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }

.lines { margin-top: 1rem; border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.75rem; }
.lines-head { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.4rem; }
.line { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.45rem; }
.line-product { flex: 3; min-width: 200px; }
.line-qty { width: 90px; }
.line-price { width: 120px; }
.line-remarks { flex: 2; min-width: 120px; }
.lines-total { text-align: right; font-size: 0.85rem; margin-top: 0.4rem; }

.detail-strip { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.8rem; }

.settle { width: 100%; border-collapse: collapse; }
.settle th, .settle td { border-bottom: 1px solid var(--surface-border, #e5e7eb); padding: 0.45rem 0.5rem; font-size: 0.85rem; text-align: left; vertical-align: top; }
.settle th { font-weight: 600; color: #374151; font-size: 0.75rem; }
.settle .num { text-align: right; font-weight: 600; }
.settle tr.bad { background: #fef2f2; }
.cell-num { width: 92px; }

.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.72rem; }
.late { color: #b91c1c; font-weight: 600; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.mt { margin-top: 0.8rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
