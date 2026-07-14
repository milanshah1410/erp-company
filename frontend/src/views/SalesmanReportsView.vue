<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import ProgressBar from 'primevue/progressbar'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const now = new Date()
const MONTHS = [
  { label: 'Jan', value: 1 }, { label: 'Feb', value: 2 }, { label: 'Mar', value: 3 }, { label: 'Apr', value: 4 },
  { label: 'May', value: 5 }, { label: 'Jun', value: 6 }, { label: 'Jul', value: 7 }, { label: 'Aug', value: 8 },
  { label: 'Sep', value: 9 }, { label: 'Oct', value: 10 }, { label: 'Nov', value: 11 }, { label: 'Dec', value: 12 },
]
const YEARS = Array.from({ length: 6 }, (_, i) => ({ label: String(now.getFullYear() - 3 + i), value: now.getFullYear() - 3 + i }))

const TABS = [
  { label: 'Incentive', value: 'incentive' },
  { label: 'Visit Compliance', value: 'compliance' },
]
const tab = ref('incentive')

// Incentive
const incLoading = ref(false)
const incRows = ref<any[]>([])
const incSummary = ref<any>(null)
const incPeriod = ref({ year: now.getFullYear(), month: now.getMonth() + 1 })

// Compliance
const compLoading = ref(false)
const compRows = ref<any[]>([])
const compRange = ref<{ from: Date; to: Date }>({
  from: new Date(now.getFullYear(), now.getMonth(), 1),
  to: new Date(now.getFullYear(), now.getMonth() + 1, 0),
})

function money(v: any) {
  return formatCurrency(v)
}
function toDateString(d: Date): string {
  return d.toISOString().slice(0, 10)
}
function achSeverity(pct: number) {
  if (pct >= 100) return 'success'
  if (pct >= 60) return 'warn'
  return 'danger'
}

async function loadIncentive() {
  incLoading.value = true
  try {
    const { data } = await http.get('/salesman-reports/incentive', {
      params: { year: incPeriod.value.year, month: incPeriod.value.month },
    })
    incRows.value = data.data ?? []
    incSummary.value = data.summary ?? null
  } finally {
    incLoading.value = false
  }
}

async function loadCompliance() {
  compLoading.value = true
  try {
    const { data } = await http.get('/salesman-reports/visit-compliance', {
      params: { date_from: toDateString(compRange.value.from), date_to: toDateString(compRange.value.to) },
    })
    compRows.value = data.data ?? []
  } finally {
    compLoading.value = false
  }
}

function refresh() {
  if (tab.value === 'incentive') loadIncentive()
  else loadCompliance()
}

const activeLoading = computed(() => (tab.value === 'incentive' ? incLoading.value : compLoading.value))

onMounted(loadIncentive)
</script>

<template>
  <div>
    <div class="header">
      <h1>Salesman Reports</h1>
      <SelectButton v-model="tab" :options="TABS" optionLabel="label" optionValue="value" :allowEmpty="false" @change="refresh" />
    </div>

    <!-- Incentive -->
    <template v-if="tab === 'incentive'">
      <div class="toolbar">
        <Select v-model="incPeriod.year" :options="YEARS" optionLabel="label" optionValue="value" @change="loadIncentive" />
        <Select v-model="incPeriod.month" :options="MONTHS" optionLabel="label" optionValue="value" @change="loadIncentive" />
        <Button label="Refresh" icon="pi pi-refresh" :loading="activeLoading" outlined @click="loadIncentive" />
      </div>

      <div v-if="incSummary" class="cards">
        <div class="stat"><span class="stat-label">Total Target</span><span class="stat-val">{{ money(incSummary.total_target) }}</span></div>
        <div class="stat"><span class="stat-label">Total Achieved</span><span class="stat-val">{{ money(incSummary.total_achieved) }}</span></div>
        <div class="stat accent"><span class="stat-label">Total Incentive</span><span class="stat-val">{{ money(incSummary.total_incentive) }}</span></div>
      </div>

      <DataTable :value="incRows" :loading="incLoading" dataKey="salesman_id" class="p-datatable-sm">
        <Column field="name" header="Salesman" />
        <Column field="code" header="Code" />
        <Column header="Target"><template #body="{ data }">{{ money(data.target_amount) }}</template></Column>
        <Column header="Achieved"><template #body="{ data }">{{ money(data.achieved_amount) }}</template></Column>
        <Column header="Achievement" style="min-width: 12rem">
          <template #body="{ data }">
            <div class="ach">
              <ProgressBar :value="Math.min(100, data.achievement_percent)" :showValue="false" style="height: 0.5rem" />
              <span class="ach-pct" :class="achSeverity(data.achievement_percent)">{{ data.achievement_percent.toFixed(0) }}%</span>
            </div>
          </template>
        </Column>
        <Column header="Orders"><template #body="{ data }">{{ data.achieved_orders }}</template></Column>
        <Column header="Incentive %"><template #body="{ data }">{{ Number(data.incentive_percent).toFixed(2) }}%</template></Column>
        <Column header="Incentive"><template #body="{ data }"><strong>{{ money(data.incentive_amount) }}</strong></template></Column>
        <Column header="Status">
          <template #body="{ data }"><Tag :severity="data.target_met ? 'success' : 'warn'" :value="data.target_met ? 'Met' : 'In progress'" /></template>
        </Column>
        <template #empty>No active salesmen.</template>
      </DataTable>
    </template>

    <!-- Visit compliance -->
    <template v-else>
      <div class="toolbar">
        <DatePicker v-model="compRange.from" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        <span class="to">to</span>
        <DatePicker v-model="compRange.to" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        <Button label="Run" icon="pi pi-search" @click="loadCompliance" />
      </div>

      <DataTable :value="compRows" :loading="compLoading" dataKey="salesman_id" class="p-datatable-sm">
        <Column field="salesman" header="Salesman" />
        <Column field="code" header="Code" />
        <Column header="Planned"><template #body="{ data }">{{ data.planned }}</template></Column>
        <Column header="Visited"><template #body="{ data }">{{ data.visited }}</template></Column>
        <Column header="Skipped"><template #body="{ data }">{{ data.skipped }}</template></Column>
        <Column header="Compliance" style="min-width: 12rem">
          <template #body="{ data }">
            <div class="ach">
              <ProgressBar :value="Math.min(100, data.compliance_percent)" :showValue="false" style="height: 0.5rem" />
              <span class="ach-pct" :class="achSeverity(data.compliance_percent)">{{ data.compliance_percent.toFixed(0) }}%</span>
            </div>
          </template>
        </Column>
        <template #empty>No visits in the selected range.</template>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  gap: 1rem;
  flex-wrap: wrap;
}
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  align-items: center;
  flex-wrap: wrap;
}
.to { color: #64748b; }
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
  margin-bottom: 1.25rem;
}
.stat {
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  background: #fff;
}
.stat.accent { border-color: #c7d2fe; background: #eef2ff; }
.stat-label { font-size: 0.8rem; color: #64748b; }
.stat-val { font-size: 1.35rem; font-weight: 700; color: #1e293b; }
.ach { display: flex; align-items: center; gap: 0.5rem; }
.ach :deep(.p-progressbar) { flex: 1; }
.ach-pct { font-size: 0.8rem; font-weight: 600; min-width: 2.5rem; text-align: right; }
.ach-pct.success { color: #16a34a; }
.ach-pct.warn { color: #d97706; }
.ach-pct.danger { color: #dc2626; }
</style>
