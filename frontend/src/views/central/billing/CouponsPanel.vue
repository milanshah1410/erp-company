<script setup lang="ts">
/**
 * Billing → Coupons (Volume 11 §Billing → Coupons).
 *
 * Promotional codes against our own subscription pricing — not the tenant-side
 * retail coupons a shop runs at its counter.
 *
 * Two server rules the form mirrors rather than re-implements: a redeemed code
 * cannot be renamed (it is out in the world by then) and the redemption limit
 * cannot drop below what has been spent. Both are enforced server-side; here they
 * only shape what is disabled, so the two can never disagree.
 */
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney, fmtDate, fmtPercent } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('coupons.create'))
const canEdit = computed(() => auth.can('coupons.edit'))
const canDelete = computed(() => auth.can('coupons.delete'))

const rows = ref<any[]>([])
const plans = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const perPage = ref(20)
const search = ref('')
const statusFilter = ref<string | null>('live')

const statusOptions = [
  { label: 'Live', value: 'live' },
  { label: 'Expired', value: 'expired' },
  { label: 'Inactive', value: 'inactive' },
]
const typeOptions = [
  { label: 'Percentage off', value: 'percent' },
  { label: 'Flat amount off', value: 'flat' },
]
const durationOptions = [
  { label: 'Once — the next invoice only', value: 'once' },
  { label: 'Repeating — a set number of cycles', value: 'repeating' },
  { label: 'Forever — every invoice', value: 'forever' },
]
const appliesOptions = [
  { label: 'Plan subscriptions', value: 'subscription' },
  { label: 'Marketplace packs', value: 'pack' },
  { label: 'Both', value: 'both' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/coupons', {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        status: statusFilter.value || undefined,
      },
    })
    rows.value = data.data ?? []
    plans.value = data.plans ?? []
    total.value = data.meta?.total ?? rows.value.length
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load coupons', life: 4000 })
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

// ---- Create / edit -----------------------------------------------------------
const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

/** A redeemed code is fixed — see the panel docblock. */
const codeLocked = computed(() => !!editing.value && (editing.value.redemption_count ?? 0) > 0)

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function blankForm() {
  return {
    code: '',
    name: '',
    description: '',
    discount_type: 'percent',
    discount_value: 10,
    currency: 'INR',
    max_discount_amount: null,
    min_amount: null,
    duration: 'once',
    duration_cycles: 3,
    applies_to: 'subscription',
    plan_ids: [],
    new_tenants_only: false,
    max_redemptions: null,
    max_redemptions_per_tenant: 1,
    starts_at: null,
    ends_at: null,
    is_active: true,
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    ...blankForm(),
    ...row,
    discount_value: Number(row.discount_value),
    max_discount_amount: row.max_discount_amount !== null ? Number(row.max_discount_amount) : null,
    min_amount: row.min_amount !== null ? Number(row.min_amount) : null,
    duration_cycles: row.duration_cycles ?? 3,
    plan_ids: row.plan_ids ?? [],
    starts_at: row.starts_at ? new Date(row.starts_at) : null,
    ends_at: row.ends_at ? new Date(row.ends_at) : null,
  }
  errors.value = {}
  dialogVisible.value = true
}

/** 'YYYY-MM-DD HH:MM:SS' in local time — what the API's date rules accept. */
function toApiDate(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:00`
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: any = {
      ...form.value,
      code: String(form.value.code || '').trim().toUpperCase(),
      currency: (form.value.currency || 'INR').toUpperCase(),
      plan_ids: form.value.plan_ids?.length ? form.value.plan_ids : null,
      starts_at: toApiDate(form.value.starts_at),
      ends_at: toApiDate(form.value.ends_at),
      duration_cycles: form.value.duration === 'repeating' ? form.value.duration_cycles : null,
      // A cap on a flat discount is meaningless — the amount already is the cap.
      max_discount_amount: form.value.discount_type === 'percent' ? form.value.max_discount_amount : null,
    }
    if (codeLocked.value) delete payload.code

    if (editing.value) {
      await centralHttp.put(`/billing/coupons/${editing.value.id}`, payload)
      toast.add({ severity: 'success', summary: 'Coupon updated', life: 2500 })
    } else {
      await centralHttp.post('/billing/coupons', payload)
      toast.add({ severity: 'success', summary: 'Coupon created', life: 2500 })
    }
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    }
    if (e.response?.data?.message) {
      toast.add({ severity: 'error', summary: 'Could not save', detail: e.response.data.message, life: 5000 })
    }
  } finally {
    saving.value = false
  }
}

async function toggle(row: any) {
  try {
    await centralHttp.post(`/billing/coupons/${row.id}/toggle`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not switch the coupon', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete ${row.code}?`,
    text: 'Only an unredeemed coupon can be deleted — a used one is part of your revenue records and should be deactivated instead.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/billing/coupons/${row.id}`)
    toast.add({ severity: 'success', summary: 'Coupon deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 5000 })
  }
}

// ---- Redemptions -------------------------------------------------------------
const redemptionsVisible = ref(false)
const redemptionsFor = ref<any>(null)
const redemptions = ref<any[]>([])
const redemptionSummary = ref<any>({})
const redemptionsLoading = ref(false)

async function openRedemptions(row: any) {
  redemptionsFor.value = row
  redemptions.value = []
  redemptionSummary.value = {}
  redemptionsVisible.value = true
  redemptionsLoading.value = true
  try {
    const { data } = await centralHttp.get(`/billing/coupons/${row.id}/redemptions`)
    redemptions.value = data.data ?? []
    redemptionSummary.value = data.summary ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load redemptions', life: 4000 })
  } finally {
    redemptionsLoading.value = false
  }
}

function stateTag(row: any): { value: string; severity: string } {
  if (!row.is_active) return { value: 'Inactive', severity: 'secondary' }
  if (row.is_exhausted) return { value: 'Used up', severity: 'warn' }
  if (!row.is_live) return { value: 'Not live', severity: 'warn' }
  return { value: 'Live', severity: 'success' }
}

function planNames(ids: number[]): string {
  if (!ids?.length) return 'All plans'
  return plans.value.filter((p) => ids.includes(p.id)).map((p) => p.name).join(', ') || 'All plans'
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <div class="filters">
        <InputText v-model="search" placeholder="Search code / name…" class="search" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
          placeholder="All coupons" showClear class="status-filter" />
      </div>
      <Button v-if="canCreate" label="New Coupon" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm"
      lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[20, 50, 100]" @page="onPage">
      <Column header="Code">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="code">{{ data.code }}</span>
            <span class="muted">{{ data.name }}</span>
          </div>
        </template>
      </Column>
      <Column header="Discount">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="strong">
              {{ data.discount_type === 'percent' ? fmtPercent(data.discount_value) : fmtMoney(data.discount_value, data.currency) }} off
            </span>
            <span class="muted">
              {{ data.duration === 'repeating' ? `${data.duration_cycles} cycles` : data.duration === 'forever' ? 'every invoice' : 'first invoice' }}
              <template v-if="data.max_discount_amount"> · max {{ fmtMoney(data.max_discount_amount, data.currency) }}</template>
            </span>
          </div>
        </template>
      </Column>
      <Column header="Applies to">
        <template #body="{ data }">
          <div class="cell-2line">
            <span class="cap">{{ data.applies_to === 'both' ? 'plans & packs' : data.applies_to === 'pack' ? 'packs' : 'plans' }}</span>
            <span class="muted ellipsis" :title="planNames(data.plan_ids)">{{ planNames(data.plan_ids) }}</span>
          </div>
        </template>
      </Column>
      <Column header="Used" style="width: 8rem">
        <template #body="{ data }">
          <Button link class="usage-link" @click="openRedemptions(data)">
            {{ data.redemption_count }}<span class="muted"> / {{ data.max_redemptions ?? '∞' }}</span>
          </Button>
        </template>
      </Column>
      <Column header="Window" style="width: 11rem">
        <template #body="{ data }">
          <span class="muted-sm">
            {{ data.starts_at ? fmtDate(data.starts_at) : 'now' }} → {{ data.ends_at ? fmtDate(data.ends_at) : 'no end' }}
          </span>
        </template>
      </Column>
      <Column header="State" style="width: 7rem">
        <template #body="{ data }">
          <Tag :value="stateTag(data).value" :severity="stateTag(data).severity" />
          <Tag v-if="data.new_tenants_only" value="New only" severity="info" class="tag-2" />
        </template>
      </Column>
      <Column header="Actions" style="width: 9rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canEdit" :icon="data.is_active ? 'pi pi-pause' : 'pi pi-play'" text rounded size="small"
            :severity="data.is_active ? 'warn' : 'success'"
            :title="data.is_active ? 'Deactivate' : 'Activate'" @click="toggle(data)" />
          <Button v-if="canDelete && !data.redemption_count" icon="pi pi-trash" text rounded severity="danger" size="small"
            title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No coupons yet.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? `Edit ${editing.code}` : 'New Coupon'" :style="{ width: '680px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>Code <span class="req">*</span></label>
          <InputText v-model="form.code" :disabled="codeLocked" placeholder="LAUNCH50"
            :class="{ invalid: fieldError('code') }" @input="form.code = String(form.code).toUpperCase()" />
          <small v-if="codeLocked" class="hint">Already redeemed — the code is fixed.</small>
          <small v-if="fieldError('code')" class="error-text">{{ fieldError('code') }}</small>
        </div>
        <div class="form-field">
          <label>Internal name <span class="req">*</span></label>
          <InputText v-model="form.name" placeholder="Launch promo — 50% off" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="form-field">
          <label>Discount type <span class="req">*</span></label>
          <Select v-model="form.discount_type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label>{{ form.discount_type === 'percent' ? 'Percentage off' : 'Amount off' }} <span class="req">*</span></label>
          <InputNumber v-model="form.discount_value" :min="0.01" :max="form.discount_type === 'percent' ? 100 : undefined"
            :minFractionDigits="0" :maxFractionDigits="2" :suffix="form.discount_type === 'percent' ? ' %' : undefined"
            :class="{ invalid: fieldError('discount_value') }" />
          <small v-if="fieldError('discount_value')" class="error-text">{{ fieldError('discount_value') }}</small>
        </div>

        <div v-if="form.discount_type === 'percent'" class="form-field">
          <label>Cap the discount at</label>
          <InputNumber v-model="form.max_discount_amount" :min="0.01" :minFractionDigits="0" :maxFractionDigits="2" />
          <small class="hint">Optional — "20% off, up to ₹2,000".</small>
        </div>
        <div class="form-field">
          <label>Minimum order value</label>
          <InputNumber v-model="form.min_amount" :min="0" :minFractionDigits="0" :maxFractionDigits="2" />
          <small class="hint">Checked against the pre-tax amount.</small>
        </div>

        <div class="form-field">
          <label>How long it applies <span class="req">*</span></label>
          <Select v-model="form.duration" :options="durationOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label v-if="form.duration === 'repeating'">Number of cycles <span class="req">*</span></label>
          <InputNumber v-if="form.duration === 'repeating'" v-model="form.duration_cycles" :min="1" :max="120" showButtons
            :class="{ invalid: fieldError('duration_cycles') }" />
          <small v-if="fieldError('duration_cycles')" class="error-text">{{ fieldError('duration_cycles') }}</small>
        </div>

        <div class="form-field">
          <label>Spendable on <span class="req">*</span></label>
          <Select v-model="form.applies_to" :options="appliesOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label>Restrict to plans</label>
          <MultiSelect v-model="form.plan_ids" :options="plans" optionLabel="name" optionValue="id"
            placeholder="All plans" display="chip" :maxSelectedLabels="2" />
        </div>

        <div class="form-field">
          <label>Total redemptions</label>
          <InputNumber v-model="form.max_redemptions" :min="1" showButtons
            :class="{ invalid: fieldError('max_redemptions') }" />
          <small class="hint">Empty = unlimited.<template v-if="editing"> {{ editing.redemption_count }} already used.</template></small>
          <small v-if="fieldError('max_redemptions')" class="error-text">{{ fieldError('max_redemptions') }}</small>
        </div>
        <div class="form-field">
          <label>Per workspace <span class="req">*</span></label>
          <InputNumber v-model="form.max_redemptions_per_tenant" :min="1" :max="100" showButtons />
        </div>

        <div class="form-field">
          <label>Starts</label>
          <DatePicker v-model="form.starts_at" showIcon showTime hourFormat="24" dateFormat="yy-mm-dd" />
          <small class="hint">Empty = live immediately.</small>
        </div>
        <div class="form-field">
          <label>Ends</label>
          <DatePicker v-model="form.ends_at" showIcon showTime hourFormat="24" dateFormat="yy-mm-dd"
            :class="{ invalid: fieldError('ends_at') }" />
          <small v-if="fieldError('ends_at')" class="error-text">{{ fieldError('ends_at') }}</small>
        </div>

        <div class="form-field full">
          <label>Description</label>
          <Textarea v-model="form.description" rows="2" autoResize placeholder="Shown to operators — not to customers." />
        </div>

        <div class="form-field full">
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="cp-active" />
            <label for="cp-active">Active</label>
            <Checkbox v-model="form.new_tenants_only" binary inputId="cp-new" class="second" />
            <label for="cp-new">First subscription only</label>
          </div>
          <small class="hint">"First subscription only" refuses the code to any workspace that has already paid us.</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog v-model:visible="redemptionsVisible" modal
      :header="redemptionsFor ? `${redemptionsFor.code} — redemptions` : 'Redemptions'" :style="{ width: '640px' }">
      <div class="redemption-summary">
        <div><span class="muted">Redeemed</span><strong>{{ redemptionSummary.redemption_count ?? 0 }}</strong></div>
        <div><span class="muted">Discount given</span><strong>{{ fmtMoney(redemptionSummary.total_discount, redemptionsFor?.currency) }}</strong></div>
      </div>
      <DataTable :value="redemptions" :loading="redemptionsLoading" class="p-datatable-sm" :rows="10" paginator>
        <Column header="Workspace">
          <template #body="{ data }">{{ data.tenant_name ?? `#${data.tenant_id}` }}</template>
        </Column>
        <Column header="Discount">
          <template #body="{ data }">{{ fmtMoney(data.discount_amount, data.currency) }}</template>
        </Column>
        <Column header="Invoice" style="width: 6rem">
          <template #body="{ data }">
            <span class="muted-sm">{{ data.subscription_invoice_id ? `#${data.subscription_invoice_id}` : '—' }}</span>
          </template>
        </Column>
        <Column header="When" style="width: 8rem">
          <template #body="{ data }">{{ fmtDate(data.redeemed_at) }}</template>
        </Column>
        <template #empty>Not redeemed yet.</template>
      </DataTable>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.search { width: 220px; }
.status-filter { width: 150px; }
.code { font-family: ui-monospace, monospace; font-weight: 600; letter-spacing: 0.02em; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.muted-sm { color: #9ca3af; font-size: 0.8rem; }
.cap { text-transform: capitalize; }
.cell-2line { display: flex; flex-direction: column; }
.ellipsis { max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.usage-link { padding: 0; font-size: 0.85rem; }
.tag-2 { margin-left: 0.25rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(.p-select),
.form-field :deep(.p-multiselect), .form-field :deep(.p-datepicker), .form-field :deep(textarea) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.checkbox-row .second { margin-left: 1rem; }
.redemption-summary { display: flex; gap: 2rem; margin-bottom: 0.85rem; }
.redemption-summary div { display: flex; flex-direction: column; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
