<script setup lang="ts">
/**
 * Device Manager — the configuration screen for the plugin-based hardware layer.
 *
 * Everything on the left is rendered from /devices/catalogue: the device classes,
 * their brand adapters and each adapter's own configuration fields. Adding a
 * brand on the backend makes it appear here with its fields, unchanged code.
 *
 * Three things sit on every device card: Test Connection, a Live/Simulator mode
 * switch, and the Simulator panel. The panel is the point — a mock scan, print
 * preview, fake weight, virtual tag read or virtual fingerprint event exercises
 * the same driver code the hardware will, so a counter or shop-floor flow can be
 * built and demonstrated before any device is bought.
 *
 * The right column tails the live event log with an `after_id` cursor, so the
 * 2-second poll stays cheap however long the log grows.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import { usePt } from '../i18n/pt'

const pt = usePt()
const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('devices.create'))
const canEdit = computed(() => auth.can('devices.edit'))
const canDelete = computed(() => auth.can('devices.delete'))
const canSeeLog = computed(() => auth.can('device-events.view'))

interface Field {
  key: string
  label: string
  type: 'text' | 'number' | 'password' | 'select' | 'checkbox'
  required?: boolean
  default?: any
  options?: string[]
  help?: string
}
interface DriverMeta {
  driver: string
  label: string
  models: string
  settings: Field[]
  credentials: Field[]
  help: string
}
interface TypeMeta {
  type: string
  label: string
  icon: string
  description: string
  actions: string[]
  simulate: string
  simulate_label: string
  drivers: DriverMeta[]
}

const catalogue = ref<TypeMeta[]>([])
const devices = ref<any[]>([])
const warehouses = ref<any[]>([])
const hrDevices = ref<any[]>([])
const loading = ref(true)
const busy = ref<Record<string, boolean>>({})

const statusSeverity: Record<string, string> = {
  online: 'success',
  offline: 'secondary',
  error: 'danger',
  unknown: 'secondary',
}

const typeFilter = ref<string | null>(null)
const visibleDevices = computed(() =>
  typeFilter.value ? devices.value.filter((d) => d.type === typeFilter.value) : devices.value,
)
const countByType = computed(() => {
  const out: Record<string, number> = {}
  for (const d of devices.value) out[d.type] = (out[d.type] ?? 0) + 1
  return out
})

function typeMeta(type: string): TypeMeta | undefined {
  return catalogue.value.find((t) => t.type === type)
}
function driverMeta(type: string, driver: string): DriverMeta | undefined {
  return typeMeta(type)?.drivers.find((d) => d.driver === driver)
}

async function loadAll() {
  loading.value = true
  try {
    const [cat, list] = await Promise.all([http.get('/devices/catalogue'), http.get('/devices')])
    catalogue.value = cat.data.data ?? []
    devices.value = list.data.data ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Load failed'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    loading.value = false
  }
  // Optional lookups — a user without these permissions still gets the screen.
  http.get('/warehouses').then((r) => (warehouses.value = r.data.data ?? r.data ?? [])).catch(() => {})
  http.get('/biometric-devices').then((r) => (hrDevices.value = r.data.data ?? [])).catch(() => {})
}

// ---------------------------------------------------------------------------
// Configuration dialog
// ---------------------------------------------------------------------------
const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const editingId = ref<number | null>(null)
const form = reactive<any>({
  type: '',
  driver: '',
  code: '',
  name: '',
  location: '',
  mode: 'simulator',
  is_active: true,
  is_default: false,
  warehouse_id: null,
  biometric_device_id: null,
  settings: {} as Record<string, any>,
  credentials: {} as Record<string, string>,
})
const savedCreds = ref<Record<string, boolean>>({})

const formDriver = computed(() => driverMeta(form.type, form.driver))

const modeOptions = computed(() => [
  { label: pt('Simulator'), value: 'simulator' },
  { label: pt('Live'), value: 'live' },
])

/** Seed the settings object from the adapter's declared field defaults. */
function applyDriverDefaults(keepExisting = false) {
  const meta = formDriver.value
  if (!meta) return
  const next: Record<string, any> = keepExisting ? { ...form.settings } : {}
  for (const f of meta.settings) {
    if (next[f.key] === undefined && f.default !== undefined) next[f.key] = f.default
  }
  form.settings = next
}

function openAdd(type: TypeMeta, driver: DriverMeta) {
  editingId.value = null
  errors.value = {}
  form.type = type.type
  form.driver = driver.driver
  form.code = suggestCode(type.type, driver.driver)
  form.name = `${driver.label} ${type.label}`
  form.location = ''
  // New devices start simulated: the screen is usable before the box arrives.
  form.mode = 'simulator'
  form.is_active = true
  form.is_default = (countByType.value[type.type] ?? 0) === 0
  form.warehouse_id = null
  form.biometric_device_id = null
  form.credentials = {}
  savedCreds.value = {}
  applyDriverDefaults()
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  form.type = row.type
  form.driver = row.driver
  form.code = row.code
  form.name = row.name
  form.location = row.location ?? ''
  form.mode = row.mode
  form.is_active = row.is_active
  form.is_default = row.is_default
  form.warehouse_id = row.warehouse_id
  form.biometric_device_id = row.biometric_device_id
  form.settings = { ...(row.settings ?? {}) }
  form.credentials = {}
  savedCreds.value = row.configured_credentials ?? {}
  applyDriverDefaults(true)
  dialogVisible.value = true
}

/** A short unique-ish handle, e.g. BARCODE-TVS-2. */
function suggestCode(type: string, driver: string): string {
  const base = `${type.split('_')[0]}-${driver.split('_')[0]}`.toUpperCase()
  const taken = new Set(devices.value.map((d) => d.code))
  if (!taken.has(base)) return base
  let n = 2
  while (taken.has(`${base}-${n}`)) n++
  return `${base}-${n}`
}

async function save() {
  saving.value = true
  errors.value = {}
  // Only send credentials the user actually typed, so "leave blank to keep" works.
  const credentials: Record<string, string> = {}
  for (const [k, v] of Object.entries(form.credentials)) {
    if (v !== null && v !== undefined && String(v).trim() !== '') credentials[k] = String(v)
  }
  const payload: any = {
    code: form.code,
    name: form.name,
    location: form.location || null,
    mode: form.mode,
    is_active: form.is_active,
    is_default: form.is_default,
    warehouse_id: form.warehouse_id,
    biometric_device_id: form.biometric_device_id,
    settings: form.settings,
    credentials,
  }
  try {
    if (editingId.value) {
      await http.put(`/devices/${editingId.value}`, payload)
    } else {
      await http.post('/devices', { type: form.type, driver: form.driver, ...payload })
    }
    toast.add({ severity: 'success', summary: pt('Device saved'), life: 2500 })
    dialogVisible.value = false
    await loadAll()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      toast.add({ severity: 'warn', summary: pt('Check the highlighted fields'), life: 4000 })
    } else {
      toast.add({ severity: 'error', summary: pt('Save failed'), detail: e.response?.data?.message, life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

function fieldError(prefix: 'settings' | 'credentials', key: string): string | null {
  return errors.value[`${prefix}.${key}`]?.[0] ?? null
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove {name}?', { name: row.name }),
    text: pt('Its configuration, saved credentials and event history are deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/devices/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Removed'), life: 2000 })
    await loadAll()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message, life: 4000 })
  }
}

/** Flip a device between hardware and simulator without opening the dialog. */
async function toggleMode(row: any) {
  const mode = row.mode === 'live' ? 'simulator' : 'live'
  try {
    await http.put(`/devices/${row.id}`, { mode })
    await loadAll()
    toast.add({
      severity: 'success',
      summary: mode === 'live' ? pt('Switched to Live') : pt('Switched to Simulator'),
      life: 2000,
    })
  } catch (e: any) {
    // Live mode enforces the adapter's required fields, so this is a real answer.
    const detail = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {}).flat()[0]
    toast.add({ severity: 'error', summary: pt('Could not switch mode'), detail: String(detail ?? ''), life: 6000 })
  }
}

function isBusy(row: any, key: string) {
  return !!busy.value[`${row.id}:${key}`]
}
function setBusy(row: any, key: string, value: boolean) {
  busy.value = { ...busy.value, [`${row.id}:${key}`]: value }
}

async function test(row: any) {
  setBusy(row, 'test', true)
  try {
    const { data } = await http.post(`/devices/${row.id}/test`)
    toast.add({
      severity: 'success',
      summary: pt('Connection OK'),
      detail: data.message + (data.latency_ms != null ? ` (${data.latency_ms} ms)` : ''),
      life: 5000,
    })
    replaceRow(data.data)
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Connection failed'),
      detail: e.response?.data?.message,
      life: 8000,
    })
    if (e.response?.data?.data) replaceRow(e.response.data.data)
  } finally {
    setBusy(row, 'test', false)
    if (canSeeLog.value) tailEvents()
  }
}

function replaceRow(row: any) {
  if (!row) return
  const i = devices.value.findIndex((d) => d.id === row.id)
  if (i >= 0) devices.value[i] = row
}

// ---------------------------------------------------------------------------
// Simulator panel
// ---------------------------------------------------------------------------
const simVisible = ref(false)
const simDevice = ref<any>(null)
const simRunning = ref(false)
const simResult = ref<any>(null)
const sim = reactive<any>({
  code: '',
  document: 'label',
  copies: 1,
  weight: null as number | null,
  stable: true,
  epcs: '',
  count: 3,
  device_user_ids: '',
  direction: 'in',
  ingest: true,
})

const documentOptions = [
  { label: 'Receipt', value: 'receipt' },
  { label: 'Barcode label', value: 'label' },
  { label: 'Price tag', value: 'price_tag' },
]

function openSimulator(row: any) {
  simDevice.value = row
  simResult.value = null
  sim.code = ''
  sim.weight = null
  sim.stable = true
  sim.epcs = ''
  sim.count = row.type === 'biometric' ? 2 : 3
  sim.device_user_ids = ''
  sim.direction = 'in'
  sim.ingest = true
  sim.document = row.type === 'printer' ? 'label' : 'label'
  simVisible.value = true
}

async function runSimulation() {
  const row = simDevice.value
  if (!row) return
  simRunning.value = true
  simResult.value = null
  const payload: any = {}
  if (row.type === 'barcode' && sim.code) payload.code = sim.code
  if (row.type === 'printer') {
    payload.document = sim.document
    payload.copies = sim.copies
  }
  if (row.type === 'weight_scale') {
    if (sim.weight !== null && sim.weight !== '') payload.weight = sim.weight
    payload.stable = sim.stable
  }
  if (row.type === 'rfid') {
    if (sim.epcs.trim()) payload.epcs = sim.epcs
    payload.count = sim.count
  }
  if (row.type === 'biometric') {
    if (sim.device_user_ids.trim()) payload.device_user_ids = sim.device_user_ids
    payload.count = sim.count
    payload.direction = sim.direction
    payload.ingest = sim.ingest
  }
  try {
    const { data } = await http.post(`/devices/${row.id}/simulate`, payload)
    simResult.value = data.result
    replaceRow(data.data)
  } catch (e: any) {
    simResult.value = { error: e.response?.data?.message || pt('Simulation failed') }
  } finally {
    simRunning.value = false
    if (canSeeLog.value) tailEvents()
  }
}

/** Run the real operation — hits hardware in Live mode, the fake in Simulator. */
async function runAction(row: any, action: string) {
  setBusy(row, action, true)
  try {
    const { data } = await http.post(`/devices/${row.id}/run/${action}`, {})
    const result = data.result ?? {}
    toast.add({ severity: 'success', summary: pt('Done'), detail: describeResult(result), life: 5000 })
    replaceRow(data.data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Failed'), detail: e.response?.data?.message, life: 8000 })
    if (e.response?.data?.data) replaceRow(e.response.data.data)
  } finally {
    setBusy(row, action, false)
    if (canSeeLog.value) tailEvents()
  }
}

function describeResult(result: any): string {
  if (result.scan) return `${result.scan.code} (${result.scan.symbology ?? '—'})`
  if (result.weight) return `${result.weight.weight} ${result.weight.unit}${result.weight.stable ? '' : ' — unstable'}`
  if (result.tags) return pt('{n} tag(s) read', { n: result.tags.length })
  if (result.stats) return pt('{n} punch(es) stored', { n: result.stats.created })
  if (result.print) return result.print.message ?? pt('Sent to printer')
  return pt('Completed')
}

const actionLabels: Record<string, string> = {
  decode: 'Decode',
  poll: 'Poll scan',
  print: 'Test print',
  open_drawer: 'Open drawer',
  read: 'Read weight',
  tare: 'Tare',
  inventory: 'Read tags',
  pull_punches: 'Pull punches',
}
/** Actions offered on the card. `decode` needs a raw frame, so it is dialog-only. */
function cardActions(row: any): string[] {
  return (row.actions ?? []).filter((a: string) => a !== 'decode')
}

// ---------------------------------------------------------------------------
// Live event log (tailing)
// ---------------------------------------------------------------------------
const events = ref<any[]>([])
const lastEventId = ref(0)
const tailing = ref(true)
const logDeviceFilter = ref<number | null>(null)
let timer: number | undefined
const logBody = ref<HTMLElement | null>(null)

const eventIcons: Record<string, string> = {
  connection: 'pi pi-link',
  scan: 'pi pi-qrcode',
  print: 'pi pi-print',
  weight: 'pi pi-sliders-h',
  tag: 'pi pi-wifi',
  punch: 'pi pi-id-card',
  config: 'pi pi-cog',
}

async function loadEvents() {
  if (!canSeeLog.value) return
  try {
    const { data } = await http.get('/device-events', {
      params: { per_page: 40, device_id: logDeviceFilter.value || undefined },
    })
    // The paginated read is newest-first; the pane reads oldest-first.
    events.value = [...(data.data ?? [])].reverse()
    lastEventId.value = events.value.length ? events.value[events.value.length - 1].id : 0
    await scrollLogToEnd()
  } catch {
    /* the log is a convenience — never block the screen on it */
  }
}

async function tailEvents() {
  if (!canSeeLog.value || !lastEventId.value) return loadEvents()
  try {
    const { data } = await http.get('/device-events', {
      params: { after_id: lastEventId.value, device_id: logDeviceFilter.value || undefined },
    })
    const fresh = data.data ?? []
    if (!fresh.length) return
    events.value = [...events.value, ...fresh].slice(-200)
    lastEventId.value = fresh[fresh.length - 1].id
    await scrollLogToEnd()
  } catch {
    /* ignore transient poll failures */
  }
}

async function scrollLogToEnd() {
  await nextTick()
  const el = logBody.value
  if (el) el.scrollTop = el.scrollHeight
}

async function clearLog() {
  const res = await Swal.fire({
    title: pt('Clear the event log?'),
    text: logDeviceFilter.value
      ? pt('Events for the selected device are deleted.')
      : pt('All recorded device events are deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Clear'),
    confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete('/device-events', { params: { device_id: logDeviceFilter.value || undefined } })
    events.value = []
    lastEventId.value = 0
    await loadEvents()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message, life: 4000 })
  }
}

function time(dt: string) {
  return dt ? new Date(dt).toLocaleTimeString() : ''
}
function fmt(dt: string | null) {
  return dt ? new Date(dt).toLocaleString() : '—'
}
/** Print previews and tag lists are worth expanding inline. */
const expanded = ref<Record<number, boolean>>({})
function payloadPreview(ev: any): string | null {
  const p = ev.payload
  if (!p) return null
  if (p.preview) return p.preview
  return JSON.stringify(p, null, 2)
}

onMounted(async () => {
  await loadAll()
  await loadEvents()
  timer = window.setInterval(() => {
    if (tailing.value && !document.hidden) tailEvents()
  }, 2500)
})
onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<template>
  <div class="dm">
    <PageHeader :title="pt('Device Manager')" :count="devices.length" :countLabel="pt('devices')">
      <template #actions>
        <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="loadAll" />
      </template>
    </PageHeader>

    <p class="hint">
      <i class="pi pi-info-circle" />
      {{ pt('Each device runs through a brand adapter. Keep a device in Simulator mode to build and test a flow — mock scans, fake weights, virtual fingerprint events and print previews — with no hardware attached.') }}
    </p>

    <div class="layout">
      <div class="main">
        <!-- Type filter chips -->
        <div class="chips">
          <button class="chip" :class="{ on: typeFilter === null }" @click="typeFilter = null">
            {{ pt('All') }} <span class="n">{{ devices.length }}</span>
          </button>
          <button
            v-for="t in catalogue"
            :key="t.type"
            class="chip"
            :class="{ on: typeFilter === t.type }"
            @click="typeFilter = t.type"
          >
            <i :class="t.icon" /> {{ pt(t.label) }} <span class="n">{{ countByType[t.type] ?? 0 }}</span>
          </button>
        </div>

        <div v-if="loading" class="loading">{{ pt('Loading…') }}</div>

        <!-- Configured devices -->
        <div v-else-if="visibleDevices.length" class="grid">
          <div v-for="row in visibleDevices" :key="row.id" class="card" :class="{ inactive: !row.is_active }">
            <div class="card-head">
              <i :class="row.icon" class="type-icon" />
              <div class="titles">
                <strong>{{ row.name }}</strong>
                <small class="muted">{{ row.driver_label }} · {{ row.code }}</small>
              </div>
              <div class="badges">
                <Tag v-if="row.is_default" :value="pt('Default')" severity="success" />
                <Tag
                  :value="row.mode === 'live' ? pt('Live') : pt('Simulator')"
                  :severity="row.mode === 'live' ? 'warn' : 'info'"
                />
                <Tag :value="pt(row.status)" :severity="statusSeverity[row.status] ?? 'secondary'" />
              </div>
            </div>

            <div class="meta">
              <div><span class="k">{{ pt('Type') }}</span>{{ pt(row.type_label) }}</div>
              <div v-if="row.driver_models"><span class="k">{{ pt('Models') }}</span>{{ row.driver_models }}</div>
              <div v-if="row.location"><span class="k">{{ pt('Location') }}</span>{{ row.location }}</div>
              <div v-if="row.warehouse"><span class="k">{{ pt('Warehouse') }}</span>{{ row.warehouse.name }}</div>
              <div v-if="row.biometric_device"><span class="k">{{ pt('HR device') }}</span>{{ row.biometric_device.name }}</div>
              <div><span class="k">{{ pt('Last tested') }}</span>{{ fmt(row.last_tested_at) }}</div>
              <div><span class="k">{{ pt('Events') }}</span>{{ row.events_count }}</div>
              <div v-if="row.last_error" class="err">
                <i class="pi pi-exclamation-triangle" /> {{ row.last_error }}
              </div>
            </div>

            <div class="mode-row">
              <ToggleSwitch
                :modelValue="row.mode === 'live'"
                :disabled="!canEdit"
                @update:modelValue="toggleMode(row)"
              />
              <span class="mode-label">{{ row.mode === 'live' ? pt('Live hardware') : pt('Simulator mode') }}</span>
            </div>

            <div class="card-actions">
              <Button
                :label="pt('Test Connection')"
                icon="pi pi-bolt"
                size="small"
                outlined
                :loading="isBusy(row, 'test')"
                :disabled="!canEdit"
                @click="test(row)"
              />
              <Button
                :label="pt(row.simulate_label)"
                icon="pi pi-play"
                size="small"
                severity="secondary"
                outlined
                :disabled="!canEdit"
                @click="openSimulator(row)"
              />
              <Button
                v-for="a in cardActions(row)"
                :key="a"
                :label="pt(actionLabels[a] ?? a)"
                size="small"
                text
                :loading="isBusy(row, a)"
                :disabled="!canEdit"
                @click="runAction(row, a)"
              />
              <span class="spacer" />
              <Button icon="pi pi-pencil" size="small" text :disabled="!canEdit" :title="pt('Edit')" @click="openEdit(row)" />
              <Button
                icon="pi pi-trash"
                size="small"
                text
                severity="danger"
                :disabled="!canDelete"
                :title="pt('Remove')"
                @click="remove(row)"
              />
            </div>
          </div>
        </div>

        <div v-else class="empty">
          {{ pt('No devices configured yet. Pick a brand below — it starts in Simulator mode, so you can use it immediately.') }}
        </div>

        <!-- Catalogue: add a device -->
        <h2 class="section">{{ pt('Add a device') }}</h2>
        <div v-for="t in catalogue" :key="t.type" class="cat-block">
          <div class="cat-head">
            <i :class="t.icon" />
            <div>
              <strong>{{ pt(t.label) }}</strong>
              <small>{{ pt(t.description) }}</small>
            </div>
          </div>
          <div class="brand-strip">
            <button
              v-for="d in t.drivers"
              :key="d.driver"
              class="brand-btn"
              :disabled="!canCreate"
              :title="d.models"
              @click="openAdd(t, d)"
            >
              <span class="brand-name">{{ d.label }}</span>
              <small>{{ d.models }}</small>
            </button>
          </div>
        </div>
      </div>

      <!-- Live event log -->
      <aside v-if="canSeeLog" class="log">
        <div class="log-head">
          <strong><i class="pi pi-bolt" /> {{ pt('Live Event Log') }}</strong>
          <div class="log-tools">
            <ToggleSwitch v-model="tailing" />
            <span class="tail-label">{{ tailing ? pt('Tailing') : pt('Paused') }}</span>
          </div>
        </div>
        <div class="log-filter">
          <Select
            v-model="logDeviceFilter"
            :options="devices"
            optionLabel="name"
            optionValue="id"
            :placeholder="pt('All devices')"
            showClear
            size="small"
            @change="loadEvents"
          />
          <Button
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            :disabled="!canDelete"
            :title="pt('Clear log')"
            @click="clearLog"
          />
        </div>
        <div ref="logBody" class="log-body">
          <div v-if="!events.length" class="log-empty">
            {{ pt('Nothing yet. Test a connection or run a simulation and it will appear here.') }}
          </div>
          <div v-for="ev in events" :key="ev.id" class="ev" :class="ev.status">
            <div class="ev-line" @click="expanded[ev.id] = !expanded[ev.id]">
              <i :class="eventIcons[ev.event] ?? 'pi pi-circle'" />
              <span class="ev-time">{{ time(ev.occurred_at) }}</span>
              <span class="ev-device">{{ ev.device_code ?? ev.device_type }}</span>
              <span class="ev-msg">{{ ev.message }}</span>
              <Tag v-if="ev.source === 'simulator'" value="SIM" severity="info" class="sim-tag" />
              <span v-if="ev.duration_ms != null" class="ev-ms">{{ ev.duration_ms }}ms</span>
            </div>
            <pre v-if="expanded[ev.id] && payloadPreview(ev)" class="ev-payload">{{ payloadPreview(ev) }}</pre>
          </div>
        </div>
      </aside>
    </div>

    <!-- Configuration dialog -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editingId ? pt('Edit device') : pt('Add {brand}', { brand: formDriver?.label ?? '' })"
      :style="{ width: '620px' }"
    >
      <div v-if="formDriver" class="form">
        <p class="help"><i class="pi pi-info-circle" /> {{ formDriver.help }}</p>

        <div class="row2">
          <div class="fld">
            <label>{{ pt('Name') }} <span class="req">*</span></label>
            <InputText v-model="form.name" :class="{ invalid: errors.name }" />
            <small v-if="errors.name" class="error-text">{{ errors.name[0] }}</small>
          </div>
          <div class="fld">
            <label>{{ pt('Code') }} <span class="req">*</span></label>
            <InputText v-model="form.code" :class="{ invalid: errors.code }" />
            <small v-if="errors.code" class="error-text">{{ errors.code[0] }}</small>
          </div>
        </div>

        <div class="row2">
          <div class="fld">
            <label>{{ pt('Location') }}</label>
            <InputText v-model="form.location" :placeholder="pt('Counter 1, packing table, gate…')" />
          </div>
          <div class="fld">
            <label>{{ pt('Mode') }}</label>
            <SelectButton v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
            <small class="muted">{{ form.mode === 'simulator' ? pt('No hardware needed — the adapter answers from its built-in fake.') : pt('Required fields must be filled before Live mode saves.') }}</small>
          </div>
        </div>

        <div class="row2">
          <div class="fld">
            <label>{{ pt('Warehouse') }}</label>
            <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="name" optionValue="id" :placeholder="pt('Not linked')" showClear />
          </div>
          <div v-if="form.type === 'biometric'" class="fld">
            <label>{{ pt('HR biometric device') }}</label>
            <Select v-model="form.biometric_device_id" :options="hrDevices" optionLabel="name" optionValue="id" :placeholder="pt('Not linked')" showClear />
            <small class="muted">{{ pt('Punches pulled here are stored against this HR device, so attendance picks them up.') }}</small>
          </div>
        </div>

        <div class="toggles">
          <label class="tg"><ToggleSwitch v-model="form.is_active" /> <span>{{ pt('Active') }}</span></label>
          <label class="tg"><ToggleSwitch v-model="form.is_default" /> <span>{{ pt('Default for this type') }}</span></label>
        </div>

        <!-- Adapter-declared settings -->
        <div v-if="formDriver.settings.length" class="group">
          <h4>{{ pt('Connection & options') }}</h4>
          <div v-for="f in formDriver.settings" :key="f.key" class="fld">
            <template v-if="f.type === 'checkbox'">
              <label class="tg">
                <Checkbox v-model="form.settings[f.key]" :binary="true" :inputId="`s-${f.key}`" />
                <span>{{ f.label }}</span>
              </label>
            </template>
            <template v-else>
              <label>
                {{ f.label }}
                <span v-if="f.required" class="req">*</span>
              </label>
              <Select
                v-if="f.type === 'select'"
                v-model="form.settings[f.key]"
                :options="f.options"
                :class="{ invalid: fieldError('settings', f.key) }"
              />
              <InputNumber
                v-else-if="f.type === 'number'"
                v-model="form.settings[f.key]"
                :useGrouping="false"
                :class="{ invalid: fieldError('settings', f.key) }"
              />
              <InputText
                v-else
                v-model="form.settings[f.key]"
                :class="{ invalid: fieldError('settings', f.key) }"
              />
            </template>
            <small v-if="f.help" class="muted">{{ f.help }}</small>
            <small v-if="fieldError('settings', f.key)" class="error-text">{{ fieldError('settings', f.key) }}</small>
          </div>
        </div>

        <!-- Adapter-declared secrets -->
        <div v-if="formDriver.credentials.length" class="group">
          <h4>{{ pt('Credentials') }}</h4>
          <div v-for="f in formDriver.credentials" :key="f.key" class="fld">
            <label>
              {{ f.label }}
              <span v-if="f.required" class="req">*</span>
              <small v-if="savedCreds[f.key]" class="stored">— {{ pt('saved, leave blank to keep') }}</small>
            </label>
            <Password
              v-model="form.credentials[f.key]"
              toggleMask
              :feedback="false"
              :placeholder="savedCreds[f.key] ? '•••••••• ' : ''"
              :inputStyle="{ width: '100%' }"
              style="width: 100%"
              :class="{ invalid: fieldError('credentials', f.key) }"
            />
            <small v-if="fieldError('credentials', f.key)" class="error-text">{{ fieldError('credentials', f.key) }}</small>
          </div>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Simulator dialog -->
    <Dialog
      v-model:visible="simVisible"
      modal
      :header="pt(simDevice?.simulate_label ?? 'Simulator')"
      :style="{ width: '600px' }"
    >
      <div v-if="simDevice" class="sim">
        <p class="help">
          <i class="pi pi-info-circle" />
          {{ pt('Runs the same adapter code the hardware would, so what you see here is what the device will produce.') }}
        </p>

        <!-- Barcode -->
        <template v-if="simDevice.type === 'barcode'">
          <div class="fld">
            <label>{{ pt('Barcode to scan') }}</label>
            <InputText v-model="sim.code" :placeholder="pt('Leave blank for a random sample code')" />
          </div>
        </template>

        <!-- Printer -->
        <template v-else-if="simDevice.type === 'printer'">
          <div class="row2">
            <div class="fld">
              <label>{{ pt('Document') }}</label>
              <Select v-model="sim.document" :options="documentOptions" optionLabel="label" optionValue="value" />
            </div>
            <div class="fld">
              <label>{{ pt('Copies') }}</label>
              <InputNumber v-model="sim.copies" :min="1" :max="20" showButtons />
            </div>
          </div>
        </template>

        <!-- Weighing scale -->
        <template v-else-if="simDevice.type === 'weight_scale'">
          <div class="row2">
            <div class="fld">
              <label>{{ pt('Weight') }}</label>
              <InputNumber v-model="sim.weight" :minFractionDigits="0" :maxFractionDigits="3" :placeholder="pt('Random')" />
            </div>
            <div class="fld">
              <label class="tg"><ToggleSwitch v-model="sim.stable" /> <span>{{ pt('Stable reading') }}</span></label>
              <small class="muted">{{ pt('Turn off to check that your screen refuses an unsettled weight.') }}</small>
            </div>
          </div>
        </template>

        <!-- RFID -->
        <template v-else-if="simDevice.type === 'rfid'">
          <div class="fld">
            <label>{{ pt('EPCs to read') }}</label>
            <Textarea v-model="sim.epcs" rows="3" :placeholder="pt('Comma or newline separated. Leave blank to generate random tags.')" />
          </div>
          <div class="fld narrow">
            <label>{{ pt('Tag count (when blank)') }}</label>
            <InputNumber v-model="sim.count" :min="1" :max="50" showButtons />
          </div>
        </template>

        <!-- Biometric -->
        <template v-else-if="simDevice.type === 'biometric'">
          <div class="fld">
            <label>{{ pt('Device user IDs') }}</label>
            <Textarea v-model="sim.device_user_ids" rows="2" :placeholder="pt('The employee\'s biometric user id. Blank generates test ids.')" />
          </div>
          <div class="row2">
            <div class="fld">
              <label>{{ pt('Direction') }}</label>
              <SelectButton
                v-model="sim.direction"
                :options="[{ label: pt('In'), value: 'in' }, { label: pt('Out'), value: 'out' }]"
                optionLabel="label"
                optionValue="value"
                :allowEmpty="false"
              />
            </div>
            <div class="fld">
              <label>{{ pt('Punch count (when blank)') }}</label>
              <InputNumber v-model="sim.count" :min="1" :max="50" showButtons />
            </div>
          </div>
          <label class="tg">
            <ToggleSwitch v-model="sim.ingest" />
            <span>{{ pt('Store the punches (feeds HR attendance)') }}</span>
          </label>
        </template>

        <!-- Result -->
        <div v-if="simResult" class="sim-result">
          <div v-if="simResult.error" class="sim-error"><i class="pi pi-times-circle" /> {{ simResult.error }}</div>
          <template v-else>
            <h4>{{ pt('Result') }}</h4>
            <pre v-if="simResult.print">{{ simResult.print.preview }}</pre>
            <pre v-else>{{ JSON.stringify(simResult, null, 2) }}</pre>
            <small v-if="simResult.print" class="muted">
              {{ simResult.print.language.toUpperCase() }} · {{ simResult.print.bytes }} {{ pt('bytes') }} ·
              {{ simResult.print.copies }} {{ pt('copy(ies)') }}
            </small>
          </template>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="simVisible = false" />
        <Button :label="pt('Run')" icon="pi pi-play" :loading="simRunning" @click="runSimulation" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.hint { display: flex; align-items: flex-start; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin: 0 0 1.1rem; max-width: 900px; }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1.25rem; align-items: start; }
@media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }

.chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1rem; }
.chip { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.7rem; border: 1px solid var(--border, #e5e7eb); border-radius: 999px; background: var(--surface, #fff); font-size: 0.82rem; cursor: pointer; }
.chip.on { border-color: #6366f1; background: #eef2ff; color: #4338ca; }
.chip .n { font-size: 0.72rem; opacity: 0.65; }

.loading, .empty { color: #6b7280; font-size: 0.9rem; padding: 1.5rem; text-align: center; border: 1px dashed #e5e7eb; border-radius: 12px; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 1rem; }
.card { border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 1rem; background: var(--surface, #fff); display: flex; flex-direction: column; gap: 0.7rem; }
.card.inactive { opacity: 0.62; }
.card-head { display: flex; align-items: flex-start; gap: 0.6rem; }
.type-icon { font-size: 1.3rem; color: #4b5563; margin-top: 0.15rem; }
.titles { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.titles strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.badges { display: flex; flex-wrap: wrap; gap: 0.25rem; justify-content: flex-end; }
.meta { font-size: 0.82rem; display: flex; flex-direction: column; gap: 0.15rem; }
.meta .k { display: inline-block; width: 96px; color: #6b7280; }
.muted { color: #6b7280; }
.err { color: #dc2626; margin-top: 0.25rem; }
.mode-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; border-top: 1px solid #f1f5f9; padding-top: 0.6rem; }
.mode-label { color: #374151; }
.card-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 0.25rem; border-top: 1px solid #f1f5f9; padding-top: 0.6rem; }
.spacer { flex: 1; }

.section { font-size: 1.05rem; font-weight: 600; margin: 1.75rem 0 0.75rem; }
.cat-block { margin-bottom: 1.1rem; }
.cat-head { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.45rem; }
.cat-head i { font-size: 1.1rem; color: #4b5563; }
.cat-head div { display: flex; flex-direction: column; }
.cat-head small { color: #6b7280; font-size: 0.76rem; }
.brand-strip { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.brand-btn { display: flex; flex-direction: column; align-items: flex-start; gap: 0.15rem; padding: 0.55rem 0.75rem; border: 1px solid var(--border, #e5e7eb); border-radius: 9px; background: var(--surface, #fff); cursor: pointer; text-align: left; max-width: 230px; transition: 0.15s; }
.brand-btn:hover:not(:disabled) { border-color: #6366f1; background: #f5f5ff; }
.brand-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.brand-name { font-size: 0.85rem; font-weight: 600; }
.brand-btn small { color: #6b7280; font-size: 0.7rem; }

/* Live log */
.log { border: 1px solid var(--border, #e5e7eb); border-radius: 12px; background: var(--surface, #fff); display: flex; flex-direction: column; position: sticky; top: 0.5rem; max-height: calc(100vh - 120px); }
.log-head { display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 0.85rem; border-bottom: 1px solid #f1f5f9; }
.log-head strong { font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; }
.log-tools { display: flex; align-items: center; gap: 0.4rem; }
.tail-label { font-size: 0.72rem; color: #6b7280; }
.log-filter { display: flex; gap: 0.35rem; padding: 0.5rem 0.6rem; border-bottom: 1px solid #f1f5f9; }
.log-filter :deep(.p-select) { flex: 1; }
.log-body { overflow-y: auto; padding: 0.4rem 0.5rem; font-size: 0.76rem; flex: 1; min-height: 260px; }
.log-empty { color: #9ca3af; padding: 1.25rem 0.5rem; text-align: center; }
.ev { border-left: 3px solid #e5e7eb; padding: 0.25rem 0.4rem; margin-bottom: 0.2rem; border-radius: 0 4px 4px 0; }
.ev.success { border-left-color: #16a34a; }
.ev.failed { border-left-color: #dc2626; background: #fef2f2; }
.ev.info { border-left-color: #6366f1; }
.ev-line { display: flex; align-items: center; gap: 0.35rem; cursor: pointer; }
.ev-line > i { color: #6b7280; }
.ev-time { color: #9ca3af; font-variant-numeric: tabular-nums; }
.ev-device { font-weight: 600; color: #374151; }
.ev-msg { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ev-ms { color: #9ca3af; font-size: 0.68rem; }
.sim-tag { font-size: 0.6rem; }
.ev-payload { margin: 0.3rem 0 0.1rem; padding: 0.45rem; background: #0f172a; color: #e2e8f0; border-radius: 5px; font-size: 0.68rem; white-space: pre-wrap; word-break: break-all; max-height: 220px; overflow: auto; }

/* Forms */
.form, .sim { display: flex; flex-direction: column; gap: 0.85rem; padding-top: 0.35rem; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
@media (max-width: 560px) { .row2 { grid-template-columns: 1fr; } }
.fld { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
.fld.narrow { max-width: 220px; }
.fld > label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(.p-select), .fld :deep(.p-inputnumber), .fld :deep(.p-textarea) { width: 100%; }
.fld small.muted { font-size: 0.72rem; }
.group { border-top: 1px solid #f1f5f9; padding-top: 0.75rem; display: flex; flex-direction: column; gap: 0.7rem; }
.group h4 { font-size: 0.88rem; font-weight: 600; }
.toggles { display: flex; gap: 1.5rem; flex-wrap: wrap; }
.tg { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #374151; cursor: pointer; }
.req { color: #dc2626; }
.stored { color: #059669; font-weight: 400; }
.error-text { color: #dc2626; font-size: 0.74rem; }
.invalid :deep(.p-inputtext), .invalid :deep(input) { border-color: #dc2626 !important; }
.help { display: flex; align-items: flex-start; gap: 0.45rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.55rem 0.7rem; font-size: 0.78rem; color: #475569; margin: 0; }

.sim-result { border-top: 1px solid #f1f5f9; padding-top: 0.7rem; }
.sim-result h4 { font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; }
.sim-result pre { background: #0f172a; color: #e2e8f0; padding: 0.7rem; border-radius: 7px; font-size: 0.72rem; white-space: pre-wrap; word-break: break-all; max-height: 300px; overflow: auto; }
.sim-error { color: #dc2626; display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
</style>
