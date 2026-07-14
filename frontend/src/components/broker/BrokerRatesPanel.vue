<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../../api/http'
import { useAuthStore } from '../../stores/auth'

const props = defineProps<{ brokerId: number | null }>()

const toast = useToast()
const auth = useAuthStore()
const canEdit = auth.can('brokers.edit')

const rates = ref<any[]>([])
const loading = ref(false)
const products = ref<{ label: string; value: number }[]>([])
const customers = ref<{ label: string; value: number }[]>([])

const scopeOptions = [
  { label: 'Product', value: 'product' },
  { label: 'Customer', value: 'customer' },
]

const form = ref<{ scope: string; product_id: number | null; customer_id: number | null; commission_percent: number | null }>({
  scope: 'product',
  product_id: null,
  customer_id: null,
  commission_percent: null,
})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

async function loadOptions() {
  const [p, c] = await Promise.all([
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 100 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.sku} — ${x.name}`, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
}

async function load() {
  if (!props.brokerId) {
    rates.value = []
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/brokers/${props.brokerId}/rates`)
    rates.value = data.data ?? data
  } finally {
    loading.value = false
  }
}

function resetForm() {
  form.value = { scope: 'product', product_id: null, customer_id: null, commission_percent: null }
  errors.value = {}
}

async function addRate() {
  if (!props.brokerId) return
  saving.value = true
  errors.value = {}
  try {
    await http.post(`/brokers/${props.brokerId}/rates`, form.value)
    toast.add({ severity: 'success', summary: 'Rate added', life: 2000 })
    resetForm()
    await load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function removeRate(row: any) {
  const res = await Swal.fire({ title: 'Delete this rate?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  await http.delete(`/brokers/${props.brokerId}/rates/${row.id}`)
  toast.add({ severity: 'success', summary: 'Rate deleted', life: 2000 })
  await load()
}

function targetLabel(row: any): string {
  if (row.scope === 'product') return row.product?.name ? `${row.product.sku} — ${row.product.name}` : `Product #${row.product_id}`
  return row.customer?.name ? `${row.customer.code} — ${row.customer.name}` : `Customer #${row.customer_id}`
}

watch(() => props.brokerId, load)
onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<template>
  <div>
    <p class="note">
      Overrides the broker's default commission for a specific product or customer.
      Resolution order at billing: <strong>product rate → customer rate → broker default</strong>.
    </p>

    <div v-if="canEdit && brokerId" class="add-row">
      <Select v-model="form.scope" :options="scopeOptions" optionLabel="label" optionValue="value" class="scope" />
      <Select v-if="form.scope === 'product'" v-model="form.product_id" :options="products" optionLabel="label" optionValue="value" filter placeholder="Select product" class="target" />
      <Select v-else v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter placeholder="Select customer" class="target" />
      <InputNumber v-model="form.commission_percent" :minFractionDigits="0" :maxFractionDigits="2" suffix=" %" placeholder="Commission %" class="pct" />
      <Button label="Add" icon="pi pi-plus" :loading="saving" @click="addRate" />
    </div>
    <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
    <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
    <small v-if="errors.commission_percent" class="err">{{ errors.commission_percent[0] }}</small>

    <DataTable :value="rates" :loading="loading" class="p-datatable-sm" dataKey="id">
      <Column header="Scope">
        <template #body="{ data }"><Tag :value="data.scope" :severity="data.scope === 'product' ? 'info' : 'warn'" /></template>
      </Column>
      <Column header="Target">
        <template #body="{ data }">{{ targetLabel(data) }}</template>
      </Column>
      <Column header="Commission %">
        <template #body="{ data }">{{ Number(data.commission_percent).toFixed(2) }} %</template>
      </Column>
      <Column v-if="canEdit" header="" style="width: 4rem">
        <template #body="{ data }">
          <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeRate(data)" />
        </template>
      </Column>
      <template #empty>{{ brokerId ? 'No rate overrides — the broker default applies.' : 'Select a broker.' }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.note { color: #64748b; font-size: 0.85rem; margin-bottom: 1rem; }
.add-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; }
.scope { min-width: 9rem; }
.target { min-width: 18rem; }
.pct { width: 10rem; }
.err { color: #dc2626; font-size: 0.78rem; display: block; }
</style>
