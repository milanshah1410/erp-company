<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { fmtMoney } from '../billing/shared'
import { SERIES, STATUS, fmtNumber, fmtPct } from '../analytics/shared'

const toast = useToast()
const loading = ref(false)
const data = ref<any>(null)
const threshold = ref(0.8)

const thresholdOptions = [
  { label: 'At 70% of a cap', value: 0.7 },
  { label: 'At 80% of a cap', value: 0.8 },
  { label: 'At 90% of a cap', value: 0.9 },
  { label: 'Only at the cap', value: 1.0 },
]

async function load() {
  loading.value = true
  try {
    const res = await centralHttp.get('/insights/upsell', { params: { threshold: threshold.value } })
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load upsell candidates', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const rows = computed<any[]>(() => data.value?.rows ?? [])

/** A resource over its cap is a different state from one merely near it. */
function pressureColor(p: any): string {
  return p.exceeded ? STATUS.critical : p.pct >= 95 ? STATUS.serious : SERIES[1]
}

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        Paying workspaces pressing against the caps of the plan they are on, matched to the
        cheapest tier that would actually clear the pressure. Trials and cancelled accounts
        are excluded — conversion and win-back are different motions.
      </p>
      <div class="controls">
        <Select v-model="threshold" :options="thresholdOptions" optionLabel="label" optionValue="value"
          class="w-13" @change="load" />
        <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
      </div>
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Checking utilisation…</div>

    <template v-else-if="data">
      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Candidates</span>
          <span class="tile-value">{{ fmtNumber(data.candidates) }}</span>
          <span class="tile-note">at or above {{ data.threshold_pct }}% of a cap</span>
        </div>
        <div class="tile">
          <span class="tile-label">Potential uplift</span>
          <span class="tile-value">{{ fmtMoney(data.potential_uplift_mrr, currency) }}</span>
          <span class="tile-note ok">added MRR if every offer lands</span>
        </div>
        <div class="tile">
          <span class="tile-label">Annualised</span>
          <span class="tile-value">{{ fmtMoney(data.potential_uplift_mrr * 12, currency) }}</span>
          <span class="tile-note">uplift × 12</span>
        </div>
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
        <Column field="current_plan" header="On" style="width: 10rem">
          <template #body="{ data: row }">
            <div class="stack">
              <span>{{ row.current_plan }}</span>
              <span class="muted">{{ fmtMoney(row.current_mrr, currency) }}/mo</span>
            </div>
          </template>
        </Column>
        <Column header="Pressure" style="min-width: 15rem">
          <template #body="{ data: row }">
            <div class="pressure-list">
              <div v-for="p in row.pressure" :key="p.resource" class="pressure">
                <span class="pressure-label">
                  {{ p.label }}
                  <strong class="num">{{ fmtNumber(p.used) }}/{{ fmtNumber(p.limit) }}</strong>
                  <Tag v-if="p.exceeded" value="over cap" severity="danger" class="mini" />
                </span>
                <span class="meter">
                  <span :style="{ width: `${Math.min(100, p.pct)}%`, background: pressureColor(p) }" />
                </span>
              </div>
            </div>
          </template>
        </Column>
        <Column field="max_utilisation_pct" header="Peak" sortable style="width: 7rem">
          <template #body="{ data: row }"><span class="num">{{ fmtPct(row.max_utilisation_pct, 0) }}</span></template>
        </Column>
        <Column header="Recommendation" style="width: 20rem">
          <template #body="{ data: row }">
            <div class="stack">
              <span class="action">{{ row.action }}</span>
              <span v-if="row.uplift_mrr" class="uplift">+{{ fmtMoney(row.uplift_mrr, currency) }}/mo</span>
            </div>
          </template>
        </Column>
        <template #empty>
          No workspace is near its caps at this threshold. Lower it to see who is approaching one.
        </template>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.w-13 { width: 13rem; }
.pressure-list { display: flex; flex-direction: column; gap: 0.4rem; }
.pressure { display: flex; flex-direction: column; gap: 0.2rem; }
.pressure-label { font-size: 0.82rem; color: #374151; display: flex; align-items: center; gap: 0.4rem; }
.mini :deep(.p-tag-label) { font-size: 0.64rem; }
.action { font-size: 0.85rem; color: #374151; }
.uplift { font-size: 0.8rem; color: #15803d; font-weight: 600; }
</style>
