<script setup lang="ts">
/**
 * Quality inspections.
 *
 * Read as a rejection report first: the rate and the defect Pareto lead, because
 * a factory opens this screen to find out what is going wrong, not to admire its
 * paperwork. The register is underneath.
 *
 * Raising scrap is a separate, deliberate action rather than something that
 * happens when a lot fails — rejected material is often returned, reprocessed or
 * reground, and writing stock off the moment an inspector types a number would
 * be wrong more often than right.
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
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('quality-inspections.create'))
const canEdit = computed(() => auth.can('quality-inspections.edit'))
const canDelete = computed(() => auth.can('quality-inspections.delete'))
const canScrap = computed(() => auth.can('production-scrap.create'))

const rows = ref<any[]>([])
const summary = ref<any>({})
const pareto = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const stageFilter = ref<string | null>(null)
const resultFilter = ref<string | null>(null)

const products = ref<any[]>([])
const orders = ref<any[]>([])
const employees = ref<any[]>([])
const warehouses = ref<any[]>([])

const STAGES = [
  { label: 'Incoming (material received)', value: 'incoming' },
  { label: 'In-process (on the line)', value: 'in_process' },
  { label: 'Final (finished output)', value: 'final' },
  { label: 'Pre-dispatch (before it leaves)', value: 'pre_dispatch' },
]

const RESULTS = [
  { label: 'Pending', value: 'pending' },
  { label: 'Passed', value: 'passed' },
  { label: 'Failed', value: 'failed' },
  { label: 'Conditional', value: 'conditional' },
]

const SEVERITIES = [
  { label: 'Minor', value: 'minor' },
  { label: 'Major', value: 'major' },
  { label: 'Critical', value: 'critical' },
]

function resultSeverity(result: string) {
  if (result === 'passed') return 'success'
  if (result === 'failed') return 'danger'
  if (result === 'conditional') return 'warn'
  return 'secondary'
}

async function load() {
  loading.value = true
  try {
    const [list, defects] = await Promise.all([
      http.get('/quality-inspections', {
        params: {
          search: search.value || undefined,
          stage: stageFilter.value || undefined,
          result: resultFilter.value || undefined,
          per_page: 100,
        },
      }),
      http.get('/quality-inspections/defect-analysis'),
    ])
    rows.value = list.data.data
    summary.value = list.data.summary
    pareto.value = defects.data.data
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  const [p, o, e, w] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/quality-inspections/order-options'),
    http.get('/employees', { params: { per_page: 300, is_active: 1 } }).catch(() => ({ data: { data: [] } })),
    http.get('/warehouses', { params: { per_page: 200 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
  }))
  orders.value = o.data.data.map((x: any) => ({
    label: `${x.mo_number} — ${x.quantity}`,
    value: x.id,
    product_id: x.product_id,
    quantity: x.quantity,
  }))
  employees.value = (e.data.data ?? e.data).map((x: any) => ({
    label: `${x.first_name ?? ''} ${x.last_name ?? ''}`.trim() || x.employee_code,
    value: x.id,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blankDefect = () => ({ defect: '', quantity: null as number | null, severity: 'minor', remarks: '' })

const blank = () => ({
  stage: 'final',
  inspected_on: new Date(),
  manufacturing_order_id: null as number | null,
  product_id: null as number | null,
  lot_quantity: null as number | null,
  sample_size: null as number | null,
  accepted_quantity: null as number | null,
  rejected_quantity: null as number | null,
  rework_quantity: null as number | null,
  result: null as string | null,
  inspector_id: null as number | null,
  remarks: '',
  defects: [] as any[],
})

const form = ref(blank())

/**
 * Live check of the same rule the backend enforces, so an inspector sees the
 * lot not adding up while typing rather than on submit.
 */
const judged = computed(
  () =>
    (Number(form.value.accepted_quantity) || 0) +
    (Number(form.value.rejected_quantity) || 0) +
    (Number(form.value.rework_quantity) || 0),
)

const overJudged = computed(
  () => Number(form.value.lot_quantity) > 0 && judged.value > Number(form.value.lot_quantity),
)

const rejectionPercent = computed(() => {
  const lot = Number(form.value.lot_quantity) || 0
  if (lot <= 0) return null
  return Math.round(((Number(form.value.rejected_quantity) || 0) / lot) * 1000) / 10
})

function toIso(v: Date | null) {
  if (!v) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`
}

/** Pull the product and quantity across when a run is picked. */
function onOrderPicked() {
  const o = orders.value.find((x) => x.value === form.value.manufacturing_order_id)
  if (!o) return
  if (!form.value.product_id) form.value.product_id = o.product_id
  if (!form.value.lot_quantity) form.value.lot_quantity = o.quantity
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/quality-inspections/${row.id}`)
  const r = data.data
  editingId.value = r.id
  form.value = {
    stage: r.stage,
    inspected_on: r.inspected_on ? new Date(r.inspected_on) : new Date(),
    manufacturing_order_id: r.manufacturing_order_id,
    product_id: r.product_id,
    lot_quantity: r.lot_quantity,
    sample_size: r.sample_size,
    accepted_quantity: r.accepted_quantity,
    rejected_quantity: r.rejected_quantity,
    rework_quantity: r.rework_quantity,
    result: r.result,
    inspector_id: r.inspector_id,
    remarks: r.remarks ?? '',
    defects: (r.defects ?? []).map((d: any) => ({
      defect: d.defect,
      quantity: d.quantity,
      severity: d.severity,
      remarks: d.remarks ?? '',
    })),
  }
  dialogVisible.value = true
}

function addDefect() {
  form.value.defects.push(blankDefect())
}

function removeDefect(i: number) {
  form.value.defects.splice(i, 1)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      inspected_on: toIso(form.value.inspected_on),
      defects: form.value.defects.filter((d) => d.defect),
    }
    if (editingId.value) {
      await http.put(`/quality-inspections/${editingId.value}`, payload)
    } else {
      await http.post('/quality-inspections', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Inspection saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the inspection'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this inspection?'),
    text: pt("The linked order's rejected quantity is recalculated without it."),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/quality-inspections/${row.id}`)
    await load()
    toast.add({ severity: 'success', summary: pt('Inspection deleted'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  }
}

// ---- Raise scrap ----------------------------------------------------------

const scrapVisible = ref(false)
const scrapSaving = ref(false)
const scrapRow = ref<any>(null)
const scrapForm = ref({
  warehouse_id: null as number | null,
  quantity: null as number | null,
  unit_cost: null as number | null,
  recoverable_value: null as number | null,
  remarks: '',
})

function openScrap(row: any) {
  scrapRow.value = row
  scrapForm.value = {
    warehouse_id: warehouses.value[0]?.value ?? null,
    quantity: row.rejected_quantity,
    unit_cost: null,
    recoverable_value: null,
    remarks: '',
  }
  scrapVisible.value = true
}

async function saveScrap() {
  scrapSaving.value = true
  try {
    const { data } = await http.post(
      `/quality-inspections/${scrapRow.value.id}/raise-scrap`,
      scrapForm.value,
    )
    scrapVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  } finally {
    scrapSaving.value = false
  }
}

onMounted(() => {
  load()
  loadOptions()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Quality Inspection')"
      :subtitle="pt('Incoming, in-process, final and pre-dispatch checks — and the defects behind the rejections')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Inspection')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Inspections') }}</span>
        <span class="t-value">{{ summary.inspections ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Failed') }}</span>
        <span class="t-value bad">{{ summary.failed ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Rejection rate') }}</span>
        <span class="t-value" :class="{ bad: (summary.rejection_percent ?? 0) > 5 }">
          <template v-if="summary.rejection_percent !== null && summary.rejection_percent !== undefined">
            {{ summary.rejection_percent }}%
          </template>
          <template v-else>—</template>
        </span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Rejected qty') }}</span>
        <span class="t-value">{{ summary.rejected_quantity ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Rework qty') }}</span>
        <span class="t-value">{{ summary.rework_quantity ?? 0 }}</span>
      </div>
    </div>

    <!-- Defect Pareto: the actionable half of the module. -->
    <div v-if="pareto.length" class="pareto">
      <h4 class="section-title">{{ pt('Top defects') }}</h4>
      <div class="pareto-rows">
        <div v-for="d in pareto.slice(0, 6)" :key="d.defect + d.severity" class="p-row">
          <span class="p-name">
            {{ d.defect }}
            <Tag
              v-if="d.severity !== 'minor'"
              :value="pt(d.severity)"
              :severity="d.severity === 'critical' ? 'danger' : 'warn'"
            />
          </span>
          <span class="p-bar">
            <span class="p-fill" :style="{ width: (d.share_percent ?? 0) + '%' }"></span>
          </span>
          <span class="p-val">{{ d.quantity }} · {{ d.share_percent }}%</span>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search number or product')" @keyup.enter="load" />
      <Select
        v-model="stageFilter"
        :options="STAGES"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Any stage')"
        @change="load"
      />
      <Select
        v-model="resultFilter"
        :options="RESULTS"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Any result')"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Quality Inspections"
        filenameBase="quality-inspections"
        :rows="rows"
        :columns="[
          { key: 'inspection_number', label: 'Number' },
          { key: 'inspected_on', label: 'Date' },
          { key: 'stage', label: 'Stage' },
          { key: 'product', label: 'Product' },
          { key: 'lot_quantity', label: 'Lot' },
          { key: 'rejected_quantity', label: 'Rejected' },
          { key: 'rejection_percent', label: 'Rejection %' },
          { key: 'result', label: 'Result' },
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
      <Column field="inspection_number" :header="pt('Number')" sortable />
      <Column field="inspected_on" :header="pt('Date')" sortable />
      <Column field="stage" :header="pt('Stage')" sortable>
        <template #body="{ data }">{{ pt(data.stage_label) }}</template>
      </Column>
      <Column field="product" :header="pt('Product')" sortable>
        <template #body="{ data }">
          <div>
            <div>{{ data.product }}</div>
            <small class="muted">
              {{ data.sku }}
              <template v-if="data.mo_number"> · {{ data.mo_number }}</template>
              <template v-else-if="data.grn_number"> · {{ data.grn_number }}</template>
            </small>
          </div>
        </template>
      </Column>
      <Column field="lot_quantity" :header="pt('Lot')" sortable />
      <Column field="rejected_quantity" :header="pt('Rejected')" sortable />
      <Column field="rejection_percent" :header="pt('Rejection')" sortable>
        <template #body="{ data }">
          <Tag
            v-if="data.rejection_percent !== null"
            :value="`${data.rejection_percent}%`"
            :severity="data.rejection_percent > 5 ? 'danger' : 'success'"
          />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="result" :header="pt('Result')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.result)" :severity="resultSeverity(data.result)" />
        </template>
      </Column>
      <Column field="defects_count" :header="pt('Defects')" />

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            v-if="canScrap && data.rejected_quantity > 0"
            icon="pi pi-trash"
            text
            rounded
            severity="warn"
            :title="pt('Raise scrap')"
            @click="openScrap(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
          <Button
            v-if="canDelete"
            icon="pi pi-times"
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
          {{ pt('No inspections yet. Rejections cannot be measured until lots are judged.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Inspection') : pt('New Inspection')"
      modal
      :style="{ width: '940px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Stage') }} *</label>
          <Select v-model="form.stage" :options="STAGES" option-label="label" option-value="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Inspected on') }} *</label>
          <DatePicker v-model="form.inspected_on" date-format="dd/mm/yy" show-icon />
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
            @change="onOrderPicked"
          />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Product') }} *</label>
          <Select v-model="form.product_id" :options="products" option-label="label" option-value="value" filter />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Inspector') }}</label>
          <Select v-model="form.inspector_id" :options="employees" option-label="label" option-value="value" filter show-clear />
        </div>
      </div>

      <h4 class="section-title">{{ pt('The lot') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Lot quantity') }} *</label>
          <InputNumber v-model="form.lot_quantity" :min="0" :max-fraction-digits="3" />
          <small v-if="errors.lot_quantity" class="err">{{ errors.lot_quantity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sample size') }}</label>
          <InputNumber v-model="form.sample_size" :min="0" :max-fraction-digits="3" />
          <small class="hint">{{ pt('How many were actually checked.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Accepted') }}</label>
          <InputNumber v-model="form.accepted_quantity" :min="0" :max-fraction-digits="3" />
        </div>
        <div class="fld">
          <label>{{ pt('Rejected') }}</label>
          <InputNumber v-model="form.rejected_quantity" :min="0" :max-fraction-digits="3" />
        </div>
        <div class="fld">
          <label>{{ pt('Rework') }}</label>
          <InputNumber v-model="form.rework_quantity" :min="0" :max-fraction-digits="3" />
          <small class="hint">{{ pt('Goes back to the floor — not a loss.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Result') }}</label>
          <Select v-model="form.result" :options="RESULTS" option-label="label" option-value="value" show-clear />
          <small class="hint">{{ pt('Left empty, it follows the quantities.') }}</small>
        </div>
      </div>

      <div class="live" :class="{ bad: overJudged }">
        <span>
          {{ pt('Judged') }}: <strong>{{ judged }}</strong>
          {{ pt('of') }} {{ form.lot_quantity ?? 0 }}
        </span>
        <span v-if="rejectionPercent !== null">
          {{ pt('Rejection') }}: <strong>{{ rejectionPercent }}%</strong>
        </span>
        <span v-if="overJudged" class="err">
          {{ pt('More has been judged than the lot holds.') }}
        </span>
      </div>

      <h4 class="section-title">{{ pt('Defects') }}</h4>
      <table class="lines">
        <thead>
          <tr>
            <th style="width: 38%">{{ pt('Defect') }}</th>
            <th style="width: 16%">{{ pt('Quantity') }}</th>
            <th style="width: 18%">{{ pt('Severity') }}</th>
            <th style="width: 24%">{{ pt('Remarks') }}</th>
            <th style="width: 4%"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(d, i) in form.defects" :key="i">
            <td>
              <InputText v-model="d.defect" class="w-full" :placeholder="pt('e.g. shade variation')" />
            </td>
            <td><InputNumber v-model="d.quantity" :min="0" :max-fraction-digits="3" class="w-full" /></td>
            <td>
              <Select v-model="d.severity" :options="SEVERITIES" option-label="label" option-value="value" class="w-full" />
            </td>
            <td><InputText v-model="d.remarks" class="w-full" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" @click="removeDefect(i)" /></td>
          </tr>
        </tbody>
      </table>
      <div class="lines-footer">
        <Button :label="pt('Add defect')" icon="pi pi-plus" text size="small" @click="addDefect" />
        <small class="hint">
          {{ pt('Defects explain the rejected and reworked pieces — they cannot add up to more.') }}
        </small>
      </div>
      <small v-if="errors.defects" class="err">{{ errors.defects[0] }}</small>
      <small v-if="errors.accepted_quantity" class="err">{{ errors.accepted_quantity[0] }}</small>

      <div class="fld" style="margin-top: 0.8rem">
        <label>{{ pt('Remarks') }}</label>
        <Textarea v-model="form.remarks" rows="2" auto-resize />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Raise scrap -->
    <Dialog
      v-model:visible="scrapVisible"
      :header="pt('Raise Scrap') + ' — ' + (scrapRow?.inspection_number ?? '')"
      modal
      :style="{ width: '600px' }"
    >
      <p class="hint mb">
        {{
          pt(
            'Incoming rejections take material out of stock. Rejections on the line do not — that output was never received.',
          )
        }}
      </p>
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Warehouse') }} *</label>
          <Select v-model="scrapForm.warehouse_id" :options="warehouses" option-label="label" option-value="value" filter />
        </div>
        <div class="fld">
          <label>{{ pt('Quantity') }}</label>
          <InputNumber v-model="scrapForm.quantity" :min="0" :max-fraction-digits="3" />
          <small class="hint">{{ pt('Rejected') }}: {{ scrapRow?.rejected_quantity }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Unit cost') }}</label>
          <InputNumber v-model="scrapForm.unit_cost" :min="0" :max-fraction-digits="4" />
          <small class="hint">{{ pt('Defaults to product cost.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Recoverable value') }}</label>
          <InputNumber v-model="scrapForm.recoverable_value" :min="0" :max-fraction-digits="2" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Remarks') }}</label>
          <InputText v-model="scrapForm.remarks" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="scrapVisible = false" />
        <Button :label="pt('Raise scrap note')" severity="warn" :loading="scrapSaving" @click="saveScrap" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.bad { color: #b91c1c; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.hint { font-size: 0.7rem; color: #6b7280; }
.mb { margin-bottom: 0.7rem; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.section-title { margin: 1.2rem 0 0.5rem; font-size: 0.9rem; }

.pareto { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.6rem 0.9rem 0.9rem; margin-bottom: 1rem; }
.pareto .section-title { margin-top: 0.3rem; }
.pareto-rows { display: flex; flex-direction: column; gap: 0.35rem; }
.p-row { display: grid; grid-template-columns: minmax(140px, 1fr) 2fr auto; gap: 0.7rem; align-items: center; font-size: 0.82rem; }
.p-name { display: inline-flex; align-items: center; gap: 0.35rem; }
.p-bar { background: var(--surface-border, #e5e7eb); border-radius: 999px; height: 0.5rem; overflow: hidden; }
.p-fill { display: block; height: 100%; background: #b91c1c; }
.p-val { font-variant-numeric: tabular-nums; color: #6b7280; }

.live { display: flex; gap: 1.2rem; align-items: center; flex-wrap: wrap; font-size: 0.82rem; margin-top: 0.6rem; padding: 0.5rem 0.7rem; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; }
.live.bad { border-color: #b91c1c; }

.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.72rem; color: #6b7280; font-weight: 500; padding: 0.3rem 0.35rem; }
.lines td { padding: 0.2rem 0.35rem; vertical-align: middle; }
.lines-footer { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 0.4rem; }
</style>
