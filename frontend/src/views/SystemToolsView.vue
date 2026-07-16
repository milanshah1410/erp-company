<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()
const canGenerate = computed(() => auth.can('settings.edit'))

interface ModuleOption { key: string; label: string }
interface ImportError { row: number; message: string }
interface ImportResult { message: string; created: number; failed: number; errors: ImportError[] }

const modules = ref<ModuleOption[]>([])
const counts = ref<number[]>([100, 500, 1000, 10000])
const selectedModules = ref<string[]>([])
const selectedCount = ref<number>(100)
const generating = ref(false)

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
  try {
    const [demo, imp] = await Promise.all([
      http.get('/system-tools/demo-data/options'),
      http.get('/system-tools/import/modules'),
    ])
    modules.value = demo.data.modules
    counts.value = demo.data.counts
    selectedModules.value = demo.data.modules
      .filter((m: ModuleOption) => ['customers', 'products', 'suppliers'].includes(m.key))
      .map((m: ModuleOption) => m.key)

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

function toggleAll() {
  selectedModules.value =
    selectedModules.value.length === modules.value.length ? [] : modules.value.map((m) => m.key)
}

async function generate() {
  if (!selectedModules.value.length) {
    toast.add({ severity: 'warn', summary: 'Pick at least one module', life: 3000 })
    return
  }
  generating.value = true
  try {
    const { data } = await http.post('/system-tools/demo-data', {
      modules: selectedModules.value,
      count: selectedCount.value,
    })
    toast.add({ severity: 'success', summary: 'Generation started', detail: data.message, life: 6000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Could not start generation',
      detail: e.response?.data?.message || 'Unexpected error',
      life: 5000,
    })
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="system-tools">
    <h1>System Tools</h1>
    <p class="subtitle">Administrative utilities for this workspace.</p>

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

    <!-- Demo Data (project-stack.md §7) -->
    <section class="tool-card">
      <div class="tool-head">
        <div class="tool-icon"><i class="pi pi-database" /></div>
        <div>
          <h2>Demo Data</h2>
          <p>Populate the ERP with realistic sample records to explore features.</p>
        </div>
      </div>

      <div class="field">
        <div class="field-head">
          <label>Modules</label>
          <button class="link" type="button" @click="toggleAll">
            {{ selectedModules.length === modules.length ? 'Clear all' : 'Select all' }}
          </button>
        </div>
        <div class="module-grid">
          <label v-for="m in modules" :key="m.key" class="module-check">
            <Checkbox v-model="selectedModules" :value="m.key" :inputId="'mod-' + m.key" />
            <span>{{ m.label }}</span>
          </label>
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
            @click="selectedCount = c"
          >
            {{ c.toLocaleString() }}
          </button>
        </div>
      </div>

      <div class="actions">
        <Button
          label="Generate Demo Data"
          icon="pi pi-bolt"
          :loading="generating"
          :disabled="!canGenerate || !selectedModules.length"
          @click="generate"
        />
        <span v-if="!canGenerate" class="hint">You need the “settings.edit” permission to generate data.</span>
      </div>
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
  </div>
</template>

<style scoped>
.system-tools {
  columns: 2;
  column-gap: 1.5rem;
}
@media (max-width: 900px) {
  .system-tools {
    columns: 1;
  }
}
.system-tools h1 {
  font-size: 1.6rem;
  font-weight: 600;
  column-span: all;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.5rem;
  column-span: all;
}
.tool-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.5rem;
  break-inside: avoid;
  margin-bottom: 1.5rem;
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
.module-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 0.6rem;
  margin-top: 0.6rem;
}
.module-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  cursor: pointer;
}
.count-row {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.6rem;
  flex-wrap: wrap;
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
