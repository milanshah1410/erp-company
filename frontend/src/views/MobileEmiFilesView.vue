<script setup lang="ts">
/**
 * EMI files — the collections desk.
 *
 * The one thing this screen has to make unmissable is *who is owed the money*. An
 * in-house file is the shop's receivable and collecting an instalment moves the cash
 * book; a finance-company file was settled with the shop at disbursal and its
 * instalments are the customer's business with the bank, tracked here only so the
 * counter can answer "how many are left?". Confusing the two either invents revenue
 * or hides debt, so the distinction is a tag on every row and the receivable tile
 * counts in-house files only.
 *
 * A file's schedule expands in place rather than opening a page: the question at the
 * desk is always "which one is late and can I take it now", and that is two clicks
 * away from a list, not a navigation.
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
import Message from 'primevue/message'
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

const canCreate = computed(() => auth.can('mobile-emi.create'))
const canEdit = computed(() => auth.can('mobile-emi.edit'))
const canDelete = computed(() => auth.can('mobile-emi.delete'))
const canCollect = computed(() => auth.can('payments.create'))

const FINANCIERS = [
  { label: 'Our own finance (we are owed)', value: 'in_house' },
  { label: 'Finance company (bank is owed)', value: 'finance_company' },
]

const MODES = [
  { label: 'Cash', value: 'cash' },
  { label: 'Card', value: 'card' },
  { label: 'UPI', value: 'upi' },
]

const rows = ref<any[]>([])
const expanded = ref<Record<number, boolean>>({})
const summary = ref({
  active: 0,
  in_house: 0,
  financed: 0,
  receivable: 0,
  overdue_instalments: 0,
  due_this_week: 0,
})
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const handsets = ref<any[]>([])
const invoices = ref<any[]>([])
const search = ref('')
const overdueOnly = ref(false)

function money(v: any) {
  return v === null || v === undefined ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/mobile/emi', {
      params: {
        search: search.value || undefined,
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
  const [c, p, h, i] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/mobile/handsets', { params: { per_page: 500 } }),
    http.get('/invoices', { params: { per_page: 200 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: x.phone ? `${x.name} (${x.phone})` : x.name,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    selling_price: x.selling_price,
  }))
  handsets.value = (h.data.data ?? h.data).map((x: any) => ({
    label: x.label,
    value: x.id,
    imei: x.imei_1,
    product_id: x.product_id,
    customer_id: x.customer_id,
  }))
  invoices.value = (i.data.data ?? i.data).map((x: any) => ({
    label: `${x.invoice_number} — ${money(x.total)}`,
    value: x.id,
    total: Number(x.total ?? 0),
    customer_id: x.customer_id ?? x.customer?.id ?? null,
  }))
}

// ---- The schedule, expanded in place -------------------------------------

const detail = ref<Record<number, any>>({})

async function toggle(row: any) {
  expanded.value[row.id] = !expanded.value[row.id]
  if (expanded.value[row.id] && !detail.value[row.id]) {
    const { data } = await http.get(`/mobile/emi/${row.id}`)
    detail.value[row.id] = data.data
  }
}

function refreshRow(plan: any) {
  detail.value[plan.id] = plan
  const idx = rows.value.findIndex((r) => r.id === plan.id)
  if (idx > -1) {
    // The list row carries the derived figures, so it has to move with the
    // schedule — otherwise the desk collects an instalment and the outstanding
    // beside it still shows the old number.
    rows.value[idx] = { ...rows.value[idx], ...plan }
  }
}

const collectDialog = ref(false)
const collecting = ref(false)
const collectTarget = ref<any>(null)
const collectForm = ref({ mode: 'cash', paid_on: new Date() as Date | null, notes: '' })

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function openCollect(plan: any, instalment: any) {
  collectTarget.value = { plan, instalment }
  collectForm.value = { mode: 'cash', paid_on: new Date(), notes: '' }
  collectDialog.value = true
}

async function doCollect() {
  collecting.value = true
  try {
    const { data } = await http.post(
      `/mobile/emi/instalments/${collectTarget.value.instalment.id}/collect`,
      { ...collectForm.value, paid_on: asDate(collectForm.value.paid_on) },
    )
    refreshRow(data.data)
    collectDialog.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Instalment collected'), life: 2500 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.instalment?.[0] ?? e.response?.data?.message,
      life: 4500,
    })
  } finally {
    collecting.value = false
  }
}

async function waive(instalment: any) {
  const res = await Swal.fire({
    title: pt('Write this instalment off?'),
    text: pt('No money is recorded as received. Use this for a subvention the brand absorbed, or a correction.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Write off'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/mobile/emi/instalments/${instalment.id}/waive`, {
      reason: res.value || undefined,
    })
    refreshRow(data.data)
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.instalment?.[0] ?? e.response?.data?.message,
      life: 4500,
    })
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  financier: 'in_house',
  financier_name: '',
  loan_account_no: '',
  invoice_id: null as number | null,
  handset_id: null as number | null,
  product_id: null as number | null,
  imei: '',
  asset_value: null as number | null,
  down_payment: 0 as number | null,
  processing_fee: 0 as number | null,
  interest_percent: 0 as number | null,
  tenure_months: 9 as number | null,
  first_due_date: null as Date | null,
  notes: '',
})

const form = ref(blank())

/** What the schedule will be built from — shown live, because it is the number the customer is quoted. */
const financed = computed(() =>
  Math.max(0, Number(form.value.asset_value ?? 0) - Number(form.value.down_payment ?? 0)),
)
const perMonth = computed(() => {
  const t = Number(form.value.tenure_months ?? 0)
  return t > 0 ? Math.floor((financed.value / t) * 100) / 100 : 0
})

function onHandsetPicked() {
  const h = handsets.value.find((x) => x.value === form.value.handset_id)
  if (!h) return
  if (!form.value.imei) form.value.imei = h.imei ?? ''
  if (!form.value.product_id) form.value.product_id = h.product_id
  if (!form.value.customer_id) form.value.customer_id = h.customer_id
  const p = products.value.find((x) => x.value === h.product_id)
  if (p && form.value.asset_value === null) form.value.asset_value = p.selling_price
}

function onInvoicePicked() {
  const inv = invoices.value.find((x) => x.value === form.value.invoice_id)
  if (!inv) return
  if (form.value.asset_value === null) form.value.asset_value = inv.total
  if (!form.value.customer_id) form.value.customer_id = inv.customer_id
}

function openCreate() {
  form.value = blank()
  form.value.first_due_date = new Date(new Date().setMonth(new Date().getMonth() + 1))
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/mobile/emi/${row.id}`)
  const p = data.data
  editingId.value = p.id
  form.value = {
    customer_id: p.customer_id,
    financier: p.financier,
    financier_name: p.financier_name ?? '',
    loan_account_no: p.loan_account_no ?? '',
    invoice_id: p.invoice_id,
    handset_id: p.handset_id,
    product_id: p.product_id,
    imei: p.imei ?? '',
    asset_value: p.asset_value,
    down_payment: p.down_payment,
    processing_fee: p.processing_fee,
    interest_percent: p.interest_percent,
    tenure_months: p.tenure_months,
    first_due_date: p.first_due_date ? new Date(p.first_due_date) : null,
    notes: p.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, first_due_date: asDate(form.value.first_due_date) }
    if (editingId.value) {
      await http.put(`/mobile/emi/${editingId.value}`, payload)
    } else {
      await http.post('/mobile/emi', payload)
    }
    dialogVisible.value = false
    detail.value = {}
    await load()
    toast.add({ severity: 'success', summary: pt('Finance file saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the file'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function progress(row: any, status: string) {
  try {
    const { data } = await http.post(`/mobile/emi/${row.id}/progress`, { status })
    refreshRow(data.data)
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.status?.[0] ?? e.response?.data?.message,
      life: 5000,
    })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this file?'),
    text: pt('Only a file with nothing collected on it can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/mobile/emi/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('EMI Files')"
      :subtitle="pt('What is owed, by whom, and to us or to the bank')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Open Finance File')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Active files') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Owed to us') }}</span>
        <span class="t-value">{{ money(summary.receivable) }}</span>
        <small class="t-note">{{ summary.in_house }} {{ pt('in-house files') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Bank-financed') }}</span>
        <span class="t-value">{{ summary.financed }}</span>
        <small class="t-note">{{ pt('tracked, not owed to us') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Overdue instalments') }}</span>
        <span class="t-value" :class="{ warn: summary.overdue_instalments > 0 }">
          {{ summary.overdue_instalments }}
        </span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Due this week') }}</span>
        <span class="t-value">{{ summary.due_this_week }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('File number, customer, IMEI or financier')" @keyup.enter="load" />
      <Button
        :label="pt('Overdue only')"
        :outlined="!overdueOnly"
        icon="pi pi-exclamation-circle"
        @click="
          () => {
            overdueOnly = !overdueOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="EMI Files"
        filenameBase="emi-files"
        :rows="rows"
        :columns="[
          { key: 'plan_number', label: 'File' },
          { key: 'customer', label: 'Customer' },
          { key: 'financier_name', label: 'Financier' },
          { key: 'financed_amount', label: 'Financed' },
          { key: 'instalment_amount', label: 'Per month' },
          { key: 'outstanding', label: 'Outstanding' },
          { key: 'next_due_date', label: 'Next due' },
          { key: 'status', label: 'Status' },
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
      <Column style="width: 3rem">
        <template #body="{ data }">
          <Button
            :icon="expanded[data.id] ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"
            text
            rounded
            :title="pt('Schedule')"
            @click="toggle(data)"
          />
        </template>
      </Column>

      <Column field="plan_number" :header="pt('File')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.plan_number }}</div>
          <small class="muted">{{ data.handset || '—' }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">
          <div>{{ data.customer }}</div>
          <small class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>

      <Column field="financier" :header="pt('Owed to')">
        <template #body="{ data }">
          <Tag
            :value="data.is_receivable ? pt('Us') : pt('Bank')"
            :severity="data.is_receivable ? 'warn' : 'secondary'"
          />
          <small v-if="data.financier_name" class="muted block">{{ data.financier_name }}</small>
        </template>
      </Column>

      <Column field="financed_amount" :header="pt('Financed')" sortable>
        <template #body="{ data }">
          <div>{{ money(data.financed_amount) }}</div>
          <small class="muted">
            {{ money(data.instalment_amount) }} × {{ data.tenure_months }}
          </small>
        </template>
      </Column>

      <Column field="paid_count" :header="pt('Paid')">
        <template #body="{ data }">{{ data.paid_count }} / {{ data.tenure_months }}</template>
      </Column>

      <Column field="outstanding" :header="pt('Outstanding')" sortable>
        <template #body="{ data }">
          <div :class="{ owed: data.is_receivable && data.outstanding > 0 }">
            {{ money(data.outstanding) }}
          </div>
          <small v-if="data.overdue_count" class="err">
            {{ data.overdue_count }} {{ pt('overdue') }}
          </small>
        </template>
      </Column>

      <Column field="next_due_date" :header="pt('Next due')" sortable>
        <template #body="{ data }">
          <div v-if="data.next_due_date">{{ data.next_due_date }}</div>
          <span v-else class="muted">—</span>
          <small v-if="data.next_due_amount" class="muted">{{ money(data.next_due_amount) }}</small>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="pt(data.status)"
            :severity="
              data.status === 'active'
                ? 'info'
                : data.status === 'closed'
                  ? 'success'
                  : data.status === 'defaulted'
                    ? 'danger'
                    : 'secondary'
            "
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.paid_count === 0"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canEdit && data.status === 'active'"
            icon="pi pi-flag"
            text
            rounded
            severity="warn"
            :title="pt('Mark defaulted')"
            @click="progress(data, 'defaulted')"
          />
          <Button
            v-if="canDelete && data.paid_count === 0"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #expansion />

      <template #empty>
        <div class="empty-state">
          {{ pt('No finance files yet. Most handsets above a certain price leave the shop on one.') }}
        </div>
      </template>
    </DataTable>

    <!-- Schedules, expanded under the list rather than on a page of their own -->
    <div v-for="row in rows.filter((r) => expanded[r.id])" :key="'sch' + row.id" class="schedule">
      <div class="s-head">
        <span class="strong">{{ row.plan_number }}</span>
        <span class="muted">{{ row.customer }}</span>
        <Tag
          :value="row.is_receivable ? pt('We are owed these') : pt('The bank is owed these')"
          :severity="row.is_receivable ? 'warn' : 'secondary'"
        />
      </div>

      <Message v-if="!row.is_receivable" severity="info" :closable="false">
        {{
          pt(
            'This file was settled with us at disbursal. Ticking an instalment here records that the customer paid the financier — it does not take money into the shop.',
          )
        }}
      </Message>

      <table v-if="detail[row.id]" class="s-table">
        <thead>
          <tr>
            <th>#</th>
            <th>{{ pt('Due') }}</th>
            <th>{{ pt('Amount') }}</th>
            <th>{{ pt('Status') }}</th>
            <th>{{ pt('Paid on') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in detail[row.id].instalments" :key="i.id" :class="{ late: i.is_overdue }">
            <td>{{ i.sequence }}</td>
            <td>{{ i.due_date }}</td>
            <td>{{ money(i.amount) }}</td>
            <td>
              <Tag
                :value="i.is_overdue ? pt('overdue') : pt(i.status)"
                :severity="
                  i.status === 'paid'
                    ? 'success'
                    : i.status === 'waived'
                      ? 'secondary'
                      : i.is_overdue
                        ? 'danger'
                        : 'info'
                "
              />
              <small v-if="i.days_late" class="err"> {{ i.days_late }} {{ pt('days') }}</small>
            </td>
            <td>{{ i.paid_on || '—' }}</td>
            <td class="s-actions">
              <template v-if="i.status === 'pending'">
                <Button
                  v-if="canCollect"
                  :label="pt('Collect')"
                  size="small"
                  @click="openCollect(row, i)"
                />
                <Button
                  v-if="canDelete"
                  :label="pt('Write off')"
                  size="small"
                  text
                  severity="danger"
                  @click="waive(i)"
                />
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="muted">{{ pt('Loading…') }}</div>
    </div>

    <!-- Collect -->
    <Dialog v-model:visible="collectDialog" :header="pt('Collect Instalment')" modal :style="{ width: '460px' }">
      <div v-if="collectTarget" class="grid">
        <div class="fld span-2">
          <Message severity="info" :closable="false">
            {{ pt('Instalment') }} {{ collectTarget.instalment.sequence }} —
            {{ money(collectTarget.instalment.amount) }},
            {{ pt('due') }} {{ collectTarget.instalment.due_date }}
          </Message>
        </div>
        <div class="fld">
          <label>{{ pt('How it arrived') }}</label>
          <Select
            v-model="collectForm.mode"
            :options="MODES"
            option-label="label"
            option-value="value"
            class="w-full"
            :disabled="!collectTarget.plan.is_receivable"
          />
          <small v-if="!collectTarget.plan.is_receivable" class="hint">
            {{ pt('No receipt is written on a bank file.') }}
          </small>
        </div>
        <div class="fld">
          <label>{{ pt('Paid on') }}</label>
          <DatePicker v-model="collectForm.paid_on" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <InputText v-model="collectForm.notes" class="w-full" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="collectDialog = false" />
        <Button :label="pt('Record')" :loading="collecting" @click="doCollect" />
      </template>
    </Dialog>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Finance File') : pt('Open Finance File')"
      modal
      :style="{ width: '840px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Who is owed the instalments') }} *</label>
          <Select
            v-model="form.financier"
            :options="FINANCIERS"
            option-label="label"
            option-value="value"
            class="w-full"
          />
          <small class="hint">
            {{
              pt(
                'In-house means every instalment is money we are still owed. A finance company pays us up front, and the schedule is only so we can answer the customer.',
              )
            }}
          </small>
        </div>

        <div class="fld">
          <label>{{ pt('Financier') }}</label>
          <InputText v-model="form.financier_name" class="w-full" placeholder="Bajaj Finserv / HDFC" />
          <small v-if="errors.financier_name" class="err">{{ errors.financier_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Loan / card account no.') }}</label>
          <InputText v-model="form.loan_account_no" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sale invoice') }}</label>
          <Select
            v-model="form.invoice_id"
            :options="invoices"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onInvoicePicked"
          />
          <small v-if="errors.invoice_id" class="err">{{ errors.invoice_id[0] }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Handset') }}</label>
          <Select
            v-model="form.handset_id"
            :options="handsets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onHandsetPicked"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Handset price') }} *</label>
          <InputNumber v-model="form.asset_value" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.asset_value" class="err">{{ errors.asset_value[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Down payment') }}</label>
          <InputNumber v-model="form.down_payment" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.down_payment" class="err">{{ errors.down_payment[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Processing fee') }}</label>
          <InputNumber v-model="form.processing_fee" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Interest') }}</label>
          <InputNumber v-model="form.interest_percent" :min="0" :max-fraction-digits="3" suffix=" %" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Tenure') }} *</label>
          <InputNumber v-model="form.tenure_months" :min="1" suffix=" mo" class="w-full" />
          <small v-if="errors.tenure_months" class="err">{{ errors.tenure_months[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('First instalment due') }} *</label>
          <DatePicker v-model="form.first_due_date" date-format="dd/mm/yy" class="w-full" />
        </div>

        <div class="fld span-2">
          <Message severity="info" :closable="false">
            {{ pt('To finance') }}: <strong>{{ money(financed) }}</strong> ·
            {{ pt('about') }} <strong>{{ money(perMonth) }}</strong> {{ pt('a month') }}
            <template v-if="form.tenure_months"> × {{ form.tenure_months }}</template>.
            {{ pt('The last instalment carries the rounding.') }}
          </Message>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
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
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }
.t-note { font-size: 0.68rem; color: #9ca3af; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }

.schedule { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.8rem 1rem; margin-top: 1rem; }
.s-head { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.6rem; }
.s-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; margin-top: 0.5rem; }
.s-table th { text-align: left; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--surface-border, #e5e7eb); }
.s-table td { padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--surface-border, #f3f4f6); }
.s-table tr.late td { background: #fef2f2; }
.s-actions { display: flex; gap: 0.35rem; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.block { display: block; }
.strong { font-weight: 600; }
.owed { font-weight: 600; color: #b45309; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
