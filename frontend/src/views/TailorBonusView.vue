<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const tailors = ref<{ label: string; value: number }[]>([])
const filterTailor = ref<number | null>(null)
const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const dialogVisible = ref(false)
const saving = ref(false)
const calculating = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({
  tailor_id: null, basis: 'pieces', period_start: null, period_end: null,
  pieces: null, rate: null, target: null, amount: null, notes: '',
})

const basisOptions = [
  { label: 'Pieces above target', value: 'pieces' },
  { label: 'Efficiency', value: 'efficiency' },
  { label: 'On-time delivery', value: 'ontime' },
  { label: 'Manual', value: 'manual' },
]

const canCreate = computed(() => auth.can('tailor-bonuses.create'))
const canApprove = computed(() => auth.can('tailor-bonuses.edit'))

function money(v: any) {
  return formatCurrency(v)
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function statusSeverity(status: string) {
  return { draft: 'secondary', approved: 'info', paid: 'success' }[status] || 'secondary'
}

async function loadTailors() {
  const { data } = await http.get('/tailors', { params: { per_page: 100, is_active: true } })
  tailors.value = (data.data ?? data).map((t: any) => ({ label: `${t.code} — ${t.name}`, value: t.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/tailor-bonuses', {
      params: { tailor_id: filterTailor.value || undefined, page: page.value, per_page: perPage.value },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function openCreate() {
  form.value = {
    tailor_id: filterTailor.value, basis: 'pieces', period_start: null, period_end: null,
    pieces: null, rate: null, target: null, amount: null, notes: '',
  }
  errors.value = {}
  dialogVisible.value = true
}

/** Ask the backend to suggest an amount from the tailor's performance. */
async function calculate() {
  if (!form.value.tailor_id) {
    toast.add({ severity: 'warn', summary: 'Select a tailor first', life: 2500 })
    return
  }
  calculating.value = true
  try {
    const { data } = await http.get('/tailor-bonuses/calculate', {
      params: {
        tailor_id: form.value.tailor_id,
        basis: form.value.basis,
        rate: form.value.rate ?? undefined,
        target: form.value.target ?? undefined,
        period_start: toDateString(form.value.period_start),
        period_end: toDateString(form.value.period_end),
      },
    })
    form.value.amount = Number(data.data?.amount ?? 0)
    toast.add({ severity: 'success', summary: `Suggested: ${money(form.value.amount)}`, life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Calculation failed', life: 4000 })
  } finally {
    calculating.value = false
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      tailor_id: form.value.tailor_id,
      basis: form.value.basis,
      period_start: toDateString(form.value.period_start),
      period_end: toDateString(form.value.period_end),
      pieces: form.value.pieces ?? undefined,
      rate: form.value.rate ?? undefined,
      amount: form.value.amount ?? undefined,
      notes: form.value.notes || undefined,
    }
    await http.post('/tailor-bonuses', payload)
    toast.add({ severity: 'success', summary: 'Bonus created (draft)', life: 2500 })
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function approve(row: any) {
  const res = await Swal.fire({
    title: 'Approve this bonus?',
    text: `${money(row.amount)} will be credited to ${row.tailor_name}'s ledger.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/tailor-bonuses/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Bonus approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Approve failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this bonus?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/tailor-bonuses/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  await loadTailors()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Tailor Bonuses</h1>
      <Button v-if="canCreate" label="New Bonus" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select v-model="filterTailor" :options="tailors" optionLabel="label" optionValue="value"
        placeholder="All tailors" showClear filter class="tailor-select" @change="() => { page = 1; load() }" />
      <TableExportButtons title="Tailor Bonuses" endpoint="/tailor-bonuses"
        :params="() => ({ tailor_id: filterTailor || undefined })"
        :columns="[
          { key: 'bonus_number', label: 'Bonus #' },
          { key: 'tailor_name', label: 'Tailor' },
          { key: 'basis', label: 'Basis' },
          { key: 'period_start', label: 'Period Start' },
          { key: 'period_end', label: 'Period End' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="bonus_number" header="Bonus #" />
      <Column field="tailor_name" header="Tailor" />
      <Column field="basis" header="Basis" />
      <Column header="Period">
        <template #body="{ data }">{{ data.period_start || '—' }} → {{ data.period_end || '—' }}</template>
      </Column>
      <Column header="Amount">
        <template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canApprove && data.status === 'draft'" label="Approve" icon="pi pi-check" text size="small" @click="approve(data)" />
          <Button v-if="canApprove && data.status === 'draft'" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
          <span v-if="data.status !== 'draft'" class="muted">—</span>
        </template>
      </Column>
      <template #empty>No bonuses recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal header="New Bonus" :style="{ width: '520px' }">
      <div class="form">
        <div class="field">
          <label>Tailor <span class="req">*</span></label>
          <Select v-model="form.tailor_id" :options="tailors" optionLabel="label" optionValue="value" filter
            placeholder="Select tailor" :class="{ invalid: fieldError('tailor_id') }" />
          <small v-if="fieldError('tailor_id')" class="error-text">{{ fieldError('tailor_id') }}</small>
        </div>
        <div class="field">
          <label>Basis <span class="req">*</span></label>
          <Select v-model="form.basis" :options="basisOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="row">
          <div class="field">
            <label>Period From</label>
            <DatePicker v-model="form.period_start" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
          <div class="field">
            <label>Period To</label>
            <DatePicker v-model="form.period_end" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
        </div>
        <div class="row" v-if="form.basis !== 'manual'">
          <div class="field">
            <label>Rate</label>
            <InputNumber v-model="form.rate" :min="0" :minFractionDigits="0" :maxFractionDigits="2" />
            <small class="hint">Per-piece / per-unit rate used by the rule.</small>
          </div>
          <div class="field" v-if="form.basis === 'pieces'">
            <label>Target Pieces</label>
            <InputNumber v-model="form.target" :min="0" />
            <small class="hint">Bonus applies to pieces above this.</small>
          </div>
        </div>
        <div class="field">
          <label>Amount <span class="req">*</span></label>
          <div class="amount-row">
            <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0"
              :class="{ invalid: fieldError('amount') }" class="grow" />
            <Button v-if="form.basis !== 'manual'" label="Calculate" icon="pi pi-calculator" outlined
              :loading="calculating" @click="calculate" />
          </div>
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
          <small v-else class="hint">Leave the rule fields set and press Calculate, or type an amount directly.</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save Draft" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Bonus Details" :fields="[
      { key: 'bonus_number', label: 'Bonus #' },
      { key: 'tailor_name', label: 'Tailor' },
      { key: 'basis', label: 'Basis' },
      { key: 'period_start', label: 'Period Start' },
      { key: 'period_end', label: 'Period End' },
      { key: 'pieces', label: 'Pieces' },
      { key: 'rate', label: 'Rate', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'amount', label: 'Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  margin-bottom: 1rem;
}
.tailor-select {
  min-width: 22rem;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
}
.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.amount-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.amount-row .grow {
  flex: 1;
}
.req {
  color: #dc2626;
}
.hint {
  color: #64748b;
  font-size: 0.75rem;
}
.muted {
  color: #9ca3af;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
