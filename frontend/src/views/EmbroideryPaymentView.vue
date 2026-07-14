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
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()

const vendors = ref<{ label: string; value: number }[]>([])
const filterVendor = ref<number | null>(null)
const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const outstanding = ref<number | null>(null)
const form = ref<{ embroidery_vendor_id: number | null; amount: number | null; mode: string; payment_date: Date | null; notes: string }>({
  embroidery_vendor_id: null,
  amount: null,
  mode: 'cash',
  payment_date: new Date(),
  notes: '',
})

const modeOptions = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank', value: 'bank' },
  { label: 'UPI', value: 'upi' },
  { label: 'Adjustment', value: 'adjustment' },
]

const canPay = computed(() => auth.can('embroidery-payments.create'))

function money(v: any) {
  return formatCurrency(v)
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function loadVendors() {
  const { data } = await http.get('/embroidery-vendors', { params: { per_page: 100, is_active: true } })
  vendors.value = (data.data ?? data).map((t: any) => ({ label: `${t.code} — ${t.name}`, value: t.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/embroidery-payments', {
      params: { embroidery_vendor_id: filterVendor.value || undefined, page: page.value, per_page: perPage.value },
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

function openPay() {
  form.value = { embroidery_vendor_id: filterVendor.value, amount: null, mode: 'cash', payment_date: new Date(), notes: '' }
  outstanding.value = null
  errors.value = {}
  dialogVisible.value = true
  if (form.value.embroidery_vendor_id) fetchOutstanding(form.value.embroidery_vendor_id)
}

async function fetchOutstanding(vendorId: number) {
  try {
    const { data } = await http.get(`/embroidery-vendors/${vendorId}`)
    outstanding.value = Number(data.data?.outstanding_balance ?? data.outstanding_balance ?? 0)
  } catch {
    outstanding.value = null
  }
}

async function savePayment() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      embroidery_vendor_id: form.value.embroidery_vendor_id,
      amount: form.value.amount,
      mode: form.value.mode,
      payment_date: form.value.payment_date ? form.value.payment_date.toISOString().slice(0, 10) : undefined,
      notes: form.value.notes || undefined,
    }
    await http.post('/embroidery-payments', payload)
    toast.add({ severity: 'success', summary: 'Payment recorded', life: 2500 })
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadVendors()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Embroidery Payments</h1>
      <Button v-if="canPay" label="Record Payment" icon="pi pi-wallet" @click="openPay" />
    </div>

    <div class="toolbar">
      <Select v-model="filterVendor" :options="vendors" optionLabel="label" optionValue="value"
        placeholder="All vendors" showClear filter class="vendor-select" @change="() => { page = 1; load() }" />
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
      <Column field="payment_number" header="Payment #" />
      <Column field="payment_date" header="Date" />
      <Column field="vendor_name" header="Vendor" />
      <Column header="Mode">
        <template #body="{ data }"><Tag :value="data.mode" severity="info" /></template>
      </Column>
      <Column header="Amount">
        <template #body="{ data }">{{ money(data.amount) }}</template>
      </Column>
      <Column field="notes" header="Notes" />
      <template #empty>No payments recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal header="Record Payment" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>Vendor <span class="req">*</span></label>
          <Select v-model="form.embroidery_vendor_id" :options="vendors" optionLabel="label" optionValue="value" filter
            placeholder="Select vendor" :class="{ invalid: fieldError('embroidery_vendor_id') }"
            @change="(e:any) => fetchOutstanding(e.value)" />
          <small v-if="fieldError('embroidery_vendor_id')" class="error-text">{{ fieldError('embroidery_vendor_id') }}</small>
          <small v-else-if="outstanding !== null" class="hint">Balance due: {{ money(outstanding) }}</small>
        </div>
        <div class="field">
          <label>Amount <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" :min="0"
            :class="{ invalid: fieldError('amount') }" />
          <small v-if="fieldError('amount')" class="error-text">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>Mode <span class="req">*</span></label>
          <Select v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="field">
          <label>Payment Date</label>
          <DatePicker v-model="form.payment_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" :loading="saving" @click="savePayment" />
      </template>
    </Dialog>
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
.vendor-select {
  min-width: 22rem;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
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
.req {
  color: #dc2626;
}
.hint {
  color: #64748b;
  font-size: 0.78rem;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
