<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney } from './shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('plans.create'))
const canEdit = computed(() => auth.can('plans.edit'))
const canDelete = computed(() => auth.can('plans.delete'))

const plans = ref<any[]>([])
const loading = ref(false)

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({})
const limits = ref<{ key: string; value: string }[]>([])
const errors = ref<Record<string, string[]>>({})

const cycleOptions = [
  { label: 'Monthly', value: 'monthly' },
  { label: 'Yearly', value: 'yearly' },
]

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/plans')
    plans.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load plans', life: 4000 })
  } finally {
    loading.value = false
  }
}

function blankForm() {
  return {
    name: '', slug: '', price: 0, billing_cycle: 'monthly', currency: 'INR',
    is_custom: false, is_active: true, sort_order: plans.value.length,
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  limits.value = [{ key: '', value: '' }]
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    name: row.name,
    slug: row.slug,
    price: Number(row.price),
    billing_cycle: row.billing_cycle,
    currency: row.currency ?? 'INR',
    is_custom: !!row.is_custom,
    is_active: !!row.is_active,
    sort_order: row.sort_order ?? 0,
  }
  limits.value = Object.entries(row.feature_limits ?? {}).map(([key, value]) => ({
    key,
    value: value === null ? '' : String(value),
  }))
  if (!limits.value.length) limits.value = [{ key: '', value: '' }]
  errors.value = {}
  dialogVisible.value = true
}

function addLimit() {
  limits.value.push({ key: '', value: '' })
}

function removeLimit(idx: number) {
  limits.value.splice(idx, 1)
}

/** 'null'/'' → null (unlimited), 'true'/'false' → boolean, numeric → number, else string. */
function parseLimitValue(raw: string): any {
  const v = raw.trim()
  if (v === '' || v.toLowerCase() === 'null') return null
  if (v.toLowerCase() === 'true') return true
  if (v.toLowerCase() === 'false') return false
  if (/^-?\d+(\.\d+)?$/.test(v)) return Number(v)
  return v
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const featureLimits: Record<string, any> = {}
    for (const row of limits.value) {
      const key = row.key.trim()
      if (key) featureLimits[key] = parseLimitValue(row.value)
    }
    const payload = {
      ...form.value,
      currency: (form.value.currency || 'INR').toUpperCase(),
      feature_limits: Object.keys(featureLimits).length ? featureLimits : null,
    }
    if (editing.value) {
      await centralHttp.put(`/billing/plans/${editing.value.id}`, payload)
      toast.add({ severity: 'success', summary: 'Plan updated', life: 2500 })
    } else {
      await centralHttp.post('/billing/plans', payload)
      toast.add({ severity: 'success', summary: 'Plan created', life: 2500 })
    }
    dialogVisible.value = false
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

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete plan "${row.name}"?`,
    text: 'Plans with subscriptions cannot be deleted — deactivate those instead.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/billing/plans/${row.id}`)
    toast.add({ severity: 'success', summary: 'Plan deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4500 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <span class="hint">The public signup page lists active plans only; inactive plans are hidden but keep their history.</span>
      <Button v-if="canCreate" label="New Plan" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="plans" :loading="loading" dataKey="id" class="p-datatable-sm" paginator :rows="15" :rowsPerPageOptions="[15, 30, 50]">
      <Column field="name" header="Plan" sortable>
        <template #body="{ data }">
          <div class="plan-cell">
            <span class="plan-name">{{ data.name }}</span>
            <span class="muted">{{ data.slug }}</span>
          </div>
        </template>
      </Column>
      <Column header="Price" sortable sortField="price">
        <template #body="{ data }">
          <template v-if="data.is_custom"><Tag value="Custom pricing" severity="info" /></template>
          <template v-else>{{ fmtMoney(data.price, data.currency) }} <span class="muted">/ {{ data.billing_cycle === 'yearly' ? 'yr' : 'mo' }}</span></template>
        </template>
      </Column>
      <Column header="Subscriptions" style="width: 9rem">
        <template #body="{ data }">
          {{ data.active_subscriptions_count }} <span class="muted">live / {{ data.subscriptions_count }} total</span>
        </template>
      </Column>
      <Column header="Limits">
        <template #body="{ data }">
          <div class="limit-tags">
            <span v-for="(v, k) in data.feature_limits ?? {}" :key="k" class="limit-chip">
              {{ k }}: {{ v === null ? '∞' : v }}
            </span>
            <span v-if="!Object.keys(data.feature_limits ?? {}).length" class="muted">—</span>
          </div>
        </template>
      </Column>
      <Column field="sort_order" header="Order" sortable style="width: 6rem" />
      <Column header="Active" style="width: 6rem">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'secondary'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No plans found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Plan' : 'New Plan'" :style="{ width: '560px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="form-field">
          <label>Slug <span class="req">*</span></label>
          <InputText v-model="form.slug" :class="{ invalid: fieldError('slug') }" />
          <small v-if="fieldError('slug')" class="error-text">{{ fieldError('slug') }}</small>
        </div>
        <div class="form-field">
          <label>Price <span class="req">*</span></label>
          <InputNumber v-model="form.price" :minFractionDigits="0" :maxFractionDigits="2" :min="0" :class="{ invalid: fieldError('price') }" />
          <small v-if="fieldError('price')" class="error-text">{{ fieldError('price') }}</small>
        </div>
        <div class="form-field">
          <label>Billing cycle <span class="req">*</span></label>
          <Select v-model="form.billing_cycle" :options="cycleOptions" optionLabel="label" optionValue="value" />
          <small v-if="fieldError('billing_cycle')" class="error-text">{{ fieldError('billing_cycle') }}</small>
        </div>
        <div class="form-field">
          <label>Currency</label>
          <InputText v-model="form.currency" maxlength="3" :class="{ invalid: fieldError('currency') }" />
          <small v-if="fieldError('currency')" class="error-text">{{ fieldError('currency') }}</small>
        </div>
        <div class="form-field">
          <label>Sort order</label>
          <InputNumber v-model="form.sort_order" :min="0" showButtons />
        </div>
        <div class="form-field">
          <label>Flags</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="plan-active" />
            <label for="plan-active">Active</label>
            <Checkbox v-model="form.is_custom" binary inputId="plan-custom" class="second" />
            <label for="plan-custom">Custom pricing</label>
          </div>
        </div>

        <div class="form-field full">
          <label>Feature limits</label>
          <small class="hint">Leave a value empty (or "null") for unlimited. Numbers, true/false and text are supported — e.g. max_users = 10, api_access = true.</small>
          <div v-for="(row, idx) in limits" :key="idx" class="limit-row">
            <InputText v-model="row.key" placeholder="key (e.g. max_users)" class="limit-key" />
            <InputText v-model="row.value" placeholder="value (empty = unlimited)" class="limit-value" />
            <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLimit(idx)" />
          </div>
          <Button label="Add limit" icon="pi pi-plus" text size="small" class="add-limit" @click="addLimit" />
          <small v-if="fieldError('feature_limits')" class="error-text">{{ fieldError('feature_limits') }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.plan-cell { display: flex; flex-direction: column; }
.plan-name { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.limit-tags { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.limit-chip { background: #f3f4f6; border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.72rem; color: #374151; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(.p-select) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.checkbox-row .second { margin-left: 1rem; }
.limit-row { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.35rem; }
.limit-key { flex: 2; }
.limit-value { flex: 3; }
.add-limit { align-self: flex-start; margin-top: 0.35rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
