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
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/shrinkage-records')

const rolls = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('textile.create'))
const canEdit = computed(() => auth.can('textile.edit'))
const canDelete = computed(() => auth.can('textile.delete'))

const processOptions = [
  { label: 'Washing', value: 'washing' },
  { label: 'Dyeing', value: 'dyeing' },
  { label: 'Steaming', value: 'steaming' },
  { label: 'Other', value: 'other' },
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
    fabric_roll_id: null, product_id: null, process: 'washing',
    length_before: null, length_after: null, width_before: null, width_after: null,
    test_date: today(), tested_by: '', notes: '',
  }
}

function pct(before: any, after: any) {
  const b = Number(before || 0)
  if (b <= 0) return 0
  return Math.round(((b - Number(after || 0)) / b) * 100 * 100) / 100
}
const lengthPct = computed(() => pct(form.value.length_before, form.value.length_after))
const widthPct = computed(() => pct(form.value.width_before, form.value.width_after))

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/shrinkage-records/${row.id}`)
    const r = data.data
    editingId.value = r.id
    form.value = {
      fabric_roll_id: r.fabric_roll_id, product_id: r.product_id, process: r.process || 'washing',
      length_before: Number(r.length_before), length_after: Number(r.length_after),
      width_before: r.width_before != null ? Number(r.width_before) : null,
      width_after: r.width_after != null ? Number(r.width_after) : null,
      test_date: r.test_date, tested_by: r.tested_by || '', notes: r.notes || '',
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load record', life: 3000 })
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/shrinkage-records/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Record updated', life: 2500 })
    } else {
      await http.post('/shrinkage-records', form.value)
      toast.add({ severity: 'success', summary: 'Shrinkage recorded', life: 2500 })
    }
    dialogVisible.value = false
    crud.fetch()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function removeRow(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.record_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shrinkage-records/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    crud.fetch()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch()
}
function pctClass(v: any) {
  return Number(v) >= 5 ? 'dmg' : Number(v) > 0 ? 'warn' : ''
}

onMounted(async () => {
  const [r, p] = await Promise.all([
    http.get('/fabric-rolls', { params: { per_page: 200, all: 1 } }),
    http.get('/products', { params: { per_page: 200 } }),
  ])
  rolls.value = (r.data.data ?? r.data).map((x: any) => ({
    ...x, label: `${x.roll_number} — ${x.product?.name ?? ''}`,
  }))
  products.value = p.data.data ?? p.data
  crud.fetch()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Shrinkage Records</h1>
      <Button v-if="canCreate" label="New Record" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="record_number" header="Record #" />
      <Column header="Roll / Fabric">
        <template #body="{ data }">{{ data.fabric_roll || data.product || '—' }}</template>
      </Column>
      <Column field="process" header="Process">
        <template #body="{ data }"><span class="cap">{{ data.process || '—' }}</span></template>
      </Column>
      <Column field="test_date" header="Date" />
      <Column header="Length (before → after)">
        <template #body="{ data }">{{ Number(data.length_before) }} → {{ Number(data.length_after) }}</template>
      </Column>
      <Column header="Length Shrink %" style="text-align:right">
        <template #body="{ data }"><strong :class="pctClass(data.length_shrinkage_pct)">{{ Number(data.length_shrinkage_pct) }}%</strong></template>
      </Column>
      <Column header="Width Shrink %" style="text-align:right">
        <template #body="{ data }"><span :class="pctClass(data.width_shrinkage_pct)">{{ Number(data.width_shrinkage_pct) }}%</span></template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeRow(data)" />
        </template>
      </Column>
      <template #empty>No shrinkage records yet.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Shrinkage Record' : 'New Shrinkage Record'" :style="{ width: '680px' }">
      <p class="hint">Link a fabric roll or a product (either optional), enter before / after measurements — the shrinkage percentages are computed automatically.</p>
      <div class="form-grid">
        <div class="fld">
          <label>Fabric Roll</label>
          <Select v-model="form.fabric_roll_id" :options="rolls" optionLabel="label" optionValue="id" filter showClear placeholder="Optional" />
        </div>
        <div class="fld">
          <label>Product</label>
          <Select v-model="form.product_id" :options="products" optionLabel="name" optionValue="id" filter showClear placeholder="Optional" />
        </div>
        <div class="fld">
          <label>Process</label>
          <Select v-model="form.process" :options="processOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>Test Date *</label>
          <input type="date" v-model="form.test_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Length Before *</label>
          <InputNumber v-model="form.length_before" :minFractionDigits="0" :maxFractionDigits="3" />
          <small v-if="errors.length_before" class="err">{{ errors.length_before[0] }}</small>
        </div>
        <div class="fld">
          <label>Length After *</label>
          <InputNumber v-model="form.length_after" :minFractionDigits="0" :maxFractionDigits="3" />
          <small v-if="errors.length_after" class="err">{{ errors.length_after[0] }}</small>
        </div>
        <div class="fld">
          <label>Width Before</label>
          <InputNumber v-model="form.width_before" :minFractionDigits="0" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>Width After</label>
          <InputNumber v-model="form.width_after" :minFractionDigits="0" :maxFractionDigits="2" />
        </div>
      </div>

      <div class="score">
        <div class="score-box">
          <span class="score-label">Length Shrinkage</span>
          <span class="score-val" :class="pctClass(lengthPct)">{{ lengthPct }}%</span>
        </div>
        <div class="score-box">
          <span class="score-label">Width Shrinkage</span>
          <span class="score-val" :class="pctClass(widthPct)">{{ widthPct }}%</span>
        </div>
      </div>

      <div class="form-grid" style="margin-top:1rem">
        <div class="fld">
          <label>Tested By</label>
          <InputText v-model="form.tested_by" />
        </div>
      </div>
      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Record'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { font-size: 0.82rem; color: #6b7280; margin-bottom: 1rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.score { display: flex; gap: 2.5rem; margin-top: 1rem; padding: 0.85rem 1rem; background: #f9fafb; border-radius: 8px; }
.score-box { display: flex; flex-direction: column; gap: 0.25rem; }
.score-label { font-size: 0.75rem; color: #6b7280; }
.score-val { font-size: 1.35rem; font-weight: 700; }
.cap { text-transform: capitalize; }
.warn { color: #d97706; }
.dmg { color: #dc2626; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
