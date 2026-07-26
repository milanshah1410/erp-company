<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/fabric-inspections')

const rolls = ref<any[]>([])

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const search = ref('')
const resultFilter = ref<string | null>(null)
const gradeFilter = ref<string | null>(null)
const resultOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Pass', value: 'pass' },
  { label: 'Reject', value: 'reject' },
]
const gradeFilterOptions = [
  { label: 'Grade A', value: 'A' },
  { label: 'Grade B', value: 'B' },
  { label: 'Grade C', value: 'C' },
]

function reload() {
  crud.fetch({ search: search.value || undefined, result: resultFilter.value || undefined, grade: gradeFilter.value || undefined })
}

const canCreate = computed(() => auth.can('textile.create'))
const canEdit = computed(() => auth.can('textile.edit'))
const canDelete = computed(() => auth.can('textile.delete'))

const gradeColors: Record<string, string> = { A: 'success', B: 'warn', C: 'danger' }
const resultColors: Record<string, string> = { pending: 'secondary', pass: 'success', reject: 'danger' }

const severityOptions = [
  { label: 'Minor (1 pt)', value: 'minor', points: 1 },
  { label: 'Major (2-3 pt)', value: 'major', points: 2 },
  { label: 'Critical (4 pt)', value: 'critical', points: 4 },
]

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(emptyForm())
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}
function emptyForm() {
  return {
    fabric_roll_id: null, inspected_length: null, inspected_width: null,
    result: null, inspector: '', inspection_date: today(), notes: '', defects: [],
  }
}
function addDefect() {
  form.value.defects.push({ defect_type: '', severity: 'minor', size_range: '', points: 1, count: 1 })
}
function removeDefect(i: number | string) {
  form.value.defects.splice(Number(i), 1)
}
function onSeverityChange(line: any) {
  const s = severityOptions.find((o) => o.value === line.severity)
  if (s) line.points = s.points
}

const selectedRoll = computed(() => rolls.value.find((r) => r.id === form.value.fabric_roll_id))

// Live 4-point preview: Σ(points × count) normalised to 100 m².
const totalPoints = computed(() =>
  form.value.defects.reduce((sum: number, d: any) => sum + Number(d.points || 0) * Number(d.count || 1), 0),
)
const per100 = computed(() => {
  const roll = selectedRoll.value
  const lengthM = Number(form.value.inspected_length || 0) * (roll?.length_unit === 'yard' ? 0.9144 : 1)
  const widthRaw = Number(form.value.inspected_width || roll?.width || 0)
  const widthM = roll?.width_unit === 'inch' ? widthRaw * 0.0254 : roll?.width_unit === 'cm' ? widthRaw / 100 : widthRaw
  const area = lengthM * widthM
  return area > 0 ? Math.round((totalPoints.value / area) * 100 * 100) / 100 : 0
})
const previewGrade = computed(() => (per100.value <= 20 ? 'A' : per100.value <= 40 ? 'B' : 'C'))

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  addDefect()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/fabric-inspections/${row.id}`)
    const i = data.data
    editingId.value = i.id
    form.value = {
      fabric_roll_id: i.fabric_roll_id,
      inspected_length: Number(i.inspected_length),
      inspected_width: i.inspected_width != null ? Number(i.inspected_width) : null,
      result: i.result, inspector: i.inspector || '', inspection_date: i.inspection_date,
      notes: i.notes || '',
      defects: (i.defects || []).map((d: any) => ({
        defect_type: d.defect_type, severity: d.severity, size_range: d.size_range || '',
        points: Number(d.points), count: Number(d.count),
      })),
    }
    if (!form.value.defects.length) addDefect()
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load inspection', life: 3000 })
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, defects: form.value.defects.filter((d: any) => d.defect_type) }
    if (editingId.value) {
      await http.put(`/fabric-inspections/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Inspection updated', life: 2500 })
    } else {
      await http.post('/fabric-inspections', payload)
      toast.add({ severity: 'success', summary: 'Inspection recorded', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function removeRow(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.inspection_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/fabric-inspections/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const { data } = await http.get('/fabric-rolls', { params: { per_page: 200, all: 1 } })
  rolls.value = (data.data ?? data).map((r: any) => ({
    ...r,
    label: `${r.roll_number} — ${r.product?.name ?? ''}${r.dye_lot ? ' (' + r.dye_lot + ')' : ''}`,
  }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Fabric Inspection" :count="crud.total.value">
      <template #actions>
        <Button v-if="canCreate" label="New Inspection" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>
    <p class="subtitle">4-point system — defect points scored per 100 m² grade the fabric A / B / C.</p>

    <div class="toolbar no-print" style="display:flex;justify-content:flex-end;gap:0.75rem;align-items:center;margin-bottom:1rem">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <Select v-model="resultFilter" :options="resultOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All results" @change="reload" class="filter-sel" />
      <Select v-model="gradeFilter" :options="gradeFilterOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All grades" @change="reload" class="filter-sel" />
      <TableExportButtons title="Fabric Inspections" endpoint="/fabric-inspections"
        :params="() => ({ search: search || undefined, result: resultFilter || undefined, grade: gradeFilter || undefined })" :columns="[
        { key: 'inspection_number', label: 'Inspection #' },
        { key: 'fabric_roll.roll_number', label: 'Roll' },
        { key: 'inspection_date', label: 'Date' },
        { key: 'inspected_length', label: 'Inspected' },
        { key: 'total_points', label: 'Points' },
        { key: 'points_per_100sqm', label: 'Pts / 100m²' },
        { key: 'grade', label: 'Grade' },
        { key: 'result', label: 'Result' },
      ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="inspection_number" header="Inspection #" />
      <Column header="Roll">
        <template #body="{ data }">
          <div>{{ data.fabric_roll?.roll_number }}</div>
          <small class="muted">{{ data.fabric_roll?.product?.name }}</small>
        </template>
      </Column>
      <Column field="inspection_date" header="Date" />
      <Column header="Inspected" style="text-align:right">
        <template #body="{ data }">{{ Number(data.inspected_length) }}</template>
      </Column>
      <Column header="Points" style="text-align:right">
        <template #body="{ data }">{{ Number(data.total_points) }}</template>
      </Column>
      <Column header="Pts / 100m²" style="text-align:right">
        <template #body="{ data }"><strong>{{ Number(data.points_per_100sqm) }}</strong></template>
      </Column>
      <Column header="Grade">
        <template #body="{ data }"><Tag v-if="data.grade" :severity="gradeColors[data.grade]" :value="data.grade" /></template>
      </Column>
      <Column header="Result">
        <template #body="{ data }"><Tag :severity="resultColors[data.result]" :value="data.result" /></template>
      </Column>
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeRow(data)" />
        </template>
      </Column>
      <template #empty>No inspections yet.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Inspection' : 'New Fabric Inspection'" :style="{ width: '860px' }">
      <div class="form-top">
        <div class="fld span2">
          <label>Fabric Roll *</label>
          <Select v-model="form.fabric_roll_id" :options="rolls" optionLabel="label" optionValue="id" filter placeholder="Select roll" />
          <small v-if="errors.fabric_roll_id" class="err">{{ errors.fabric_roll_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Inspected Length *</label>
          <InputNumber v-model="form.inspected_length" :minFractionDigits="0" :maxFractionDigits="3" :suffix="selectedRoll ? ' ' + selectedRoll.length_unit : ''" />
          <small v-if="errors.inspected_length" class="err">{{ errors.inspected_length[0] }}</small>
        </div>
        <div class="fld">
          <label>Inspected Width</label>
          <InputNumber v-model="form.inspected_width" :minFractionDigits="0" :maxFractionDigits="2" :placeholder="selectedRoll?.width ? `roll: ${Number(selectedRoll.width)} ${selectedRoll.width_unit}` : ''" />
        </div>
        <div class="fld">
          <label>Inspector</label>
          <InputText v-model="form.inspector" />
        </div>
        <div class="fld">
          <label>Date *</label>
          <input type="date" v-model="form.inspection_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">Defects</h3>
      <table class="lines">
        <thead>
          <tr><th style="width: 30%">Defect Type</th><th>Severity</th><th>Size / Location</th><th>Points</th><th>Count</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(d, i) in form.defects" :key="i">
            <td><InputText v-model="d.defect_type" placeholder="e.g. Hole, Slub, Stain" class="w-full" /></td>
            <td><Select v-model="d.severity" :options="severityOptions" optionLabel="label" optionValue="value" @change="onSeverityChange(d)" class="w-full" /></td>
            <td><InputText v-model="d.size_range" placeholder="e.g. 3-6 inch" class="w-full" /></td>
            <td><InputNumber v-model="d.points" :min="0" :max="4" :minFractionDigits="0" :maxFractionDigits="2" class="num" /></td>
            <td><InputNumber v-model="d.count" :min="1" class="num sm" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeDefect(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Defect" icon="pi pi-plus" text size="small" @click="addDefect" />

      <div class="score">
        <div class="score-box">
          <span class="score-label">Total Points</span>
          <span class="score-val">{{ totalPoints }}</span>
        </div>
        <div class="score-box">
          <span class="score-label">Points / 100 m²</span>
          <span class="score-val">{{ per100 }}</span>
        </div>
        <div class="score-box">
          <span class="score-label">Auto Grade</span>
          <Tag :severity="gradeColors[previewGrade]" :value="previewGrade" />
        </div>
        <div class="fld grow">
          <label>Result (override)</label>
          <Select v-model="form.result" :options="[{label:`Auto (${previewGrade === 'C' ? 'reject' : 'pass'})`, value:null},{label:'Pass',value:'pass'},{label:'Reject',value:'reject'},{label:'Pending',value:'pending'}]" optionLabel="label" optionValue="value" />
          <small class="muted">A reject quarantines the roll.</small>
        </div>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Record Inspection'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="Fabric Inspection" :record="detailRecord" :fields="[
      { key: 'inspection_number', label: 'Inspection #' },
      { key: 'fabric_roll.roll_number', label: 'Roll' },
      { key: 'fabric_roll.product.name', label: 'Fabric' },
      { key: 'inspection_date', label: 'Date' },
      { key: 'inspector', label: 'Inspector' },
      { key: 'inspected_length', label: 'Inspected Length', format: (v) => v == null ? '—' : String(Number(v)) },
      { key: 'inspected_width', label: 'Inspected Width', format: (v) => v == null ? '—' : String(Number(v)) },
      { key: 'total_points', label: 'Total Points', format: (v) => v == null ? '—' : String(Number(v)) },
      { key: 'points_per_100sqm', label: 'Points / 100 m²', format: (v) => v == null ? '—' : String(Number(v)) },
      { key: 'grade', label: 'Grade' },
      { key: 'result', label: 'Result' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; font-size: 0.85rem; margin-bottom: 1rem; }
.filter-sel { width: 150px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.span2 { grid-column: span 2; }
.fld.full { margin-top: 1rem; }
.fld.grow { flex: 1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select), .lines :deep(.p-inputtext) { width: 100%; }
.num :deep(input) { width: 70px; }
.num.sm :deep(input) { width: 56px; }
.w-full { width: 100%; }
.score { display: flex; align-items: flex-end; gap: 1.5rem; margin-top: 1rem; padding: 0.85rem 1rem; background: #f9fafb; border-radius: 8px; }
.score-box { display: flex; flex-direction: column; gap: 0.25rem; }
.score-label { font-size: 0.75rem; color: #6b7280; }
.score-val { font-size: 1.25rem; font-weight: 700; }
.muted { color: #6b7280; font-size: 0.78rem; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
