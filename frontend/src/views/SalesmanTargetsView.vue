<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ProgressBar from 'primevue/progressbar'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const salesmen = ref<{ label: string; value: number }[]>([])

const now = new Date()
const MONTHS = [
  { label: 'January', value: 1 }, { label: 'February', value: 2 }, { label: 'March', value: 3 },
  { label: 'April', value: 4 }, { label: 'May', value: 5 }, { label: 'June', value: 6 },
  { label: 'July', value: 7 }, { label: 'August', value: 8 }, { label: 'September', value: 9 },
  { label: 'October', value: 10 }, { label: 'November', value: 11 }, { label: 'December', value: 12 },
]
const YEARS = Array.from({ length: 6 }, (_, i) => ({ label: String(now.getFullYear() - 3 + i), value: now.getFullYear() - 3 + i }))

const filters = ref<{ salesman_id: number | null; period_year: number | null; period_month: number | null }>({
  salesman_id: null,
  period_year: now.getFullYear(),
  period_month: null,
})

const canCreate = computed(() => auth.can('salesman-targets.create'))
const canEdit = computed(() => auth.can('salesman-targets.edit'))
const canDelete = computed(() => auth.can('salesman-targets.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({
  salesman_id: null, period_year: now.getFullYear(), period_month: now.getMonth() + 1,
  target_amount: null, target_orders: null, incentive_percent: null, notes: '',
})

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

function money(v: any) {
  return formatCurrency(v)
}
function monthName(m: number) {
  return MONTHS.find((x) => x.value === m)?.label ?? m
}
function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}
function achievementSeverity(pct: number) {
  if (pct >= 100) return 'success'
  if (pct >= 60) return 'warn'
  return 'danger'
}

async function loadSalesmen() {
  const { data } = await http.get('/salesmen', { params: { per_page: 100, is_active: true } })
  salesmen.value = (data.data ?? data).map((s: any) => ({ label: `${s.code} — ${s.name}`, value: s.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/salesman-targets', {
      params: {
        salesman_id: filters.value.salesman_id || undefined,
        period_year: filters.value.period_year || undefined,
        period_month: filters.value.period_month || undefined,
        with_achievement: 1,
        page: page.value,
        per_page: perPage.value,
      },
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
  editingId.value = null
  form.value = {
    salesman_id: null, period_year: now.getFullYear(), period_month: now.getMonth() + 1,
    target_amount: null, target_orders: null, incentive_percent: null, notes: '',
  }
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    salesman_id: row.salesman_id,
    period_year: row.period_year,
    period_month: row.period_month,
    target_amount: Number(row.target_amount),
    target_orders: row.target_orders != null ? Number(row.target_orders) : null,
    incentive_percent: row.incentive_percent != null ? Number(row.incentive_percent) : null,
    notes: row.notes || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, notes: form.value.notes || undefined }
    if (editingId.value) {
      await http.put(`/salesman-targets/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Target updated', life: 2500 })
    } else {
      await http.post('/salesman-targets', payload)
      toast.add({ severity: 'success', summary: 'Target created', life: 2500 })
    }
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this target?',
    text: `${row.salesman} · ${monthName(row.period_month)} ${row.period_year}`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/salesman-targets/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => {
  await loadSalesmen()
  await load()
})
</script>

<template>
  <div>
    <PageHeader title="Salesman Targets &amp; Incentive">
      <template #actions>
        <Button v-if="canCreate" label="New Target" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar">
      <Select v-model="filters.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter showClear
        placeholder="All salesmen" class="w-60" @change="load" />
      <Select v-model="filters.period_year" :options="YEARS" optionLabel="label" optionValue="value" showClear
        placeholder="Year" @change="load" />
      <Select v-model="filters.period_month" :options="MONTHS" optionLabel="label" optionValue="value" showClear
        placeholder="All months" @change="load" />
      <TableExportButtons title="Salesman Targets" endpoint="/salesman-targets"
        :params="() => ({
          salesman_id: filters.salesman_id || undefined,
          period_year: filters.period_year || undefined,
          period_month: filters.period_month || undefined,
          with_achievement: 1,
        })"
        :columns="[
          { key: 'salesman', label: 'Salesman' },
          { key: 'period_month', label: 'Month' },
          { key: 'period_year', label: 'Year' },
          { key: 'target_amount', label: 'Target' },
          { key: 'achievement.achieved_amount', label: 'Achieved' },
          { key: 'achievement.achievement_percent', label: 'Achievement %' },
          { key: 'achievement.achieved_orders', label: 'Orders' },
          { key: 'target_orders', label: 'Target Orders' },
          { key: 'effective_incentive_percent', label: 'Incentive %' },
          { key: 'achievement.incentive_amount', label: 'Incentive' },
          { key: 'achievement.target_met', label: 'Target Met', format: (v) => (v ? 'Yes' : 'No') },
        ]" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="salesman" header="Salesman" />
      <Column header="Period">
        <template #body="{ data }">{{ monthName(data.period_month) }} {{ data.period_year }}</template>
      </Column>
      <Column header="Target">
        <template #body="{ data }">{{ money(data.target_amount) }}</template>
      </Column>
      <Column header="Achieved">
        <template #body="{ data }">{{ money(data.achievement?.achieved_amount ?? 0) }}</template>
      </Column>
      <Column header="Achievement" style="min-width: 12rem">
        <template #body="{ data }">
          <div class="ach">
            <ProgressBar :value="Math.min(100, data.achievement?.achievement_percent ?? 0)" :showValue="false" style="height: 0.5rem" />
            <span class="ach-pct" :class="achievementSeverity(data.achievement?.achievement_percent ?? 0)">
              {{ (data.achievement?.achievement_percent ?? 0).toFixed(0) }}%
            </span>
          </div>
        </template>
      </Column>
      <Column header="Orders">
        <template #body="{ data }">
          {{ data.achievement?.achieved_orders ?? 0 }}<span v-if="data.target_orders"> / {{ data.target_orders }}</span>
        </template>
      </Column>
      <Column header="Incentive %">
        <template #body="{ data }">{{ Number(data.effective_incentive_percent).toFixed(2) }}%</template>
      </Column>
      <Column header="Incentive">
        <template #body="{ data }">
          <strong>{{ money(data.achievement?.incentive_amount ?? 0) }}</strong>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="data.achievement?.target_met ? 'success' : 'warn'" :value="data.achievement?.target_met ? 'Met' : 'In progress'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 10rem; white-space: nowrap">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No targets set.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Target' : 'New Target'" :style="{ width: '520px' }">
      <div class="form">
        <div class="field">
          <label>Salesman <span class="req">*</span></label>
          <Select v-model="form.salesman_id" :options="salesmen" optionLabel="label" optionValue="value" filter
            placeholder="Select salesman" :disabled="!!editingId" :class="{ invalid: fieldError('salesman_id') }" />
          <small v-if="fieldError('salesman_id')" class="error-text">{{ fieldError('salesman_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Year <span class="req">*</span></label>
            <Select v-model="form.period_year" :options="YEARS" optionLabel="label" optionValue="value"
              :class="{ invalid: fieldError('period_year') }" />
            <small v-if="fieldError('period_year')" class="error-text">{{ fieldError('period_year') }}</small>
          </div>
          <div class="field">
            <label>Month <span class="req">*</span></label>
            <Select v-model="form.period_month" :options="MONTHS" optionLabel="label" optionValue="value"
              :class="{ invalid: fieldError('period_month') }" />
            <small v-if="fieldError('period_month')" class="error-text">{{ fieldError('period_month') }}</small>
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>Target Amount <span class="req">*</span></label>
            <InputNumber v-model="form.target_amount" mode="currency" currency="INR" locale="en-IN" :min="0"
              :class="{ invalid: fieldError('target_amount') }" />
            <small v-if="fieldError('target_amount')" class="error-text">{{ fieldError('target_amount') }}</small>
          </div>
          <div class="field">
            <label>Target Orders</label>
            <InputNumber v-model="form.target_orders" :min="0" />
          </div>
        </div>
        <div class="field">
          <label>Incentive %</label>
          <InputNumber v-model="form.incentive_percent" :min="0" :max="100" :maxFractionDigits="2" suffix=" %" />
          <small class="hint">Leave blank to use the salesman's default incentive rate.</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog
      v-model:visible="detailVisible"
      title="Salesman Target"
      :record="detailRecord"
      :fields="[
        { label: 'Salesman', key: 'salesman' },
        { label: 'Period', key: 'period_month', format: (_v, r) => `${monthName(r.period_month)} ${r.period_year}` },
        { label: 'Target Amount', key: 'target_amount', format: (v) => money(v) },
        { label: 'Achieved', key: 'achievement.achieved_amount', format: (v) => money(v ?? 0) },
        { label: 'Achievement %', key: 'achievement.achievement_percent', format: (v) => `${(v ?? 0).toFixed(0)}%` },
        { label: 'Target Orders', key: 'target_orders' },
        { label: 'Achieved Orders', key: 'achievement.achieved_orders', format: (v) => String(v ?? 0) },
        { label: 'Incentive %', key: 'effective_incentive_percent', format: (v) => `${Number(v).toFixed(2)}%` },
        { label: 'Incentive', key: 'achievement.incentive_amount', format: (v) => money(v ?? 0) },
        { label: 'Notes', key: 'notes', full: true },
      ]"
    />
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
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.w-60 {
  width: 240px;
}
.ach {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.ach :deep(.p-progressbar) {
  flex: 1;
}
.ach-pct {
  font-size: 0.8rem;
  font-weight: 600;
  min-width: 2.5rem;
  text-align: right;
}
.ach-pct.success { color: #16a34a; }
.ach-pct.warn { color: #d97706; }
.ach-pct.danger { color: #dc2626; }
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
.field :deep(.p-inputtext) {
  width: 100%;
}
.req { color: #dc2626; }
.hint { color: #64748b; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
