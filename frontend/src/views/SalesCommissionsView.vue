<script setup lang="ts">
/**
 * Shop-staff sales commission (retail base §8b).
 *
 * Two tabs because there are two jobs here and they belong to different people:
 * the **Payout** tab is what a floor manager and payroll work from once a month,
 * and **Rules** is what an owner sets once a season. Payout leads, because it is
 * opened twelve times as often.
 *
 * The payout tab is deliberately a per-employee sheet first and a line-by-line
 * register second. Commission is settled per person; the lines only matter when
 * somebody disputes a figure, and then they matter a great deal.
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
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
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

const canCreate = computed(() => auth.can('sales-commissions.create'))
const canEdit = computed(() => auth.can('sales-commissions.edit'))
const canApprove = computed(() => auth.can('sales-commissions.approve'))
const canDelete = computed(() => auth.can('sales-commissions.delete'))

const tab = ref<'payout' | 'rules'>('payout')

const BASES = [
  { label: '% of sale value', value: 'percent_of_sale' },
  { label: '% of margin', value: 'percent_of_margin' },
  { label: 'Flat amount per unit', value: 'flat_per_unit' },
]

const APPLIES_TO = [
  { label: 'All staff', value: 'all' },
  { label: 'One employee', value: 'employee' },
  { label: 'A department', value: 'department' },
]

const STATUSES = [
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Paid', value: 'paid' },
  { label: 'Reversed', value: 'reversed' },
]

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
].map((label, i) => ({ label, value: i + 1 }))

const years = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map((y) => ({ label: String(y), value: y }))
})

function labelOf(list: { label: string; value: any }[], value: any) {
  return list.find((o) => o.value === value)?.label ?? value ?? '—'
}

function money(v: any) {
  return '₹' + Number(v ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })
}

function statusSeverity(status: string) {
  switch (status) {
    case 'paid':
      return 'success'
    case 'approved':
      return 'info'
    case 'reversed':
      return 'danger'
    default:
      return 'warn'
  }
}

/** A rate reads differently depending on the basis — 2% vs ₹50 a unit. */
function rateText(row: any) {
  return row.basis === 'flat_per_unit'
    ? money(row.rate) + ' ' + pt('per unit')
    : Number(row.rate) + '%'
}

// ---- Pickers ---------------------------------------------------------------

const employees = ref<any[]>([])
const departments = ref<any[]>([])
const categories = ref<any[]>([])
const brands = ref<any[]>([])
const products = ref<any[]>([])
const pickersLoaded = ref(false)

async function loadPickers() {
  if (pickersLoaded.value) return
  try {
    const [e, d, c, b, p] = await Promise.all([
      http.get('/employees', { params: { per_page: 300 } }),
      http.get('/departments', { params: { per_page: 100 } }),
      http.get('/categories', { params: { per_page: 300 } }),
      http.get('/brands', { params: { per_page: 300 } }),
      http.get('/products', { params: { per_page: 300 } }),
    ])
    employees.value = (e.data.data ?? []).map((x: any) => ({
      ...x,
      label: [x.employee_code, [x.first_name, x.last_name].filter(Boolean).join(' ')].filter(Boolean).join(' — '),
    }))
    departments.value = d.data.data ?? []
    categories.value = c.data.data ?? []
    brands.value = b.data.data ?? []
    products.value = p.data.data ?? []
    pickersLoaded.value = true
  } catch {
    // Pickers are a convenience; the screen still works without them.
  }
}

// ---- Payout ---------------------------------------------------------------

const year = ref(new Date().getFullYear())
const month = ref(new Date().getMonth() + 1)
const employeeFilter = ref<number | null>(null)
const statusFilter = ref<string | null>(null)

const summary = ref<any>({ earnings: 0, total: 0, pending: 0, approved: 0, paid: 0, sales_value: 0 })
const sheet = ref<any[]>([])
const lines = ref<any[]>([])
const selected = ref<any[]>([])
const loading = ref(false)
const generating = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(50)

async function loadEarnings() {
  loading.value = true
  try {
    const { data } = await http.get('/sales-commissions', {
      params: {
        year: year.value,
        month: month.value,
        employee_id: employeeFilter.value || undefined,
        status: statusFilter.value || undefined,
        page: page.value,
        per_page: perPage.value,
      },
    })
    lines.value = data.data ?? []
    sheet.value = data.by_employee ?? []
    summary.value = data.summary ?? summary.value
    total.value = data.meta?.total ?? lines.value.length
    selected.value = []
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  loadEarnings()
}

function applyPeriod() {
  page.value = 1
  loadEarnings()
}

async function generate() {
  const ok = await Swal.fire({
    title: pt('Work out commission for {m} {y}?', { m: labelOf(MONTHS, month.value), y: year.value }),
    text: pt('Safe to run again: figures already paid are left alone, and everything still open is refreshed against the current rules.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Work it out'),
  })
  if (!ok.isConfirmed) return

  generating.value = true
  try {
    const { data } = await http.post('/sales-commissions/generate', { year: year.value, month: month.value })
    toast.add({ severity: 'success', summary: data.message, life: 6000 })
    await loadEarnings()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not work out commission'), life: 5000 })
  } finally {
    generating.value = false
  }
}

const selectableIds = computed(() => selected.value.map((r) => r.id))

async function approveSelected() {
  if (!selectableIds.value.length) return
  try {
    const { data } = await http.post('/sales-commissions/approve', { ids: selectableIds.value })
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    await loadEarnings()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not approve'), life: 4000 })
  }
}

const payDialog = ref(false)
const payForm = ref({ paid_on: new Date().toISOString().slice(0, 10), payment_reference: '' })

async function markPaid() {
  if (!selectableIds.value.length) return
  try {
    const { data } = await http.post('/sales-commissions/mark-paid', {
      ids: selectableIds.value,
      paid_on: payForm.value.paid_on,
      payment_reference: payForm.value.payment_reference || undefined,
    })
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    if (data.skipped) {
      // Approval is a prerequisite — say so rather than letting a run appear to
      // have settled rows it never touched.
      toast.add({
        severity: 'info',
        summary: pt('{n} skipped — approve them first', { n: data.skipped }),
        life: 5000,
      })
    }
    payDialog.value = false
    await loadEarnings()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not settle'), life: 4000 })
  }
}

async function reverse(row: any) {
  const ok = await Swal.fire({
    title: pt('Reverse this earning?'),
    text: pt('It stays on the register marked reversed and stops counting. This is the only way to change a figure already paid.'),
    icon: 'warning',
    input: 'text',
    inputPlaceholder: pt('Reason'),
    showCancelButton: true,
    confirmButtonText: pt('Reverse'),
  })
  if (!ok.isConfirmed) return
  try {
    const { data } = await http.post(`/sales-commissions/${row.id}/reverse`, { reason: ok.value || undefined })
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    await loadEarnings()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not reverse'), life: 4000 })
  }
}

// ---- Correcting one figure -------------------------------------------------

const editDialog = ref(false)
const editSaving = ref(false)
const editRow = ref<any>(null)
const editForm = ref({ commission_amount: 0, employee_id: null as number | null, notes: '' })

async function openEditEarning(row: any) {
  editRow.value = row
  editForm.value = {
    commission_amount: Number(row.commission_amount),
    employee_id: row.employee_id,
    notes: row.notes ?? '',
  }
  editDialog.value = true
  await loadPickers()
}

async function saveEarning() {
  if (!editRow.value) return
  editSaving.value = true
  try {
    await http.put(`/sales-commissions/${editRow.value.id}`, editForm.value)
    toast.add({ severity: 'success', summary: pt('Earning corrected'), life: 2500 })
    editDialog.value = false
    await loadEarnings()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save'), life: 4000 })
  } finally {
    editSaving.value = false
  }
}

// ---- Rules ----------------------------------------------------------------

const rules = ref<any[]>([])
const rulesLoading = ref(false)

async function loadRules() {
  rulesLoading.value = true
  try {
    const { data } = await http.get('/commission-rules')
    rules.value = data.data ?? []
  } finally {
    rulesLoading.value = false
  }
}

const ruleDialog = ref(false)
const ruleSaving = ref(false)
const ruleEditingId = ref<number | null>(null)
const ruleErrors = ref<Record<string, string[]>>({})

const blankRule = () => ({
  name: '',
  applies_to: 'all',
  employee_id: null as number | null,
  department_id: null as number | null,
  category_id: null as number | null,
  brand_id: null as number | null,
  product_id: null as number | null,
  basis: 'percent_of_sale',
  rate: 1,
  min_line_amount: 0,
  min_invoice_amount: 0,
  priority: 100,
  effective_from: '',
  effective_to: '',
  is_active: true,
  notes: '',
})
const ruleForm = ref(blankRule())

async function openRuleNew() {
  ruleEditingId.value = null
  ruleErrors.value = {}
  ruleForm.value = blankRule()
  ruleDialog.value = true
  await loadPickers()
}

async function openRuleEdit(row: any) {
  ruleEditingId.value = row.id
  ruleErrors.value = {}
  ruleForm.value = {
    name: row.name,
    applies_to: row.applies_to,
    employee_id: row.employee_id,
    department_id: row.department_id,
    category_id: row.category_id,
    brand_id: row.brand_id,
    product_id: row.product_id,
    basis: row.basis,
    rate: Number(row.rate),
    min_line_amount: Number(row.min_line_amount),
    min_invoice_amount: Number(row.min_invoice_amount),
    priority: row.priority,
    effective_from: row.effective_from ?? '',
    effective_to: row.effective_to ?? '',
    is_active: row.is_active,
    notes: row.notes ?? '',
  }
  ruleDialog.value = true
  await loadPickers()
}

async function saveRule() {
  ruleSaving.value = true
  ruleErrors.value = {}
  const payload = {
    ...ruleForm.value,
    effective_from: ruleForm.value.effective_from || null,
    effective_to: ruleForm.value.effective_to || null,
  }
  try {
    if (ruleEditingId.value) {
      await http.put(`/commission-rules/${ruleEditingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Rule updated'), life: 2500 })
    } else {
      await http.post('/commission-rules', payload)
      toast.add({ severity: 'success', summary: pt('Rule added'), life: 2500 })
    }
    ruleDialog.value = false
    await loadRules()
  } catch (e: any) {
    ruleErrors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the rule'), life: 4000 })
  } finally {
    ruleSaving.value = false
  }
}

async function removeRule(row: any) {
  const ok = await Swal.fire({
    title: pt('Remove "{name}"?', { name: row.name }),
    text: row.earnings_count
      ? pt('Commission has been earned under this rule, so it will be switched off rather than deleted.')
      : pt('Nothing has been earned under it yet, so it will be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
  })
  if (!ok.isConfirmed) return
  try {
    const { data } = await http.delete(`/commission-rules/${row.id}`)
    toast.add({ severity: 'success', summary: data.message, life: 4000 })
    await loadRules()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not remove the rule'), life: 4000 })
  }
}

function switchTab(next: 'payout' | 'rules') {
  tab.value = next
  if (next === 'rules' && !rules.value.length) loadRules()
}

onMounted(loadEarnings)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Sales Commission')"
      :subtitle="pt('What your counter staff earn on what they sell')"
    >
      <template #actions>
        <Button
          v-if="tab === 'payout' && canCreate"
          :label="pt('Work Out Commission')"
          icon="pi pi-calculator"
          :loading="generating"
          @click="generate"
        />
        <Button v-if="tab === 'rules' && canCreate" :label="pt('New Rule')" icon="pi pi-plus" @click="openRuleNew" />
      </template>
    </PageHeader>

    <div class="tabs">
      <button :class="{ on: tab === 'payout' }" @click="switchTab('payout')">
        <i class="pi pi-wallet" /> {{ pt('Payout') }}
      </button>
      <button :class="{ on: tab === 'rules' }" @click="switchTab('rules')">
        <i class="pi pi-percentage" /> {{ pt('Rules') }}
      </button>
    </div>

    <!-- ================= Payout ================= -->
    <template v-if="tab === 'payout'">
      <div class="period-bar">
        <Select v-model="month" :options="MONTHS" optionLabel="label" optionValue="value" class="p-sel" @change="applyPeriod" />
        <Select v-model="year" :options="years" optionLabel="label" optionValue="value" class="p-sel narrow" @change="applyPeriod" />
        <Select
          v-model="employeeFilter"
          :options="employees"
          optionLabel="label"
          optionValue="id"
          filter
          showClear
          :placeholder="pt('All staff')"
          class="p-sel"
          @change="applyPeriod"
          @before-show="loadPickers"
        />
        <Select v-model="statusFilter" :options="STATUSES" optionLabel="label" optionValue="value" showClear :placeholder="pt('Any status')" class="p-sel narrow" @change="applyPeriod" />
      </div>

      <div class="stat-row">
        <div class="stat big"><span>{{ pt('Commission') }}</span><strong>{{ money(summary.total) }}</strong></div>
        <div class="stat warn"><span>{{ pt('Awaiting approval') }}</span><strong>{{ money(summary.pending) }}</strong></div>
        <div class="stat"><span>{{ pt('Approved, unpaid') }}</span><strong>{{ money(summary.approved) }}</strong></div>
        <div class="stat"><span>{{ pt('Paid') }}</span><strong>{{ money(summary.paid) }}</strong></div>
        <div class="stat"><span>{{ pt('On sales of') }}</span><strong>{{ money(summary.sales_value) }}</strong></div>
      </div>

      <!-- The sheet commission is actually settled from. -->
      <h3 class="sec">{{ pt('By employee') }}</h3>
      <DataTable :value="sheet" :loading="loading" dataKey="employee_id" class="p-datatable-sm" responsiveLayout="scroll">
        <Column field="employee" :header="pt('Employee')">
          <template #body="{ data }">
            <strong>{{ data.employee }}</strong>
            <small v-if="data.employee_code" class="muted"> · {{ data.employee_code }}</small>
          </template>
        </Column>
        <Column :header="pt('Lines')" style="width: 6rem">
          <template #body="{ data }">{{ data.lines_count }}</template>
        </Column>
        <Column :header="pt('Sales')">
          <template #body="{ data }">{{ money(data.sales_value) }}</template>
        </Column>
        <Column :header="pt('Pending')">
          <template #body="{ data }">{{ money(data.pending) }}</template>
        </Column>
        <Column :header="pt('Approved')">
          <template #body="{ data }">{{ money(data.approved) }}</template>
        </Column>
        <Column :header="pt('Paid')">
          <template #body="{ data }">{{ money(data.paid) }}</template>
        </Column>
        <Column :header="pt('Commission')">
          <template #body="{ data }"><strong>{{ money(data.commission) }}</strong></template>
        </Column>
        <Column style="width: 6rem">
          <template #body="{ data }">
            <Button
              icon="pi pi-list"
              text
              rounded
              size="small"
              v-tooltip.top="pt('Show their lines')"
              @click="employeeFilter = data.employee_id; applyPeriod()"
            />
          </template>
        </Column>
        <template #empty>
          {{ pt('Nothing worked out for this month yet. Set a rule, then press “Work Out Commission”.') }}
        </template>
      </DataTable>

      <!-- The lines behind it — read when a figure is disputed. -->
      <div class="lines-head">
        <h3 class="sec">{{ pt('Line by line') }}</h3>
        <div class="line-actions">
          <Button
            v-if="canApprove"
            :label="pt('Approve {n}', { n: selected.length })"
            icon="pi pi-check"
            size="small"
            outlined
            :disabled="!selected.length"
            @click="approveSelected"
          />
          <Button
            v-if="canApprove"
            :label="pt('Mark Paid')"
            icon="pi pi-indian-rupee"
            size="small"
            :disabled="!selected.length"
            @click="payDialog = true"
          />
          <TableExportButtons
            title="Sales Commission"
            filenameBase="sales-commission"
            :rows="lines"
            :columns="[
              { key: 'earned_on', label: 'Date' },
              { key: 'employee', label: 'Employee' },
              { key: 'so_number', label: 'Order' },
              { key: 'invoice_number', label: 'Bill' },
              { key: 'product', label: 'Item' },
              { key: 'quantity', label: 'Qty' },
              { key: 'sale_amount', label: 'Sale Value' },
              { key: 'margin_amount', label: 'Margin' },
              { key: 'rate', label: 'Rate' },
              { key: 'commission_amount', label: 'Commission' },
              { key: 'status', label: 'Status' },
            ]"
          />
        </div>
      </div>

      <DataTable
        v-model:selection="selected"
        :value="lines"
        :loading="loading"
        lazy
        paginator
        :rows="perPage"
        :totalRecords="total"
        :rowsPerPageOptions="[50, 100, 200]"
        dataKey="id"
        class="p-datatable-sm"
        responsiveLayout="scroll"
        @page="onPage"
      >
        <Column selectionMode="multiple" style="width: 3rem" />
        <Column field="earned_on" :header="pt('Date')" style="width: 7rem" />
        <Column field="employee" :header="pt('Employee')" />
        <Column :header="pt('Bill')">
          <template #body="{ data }">
            <div>{{ data.invoice_number || data.so_number }}</div>
            <small class="muted">{{ data.product }}</small>
          </template>
        </Column>
        <Column :header="pt('Qty')" style="width: 5rem">
          <template #body="{ data }">{{ Number(data.quantity) }}</template>
        </Column>
        <Column :header="pt('Sale')">
          <template #body="{ data }">{{ money(data.sale_amount) }}</template>
        </Column>
        <!-- Only meaningful on a margin-based rule, and null where no cost was
             ever posted — shown as a dash rather than a misleading zero. -->
        <Column :header="pt('Margin')">
          <template #body="{ data }">
            <span v-if="data.margin_amount !== null">{{ money(data.margin_amount) }}</span>
            <span v-else class="muted">—</span>
          </template>
        </Column>
        <Column :header="pt('Rate')" style="width: 8rem">
          <template #body="{ data }">
            {{ rateText(data) }}
            <div class="muted small">{{ data.rule }}</div>
          </template>
        </Column>
        <Column :header="pt('Commission')">
          <template #body="{ data }"><strong>{{ money(data.commission_amount) }}</strong></template>
        </Column>
        <Column :header="pt('Status')" style="width: 8rem">
          <template #body="{ data }">
            <Tag :value="labelOf(STATUSES, data.status)" :severity="statusSeverity(data.status)" />
            <div v-if="data.payment_reference" class="muted small">{{ data.payment_reference }}</div>
          </template>
        </Column>
        <Column style="width: 6rem">
          <template #body="{ data }">
            <Button
              v-if="canEdit && data.status !== 'paid' && data.status !== 'reversed'"
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              @click="openEditEarning(data)"
            />
            <Button
              v-if="canApprove && data.status !== 'reversed'"
              icon="pi pi-undo"
              text
              rounded
              size="small"
              severity="danger"
              v-tooltip.top="pt('Reverse')"
              @click="reverse(data)"
            />
          </template>
        </Column>
        <template #empty>{{ pt('No commission lines for this period.') }}</template>
      </DataTable>
    </template>

    <!-- ================= Rules ================= -->
    <template v-else>
      <p class="hint-block">
        {{ pt('Several rules can match one sale — the one with the lowest priority number wins, and only that one pays. Give a specific rule (one brand, one product) a low number and your shop-wide default a high one.') }}
      </p>

      <DataTable :value="rules" :loading="rulesLoading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
        <Column :header="pt('Priority')" style="width: 6rem">
          <template #body="{ data }"><Tag severity="secondary" :value="String(data.priority)" /></template>
        </Column>
        <Column :header="pt('Rule')">
          <template #body="{ data }">
            <strong>{{ data.name }}</strong>
            <div class="muted small">{{ data.scope }}</div>
          </template>
        </Column>
        <Column :header="pt('Pays')">
          <template #body="{ data }">
            <strong>{{ rateText(data) }}</strong>
            <div class="muted small">{{ labelOf(BASES, data.basis) }}</div>
          </template>
        </Column>
        <Column :header="pt('Floors')">
          <template #body="{ data }">
            <div v-if="Number(data.min_line_amount)" class="small">
              {{ pt('line ≥') }} {{ money(data.min_line_amount) }}
            </div>
            <div v-if="Number(data.min_invoice_amount)" class="small">
              {{ pt('bill ≥') }} {{ money(data.min_invoice_amount) }}
            </div>
            <span v-if="!Number(data.min_line_amount) && !Number(data.min_invoice_amount)" class="muted">—</span>
          </template>
        </Column>
        <Column :header="pt('In force')">
          <template #body="{ data }">
            <span v-if="data.effective_from || data.effective_to">
              {{ data.effective_from || '…' }} → {{ data.effective_to || '…' }}
            </span>
            <span v-else class="muted">{{ pt('Always') }}</span>
          </template>
        </Column>
        <Column :header="pt('Status')" style="width: 8rem">
          <template #body="{ data }">
            <Tag :value="data.is_active ? pt('Active') : pt('Off')" :severity="data.is_active ? 'success' : 'secondary'" />
            <div v-if="data.earnings_count" class="muted small">{{ pt('{n} earning(s)', { n: data.earnings_count }) }}</div>
          </template>
        </Column>
        <Column style="width: 6rem">
          <template #body="{ data }">
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openRuleEdit(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" @click="removeRule(data)" />
          </template>
        </Column>
        <template #empty>{{ pt('No commission rules yet. Add one — “1% on everything” is a reasonable start.') }}</template>
      </DataTable>
    </template>

    <!-- ============ Rule editor ============ -->
    <Dialog
      v-model:visible="ruleDialog"
      :header="ruleEditingId ? pt('Edit Commission Rule') : pt('New Commission Rule')"
      modal
      :style="{ width: '46rem' }"
    >
      <div class="grid">
        <div class="fld wide">
          <label>{{ pt('Name') }} *</label>
          <InputText v-model="ruleForm.name" :placeholder="pt('e.g. 1% on everything, 3% on Brand X')" />
          <small v-if="ruleErrors.name" class="err">{{ ruleErrors.name[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Who earns it') }}</label>
          <Select v-model="ruleForm.applies_to" :options="APPLIES_TO" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label v-if="ruleForm.applies_to === 'employee'">{{ pt('Employee') }} *</label>
          <label v-else-if="ruleForm.applies_to === 'department'">{{ pt('Department') }} *</label>
          <label v-else>&nbsp;</label>
          <Select
            v-if="ruleForm.applies_to === 'employee'"
            v-model="ruleForm.employee_id"
            :options="employees"
            optionLabel="label"
            optionValue="id"
            filter
            :placeholder="pt('Pick the employee')"
          />
          <Select
            v-else-if="ruleForm.applies_to === 'department'"
            v-model="ruleForm.department_id"
            :options="departments"
            optionLabel="name"
            optionValue="id"
            filter
            :placeholder="pt('Pick the department')"
          />
          <small v-else class="hint">{{ pt('Everyone who rings up a bill.') }}</small>
        </div>

        <div class="fld wide">
          <label>{{ pt('On what') }}</label>
          <div class="triple">
            <Select v-model="ruleForm.category_id" :options="categories" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Any category')" />
            <Select v-model="ruleForm.brand_id" :options="brands" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Any brand')" />
            <Select v-model="ruleForm.product_id" :options="products" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Any product')" />
          </div>
          <small class="hint">{{ pt('Leave all three blank for anything sold. Set more than one and all must match.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Worked out as') }}</label>
          <Select v-model="ruleForm.basis" :options="BASES" optionLabel="label" optionValue="value" />
          <small v-if="ruleForm.basis === 'percent_of_margin'" class="hint">
            {{ pt('Margin comes from the stock ledger. A line with no cost posted earns nothing rather than being treated as pure profit.') }}
          </small>
        </div>
        <div class="fld">
          <label>{{ ruleForm.basis === 'flat_per_unit' ? pt('Amount per unit') : pt('Percentage') }} *</label>
          <InputNumber v-model="ruleForm.rate" :min="0" :maxFractionDigits="4" :suffix="ruleForm.basis === 'flat_per_unit' ? '' : ' %'" />
          <small v-if="ruleErrors.rate" class="err">{{ ruleErrors.rate[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Ignore lines below') }}</label>
          <InputNumber v-model="ruleForm.min_line_amount" :min="0" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Ignore bills below') }}</label>
          <InputNumber v-model="ruleForm.min_invoice_amount" :min="0" :maxFractionDigits="2" />
        </div>

        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <InputNumber v-model="ruleForm.priority" :min="1" :max="9999" />
          <small class="hint">{{ pt('Lower wins. Specific rules low, defaults high.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Active') }}</label>
          <div class="chk"><Checkbox v-model="ruleForm.is_active" binary /> {{ pt('In use') }}</div>
        </div>

        <div class="fld">
          <label>{{ pt('From') }}</label>
          <input type="date" v-model="ruleForm.effective_from" class="date-input" />
        </div>
        <div class="fld">
          <label>{{ pt('Until') }}</label>
          <input type="date" v-model="ruleForm.effective_to" class="date-input" />
          <small v-if="ruleErrors.effective_to" class="err">{{ ruleErrors.effective_to[0] }}</small>
        </div>

        <div class="fld wide">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="ruleForm.notes" rows="2" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="ruleDialog = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="ruleSaving" @click="saveRule" />
      </template>
    </Dialog>

    <!-- ============ Settle ============ -->
    <Dialog v-model:visible="payDialog" :header="pt('Mark as Paid')" modal :style="{ width: '30rem' }">
      <p class="hint">
        {{ pt('Only approved lines are settled. After this they can no longer be edited — a paid figure is changed by reversing it.') }}
      </p>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Paid on') }}</label>
          <input type="date" v-model="payForm.paid_on" class="date-input" />
        </div>
        <div class="fld">
          <label>{{ pt('Reference') }}</label>
          <InputText v-model="payForm.payment_reference" :placeholder="pt('e.g. Aug payroll')" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="payDialog = false" />
        <Button :label="pt('Settle {n} line(s)', { n: selected.length })" icon="pi pi-check" @click="markPaid" />
      </template>
    </Dialog>

    <!-- ============ Correct one figure ============ -->
    <Dialog v-model:visible="editDialog" :header="pt('Correct Earning')" modal :style="{ width: '32rem' }">
      <div v-if="editRow" class="edit-head">
        <strong>{{ editRow.employee }}</strong>
        <small class="muted">{{ editRow.invoice_number || editRow.so_number }} · {{ editRow.product }}</small>
      </div>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Commission') }}</label>
          <InputNumber v-model="editForm.commission_amount" :min="0" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Credit it to') }}</label>
          <Select v-model="editForm.employee_id" :options="employees" optionLabel="label" optionValue="id" filter />
          <small class="hint">{{ pt('For when somebody else actually made the sale.') }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Why') }}</label>
          <Textarea v-model="editForm.notes" rows="2" />
        </div>
      </div>
      <p class="hint-block">
        {{ pt('Working the month out again will not overwrite a corrected figure once it is approved and paid; while it is still pending, a re-run recalculates it.') }}
      </p>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="editDialog = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="editSaving" @click="saveEarning" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 0.4rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border, #e8e4da); }
.tabs button {
  background: none; border: none; border-bottom: 2px solid transparent;
  padding: 0.55rem 0.9rem; cursor: pointer; font-size: 0.85rem; color: #6b7280;
  display: flex; align-items: center; gap: 0.4rem;
}
.tabs button.on { color: var(--p-primary-color, #4f46e5); border-bottom-color: var(--p-primary-color, #4f46e5); font-weight: 600; }

.period-bar { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
.p-sel { width: 200px; }
.p-sel.narrow { width: 130px; }

.stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
.stat {
  border: 1px solid var(--border, #e8e4da); border-radius: 10px;
  padding: 0.6rem 1rem; display: flex; flex-direction: column; gap: 0.15rem; min-width: 150px;
}
.stat span { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.stat strong { font-size: 1.15rem; }
.stat.big strong { font-size: 1.5rem; }
.stat.warn { border-color: #e8c07d; background: rgba(232, 192, 125, 0.12); }

.sec { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin: 1.25rem 0 0.5rem; }
.lines-head { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 0.6rem; }
.line-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }

.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.triple { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.chk { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; color: #374151; padding-top: 0.4rem; }

.hint { font-size: 0.75rem; color: #6b7280; }
.hint-block {
  font-size: 0.78rem; color: #4a3410; margin: 0 0 1rem;
  border: 1px solid #e8c07d; background: rgba(232, 192, 125, 0.14);
  border-radius: 10px; padding: 0.6rem 0.8rem;
}
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
.small { font-size: 0.72rem; }
.edit-head { display: flex; flex-direction: column; gap: 0.15rem; margin-bottom: 0.85rem; }

@media (max-width: 640px) {
  .grid, .triple { grid-template-columns: 1fr; }
}
</style>
