<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useMenuStore, type MenuNode } from '../stores/menu'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const menuStore = useMenuStore()
const canGenerate = computed(() => auth.can('settings.edit'))

interface ModuleOption { key: string; label: string }
interface ModuleGroup { label: string; modules: ModuleOption[] }
interface ImportError { row: number; message: string }
interface ImportResult { message: string; created: number; failed: number; errors: ImportError[] }

const modules = ref<ModuleOption[]>([])
const counts = ref<number[]>([10, 20, 50])
const selectedCount = ref<number>(10)
// Bulk volumes (1K / 100K / 1M) live in a dropdown next to the quick buttons.
// Picking one always hands the work to the queue worker (see DemoDataController).
const bulkCounts = ref<number[]>([])
const bulkCount = ref<number | null>(null)

function shortCount(v: number): string {
  if (v >= 1_000_000) return `${v / 1_000_000}M`
  if (v >= 1_000) return `${v / 1_000}K`
  return String(v)
}

const bulkOptions = computed(() =>
  bulkCounts.value.map((v) => ({ label: `${shortCount(v)} (${v.toLocaleString()})`, value: v })),
)
const isBulk = computed(() => bulkCounts.value.includes(selectedCount.value))

function pickCount(c: number) {
  selectedCount.value = c
  bulkCount.value = null
}

// Choosing a bulk volume takes over the selection; clearing it hands the
// selection back to the first quick volume.
watch(bulkCount, (v) => {
  selectedCount.value = v ?? counts.value[0] ?? 10
})
// Which module is currently generating (for the per-button spinner), or null.
const generatingModule = ref<string | null>(null)

// Icon per module so each button is visually distinct.
const MODULE_ICONS: Record<string, string> = {
  companies: 'pi pi-sitemap',
  branches: 'pi pi-map-marker',
  users: 'pi pi-user',
  categories: 'pi pi-sitemap',
  brands: 'pi pi-tag',
  units: 'pi pi-percentage',
  products: 'pi pi-box',
  price_lists: 'pi pi-indian-rupee',
  customers: 'pi pi-users',
  customer_categories: 'pi pi-tags',
  suppliers: 'pi pi-truck',
  brokers: 'pi pi-user',
  warehouses: 'pi pi-building',
  sales_quotations: 'pi pi-file-edit',
  sales_orders: 'pi pi-shopping-cart',
  pos_sales: 'pi pi-calculator',
  b2b_orders: 'pi pi-check-square',
  invoices: 'pi pi-file',
  sales_returns: 'pi pi-replay',
  leads: 'pi pi-filter',
  follow_ups: 'pi pi-bell',
  referrals: 'pi pi-share-alt',
  coupons: 'pi pi-ticket',
  marketing_campaigns: 'pi pi-megaphone',
  whatsapp_messages: 'pi pi-whatsapp',
  sms_messages: 'pi pi-comment',
  email_logs: 'pi pi-envelope',
  salesmen: 'pi pi-user-plus',
  beats: 'pi pi-map',
  purchase_indents: 'pi pi-clipboard',
  rfqs: 'pi pi-send',
  purchase_orders: 'pi pi-file-edit',
  vendor_invoices: 'pi pi-receipt',
  vendor_ratings: 'pi pi-star',
  stock_adjustments: 'pi pi-sliders-h',
  stock_transfers: 'pi pi-arrows-h',
  stock_returns: 'pi pi-undo',
  gate_entries: 'pi pi-sign-in',
  cycle_counts: 'pi pi-check-square',
  batches: 'pi pi-clone',
  serial_numbers: 'pi pi-hashtag',
  boms: 'pi pi-sitemap',
  manufacturing_orders: 'pi pi-wrench',
  colour_printings: 'pi pi-palette',
  job_works: 'pi pi-briefcase',
  fabric_rolls: 'pi pi-scroll',
  fabric_inspections: 'pi pi-search',
  shrinkage_records: 'pi pi-arrows-h',
  fabric_cuttings: 'pi pi-objects-column',
  contractors: 'pi pi-briefcase',
  tailors: 'pi pi-user',
  rate_cards: 'pi pi-list',
  piece_rates: 'pi pi-sliders-h',
  tailor_works: 'pi pi-clock',
  tailor_alterations: 'pi pi-pencil',
  tailor_bonuses: 'pi pi-gift',
  tailor_payments: 'pi pi-money-bill',
  embroidery_vendors: 'pi pi-users',
  embroidery_works: 'pi pi-clock',
  embroidery_payments: 'pi pi-money-bill',
  bank_accounts: 'pi pi-building-columns',
  vouchers: 'pi pi-book',
  expense_categories: 'pi pi-tags',
  assets: 'pi pi-desktop',
  employee_claims: 'pi pi-receipt',
  vehicle_expenses: 'pi pi-car',
  fuel_expenses: 'pi pi-bolt',
  electricity_bills: 'pi pi-flash',
  factory_maintenances: 'pi pi-wrench',
  asset_maintenances: 'pi pi-cog',
  gst_registrations: 'pi pi-id-card',
  hsn_codes: 'pi pi-hashtag',
  tds_tcs_rates: 'pi pi-percentage',
  departments: 'pi pi-sitemap',
  biometric_devices: 'pi pi-desktop',
  attendance: 'pi pi-calendar',
  biometric_punches: 'pi pi-clock',
  leave_types: 'pi pi-tag',
  leave_applications: 'pi pi-calendar-times',
  overtime_records: 'pi pi-clock',
  salary_structures: 'pi pi-sliders-h',
  salaries: 'pi pi-wallet',
  employee_bonuses: 'pi pi-gift',
  salary_advances: 'pi pi-money-bill',
  employee_loans: 'pi pi-credit-card',
  vehicles: 'pi pi-truck',
  drivers: 'pi pi-id-card',
  delivery_routes: 'pi pi-map-marker',
  dispatch_plans: 'pi pi-send',
  dispatch_pods: 'pi pi-check-circle',
  transport_charges: 'pi pi-send',
  channel_integrations: 'pi pi-globe',
  channel_orders: 'pi pi-inbox',
  channel_listings: 'pi pi-list',
  channel_sync_logs: 'pi pi-sync',
  payment_gateway_accounts: 'pi pi-credit-card',
  payment_transactions: 'pi pi-wallet',
  employees: 'pi pi-id-card',
  expenses: 'pi pi-wallet',
}
function moduleIcon(key: string) {
  return MODULE_ICONS[key] ?? 'pi pi-database'
}

// Each demo module's nav route, used only to resolve which top-bar section it
// belongs to (see `groupedModules`). Modules without a route of their own point
// at a representative sibling so they still land in the right section.
const MODULE_ROUTES: Record<string, string> = {
  // Organisation structure + staff logins (Settings section)
  companies: '/companies',
  branches: '/branches',
  users: '/users',
  // Masters
  categories: '/categories',
  brands: '/brands',
  units: '/units',
  products: '/products',
  price_lists: '/price-lists',
  customers: '/customers',
  customer_categories: '/customer-categories',
  suppliers: '/suppliers',
  brokers: '/brokers',
  warehouses: '/warehouses',
  // Sales (incl. CRM, Field Sales, E-Commerce — top-bar sub-groups of Sales)
  sales_quotations: '/sales-quotations',
  sales_orders: '/sales-orders',
  pos_sales: '/pos',
  b2b_orders: '/b2b-orders',
  invoices: '/sales-orders',
  sales_returns: '/sales-returns',
  leads: '/leads',
  follow_ups: '/follow-ups',
  referrals: '/referrals',
  coupons: '/coupons',
  marketing_campaigns: '/marketing-campaigns',
  whatsapp_messages: '/whatsapp',
  sms_messages: '/sms',
  email_logs: '/email-logs',
  salesmen: '/salesmen',
  beats: '/beats',
  channel_integrations: '/ecommerce-integrations',
  channel_orders: '/channel-orders',
  channel_listings: '/channel-listings',
  channel_sync_logs: '/channel-sync-logs',
  // Purchase
  purchase_indents: '/purchase-indents',
  rfqs: '/rfqs',
  purchase_orders: '/purchase-orders',
  vendor_invoices: '/purchase-invoices',
  vendor_ratings: '/vendor-ratings',
  // Inventory
  stock_adjustments: '/stock-adjustments',
  stock_transfers: '/stock-transfers',
  stock_returns: '/stock-returns',
  gate_entries: '/gate-entries',
  cycle_counts: '/cycle-counts',
  batches: '/batches',
  serial_numbers: '/serial-numbers',
  // Manufacturing (incl. Textile, Tailoring, Embroidery)
  boms: '/boms',
  manufacturing_orders: '/manufacturing-orders',
  colour_printings: '/colour-printings',
  job_works: '/job-works',
  fabric_rolls: '/fabric-rolls',
  fabric_inspections: '/fabric-inspections',
  shrinkage_records: '/shrinkage-records',
  fabric_cuttings: '/fabric-cuttings',
  contractors: '/contractors',
  tailors: '/tailors',
  rate_cards: '/rate-cards',
  piece_rates: '/piece-rates',
  tailor_works: '/tailor-works/pending',
  tailor_alterations: '/tailor-alterations',
  tailor_bonuses: '/tailor-bonuses',
  tailor_payments: '/tailor-payments',
  embroidery_vendors: '/embroidery-vendors',
  embroidery_works: '/embroidery-works/pending',
  embroidery_payments: '/embroidery-payments',
  // Finance (incl. GST, Payments — top-bar sub-groups of Finance)
  bank_accounts: '/bank-accounts',
  vouchers: '/vouchers',
  expenses: '/expenses',
  expense_categories: '/expense-categories',
  assets: '/assets',
  employee_claims: '/employee-claims',
  vehicle_expenses: '/vehicle-expenses',
  fuel_expenses: '/fuel-expenses',
  electricity_bills: '/electricity-bills',
  factory_maintenances: '/factory-maintenances',
  asset_maintenances: '/asset-maintenances',
  gst_registrations: '/gst-registrations',
  hsn_codes: '/hsn-codes',
  tds_tcs_rates: '/tds-tcs-rates',
  payment_gateway_accounts: '/payment-gateways',
  payment_transactions: '/payment-transactions',
  // HR & Payroll
  departments: '/departments',
  employees: '/employees',
  attendance: '/attendance',
  biometric_devices: '/biometric-devices',
  biometric_punches: '/biometric-punches',
  leave_types: '/leave-types',
  leave_applications: '/leave-applications',
  overtime_records: '/overtime',
  salary_structures: '/salary-structures',
  salaries: '/salaries',
  employee_bonuses: '/employee-bonuses',
  salary_advances: '/salary-advances',
  employee_loans: '/employee-loans',
  // Logistics
  vehicles: '/vehicles',
  drivers: '/drivers',
  delivery_routes: '/delivery-routes',
  dispatch_plans: '/dispatch-plans',
  dispatch_pods: '/proof-of-delivery',
  transport_charges: '/transport-charges',
}

// Flat map of route → the label of its top-level top-bar section, built from the
// live (role/plan-filtered) menu so the demo groups mirror the nav exactly.
const routeToSection = computed<Record<string, string>>(() => {
  const map: Record<string, string> = {}
  for (const top of menuStore.menu) {
    if (top.to) map[top.to] = top.label
    const walk = (n: MenuNode) => {
      if (n.to) map[n.to] = top.label
      n.children?.forEach(walk)
    }
    top.children?.forEach(walk)
  }
  return map
})

// Group the demo modules under top-bar section headers, in top-bar order.
// Only modules whose route is present in the current (role/plan/module-gated)
// top-bar menu are shown — anything not in the live menu is hidden, so the
// Demo Data box mirrors exactly what the user can navigate to.
const groupedModules = computed<ModuleGroup[]>(() => {
  const r2s = routeToSection.value
  const buckets: Record<string, ModuleOption[]> = {}
  for (const m of modules.value) {
    const route = MODULE_ROUTES[m.key]
    const section = route ? r2s[route] : undefined
    if (!section) continue // not in the current top-bar menu → hide it
    ;(buckets[section] ??= []).push(m)
  }
  return menuStore.menu
    .map((m) => m.label)
    .filter((label) => buckets[label]?.length)
    .map((label) => ({ label, modules: buckets[label] }))
})

// --- Data import state ---
const importModules = ref<ModuleOption[]>([])
const importModule = ref<string | null>(null)
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<ImportResult | null>(null)

// --- Performance diagnostics state (§17) ---
interface PerfMetrics {
  cache: { store: string; versions: Record<string, number | null> }
  queue: {
    connection: string
    driver: string
    pending: number | null
    failed: number | null
    by_queue: Record<string, number>
  }
  database: {
    driver: string
    connection_driver: string | null
    tables: number | null
    indexes: number | null
    unindexed_tables: string[]
  }
  generated_at: string
}
const perf = ref<PerfMetrics | null>(null)
const perfLoading = ref(false)

async function loadPerformance() {
  perfLoading.value = true
  try {
    const { data } = await http.get('/system-tools/performance')
    perf.value = data
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load performance metrics', life: 4000 })
  } finally {
    perfLoading.value = false
  }
}

onMounted(async () => {
  // The demo modules are grouped by the top-bar menu; make sure it's loaded
  // (cached/no-op if the shell already fetched it this session).
  menuStore.fetchMenu()

  try {
    const [demo, imp] = await Promise.all([
      http.get('/system-tools/demo-data/options'),
      http.get('/system-tools/import/modules'),
    ])
    modules.value = demo.data.modules
    counts.value = demo.data.counts
    bulkCounts.value = demo.data.bulk_counts ?? []
    if (counts.value.length) selectedCount.value = counts.value[0]

    importModules.value = imp.data.modules
    importModule.value = imp.data.modules[0]?.key ?? null
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load system-tools options', life: 4000 })
  }

  loadPerformance()
})

function onFileChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  importFile.value = files && files.length ? files[0] : null
}

async function downloadTemplate() {
  if (!importModule.value) return
  try {
    const res = await http.get(`/system-tools/import/${importModule.value}/template`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.download = `${importModule.value}-import-template.xlsx`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch {
    toast.add({ severity: 'error', summary: 'Could not download template', life: 4000 })
  }
}

async function runImport() {
  if (!importModule.value || !importFile.value) {
    toast.add({ severity: 'warn', summary: 'Pick a module and a file', life: 3000 })
    return
  }
  importing.value = true
  importResult.value = null
  try {
    const form = new FormData()
    form.append('file', importFile.value)
    const { data } = await http.post(`/system-tools/import/${importModule.value}`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    // Large files are processed on the queue (§17 Performance): the POST returns
    // 202 + a job_id and we poll for the per-row summary. Small files come back
    // with the result inline.
    if (data.queued && data.job_id) {
      toast.add({ severity: 'info', summary: data.message, life: 4000 })
      const result = await pollImport(data.job_id)
      importResult.value = result
      toast.add({ severity: result.failed ? 'warn' : 'success', summary: result.message, life: 5000 })
    } else {
      importResult.value = data
      toast.add({ severity: data.failed ? 'warn' : 'success', summary: data.message, life: 5000 })
    }
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Import failed',
      detail: e.response?.data?.message || 'Unexpected error',
      life: 5000,
    })
  } finally {
    importing.value = false
  }
}

/** Poll a queued import until it finishes (or a sane attempt cap is hit). */
async function pollImport(jobId: string): Promise<ImportResult> {
  const wait = (ms: number) => new Promise((r) => setTimeout(r, ms))
  for (let attempt = 0; attempt < 150; attempt++) {
    await wait(2000)
    try {
      const { data } = await http.get(`/system-tools/import/status/${jobId}`)
      if (data.status && data.status !== 'processing') {
        return data
      }
    } catch {
      /* transient — keep polling */
    }
  }
  return { message: 'Import is still running in the background.', created: 0, failed: 0, errors: [] }
}

/** Queue a demo-data run for a single module at the selected volume. */
async function generateModule(key: string) {
  if (!canGenerate.value) return
  generatingModule.value = key
  try {
    const { data } = await http.post('/system-tools/demo-data', {
      modules: [key],
      count: selectedCount.value,
    })
    toast.add({
      severity: data.queued ? 'info' : 'success',
      summary: data.queued ? 'Generation queued' : 'Demo data created',
      detail: data.message,
      life: data.queued ? 8000 : 5000,
    })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Could not start generation',
      detail: e.response?.data?.message || 'Unexpected error',
      life: 5000,
    })
  } finally {
    generatingModule.value = null
  }
}
</script>

<template>
  <div class="system-tools">
    <PageHeader title="System Tools" subtitle="Administrative utilities for this workspace." />

    <!-- Performance diagnostics (project-stack.md §17) -->
    <section class="tool-card">
      <div class="tool-head">
        <div class="tool-icon"><i class="pi pi-gauge" /></div>
        <div>
          <h2>Performance</h2>
          <p>Live health of the cache, queue and database indexing for this workspace.</p>
        </div>
        <Button
          class="refresh-btn"
          icon="pi pi-refresh"
          text
          rounded
          :loading="perfLoading"
          aria-label="Refresh"
          @click="loadPerformance"
        />
      </div>

      <div v-if="perf" class="perf-grid">
        <!-- Cache -->
        <div class="perf-panel">
          <div class="perf-panel-head"><i class="pi pi-bolt" /> Cache</div>
          <div class="perf-row"><span>Store</span><strong>{{ perf.cache.store }}</strong></div>
          <div v-for="(v, g) in perf.cache.versions" :key="g" class="perf-row">
            <span>{{ g }} version</span><strong>{{ v ?? '—' }}</strong>
          </div>
        </div>

        <!-- Queue -->
        <div class="perf-panel">
          <div class="perf-panel-head"><i class="pi pi-inbox" /> Queue</div>
          <div class="perf-row"><span>Driver</span><strong>{{ perf.queue.driver }}</strong></div>
          <div class="perf-row">
            <span>Pending jobs</span>
            <strong :class="{ warn: (perf.queue.pending ?? 0) > 50 }">{{ perf.queue.pending ?? '—' }}</strong>
          </div>
          <div class="perf-row">
            <span>Failed jobs</span>
            <strong :class="{ fail: (perf.queue.failed ?? 0) > 0 }">{{ perf.queue.failed ?? '—' }}</strong>
          </div>
        </div>

        <!-- Database / indexes -->
        <div class="perf-panel">
          <div class="perf-panel-head"><i class="pi pi-database" /> Indexes</div>
          <div class="perf-row"><span>Driver</span><strong>{{ perf.database.connection_driver ?? perf.database.driver }}</strong></div>
          <div class="perf-row"><span>Tables</span><strong>{{ perf.database.tables ?? '—' }}</strong></div>
          <div class="perf-row"><span>Indexes</span><strong>{{ perf.database.indexes ?? '—' }}</strong></div>
          <div class="perf-row">
            <span>Tenant tables w/o secondary index</span>
            <strong :class="{ warn: perf.database.unindexed_tables.length > 0 }">
              {{ perf.database.unindexed_tables.length }}
            </strong>
          </div>
        </div>
      </div>

      <p v-if="perf?.database.unindexed_tables.length" class="hint perf-hint">
        Under-indexed: {{ perf.database.unindexed_tables.join(', ') }}
      </p>
      <p v-if="perf" class="perf-stamp">Updated {{ new Date(perf.generated_at).toLocaleTimeString() }}</p>
    </section>

    <!-- Import Data (project-stack.md §8) -->
    <section class="tool-card">
      <div class="tool-head">
        <div class="tool-icon" style="background: linear-gradient(135deg, #5fa483, #4c8b6c)"><i class="pi pi-upload" /></div>
        <div>
          <h2>Import Data</h2>
          <p>Bulk-import records from Excel, CSV or JSON. Download the template to see the expected columns.</p>
        </div>
      </div>

      <div class="field">
        <label>Module</label>
        <div class="import-row">
          <Select
            v-model="importModule"
            :options="importModules"
            optionLabel="label"
            optionValue="key"
            placeholder="Select module"
            class="import-select"
          />
          <Button label="Download template" icon="pi pi-download" severity="secondary" outlined @click="downloadTemplate" />
        </div>
      </div>

      <div class="field">
        <label>File <span class="muted">(.xlsx, .xls, .csv, .json — max 10 MB)</span></label>
        <input type="file" accept=".xlsx,.xls,.csv,.json" class="file-input" @change="onFileChange" />
      </div>

      <div class="actions">
        <Button
          label="Import"
          icon="pi pi-upload"
          :loading="importing"
          :disabled="!canGenerate || !importFile || !importModule"
          @click="runImport"
        />
        <span v-if="!canGenerate" class="hint">You need the “settings.edit” permission to import.</span>
      </div>

      <div v-if="importResult" class="import-result">
        <div class="result-summary">
          <span class="ok"><i class="pi pi-check-circle" /> {{ importResult.created }} created</span>
          <span v-if="importResult.failed" class="fail"><i class="pi pi-times-circle" /> {{ importResult.failed }} failed</span>
        </div>
        <ul v-if="importResult.errors.length" class="error-list">
          <li v-for="(err, i) in importResult.errors" :key="i">
            <strong>Row {{ err.row }}:</strong> {{ err.message }}
          </li>
        </ul>
      </div>
    </section>

    <!-- Demo Data (project-stack.md §7) — spans the full width below the row above -->
    <section class="tool-card tool-card--wide">
      <div class="tool-head">
        <div class="tool-icon"><i class="pi pi-database" /></div>
        <div>
          <h2>Demo Data</h2>
          <p>Generate realistic sample records per module. Pick a volume, then click a module to populate it.</p>
        </div>
      </div>

      <div class="field">
        <label>Records per module</label>
        <div class="count-row">
          <button
            v-for="c in counts"
            :key="c"
            type="button"
            class="count-btn"
            :class="{ active: selectedCount === c }"
            @click="pickCount(c)"
          >
            {{ c.toLocaleString() }}
          </button>
          <Select
            v-model="bulkCount"
            :options="bulkOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Bulk volume"
            showClear
            class="bulk-select"
            :class="{ active: isBulk }"
          />
        </div>
        <span v-if="isBulk" class="hint bulk-hint">
          <i class="pi pi-info-circle" />
          {{ selectedCount.toLocaleString() }} rows per module runs in the background on the queue worker —
          it can take a while, and rows appear progressively.
        </span>
      </div>

      <div class="field">
        <label>Modules</label>
        <div v-for="group in groupedModules" :key="group.label" class="demo-group">
          <div class="demo-group-head">{{ group.label }}</div>
          <div class="demo-module-grid">
            <button
              v-for="m in group.modules"
              :key="m.key"
              type="button"
              class="demo-module-btn"
              :disabled="!canGenerate || (generatingModule !== null && generatingModule !== m.key)"
              @click="generateModule(m.key)"
            >
              <i v-if="generatingModule === m.key" class="pi pi-spin pi-spinner demo-mod-icon" />
              <i v-else :class="moduleIcon(m.key)" class="demo-mod-icon" />
              <span class="demo-mod-label">{{ m.label }}</span>
              <span class="demo-mod-count">+{{ selectedCount.toLocaleString() }}</span>
            </button>
          </div>
        </div>
      </div>

      <span v-if="!canGenerate" class="hint">You need the “settings.edit” permission to generate data.</span>
    </section>
  </div>
</template>

<style scoped>
.system-tools {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 900px) {
  .system-tools {
    grid-template-columns: 1fr;
  }
}
.system-tools h1 {
  font-size: 1.6rem;
  font-weight: 600;
  grid-column: 1 / -1;
  margin-bottom: -0.5rem;
}
.subtitle {
  color: #6b7280;
  grid-column: 1 / -1;
}
.tool-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.5rem;
}
.tool-card--wide {
  grid-column: 1 / -1;
}
.tool-head {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}
.tool-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: linear-gradient(135deg, #3d637c, #2c4a5e);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  flex-shrink: 0;
}
.tool-head h2 {
  font-size: 1.15rem;
  margin: 0;
}
.tool-head p {
  color: #6b7280;
  margin: 0.25rem 0 0;
  font-size: 0.9rem;
}
.field {
  margin-bottom: 1.25rem;
}
.field-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.field label {
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--graphite, #1e2328);
}
.link {
  background: none;
  border: none;
  color: var(--ink-blue, #2c4a5e);
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 600;
}
.demo-group + .demo-group {
  margin-top: 1.25rem;
}
.demo-group-head {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ink-blue, #2c4a5e);
  padding-bottom: 0.4rem;
  margin-top: 0.6rem;
  border-bottom: 1px solid var(--border, #e8e4da);
}
.demo-module-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 0.7rem;
  margin-top: 0.6rem;
}
.demo-module-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding: 1rem 0.75rem;
  border: 1px solid var(--border, #e3ded3);
  border-radius: 12px;
  background: var(--surface, #fff);
  color: var(--graphite, #1e2328);
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.demo-module-btn:hover:not(:disabled) {
  border-color: var(--safety-amber, #e8a33d);
  box-shadow: 0 6px 16px rgba(232, 163, 61, 0.18);
  transform: translateY(-2px);
}
.demo-module-btn:disabled {
  opacity: 0.55;
  cursor: default;
}
.demo-mod-icon {
  font-size: 1.5rem;
  color: var(--ink-blue, #2c4a5e);
}
.demo-mod-label {
  font-weight: 600;
  font-size: 0.88rem;
}
.demo-mod-count {
  font-size: 0.72rem;
  font-weight: 700;
  color: #fff;
  background: var(--safety-amber, #e8a33d);
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
}
.count-row {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.6rem;
  flex-wrap: wrap;
  align-items: center;
}
/* Bulk-volume dropdown sits alongside the quick-count buttons and highlights
   like them while one of its volumes is selected. */
.bulk-select {
  min-width: 11rem;
}
.bulk-select :deep(.p-select) {
  border-radius: 8px;
}
.bulk-select.active :deep(.p-select) {
  border-color: var(--safety-amber, #e8a33d);
  box-shadow: 0 0 0 1px var(--safety-amber, #e8a33d);
}
.bulk-hint {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.5rem;
  color: var(--alert-rust, #c1502e);
}
.count-btn {
  border: 1px solid var(--border, #e3ded3);
  background: var(--surface, #fff);
  color: var(--graphite, #1e2328);
  border-radius: 8px;
  padding: 0.5rem 1rem;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
}
.count-btn.active {
  background: var(--safety-amber, #e8a33d);
  border-color: var(--safety-amber, #e8a33d);
  color: #23292f;
}
.actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.hint {
  color: #9ca3af;
  font-size: 0.8rem;
}
.muted {
  color: #9ca3af;
  font-weight: 400;
  font-size: 0.8rem;
}
.import-row {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  margin-top: 0.6rem;
  flex-wrap: wrap;
}
.import-select {
  min-width: 200px;
}
.file-input {
  display: block;
  margin-top: 0.6rem;
  font-size: 0.85rem;
}
.import-result {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid var(--border, #e8e4da);
}
.result-summary {
  display: flex;
  gap: 1.25rem;
  font-weight: 600;
  font-size: 0.9rem;
}
.result-summary .ok {
  color: #4c8b6c;
}
.result-summary .fail {
  color: #c1502e;
}
.error-list {
  margin: 0.75rem 0 0;
  padding-left: 1.1rem;
  color: #b45309;
  font-size: 0.82rem;
  max-height: 200px;
  overflow-y: auto;
}
.error-list li {
  margin-bottom: 0.25rem;
}

/* --- Performance diagnostics --- */
.refresh-btn {
  margin-left: auto;
}
.perf-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
}
.perf-panel {
  border: 1px solid var(--border, #e8e4da);
  border-radius: 10px;
  padding: 0.9rem 1rem;
  background: var(--paper, #faf9f6);
}
.perf-panel-head {
  font-weight: 600;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.6rem;
  color: var(--text, #23292f);
}
.perf-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 0.82rem;
  padding: 0.2rem 0;
  color: #6b7280;
  text-transform: capitalize;
}
.perf-row strong {
  color: var(--text, #23292f);
  font-variant-numeric: tabular-nums;
}
.perf-row strong.warn {
  color: #b45309;
}
.perf-row strong.fail {
  color: #dc2626;
}
.perf-hint {
  margin-top: 0.75rem;
  word-break: break-word;
}
.perf-stamp {
  margin-top: 0.75rem;
  font-size: 0.72rem;
  color: #9ca3af;
}
</style>
