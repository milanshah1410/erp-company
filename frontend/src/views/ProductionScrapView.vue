<script setup lang="ts">
/**
 * Scrap & rejection.
 *
 * The summary leads with net loss rather than gross value, because regrind,
 * offcuts and paper trim are sold by the kilo in most of these trades and a shop
 * that reports only the gross figure believes it is losing more than it is.
 *
 * Loss by reason sits beside it: the total says how much, the reasons say who
 * can do something about it — a machine defect, a bad material lot and setup
 * waste are three different conversations.
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

const canCreate = computed(() => auth.can('production-scrap.create'))
const canEdit = computed(() => auth.can('production-scrap.edit'))
const canDelete = computed(() => auth.can('production-scrap.delete'))

const rows = ref<any[]>([])
const summary = ref<any>({})
const byReason = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const reasonFilter = ref<string | null>(null)

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const orders = ref<any[]>([])

const REASONS = [
  { label: 'QC Rejection', value: 'rejection' },
  { label: 'Setup / Make-ready Waste', value: 'setup_waste' },
  { label: 'Machine Defect', value: 'machine_defect' },
  { label: 'Material Defect', value: 'material_defect' },
  { label: 'Operator Error', value: 'operator_error' },
  { label: 'Trial Run', value: 'trial_run' },
  { label: 'Expired', value: 'expiry' },
  { label: 'Other', value: 'other' },
]

const TYPES = [
  { label: 'Raw material', value: 'material' },
  { label: 'Finished output', value: 'finished' },
]

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/production-scraps', {
      params: {
        search: search.value || undefined,
        reason: reasonFilter.value || undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
    byReason.value = data.by_reason
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  const [p, w, o] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 200 } }),
    http.get('/quality-inspections/order-options').catch(() => ({ data: { data: [] } })),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    cost_price: x.cost_price,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  orders.value = o.data.data.map((x: any) => ({ label: x.mo_number, value: x.id, product_id: x.product_id }))
}

const maxReasonLoss = computed(() =>
  byReason.value.reduce((m, r) => Math.max(m, Number(r.net_loss) || 0), 0),
)

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  scrap_date: new Date(),
  manufacturing_order_id: null as number | null,
  product_id: null as number | null,
  warehouse_id: null as number | null,
  quantity: null as number | null,
  scrap_type: 'material',
  reason: 'rejection',
  unit_cost: null as number | null,
  recoverable_value: null as number | null,
  affects_stock: true,
  remarks: '',
})

const form = ref(blank())

const grossValue = computed(
  () => (Number(form.value.quantity) || 0) * (Number(form.value.unit_cost) || 0),
)
const netLoss = computed(() => grossValue.value - (Number(form.value.recoverable_value) || 0))

function toIso(v: Date | null) {
  if (!v) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`
}

function onProductPicked() {
  if (form.value.unit_cost === null || form.value.unit_cost === undefined) {
    const p = products.value.find((x) => x.value === form.value.product_id)
    if (p) form.value.unit_cost = Number(p.cost_price) || 0
  }
}

function openCreate() {
  form.value = blank()
  form.value.warehouse_id = warehouses.value[0]?.value ?? null
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  errors.value = {}
  editingId.value = row.id
  form.value = {
    scrap_date: row.scrap_date ? new Date(row.scrap_date) : new Date(),
    manufacturing_order_id: row.manufacturing_order_id,
    product_id: row.product_id,
    warehouse_id: row.warehouse_id,
    quantity: row.quantity,
    scrap_type: row.scrap_type,
    reason: row.reason,
    unit_cost: row.unit_cost,
    recoverable_value: row.recoverable_value,
    affects_stock: row.affects_stock,
    remarks: row.remarks ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, scrap_date: toIso(form.value.scrap_date) }
    if (editingId.value) {
      await http.put(`/production-scraps/${editingId.value}`, payload)
    } else {
      await http.post('/production-scraps', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Scrap note saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the scrap note'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this scrap note?'),
    text: row.is_posted
      ? pt('The stock it wrote off is returned to the warehouse.')
      : pt('This note moved no stock.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  await http.delete(`/production-scraps/${row.id}`)
  await load()
  toast.add({ severity: 'success', summary: pt('Scrap note deleted'), life: 2000 })
}

onMounted(() => {
  load()
  loadOptions()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Scrap & Rejection')"
      :subtitle="pt('What was made and lost — with what it cost and what can still be recovered')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Scrap Note')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Scrap notes') }}</span>
        <span class="t-value">{{ summary.notes ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Quantity') }}</span>
        <span class="t-value">{{ summary.quantity ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value scrapped') }}</span>
        <span class="t-value">{{ money(summary.value ?? 0) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Recovered') }}</span>
        <span class="t-value ok">{{ money(summary.recoverable_value ?? 0) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Net loss') }}</span>
        <span class="t-value bad">{{ money(summary.net_loss ?? 0) }}</span>
      </div>
    </div>

    <div v-if="byReason.length" class="pareto">
      <h4 class="section-title">{{ pt('Loss by reason') }}</h4>
      <div class="pareto-rows">
        <div v-for="r in byReason" :key="r.reason" class="p-row">
          <span class="p-name">{{ pt(r.reason_label) }}</span>
          <span class="p-bar">
            <span
              class="p-fill"
              :style="{ width: maxReasonLoss > 0 ? (r.net_loss / maxReasonLoss) * 100 + '%' : '0%' }"
            ></span>
          </span>
          <span class="p-val">{{ money(r.net_loss) }}</span>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search number or product')" @keyup.enter="load" />
      <Select
        v-model="reasonFilter"
        :options="REASONS"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Any reason')"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Scrap & Rejection"
        filenameBase="production-scrap"
        :rows="rows"
        :columns="[
          { key: 'scrap_number', label: 'Number' },
          { key: 'scrap_date', label: 'Date' },
          { key: 'product', label: 'Product' },
          { key: 'quantity', label: 'Quantity' },
          { key: 'reason_label', label: 'Reason' },
          { key: 'total_value', label: 'Value' },
          { key: 'recoverable_value', label: 'Recovered' },
          { key: 'net_loss', label: 'Net loss' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="id"
      paginator
      :rows="25"
      striped-rows
      responsive-layout="scroll"
    >
      <Column field="scrap_number" :header="pt('Number')" sortable />
      <Column field="scrap_date" :header="pt('Date')" sortable />
      <Column field="product" :header="pt('Product')" sortable>
        <template #body="{ data }">
          <div>
            <div>{{ data.product }}</div>
            <small class="muted">
              {{ data.sku }}
              <template v-if="data.mo_number"> · {{ data.mo_number }}</template>
              <template v-if="data.inspection_number"> · {{ data.inspection_number }}</template>
            </small>
          </div>
        </template>
      </Column>
      <Column field="quantity" :header="pt('Quantity')" sortable />
      <Column field="scrap_type" :header="pt('Type')">
        <template #body="{ data }">
          <Tag
            :value="data.scrap_type === 'material' ? pt('Raw material') : pt('Finished output')"
            :severity="data.scrap_type === 'material' ? 'info' : 'secondary'"
          />
        </template>
      </Column>
      <Column field="reason_label" :header="pt('Reason')" sortable>
        <template #body="{ data }">{{ pt(data.reason_label) }}</template>
      </Column>
      <Column field="total_value" :header="pt('Value')" sortable>
        <template #body="{ data }">{{ money(data.total_value) }}</template>
      </Column>
      <Column field="net_loss" :header="pt('Net loss')" sortable>
        <template #body="{ data }">
          <strong>{{ money(data.net_loss) }}</strong>
          <small v-if="data.recoverable_value > 0" class="muted">
            ({{ money(data.recoverable_value) }} {{ pt('recovered') }})
          </small>
        </template>
      </Column>
      <Column field="is_posted" :header="pt('Stock')">
        <template #body="{ data }">
          <Tag
            v-if="data.is_posted"
            :value="pt('Written off')"
            severity="danger"
            :title="pt('Stock was taken out of the warehouse')"
          />
          <span v-else class="muted" :title="pt('No stock movement — this output was never received')">—</span>
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
          <Button
            v-if="canDelete"
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
          {{ pt('No scrap recorded. What a shop cannot see, it cannot reduce.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Scrap Note') : pt('New Scrap Note')"
      modal
      :style="{ width: '840px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Date') }} *</label>
          <DatePicker v-model="form.scrap_date" date-format="dd/mm/yy" show-icon />
        </div>
        <div class="fld">
          <label>{{ pt('Production order') }}</label>
          <Select
            v-model="form.manufacturing_order_id"
            :options="orders"
            option-label="label"
            option-value="value"
            filter
            show-clear
          />
        </div>
        <div class="fld">
          <label>{{ pt('Reason') }} *</label>
          <Select v-model="form.reason" :options="REASONS" option-label="label" option-value="value" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Product') }} *</label>
          <Select
            v-model="form.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            @change="onProductPicked"
          />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Warehouse') }} *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" option-label="label" option-value="value" filter />
        </div>

        <div class="fld">
          <label>{{ pt('What was lost') }} *</label>
          <Select v-model="form.scrap_type" :options="TYPES" option-label="label" option-value="value" />
          <small class="hint">
            {{
              form.scrap_type === 'material'
                ? pt('Issued material — this leaves the warehouse.')
                : pt('Output rejected on the line — it was never received into stock.')
            }}
          </small>
        </div>
        <div class="fld">
          <label>{{ pt('Quantity') }} *</label>
          <InputNumber v-model="form.quantity" :min="0" :max-fraction-digits="3" />
          <small v-if="errors.quantity" class="err">{{ errors.quantity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Unit cost') }}</label>
          <InputNumber v-model="form.unit_cost" :min="0" :max-fraction-digits="4" />
        </div>

        <div class="fld">
          <label>{{ pt('Recoverable value') }}</label>
          <InputNumber v-model="form.recoverable_value" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt('Regrind, offcut or trim that can still be sold.') }}</small>
          <small v-if="errors.recoverable_value" class="err">{{ errors.recoverable_value[0] }}</small>
        </div>
        <div v-if="form.scrap_type === 'material'" class="fld">
          <label>&nbsp;</label>
          <label class="inline-check">
            <Checkbox v-model="form.affects_stock" binary />
            {{ pt('Take this out of stock') }}
          </label>
          <small class="hint">{{ pt('Untick if the stock was already written off by a count.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Net loss') }}</label>
          <div class="readout">
            <strong>{{ money(netLoss) }}</strong>
            <small class="muted">{{ pt('of') }} {{ money(grossValue) }}</small>
          </div>
        </div>

        <div class="fld span-3">
          <label>{{ pt('Remarks') }}</label>
          <Textarea v-model="form.remarks" rows="2" auto-resize />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.ok { color: #15803d; }
.t-value.bad { color: #b91c1c; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.readout { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.45rem 0.6rem; display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.section-title { margin: 1.2rem 0 0.5rem; font-size: 0.9rem; }

.pareto { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.6rem 0.9rem 0.9rem; margin-bottom: 1rem; }
.pareto .section-title { margin-top: 0.3rem; }
.pareto-rows { display: flex; flex-direction: column; gap: 0.35rem; }
.p-row { display: grid; grid-template-columns: minmax(160px, 1fr) 2fr auto; gap: 0.7rem; align-items: center; font-size: 0.82rem; }
.p-bar { background: var(--surface-border, #e5e7eb); border-radius: 999px; height: 0.5rem; overflow: hidden; }
.p-fill { display: block; height: 100%; background: #b45309; }
.p-val { font-variant-numeric: tabular-nums; color: #6b7280; }
</style>
