<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(25)

const devices = ref<{ label: string; value: number }[]>([])
const filters = ref<{ biometric_device_id: number | null; processed: string | null }>({ biometric_device_id: null, processed: null })
const PROCESSED = [{ label: 'Unprocessed', value: 'false' }, { label: 'Processed', value: 'true' }]

const canImport = computed(() => auth.can('biometric-punches.create'))
const canSync = computed(() => auth.can('attendance.create'))
const canDelete = computed(() => auth.can('biometric-punches.delete'))

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const importVisible = ref(false)
const importing = ref(false)
const importDevice = ref<number | null>(null)
const importText = ref('')

const syncing = ref(false)
const syncRange = ref<{ from: Date | null; to: Date | null }>({ from: null, to: null })

function toDateString(d: Date | null): string | undefined { return d ? d.toISOString().slice(0, 10) : undefined }

async function loadDevices() {
  const { data } = await http.get('/biometric-devices', { params: { per_page: 200 } })
  devices.value = (data.data ?? data).map((d: any) => ({ label: `${d.code} — ${d.name}`, value: d.id }))
}
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/biometric-punches', {
      params: {
        biometric_device_id: filters.value.biometric_device_id || undefined,
        processed: filters.value.processed ?? undefined,
        page: page.value, per_page: perPage.value,
      },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}
function onPage(e: any) { page.value = e.page + 1; perPage.value = e.rows; load() }

function openImport() {
  importDevice.value = filters.value.biometric_device_id
  importText.value = '[\n  { "device_user_id": "1001", "punched_at": "2026-07-15 09:05:00", "direction": "in" },\n  { "device_user_id": "1001", "punched_at": "2026-07-15 18:10:00", "direction": "out" }\n]'
  importVisible.value = true
}

async function runImport() {
  let punches: any
  try {
    punches = JSON.parse(importText.value)
    if (!Array.isArray(punches)) throw new Error('not an array')
  } catch {
    toast.add({ severity: 'error', summary: 'Invalid JSON', detail: 'Provide a JSON array of punches.', life: 4000 })
    return
  }
  importing.value = true
  try {
    const { data } = await http.post('/biometric-punches/import', { biometric_device_id: importDevice.value || undefined, punches })
    toast.add({ severity: 'success', summary: 'Imported', detail: data.message, life: 3500 })
    importVisible.value = false
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Import failed', life: 4000 })
  } finally {
    importing.value = false
  }
}

async function runSync() {
  syncing.value = true
  try {
    const { data } = await http.post('/biometric-punches/sync', {
      from: toDateString(syncRange.value.from),
      to: toDateString(syncRange.value.to),
    })
    toast.add({ severity: 'success', summary: 'Synced', detail: data.message, life: 3500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Sync failed', life: 4000 })
  } finally {
    syncing.value = false
  }
}

async function del(row: any) {
  try {
    await http.delete(`/biometric-punches/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => { await loadDevices(); await load() })
</script>

<template>
  <div>
    <div class="header">
      <h1>Biometric Punches</h1>
      <div class="actions">
        <Button v-if="canImport" label="Import Punches" icon="pi pi-upload" @click="openImport" />
      </div>
    </div>

    <div class="toolbar">
      <Select v-model="filters.biometric_device_id" :options="devices" optionLabel="label" optionValue="value" filter showClear placeholder="All devices" class="w-60" @change="load" />
      <Select v-model="filters.processed" :options="PROCESSED" optionLabel="label" optionValue="value" showClear placeholder="All punches" @change="load" />
      <TableExportButtons title="Biometric Punches" endpoint="/biometric-punches" :params="() => ({
        biometric_device_id: filters.biometric_device_id || undefined,
        processed: filters.processed ?? undefined,
      })" :columns="[
        { key: 'punched_at', label: 'Punched At' },
        { key: 'employee_name', label: 'Employee' },
        { key: 'device_user_id', label: 'Device User' },
        { key: 'device_name', label: 'Device' },
        { key: 'direction', label: 'Direction' },
        { key: 'source', label: 'Source' },
        { key: 'processed', label: 'Processed', format: (v) => v ? 'Yes' : 'No' },
      ]" />
      <div class="spacer" />
      <template v-if="canSync">
        <DatePicker v-model="syncRange.from" dateFormat="yy-mm-dd" placeholder="From" showIcon iconDisplay="input" />
        <DatePicker v-model="syncRange.to" dateFormat="yy-mm-dd" placeholder="To" showIcon iconDisplay="input" />
        <Button label="Sync to Attendance" icon="pi pi-sync" severity="info" :loading="syncing" @click="runSync" />
      </template>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[25, 50, 100, 200]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="punched_at" header="Punched At" />
      <Column field="employee_name" header="Employee" />
      <Column field="device_user_id" header="Device User" />
      <Column field="device_name" header="Device" />
      <Column header="Direction"><template #body="{ data }"><Tag :value="data.direction" :severity="data.direction === 'in' ? 'success' : 'warn'" /></template></Column>
      <Column field="source" header="Source" />
      <Column header="Processed"><template #body="{ data }"><Tag :value="data.processed ? 'Yes' : 'No'" :severity="data.processed ? 'success' : 'secondary'" /></template></Column>
      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canDelete && !data.processed" icon="pi pi-trash" text rounded severity="danger" size="small" @click="del(data)" />
        </template>
      </Column>
      <template #empty>No punches recorded.</template>
    </DataTable>

    <Dialog v-model:visible="importVisible" modal header="Import Biometric Punches" :style="{ width: '560px' }">
      <div class="form">
        <div class="field">
          <label>Device</label>
          <Select v-model="importDevice" :options="devices" optionLabel="label" optionValue="value" filter showClear placeholder="Select device (optional)" />
        </div>
        <div class="field">
          <label>Punches (JSON array)</label>
          <Textarea v-model="importText" rows="10" class="mono" />
          <small class="hint">Each punch needs <code>punched_at</code> and either <code>employee_id</code> or a <code>device_user_id</code> mapped on the employee. <code>direction</code> is <code>in</code>/<code>out</code>.</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="importVisible = false" />
        <Button label="Import" icon="pi pi-upload" :loading="importing" @click="runImport" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Punch Details" :fields="[
      { key: 'punched_at', label: 'Punched At' },
      { key: 'employee_name', label: 'Employee' },
      { key: 'device_user_id', label: 'Device User' },
      { key: 'device_name', label: 'Device' },
      { key: 'direction', label: 'Direction' },
      { key: 'source', label: 'Source' },
      { key: 'processed', label: 'Processed' },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
.spacer { flex: 1; }
.w-60 { width: 240px; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.field :deep(.p-select), .field :deep(.p-inputtext), .field :deep(.p-datepicker) { width: 100%; }
.mono :deep(textarea) { font-family: ui-monospace, monospace; font-size: 0.8rem; }
.hint { color: #6b7280; font-size: 0.78rem; }
</style>
