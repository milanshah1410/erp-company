<script setup lang="ts">
/**
 * Device Event Log — the searchable history behind the Device Manager's live
 * tail. Every connection test, scan, print job, weight reading, tag read and
 * punch pull lands here, marked live or simulated, so a counter problem can be
 * reconstructed after the fact instead of reproduced on the phone.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import { usePt } from '../i18n/pt'

const pt = usePt()
const toast = useToast()
const auth = useAuthStore()
const canClear = computed(() => auth.can('devices.delete'))

const rows = ref<any[]>([])
const devices = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const page = ref(0)
const perPage = ref(50)

const filters = ref<{
  device_id: number | null
  event: string | null
  status: string | null
  source: string | null
  from: Date | null
  to: Date | null
}>({ device_id: null, event: null, status: null, source: null, from: null, to: null })

const eventOptions = [
  { label: 'Connection', value: 'connection' },
  { label: 'Scan', value: 'scan' },
  { label: 'Print', value: 'print' },
  { label: 'Weight', value: 'weight' },
  { label: 'Tag', value: 'tag' },
  { label: 'Punch', value: 'punch' },
]
const statusOptions = [
  { label: 'Success', value: 'success' },
  { label: 'Failed', value: 'failed' },
  { label: 'Info', value: 'info' },
]
const sourceOptions = [
  { label: 'Live', value: 'live' },
  { label: 'Simulator', value: 'simulator' },
]

const statusSeverity: Record<string, string> = { success: 'success', failed: 'danger', info: 'info' }

function queryParams(): Record<string, any> {
  const f = filters.value
  return {
    device_id: f.device_id || undefined,
    event: f.event || undefined,
    status: f.status || undefined,
    source: f.source || undefined,
    from: f.from ? isoDate(f.from) : undefined,
    to: f.to ? isoDate(f.to, true) : undefined,
    per_page: perPage.value,
  }
}

function isoDate(d: Date, endOfDay = false): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
  return endOfDay ? `${date} 23:59:59` : `${date} 00:00:00`
}

async function load(p = page.value) {
  loading.value = true
  page.value = p
  try {
    const { data } = await http.get('/device-events', { params: { ...queryParams(), page: p + 1 } })
    rows.value = data.data ?? []
    total.value = data.meta?.total ?? rows.value.length
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Load failed'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.value = { device_id: null, event: null, status: null, source: null, from: null, to: null }
  load(0)
}

async function clearLog() {
  const res = await Swal.fire({
    title: pt('Clear the event log?'),
    text: filters.value.device_id
      ? pt('Events for the selected device are deleted.')
      : pt('All recorded device events are deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Clear'),
    confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.delete('/device-events', {
      params: { device_id: filters.value.device_id || undefined },
    })
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    await load(0)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message, life: 4000 })
  }
}

// --- Payload viewer ---
const detailVisible = ref(false)
const detailRow = ref<any>(null)
function openDetail(row: any) {
  detailRow.value = row
  detailVisible.value = true
}
const detailText = computed(() => {
  const p = detailRow.value?.payload
  if (!p) return pt('No payload recorded.')
  if (p.preview) return p.preview
  return JSON.stringify(p, null, 2)
})

function fmt(dt: string) {
  return dt ? new Date(dt).toLocaleString() : '—'
}

onMounted(async () => {
  http.get('/devices').then((r) => (devices.value = r.data.data ?? [])).catch(() => {})
  await load(0)
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Device Event Log')" :count="total" :countLabel="pt('events')">
      <template #actions>
        <TableExportButtons
          :title="pt('Device Events')"
          filenameBase="device-events"
          endpoint="/device-events"
          :params="queryParams"
          :columns="[
            { key: 'occurred_at', label: pt('Time') },
            { key: 'device_code', label: pt('Device') },
            { key: 'device_type', label: pt('Type') },
            { key: 'driver', label: pt('Adapter') },
            { key: 'event', label: pt('Event') },
            { key: 'status', label: pt('Status') },
            { key: 'source', label: pt('Source') },
            { key: 'message', label: pt('Message') },
            { key: 'duration_ms', label: pt('ms') },
            { key: 'user', label: pt('User') },
          ]"
        />
        <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load(page)" />
        <Button
          v-if="canClear"
          icon="pi pi-trash"
          text
          rounded
          severity="danger"
          :title="pt('Clear log')"
          @click="clearLog"
        />
      </template>
    </PageHeader>

    <div class="filters">
      <Select
        v-model="filters.device_id"
        :options="devices"
        optionLabel="name"
        optionValue="id"
        :placeholder="pt('All devices')"
        showClear
        @change="load(0)"
      />
      <Select
        v-model="filters.event"
        :options="eventOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All events')"
        showClear
        @change="load(0)"
      />
      <Select
        v-model="filters.status"
        :options="statusOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('Any status')"
        showClear
        @change="load(0)"
      />
      <Select
        v-model="filters.source"
        :options="sourceOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('Live & simulated')"
        showClear
        @change="load(0)"
      />
      <DatePicker v-model="filters.from" dateFormat="dd-mm-yy" :placeholder="pt('From')" showIcon @update:modelValue="load(0)" />
      <DatePicker v-model="filters.to" dateFormat="dd-mm-yy" :placeholder="pt('To')" showIcon @update:modelValue="load(0)" />
      <Button :label="pt('Reset')" text size="small" @click="resetFilters" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :first="page * perPage"
      :rowsPerPageOptions="[25, 50, 100, 200]"
      dataKey="id"
      size="small"
      stripedRows
      @page="load($event.page); perPage = $event.rows"
    >
      <template #empty>
        <div class="empty">{{ pt('No events match these filters.') }}</div>
      </template>

      <Column field="occurred_at" :header="pt('Time')" style="width: 160px">
        <template #body="{ data }"><span class="mono">{{ fmt(data.occurred_at) }}</span></template>
      </Column>
      <Column :header="pt('Device')" style="width: 190px">
        <template #body="{ data }">
          <div class="dev">
            <strong>{{ data.device_name ?? data.device_code ?? '—' }}</strong>
            <small>{{ data.driver }}</small>
          </div>
        </template>
      </Column>
      <Column field="event" :header="pt('Event')" style="width: 110px">
        <template #body="{ data }"><Tag :value="pt(data.event)" severity="secondary" /></template>
      </Column>
      <Column field="status" :header="pt('Status')" style="width: 100px">
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="statusSeverity[data.status] ?? 'secondary'" />
        </template>
      </Column>
      <Column field="source" :header="pt('Source')" style="width: 100px">
        <template #body="{ data }">
          <Tag v-if="data.source === 'simulator'" :value="pt('Simulator')" severity="info" />
          <span v-else class="muted">{{ pt('Live') }}</span>
        </template>
      </Column>
      <Column field="message" :header="pt('Message')">
        <template #body="{ data }"><span class="msg">{{ data.message }}</span></template>
      </Column>
      <Column field="duration_ms" :header="pt('ms')" style="width: 80px">
        <template #body="{ data }"><span class="mono">{{ data.duration_ms ?? '—' }}</span></template>
      </Column>
      <Column field="user" :header="pt('User')" style="width: 130px">
        <template #body="{ data }"><span class="muted">{{ data.user ?? '—' }}</span></template>
      </Column>
      <Column style="width: 60px">
        <template #body="{ data }">
          <Button
            v-if="data.payload"
            icon="pi pi-eye"
            text
            size="small"
            :title="pt('View payload')"
            @click="openDetail(data)"
          />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="detailVisible" modal :header="pt('Event payload')" :style="{ width: '640px' }">
      <div v-if="detailRow" class="detail">
        <div class="detail-head">
          <span><strong>{{ detailRow.device_name ?? detailRow.device_code }}</strong> · {{ detailRow.driver }}</span>
          <span class="muted">{{ fmt(detailRow.occurred_at) }}</span>
        </div>
        <p class="detail-msg">{{ detailRow.message }}</p>
        <pre>{{ detailText }}</pre>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.9rem; }
.filters :deep(.p-select), .filters :deep(.p-datepicker) { min-width: 160px; }
.empty { padding: 1.5rem; text-align: center; color: #6b7280; }
.mono { font-variant-numeric: tabular-nums; font-size: 0.8rem; }
.muted { color: #6b7280; }
.dev { display: flex; flex-direction: column; }
.dev small { color: #6b7280; font-size: 0.7rem; }
.msg { font-size: 0.82rem; }
.detail { display: flex; flex-direction: column; gap: 0.6rem; }
.detail-head { display: flex; justify-content: space-between; font-size: 0.85rem; }
.detail-msg { font-size: 0.85rem; margin: 0; }
.detail pre { background: #0f172a; color: #e2e8f0; padding: 0.8rem; border-radius: 7px; font-size: 0.74rem; white-space: pre-wrap; word-break: break-all; max-height: 420px; overflow: auto; margin: 0; }
</style>
