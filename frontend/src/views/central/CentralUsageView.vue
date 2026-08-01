<script setup lang="ts">
/**
 * Tenant Usage (/platform/usage) — Volume 11 §"Tenant Usage".
 *
 * Five metrics of two shapes, and the difference matters when reading them:
 *
 *   API calls, AI usage — counters, summed over the selected window
 *   Storage, Products, Customers — snapshots, i.e. where the workspace stands now
 *
 * So changing the window moves the first two and leaves the last three alone.
 * That is not a bug in the filter; "how many API calls in 90 days" and "how many
 * products do they have" are different questions, and averaging the second over
 * a window would answer neither.
 *
 * Products and customers carry a cap, and only those two: they are what a plan is
 * sold against. The others are reported, not enforced — so they get no meter,
 * because a progress bar with no ceiling implies one.
 *
 * Storage in the list is the last nightly reading, not a live figure: walking
 * every tenant's folder on a page render would take minutes. The detail view can
 * force a fresh count for one workspace, which is cheap.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../api/centralHttp'

const toast = useToast()

type MetricMeta = { label: string; kind: 'counter' | 'snapshot'; unit: 'count' | 'bytes'; icon: string }
type Usage = Record<string, number>

type Row = {
  tenant_id: number
  company_name: string
  subdomain: string
  status: string
  plan: string | null
  plan_slug: string | null
  usage: Usage
  limits: Record<string, number | null>
}

const rows = ref<Row[]>([])
const metrics = ref<Record<string, MetricMeta>>({})
const totals = ref<Usage>({})
const loading = ref(false)

const windowOptions = [
  { label: '7 days', value: 7 },
  { label: '30 days', value: 30 },
  { label: '90 days', value: 90 },
]
const days = ref(30)

const detail = ref<any>(null)
const detailVisible = ref(false)
const detailLoading = ref(false)
const refreshingStorage = ref(false)

const metricKeys = computed(() => Object.keys(metrics.value))

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/usage', { params: { days: days.value } })
    rows.value = data.data ?? []
    metrics.value = data.metrics ?? {}
    totals.value = data.totals ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load usage', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function openDetail(row: Row) {
  detail.value = null
  detailVisible.value = true
  detailLoading.value = true
  try {
    const { data } = await centralHttp.get(`/usage/${row.tenant_id}`, { params: { days: days.value } })
    detail.value = data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load that workspace', life: 4000 })
  } finally {
    detailLoading.value = false
  }
}

async function refreshStorage() {
  if (!detail.value) return
  refreshingStorage.value = true
  try {
    const { data } = await centralHttp.post(`/usage/${detail.value.tenant.id}/refresh-storage`)
    detail.value.current.storage_bytes = data.data.storage_bytes
    toast.add({ severity: 'success', summary: 'Storage recounted', life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not recount storage', life: 4000 })
  } finally {
    refreshingStorage.value = false
  }
}

function changeWindow() {
  load()
}

// -- formatting ------------------------------------------------------------

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / Math.pow(1024, i)
  return `${value >= 100 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`
}

function formatValue(metric: string, value: number): string {
  if (metrics.value[metric]?.unit === 'bytes') return formatBytes(value ?? 0)
  return (value ?? 0).toLocaleString()
}

/** Fraction of a cap consumed, or null when the resource is uncapped. */
function ratio(used: number, limit: number | null): number | null {
  if (limit === null || limit <= 0) return null
  return used / limit
}

function severity(used: number, limit: number | null): '' | 'warn' | 'danger' {
  const r = ratio(used, limit)
  if (r === null) return ''
  if (r >= 1) return 'danger'
  if (r >= 0.8) return 'warn'
  return ''
}

function barWidth(used: number, limit: number | null): string {
  const r = ratio(used, limit)
  return r === null ? '0%' : `${Math.min(100, Math.round(r * 100))}%`
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Tenant Usage</h1>
        <p class="sub">API calls, storage, products, customers &amp; AI usage per workspace.</p>
      </div>
      <SelectButton
        v-model="days"
        :options="windowOptions"
        optionLabel="label"
        optionValue="value"
        :allowEmpty="false"
        @update:modelValue="changeWindow"
      />
    </div>

    <p class="intro">
      <i class="pi pi-info-circle" />
      API calls and AI usage are totals over the selected window. Storage, products and
      customers are where each workspace stands right now, so the window does not change
      them. Only products and customers are capped by a plan — the rest are measured, not
      enforced. Storage here is the last nightly count; open a workspace to recount it.
    </p>

    <div class="totals">
      <div v-for="key in metricKeys" :key="key" class="total">
        <i :class="metrics[key].icon" />
        <div>
          <div class="total-value">{{ formatValue(key, totals[key]) }}</div>
          <div class="total-label">
            {{ metrics[key].label }}
            <span v-if="metrics[key].kind === 'counter'" class="total-window">/ {{ days }}d</span>
          </div>
        </div>
      </div>
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      dataKey="tenant_id"
      class="p-datatable-sm"
      sortField="usage.api_calls"
      :sortOrder="-1"
      paginator
      :rows="25"
      :rowsPerPageOptions="[25, 50, 100]"
    >
      <Column field="company_name" header="Workspace" sortable>
        <template #body="{ data }">
          <div class="name">{{ data.company_name }}</div>
          <small class="muted">{{ data.subdomain }}</small>
        </template>
      </Column>

      <Column field="plan" header="Plan" sortable style="width: 9rem">
        <template #body="{ data }">
          <span v-if="data.plan">{{ data.plan }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="usage.api_calls" header="API calls" sortable style="width: 8rem" class="num">
        <template #body="{ data }">{{ data.usage.api_calls.toLocaleString() }}</template>
      </Column>

      <Column field="usage.ai_calls" header="AI usage" sortable style="width: 7rem" class="num">
        <template #body="{ data }">{{ data.usage.ai_calls.toLocaleString() }}</template>
      </Column>

      <Column field="usage.storage_bytes" header="Storage" sortable style="width: 7rem" class="num">
        <template #body="{ data }">{{ formatBytes(data.usage.storage_bytes) }}</template>
      </Column>

      <Column field="usage.products" header="Products" sortable style="width: 9rem">
        <template #body="{ data }">
          <div class="capped">
            <span :class="severity(data.usage.products, data.limits.products)">
              {{ data.usage.products.toLocaleString() }}
              <small v-if="data.limits.products" class="of">/ {{ data.limits.products.toLocaleString() }}</small>
            </span>
            <div v-if="data.limits.products" class="meter" :class="severity(data.usage.products, data.limits.products)">
              <div class="meter-fill" :style="{ width: barWidth(data.usage.products, data.limits.products) }" />
            </div>
          </div>
        </template>
      </Column>

      <Column field="usage.customers" header="Customers" sortable style="width: 9rem">
        <template #body="{ data }">
          <div class="capped">
            <span :class="severity(data.usage.customers, data.limits.customers)">
              {{ data.usage.customers.toLocaleString() }}
              <small v-if="data.limits.customers" class="of">/ {{ data.limits.customers.toLocaleString() }}</small>
            </span>
            <div v-if="data.limits.customers" class="meter" :class="severity(data.usage.customers, data.limits.customers)">
              <div class="meter-fill" :style="{ width: barWidth(data.usage.customers, data.limits.customers) }" />
            </div>
          </div>
        </template>
      </Column>

      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button icon="pi pi-chart-bar" text rounded size="small" title="Usage detail & history" @click="openDetail(data)" />
        </template>
      </Column>

      <template #empty>No workspaces yet.</template>
    </DataTable>

    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? `Usage — ${detail.tenant.company_name}` : 'Usage'"
      :style="{ width: '760px' }"
    >
      <div v-if="detailLoading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading…</div>

      <template v-else-if="detail">
        <div class="totals detail-totals">
          <div v-for="key in metricKeys" :key="key" class="total">
            <i :class="metrics[key].icon" />
            <div>
              <div class="total-value">{{ formatValue(key, detail.current[key]) }}</div>
              <div class="total-label">
                {{ metrics[key].label }}
                <span v-if="metrics[key].kind === 'counter'" class="total-window">today</span>
              </div>
            </div>
          </div>
        </div>

        <div class="detail-actions">
          <Button
            label="Recount storage"
            icon="pi pi-refresh"
            size="small"
            outlined
            :loading="refreshingStorage"
            @click="refreshStorage"
          />
          <small class="muted">
            The list shows the last nightly count; this walks the workspace's files now.
          </small>
        </div>

        <h3 class="section">Against plan limits</h3>
        <div v-for="res in detail.limits.resources" :key="res.key" class="resource">
          <div class="resource-head">
            <span class="resource-label">{{ res.label }}</span>
            <Tag
              :severity="res.exceeded ? 'danger' : res.unlimited ? 'secondary' : 'info'"
              :value="res.unlimited ? `${res.usage.toLocaleString()} — unlimited` : `${res.usage.toLocaleString()} of ${res.limit.toLocaleString()}`"
              class="usage-tag"
            />
            <Tag v-if="res.has_override" value="override" severity="info" class="usage-tag" />
          </div>
          <div v-if="!res.unlimited" class="meter" :class="severity(res.usage, res.limit)">
            <div class="meter-fill" :style="{ width: barWidth(res.usage, res.limit) }" />
          </div>
        </div>

        <h3 class="section">Daily history — last {{ detail.series.length }} days</h3>
        <div class="series">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th v-for="key in metricKeys" :key="key" class="num">{{ metrics[key].label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="point in [...detail.series].reverse()" :key="point.date">
                <td>{{ point.date }}</td>
                <td v-for="key in metricKeys" :key="key" class="num">
                  <span v-if="point[key] === null" class="muted" title="Not measured that day">—</span>
                  <span v-else>{{ formatValue(key, point[key]) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.88rem; margin-top: 0.15rem; }
.intro {
  display: flex; align-items: flex-start; gap: 0.45rem;
  margin: 0 0 1rem; padding: 0.6rem 0.8rem; border-radius: 10px;
  font-size: 0.8rem; line-height: 1.45; color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.6rem; margin-bottom: 1.1rem; }
.total {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.65rem 0.85rem; border-radius: 10px;
  background: #fff; border: 1px solid #e5e7eb;
}
.total i { font-size: 1.15rem; color: var(--safety-amber, #e8a33d); }
.total-value { font-size: 1.2rem; font-weight: 700; line-height: 1.15; }
.total-label { font-size: 0.72rem; color: #6b7280; }
.total-window { color: #9ca3af; }
.detail-totals { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); margin-bottom: 0.8rem; }
.detail-actions { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
.name { font-weight: 500; }
.muted { color: #9ca3af; }
.of { color: #9ca3af; font-weight: 400; }
.capped { display: flex; flex-direction: column; gap: 0.25rem; }
.capped .warn { color: #8a5a12; font-weight: 600; }
.capped .danger { color: #dc2626; font-weight: 600; }
:deep(.num), .num { text-align: right; }
.meter { height: 5px; border-radius: 999px; background: var(--surface-2, #f2f0ea); overflow: hidden; }
.meter-fill { height: 100%; border-radius: 999px; background: #16a34a; transition: width 0.2s ease; }
.meter.warn .meter-fill { background: var(--safety-amber, #e8a33d); }
.meter.danger .meter-fill { background: #dc2626; }
.loading { color: #6b7280; font-size: 0.88rem; padding: 1rem 0; }
.section { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin: 1.1rem 0 0.55rem; }
.resource { margin-bottom: 0.7rem; }
.resource-head { display: flex; align-items: center; gap: 0.45rem; margin-bottom: 0.35rem; }
.resource-label { font-size: 0.85rem; font-weight: 600; }
.usage-tag { font-size: 0.7rem; }
.series { max-height: 260px; overflow-y: auto; border: 1px solid #f3f4f6; border-radius: 8px; }
.series table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.series th {
  position: sticky; top: 0; background: #fff; text-align: left;
  font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: #9ca3af;
  font-weight: 600; padding: 0.35rem 0.55rem; border-bottom: 1px solid #f3f4f6;
}
.series td { padding: 0.3rem 0.55rem; border-bottom: 1px solid #f9fafb; }
</style>
