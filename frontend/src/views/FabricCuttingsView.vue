<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
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
const crud = useCrud('/fabric-cuttings')

const rolls = ref<any[]>([])

const canCreate = computed(() => auth.can('textile.create'))
const canDelete = computed(() => auth.can('textile.delete'))

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

// --- Tabs ---
const tab = ref<'cuttings' | 'loss'>('cuttings')
const tabs = [
  { key: 'cuttings', label: 'Cuttings', icon: 'pi pi-scissors' },
  { key: 'loss', label: 'Cutting Loss Report', icon: 'pi pi-chart-bar' },
] as const

const lossRows = ref<any[]>([])
const lossTotals = ref<any>(null)
const lossLoading = ref(false)
async function loadLoss() {
  lossLoading.value = true
  try {
    const { data } = await http.get('/fabric-cuttings/loss-report')
    lossRows.value = data.data ?? data
    lossTotals.value = data.totals ?? null
  } finally {
    lossLoading.value = false
  }
}
function switchTab(key: typeof tab.value) {
  tab.value = key
  if (key === 'loss') loadLoss()
  else crud.fetch()
}

// --- Create dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const form = ref<any>(emptyForm())
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}
function emptyForm() {
  return { fabric_roll_id: null, cut_date: today(), length_consumed: null, cutting_loss: 0, leftover_length: 0, pieces_produced: 0, notes: '' }
}

const selectedRoll = computed(() => rolls.value.find((r) => r.id === form.value.fabric_roll_id))
const remaining = computed(() => (selectedRoll.value ? Number(selectedRoll.value.current_length) : 0))
const totalUsed = computed(() =>
  Number(form.value.length_consumed || 0) + Number(form.value.cutting_loss || 0) + Number(form.value.leftover_length || 0),
)
const overCut = computed(() => totalUsed.value - remaining.value > 0.0001)

function openCreate() {
  form.value = emptyForm()
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  if (overCut.value) {
    errors.value = { length_consumed: [`Total used (${totalUsed.value}) exceeds remaining length (${remaining.value}).`] }
    return
  }
  saving.value = true
  errors.value = {}
  try {
    await http.post('/fabric-cuttings', form.value)
    toast.add({ severity: 'success', summary: 'Cutting recorded', life: 2500 })
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
  const res = await Swal.fire({
    title: `Reverse ${row.cutting_number}?`,
    text: 'The used length is returned to the roll, any leftover roll is removed and the stock movements are unwound.',
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Reverse & delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/fabric-cuttings/${row.id}`)
    toast.add({ severity: 'success', summary: 'Cutting reversed', life: 2500 })
    crud.fetch()
    loadRolls()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  crud.fetch()
}

async function loadRolls() {
  const { data } = await http.get('/fabric-rolls', { params: { per_page: 200, all: 1, status: 'available' } })
  const av = data.data ?? data
  const { data: pd } = await http.get('/fabric-rolls', { params: { per_page: 200, all: 1, status: 'partial' } })
  rolls.value = [...av, ...(pd.data ?? pd)].map((x: any) => ({
    ...x, label: `${x.roll_number} — ${x.product?.name ?? ''} (${Number(x.current_length)} ${x.length_unit} left)`,
  }))
}

onMounted(async () => {
  await loadRolls()
  crud.fetch()
})
</script>

<template>
  <div>
    <PageHeader title="Fabric Cutting" :count="crud.total.value">
      <template #actions>
        <Button v-if="canCreate && tab === 'cuttings'" label="New Cutting" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tabbar">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <div v-if="tab === 'cuttings'" class="toolbar no-print" style="display:flex;justify-content:flex-end;margin-bottom:1rem">
      <TableExportButtons title="Fabric Cuttings" endpoint="/fabric-cuttings" :columns="[
        { key: 'cutting_number', label: 'Cutting #' },
        { key: 'fabric_roll.roll_number', label: 'Roll' },
        { key: 'cut_date', label: 'Date' },
        { key: 'length_used', label: 'Used' },
        { key: 'length_consumed', label: 'Consumed' },
        { key: 'cutting_loss', label: 'Loss' },
        { key: 'leftover_length', label: 'Leftover' },
        { key: 'pieces_produced', label: 'Pieces' },
      ]" />
    </div>

    <!-- Cuttings -->
    <DataTable v-if="tab === 'cuttings'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="cutting_number" header="Cutting #" />
      <Column header="Roll">
        <template #body="{ data }">
          <div>{{ data.fabric_roll?.roll_number }}</div>
          <small class="muted">{{ data.fabric_roll?.product?.name }}</small>
        </template>
      </Column>
      <Column field="cut_date" header="Date" />
      <Column header="Used" style="text-align:right">
        <template #body="{ data }">{{ Number(data.length_used) }}</template>
      </Column>
      <Column header="Consumed" style="text-align:right">
        <template #body="{ data }">{{ Number(data.length_consumed) }}</template>
      </Column>
      <Column header="Loss" style="text-align:right">
        <template #body="{ data }"><strong class="dmg">{{ Number(data.cutting_loss) }}</strong> <small class="muted">({{ Number(data.loss_pct) }}%)</small></template>
      </Column>
      <Column header="Leftover" style="text-align:right">
        <template #body="{ data }">
          <span class="lo">{{ Number(data.leftover_length) }}</span>
          <small v-if="data.leftover_roll" class="muted"> → {{ data.leftover_roll }}</small>
        </template>
      </Column>
      <Column field="pieces_produced" header="Pieces" style="text-align:right" />
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" size="small" text rounded severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canDelete" icon="pi pi-undo" size="small" text severity="danger" title="Reverse" @click="removeRow(data)" />
        </template>
      </Column>
      <template #empty>No cuttings recorded yet.</template>
    </DataTable>

    <!-- Loss report -->
    <div v-else>
      <div v-if="lossTotals" class="totals">
        <div class="tot"><span>Total Used</span><strong>{{ Number(lossTotals.length_used) }}</strong></div>
        <div class="tot"><span>Consumed</span><strong>{{ Number(lossTotals.length_consumed) }}</strong></div>
        <div class="tot"><span>Cutting Loss</span><strong class="dmg">{{ Number(lossTotals.cutting_loss) }}</strong></div>
        <div class="tot"><span>Leftover</span><strong class="lo">{{ Number(lossTotals.leftover_length) }}</strong></div>
      </div>
      <DataTable :value="lossRows" :loading="lossLoading" dataKey="id" class="p-datatable-sm" paginator :rows="20">
        <Column field="cutting_number" header="Cutting #" />
        <Column field="cut_date" header="Date" />
        <Column header="Roll">
          <template #body="{ data }">{{ data.roll_number }}</template>
        </Column>
        <Column header="Fabric">
          <template #body="{ data }">{{ data.product }}</template>
        </Column>
        <Column header="Used" style="text-align:right"><template #body="{ data }">{{ Number(data.length_used) }}</template></Column>
        <Column header="Consumed" style="text-align:right"><template #body="{ data }">{{ Number(data.length_consumed) }}</template></Column>
        <Column header="Loss" style="text-align:right"><template #body="{ data }"><strong class="dmg">{{ Number(data.cutting_loss) }}</strong></template></Column>
        <Column header="Loss %" style="text-align:right"><template #body="{ data }">{{ Number(data.loss_pct) }}%</template></Column>
        <Column header="Leftover" style="text-align:right"><template #body="{ data }"><span class="lo">{{ Number(data.leftover_length) }}</span></template></Column>
        <template #empty>No cutting data.</template>
      </DataTable>
    </div>

    <!-- Create dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="New Cutting" :style="{ width: '620px' }">
      <div class="fld">
        <label>Fabric Roll *</label>
        <Select v-model="form.fabric_roll_id" :options="rolls" optionLabel="label" optionValue="id" filter placeholder="Pick a roll to cut from" />
        <small v-if="errors.fabric_roll_id" class="err">{{ errors.fabric_roll_id[0] }}</small>
      </div>
      <p v-if="selectedRoll" class="remaining">Remaining on roll: <strong>{{ remaining }} {{ selectedRoll.length_unit }}</strong></p>

      <div class="form-grid">
        <div class="fld">
          <label>Cut Date *</label>
          <input type="date" v-model="form.cut_date" class="date-input" />
        </div>
        <div class="fld">
          <label>Pieces Produced</label>
          <InputNumber v-model="form.pieces_produced" :min="0" />
        </div>
        <div class="fld">
          <label>Consumed (into pieces) *</label>
          <InputNumber v-model="form.length_consumed" :min="0" :minFractionDigits="0" :maxFractionDigits="3" />
          <small v-if="errors.length_consumed" class="err">{{ errors.length_consumed[0] }}</small>
        </div>
        <div class="fld">
          <label>Cutting Loss (wastage)</label>
          <InputNumber v-model="form.cutting_loss" :min="0" :minFractionDigits="0" :maxFractionDigits="3" />
        </div>
        <div class="fld">
          <label>Leftover (reusable remnant)</label>
          <InputNumber v-model="form.leftover_length" :min="0" :minFractionDigits="0" :maxFractionDigits="3" />
        </div>
      </div>

      <div class="used-bar" :class="{ over: overCut }">
        <span>Total drawn off roll: <strong>{{ totalUsed }}</strong></span>
        <span v-if="selectedRoll">of {{ remaining }} {{ selectedRoll.length_unit }}</span>
        <span v-if="overCut" class="err">— exceeds remaining!</span>
      </div>
      <p class="hint">Leftover length is booked back as a new leftover roll (and returned to stock if the parent roll was stock-posted). Consumed + loss leave inventory.</p>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Record Cutting" :loading="saving" :disabled="overCut" @click="save" />
      </template>
    </Dialog>

    <DetailDialog
      v-model:visible="detailVisible"
      title="Fabric Cutting"
      :record="detailRecord"
      :fields="[
        { label: 'Cutting #', key: 'cutting_number' },
        { label: 'Roll', key: 'fabric_roll.roll_number' },
        { label: 'Fabric', key: 'fabric_roll.product.name' },
        { label: 'Date', key: 'cut_date' },
        { label: 'Length Used', key: 'length_used', format: (v) => String(Number(v)) },
        { label: 'Consumed', key: 'length_consumed', format: (v) => String(Number(v)) },
        { label: 'Cutting Loss', key: 'cutting_loss', format: (v) => String(Number(v)) },
        { label: 'Loss %', key: 'loss_pct', format: (v) => `${Number(v)}%` },
        { label: 'Leftover', key: 'leftover_length', format: (v) => String(Number(v)) },
        { label: 'Pieces Produced', key: 'pieces_produced' },
        { label: 'Notes', key: 'notes', full: true },
      ]"
    />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.tabbar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.tab { background: none; border: none; padding: 0.6rem 1rem; font: inherit; font-size: 0.9rem; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; display: flex; align-items: center; gap: 0.4rem; }
.tab:hover { color: #111827; }
.tab.active { color: var(--p-primary-color, #6366f1); border-bottom-color: var(--p-primary-color, #6366f1); font-weight: 600; }
.totals { display: flex; gap: 1rem; margin-bottom: 1rem; }
.tot { flex: 1; background: #f9fafb; border-radius: 8px; padding: 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; }
.tot span { font-size: 0.75rem; color: #6b7280; }
.tot strong { font-size: 1.2rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.75rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputnumber) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.remaining { margin: 0.75rem 0 0; font-size: 0.9rem; color: #374151; }
.used-bar { margin-top: 1rem; padding: 0.6rem 0.9rem; background: #f0f9ff; border-radius: 6px; font-size: 0.9rem; display: flex; gap: 0.5rem; align-items: center; }
.used-bar.over { background: #fef2f2; }
.hint { font-size: 0.8rem; color: #6b7280; margin-top: 0.6rem; }
.muted { color: #6b7280; font-size: 0.78rem; }
.dmg { color: #dc2626; }
.lo { color: #059669; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
