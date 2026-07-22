<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney, fmtDate, subscriptionSeverity, invoiceSeverity, SUBSCRIPTION_STATUSES } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('subscriptions.edit'))
const canCancel = computed(() => auth.can('subscriptions.cancel'))

const rows = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const perPage = ref(15)
const statusFilter = ref<string | null>(null)
const search = ref('')

const statusOptions = SUBSCRIPTION_STATUSES.map((s) => ({ label: s.replace('_', ' '), value: s }))

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/subscriptions', {
      params: {
        page: page.value,
        per_page: perPage.value,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
      },
    })
    rows.value = data.data ?? []
    total.value = data.meta?.total ?? rows.value.length
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load subscriptions', life: 4000 })
  } finally {
    loading.value = false
  }
}

function onPage(event: any) {
  page.value = event.page + 1
  perPage.value = event.rows
  load()
}

let searchTimer: number | undefined
watch([search, statusFilter], () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    load()
  }, 400)
})

// ---- Detail dialog ---------------------------------------------------------
const detailVisible = ref(false)
const detail = ref<any>(null)
const detailLoading = ref(false)

async function openDetail(row: any) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await centralHttp.get(`/billing/subscriptions/${row.id}`)
    detail.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load subscription', life: 4000 })
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

// ---- Edit dialog ------------------------------------------------------------
const editVisible = ref(false)
const editingRow = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const planOptions = ref<any[]>([])
const editForm = ref<any>({})

// Statuses an operator may force manually; 'cancelled' goes through cancel().
const editStatusOptions = ['trialing', 'active', 'past_due', 'suspended'].map((s) => ({ label: s.replace('_', ' '), value: s }))

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function toDateObj(s: string | null): Date | null {
  return s ? new Date(s.replace(' ', 'T')) : null
}

function toApiDate(d: Date | null): string | null {
  if (!d) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

async function loadPlanOptions() {
  if (planOptions.value.length) return
  try {
    const { data } = await centralHttp.get('/billing/plans')
    planOptions.value = (data.data ?? data).map((p: any) => ({
      label: `${p.name} (${fmtMoney(p.price, p.currency)}/${p.billing_cycle === 'yearly' ? 'yr' : 'mo'})`,
      value: p.id,
    }))
  } catch {
    planOptions.value = []
  }
}

function openEdit(row: any) {
  editingRow.value = row
  editForm.value = {
    plan_id: row.plan_id,
    status: row.status === 'cancelled' ? null : row.status,
    trial_ends_at: toDateObj(row.trial_ends_at),
    current_period_start: toDateObj(row.current_period_start),
    current_period_end: toDateObj(row.current_period_end),
  }
  errors.value = {}
  editVisible.value = true
  loadPlanOptions()
}

async function saveEdit() {
  const row = editingRow.value
  // Send only what actually changed — a status write also resyncs the tenant.
  const norm = (s: string | null) => (s ?? '').slice(0, 16)
  const payload: Record<string, any> = {}
  if (editForm.value.plan_id && editForm.value.plan_id !== row.plan_id) payload.plan_id = editForm.value.plan_id
  if (editForm.value.status && editForm.value.status !== row.status) payload.status = editForm.value.status
  for (const key of ['trial_ends_at', 'current_period_start', 'current_period_end']) {
    const next = toApiDate(editForm.value[key])
    if (norm(next) !== norm(row[key])) payload[key] = next
  }
  if (!Object.keys(payload).length) {
    editVisible.value = false
    return
  }

  saving.value = true
  errors.value = {}
  try {
    await centralHttp.put(`/billing/subscriptions/${row.id}`, payload)
    toast.add({ severity: 'success', summary: 'Subscription updated', life: 2500 })
    editVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

// ---- Cancel ------------------------------------------------------------------
async function confirmCancel(row: any) {
  const res = await Swal.fire({
    title: `Cancel subscription for "${row.tenant_name}"?`,
    input: 'radio',
    inputOptions: {
      cycle_end: 'At period end — workspace stays usable until the paid period lapses',
      now: 'Immediately — workspace is suspended right away',
    },
    inputValue: 'cycle_end',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Cancel subscription',
    cancelButtonText: 'Keep it',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.post(`/billing/subscriptions/${row.id}/cancel`, {
      at_cycle_end: res.value !== 'now',
    })
    toast.add({ severity: 'success', summary: 'Cancelled', detail: data.message, life: 4000 })
    if (data.warning) {
      toast.add({ severity: 'warn', summary: 'Gateway warning', detail: data.warning, life: 8000 })
    }
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Cancel failed', life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <div class="filters">
        <InputText v-model="search" placeholder="Search tenant name / email…" class="search" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
          placeholder="All statuses" showClear class="status-filter" />
      </div>
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm"
      lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[15, 30, 50]" @page="onPage">
      <Column header="Tenant">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="strong">{{ data.tenant_name ?? '—' }}</span>
            <span class="muted">{{ data.tenant_email }}</span>
          </div>
        </template>
      </Column>
      <Column header="Plan">
        <template #body="{ data }">
          <div class="cell-2line">
            <span>{{ data.plan_name ?? '—' }}</span>
            <span class="muted">{{ fmtMoney(data.plan_price, data.currency) }} / {{ data.billing_cycle === 'yearly' ? 'yr' : 'mo' }}</span>
          </div>
        </template>
      </Column>
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }">
          <Tag :value="data.status.replace('_', ' ')" :severity="subscriptionSeverity[data.status] ?? 'secondary'" class="cap" />
        </template>
      </Column>
      <Column header="Trial ends" style="width: 8rem">
        <template #body="{ data }">{{ fmtDate(data.trial_ends_at) }}</template>
      </Column>
      <Column header="Current period" style="width: 13rem">
        <template #body="{ data }">
          <span v-if="data.current_period_start || data.current_period_end">
            {{ fmtDate(data.current_period_start) }} → {{ fmtDate(data.current_period_end) }}
          </span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 9rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" title="Details & invoices" @click="openDetail(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canCancel && data.status !== 'cancelled'" icon="pi pi-ban" text rounded severity="danger" size="small"
            title="Cancel subscription" @click="confirmCancel(data)" />
        </template>
      </Column>
      <template #empty>No subscriptions found.</template>
    </DataTable>

    <!-- Detail -->
    <Dialog v-model:visible="detailVisible" modal header="Subscription details" :style="{ width: '720px' }">
      <div v-if="detailLoading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading…</div>
      <template v-else-if="detail">
        <div class="detail-grid">
          <div><span class="dt">Tenant</span><span class="dd">{{ detail.tenant_name }} <span class="muted">({{ detail.tenant_email }})</span></span></div>
          <div><span class="dt">Tenant status</span><span class="dd cap">{{ detail.tenant_status }}</span></div>
          <div><span class="dt">Plan</span><span class="dd">{{ detail.plan_name }} — {{ fmtMoney(detail.plan_price, detail.currency) }}/{{ detail.billing_cycle === 'yearly' ? 'yr' : 'mo' }}</span></div>
          <div><span class="dt">Status</span><span class="dd"><Tag :value="detail.status.replace('_', ' ')" :severity="subscriptionSeverity[detail.status] ?? 'secondary'" class="cap" /></span></div>
          <div><span class="dt">Trial ends</span><span class="dd">{{ fmtDate(detail.trial_ends_at) }}</span></div>
          <div><span class="dt">Period</span><span class="dd">{{ fmtDate(detail.current_period_start) }} → {{ fmtDate(detail.current_period_end) }}</span></div>
          <div><span class="dt">Cancelled at</span><span class="dd">{{ fmtDate(detail.cancelled_at) }}</span></div>
          <div><span class="dt">Razorpay ID</span><span class="dd">{{ detail.razorpay_subscription_id ?? '—' }}</span></div>
        </div>

        <h4 class="invoices-title">Invoices</h4>
        <DataTable :value="detail.invoices ?? []" class="p-datatable-sm" dataKey="id">
          <Column field="id" header="#" style="width: 4rem" />
          <Column header="Amount">
            <template #body="{ data }">{{ fmtMoney(data.amount, data.currency) }}</template>
          </Column>
          <Column header="Status" style="width: 7rem">
            <template #body="{ data }">
              <Tag :value="data.status" :severity="invoiceSeverity[data.status] ?? 'secondary'" class="cap" />
            </template>
          </Column>
          <Column header="Paid at" style="width: 8rem">
            <template #body="{ data }">{{ fmtDate(data.paid_at) }}</template>
          </Column>
          <Column header="Refund">
            <template #body="{ data }">
              <span v-if="data.refunded_at">{{ fmtMoney(data.refund_amount, data.currency) }} <span class="muted">({{ fmtDate(data.refunded_at) }})</span></span>
              <span v-else class="muted">—</span>
            </template>
          </Column>
          <template #empty>No invoices yet.</template>
        </DataTable>
      </template>
    </Dialog>

    <!-- Edit -->
    <Dialog v-model:visible="editVisible" modal header="Edit subscription" :style="{ width: '520px' }">
      <div class="form-grid">
        <div class="form-field full">
          <label>Plan</label>
          <Select v-model="editForm.plan_id" :options="planOptions" optionLabel="label" optionValue="value" filter />
          <small v-if="fieldError('plan_id')" class="error-text">{{ fieldError('plan_id') }}</small>
        </div>
        <div class="form-field full">
          <label>Status</label>
          <Select v-model="editForm.status" :options="editStatusOptions" optionLabel="label" optionValue="value"
            :placeholder="editingRow?.status === 'cancelled' ? 'cancelled — pick a status to reinstate' : undefined" class="cap" />
          <small v-if="fieldError('status')" class="error-text">{{ fieldError('status') }}</small>
          <small class="hint">Changing the status also updates the tenant workspace (suspended ⇢ read-only, active ⇢ usable).</small>
        </div>
        <div class="form-field">
          <label>Trial ends</label>
          <DatePicker v-model="editForm.trial_ends_at" showTime hourFormat="24" dateFormat="yy-mm-dd" showIcon />
          <small v-if="fieldError('trial_ends_at')" class="error-text">{{ fieldError('trial_ends_at') }}</small>
        </div>
        <div class="form-field">
          <label>Period start</label>
          <DatePicker v-model="editForm.current_period_start" dateFormat="yy-mm-dd" showIcon />
          <small v-if="fieldError('current_period_start')" class="error-text">{{ fieldError('current_period_start') }}</small>
        </div>
        <div class="form-field">
          <label>Period end</label>
          <DatePicker v-model="editForm.current_period_end" dateFormat="yy-mm-dd" showIcon />
          <small v-if="fieldError('current_period_end')" class="error-text">{{ fieldError('current_period_end') }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="editVisible = false" />
        <Button label="Save" :loading="saving" @click="saveEdit" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.search { width: 240px; }
.status-filter { width: 170px; }
.cell-2line { display: flex; flex-direction: column; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.cap { text-transform: capitalize; }
.loading { color: #6b7280; padding: 1.5rem 0; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem 1.25rem; margin-bottom: 1rem; }
.detail-grid > div { display: flex; flex-direction: column; }
.dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
.dd { font-size: 0.9rem; color: #1f2937; }
.invoices-title { font-size: 0.95rem; font-weight: 600; margin: 0.5rem 0; color: #374151; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-select), .form-field :deep(.p-datepicker) { width: 100%; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
</style>
