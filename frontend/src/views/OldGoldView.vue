<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('old-gold.create'))
const canDelete = computed(() => auth.can('old-gold.delete'))

const rows = ref<any[]>([])
const summary = ref({ weight: 0, amount: 0, unsettled: 0 })
const loading = ref(false)
const customers = ref<any[]>([])

const search = ref('')
const unsettledOnly = ref(false)

const METALS = [
  { label: 'Gold', value: 'gold' },
  { label: 'Silver', value: 'silver' },
  { label: 'Platinum', value: 'platinum' },
]
const PURITIES = ['24K', '22K', '18K', '14K', '999', '925']

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/old-gold', {
      params: {
        search: search.value || undefined,
        unsettled_only: unsettledOnly.value ? 1 : undefined,
        per_page: 50,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 200, is_active: true } })
  customers.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Take scrap in --------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  metal: 'gold',
  purity: '22K',
  description: '',
  gross_weight: null as number | null,
  net_weight: null as number | null,
  deduction_percent: 0,
  notes: '',
})
const form = ref(blank())
const quote = ref<any>(null)

function openCreate() {
  form.value = blank()
  quote.value = null
  errors.value = {}
  dialogVisible.value = true
}

/**
 * Value the piece as the counter types. The arithmetic stays on the server so
 * the number quoted to the customer is the number that gets booked.
 */
async function refreshQuote() {
  if (!form.value.net_weight || form.value.net_weight <= 0) {
    quote.value = null
    return
  }
  try {
    const { data } = await http.post('/old-gold/quote', {
      metal: form.value.metal,
      purity: form.value.purity,
      net_weight: form.value.net_weight,
      deduction_percent: form.value.deduction_percent || 0,
    })
    quote.value = data.data
  } catch (e: any) {
    quote.value = null
    errors.value = e.response?.data?.errors ?? {}
  }
}

let quoteTimer: ReturnType<typeof setTimeout>
watch(
  () => [form.value.metal, form.value.purity, form.value.net_weight, form.value.deduction_percent],
  () => {
    clearTimeout(quoteTimer)
    quoteTimer = setTimeout(refreshQuote, 300)
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/old-gold', form.value)
    toast.add({ severity: 'success', summary: pt('Old gold received'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the receipt'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this receipt?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/old-gold/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Receipt deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => {
  await loadCustomers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Old Gold')"
      :subtitle="pt('Scrap taken in from customers — weighed, valued at the buy rate, and set off against a bill')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Weight taken in') }}</span>
        <strong class="t-value">{{ summary.weight }}<small>g</small></strong>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value taken in') }}</span>
        <strong class="t-value">{{ money(summary.amount) }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">{{ pt('Unsettled credit') }}</span>
        <strong class="t-value">{{ money(summary.unsettled) }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Receipt no, customer or description')" @keyup.enter="load" />
      <label class="inline-check">
        <Checkbox v-model="unsettledOnly" binary @change="load" /> {{ pt('Unsettled only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('Receive Old Gold')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Old Gold"
        filenameBase="old-gold"
        :rows="rows"
        :columns="[
          { key: 'receipt_number', label: 'Receipt' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'purity', label: 'Purity' },
          { key: 'gross_weight', label: 'Gross' },
          { key: 'net_weight', label: 'Net' },
          { key: 'chargeable_weight', label: 'Chargeable' },
          { key: 'rate_per_gram', label: 'Rate/g' },
          { key: 'amount', label: 'Amount' },
          { key: 'received_on', label: 'Date' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Receipt')">
        <template #body="{ data }">
          <strong>{{ data.receipt_number }}</strong>
          <br /><small class="muted">{{ data.received_on }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">{{ data.customer?.name ?? pt('Walk-in') }}</template>
      </Column>
      <Column :header="pt('Item')">
        <template #body="{ data }">
          {{ data.metal }} · <strong>{{ data.purity }}</strong>
          <br /><small class="muted">{{ data.description || '—' }}</small>
        </template>
      </Column>
      <Column :header="pt('Weight')">
        <template #body="{ data }">
          {{ data.net_weight }}g {{ pt('net') }}
          <br />
          <small class="muted">
            {{ pt('gross') }} {{ data.gross_weight }}g
            <template v-if="data.deduction_percent"> · −{{ data.deduction_percent }}%</template>
          </small>
        </template>
      </Column>
      <Column :header="pt('Chargeable')">
        <template #body="{ data }">
          {{ data.chargeable_weight }}g
          <br /><small class="muted">@ {{ money(data.rate_per_gram) }}/g</small>
        </template>
      </Column>
      <Column :header="pt('Amount')">
        <template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.is_settled ? pt('Settled') : pt('Open credit')"
            :severity="data.is_settled ? 'contrast' : 'success'"
          />
          <br v-if="data.invoice" /><small v-if="data.invoice" class="muted">{{ data.invoice }}</small>
        </template>
      </Column>
      <Column style="width: 4rem">
        <template #body="{ data }">
          <Button
            v-if="canDelete && !data.is_settled"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No old gold received yet.') }}</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="pt('Receive Old Gold')" :style="{ width: '38rem' }">
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Walk-in')"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Metal') }}</label>
          <Select v-model="form.metal" :options="METALS" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Purity') }}</label>
          <Select v-model="form.purity" :options="PURITIES" editable class="w-full" />
          <small v-if="errors.purity" class="err">{{ errors.purity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Gross weight (g)') }} *</label>
          <InputNumber v-model="form.gross_weight" :min="0" :maxFractionDigits="3" class="w-full" />
          <small v-if="errors.gross_weight" class="err">{{ errors.gross_weight[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Net weight (g)') }} *</label>
          <InputNumber v-model="form.net_weight" :min="0" :maxFractionDigits="3" class="w-full" />
          <small class="hint">{{ pt('After stones and solder.') }}</small>
          <small v-if="errors.net_weight" class="err">{{ errors.net_weight[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Deduction %') }}</label>
          <InputNumber v-model="form.deduction_percent" :min="0" :max="100" :maxFractionDigits="3" suffix=" %" class="w-full" />
          <small class="hint">{{ pt('Touch / melting loss.') }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Description') }}</label>
          <InputText v-model="form.description" class="w-full" :placeholder="pt('e.g. Old bangle, 2 pieces')" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <div v-if="quote" class="quote-box">
        <div class="qb-line">
          <span>{{ pt('Chargeable weight') }}</span><strong>{{ quote.chargeable_weight }}g</strong>
        </div>
        <div class="qb-line">
          <span>{{ pt('Buy rate') }}</span><strong>{{ money(quote.rate_per_gram) }}/g</strong>
        </div>
        <div class="qb-line total">
          <span>{{ pt('Payable to customer') }}</span><strong>{{ money(quote.amount) }}</strong>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button
          :label="pt('Receive')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!quote"
          @click="save"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value small { font-size: 0.8rem; font-weight: 500; color: #6b7280; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.quote-box { margin-top: 1rem; border: 1px solid #e8a33d; background: rgba(232, 163, 61, 0.09); border-radius: 8px; padding: 0.6rem 0.8rem; }
.qb-line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.12rem 0; }
.qb-line.total { border-top: 1px solid rgba(232, 163, 61, 0.5); margin-top: 0.3rem; padding-top: 0.4rem; font-size: 1rem; }
</style>
