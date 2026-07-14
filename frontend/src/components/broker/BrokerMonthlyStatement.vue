<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import http from '../../api/http'
import { formatCurrency } from '../../utils/currency'

const props = defineProps<{ brokerId: number | null }>()

const month = ref(new Date().toISOString().slice(0, 7))
const data = ref<any>(null)
const loading = ref(false)

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  if (!props.brokerId) {
    data.value = null
    return
  }
  loading.value = true
  try {
    const res = await http.get(`/brokers/${props.brokerId}/statement`, { params: { month: month.value } })
    data.value = res.data
  } finally {
    loading.value = false
  }
}

watch(() => props.brokerId, load)
watch(month, load)
onMounted(load)

defineExpose({ month })
</script>

<template>
  <div>
    <div class="toolbar">
      <label>Month</label>
      <input type="month" v-model="month" class="month-input" />
    </div>

    <div v-if="data" class="tiles">
      <div class="tile">
        <span class="tile-label">Opening Balance</span>
        <span class="tile-value">{{ money(data.opening_balance) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Commission Earned</span>
        <span class="tile-value">{{ money(data.summary.commission_earned) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">Settled</span>
        <span class="tile-value">{{ money(data.summary.settled) }}</span>
      </div>
      <div class="tile">
        <span class="tile-label">TDS Withheld</span>
        <span class="tile-value">{{ money(data.summary.tds_withheld) }}</span>
      </div>
      <div class="tile closing">
        <span class="tile-label">Closing Balance</span>
        <span class="tile-value">{{ money(data.summary.closing_balance) }}</span>
      </div>
    </div>

    <h4 class="sec">Commissions</h4>
    <DataTable :value="data?.commissions ?? []" :loading="loading" class="p-datatable-sm" dataKey="invoice_number">
      <Column field="date" header="Date" />
      <Column header="Type">
        <template #body="{ data: r }"><Tag :value="r.type" :severity="r.type === 'reversal' ? 'danger' : 'info'" /></template>
      </Column>
      <Column field="invoice_number" header="Invoice" />
      <Column header="Base"><template #body="{ data: r }">{{ money(r.base_amount) }}</template></Column>
      <Column header="Commission"><template #body="{ data: r }">{{ money(r.commission_amount) }}</template></Column>
      <template #empty>No commission activity this month.</template>
    </DataTable>

    <h4 class="sec">Settlements</h4>
    <DataTable :value="data?.settlements ?? []" :loading="loading" class="p-datatable-sm" dataKey="settlement_number">
      <Column field="date" header="Date" />
      <Column field="settlement_number" header="Number" />
      <Column field="mode" header="Mode" />
      <Column header="Gross"><template #body="{ data: r }">{{ money(r.amount) }}</template></Column>
      <Column header="TDS"><template #body="{ data: r }">{{ money(r.tds_amount) }}</template></Column>
      <Column header="Net Paid"><template #body="{ data: r }">{{ money(r.net_paid) }}</template></Column>
      <template #empty>No settlements this month.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
.month-input { padding: 0.45rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; }
.tiles { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.tile { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.7rem 0.9rem; display: flex; flex-direction: column; gap: 0.3rem; }
.tile.closing { background: #eff6ff; border-color: #bfdbfe; }
.tile-label { font-size: 0.75rem; color: #64748b; }
.tile-value { font-size: 1.1rem; font-weight: 600; color: #0f172a; }
.sec { margin: 1.25rem 0 0.5rem; font-size: 0.95rem; }
</style>
