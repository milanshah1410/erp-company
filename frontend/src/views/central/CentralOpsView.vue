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
    { key: 'tools', label: 'Tenant Tools', icon: 'pi pi-wrench', visible: auth.can('ops-tools.view') },
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

// ---- Tenant Tools -------------------------------------------------------------
// The Demo Data generator (§7) used to live on the tenant's own System Tools
// screen; it is an operator tool, so it runs here against a chosen tenant. The
// tenant keeps Import Data only.
const canRunTools = computed(() => auth.can('ops-tools.manage'))

const toolTenants = ref<any[]>([])
const toolTenantId = ref<number | null>(null)
const toolTenant = computed(() => toolTenants.value.find((t) => t.id === toolTenantId.value) ?? null)

// The picker mirrors the ERP menu: sections (Masters, Sales …) hold the
// functional sub-groups (Products, CRM …) that hold the module chips. The
// grouping comes from the menu catalogue server-side, so it follows the nav.
// `fixture` chips are an industry plugin's curated sample rows rather than a
// generator module: they seed a fixed handful whatever volume is on the dial.
type DemoModule = { key: string; label: string; icon?: string | null; fixture?: boolean }
type DemoGroup = { label: string; icon?: string | null; modules: DemoModule[] }
type DemoSection = { label: string; icon?: string | null; groups: DemoGroup[] }

const demoSections = ref<DemoSection[]>([])
const demoCounts = ref<number[]>([10, 20, 50])
const demoBulkCounts = ref<number[]>([])
const demoCount = ref(10)
const demoBulk = ref<number | null>(null)
const demoSelected = ref<string[]>([])
const demoRunning = ref(false)
const demoLoading = ref(false)
const demoSearch = ref('')
const demoOpen = ref<string[]>([])

const demoIsBulk = computed(() => demoBulkCounts.value.includes(demoCount.value))
const demoBulkOptions = computed(() =>
  demoBulkCounts.value.map((v) => ({
    label: `${v >= 1_000_000 ? v / 1_000_000 + 'M' : v / 1_000 + 'K'} (${v.toLocaleString()})`,
    value: v,
  })),
)
const demoPicked = computed(() => new Set(demoSelected.value))

/** Sections/groups narrowed to the search term; a section title match keeps it whole. */
const demoVisibleSections = computed<DemoSection[]>(() => {
  const q = demoSearch.value.trim().toLowerCase()
  if (!q) return demoSections.value
  return demoSections.value
    .map((s) => {
      if (s.label.toLowerCase().includes(q)) return s
      const groups = s.groups
        .map((g) =>
          g.label.toLowerCase().includes(q)
            ? g
            : { ...g, modules: g.modules.filter((m) => m.label.toLowerCase().includes(q)) },
        )
        .filter((g) => g.modules.length)
      return { ...s, groups }
    })
    .filter((s) => s.groups.length)
})

const demoTotalCount = computed(() => demoSections.value.reduce((n, s) => n + moduleKeys(s).length, 0))

/**
 * How much of the current selection is plugin fixtures — the volume on the dial
 * does not apply to those, so the count next to the button would otherwise
 * promise rows the run will not produce.
 */
const demoPickedFixtures = computed(
  () =>
    demoSections.value
      .flatMap((s) => s.groups.flatMap((g) => g.modules))
      .filter((m) => m.fixture && demoPicked.value.has(m.key)).length,
)

function moduleKeys(scope: DemoSection | DemoGroup): string[] {
  const groups = 'groups' in scope ? scope.groups : [scope]
  return groups.flatMap((g) => g.modules.map((m) => m.key))
}

function pickedCount(scope: DemoSection | DemoGroup): number {
  return moduleKeys(scope).filter((k) => demoPicked.value.has(k)).length
}

/** Searching auto-reveals what matched; otherwise sections stay where the user left them. */
function isSectionOpen(label: string): boolean {
  return !!demoSearch.value.trim() || demoOpen.value.includes(label)
}

function toggleSection(label: string) {
  const i = demoOpen.value.indexOf(label)
  if (i >= 0) demoOpen.value.splice(i, 1)
  else demoOpen.value.push(label)
}

function pickDemoCount(c: number) {
  demoCount.value = c
  demoBulk.value = null
}

watch(demoBulk, (v) => {
  demoCount.value = v ?? demoCounts.value[0] ?? 10
})

function toggleDemoModule(key: string) {
  const i = demoSelected.value.indexOf(key)
  if (i >= 0) demoSelected.value.splice(i, 1)
  else demoSelected.value.push(key)
}

/** Select every module in a section/group, or clear them if all are already on. */
function toggleScope(scope: DemoSection | DemoGroup) {
  const keys = moduleKeys(scope)
  const all = keys.every((k) => demoPicked.value.has(k))
  demoSelected.value = all
    ? demoSelected.value.filter((k) => !keys.includes(k))
    : [...new Set([...demoSelected.value, ...keys])]
}

function selectAllModules() {
  demoSelected.value = demoSections.value.flatMap((s) => moduleKeys(s))
}

async function loadTools() {
  try {
    const { data } = await centralHttp.get('/tenants', { params: { per_page: 200 } })
    toolTenants.value = data.data ?? data
    toolTenantId.value = toolTenants.value[0]?.id ?? null
    await loadDemoOptions()
  } catch (e: any) {
    apiError(e, 'Could not load tenant tools')
  }
}

/**
 * The picker is read for the selected tenant: the sections come back as that
 * tenant's own menu (plan + enabled modules applied), so switching tenant
 * changes which modules are on offer.
 */
async function loadDemoOptions() {
  demoLoading.value = true
  try {
    const { data } = await centralHttp.get('/ops/demo-data/options', {
      params: toolTenantId.value ? { tenant_id: toolTenantId.value } : {},
    })
    demoSections.value = data.sections ?? []
    demoCounts.value = data.counts ?? [10, 20, 50]
    demoBulkCounts.value = data.bulk_counts ?? []
    if (!demoCounts.value.includes(demoCount.value) && !demoBulkCounts.value.includes(demoCount.value)) {
      demoCount.value = demoCounts.value[0] ?? 10
    }
    // Drop picks the new tenant does not offer, and open the first section so
    // the picker reads as a menu instead of a wall of collapsed rows.
    const available = new Set(demoSections.value.flatMap((s) => moduleKeys(s)))
    demoSelected.value = demoSelected.value.filter((k) => available.has(k))
    demoOpen.value = demoSections.value[0] ? [demoSections.value[0].label] : []
  } catch (e: any) {
    demoSections.value = []
    apiError(e, 'Could not load the demo module list')
  } finally {
    demoLoading.value = false
  }
}

// Switching tenant re-reads its module catalogue.
watch(toolTenantId, () => {
  if (activeTab.value === 'tools') loadDemoOptions()
})

async function runDemoData() {
  if (!toolTenantId.value || !demoSelected.value.length) return

  if (demoIsBulk.value) {
    const res = await Swal.fire({
      title: `Generate ${demoCount.value.toLocaleString()} rows per module?`,
      text: `${demoSelected.value.length} module(s) into ${toolTenant.value?.company_name ?? 'this tenant'}. This runs on the queue and can take hours.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#c1502e',
      confirmButtonText: 'Queue it',
    })
    if (!res.isConfirmed) return
  }

  demoRunning.value = true
  try {
    const { data } = await centralHttp.post(`/ops/demo-data/${toolTenantId.value}`, {
      modules: demoSelected.value,
      count: demoCount.value,
    })
    toast.add({
      severity: data.queued ? 'info' : 'success',
      summary: data.queued ? 'Generation queued' : 'Demo data created',
      detail: data.message,
      life: data.queued ? 8000 : 5000,
    })
  } catch (e: any) {
    apiError(e, 'Could not generate demo data')
  } finally {
    demoRunning.value = false
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
  tools: loadTools,
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

    <!-- =========================== Tenant Tools =========================== -->
    <section v-if="activeTab === 'tools'">
      <div class="section-head">
        <div class="tenant-pick">
          <label>Tenant</label>
          <Select
            v-model="toolTenantId"
            :options="toolTenants"
            optionLabel="company_name"
            optionValue="id"
            filter
            placeholder="Select a tenant"
            class="tenant-select"
          />
        </div>
        <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="demoLoading" @click="loadDemoOptions" />
      </div>

      <!-- Demo Data generator (§7), writing into the selected tenant -->
      <h3 class="tool-title"><i class="pi pi-database" /> Demo Data</h3>
      <p class="muted">
        Generates sample records into
        <b>{{ toolTenant?.company_name ?? '—' }}</b>. The modules below are that tenant's own menu
        — its plan and enabled modules decide what is on offer. Pick a volume and the modules, then run.
        Chips marked <span class="fx-tag">sample</span> are an industry plugin's curated fixtures: they
        fill that vertical's own screens with a fixed handful of rows, whatever volume is selected, and
        re-running one refreshes it instead of doubling it.
      </p>

      <div class="demo-bar">
        <div class="count-row">
          <button
            v-for="c in demoCounts"
            :key="c"
            type="button"
            class="count-btn"
            :class="{ active: demoCount === c }"
            @click="pickDemoCount(c)"
          >
            {{ c.toLocaleString() }}
          </button>
          <Select
            v-model="demoBulk"
            :options="demoBulkOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Bulk volume"
            showClear
            class="bulk-select"
          />
        </div>
        <span class="demo-search">
          <i class="pi pi-search" />
          <InputText v-model="demoSearch" placeholder="Search modules…" />
        </span>
        <Button
          :label="demoSelected.length ? `Generate for ${demoSelected.length} module(s)` : 'Select modules'"
          icon="pi pi-play"
          :loading="demoRunning"
          :disabled="!canRunTools || !toolTenantId || !demoSelected.length"
          @click="runDemoData"
        />
      </div>

      <p v-if="demoIsBulk" class="bulk-warn">
        <i class="pi pi-exclamation-triangle" />
        {{ demoCount.toLocaleString() }} rows per module runs on the queue worker and can take hours.
      </p>
      <p v-if="demoPickedFixtures" class="muted">
        <i class="pi pi-info-circle" />
        {{ demoPickedFixtures }} of the selected chip(s) are plugin sample sets — they seed their fixed
        rows straight away and ignore the volume above.
      </p>
      <p v-if="!canRunTools" class="muted">You need the “ops-tools.manage” permission to generate data.</p>

      <!-- Module picker, grouped exactly like the ERP menu: section → sub-group → chips -->
      <div v-if="demoSections.length" class="picker-head">
        <span class="picked-pill" :class="{ on: demoSelected.length }">
          <i class="pi pi-check-circle" />
          {{ demoSelected.length }} of {{ demoTotalCount }} selected
        </span>
        <div class="picker-links">
          <button type="button" class="link-btn" :disabled="!canRunTools" @click="selectAllModules">Select all</button>
          <button type="button" class="link-btn" :disabled="!demoSelected.length" @click="demoSelected = []">Clear</button>
          <button type="button" class="link-btn" @click="demoOpen = demoSections.map((s) => s.label)">Expand all</button>
          <button type="button" class="link-btn" @click="demoOpen = []">Collapse all</button>
        </div>
      </div>

      <div class="menu-picker">
        <div
          v-for="s in demoVisibleSections"
          :key="s.label"
          class="menu-section"
          :class="{ open: isSectionOpen(s.label), active: pickedCount(s) > 0 }"
        >
          <div class="menu-section-head">
            <button type="button" class="sec-main" @click="toggleSection(s.label)">
              <span class="sec-icon"><i :class="s.icon || 'pi pi-folder'" /></span>
              <span class="sec-text">
                <b>{{ s.label }}</b>
                <small>
                  {{ moduleKeys(s).length }} module{{ moduleKeys(s).length === 1 ? '' : 's' }}
                  <template v-if="pickedCount(s)"> · {{ pickedCount(s) }} selected</template>
                </small>
              </span>
              <span v-if="pickedCount(s)" class="sec-count">{{ pickedCount(s) }}</span>
              <i class="pi chevron" :class="isSectionOpen(s.label) ? 'pi-chevron-down' : 'pi-chevron-right'" />
            </button>
            <button type="button" class="link-btn" :disabled="!canRunTools" @click="toggleScope(s)">
              {{ pickedCount(s) === moduleKeys(s).length ? 'Deselect all' : 'Select all' }}
            </button>
          </div>

          <div v-show="isSectionOpen(s.label)" class="menu-section-body">
            <div v-for="g in s.groups" :key="g.label" class="menu-group">
              <div class="menu-group-head">
                <span class="grp-label">
                  <i :class="g.icon || 'pi pi-angle-right'" />
                  {{ g.label || s.label }}
                </span>
                <button type="button" class="link-btn tiny" :disabled="!canRunTools" @click="toggleScope(g)">
                  {{ pickedCount(g) === g.modules.length ? 'clear' : 'all' }}
                </button>
              </div>
              <div class="demo-grid">
                <button
                  v-for="m in g.modules"
                  :key="m.key"
                  type="button"
                  class="demo-chip"
                  :class="{ picked: demoPicked.has(m.key), fixture: m.fixture }"
                  :disabled="!canRunTools"
                  :title="m.fixture ? 'Curated sample rows from the industry plugin — seeded once, the row count does not apply.' : undefined"
                  @click="toggleDemoModule(m.key)"
                >
                  <i :class="demoPicked.has(m.key) ? 'pi pi-check-circle' : m.icon || 'pi pi-circle'" />
                  {{ m.label }}
                  <span v-if="m.fixture" class="fx-tag">sample</span>
                </button>
              </div>
            </div>
          </div>
        </div>
        <p v-if="demoLoading" class="muted"><i class="pi pi-spin pi-spinner" /> Reading the tenant's menu…</p>
        <p v-else-if="!demoSections.length" class="muted">
          This tenant has no menu modules the generator can fill.
        </p>
        <p v-else-if="!demoVisibleSections.length" class="muted">No module matches “{{ demoSearch }}”.</p>
      </div>
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

/* ---- Tenant Tools tab (performance + demo data for a chosen tenant) ---- */
.tenant-pick { display: flex; align-items: center; gap: 0.5rem; }
.tenant-pick label { font-size: 0.8rem; font-weight: 600; color: #6b7280; }
.tenant-select { min-width: 260px; }
.tool-title { display: flex; align-items: center; gap: 0.4rem; font-size: 0.95rem; font-weight: 700; margin: 1.4rem 0 0.6rem; }
.tool-title i { color: #e8a33d; }
.demo-bar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin: 0.75rem 0; }
.count-row { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.count-btn { border: 1px solid #e3ded3; background: #fff; color: #1e2328; border-radius: 8px; padding: 0.45rem 0.9rem; font-weight: 600; font-size: 0.83rem; cursor: pointer; font-family: inherit; }
.count-btn.active { background: #e8a33d; border-color: #e8a33d; color: #23292f; }
.bulk-select { min-width: 11rem; }
.demo-search { position: relative; display: inline-flex; align-items: center; }
.demo-search > i { position: absolute; left: 0.65rem; font-size: 0.8rem; color: #9ca3af; pointer-events: none; }
.demo-search :deep(.p-inputtext) { width: 220px; padding-left: 2rem; border-radius: 999px; }
.bulk-warn { display: flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; font-weight: 600; color: #c1502e; margin: 0 0 0.5rem; }

/* ---- Menu-shaped module picker (section → sub-group → chips) ---- */
.picker-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin: 0.9rem 0 0.5rem; }
.picked-pill {
  display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; font-weight: 700;
  color: #6b7280; background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 0.3rem 0.75rem;
}
.picked-pill.on { color: #8a5a12; background: rgba(232, 163, 61, 0.14); border-color: rgba(232, 163, 61, 0.5); }
.picked-pill.on i { color: #4c8b6c; }
.picker-links { display: flex; align-items: center; gap: 0.15rem; flex-wrap: wrap; }
.link-btn {
  border: none; background: none; font-family: inherit; font-size: 0.76rem; font-weight: 700; letter-spacing: 0.01em;
  color: #b45309; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 6px;
}
.link-btn:hover:not(:disabled) { background: rgba(232, 163, 61, 0.14); }
.link-btn:disabled { color: #b6b0a4; cursor: not-allowed; }
.link-btn.tiny { font-size: 0.7rem; text-transform: uppercase; color: #9ca3af; }
.link-btn.tiny:hover:not(:disabled) { color: #b45309; }

.menu-picker { display: flex; flex-direction: column; gap: 0.5rem; }
.menu-section {
  background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
  border-left: 3px solid transparent; transition: border-color 0.15s, box-shadow 0.15s;
}
.menu-section:hover { box-shadow: 0 1px 3px rgba(30, 35, 40, 0.07); }
.menu-section.active { border-left-color: #e8a33d; }
.menu-section.open { box-shadow: 0 2px 8px rgba(30, 35, 40, 0.06); }

.menu-section-head { display: flex; align-items: center; gap: 0.5rem; padding-right: 0.75rem; }
.sec-main {
  flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.7rem; border: none; background: none;
  font-family: inherit; text-align: left; cursor: pointer; padding: 0.7rem 0.4rem 0.7rem 0.85rem;
}
.sec-icon {
  display: grid; place-items: center; width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px;
  background: rgba(232, 163, 61, 0.14); color: #b45309; font-size: 0.85rem;
}
.menu-section.active .sec-icon { background: #e8a33d; color: #23292f; }
.sec-text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.sec-text b { font-size: 0.9rem; font-weight: 700; color: var(--graphite); }
.sec-text small { font-size: 0.72rem; color: #9ca3af; }
.sec-count {
  flex-shrink: 0; min-width: 1.4rem; text-align: center; font-size: 0.7rem; font-weight: 800;
  background: #4c8b6c; color: #fff; border-radius: 999px; padding: 0.1rem 0.42rem;
}
.chevron { margin-left: auto; font-size: 0.72rem; color: #9ca3af; flex-shrink: 0; }

.menu-section-body { border-top: 1px dashed var(--border); padding: 0.5rem 0.85rem 0.85rem; display: flex; flex-direction: column; gap: 0.7rem; }
.menu-group + .menu-group { border-top: 1px solid var(--border); padding-top: 0.6rem; }
.menu-group-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 0.35rem; }
.grp-label {
  display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.73rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;
}
.grp-label i { color: #e8a33d; font-size: 0.78rem; }

.demo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.4rem; margin-top: 0.4rem; }
.demo-chip {
  display: flex; align-items: center; gap: 0.45rem; border: 1px solid var(--border); background: var(--surface);
  border-radius: 9px; padding: 0.45rem 0.6rem; font-size: 0.8rem; font-weight: 600; color: var(--graphite-light);
  cursor: pointer; font-family: inherit; text-align: left; transition: border-color 0.12s, background 0.12s;
}
.demo-chip i { color: #cfc8b8; font-size: 0.85rem; flex-shrink: 0; }
.demo-chip:hover:not(:disabled) { border-color: #e8c489; background: rgba(232, 163, 61, 0.06); }
.demo-chip.picked { border-color: #e8a33d; background: rgba(232, 163, 61, 0.12); color: var(--graphite); }
.demo-chip.picked i { color: #4c8b6c; }
.demo-chip:disabled { opacity: 0.55; cursor: not-allowed; }
.demo-chip.fixture { border-style: dashed; }
.fx-tag {
  margin-left: auto; padding: 0.05rem 0.35rem; border-radius: 999px; font-size: 0.63rem;
  font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase;
  background: rgba(76, 139, 108, 0.14); color: #3f7a5e;
}

@media (max-width: 640px) {
  .demo-search :deep(.p-inputtext) { width: 100%; }
  .demo-search { width: 100%; }
  .sec-text small { display: none; }
}
</style>
