<script setup lang="ts">
// Pending Customs Clearance. Cargo sitting uncleared at a port accrues storage
// and detention every day, so the board sorts arrived-but-not-cleared to the top
// and keeps the duty figures next to the entry they belong to.
//
// The whole customs track — file, assess, pay duty, clear — is driven from the
// one dialog here, because the duty breakdown is edited alongside the state it
// belongs to and the total must always be the sum of its parts.
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import { humanize, statusOptions, shipmentStatusSeverity, customsStatusSeverity } from '../composables/useTradeCatalogue'

const toast = useToast()
const auth = useAuthStore()

const canEdit = computed(() => auth.can('shipment-customs.edit'))

const rows = ref<any[]>([])
const meta = ref<any>({ total: 0, per_page: 25, current_page: 1 })
const summary = ref<any>(null)
const loading = ref(false)

const search = ref('')
const directionFilter = ref<string | null>(null)
const customsFilter = ref<string | null>(null)
const directionChoices = statusOptions(['import', 'export'])
const customsChoices = statusOptions(['pending', 'filed', 'assessed', 'duty_paid', 'on_hold', 'cleared'])
// The leg decides the entry: an import clears on a Bill of Entry, an export on a
// Shipping Bill. Shown for confirmation, never as a choice.
const entryLabels: Record<string, string> = { bill_of_entry: 'Bill of Entry', shipping_bill: 'Shipping Bill' }
function entryTypeFor(direction: string): string {
  return direction === 'import' ? 'bill_of_entry' : 'shipping_bill'
}
/** Every state after 'pending' refers back to the entry, so it must exist first. */
function needsEntryNumber(status: string): boolean {
  return ['filed', 'assessed', 'duty_paid', 'cleared'].includes(status)
}

function params(): Record<string, any> {
  return {
    per_page: meta.value.per_page,
    page: meta.value.current_page,
    ...(search.value ? { search: search.value } : {}),
    ...(directionFilter.value ? { direction: directionFilter.value } : {}),
    ...(customsFilter.value ? { customs_status: customsFilter.value } : {}),
  }
}
async function reload() {
  loading.value = true
  try {
    const { data } = await http.get('/import-export/pending-customs', { params: params() })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}
function onPage(e: any) {
  meta.value.current_page = e.page + 1
  meta.value.per_page = e.rows
  reload()
}

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
}

/** Jump from a summary chip into the entries behind it. */
function drill(status: string) {
  customsFilter.value = customsFilter.value === status ? null : status
  meta.value.current_page = 1
  reload()
}

// --- customs dialog ---
const row = ref<any>(null)
const form = ref<any>(null)
const saving = ref(false)
function openCustoms(target: any, status?: string) {
  row.value = target
  form.value = {
    customs_status: status ?? target.customs_status,
    customs_entry_type: entryTypeFor(target.direction),
    customs_entry_number: target.customs_entry_number || '',
    customs_entry_date: target.customs_entry_date || '',
    icegate_reference: target.icegate_reference || '',
    assessable_value: Number(target.assessable_value ?? 0),
    duty_bcd: Number(target.duty_bcd ?? 0),
    duty_igst: Number(target.duty_igst ?? 0),
    duty_cess: Number(target.duty_cess ?? 0),
    duty_other: Number(target.duty_other ?? 0),
    remarks: '',
  }
}
const dutyPreview = computed(() => {
  const f = form.value
  if (!f) return 0
  return Number(f.duty_bcd || 0) + Number(f.duty_igst || 0) + Number(f.duty_cess || 0) + Number(f.duty_other || 0)
})
async function save() {
  saving.value = true
  try {
    const body = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]))
    await http.post(`/shipments/${row.value.id}/customs`, body)
    toast.add({ severity: 'success', summary: 'Customs updated', life: 2200 })
    row.value = null
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

// One-click advance for the common path: file → assess → duty paid → cleared.
const nextCustoms: Record<string, string> = {
  pending: 'filed', filed: 'assessed', assessed: 'duty_paid', duty_paid: 'cleared', on_hold: 'filed',
}
async function advance(target: any) {
  const next = nextCustoms[target.customs_status]
  if (!next) return
  // Nothing can be filed without its entry number, so send the desk to the form
  // rather than to a validation error it would have to read twice.
  if (needsEntryNumber(next) && !target.customs_entry_number) {
    openCustoms(target, next)
    return
  }
  try {
    await http.post(`/shipments/${target.id}/customs`, { customs_status: next })
    toast.add({ severity: 'success', summary: `Customs ${humanize(next).toLowerCase()}`, life: 2200 })
    await reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

const detailVisible = ref(false)
const detailId = ref<number | null>(null)
function openShipment(target: any) {
  detailId.value = target.id
  detailVisible.value = true
}

const exportColumns = [
  { key: 'shipment_number', label: 'Shipment #' },
  { key: 'direction', label: 'Leg' },
  { key: 'party', label: 'Party' },
  { key: 'customs_entry_type', label: 'Entry type' },
  { key: 'customs_entry_number', label: 'Entry no.' },
  { key: 'customs_entry_date', label: 'Entry date' },
  { key: 'icegate_reference', label: 'ICEGATE ref' },
  { key: 'customs_status', label: 'Customs status' },
  { key: 'duty_total', label: 'Duty' },
  { key: 'eta', label: 'ETA' },
  { key: 'status', label: 'Shipment status' },
]

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Pending Customs" :count="meta.total" countLabel="shipments"
      subtitle="Entries still to be filed, assessed, paid or cleared — landed cargo first.">
      <template #actions>
        <div class="header-actions">
          <span class="search-box">
            <i class="pi pi-search" />
            <InputText v-model="search" placeholder="Shipment, entry no, B/L…" @keyup.enter="reload" />
          </span>
          <Select v-model="customsFilter" :options="customsChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Not cleared" class="filter-sm" @change="reload" />
          <Select v-model="directionFilter" :options="directionChoices" optionLabel="label" optionValue="value" showClear
            placeholder="Both legs" class="filter-sm" @change="reload" />
          <TableExportButtons title="Pending Customs" endpoint="/import-export/pending-customs" :params="params" :columns="exportColumns" />
        </div>
      </template>
    </PageHeader>

    <div v-if="summary" class="strip">
      <div class="chip" :class="{ warn: summary.pending > 0 }"><strong>{{ summary.pending }}</strong> awaiting clearance</div>
      <button type="button" class="chip" :class="{ active: customsFilter === 'pending' }" @click="drill('pending')">
        <strong>{{ summary.unfiled }}</strong> not filed
      </button>
      <button type="button" class="chip" :class="{ warn: summary.on_hold > 0, active: customsFilter === 'on_hold' }"
        @click="drill('on_hold')">
        <strong>{{ summary.on_hold }}</strong> on hold
      </button>
      <div class="chip"><strong>{{ money(summary.duty_outstanding) }}</strong> duty assessed</div>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="meta.per_page" :totalRecords="meta.total"
      :rowsPerPageOptions="[25, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Shipment">
        <template #body="{ data }">
          <a class="link" @click="openShipment(data)">{{ data.shipment_number }}</a>
          <Tag :severity="data.direction === 'import' ? 'info' : 'success'" :value="humanize(data.direction)" class="mini" />
        </template>
      </Column>
      <Column header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column header="Shipment status">
        <template #body="{ data }"><Tag :severity="shipmentStatusSeverity[data.status]" :value="humanize(data.status)" /></template>
      </Column>
      <Column header="Entry">
        <template #body="{ data }">
          <span v-if="data.customs_entry_number">
            {{ data.customs_entry_number }}
            <br /><small class="muted">{{ humanize(data.customs_entry_type) }} · {{ data.customs_entry_date || 'no date' }}</small>
          </span>
          <span v-else class="muted">not filed</span>
        </template>
      </Column>
      <Column header="ICEGATE"><template #body="{ data }">{{ data.icegate_reference || '—' }}</template></Column>
      <Column header="Duty">
        <template #body="{ data }">
          {{ money(data.duty_total) }}
          <br v-if="Number(data.duty_igst) > 0" />
          <small v-if="Number(data.duty_igst) > 0" class="muted">incl. IGST {{ money(data.duty_igst) }}</small>
        </template>
      </Column>
      <Column header="Customs">
        <template #body="{ data }"><Tag :severity="customsStatusSeverity[data.customs_status]" :value="humanize(data.customs_status)" /></template>
      </Column>
      <Column field="eta" header="ETA" />
      <Column header="Actions" style="width: 13rem">
        <template #body="{ data }">
          <Button v-if="canEdit && nextCustoms[data.customs_status]" :label="humanize(nextCustoms[data.customs_status])"
            size="small" severity="success" outlined @click="advance(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Entry & duty" @click="openCustoms(data)" />
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openShipment(data)" />
        </template>
      </Column>
      <template #empty>Nothing waiting at customs.</template>
    </DataTable>

    <Dialog :visible="!!row" modal :header="`Customs — ${row?.shipment_number ?? ''}`" :style="{ width: '640px' }"
      @update:visible="v => { if (!v) row = null }">
      <div v-if="form" class="form-grid">
        <div class="fld">
          <label>Status *</label>
          <Select v-model="form.customs_status" :options="customsChoices" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Entry type</label>
          <InputText :model-value="entryLabels[form.customs_entry_type]" disabled />
        </div>
        <div class="fld">
          <label>Entry no. <span v-if="needsEntryNumber(form.customs_status)">*</span></label>
          <InputText v-model="form.customs_entry_number" :placeholder="entryLabels[form.customs_entry_type] + ' number'" />
        </div>
        <div class="fld"><label>Entry date</label><input type="date" v-model="form.customs_entry_date" class="date-input" /></div>
        <div class="fld"><label>ICEGATE reference</label><InputText v-model="form.icegate_reference" /></div>
        <div class="fld"><label>Assessable value</label><InputNumber v-model="form.assessable_value" :minFractionDigits="2" /></div>
        <div class="fld"><label>BCD</label><InputNumber v-model="form.duty_bcd" :minFractionDigits="2" /></div>
        <div class="fld"><label>IGST</label><InputNumber v-model="form.duty_igst" :minFractionDigits="2" /></div>
        <div class="fld"><label>Cess / SWS</label><InputNumber v-model="form.duty_cess" :minFractionDigits="2" /></div>
        <div class="fld"><label>Other duty</label><InputNumber v-model="form.duty_other" :minFractionDigits="2" /></div>
        <div class="fld full"><label>Remarks (goes on the timeline)</label><Textarea v-model="form.remarks" rows="2" /></div>
        <p class="total-hint">
          Total duty <strong>{{ money(dutyPreview) }}</strong>
          <span class="muted"> — counted once in landed cost, so do not book it again as a logistics cost.</span>
        </p>
        <p v-if="needsEntryNumber(form.customs_status)" class="total-hint muted">
          Saving marks the {{ entryLabels[form.customs_entry_type] }} off the document checklist.
        </p>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="row = null" />
        <Button label="Save" :loading="saving" @click="save" />
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
.chip { border: 1px solid var(--border, #e8e4da); border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.78rem;
  background: none; color: inherit; font-family: inherit; }
button.chip { cursor: pointer; }
button.chip:hover { border-color: #6366f1; }
.chip strong { font-size: 0.9rem; }
.chip.warn { border-color: #fca5a5; color: #b91c1c; }
.chip.active { background: rgba(99, 102, 241, 0.12); border-color: #6366f1; }
.chip.warn.active { background: rgba(220, 38, 38, 0.1); border-color: #dc2626; }

.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.78rem; }
.mini { font-size: 0.62rem; margin-left: 0.35rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.total-hint { grid-column: 1 / -1; margin: 0; font-size: 0.85rem; }
</style>
