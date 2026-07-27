<script setup lang="ts">
// Pending Documents board. A missing B/L or certificate of origin holds up
// clearance — and on an L/C, the payment behind it — so the list defaults to what
// is still outstanding, oldest due date first, with overdue rows called out.
//
// Receiving a document and attaching its scan both happen here, in place: the
// desk is working a queue, not editing records one dialog at a time.
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { useTradeCatalogue, humanize, statusOptions } from '../composables/useTradeCatalogue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shipment-documents')
const { catalogue, load: loadCatalogue } = useTradeCatalogue()

const canEdit = computed(() => auth.can('shipment-documents.edit'))

const summary = ref<any>(null)
const search = ref('')
const statusFilter = ref<string | null>(null)
const typeFilter = ref<string | null>(null)
const directionFilter = ref<string | null>(null)
const overdueOnly = ref(false)
const showAll = ref(false)

const statusChoices = statusOptions(['pending', 'received', 'submitted', 'verified', 'rejected'])
const directionChoices = statusOptions(['import', 'export'])

function params(): Record<string, any> {
  return {
    state: showAll.value ? 'all' : 'outstanding',
    ...(search.value ? { search: search.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
    ...(typeFilter.value ? { doc_type: typeFilter.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(overdueOnly.value ? { overdue_only: 1 } : {}),
  }
}
async function reload() {
  crud.fetch(params())
  const { data } = await http.get('/shipment-documents/summary')
  summary.value = data
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch(params())
}

const statusSeverity: Record<string, string> = {
  pending: 'warn', submitted: 'info', received: 'success', verified: 'success', rejected: 'danger',
}

// Marking a document in hand is the single most common action on this screen,
// so it is one click rather than a dialog.
async function markReceived(row: any) {
  try {
    await http.put(`/shipment-documents/${row.id}`, { doc_type: row.doc_type, status: 'received' })
    toast.add({ severity: 'success', summary: `${row.doc_label} received`, life: 2200 })
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function upload(row: any, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  try {
    await http.post(`/shipment-documents/${row.id}/file`, body)
    toast.add({ severity: 'success', summary: 'File uploaded', life: 2200 })
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Upload failed', detail: e.response?.data?.message || '', life: 4000 })
  } finally {
    input.value = ''
  }
}

// --- edit dialog ---
const form = ref<any>(null)
const saving = ref(false)
function openEdit(row: any) {
  form.value = {
    id: row.id, doc_type: row.doc_type, doc_number: row.doc_number || '', doc_date: row.doc_date || '',
    due_date: row.due_date || '', status: row.status, remarks: row.remarks || '',
  }
}
async function save() {
  saving.value = true
  try {
    const body = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]))
    await http.put(`/shipment-documents/${form.value.id}`, body)
    toast.add({ severity: 'success', summary: 'Document saved', life: 2200 })
    form.value = null
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(row: any) {
  detailId.value = row.shipment_id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'party', label: 'Party' },
  { key: 'doc_label', label: 'Document' },
  { key: 'doc_number', label: 'Number' },
  { key: 'doc_date', label: 'Date' },
  { key: 'due_date', label: 'Due' },
  { key: 'status', label: 'Status' },
  { key: 'eta', label: 'Shipment ETA' },
]

onMounted(() => {
  loadCatalogue()
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Pending Documents" :count="crud.total.value" countLabel="documents"
      subtitle="The trade document set per shipment — what is still to be collected, submitted or verified.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Shipment, document no…" @keyup.enter="reload" />
          </span>
          <Select v-model="typeFilter" :options="catalogue.documents" optionLabel="label" optionValue="value" showClear
            filter placeholder="Any document" class="filter-md" @change="reload" />
          <Select v-model="statusFilter" :options="statusChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any status" class="filter-sm" @change="reload" />
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Both legs" class="filter-sm" @change="reload" />
          <TableExportButtons title="Pending Documents" endpoint="/shipment-documents" :params="params" :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="strip">
      <div class="chip"><strong>{{ summary.outstanding }}</strong> outstanding</div>
      <div class="chip" :class="{ warn: summary.overdue > 0 }"><strong>{{ summary.overdue }}</strong> overdue</div>
      <div class="chip"><strong>{{ summary.due_this_week }}</strong> due in 7 days</div>
      <div class="chip"><strong>{{ summary.received }}</strong> in hand</div>
    </div>

    <div class="toolbar">
      <label class="switch"><ToggleSwitch v-model="showAll" @change="reload" /><span>Include documents already in hand</span></label>
      <label class="switch"><ToggleSwitch v-model="overdueOnly" @change="reload" /><span>Overdue only</span></label>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Document">
        <template #body="{ data }">
          {{ data.doc_label }}
          <Tag v-if="data.is_mandatory" value="required" severity="secondary" class="mini" />
        </template>
      </Column>
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column field="doc_number" header="Number" />
      <Column header="Due">
        <template #body="{ data }">
          <span :class="{ bad: data.is_overdue }">{{ data.due_date || '—' }}</span>
          <small v-if="data.is_overdue" class="bad"> overdue</small>
        </template>
      </Column>
      <Column header="Shipment ETA"><template #body="{ data }">{{ data.eta || '—' }}</template></Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="File">
        <template #body="{ data }">
          <a v-if="data.file_url" :href="data.file_url" target="_blank" rel="noopener"><i class="pi pi-paperclip" /> open</a>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 13rem">
        <template #body="{ data }">
          <Button v-if="canEdit && ['pending', 'rejected'].includes(data.status)" label="Received" size="small"
            severity="success" outlined @click="markReceived(data)" />
          <label v-if="canEdit" class="upload-btn" title="Upload scan">
            <i class="pi pi-upload" />
            <input type="file" hidden @change="e => upload(data, e)" />
          </label>
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
        </template>
      </Column>
      <template #empty>Nothing outstanding — every document is in hand.</template>
    </DataTable>

    <Dialog :visible="!!form" modal header="Document" :style="{ width: '560px' }"
      @update:visible="v => { if (!v) form = null }">
      <div v-if="form" class="form-grid">
        <div class="fld">
          <label>Document *</label>
          <Select v-model="form.doc_type" :options="catalogue.documents" optionLabel="label" optionValue="value" filter />
        </div>
        <div class="fld">
          <label>Status</label>
          <Select v-model="form.status" :options="statusChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Number</label><InputText v-model="form.doc_number" /></div>
        <div class="fld"><label>Document date</label><input type="date" v-model="form.doc_date" class="date-input" /></div>
        <div class="fld"><label>Due date</label><input type="date" v-model="form.due_date" class="date-input" /></div>
        <div class="fld full"><label>Remarks</label><Textarea v-model="form.remarks" rows="2" /></div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="form = null" />
        <Button label="Save" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 150px; }
.filter-md { width: 190px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.strip { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.9rem; }
.chip { border: 1px solid var(--border, #e8e4da); border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.78rem; }
.chip strong { font-size: 0.9rem; }
.chip.warn { border-color: #fca5a5; color: #b91c1c; }

.toolbar { display: flex; gap: 1.25rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.bad { color: #dc2626; font-weight: 600; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.upload-btn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 50%; cursor: pointer; color: #6366f1; }
.upload-btn:hover { background: rgba(99, 102, 241, 0.1); }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
</style>
