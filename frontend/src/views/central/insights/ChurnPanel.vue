<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { fmtMoney } from '../billing/shared'
import { bandSeverity, fmtNumber } from '../analytics/shared'

const toast = useToast()
const loading = ref(false)
const data = ref<any>(null)
const bandFilter = ref<string | null>(null)
const search = ref('')

const bandOptions = [
  { label: 'Critical', value: 'critical' },
  { label: 'High', value: 'high' },
  { label: 'Medium', value: 'medium' },
  { label: 'Low', value: 'low' },
]

async function load(fresh = false) {
  loading.value = true
  try {
    const res = await centralHttp.get('/insights/churn', { params: fresh ? { fresh: 1 } : {} })
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load churn insights', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')

const rows = computed<any[]>(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.rows ?? []).filter((r: any) => {
    if (bandFilter.value && r.risk_band !== bandFilter.value) return false
    if (q && !`${r.tenant_name} ${r.email}`.toLowerCase().includes(q)) return false
    return true
  })
})

// The score bar is a magnitude read, so it is one hue stepped by band rather
// than four unrelated colours; the Tag beside it carries the band name, so the
// band is never communicated by colour alone.
function scoreColor(band: string): string {
  return { critical: '#b91c1c', high: '#c2410c', medium: '#b45309', low: '#15803d' }[band] ?? '#6b7280'
}

onMounted(() => load())
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        Every live workspace scored 0–100 on the signals that precede a cancellation. The
        reasons are the deliverable — the score only decides the order to work them in.
        Already-cancelled accounts are excluded: they are the outcome, not a prediction.
      </p>
      <Button label="Recalculate" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load(true)" />
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Scoring tenants…</div>

    <template v-else-if="data">
      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Critical risk</span>
          <span class="tile-value">{{ fmtNumber(data.bands.critical) }}</span>
          <span class="tile-note danger">act today</span>
        </div>
        <div class="tile">
          <span class="tile-label">High risk</span>
          <span class="tile-value">{{ fmtNumber(data.bands.high) }}</span>
          <span class="tile-note warn">this week</span>
        </div>
        <div class="tile">
          <span class="tile-label">MRR at risk</span>
          <span class="tile-value">{{ fmtMoney(data.mrr_at_risk, currency) }}</span>
          <span class="tile-note">from critical + high</span>
        </div>
        <div class="tile">
          <span class="tile-label">Healthy</span>
          <span class="tile-value">{{ fmtNumber(data.bands.low) }}</span>
          <span class="tile-note ok">of {{ fmtNumber(data.evaluated) }} evaluated</span>
        </div>
        <div class="tile">
          <span class="tile-label">Already churned</span>
          <span class="tile-value">{{ fmtNumber(data.already_churned) }}</span>
          <span class="tile-note">cancelled, not scored</span>
        </div>
      </div>

      <div class="controls" style="margin-bottom: 0.75rem">
        <InputText v-model="search" placeholder="Search tenant…" class="w-14" />
        <Select v-model="bandFilter" :options="bandOptions" optionLabel="label" optionValue="value"
          placeholder="All risk bands" showClear class="w-12" />
        <span class="muted">{{ rows.length }} shown</span>
      </div>

      <DataTable :value="rows" :loading="loading" class="p-datatable-sm" dataKey="tenant_id" paginator :rows="15">
        <Column field="tenant_name" header="Tenant" sortable>
          <template #body="{ data: row }">
            <div class="stack">
              <span class="strong">{{ row.tenant_name }}</span>
              <span class="muted">{{ row.email }}</span>
            </div>
          </template>
        </Column>
        <Column field="risk_score" header="Risk" sortable style="width: 11rem">
          <template #body="{ data: row }">
            <div class="stack">
              <div class="score-row">
                <Tag :severity="bandSeverity[row.risk_band]" :value="row.risk_band" />
                <span class="num strong">{{ row.risk_score }}</span>
              </div>
              <span class="meter">
                <span :style="{ width: `${row.risk_score}%`, background: scoreColor(row.risk_band) }" />
              </span>
            </div>
          </template>
        </Column>
        <Column header="Why" style="min-width: 16rem">
          <template #body="{ data: row }">
            <ul v-if="row.reasons.length" class="reason-list">
              <li v-for="(r, i) in row.reasons" :key="i">{{ r }}</li>
            </ul>
            <span v-else class="muted">No warning signals</span>
          </template>
        </Column>
        <Column header="Next action" style="width: 18rem">
          <template #body="{ data: row }"><span class="action">{{ row.recommended_action }}</span></template>
        </Column>
        <Column field="plan" header="Plan" style="width: 9rem">
          <template #body="{ data: row }">
            <div class="stack">
              <span>{{ row.plan ?? '—' }}</span>
              <span class="muted">{{ row.subscription_status ?? 'no subscription' }}</span>
            </div>
          </template>
        </Column>
        <Column field="mrr_at_risk" header="MRR" sortable style="width: 9rem">
          <template #body="{ data: row }">{{ fmtMoney(row.mrr_at_risk, currency) }}</template>
        </Column>
        <template #empty>No tenants match.</template>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.score-row { display: flex; align-items: center; gap: 0.45rem; }
.reason-list { margin: 0; padding-left: 1rem; font-size: 0.82rem; color: #b45309; }
.reason-list li { margin-bottom: 0.1rem; }
.action { font-size: 0.84rem; color: #374151; }
.w-12 { width: 12rem; }
.w-14 { width: 14rem; }
</style>
