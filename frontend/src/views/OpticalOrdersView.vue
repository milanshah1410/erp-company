<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('optical-orders.create'))
const canEdit = computed(() => auth.can('optical-orders.edit'))
const canDelete = computed(() => auth.can('optical-orders.delete'))

const rows = ref<any[]>([])
const loading = ref(false)
const summary = ref({ open: 0, at_lab: 0, overdue: 0 })

const customers = ref<any[]>([])
const prescriptions = ref<any[]>([])
const frames = ref<any[]>([])
const lenses = ref<any[]>([])

const search = ref('')
const statusFilter = ref<string | null>(null)
const openOnly = ref(true)

const STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Ordered', value: 'ordered' },
  { label: 'At Lab', value: 'at_lab' },
  { label: 'Received', value: 'received' },
  { label: 'Fitted', value: 'fitted' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]
const VISION_TYPES = [
  { label: 'Distance', value: 'distance' },
  { label: 'Near', value: 'near' },
  { label: 'Bifocal', value: 'bifocal' },
  { label: 'Progressive', value: 'progressive' },
]
// The forward step from each state — the workshop flow only runs one way.
const NEXT: Record<string, string> = {
  draft: 'ordered',
  ordered: 'at_lab',
  at_lab: 'received',
  received: 'fitted',
  fitted: 'delivered',
}
const statusColors: Record<string, string> = {
  draft: 'secondary',
  ordered: 'info',
  at_lab: 'warn',
  received: 'info',
  fitted: 'info',
  delivered: 'success',
  cancelled: 'danger',
}

function money(v: any) {
  return formatCurrency(v)
}

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/optical-orders', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        open_only: openOnly.value ? 1 : undefined,
        per_page: 50,
      },
    })
    rows.value = data.data
    if (data.summary) summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadLookups() {
  const [c, p, prod] = await Promise.all([
    http.get('/customers', { params: { per_page: 200, is_active: true } }),
    http.get('/eye-prescriptions', { params: { valid_only: 1, per_page: 200 } }),
    http.get('/products', { params: { per_page: 300, is_active: true } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  prescriptions.value = (p.data.data ?? []).map((x: any) => ({
    label: `${x.prescription_number} — ${x.patient_name}`,
    value: x.id,
    customer_id: x.customer_id,
  }))

  const products = prod.data.data ?? prod.data
  frames.value = products
    .filter((x: any) => x.is_frame)
    .map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id, price: Number(x.selling_price) || 0 }))
  lenses.value = products
    .filter((x: any) => x.lens_type)
    .map((x: any) => ({
      label: `${x.name} — ${x.lens_type}`,
      value: x.id,
      price: Number(x.selling_price) || 0,
    }))
}

// ---- Create / edit ----------------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  eye_prescription_id: null as number | null,
  warehouse_id: null as number | null,
  frame_product_id: null as number | null,
  lens_product_id: null as number | null,
  vision_type: 'distance',
  right_sph: null as number | null,
  right_cyl: null as number | null,
  right_axis: null as number | null,
  right_add: null as number | null,
  left_sph: null as number | null,
  left_cyl: null as number | null,
  left_axis: null as number | null,
  left_add: null as number | null,
  pd: null as number | null,
  frame_price: 0,
  lens_price: 0,
  fitting_charge: 0,
  discount_type: null as string | null,
  discount_value: 0,
  tax_percent: 0,
  promised_on: null as Date | null,
  lab_notes: '',
  notes: '',
})
const form = ref(blank())

const hasAdd = computed(() => ['bifocal', 'progressive'].includes(form.value.vision_type))

/** Prescriptions belonging to the chosen customer, plus any walk-in ones. */
const customerPrescriptions = computed(() =>
  prescriptions.value.filter(
    (p) => !p.customer_id || p.customer_id === form.value.customer_id,
  ),
)

const previewTotal = computed(() => {
  const gross =
    (Number(form.value.frame_price) || 0) +
    (Number(form.value.lens_price) || 0) +
    (Number(form.value.fitting_charge) || 0)
  const disc =
    form.value.discount_type === 'percent'
      ? (gross * (Number(form.value.discount_value) || 0)) / 100
      : Math.min(gross, Number(form.value.discount_value) || 0)
  const taxable = gross - disc
  return Math.round(taxable * (1 + (Number(form.value.tax_percent) || 0) / 100))
})

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    ...blank(),
    customer_id: row.customer_id,
    eye_prescription_id: row.eye_prescription_id,
    frame_product_id: row.frame?.id ?? null,
    lens_product_id: row.lens?.id ?? null,
    vision_type: row.vision_type,
    right_sph: row.right.sph,
    right_cyl: row.right.cyl,
    right_axis: row.right.axis,
    right_add: row.right.add,
    left_sph: row.left.sph,
    left_cyl: row.left.cyl,
    left_axis: row.left.axis,
    left_add: row.left.add,
    pd: row.pd,
    frame_price: row.frame_price,
    lens_price: row.lens_price,
    fitting_charge: row.fitting_charge,
    discount_type: row.discount_type,
    discount_value: row.discount_value,
    tax_percent: row.tax_percent,
    promised_on: row.promised_on ? new Date(row.promised_on) : null,
    lab_notes: row.lab_notes ?? '',
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

/** Pull the powers off the chosen test so nobody re-types them. */
async function onPrescriptionPicked() {
  if (!form.value.eye_prescription_id) return
  const { data } = await http.get(`/eye-prescriptions/${form.value.eye_prescription_id}`)
  const rx = data.data
  form.value.vision_type = rx.vision_type
  form.value.right_sph = rx.right.sph
  form.value.right_cyl = rx.right.cyl
  form.value.right_axis = rx.right.axis
  form.value.right_add = rx.right.add
  form.value.left_sph = rx.left.sph
  form.value.left_cyl = rx.left.cyl
  form.value.left_axis = rx.left.axis
  form.value.left_add = rx.left.add
  form.value.pd = rx.pd
}

watch(
  () => form.value.frame_product_id,
  (id) => {
    const f = frames.value.find((x) => x.value === id)
    if (f) form.value.frame_price = f.price
  },
)
watch(
  () => form.value.lens_product_id,
  (id) => {
    const l = lenses.value.find((x) => x.value === id)
    if (l) form.value.lens_price = l.price
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, promised_on: iso(form.value.promised_on) }
    if (editing.value) await http.put(`/optical-orders/${editing.value.id}`, payload)
    else await http.post('/optical-orders', payload)
    toast.add({ severity: 'success', summary: pt('Order saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the order'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function move(row: any, status: string) {
  if (status === 'delivered' || status === 'cancelled') {
    const ok = await Swal.fire({
      title: status === 'delivered' ? pt('Hand over and bill this order?') : pt('Cancel this order?'),
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: pt('Yes'),
      cancelButtonText: pt('Cancel'),
    })
    if (!ok.isConfirmed) return
  }
  try {
    const { data } = await http.post(`/optical-orders/${row.id}/transition`, { status })
    toast.add({
      severity: 'success',
      summary: pt('Order updated'),
      detail: data.invoice_raised ? pt('Invoice raised') : '',
      life: 3000,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4000 })
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this order?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/optical-orders/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Order deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

function power(eye: any) {
  if (eye.sph === null && eye.cyl === null) return '—'
  const sign = (v: number | null) => (v === null ? '0.00' : (v > 0 ? '+' : '') + v.toFixed(2))
  let s = sign(eye.sph)
  if (eye.cyl) s += ` / ${sign(eye.cyl)}${eye.axis !== null ? ` x ${eye.axis}` : ''}`
  if (eye.add) s += ` Add ${sign(eye.add)}`
  return s
}

onMounted(async () => {
  await loadLookups()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Spectacle Orders')"
      :subtitle="pt('Frame plus lenses, ground to the powers on the order and tracked through the lab')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open orders') }}</span>
        <strong class="t-value">{{ summary.open }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('At lab') }}</span>
        <strong class="t-value">{{ summary.at_lab }}</strong>
      </div>
      <div class="tile" :class="{ alert: summary.overdue > 0 }">
        <span class="t-label">{{ pt('Overdue') }}</span>
        <strong class="t-value">{{ summary.overdue }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Order no or customer')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="filter"
        @change="load"
      />
      <label class="inline-check">
        <Checkbox v-model="openOnly" binary @change="load" /> {{ pt('Open only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Order')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Spectacle Orders"
        filenameBase="optical-orders"
        :rows="rows"
        :columns="[
          { key: 'order_number', label: 'Order No' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'status', label: 'Status' },
          { key: 'vision_type', label: 'Vision' },
          { key: 'total', label: 'Total' },
          { key: 'promised_on', label: 'Promised' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Order')">
        <template #body="{ data }">
          <strong>{{ data.order_number }}</strong>
          <br /><small class="muted">{{ data.customer?.name }}</small>
        </template>
      </Column>
      <Column :header="pt('Spectacles')">
        <template #body="{ data }">
          {{ data.frame?.name ?? pt('No frame') }}
          <br /><small class="muted">{{ data.lens?.name ?? pt('No lens') }}</small>
        </template>
      </Column>
      <Column :header="pt('Powers')">
        <template #body="{ data }">
          <code class="power">R {{ power(data.right) }}</code>
          <br /><code class="power">L {{ power(data.left) }}</code>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="statusColors[data.status]" />
          <Tag v-if="data.is_overdue" :value="pt('Overdue')" severity="danger" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Promised')">
        <template #body="{ data }">{{ data.promised_on ?? '—' }}</template>
      </Column>
      <Column :header="pt('Total')">
        <template #body="{ data }">
          {{ money(data.total) }}
          <br v-if="data.invoice" /><small v-if="data.invoice" class="muted">{{ data.invoice }}</small>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              v-if="canEdit && ['draft', 'ordered'].includes(data.status)"
              icon="pi pi-pencil"
              text
              size="small"
              :title="pt('Edit')"
              @click="openEdit(data)"
            />
            <Button
              v-if="canEdit && NEXT[data.status]"
              :label="pt(NEXT[data.status])"
              icon="pi pi-arrow-right"
              text
              size="small"
              @click="move(data, NEXT[data.status])"
            />
            <Button
              v-if="canEdit && !['delivered', 'cancelled'].includes(data.status)"
              icon="pi pi-times"
              text
              size="small"
              severity="danger"
              :title="pt('Cancel')"
              @click="move(data, 'cancelled')"
            />
            <Button
              v-if="canDelete && data.status === 'draft'"
              icon="pi pi-trash"
              text
              size="small"
              severity="danger"
              :title="pt('Delete')"
              @click="remove(data)"
            />
          </div>
        </template>
      </Column>
      <template #empty>{{ pt('No spectacle orders match this view.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Order') : pt('New Spectacle Order')"
      :style="{ width: '52rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Customer') }} *</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter class="w-full" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Eye prescription') }}</label>
          <Select
            v-model="form.eye_prescription_id"
            :options="customerPrescriptions"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Enter powers manually')"
            class="w-full"
            @change="onPrescriptionPicked"
          />
          <small class="hint">{{ pt('Picking one copies its powers onto this order.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Frame') }}</label>
          <Select v-model="form.frame_product_id" :options="frames" optionLabel="label" optionValue="value" filter showClear class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Lens') }}</label>
          <Select v-model="form.lens_product_id" :options="lenses" optionLabel="label" optionValue="value" filter showClear class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Vision type') }}</label>
          <Select v-model="form.vision_type" :options="VISION_TYPES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('PD (mm)') }}</label>
          <InputNumber v-model="form.pd" :min="40" :max="85" :maxFractionDigits="1" class="w-full" />
        </div>
      </div>

      <table class="powers">
        <thead>
          <tr>
            <th></th>
            <th>{{ pt('SPH') }}</th>
            <th>{{ pt('CYL') }}</th>
            <th>{{ pt('AXIS') }}</th>
            <th v-if="hasAdd">{{ pt('ADD') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th>{{ pt('Right (OD)') }}</th>
            <td><InputNumber v-model="form.right_sph" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.right_cyl" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.right_axis" :min="0" :max="180" class="p-in axis" /></td>
            <td v-if="hasAdd"><InputNumber v-model="form.right_add" :step="0.25" :min="0" :max="4" :minFractionDigits="2" showButtons class="p-in" /></td>
          </tr>
          <tr>
            <th>{{ pt('Left (OS)') }}</th>
            <td><InputNumber v-model="form.left_sph" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.left_cyl" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.left_axis" :min="0" :max="180" class="p-in axis" /></td>
            <td v-if="hasAdd"><InputNumber v-model="form.left_add" :step="0.25" :min="0" :max="4" :minFractionDigits="2" showButtons class="p-in" /></td>
          </tr>
        </tbody>
      </table>

      <div v-if="errors.powers" class="power-errors">
        <div v-for="(msg, i) in errors.powers" :key="i"><i class="pi pi-times-circle" /> {{ msg }}</div>
      </div>

      <h4 class="sec">{{ pt('Charges') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Frame price') }}</label>
          <InputNumber v-model="form.frame_price" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Lens price') }}</label>
          <InputNumber v-model="form.lens_price" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Fitting charge') }}</label>
          <InputNumber v-model="form.fitting_charge" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Discount type') }}</label>
          <Select
            v-model="form.discount_type"
            :options="[{ label: pt('Percent'), value: 'percent' }, { label: pt('Flat'), value: 'flat' }]"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Discount value') }}</label>
          <InputNumber v-model="form.discount_value" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Tax %') }}</label>
          <InputNumber v-model="form.tax_percent" :min="0" :max="100" suffix=" %" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Promised on') }}</label>
          <DatePicker v-model="form.promised_on" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Lab notes') }}</label>
          <Textarea v-model="form.lab_notes" rows="2" class="w-full" />
        </div>
      </div>

      <div class="preview">
        <span>{{ pt('Order total') }}</span>
        <strong>{{ money(previewTotal) }}</strong>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.alert { border-color: #dc2626; background: rgba(220, 38, 38, 0.06); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.filter { width: 170px; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.25rem; }
.power { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
.row-actions { display: flex; flex-wrap: wrap; gap: 0.1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.sec { margin: 1.1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.powers { width: 100%; border-collapse: collapse; margin-top: 0.8rem; }
.powers th { font-size: 0.75rem; color: #6b7280; font-weight: 600; text-align: left; padding: 0.25rem 0.4rem; }
.powers tbody th { width: 6.5rem; color: #374151; }
.powers td { padding: 0.2rem 0.4rem; }
.p-in { width: 100%; }
.p-in.axis { max-width: 6rem; }
.power-errors {
  margin-top: 0.6rem; border: 1px solid #f0b4b4; background: #fdf1f1;
  color: #8a2b2b; border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.78rem;
}
.power-errors div { display: flex; align-items: center; gap: 0.35rem; padding: 0.1rem 0; }
.preview { display: flex; justify-content: space-between; align-items: baseline; margin-top: 1rem; padding-top: 0.6rem; border-top: 1px solid var(--surface-border, #e5e7eb); font-size: 0.9rem; }
.preview strong { font-size: 1.1rem; }
</style>
