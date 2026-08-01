<script setup lang="ts">
/**
 * Billing → Renewals (Volume 11 §Billing → Renewals).
 *
 * Three buckets, which are the three questions about money that has not arrived:
 * what renews soon and has no invoice yet (upcoming), what is invoiced and not yet
 * due (due), and what is past due and heading for suspension (overdue).
 *
 * The daily `subscriptions:renew` sweep does all of this unattended — this screen
 * is the manual override for chasing one account, so every action here is one the
 * cron would have taken eventually.
 */
import { ref, computed, onMounted } from 'vue'
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
import { fmtMoney, fmtDate } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canManage = computed(() => auth.can('renewals.manage'))

const bucket = ref<'upcoming' | 'due' | 'overdue'>('due')
const rows = ref<any[]>([])
const summary = ref<any>({})
const loading = ref(false)
const busyId = ref<number | null>(null)

// `upcoming` rows are subscriptions, the other two are invoices — different id
// spaces, so the row key follows the bucket.
const dataKey = computed(() => (bucket.value === 'upcoming' ? 'subscription_id' : 'id'))

const buckets = [
  { key: 'upcoming', label: 'Upcoming', icon: 'pi pi-calendar-plus', countKey: 'upcoming_count', valueKey: 'upcoming_value' },
  { key: 'due', label: 'Due', icon: 'pi pi-clock', countKey: 'due_count', valueKey: 'due_value' },
  { key: 'overdue', label: 'Overdue', icon: 'pi pi-exclamation-triangle', countKey: 'overdue_count', valueKey: 'overdue_value' },
] as const

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/renewals', { params: { bucket: bucket.value } })
    rows.value = data.data ?? []
    summary.value = data.summary ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load renewals', life: 4000 })
  } finally {
    loading.value = false
  }
}

function selectBucket(key: 'upcoming' | 'due' | 'overdue') {
  bucket.value = key
  load()
}

// ---- Issue a renewal invoice --------------------------------------------------
const issueVisible = ref(false)
const issuing = ref<any>(null)
const issueSaving = ref(false)
const issueForm = ref<{ coupon_code: string; due_date: Date | null; notify: boolean }>({
  coupon_code: '', due_date: null, notify: true,
})
const errors = ref<Record<string, string[]>>({})

function openIssue(row: any) {
  issuing.value = row
  issueForm.value = { coupon_code: row.coupon_code ?? '', due_date: null, notify: true }
  errors.value = {}
  issueVisible.value = true
}

function toApiDate(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

async function issue() {
  issueSaving.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post(`/billing/renewals/${issuing.value.subscription_id}/issue`, {
      coupon_code: issueForm.value.coupon_code || null,
      due_date: toApiDate(issueForm.value.due_date),
      notify: issueForm.value.notify,
    })
    toast.add({ severity: 'success', summary: 'Invoice raised', detail: data.message, life: 5000 })
    issueVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not raise the invoice', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    issueSaving.value = false
  }
}

// ---- Settle an invoice offline ------------------------------------------------
const payVisible = ref(false)
const paying = ref<any>(null)
const paySaving = ref(false)
const payForm = ref<{ method: string; reference: string; paid_at: Date | null }>({
  method: 'bank_transfer', reference: '', paid_at: null,
})

const methodOptions = [
  { label: 'Bank transfer', value: 'bank_transfer' },
  { label: 'UPI', value: 'upi' },
  { label: 'Cheque', value: 'cheque' },
  { label: 'Cash', value: 'cash' },
  { label: 'Other', value: 'other' },
]

function openPay(row: any) {
  paying.value = row
  payForm.value = { method: 'bank_transfer', reference: '', paid_at: null }
  errors.value = {}
  payVisible.value = true
}

async function markPaid() {
  paySaving.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post(`/billing/renewals/invoices/${paying.value.id}/mark-paid`, {
      method: payForm.value.method,
      reference: payForm.value.reference || null,
      paid_at: payForm.value.paid_at ? `${toApiDate(payForm.value.paid_at)} 12:00:00` : null,
    })
    toast.add({ severity: 'success', summary: 'Settled', detail: data.message, life: 5000 })
    payVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not settle', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    paySaving.value = false
  }
}

async function remind(row: any) {
  busyId.value = row.id
  try {
    const { data } = await centralHttp.post(`/billing/renewals/invoices/${row.id}/remind`)
    toast.add({ severity: 'success', summary: 'Reminder sent', detail: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Not sent', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  } finally {
    busyId.value = null
  }
}

async function voidInvoice(row: any) {
  const res = await Swal.fire({
    title: `Void ${row.number}?`,
    input: 'text',
    inputLabel: 'Reason (recorded on the invoice)',
    inputPlaceholder: 'Billed in error',
    text: 'The number stays in the GST series — a voided invoice remains in the books. Any coupon it used is released.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Void invoice',
    inputValidator: (v) => (v?.trim() ? undefined : 'A reason is required'),
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.post(`/billing/renewals/invoices/${row.id}/void`, { reason: res.value })
    toast.add({ severity: 'success', summary: 'Voided', detail: data.message, life: 4000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  }
}

function overdueSeverity(days: number): string {
  if (days >= 7) return 'danger'
  if (days >= 3) return 'warn'
  return 'info'
}

onMounted(load)
</script>

<template>
  <div>
    <div class="buckets">
      <button v-for="b in buckets" :key="b.key" class="bucket" :class="{ active: bucket === b.key, alert: b.key === 'overdue' && (summary[b.countKey] ?? 0) > 0 }"
        type="button" @click="selectBucket(b.key)">
        <i :class="b.icon" />
        <span class="bucket-label">{{ b.label }}</span>
        <span class="bucket-count">{{ summary[b.countKey] ?? 0 }}</span>
        <span class="bucket-value">{{ fmtMoney(summary[b.valueKey]) }}</span>
      </button>
    </div>

    <p v-if="bucket === 'upcoming'" class="bucket-note">
      Renewing soon with nothing raised yet. Amounts shown are the list price — the invoice applies any coupon and adds GST.
      The daily sweep raises these automatically ahead of the period end.
    </p>

    <DataTable :value="rows" :loading="loading" :dataKey="dataKey" class="p-datatable-sm"
      paginator :rows="20" :rowsPerPageOptions="[20, 50]">
      <Column header="Workspace">
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
            <span v-if="data.coupon_code" class="muted">coupon {{ data.coupon_code }}</span>
          </div>
        </template>
      </Column>
      <Column v-if="bucket !== 'upcoming'" header="Invoice" style="width: 11rem">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="mono">{{ data.number }}</span>
            <span class="muted">
              <template v-if="data.type === 'pack'">pack</template>
              <template v-else-if="data.period_end">to {{ data.period_end }}</template>
            </span>
          </div>
        </template>
      </Column>
      <Column header="Amount" style="width: 9rem">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="strong">{{ fmtMoney(data.amount ?? data.estimated_amount, data.currency) }}</span>
            <span v-if="Number(data.discount_amount) > 0" class="muted">less {{ fmtMoney(data.discount_amount, data.currency) }} discount</span>
            <span v-else-if="bucket === 'upcoming'" class="muted">before GST</span>
          </div>
        </template>
      </Column>
      <Column :header="bucket === 'upcoming' ? 'Renews' : 'Due'" style="width: 9rem">
        <template #body="{ data }">
          <template v-if="bucket === 'upcoming'">
            <div class="cell-2line">
              <span>{{ data.period_end }}</span>
              <span class="muted">in {{ data.days_to_renewal }} day(s)</span>
            </div>
          </template>
          <template v-else>
            <div class="cell-2line">
              <span>{{ data.due_date ?? '—' }}</span>
              <Tag v-if="data.days_overdue > 0" :value="`${data.days_overdue}d overdue`" :severity="overdueSeverity(data.days_overdue)" />
            </div>
          </template>
        </template>
      </Column>
      <Column v-if="bucket !== 'upcoming'" header="Chased" style="width: 8rem">
        <template #body="{ data }">
          <span v-if="data.reminder_stage" class="muted-sm">{{ data.reminder_stage }}× · {{ fmtDate(data.last_reminder_at) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <template v-if="bucket === 'upcoming'">
            <Button v-if="canManage" label="Invoice" icon="pi pi-file-plus" size="small" outlined @click="openIssue(data)" />
          </template>
          <template v-else>
            <Button v-if="canManage" icon="pi pi-check" text rounded severity="success" size="small"
              title="Record payment" @click="openPay(data)" />
            <Button v-if="canManage" icon="pi pi-envelope" text rounded size="small" :loading="busyId === data.id"
              title="Send reminder" @click="remind(data)" />
            <Button v-if="canManage" icon="pi pi-ban" text rounded severity="danger" size="small"
              title="Void invoice" @click="voidInvoice(data)" />
          </template>
        </template>
      </Column>
      <template #empty>
        {{ bucket === 'overdue' ? 'Nothing overdue — everything is paid up.' : bucket === 'due' ? 'No invoices awaiting payment.' : 'No renewals inside the window.' }}
      </template>
    </DataTable>

    <Dialog v-model:visible="issueVisible" modal header="Raise renewal invoice" :style="{ width: '480px' }">
      <p v-if="issuing" class="intro">
        {{ issuing.tenant_name }} — {{ issuing.plan_name }}, period ending {{ issuing.period_end }}.
        The invoice is numbered in the current GST series and emailed to the workspace.
      </p>
      <div class="form-grid">
        <div class="form-field">
          <label>Coupon code</label>
          <InputText v-model="issueForm.coupon_code" placeholder="Leave blank for the pinned coupon (if any)"
            :class="{ invalid: errors.coupon_code }" @input="issueForm.coupon_code = issueForm.coupon_code.toUpperCase()" />
          <small v-if="errors.coupon_code" class="error-text">{{ errors.coupon_code[0] }}</small>
        </div>
        <div class="form-field">
          <label>Due date</label>
          <DatePicker v-model="issueForm.due_date" showIcon dateFormat="yy-mm-dd" />
          <small class="hint">Empty uses the configured payment window.</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="issueVisible = false" />
        <Button label="Raise invoice" :loading="issueSaving" @click="issue" />
      </template>
    </Dialog>

    <Dialog v-model:visible="payVisible" modal header="Record payment" :style="{ width: '460px' }">
      <p v-if="paying" class="intro">
        {{ paying.number }} — {{ paying.tenant_name }} — {{ fmtMoney(paying.amount, paying.currency) }}.
        Settling this rolls the subscription onto the period the invoice covers and reactivates the workspace.
      </p>
      <div class="form-grid">
        <div class="form-field">
          <label>Method <span class="req">*</span></label>
          <Select v-model="payForm.method" :options="methodOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label>Reference</label>
          <InputText v-model="payForm.reference" placeholder="UTR / cheque no." />
        </div>
        <div class="form-field">
          <label>Received on</label>
          <DatePicker v-model="payForm.paid_at" showIcon dateFormat="yy-mm-dd" :maxDate="new Date()" />
          <small class="hint">Empty = now.</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="payVisible = false" />
        <Button label="Mark paid" severity="success" :loading="paySaving" @click="markPaid" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.buckets { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.bucket {
  display: grid; grid-template-columns: auto 1fr; grid-template-rows: auto auto auto;
  gap: 0 0.5rem; align-items: center; min-width: 168px; text-align: left;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.7rem 0.9rem; cursor: pointer;
}
.bucket:hover { border-color: #d1d5db; }
.bucket.active { border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1 inset; }
.bucket.alert .bucket-count { color: #dc2626; }
.bucket i { grid-row: 1 / 3; color: #6b7280; font-size: 1.1rem; }
.bucket-label { font-size: 0.8rem; color: #6b7280; }
.bucket-count { font-size: 1.25rem; font-weight: 600; line-height: 1.1; }
.bucket-value { grid-column: 2; font-size: 0.78rem; color: #6b7280; }
.bucket-note { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.75rem; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.muted-sm { color: #9ca3af; font-size: 0.8rem; }
.mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
.cell-2line { display: flex; flex-direction: column; gap: 0.1rem; }
.intro { font-size: 0.85rem; color: #4b5563; margin-bottom: 0.85rem; }
.form-grid { display: flex; flex-direction: column; gap: 1rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-select), .form-field :deep(.p-datepicker) { width: 100%; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
