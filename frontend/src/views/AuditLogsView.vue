<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import InputText from 'primevue/inputtext'
import Dialog from 'primevue/dialog'
import http from '../api/http'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)

const search = ref('')
const logNameFilter = ref<string | null>(null)
const eventFilter = ref<string | null>(null)
const subjectTypeFilter = ref<string | null>(null)
const dateFrom = ref('')
const dateTo = ref('')

const logNameOptions = ref<{ label: string; value: string | null }[]>([{ label: 'All modules', value: null }])
const eventOptions = ref<{ label: string; value: string | null }[]>([{ label: 'All events', value: null }])
const subjectTypeOptions = ref<{ label: string; value: string | null }[]>([{ label: 'All types', value: null }])

const eventSeverity: Record<string, string> = {
  created: 'success',
  updated: 'info',
  deleted: 'danger',
  login: 'success',
  login_failed: 'danger',
  logout: 'secondary',
}

async function loadMeta() {
  const { data } = await http.get('/audit-logs-meta')
  logNameOptions.value = [{ label: 'All modules', value: null }, ...data.log_names.map((n: string) => ({ label: n, value: n }))]
  eventOptions.value = [{ label: 'All events', value: null }, ...data.events.map((n: string) => ({ label: n, value: n }))]
  subjectTypeOptions.value = [{ label: 'All types', value: null }, ...data.subject_types.map((n: string) => ({ label: n, value: n }))]
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/audit-logs', {
      params: {
        page: page.value,
        per_page: perPage.value,
        log_name: logNameFilter.value || undefined,
        event: eventFilter.value || undefined,
        subject_type: subjectTypeFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
      },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}
function applyFilters() {
  page.value = 1
  load()
}
function resetFilters() {
  search.value = ''
  logNameFilter.value = null
  eventFilter.value = null
  subjectTypeFilter.value = null
  dateFrom.value = ''
  dateTo.value = ''
  applyFilters()
}
function fmt(d: string) {
  return d ? new Date(d).toLocaleString() : ''
}

// --- Detail dialog: before/after diff ---
const detailVisible = ref(false)
const detail = ref<any>(null)

const diffRows = computed(() => {
  if (!detail.value) return []
  const before = detail.value.properties?.old || {}
  const after = detail.value.properties?.attributes || {}
  const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(after)]))
  return keys.map((k) => ({ field: k, before: before[k], after: after[k] }))
})

function openDetail(row: any) {
  detail.value = row
  detailVisible.value = true
}
function val(v: any) {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

onMounted(async () => {
  await loadMeta()
  load()
})
</script>

<template>
  <div>
    <h1>Audit Logs</h1>
    <p class="subtitle">Immutable record of who changed what, and when</p>

    <div class="toolbar">
      <InputText v-model="search" placeholder="Search description…" @keyup.enter="applyFilters" />
      <Select v-model="logNameFilter" :options="logNameOptions" optionLabel="label" optionValue="value" placeholder="Module" @change="applyFilters" />
      <Select v-model="eventFilter" :options="eventOptions" optionLabel="label" optionValue="value" placeholder="Event" @change="applyFilters" />
      <Select v-model="subjectTypeFilter" :options="subjectTypeOptions" optionLabel="label" optionValue="value" placeholder="Type" @change="applyFilters" />
      <input type="date" v-model="dateFrom" class="date-input" @change="applyFilters" />
      <input type="date" v-model="dateTo" class="date-input" @change="applyFilters" />
      <Button label="Apply" icon="pi pi-filter" @click="applyFilters" />
      <Button label="Reset" icon="pi pi-times" text @click="resetFilters" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Date/Time" style="width: 11rem"><template #body="{ data }">{{ fmt(data.created_at) }}</template></Column>
      <Column header="Module"><template #body="{ data }"><Tag severity="secondary" :value="data.log_name" /></template></Column>
      <Column header="Event"><template #body="{ data }"><Tag :severity="eventSeverity[data.event] || 'info'" :value="data.event" /></template></Column>
      <Column header="Subject"><template #body="{ data }">{{ data.subject_type }}<span v-if="data.subject_id"> #{{ data.subject_id }}</span></template></Column>
      <Column field="description" header="Description" />
      <Column header="By"><template #body="{ data }">{{ data.causer?.name || 'System' }}</template></Column>
      <Column header="" style="width: 5rem">
        <template #body="{ data }"><Button icon="pi pi-eye" text rounded size="small" @click="openDetail(data)" /></template>
      </Column>
      <template #empty>No activity recorded yet.</template>
    </DataTable>

    <Dialog v-model:visible="detailVisible" modal header="Audit Log Detail" :style="{ width: '640px' }">
      <div v-if="detail" class="detail">
        <div class="meta-grid">
          <div><label>When</label><span>{{ fmt(detail.created_at) }}</span></div>
          <div><label>By</label><span>{{ detail.causer?.name || 'System' }}</span></div>
          <div><label>Module</label><span>{{ detail.log_name }}</span></div>
          <div><label>Event</label><span>{{ detail.event }}</span></div>
          <div><label>Subject</label><span>{{ detail.subject_type }} <template v-if="detail.subject_id">#{{ detail.subject_id }}</template></span></div>
        </div>
        <p class="desc">{{ detail.description }}</p>

        <table v-if="diffRows.length" class="diff">
          <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
          <tbody>
            <tr v-for="r in diffRows" :key="r.field">
              <td class="field">{{ r.field }}</td>
              <td class="before">{{ val(r.before) }}</td>
              <td class="after">{{ val(r.after) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="no-changes">No attribute changes recorded for this entry.</p>
      </div>
      <template #footer>
        <Button label="Close" text @click="detailVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.6rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem 1.5rem; margin-bottom: 0.75rem; }
.meta-grid label { display: block; font-size: 0.75rem; color: #6b7280; }
.meta-grid span { font-size: 0.9rem; color: #111827; }
.desc { font-size: 0.9rem; color: #374151; margin: 0.5rem 0 1rem; }
.diff { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.diff th { text-align: left; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; color: #6b7280; }
.diff td { padding: 0.4rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; word-break: break-word; }
.diff .field { font-weight: 600; color: #374151; width: 25%; }
.diff .before { color: #dc2626; width: 37.5%; }
.diff .after { color: #059669; width: 37.5%; }
.no-changes { color: #6b7280; font-size: 0.85rem; }
</style>
