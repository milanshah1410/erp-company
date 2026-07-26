<script setup lang="ts">
// Tenant System Tools — Import Data only (project-stack.md §8).
//
// The Demo Data generator (§7) and Performance diagnostics (§17) are operator
// tools: they moved to the SaaS-owner platform panel (Platform → Ops → Tenant
// Tools), where they run against a chosen tenant. The tenant routes for them are
// gone, so this screen no longer calls them.
import { ref, onMounted, computed } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const canImport = computed(() => auth.can('settings.edit'))

interface ModuleOption { key: string; label: string }
interface ImportError { row: number; message: string }
interface ImportResult { message: string; created: number; failed: number; errors: ImportError[] }

const importModules = ref<ModuleOption[]>([])
const importModule = ref<string | null>(null)
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<ImportResult | null>(null)

onMounted(async () => {
  try {
    const { data } = await http.get('/system-tools/import/modules')
    importModules.value = data.modules
    importModule.value = data.modules[0]?.key ?? null
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load import modules', life: 4000 })
  }
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
</script>

<template>
  <div>
    <PageHeader title="System Tools" subtitle="Bulk-import your existing records into this workspace." />

    <!-- Import Data (project-stack.md §8) -->
    <section class="tool-card">
      <div class="tool-head">
        <div class="tool-icon"><i class="pi pi-upload" /></div>
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
          :disabled="!canImport || !importFile || !importModule"
          @click="runImport"
        />
        <span v-if="!canImport" class="hint">You need the “settings.edit” permission to import.</span>
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
.tool-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.5rem;
  max-width: 720px;
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
  background: linear-gradient(135deg, #5fa483, #4c8b6c);
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
  color: var(--muted, #6b7280);
  margin: 0.25rem 0 0;
  font-size: 0.9rem;
}
.field {
  margin-bottom: 1.25rem;
}
.field label {
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--graphite, #1e2328);
}
.actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.hint {
  color: var(--muted, #9ca3af);
  font-size: 0.8rem;
}
.muted {
  color: var(--muted, #9ca3af);
  font-weight: 400;
  font-size: 0.8rem;
}
.import-row {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  margin-top: 0.5rem;
  flex-wrap: wrap;
}
.import-select {
  min-width: 220px;
}
.file-input {
  display: block;
  margin-top: 0.5rem;
  font-size: 0.9rem;
}
.import-result {
  border-top: 1px solid var(--border, #f0ede6);
  padding-top: 1rem;
  margin-top: 0.5rem;
}
.result-summary {
  display: flex;
  gap: 1.25rem;
  font-size: 0.9rem;
  font-weight: 600;
}
.result-summary .ok {
  color: var(--stock-green, #4c8b6c);
}
.result-summary .fail {
  color: var(--alert-rust, #c1502e);
}
.error-list {
  margin: 0.75rem 0 0;
  padding-left: 1.1rem;
  max-height: 220px;
  overflow-y: auto;
  font-size: 0.82rem;
  color: var(--muted, #6b7280);
}
.error-list li {
  margin-bottom: 0.25rem;
}
</style>
