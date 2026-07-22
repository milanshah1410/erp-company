<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

// ---- Tabs (each gated by its Ops permission) --------------------------------
const tabs = computed(() =>
  [
    { key: 'health', label: 'System Health', icon: 'pi pi-heart', visible: auth.can('ops-health.view') },
    { key: 'queues', label: 'Queues', icon: 'pi pi-list', visible: auth.can('ops-queues.view') },
    { key: 'backups', label: 'Backups', icon: 'pi pi-database', visible: auth.can('ops-backups.view') },
    { key: 'logs', label: 'Logs', icon: 'pi pi-file', visible: auth.can('ops-logs.view') },
    { key: 'flags', label: 'Feature Flags', icon: 'pi pi-flag', visible: auth.can('feature-flags.view') },
  ].filter((t) => t.visible),
)
const activeTab = ref('')

const canManageQueues = computed(() => auth.can('ops-queues.manage'))
const canCreateBackup = computed(() => auth.can('ops-backups.create'))
const canDeleteBackup = computed(() => auth.can('ops-backups.restore'))
const canViewSystem = computed(() => auth.can('ops-system.view'))
const canEditFlags = computed(() => auth.can('feature-flags.edit'))

function apiError(e: any, fallback: string) {
  toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || fallback, life: 4000 })
}

function fmtBytes(bytes: number | null): string {
  if (bytes === null || bytes === undefined) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let v = bytes
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${units[i]}`
}

// ---- Health ------------------------------------------------------------------
const health = ref<any>(null)
const system = ref<any>(null)
const healthLoading = ref(false)

const statusSeverity: Record<string, string> = { ok: 'success', warn: 'warn', down: 'danger' }

async function loadHealth() {
  healthLoading.value = true
  try {
    const requests: Promise<any>[] = [centralHttp.get('/ops/health')]
    if (canViewSystem.value && !system.value) requests.push(centralHttp.get('/ops/system'))
    const [healthRes, systemRes] = await Promise.all(requests)
    health.value = healthRes.data.data
    if (systemRes) system.value = systemRes.data.data
  } catch (e: any) {
    apiError(e, 'Could not load system health')
  } finally {
    healthLoading.value = false
  }
}

// ---- Queues ------------------------------------------------------------------
const queues = ref<any>(null)
const queuesLoading = ref(false)
const traceDialog = ref(false)
const trace = ref<any>(null)

async function loadQueues() {
  queuesLoading.value = true
  try {
    const { data } = await centralHttp.get('/ops/queues')
    queues.value = data.data
  } catch (e: any) {
    apiError(e, 'Could not load queue status')
  } finally {
    queuesLoading.value = false
  }
}

async function showTrace(row: any) {
  try {
    const { data } = await centralHttp.get(`/ops/queues/failed/${row.uuid}`)
    trace.value = data.data
    traceDialog.value = true
  } catch (e: any) {
    apiError(e, 'Could not load exception trace')
  }
}

async function retryJob(row: any) {
  try {
    await centralHttp.post(`/ops/queues/failed/${row.uuid}/retry`)
    toast.add({ severity: 'success', summary: 'Job queued for retry', life: 2500 })
    await loadQueues()
  } catch (e: any) {
    apiError(e, 'Retry failed')
  }
}

async function retryAll() {
  try {
    await centralHttp.post('/ops/queues/failed/retry-all')
    toast.add({ severity: 'success', summary: 'All failed jobs queued for retry', life: 2500 })
    await loadQueues()
  } catch (e: any) {
    apiError(e, 'Retry failed')
  }
}

async function forgetJob(row: any) {
  const res = await Swal.fire({
    title: 'Delete this failed job?',
    text: row.job,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/ops/queues/failed/${row.uuid}`)
    toast.add({ severity: 'success', summary: 'Failed job deleted', life: 2500 })
    await loadQueues()
  } catch (e: any) {
    apiError(e, 'Delete failed')
  }
}

async function flushFailed() {
  const res = await Swal.fire({
    title: 'Delete ALL failed jobs?',
    text: 'This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Flush all',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete('/ops/queues/failed')
    toast.add({ severity: 'success', summary: 'Failed jobs flushed', life: 2500 })
    await loadQueues()
  } catch (e: any) {
    apiError(e, 'Flush failed')
  }
}

// ---- Backups -----------------------------------------------------------------
const backups = ref<any[]>([])
const backupsLoading = ref(false)
const backupRunning = ref(false)

async function loadBackups() {
  backupsLoading.value = true
  try {
    const { data } = await centralHttp.get('/ops/backups')
    backups.value = data.data ?? data
  } catch (e: any) {
    apiError(e, 'Could not load backups')
  } finally {
    backupsLoading.value = false
  }
}

async function runBackup() {
  backupRunning.value = true
  try {
    await centralHttp.post('/ops/backups', {}, { timeout: 300000 })
    toast.add({ severity: 'success', summary: 'Backup completed', life: 3000 })
    await loadBackups()
  } catch (e: any) {
    apiError(e, 'Backup failed')
  } finally {
    backupRunning.value = false
  }
}

async function downloadBlob(url: string, filename: string) {
  try {
    const { data } = await centralHttp.get(url, { responseType: 'blob' })
    const href = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = href
    a.download = filename
    a.click()
    URL.revokeObjectURL(href)
  } catch (e: any) {
    apiError(e, 'Download failed')
  }
}

async function deleteBackup(row: any) {
  const res = await Swal.fire({
    title: `Delete backup "${row.filename}"?`,
    text: 'The archive cannot be recovered afterwards.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/ops/backups/${encodeURIComponent(row.filename)}`)
    toast.add({ severity: 'success', summary: 'Backup deleted', life: 2500 })
    await loadBackups()
  } catch (e: any) {
    apiError(e, 'Delete failed')
  }
}

// ---- Logs --------------------------------------------------------------------
const logFiles = ref<any[]>([])
const logFile = ref<string | null>(null)
const logLevel = ref<string | null>(null)
const logLimit = ref(200)
const logData = ref<any>(null)
const logsLoading = ref(false)
// v-for index over an untyped payload infers string | number — accept both.
const expandedEntry = ref<number | string | null>(null)
const logEntries = computed<any[]>(() => logData.value?.entries ?? [])

const levelOptions = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].map((l) => ({ label: l, value: l }))
const limitOptions = [100, 200, 500].map((n) => ({ label: `${n} entries`, value: n }))
const levelSeverity: Record<string, string> = {
  DEBUG: 'secondary',
  INFO: 'info',
  NOTICE: 'info',
  WARNING: 'warn',
  ERROR: 'danger',
  CRITICAL: 'danger',
  ALERT: 'danger',
  EMERGENCY: 'danger',
}

async function loadLogFiles() {
  try {
    const { data } = await centralHttp.get('/ops/logs')
    logFiles.value = data.data ?? data
    if (!logFile.value && logFiles.value.length) {
      logFile.value = logFiles.value[0].filename
      await loadLogEntries()
    }
  } catch (e: any) {
    apiError(e, 'Could not load log files')
  }
}

async function loadLogEntries() {
  if (!logFile.value) return
  logsLoading.value = true
  expandedEntry.value = null
  try {
    const params: Record<string, any> = { limit: logLimit.value }
    if (logLevel.value) params.level = logLevel.value
    const { data } = await centralHttp.get(`/ops/logs/${encodeURIComponent(logFile.value)}`, { params })
    logData.value = data.data
  } catch (e: any) {
    apiError(e, 'Could not read log file')
  } finally {
    logsLoading.value = false
  }
}

// ---- Feature flags -----------------------------------------------------------
const flags = ref<any[]>([])
const flagsLoading = ref(false)
const flagDialog = ref(false)
const editingFlag = ref<any>(null)
const flagSaving = ref(false)
const flagForm = ref<any>({ key: '', label: '', description: '', enabled: false })
const flagErrors = ref<Record<string, string[]>>({})

function flagError(key: string): string | null {
  return flagErrors.value[key]?.[0] ?? null
}

async function loadFlags() {
  flagsLoading.value = true
  try {
    const { data } = await centralHttp.get('/ops/feature-flags')
    flags.value = data.data ?? data
  } catch (e: any) {
    apiError(e, 'Could not load feature flags')
  } finally {
    flagsLoading.value = false
  }
}

function openCreateFlag() {
  editingFlag.value = null
  flagForm.value = { key: '', label: '', description: '', enabled: false }
  flagErrors.value = {}
  flagDialog.value = true
}

function openEditFlag(row: any) {
  editingFlag.value = row
  flagForm.value = { key: row.key, label: row.label, description: row.description ?? '', enabled: !!row.enabled }
  flagErrors.value = {}
  flagDialog.value = true
}

async function saveFlag() {
  flagSaving.value = true
  flagErrors.value = {}
  try {
    if (editingFlag.value) {
      await centralHttp.put(`/ops/feature-flags/${editingFlag.value.id}`, flagForm.value)
      toast.add({ severity: 'success', summary: 'Flag updated', life: 2500 })
    } else {
      await centralHttp.post('/ops/feature-flags', flagForm.value)
      toast.add({ severity: 'success', summary: 'Flag created', life: 2500 })
    }
    flagDialog.value = false
    await loadFlags()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      flagErrors.value = e.response.data.errors
    } else {
      apiError(e, 'Save failed')
    }
  } finally {
    flagSaving.value = false
  }
}

async function toggleFlag(row: any, enabled: boolean) {
  try {
    await centralHttp.put(`/ops/feature-flags/${row.id}`, { enabled })
    toast.add({ severity: 'success', summary: `"${row.key}" ${enabled ? 'enabled' : 'disabled'}`, life: 2000 })
    await loadFlags()
  } catch (e: any) {
    row.enabled = !enabled // revert the optimistic switch
    apiError(e, 'Toggle failed')
  }
}

async function deleteFlag(row: any) {
  const res = await Swal.fire({
    title: `Delete flag "${row.key}"?`,
    text: 'Code checking this flag will treat it as disabled.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/ops/feature-flags/${row.id}`)
    toast.add({ severity: 'success', summary: 'Flag deleted', life: 2500 })
    await loadFlags()
  } catch (e: any) {
    apiError(e, 'Delete failed')
  }
}

// ---- Lazy per-tab loading ------------------------------------------------------
const loadedTabs = new Set<string>()
const tabLoaders: Record<string, () => Promise<void>> = {
  health: loadHealth,
  queues: loadQueues,
  backups: loadBackups,
  logs: loadLogFiles,
  flags: loadFlags,
}

watch(activeTab, async (tab) => {
  if (!tab || loadedTabs.has(tab)) return
  loadedTabs.add(tab)
  await tabLoaders[tab]?.()
})

onMounted(() => {
  activeTab.value = tabs.value[0]?.key ?? ''
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Ops</h1>
    </div>

    <div class="tab-bar">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="tab"
        :class="{ active: activeTab === tab.key }"
        type="button"
        @click="activeTab = tab.key"
      >
        <i :class="tab.icon" />
        {{ tab.label }}
      </button>
    </div>

    <!-- ============================== Health ============================== -->
    <section v-if="activeTab === 'health'">
      <div class="section-head">
        <div v-if="health" class="overall">
          <Tag :severity="statusSeverity[health.overall]" :value="health.overall === 'ok' ? 'All systems operational' : health.overall === 'warn' ? 'Degraded' : 'Outage'" />
          <span class="muted">checked {{ health.checked_at }}</span>
        </div>
        <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="healthLoading" @click="loadHealth" />
      </div>

      <div class="cards">
        <div v-for="check in health?.checks ?? []" :key="check.key" class="card">
          <div class="card-top">
            <span class="card-title">{{ check.label }}</span>
            <Tag :severity="statusSeverity[check.status]" :value="check.status.toUpperCase()" />
          </div>
          <div class="card-detail">
            <span>{{ check.detail || '—' }}</span>
            <span class="muted">{{ check.latency_ms }} ms</span>
          </div>
        </div>
      </div>

      <template v-if="canViewSystem && system">
        <h2 class="sub-heading">System Information</h2>
        <div class="cards">
          <div class="card">
            <div class="card-title">Application</div>
            <dl class="kv">
              <dt>Laravel</dt><dd>{{ system.app.laravel_version }}</dd>
              <dt>Environment</dt><dd>{{ system.app.environment }} <Tag v-if="system.app.debug" severity="warn" value="debug on" class="mini-tag" /></dd>
              <dt>Timezone</dt><dd>{{ system.app.timezone }}</dd>
              <dt>URL</dt><dd>{{ system.app.url }}</dd>
            </dl>
          </div>
          <div class="card">
            <div class="card-title">PHP</div>
            <dl class="kv">
              <dt>Version</dt><dd>{{ system.php.version }}</dd>
              <dt>Memory limit</dt><dd>{{ system.php.memory_limit }}</dd>
              <dt>Upload max</dt><dd>{{ system.php.upload_max_filesize }} / post {{ system.php.post_max_size }}</dd>
              <dt>Max execution</dt><dd>{{ system.php.max_execution_time }}s</dd>
            </dl>
          </div>
          <div class="card">
            <div class="card-title">Drivers</div>
            <dl class="kv">
              <dt>Database</dt><dd>{{ system.drivers.database }} {{ system.drivers.database_version }}</dd>
              <dt>Cache</dt><dd>{{ system.drivers.cache }}</dd>
              <dt>Queue</dt><dd>{{ system.drivers.queue }}</dd>
              <dt>Session</dt><dd>{{ system.drivers.session }}</dd>
              <dt>Mail</dt><dd>{{ system.drivers.mail }}</dd>
              <dt>Search</dt><dd>{{ system.drivers.search }}</dd>
            </dl>
          </div>
          <div class="card">
            <div class="card-title">Server</div>
            <dl class="kv">
              <dt>OS</dt><dd>{{ system.server.os }}</dd>
              <dt>Hostname</dt><dd>{{ system.server.hostname }}</dd>
              <dt>Disk</dt><dd>{{ fmtBytes(system.server.disk_free_bytes) }} free of {{ fmtBytes(system.server.disk_total_bytes) }}</dd>
            </dl>
          </div>
        </div>
      </template>
    </section>

    <!-- ============================== Queues ============================== -->
    <section v-if="activeTab === 'queues'">
      <div class="section-head">
        <div class="stats">
          <div class="stat">
            <span class="stat-value">{{ queues?.pending_total ?? '—' }}</span>
            <span class="stat-label">Pending jobs</span>
          </div>
          <div class="stat">
            <span class="stat-value" :class="{ danger: (queues?.failed_total ?? 0) > 0 }">{{ queues?.failed_total ?? '—' }}</span>
            <span class="stat-label">Failed jobs</span>
          </div>
        </div>
        <div class="actions">
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="queuesLoading" @click="loadQueues" />
          <template v-if="canManageQueues && (queues?.failed_total ?? 0) > 0">
            <Button label="Retry all" icon="pi pi-replay" size="small" severity="warn" @click="retryAll" />
            <Button label="Flush all" icon="pi pi-trash" size="small" severity="danger" outlined @click="flushFailed" />
          </template>
        </div>
      </div>

      <h2 class="sub-heading">Pending by queue</h2>
      <DataTable :value="queues?.pending_by_queue ?? []" :loading="queuesLoading" class="p-datatable-sm">
        <Column field="queue" header="Queue" />
        <Column field="jobs" header="Jobs" style="width: 8rem" />
        <Column field="oldest_created_at" header="Oldest job" style="width: 14rem" />
        <template #empty>No pending jobs — the queue is empty.</template>
      </DataTable>

      <h2 class="sub-heading">Failed jobs <span v-if="(queues?.failed_total ?? 0) > 50" class="muted">(showing latest 50)</span></h2>
      <DataTable :value="queues?.failed ?? []" :loading="queuesLoading" dataKey="uuid" class="p-datatable-sm">
        <Column field="job" header="Job" />
        <Column field="queue" header="Queue" style="width: 8rem" />
        <Column field="exception" header="Exception">
          <template #body="{ data }">
            <span class="mono ellipsis" :title="data.exception">{{ data.exception }}</span>
          </template>
        </Column>
        <Column field="failed_at" header="Failed at" style="width: 12rem" sortable />
        <Column header="Actions" style="width: 9rem">
          <template #body="{ data }">
            <Button icon="pi pi-eye" text rounded size="small" title="View trace" @click="showTrace(data)" />
            <Button v-if="canManageQueues" icon="pi pi-replay" text rounded size="small" severity="warn" title="Retry" @click="retryJob(data)" />
            <Button v-if="canManageQueues" icon="pi pi-trash" text rounded size="small" severity="danger" title="Delete" @click="forgetJob(data)" />
          </template>
        </Column>
        <template #empty>No failed jobs. 🎉</template>
      </DataTable>

      <Dialog v-model:visible="traceDialog" modal header="Exception trace" :style="{ width: '760px' }">
        <div v-if="trace">
          <p class="mono"><strong>{{ trace.job }}</strong> — failed at {{ trace.failed_at }}</p>
          <pre class="trace">{{ trace.exception }}</pre>
        </div>
      </Dialog>
    </section>

    <!-- ============================== Backups ============================== -->
    <section v-if="activeTab === 'backups'">
      <div class="section-head">
        <span class="muted">Database dump + application storage, via spatie/laravel-backup. Restores are a manual DB-import step.</span>
        <div class="actions">
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="backupsLoading" @click="loadBackups" />
          <Button v-if="canCreateBackup" label="Run backup now" icon="pi pi-play" size="small" :loading="backupRunning" @click="runBackup" />
        </div>
      </div>

      <DataTable :value="backups" :loading="backupsLoading" class="p-datatable-sm" paginator :rows="15">
        <Column field="filename" header="Archive" />
        <Column header="Size" style="width: 8rem">
          <template #body="{ data }">{{ fmtBytes(data.size) }}</template>
        </Column>
        <Column field="created_at" header="Created" style="width: 14rem" sortable />
        <Column header="Actions" style="width: 7rem">
          <template #body="{ data }">
            <Button icon="pi pi-download" text rounded size="small" title="Download"
              @click="downloadBlob(`/ops/backups/${encodeURIComponent(data.filename)}/download`, data.filename)" />
            <Button v-if="canDeleteBackup" icon="pi pi-trash" text rounded size="small" severity="danger" title="Delete" @click="deleteBackup(data)" />
          </template>
        </Column>
        <template #empty>No backups yet.</template>
      </DataTable>
    </section>

    <!-- ============================== Logs ============================== -->
    <section v-if="activeTab === 'logs'">
      <div class="section-head">
        <div class="actions wrap">
          <Select v-model="logFile" :options="logFiles" optionLabel="filename" optionValue="filename"
            placeholder="Log file" class="w-16" @change="loadLogEntries" />
          <Select v-model="logLevel" :options="levelOptions" optionLabel="label" optionValue="value"
            placeholder="All levels" showClear class="w-11" @change="loadLogEntries" />
          <Select v-model="logLimit" :options="limitOptions" optionLabel="label" optionValue="value" class="w-10" @change="loadLogEntries" />
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="logsLoading" @click="loadLogEntries" />
          <Button v-if="logFile" label="Download" icon="pi pi-download" size="small" text
            @click="downloadBlob(`/ops/logs/${encodeURIComponent(logFile)}/download`, logFile)" />
        </div>
      </div>

      <p v-if="logData?.truncated" class="muted note">
        Large file ({{ fmtBytes(logData.size) }}) — showing entries from the last 512 KB only. Download for the full log.
      </p>

      <div v-if="logData" class="log-list">
        <div v-for="(entry, i) in logEntries" :key="i" class="log-entry" :class="{ open: expandedEntry === i }"
          @click="expandedEntry = expandedEntry === i ? null : i">
          <div class="log-line">
            <span class="mono muted ts">{{ entry.timestamp }}</span>
            <Tag :severity="levelSeverity[entry.level] ?? 'secondary'" :value="entry.level" class="mini-tag" />
            <span class="mono msg" :class="{ ellipsis: expandedEntry !== i }">{{ entry.message }}</span>
            <i v-if="entry.context" class="pi muted" :class="expandedEntry === i ? 'pi-chevron-up' : 'pi-chevron-down'" />
          </div>
          <pre v-if="expandedEntry === i && entry.context" class="trace">{{ entry.context }}</pre>
        </div>
        <p v-if="!logData.entries.length" class="muted">No entries match.</p>
      </div>
      <p v-else-if="!logFiles.length && !logsLoading" class="muted">No log files found in storage/logs.</p>
    </section>

    <!-- ============================== Feature flags ============================== -->
    <section v-if="activeTab === 'flags'">
      <div class="section-head">
        <span class="muted">Platform-level switches. Code treats unknown or deleted flags as disabled.</span>
        <div class="actions">
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="flagsLoading" @click="loadFlags" />
          <Button v-if="canEditFlags" label="New Flag" icon="pi pi-plus" size="small" @click="openCreateFlag" />
        </div>
      </div>

      <DataTable :value="flags" :loading="flagsLoading" dataKey="id" class="p-datatable-sm">
        <Column field="key" header="Key">
          <template #body="{ data }"><span class="mono">{{ data.key }}</span></template>
        </Column>
        <Column field="label" header="Label" />
        <Column field="description" header="Description">
          <template #body="{ data }"><span class="muted">{{ data.description || '—' }}</span></template>
        </Column>
        <Column header="Enabled" style="width: 7rem">
          <template #body="{ data }">
            <ToggleSwitch v-if="canEditFlags" v-model="data.enabled" @update:modelValue="(v: boolean) => toggleFlag(data, v)" />
            <Tag v-else :severity="data.enabled ? 'success' : 'secondary'" :value="data.enabled ? 'On' : 'Off'" />
          </template>
        </Column>
        <Column header="Last change" style="width: 14rem">
          <template #body="{ data }">
            <span class="muted">{{ data.updated_by || '—' }}<template v-if="data.updated_at"> · {{ data.updated_at.slice(0, 16).replace('T', ' ') }}</template></span>
          </template>
        </Column>
        <Column v-if="canEditFlags" header="Actions" style="width: 7rem">
          <template #body="{ data }">
            <Button icon="pi pi-pencil" text rounded size="small" @click="openEditFlag(data)" />
            <Button icon="pi pi-trash" text rounded size="small" severity="danger" @click="deleteFlag(data)" />
          </template>
        </Column>
        <template #empty>No feature flags defined yet.</template>
      </DataTable>

      <Dialog v-model:visible="flagDialog" modal :header="editingFlag ? 'Edit Feature Flag' : 'New Feature Flag'" :style="{ width: '480px' }">
        <div class="form-grid">
          <div class="form-field full">
            <label>Key <span class="req">*</span></label>
            <InputText v-model="flagForm.key" placeholder="e.g. new-billing-ui" :class="{ invalid: flagError('key') }" />
            <small v-if="flagError('key')" class="error-text">{{ flagError('key') }}</small>
            <small v-else class="hint">Lowercase letters, digits and - _ . separators.</small>
          </div>
          <div class="form-field full">
            <label>Label <span class="req">*</span></label>
            <InputText v-model="flagForm.label" :class="{ invalid: flagError('label') }" />
            <small v-if="flagError('label')" class="error-text">{{ flagError('label') }}</small>
          </div>
          <div class="form-field full">
            <label>Description</label>
            <Textarea v-model="flagForm.description" rows="2" autoResize :class="{ invalid: flagError('description') }" />
            <small v-if="flagError('description')" class="error-text">{{ flagError('description') }}</small>
          </div>
          <div class="form-field">
            <label>Enabled</label>
            <div class="checkbox-row">
              <Checkbox v-model="flagForm.enabled" binary inputId="flag-enabled" />
              <label for="flag-enabled">On</label>
            </div>
          </div>
        </div>
        <template #footer>
          <Button label="Cancel" text @click="flagDialog = false" />
          <Button :label="editingFlag ? 'Update' : 'Create'" :loading="flagSaving" @click="saveFlag" />
        </template>
      </Dialog>
    </section>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }

.tab-bar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1.25rem; flex-wrap: wrap; }
.tab {
  display: flex; align-items: center; gap: 0.45rem; padding: 0.55rem 0.9rem; border: none; background: none;
  font-size: 0.9rem; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: #1f2937; }
.tab.active { color: #b45309; border-bottom-color: #e8a33d; }

.section-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.actions { display: flex; gap: 0.5rem; align-items: center; }
.actions.wrap { flex-wrap: wrap; }
.overall { display: flex; align-items: center; gap: 0.6rem; }
.sub-heading { font-size: 1.02rem; font-weight: 600; margin: 1.5rem 0 0.6rem; }

.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.9rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.9rem 1rem; }
.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; }
.card-title { font-weight: 600; font-size: 0.92rem; margin-bottom: 0.4rem; display: block; }
.card-top .card-title { margin-bottom: 0; }
.card-detail { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.85rem; color: #374151; }

.kv { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.9rem; font-size: 0.84rem; margin: 0; }
.kv dt { color: #6b7280; }
.kv dd { margin: 0; overflow-wrap: anywhere; }

.stats { display: flex; gap: 1.75rem; }
.stat { display: flex; flex-direction: column; line-height: 1.2; }
.stat-value { font-size: 1.4rem; font-weight: 700; }
.stat-value.danger { color: #dc2626; }
.stat-label { font-size: 0.75rem; color: #6b7280; }

.muted { color: #9ca3af; font-size: 0.82rem; }
.note { margin-bottom: 0.75rem; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.82rem; }
.ellipsis { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.trace { background: #1f2937; color: #e5e7eb; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.76rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word; margin-top: 0.5rem; }
.mini-tag { font-size: 0.66rem; }
.mini-tag :deep(.p-tag-label) { font-size: 0.66rem; }

.log-list { display: flex; flex-direction: column; gap: 0.3rem; }
.log-entry { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.45rem 0.75rem; cursor: pointer; }
.log-entry.open { border-color: #d1d5db; }
.log-line { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.log-line .ts { flex-shrink: 0; }
.log-line .msg { flex: 1; min-width: 0; }
.log-line i { flex-shrink: 0; font-size: 0.7rem; }

.w-10 { width: 10rem; }
.w-11 { width: 11rem; }
.w-16 { width: 16rem; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-textarea) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
