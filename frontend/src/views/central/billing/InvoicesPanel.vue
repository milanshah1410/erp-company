<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney, fmtDate, invoiceSeverity, INVOICE_STATUSES } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canRefund = computed(() => auth.can('invoices.refund'))

const rows = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const perPage = ref(15)
const statusFilter = ref<string | null>(null)
const search = ref('')

const statusOptions = INVOICE_STATUSES.map((s) => ({ label: s, value: s }))

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/invoices', {
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
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load invoices', life: 4000 })
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

// ---- Refund dialog -----------------------------------------------------------
const refundVisible = ref(false)
const refunding = ref<any>(null)
const refundSaving = ref(false)
const refundForm = ref<{ amount: number | null; reason: string }>({ amount: null, reason: '' })
const errors = ref<Record<string, string[]>>({})

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function openRefund(row: any) {
  refunding.value = row
  refundForm.value = { amount: Number(row.amount), reason: '' }
  errors.value = {}
  refundVisible.value = true
}

async function saveRefund() {
  refundSaving.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post(`/billing/invoices/${refunding.value.id}/refund`, {
      amount: refundForm.value.amount,
      reason: refundForm.value.reason,
    })
    toast.add({ severity: 'success', summary: 'Refund recorded', detail: data.message, life: 6000 })
    refundVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Refund failed', life: 4000 })
    }
  } finally {
    refundSaving.value = false
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
      <Column field="id" header="#" style="width: 4.5rem" />
      <Column header="Tenant">
        <template #body="{ data }">
          <span class="strong">{{ data.tenant_name ?? '—' }}</span>
        </template>
      </Column>
      <Column field="plan_name" header="Plan" />
      <Column header="Amount">
        <template #body="{ data }">{{ fmtMoney(data.amount, data.currency) }}</template>
      </Column>
      <Column header="Status" style="width: 7.5rem">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="invoiceSeverity[data.status] ?? 'secondary'" class="cap" />
        </template>
      </Column>
      <Column header="Method / Ref">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="cap">{{ data.payment_method ? data.payment_method.replace('_', ' ') : 'gateway' }}</span>
            <span v-if="data.payment_gateway_ref" class="mono muted-sm">{{ data.payment_gateway_ref }}</span>
          </div>
        </template>
      </Column>
      <Column header="Paid at" style="width: 8rem">
        <template #body="{ data }">{{ fmtDate(data.paid_at) }}</template>
      </Column>
      <Column header="Refund">
        <template #body="{ data }">
          <div v-if="data.refunded_at" class="cell-2line" :title="data.refund_reason">
            <span>{{ fmtMoney(data.refund_amount, data.currency) }} on {{ fmtDate(data.refunded_at) }}</span>
            <span class="muted ellipsis">{{ data.refund_reason }}</span>
          </div>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canRefund && data.status === 'paid'" icon="pi pi-replay" text rounded severity="warn" size="small"
            title="Record refund" @click="openRefund(data)" />
        </template>
      </Column>
      <template #empty>No invoices found.</template>
    </DataTable>

    <Dialog v-model:visible="refundVisible" modal header="Record refund" :style="{ width: '460px' }">
      <p v-if="refunding" class="refund-intro">
        Invoice #{{ refunding.id }} — {{ refunding.tenant_name }} — {{ fmtMoney(refunding.amount, refunding.currency) }} paid.
        This records the refund for revenue reporting; move the money via your payment gateway or bank.
      </p>
      <div class="form-grid">
        <div class="form-field">
          <label>Refund amount <span class="req">*</span></label>
          <InputNumber v-model="refundForm.amount" :min="0.01" :max="Number(refunding?.amount ?? 0)"
            :minFractionDigits="0" :maxFractionDigits="2" :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="form-field">
          <label>Reason <span class="req">*</span></label>
          <Textarea v-model="refundForm.reason" rows="3" autoResize :class="{ invalid: fieldError('reason') }" />
          <small v-if="fieldError('reason')" class="error-text">{{ fieldError('reason') }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="refundVisible = false" />
        <Button label="Record refund" severity="warn" :loading="refundSaving" @click="saveRefund" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.search { width: 240px; }
.status-filter { width: 160px; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.mono { font-family: ui-monospace, monospace; font-size: 0.8rem; }
.muted-sm { color: #9ca3af; }
.cap { text-transform: capitalize; }
.cell-2line { display: flex; flex-direction: column; }
.ellipsis { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.refund-intro { font-size: 0.85rem; color: #4b5563; margin-bottom: 0.85rem; }
.form-grid { display: flex; flex-direction: column; gap: 1rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
