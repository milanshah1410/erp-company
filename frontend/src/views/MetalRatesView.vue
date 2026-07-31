<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
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

const canQuote = computed(() => auth.can('metal-rates.create'))
const canDelete = computed(() => auth.can('metal-rates.delete'))

const today = ref<any[]>([])
const history = ref<any[]>([])
const loading = ref(false)

const METALS = [
  { label: 'Gold', value: 'gold' },
  { label: 'Silver', value: 'silver' },
  { label: 'Platinum', value: 'platinum' },
]
// The purities a shop quotes by name; anything else can be typed and the
// counter supplies its own fineness.
const PURITIES = ['24K', '22K', '18K', '14K', '999', '925']

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/metal-rates', { params: { per_page: 100 } })
    history.value = data.data.data ?? data.data
    today.value = data.today
  } finally {
    loading.value = false
  }
}

// ---- Quote today's rate --------------------------------------------------------

const form = ref({
  metal: 'gold',
  purity: '22K',
  rate_per_gram: null as number | null,
  buy_rate_per_gram: null as number | null,
  fineness: null as number | null,
  effective_on: null as Date | null,
})
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

function iso(d: Date | null) {
  if (!d) return undefined
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function quote() {
  if (!form.value.rate_per_gram) return
  saving.value = true
  errors.value = {}
  try {
    await http.post('/metal-rates', {
      ...form.value,
      effective_on: iso(form.value.effective_on),
    })
    toast.add({ severity: 'success', summary: pt('Rate quoted'), life: 2500 })
    form.value.rate_per_gram = null
    form.value.buy_rate_per_gram = null
    form.value.fineness = null
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the rate'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Remove this rate?'),
    text: pt('Bills already raised keep the rate they were priced at.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/metal-rates/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Rate removed'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Metal Rates')"
      :subtitle="pt('The daily rate card — nothing in the shop can be priced until it is quoted')"
    />

    <!-- Today's card, big and first: this is the number the whole counter works from. -->
    <div class="card-row">
      <div v-for="r in today" :key="r.id" class="rate-card" :class="{ stale: !r.is_today }">
        <span class="rc-purity">{{ r.metal }} · {{ r.purity }}</span>
        <strong class="rc-rate">{{ money(r.rate_per_gram) }}<small>/g</small></strong>
        <span class="rc-buy">{{ pt('Buy') }} {{ money(r.effective_buy_rate) }}</span>
        <Tag
          v-if="!r.is_today"
          :value="pt('from') + ' ' + r.effective_on"
          severity="warn"
          class="rc-tag"
        />
      </div>
      <div v-if="!today.length" class="rate-empty">
        <i class="pi pi-exclamation-triangle" />
        {{ pt('No rate has ever been quoted. Jewellery items cannot be priced until you set one.') }}
      </div>
    </div>

    <!-- Quote -->
    <div v-if="canQuote" class="quote">
      <h3>{{ pt('Quote a rate') }}</h3>
      <div class="quote-row">
        <div class="fld">
          <label>{{ pt('Metal') }}</label>
          <Select v-model="form.metal" :options="METALS" optionLabel="label" optionValue="value" class="q-metal" />
        </div>
        <div class="fld">
          <label>{{ pt('Purity') }}</label>
          <Select v-model="form.purity" :options="PURITIES" editable class="q-purity" />
        </div>
        <div class="fld">
          <label>{{ pt('Selling rate / gram') }}</label>
          <InputNumber v-model="form.rate_per_gram" :min="0" :maxFractionDigits="2" class="q-rate" />
          <small v-if="errors.rate_per_gram" class="err">{{ errors.rate_per_gram[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Buy rate / gram') }}</label>
          <InputNumber v-model="form.buy_rate_per_gram" :min="0" :maxFractionDigits="2" class="q-rate" />
          <small class="hint">{{ pt('For old gold. Blank keeps the existing one.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Fineness') }}</label>
          <InputNumber v-model="form.fineness" :min="1" :max="1000" class="q-fine" />
          <small class="hint">{{ pt('Only for a non-standard purity.') }}</small>
          <small v-if="errors.fineness" class="err">{{ errors.fineness[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Effective on') }}</label>
          <DatePicker v-model="form.effective_on" dateFormat="yy-mm-dd" showButtonBar :placeholder="pt('Today')" class="q-date" />
        </div>
        <Button
          :label="pt('Quote')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!form.rate_per_gram"
          @click="quote"
        />
      </div>
      <p class="note">
        {{ pt('Re-quoting the same metal, purity and day replaces that rate — a shop has one rate per day, not two.') }}
      </p>
    </div>

    <h3 class="hist-title">{{ pt('Rate history') }}</h3>
    <div class="toolbar">
      <TableExportButtons
        title="Metal Rates"
        filenameBase="metal-rates"
        :rows="history"
        :columns="[
          { key: 'effective_on', label: 'Date' },
          { key: 'metal', label: 'Metal' },
          { key: 'purity', label: 'Purity' },
          { key: 'fineness', label: 'Fineness' },
          { key: 'rate_per_gram', label: 'Sell / g' },
          { key: 'effective_buy_rate', label: 'Buy / g' },
        ]"
      />
    </div>

    <DataTable :value="history" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column field="effective_on" :header="pt('Date')" />
      <Column :header="pt('Metal')">
        <template #body="{ data }">{{ data.metal }} · <strong>{{ data.purity }}</strong></template>
      </Column>
      <Column field="fineness" :header="pt('Fineness')" />
      <Column :header="pt('Selling / g')">
        <template #body="{ data }">{{ money(data.rate_per_gram) }}</template>
      </Column>
      <Column :header="pt('Buy / g')">
        <template #body="{ data }">
          {{ money(data.effective_buy_rate) }}
          <small v-if="data.buy_rate_per_gram === null" class="muted">({{ pt('same as selling') }})</small>
        </template>
      </Column>
      <Column style="width: 4rem">
        <template #body="{ data }">
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No rates quoted yet.') }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.card-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.rate-card {
  border: 1px solid #e8a33d;
  background: rgba(232, 163, 61, 0.1);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 150px;
}
.rate-card.stale { border-color: #d8d3c8; background: #fbfaf7; }
.rc-purity { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.rc-rate { font-size: 1.4rem; font-weight: 700; }
.rc-rate small { font-size: 0.8rem; font-weight: 500; color: #6b7280; }
.rc-buy { font-size: 0.75rem; color: #6b7280; }
.rc-tag { margin-top: 0.25rem; align-self: flex-start; }
.rate-empty {
  display: flex; align-items: center; gap: 0.5rem;
  border: 1px solid #f0b4b4; background: #fdf1f1; color: #8a2b2b;
  border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.85rem;
}
.quote { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem; }
.quote h3 { margin: 0 0 0.75rem; font-size: 1rem; font-weight: 600; }
.quote-row { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.q-metal { width: 120px; }
.q-purity { width: 110px; }
.q-rate { width: 140px; }
.q-fine { width: 110px; }
.q-date { width: 150px; }
.note { font-size: 0.75rem; color: #6b7280; margin: 0.75rem 0 0; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.hist-title { font-size: 1rem; font-weight: 600; margin: 0 0 0.6rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.muted { color: #6b7280; }
</style>
