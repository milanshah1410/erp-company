<script setup lang="ts">
// Container Status board. Cross-shipment on purpose: the desk works the boxes,
// not the paperwork — which are still at the port, which are running out of free
// days, which came back damaged, which are yet to be returned empty.
//
// A move is recorded here rather than on the shipment, because that is where the
// information arrives (a gate-in SMS, a carrier portal, a driver's call).
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { humanize, statusOptions, containerStatusSeverity } from '../composables/useTradeCatalogue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shipment-containers')

const canEdit = computed(() => auth.can('shipment-containers.edit'))

const summary = ref<any>(null)
const search = ref('')
const statusFilter = ref<string | null>(null)
const directionFilter = ref<string | null>(null)
const typeFilter = ref<string | null>(null)
const detentionOnly = ref(false)
const openOnly = ref(true)

const containerStatuses = [
  'planned', 'stuffed', 'gated_in', 'loaded', 'in_transit', 'discharged', 'gated_out', 'delivered', 'empty_returned',
]
const statusChoices = statusOptions(containerStatuses)
const directionChoices = statusOptions(['import', 'export'])
const typeChoices = ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'].map(v => ({ label: v.toUpperCase(), value: v }))

function params(): Record<string, any> {
  return {
    ...(search.value ? { search: search.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(typeFilter.value ? { container_type: typeFilter.value } : {}),
    ...(detentionOnly.value ? { detention_only: 1 } : {}),
    ...(openOnly.value ? { open_only: 1 } : {}),
  }
}
async function reload() {
  crud.fetch(params())
  const { data } = await http.get('/shipment-containers/summary')
  summary.value = data
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch(params())
}

// --- record a move ---
const moveRow = ref<any>(null)
const moveForm = ref<any>(null)
const moving = ref(false)
function openMove(row: any) {
  moveRow.value = row
  moveForm.value = {
    status: containerStatuses[Math.min(containerStatuses.indexOf(row.status) + 1, containerStatuses.length - 1)],
    current_location: row.current_location || '',
    moved_at: new Date().toISOString().slice(0, 10),
    is_damaged: !!row.is_damaged,
    damage_remarks: row.damage_remarks || '',
    remarks: '',
  }
}
async function saveMove() {
  moving.value = true
  try {
    await http.post(`/shipment-containers/${moveRow.value.id}/move`, moveForm.value)
    toast.add({ severity: 'success', summary: 'Move recorded', life: 2200 })
    moveRow.value = null
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    moving.value = false
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(row: any) {
  detailId.value = row.shipment_id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'container_number', label: 'Container #' },
  { key: 'container_type', label: 'Type' },
  { key: 'seal_number', label: 'Seal' },
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'status', label: 'Status' },
  { key: 'current_location', label: 'Location' },
  { key: 'detention_free_until', label: 'Free until' },
  { key: 'detention_days', label: 'Detention days' },
  { key: 'is_damaged', label: 'Damaged' },
  { key: 'empty_returned_on', label: 'Empty returned' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Container Status" :count="crud.total.value" countLabel="containers"
      subtitle="Where every box is, what it is costing in detention, and what is still to come back empty.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Container, seal, shipment…" @keyup.enter="reload" />
          </span>
          <Select v-model="statusFilter" :options="statusChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any status" class="filter-sm" @change="reload" />
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Both legs" class="filter-sm" @change="reload" />
          <Select v-model="typeFilter" :options="typeChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Any type" class="filter-sm" @change="reload" />
          <TableExportButtons title="Container Status" endpoint="/shipment-containers" :params="params" :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="strip">
      <div v-for="s in summary.by_status" :key="s.status" class="chip" :class="{ zero: !s.total }">
        <strong>{{ s.total }}</strong> {{ humanize(s.status) }}
      </div>
      <div class="chip warn"><strong>{{ summary.in_detention }}</strong> in detention</div>
      <div class="chip warn"><strong>{{ summary.damaged }}</strong> damaged</div>
    </div>

    <div class="toolbar">
      <label class="switch"><ToggleSwitch v-model="openOnly" @change="reload" /><span>Hide returned boxes</span></label>
      <label class="switch"><ToggleSwitch v-model="detentionOnly" @change="reload" /><span>Detention only</span></label>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="container_number" header="Container #" />
      <Column header="Type"><template #body="{ data }">{{ String(data.container_type).toUpperCase() }}</template></Column>
      <Column field="seal_number" header="Seal" />
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="containerStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="Location">
        <template #body="{ data }">
          {{ data.current_location || '—' }}
          <br v-if="data.status_updated_at" />
          <small v-if="data.status_updated_at" class="muted">{{ new Date(data.status_updated_at).toLocaleDateString() }}</small>
        </template>
      </Column>
      <Column header="Free days / Detention">
        <template #body="{ data }">
          <span v-if="data.accruing_detention" class="bad">{{ data.detention_days }} days over</span>
          <span v-else-if="data.detention_free_until" class="muted">free to {{ data.detention_free_until }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Damage">
        <template #body="{ data }">
          <Tag v-if="data.is_damaged" severity="danger" value="Damaged" class="mini" :title="data.damage_remarks" />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column header="Empty return">
        <template #body="{ data }">{{ data.empty_returned_on || '—' }}</template>
      </Column>
      <Column header="" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit && data.status !== 'empty_returned'" label="Move" size="small" outlined @click="openMove(data)" />
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="Open shipment" @click="openShipment(data)" />
        </template>
      </Column>
      <template #empty>No containers match this filter.</template>
    </DataTable>

    <Dialog :visible="!!moveRow" modal :header="`Move ${moveRow?.container_number ?? ''}`" :style="{ width: '520px' }"
      @update:visible="v => { if (!v) moveRow = null }">
      <div v-if="moveForm" class="form-grid">
        <div class="fld">
          <label>New status *</label>
          <Select v-model="moveForm.status" :options="statusChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld"><label>Moved on</label><input type="date" v-model="moveForm.moved_at" class="date-input" /></div>
        <div class="fld full"><label>Location</label><InputText v-model="moveForm.current_location" placeholder="Port, CFS, yard…" /></div>
        <div class="fld chk">
          <Checkbox v-model="moveForm.is_damaged" :binary="true" inputId="dmg2" />
          <label for="dmg2">Damage found</label>
        </div>
        <div class="fld full" v-if="moveForm.is_damaged">
          <label>Damage details</label><Textarea v-model="moveForm.damage_remarks" rows="2" />
        </div>
        <div class="fld full"><label>Remarks (goes on the shipment timeline)</label><Textarea v-model="moveForm.remarks" rows="2" /></div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="moveRow = null" />
        <Button label="Record move" :loading="moving" @click="saveMove" />
      </template>
    </Dialog>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="detailId" @changed="reload" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter-sm { width: 150px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }

.strip { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.9rem; }
.chip { border: 1px solid var(--border, #e8e4da); border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.78rem; }
.chip strong { font-size: 0.9rem; }
.chip.zero { opacity: 0.5; }
.chip.warn { border-color: #fca5a5; color: #b91c1c; }

.toolbar { display: flex; gap: 1.25rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
.switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.bad { color: #dc2626; font-weight: 600; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld.chk { flex-direction: row; align-items: center; gap: 0.5rem; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
</style>
