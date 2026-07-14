<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../../api/http'
import { useAuthStore } from '../../stores/auth'
import { formatCurrency } from '../../utils/currency'

const props = defineProps<{ brokerId: number | null }>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const toast = useToast()
const auth = useAuthStore()
const canManage = auth.can('brokers.create')

const txns = ref<any[]>([])
const balance = ref(0)
const loading = ref(false)

const typeSeverity: Record<string, string> = {
  commission: 'success',
  topup: 'success',
  reversal: 'danger',
  settlement: 'warn',
  advance: 'warn',
  adjustment: 'info',
}

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<{ type: string; direction: string; amount: number | null; notes: string }>({
  type: 'topup',
  direction: 'credit',
  amount: null,
  notes: '',
})
const typeOptions = [
  { label: 'Top-up (credit wallet)', value: 'topup' },
  { label: 'Advance paid (debit wallet)', value: 'advance' },
  { label: 'Adjustment', value: 'adjustment' },
]
const directionOptions = [
  { label: 'Credit (+)', value: 'credit' },
  { label: 'Debit (−)', value: 'debit' },
]

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  if (!props.brokerId) {
    txns.value = []
    balance.value = 0
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/brokers/${props.brokerId}/wallet`, { params: { per_page: 50 } })
    txns.value = data.data ?? data
    balance.value = data.balance ?? 0
  } finally {
    loading.value = false
  }
}

function openEntry() {
  form.value = { type: 'topup', direction: 'credit', amount: null, notes: '' }
  errors.value = {}
  dialogVisible.value = true
}

async function saveEntry() {
  if (!props.brokerId) return
  saving.value = true
  errors.value = {}
  try {
    const payload: any = { type: form.value.type, amount: form.value.amount, notes: form.value.notes || undefined }
    if (form.value.type === 'adjustment') payload.direction = form.value.direction
    await http.post(`/brokers/${props.brokerId}/wallet`, payload)
    toast.add({ severity: 'success', summary: 'Wallet updated', life: 2000 })
    dialogVisible.value = false
    await load()
    emit('changed')
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

watch(() => props.brokerId, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="wallet-head">
      <div class="balance-tile">
        <span class="bal-label">Wallet Balance</span>
        <span class="bal-value" :class="{ neg: balance < 0 }">{{ money(balance) }}</span>
      </div>
      <Button v-if="canManage && brokerId" label="Add Entry" icon="pi pi-plus" @click="openEntry" />
    </div>

    <DataTable :value="txns" :loading="loading" class="p-datatable-sm" dataKey="id">
      <Column field="created_at" header="Date">
        <template #body="{ data }">{{ (data.created_at || '').slice(0, 10) }}</template>
      </Column>
      <Column header="Type">
        <template #body="{ data }"><Tag :value="data.type" :severity="typeSeverity[data.type] || 'secondary'" /></template>
      </Column>
      <Column header="Amount">
        <template #body="{ data }">
          <span :class="Number(data.amount) < 0 ? 'debit' : 'credit'">{{ money(data.amount) }}</span>
        </template>
      </Column>
      <Column header="Balance">
        <template #body="{ data }">{{ money(data.balance_after) }}</template>
      </Column>
      <Column field="notes" header="Notes" />
      <template #empty>{{ brokerId ? 'No wallet transactions yet.' : 'Select a broker.' }}</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal header="Wallet Entry" :style="{ width: '440px' }">
      <div class="form">
        <div class="field">
          <label>Type</label>
          <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div v-if="form.type === 'adjustment'" class="field">
          <label>Direction</label>
          <Select v-model="form.direction" :options="directionOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="field">
          <label>Amount <span class="req">*</span></label>
          <InputNumber v-model="form.amount" mode="currency" currency="INR" locale="en-IN" />
          <small v-if="fieldError('amount')" class="err">{{ fieldError('amount') }}</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" :loading="saving" @click="saveEntry" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.wallet-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.balance-tile { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.5rem; padding: 0.6rem 1.1rem; display: flex; flex-direction: column; gap: 0.25rem; }
.bal-label { font-size: 0.78rem; color: #64748b; }
.bal-value { font-size: 1.4rem; font-weight: 700; color: #1d4ed8; }
.bal-value.neg { color: #dc2626; }
.credit { color: #047857; }
.debit { color: #dc2626; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.field :deep(.p-inputnumber), .field :deep(.p-select), .field :deep(.p-inputtext) { width: 100%; }
.req { color: #dc2626; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
