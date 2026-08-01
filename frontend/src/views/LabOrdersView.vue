<script setup lang="ts">
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
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

/**
 * The lab's worklist and its one document.
 *
 * The same screen is the registration desk, the collection queue, the results
 * bench and the reports counter — because in a small lab they are the same
 * three people, and the status filter is what changes between them. The row
 * therefore leads with progress (how many parameters are still empty) rather
 * than with money.
 */

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/lab-orders')

const canCreate = computed(() => auth.can('lab-orders.create'))
const canEdit = computed(() => auth.can('lab-orders.edit'))
const canDelete = computed(() => auth.can('lab-orders.delete'))
// Verifying a report and billing it are the same authority — see routes.php.
const canApprove = computed(() => auth.can('lab-orders.approve'))

const search = ref('')
const statusFilter = ref<string | null>(null)
const unbilledOnly = ref(false)
const summary = ref<any>({})

const customers = ref<any[]>([])
const tests = ref<any[]>([])

const STATUSES = computed(() => [
  { label: pt('Awaiting collection'), value: 'registered' },
  { label: pt('Collected'), value: 'collected' },
  { label: pt('In process'), value: 'processing' },
  { label: pt('Reported'), value: 'reported' },
  { label: pt('Delivered'), value: 'delivered' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

const GENDERS = [
  { label: 'Male', value: 'male' },
  { label: 'Female', value: 'female' },
  { label: 'Other', value: 'other' },
]

const STATUS_SEVERITY: Record<string, string> = {
  registered: 'secondary',
  collected: 'info',
  processing: 'warn',
  reported: 'success',
  delivered: 'success',
  cancelled: 'danger',
}

const FLAG_SEVERITY: Record<string, string> = {
  pending: 'secondary',
  normal: 'success',
  low: 'info',
  high: 'warn',
  abnormal: 'danger',
}

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  const { data } = await http.get('/lab-orders', {
    params: {
      per_page: 50,
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      unbilled: unbilledOnly.value ? 1 : undefined,
    },
  })
  crud.items.value = data.data ?? data
  summary.value = data.summary ?? {}
}

async function loadLookups() {
  const [c, t] = await Promise.all([
    http.get('/customers', { params: { per_page: 200, is_active: true } }),
    http.get('/lab-tests', { params: { per_page: 200, is_active: true } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  tests.value = (t.data.data ?? t.data).map((x: any) => ({
    label: `${x.code} — ${x.name}`,
    value: x.id,
    price: x.price,
    is_panel: x.is_panel,
  }))
}

// ---- Register / amend ---------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  patient_name: '',
  patient_age: null as number | null,
  patient_gender: null as string | null,
  patient_phone: '',
  referring_doctor: '',
  doctor_registration_no: '',
  order_date: new Date() as Date | null,
  priority: 'routine',
  discount_type: null as string | null,
  discount_value: 0,
  notes: '',
  items: [] as { lab_test_id: number | null; unit_price: number }[],
})
const form = ref(blank())

const formTotal = computed(() =>
  form.value.items.reduce((sum, l) => sum + (Number(l.unit_price) || 0), 0),
)

function addLine() {
  form.value.items.push({ lab_test_id: null, unit_price: 0 })
}

function removeLine(index: number) {
  form.value.items.splice(index, 1)
}

/** Default the price from the master the moment a test is picked. */
function onTestPicked(line: { lab_test_id: number | null; unit_price: number }) {
  const test = tests.value.find((t) => t.value === line.lab_test_id)
  if (test) line.unit_price = Number(test.price) || 0
}

function openCreate() {
  editing.value = null
  form.value = blank()
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/lab-orders/${row.id}`)
  const o = data.data
  editing.value = o
  errors.value = {}
  form.value = {
    customer_id: o.customer_id,
    patient_name: o.patient_name,
    patient_age: o.patient_age,
    patient_gender: o.patient_gender,
    patient_phone: o.patient_phone ?? '',
    referring_doctor: o.referring_doctor ?? '',
    doctor_registration_no: o.doctor_registration_no ?? '',
    order_date: o.order_date ? new Date(o.order_date) : null,
    priority: o.priority,
    discount_type: o.discount_type,
    discount_value: o.discount_value,
    notes: o.notes ?? '',
    items: o.items.map((i: any) => ({ lab_test_id: i.lab_test_id, unit_price: i.unit_price })),
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      order_date: iso(form.value.order_date),
      items: form.value.items.filter((l) => l.lab_test_id),
    }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Lab order saved'), life: 2500 })
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

// ---- Workflow actions -----------------------------------------------------------

const busy = ref<number | null>(null)

async function act(row: any, action: string, body: Record<string, any> = {}, confirmText?: string) {
  if (confirmText) {
    const ok = await Swal.fire({
      title: confirmText,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: pt('Yes'),
      cancelButtonText: pt('Cancel'),
    })
    if (!ok.isConfirmed) return
  }

  busy.value = row.id
  try {
    const { data } = await http.post(`/lab-orders/${row.id}/${action}`, body)
    toast.add({ severity: 'success', summary: data.message ?? pt('Done'), life: 3000 })
    await load()
    if (detail.value?.id === row.id && data.data) detail.value = data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4500 })
  } finally {
    busy.value = null
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
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Lab order deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

// ---- Result entry ----------------------------------------------------------------

const detailVisible = ref(false)
const detail = ref<any>(null)
const savingResults = ref(false)

async function openDetail(row: any) {
  const { data } = await http.get(`/lab-orders/${row.id}`)
  detail.value = data.data
  detailVisible.value = true
}

async function saveResults() {
  if (!detail.value) return
  savingResults.value = true
  try {
    const { data } = await http.post(`/lab-orders/${detail.value.id}/results`, {
      results: detail.value.results.map((r: any) => ({
        id: r.id,
        result_value: r.result_value ?? '',
        remarks: r.remarks ?? '',
      })),
    })
    detail.value = data.data
    toast.add({ severity: 'success', summary: data.message, life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save results'), life: 4000 })
  } finally {
    savingResults.value = false
  }
}

// ---- Report ------------------------------------------------------------------------

const reportVisible = ref(false)
const report = ref<any>(null)

async function openReport(row: any) {
  try {
    const { data } = await http.get(`/lab-orders/${row.id}/report`)
    report.value = data.data
    reportVisible.value = true
  } catch (e: any) {
    toast.add({ severity: 'warn', summary: e.response?.data?.message ?? pt('No report yet'), life: 4000 })
  }
}

function esc(s: unknown): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

/**
 * Print the report through a hidden iframe rather than window.print(), which
 * would print the whole SPA around it. Same mechanism the shared table export
 * uses; the layout differs enough (sections, abnormal marking, a signature
 * line) that it is built here rather than squeezed into a generic table.
 */
function printReport() {
  const r = report.value
  if (!r) return

  const sections = (r.sections ?? [])
    .map(
      (s: any) => `
        <h2>${esc(s.test_name)}${s.category ? ` <span class="muted">· ${esc(s.category)}</span>` : ''}</h2>
        <table>
          <thead><tr><th>Parameter</th><th>Result</th><th>Unit</th><th>Reference</th></tr></thead>
          <tbody>
            ${(s.parameters ?? [])
              .map(
                (p: any) => `<tr>
                  <td>${esc(p.parameter)}</td>
                  <td class="${p.is_abnormal ? 'abn' : ''}">${esc(p.result_value ?? '—')}</td>
                  <td>${esc(p.unit ?? '')}</td>
                  <td>${esc(p.reference_range ?? '')}</td>
                </tr>`,
              )
              .join('')}
          </tbody>
        </table>
        ${s.method ? `<div class="muted small">Method: ${esc(s.method)}</div>` : ''}`,
    )
    .join('')

  const patient = [r.patient.age, r.patient.gender, r.patient.phone].filter(Boolean).join(' · ')

  const doc = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(r.order_number)}</title>
    <style>
      * { font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
      .head { display: flex; justify-content: space-between; border-bottom: 1px solid #999; padding-bottom: 8px; }
      .right { text-align: right; }
      h2 { font-size: 14px; margin: 16px 0 4px; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
      th { background: #f2f2f2; }
      .muted { color: #666; }
      .small { font-size: 11px; }
      .abn { font-weight: 700; }
      .sign { margin-top: 40px; text-align: right; }
    </style></head>
    <body>
      <div class="head">
        <div><strong>${esc(r.patient.name)}</strong><div class="muted small">${esc(patient)}</div></div>
        <div class="right">
          <strong>${esc(r.order_number)}</strong>
          <div class="muted small">Reported: ${esc(r.reported_at ?? '')}</div>
          ${r.referring_doctor ? `<div class="muted small">Ref: ${esc(r.referring_doctor)}</div>` : ''}
        </div>
      </div>
      ${sections}
      <div class="sign"><div class="muted small">Verified by</div><strong>${esc(r.verified_by ?? '—')}</strong></div>
    </body></html>`

  const iframe = document.createElement('iframe')
  iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  document.body.appendChild(iframe)
  const win = iframe.contentWindow
  if (!win) {
    iframe.remove()
    return
  }
  win.document.open()
  win.document.write(doc)
  win.document.close()
  setTimeout(() => {
    win.focus()
    win.print()
    setTimeout(() => iframe.remove(), 1000)
  }, 250)
}

onMounted(async () => {
  await Promise.all([loadLookups(), load()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Lab Orders')"
      :subtitle="pt('Registration, sample collection, results and verified reports')"
    />

    <div class="cards">
      <div class="card">
        <span class="k">{{ pt('Awaiting collection') }}</span>
        <strong>{{ summary.awaiting_collection ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('In process') }}</span>
        <strong>{{ summary.in_process ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Reported') }}</span>
        <strong>{{ summary.reported ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Unbilled value') }}</span>
        <strong>{{ summary.unbilled_value ?? 0 }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Order no, patient, phone or doctor')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="w-14"
        @change="load"
      />
      <label class="inline-check">
        <input v-model="unbilledOnly" type="checkbox" @change="load" />
        {{ pt('Unbilled only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('Register Order')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Lab Orders"
        filenameBase="lab-orders"
        :rows="crud.items.value"
        :columns="[
          { key: 'order_number', label: 'Order No' },
          { key: 'order_date', label: 'Date' },
          { key: 'patient_name', label: 'Patient' },
          { key: 'referring_doctor', label: 'Referred by' },
          { key: 'tests', label: 'Tests' },
          { key: 'status', label: 'Status' },
          { key: 'total', label: 'Total' },
          { key: 'invoice_number', label: 'Invoice' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Order')">
        <template #body="{ data }">
          <strong>{{ data.order_number }}</strong>
          <Tag v-if="data.priority === 'urgent'" :value="pt('Urgent')" severity="danger" class="ml-1" />
          <br /><small class="muted">{{ data.order_date }}</small>
        </template>
      </Column>
      <Column :header="pt('Patient')">
        <template #body="{ data }">
          {{ data.patient_name }}
          <br />
          <small class="muted">
            {{ [data.patient_age, data.patient_gender, data.patient_phone].filter(Boolean).join(' · ') || '—' }}
          </small>
        </template>
      </Column>
      <Column :header="pt('Tests')">
        <template #body="{ data }">
          <span class="tests">{{ data.tests || '—' }}</span>
          <br />
          <small v-if="data.referring_doctor" class="muted">{{ pt('Ref') }}: {{ data.referring_doctor }}</small>
        </template>
      </Column>
      <Column :header="pt('Progress')">
        <template #body="{ data }">
          <span v-if="data.results_count">
            {{ data.results_count - (data.pending_results_count ?? 0) }} / {{ data.results_count }}
          </span>
          <span v-else class="muted">—</span>
          <br />
          <Tag
            v-if="data.abnormal_results_count"
            :value="`${data.abnormal_results_count} ${pt('abnormal')}`"
            severity="warn"
          />
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="STATUS_SEVERITY[data.status] ?? 'secondary'" />
        </template>
      </Column>
      <Column :header="pt('Billing')">
        <template #body="{ data }">
          <span>{{ data.total }}</span>
          <br />
          <small v-if="data.invoice_number" class="muted">{{ data.invoice_number }}</small>
          <Tag v-else :value="pt('Unbilled')" severity="secondary" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button
            icon="pi pi-list"
            text
            size="small"
            :title="pt('Results')"
            :loading="busy === data.id"
            @click="openDetail(data)"
          />
          <Button
            v-if="canEdit && data.status === 'registered'"
            icon="pi pi-inbox"
            text
            size="small"
            :title="pt('Mark samples collected')"
            @click="act(data, 'collect', {}, pt('Mark every pending sample as collected?'))"
          />
          <Button
            v-if="canApprove && ['collected', 'processing'].includes(data.status)"
            icon="pi pi-verified"
            text
            size="small"
            severity="success"
            :title="pt('Verify & release report')"
            @click="act(data, 'verify')"
          />
          <Button
            v-if="['reported', 'delivered'].includes(data.status)"
            icon="pi pi-file"
            text
            size="small"
            :title="pt('Report')"
            @click="openReport(data)"
          />
          <Button
            v-if="canApprove && !data.is_billed && data.status !== 'cancelled'"
            icon="pi pi-indian-rupee"
            text
            size="small"
            :title="pt('Raise invoice')"
            @click="act(data, 'bill', {}, pt('Raise the invoice for this order?'))"
          />
          <Button
            v-if="canEdit && data.status === 'reported'"
            icon="pi pi-send"
            text
            size="small"
            :title="pt('Hand over')"
            @click="act(data, 'deliver')"
          />
          <Button
            v-if="canEdit && data.is_editable"
            icon="pi pi-pencil"
            text
            size="small"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && !data.is_billed"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No lab orders match this view.') }}</template>
    </DataTable>

    <!-- Register / amend -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Lab Order') : pt('Register Lab Order')"
      :style="{ width: '52rem' }"
    >
      <h4 class="sec">{{ pt('Patient') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Patient name') }} *</label>
          <InputText v-model="form.patient_name" class="w-full" />
          <small v-if="errors.patient_name" class="err">{{ errors.patient_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Age') }}</label>
          <InputNumber v-model="form.patient_age" :min="0" :max="130" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Gender') }}</label>
          <Select
            v-model="form.patient_gender"
            :options="GENDERS"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.patient_phone" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Bill to (account)') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Walk-in patient')"
            class="w-full"
          />
          <small class="hint">{{ pt('Leave blank for a walk-in — the bill goes to the walk-in account.') }}</small>
        </div>
      </div>

      <h4 class="sec">{{ pt('Referral') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Referring doctor') }}</label>
          <InputText v-model="form.referring_doctor" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Registration no') }}</label>
          <InputText v-model="form.doctor_registration_no" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Order date') }} *</label>
          <DatePicker v-model="form.order_date" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select
            v-model="form.priority"
            :options="[
              { label: pt('Routine'), value: 'routine' },
              { label: pt('Urgent'), value: 'urgent' },
            ]"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
        </div>
      </div>

      <h4 class="sec">{{ pt('Tests') }}</h4>
      <small v-if="errors.items" class="err">{{ errors.items[0] }}</small>
      <table class="lines">
        <thead>
          <tr>
            <th>{{ pt('Test') }}</th>
            <th style="width: 9rem">{{ pt('Price') }}</th>
            <th style="width: 3rem"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select
                v-model="line.lab_test_id"
                :options="tests"
                optionLabel="label"
                optionValue="value"
                filter
                class="w-full"
                :placeholder="pt('Pick a test or panel')"
                @change="onTestPicked(line)"
              />
            </td>
            <td><InputNumber v-model="line.unit_price" :minFractionDigits="2" class="w-full" /></td>
            <td>
              <Button icon="pi pi-times" text size="small" severity="danger" @click="removeLine(i)" />
            </td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add test')" icon="pi pi-plus" text size="small" @click="addLine" />

      <div class="grid mt">
        <div class="fld">
          <label>{{ pt('Discount type') }}</label>
          <Select
            v-model="form.discount_type"
            :options="[
              { label: pt('Percent'), value: 'percent' },
              { label: pt('Flat'), value: 'flat' },
            ]"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Discount value') }}</label>
          <InputNumber v-model="form.discount_value" :minFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Gross') }}</label>
          <div class="total">{{ formTotal.toFixed(2) }}</div>
          <small class="hint">{{ pt('Tax and discount are applied on save.') }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Result entry -->
    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? `${detail.order_number} — ${detail.patient_name}` : pt('Results')"
      :style="{ width: '56rem' }"
    >
      <template v-if="detail">
        <div class="meta">
          <span><strong>{{ pt('Status') }}:</strong> {{ detail.status }}</span>
          <span v-if="detail.referring_doctor"><strong>{{ pt('Ref') }}:</strong> {{ detail.referring_doctor }}</span>
          <span v-if="detail.verified_by"><strong>{{ pt('Verified by') }}:</strong> {{ detail.verified_by }}</span>
          <span v-if="detail.invoice_number"><strong>{{ pt('Invoice') }}:</strong> {{ detail.invoice_number }}</span>
        </div>

        <table class="lines">
          <thead>
            <tr>
              <th>{{ pt('Parameter') }}</th>
              <th style="width: 10rem">{{ pt('Result') }}</th>
              <th style="width: 6rem">{{ pt('Unit') }}</th>
              <th style="width: 9rem">{{ pt('Reference') }}</th>
              <th style="width: 6rem">{{ pt('Flag') }}</th>
              <th style="width: 12rem">{{ pt('Remarks') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in detail.results" :key="r.id">
              <td>{{ r.parameter }}</td>
              <td>
                <InputText
                  v-model="r.result_value"
                  class="w-full"
                  :disabled="!canEdit || detail.status === 'cancelled'"
                />
              </td>
              <td class="muted">{{ r.unit || '—' }}</td>
              <td class="muted">{{ r.reference_range || '—' }}</td>
              <td><Tag :value="r.flag" :severity="FLAG_SEVERITY[r.flag] ?? 'secondary'" /></td>
              <td>
                <InputText
                  v-model="r.remarks"
                  class="w-full"
                  :disabled="!canEdit || detail.status === 'cancelled'"
                />
              </td>
            </tr>
          </tbody>
        </table>
        <small class="hint">
          {{ pt('High / low is judged against the range this order was registered with, not the current master.') }}
        </small>
      </template>

      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
        <Button
          v-if="canEdit && detail && detail.status !== 'cancelled'"
          :label="pt('Save results')"
          icon="pi pi-check"
          :loading="savingResults"
          @click="saveResults"
        />
        <Button
          v-if="canApprove && detail && ['collected', 'processing'].includes(detail.status)"
          :label="pt('Verify & release')"
          icon="pi pi-verified"
          severity="success"
          @click="act(detail, 'verify')"
        />
      </template>
    </Dialog>

    <!-- Printable report -->
    <Dialog
      v-model:visible="reportVisible"
      modal
      :header="pt('Lab Report')"
      :style="{ width: '48rem' }"
    >
      <div v-if="report" id="lab-report" class="report">
        <div class="rep-head">
          <div>
            <strong>{{ report.patient.name }}</strong>
            <div class="muted">
              {{ [report.patient.age, report.patient.gender, report.patient.phone].filter(Boolean).join(' · ') }}
            </div>
          </div>
          <div class="right">
            <div><strong>{{ report.order_number }}</strong></div>
            <div class="muted">{{ pt('Reported') }}: {{ report.reported_at }}</div>
            <div v-if="report.referring_doctor" class="muted">{{ pt('Ref') }}: {{ report.referring_doctor }}</div>
          </div>
        </div>

        <div v-for="(section, i) in report.sections" :key="i" class="section">
          <h4>{{ section.test_name }}<span v-if="section.category" class="muted"> · {{ section.category }}</span></h4>
          <table class="lines">
            <thead>
              <tr>
                <th>{{ pt('Parameter') }}</th>
                <th>{{ pt('Result') }}</th>
                <th>{{ pt('Unit') }}</th>
                <th>{{ pt('Reference') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in section.parameters" :key="p.id">
                <td>{{ p.parameter }}</td>
                <td :class="{ abnormal: p.is_abnormal }">{{ p.result_value ?? '—' }}</td>
                <td class="muted">{{ p.unit || '' }}</td>
                <td class="muted">{{ p.reference_range || '' }}</td>
              </tr>
            </tbody>
          </table>
          <small v-if="section.method" class="muted">{{ pt('Method') }}: {{ section.method }}</small>
        </div>

        <div class="sign">
          <span class="muted">{{ pt('Verified by') }}</span>
          <strong>{{ report.verified_by || '—' }}</strong>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="reportVisible = false" />
        <Button :label="pt('Print')" icon="pi pi-print" @click="printReport" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.cards { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem 1rem; min-width: 9rem; }
.card .k { display: block; font-size: 0.72rem; color: #6b7280; }
.card strong { font-size: 1.15rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.35rem; }
.tests { font-size: 0.85rem; }
.sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.sec:first-child { margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.grid.mt { margin-top: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.w-14 { width: 12rem; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.total { font-weight: 600; padding: 0.4rem 0; }
.lines { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.lines th { text-align: left; font-weight: 600; padding: 0.35rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.3rem 0.35rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.meta { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.82rem; margin-bottom: 0.75rem; }
.report .rep-head { display: flex; justify-content: space-between; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; }
.report .right { text-align: right; }
.report .section { margin-top: 1rem; }
.report .section h4 { margin: 0 0 0.35rem; font-size: 0.9rem; }
.report .abnormal { font-weight: 700; color: #b91c1c; }
.report .sign { margin-top: 1.5rem; display: flex; flex-direction: column; align-items: flex-end; }
</style>
