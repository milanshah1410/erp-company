<script setup lang="ts">
/**
 * Flock batches — the poultry performance board.
 *
 * Every number a poultry farm is judged on is a ratio over birds placed, so the
 * table shows the ratios and not the raw counters they come from: mortality %,
 * feed per bird, feed per dozen eggs, lay %. Broiler rows show a dash where a
 * layer figure does not apply — printing a lay percentage for a broiler batch
 * would be a metric computed from the wrong denominator.
 *
 * Mortality, feed and eggs are read-only here on purpose. They come from the daily
 * production log, and letting this form overwrite them would put the batch record
 * and the log history permanently at odds.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('livestock-flocks.create'))
const canEdit = computed(() => auth.can('livestock-flocks.edit'))
const canDelete = computed(() => auth.can('livestock-flocks.delete'))

const BIRD_TYPES = [
  { label: 'Broiler', value: 'broiler' },
  { label: 'Layer', value: 'layer' },
  { label: 'Breeder', value: 'breeder' },
  { label: 'Duck', value: 'duck' },
  { label: 'Other', value: 'other' },
]

const rows = ref<any[]>([])
const summary = ref<any>({
  active_batches: 0, birds: 0, placed: 0, mortality: 0,
  mortality_percent: 0, feed_kg: 0, eggs: 0, lay_window_days: 7,
})
const warehouses = ref<any[]>([])
const suppliers = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const filterStatus = ref<string | null>(null)
const filterType = ref<string | null>(null)

function money(v: any) {
  return formatCurrency(v)
}

function typeLabel(value: string) {
  return pt(BIRD_TYPES.find((t) => t.value === value)?.label ?? value)
}

/**
 * Mortality is the number a poultry farm watches hourly. Around 5% over a broiler
 * cycle is normal, so the colouring turns at 5 and again at 10 rather than at some
 * arbitrary midpoint.
 */
function mortalitySeverity(percent: number) {
  if (percent <= 5) return 'success'
  if (percent <= 10) return 'warn'
  return 'danger'
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/livestock-flocks', {
      params: {
        search: search.value || undefined,
        status: filterStatus.value || undefined,
        bird_type: filterType.value || undefined,
        per_page: 200,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [w, s] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 200 } }),
    http.get('/suppliers', { params: { per_page: 200 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
/** Counters the log owns, shown read-only while editing. */
const readOnlyCounters = ref<any>(null)

const blank = () => ({
  batch_code: '',
  bird_type: 'broiler',
  breed: '',
  placed_on: new Date(),
  opening_birds: null as number | null,
  sold_birds: 0,
  chick_cost: null as number | null,
  supplier_id: null as number | null,
  warehouse_id: null as number | null,
  status: 'active',
  closed_on: null as Date | null,
  notes: '',
})

const form = ref(blank())

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function parse(value: string | null) {
  return value ? new Date(value + 'T00:00:00') : null
}

function openNew() {
  editingId.value = null
  errors.value = {}
  readOnlyCounters.value = null
  form.value = blank()
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  readOnlyCounters.value = row
  form.value = {
    batch_code: row.batch_code,
    bird_type: row.bird_type,
    breed: row.breed ?? '',
    placed_on: parse(row.placed_on) ?? new Date(),
    opening_birds: row.opening_birds,
    sold_birds: row.sold_birds ?? 0,
    chick_cost: row.chick_cost ?? null,
    supplier_id: row.supplier_id ?? null,
    warehouse_id: row.warehouse_id ?? null,
    status: row.status,
    closed_on: parse(row.closed_on),
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = {
    ...form.value,
    placed_on: iso(form.value.placed_on),
    closed_on: iso(form.value.closed_on),
  }
  try {
    if (editingId.value) {
      await http.put(`/livestock-flocks/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Batch updated'), life: 2500 })
    } else {
      await http.post('/livestock-flocks', payload)
      toast.add({ severity: 'success', summary: pt('Batch placed'), life: 2500 })
    }
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the batch'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete batch {code}?', { code: row.batch_code }),
    text: pt('A finished batch should be closed instead, which keeps its performance history. Deleting also deletes its production entries.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/livestock-flocks/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Batch deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => {
  await Promise.all([load(), loadRefs()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Flock Batches')"
      :subtitle="pt('Birds tracked per placement — mortality, feed and lay against the number placed')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Place Batch')" icon="pi pi-plus" @click="openNew" />
      </template>
    </PageHeader>

    <div class="stat-row">
      <div class="stat">
        <span class="s-label">{{ pt('Birds alive') }}</span>
        <strong>{{ summary.birds }}</strong>
        <small>{{ pt('in {n} active batches', { n: summary.active_batches }) }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Mortality') }}</span>
        <strong>{{ summary.mortality_percent }}%</strong>
        <small>{{ pt('{n} of {p} placed', { n: summary.mortality, p: summary.placed }) }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Feed drawn') }}</span>
        <strong>{{ summary.feed_kg }}</strong>
        <small>{{ pt('kg, active batches') }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Eggs collected') }}</span>
        <strong>{{ summary.eggs }}</strong>
        <small>{{ pt('active batches, lifetime') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search batch code, breed')" class="t-search" @keyup.enter="load" />
      <Select
        v-model="filterType"
        :options="BIRD_TYPES"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All bird types')"
        showClear
        class="t-sel"
        @change="load"
      />
      <Select
        v-model="filterStatus"
        :options="[
          { label: pt('Active'), value: 'active' },
          { label: pt('Closed'), value: 'closed' },
        ]"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All statuses')"
        showClear
        class="t-sel"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="load" />
      <TableExportButtons
        title="Flock Batches"
        filenameBase="livestock-flocks"
        :rows="rows"
        :columns="[
          { key: 'batch_code', label: 'Batch' },
          { key: 'bird_type', label: 'Type' },
          { key: 'placed_on', label: 'Placed' },
          { key: 'age_days', label: 'Age (days)' },
          { key: 'opening_birds', label: 'Placed birds' },
          { key: 'current_birds', label: 'Alive' },
          { key: 'mortality_percent', label: 'Mortality %' },
          { key: 'feed_per_bird', label: 'Feed/bird (kg)' },
          { key: 'eggs_collected', label: 'Eggs' },
          { key: 'feed_per_dozen_eggs', label: 'Feed/dozen (kg)' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
      <Column :header="pt('Batch')">
        <template #body="{ data }">
          <strong>{{ data.batch_code }}</strong>
          <small class="muted"> · {{ typeLabel(data.bird_type) }}</small>
          <div v-if="data.breed" class="muted sm">{{ data.breed }}</div>
        </template>
      </Column>
      <Column :header="pt('Age')">
        <template #body="{ data }">
          {{ pt('{n} d', { n: data.age_days }) }}
          <div class="muted sm">{{ data.placed_on }}</div>
        </template>
      </Column>
      <Column :header="pt('Birds')">
        <template #body="{ data }">
          <strong>{{ data.current_birds }}</strong>
          <small class="muted"> / {{ data.opening_birds }}</small>
          <div v-if="data.sold_birds" class="muted sm">{{ pt('{n} sold', { n: data.sold_birds }) }}</div>
        </template>
      </Column>
      <Column :header="pt('Mortality')">
        <template #body="{ data }">
          <Tag :value="data.mortality_percent + '%'" :severity="mortalitySeverity(data.mortality_percent)" />
          <div class="muted sm">{{ pt('{n} lost', { n: data.mortality_birds }) }}</div>
        </template>
      </Column>
      <Column :header="pt('Feed / bird')">
        <template #body="{ data }">
          {{ data.feed_per_bird }} <small class="muted">kg</small>
          <div class="muted sm">{{ data.feed_consumed_kg }} kg {{ pt('total') }}</div>
        </template>
      </Column>
      <Column :header="pt('Eggs')">
        <template #body="{ data }">
          <template v-if="data.bird_type === 'broiler'"><span class="muted">—</span></template>
          <template v-else>
            {{ data.eggs_collected }}
            <div class="muted sm">
              {{ data.lay_percent === null ? pt('no lay data') : pt('{n}% lay', { n: data.lay_percent }) }}
            </div>
          </template>
        </template>
      </Column>
      <Column :header="pt('Feed / dozen')">
        <template #body="{ data }">
          <template v-if="data.feed_per_dozen_eggs === null"><span class="muted">—</span></template>
          <template v-else>{{ data.feed_per_dozen_eggs }} <small class="muted">kg</small></template>
        </template>
      </Column>
      <Column :header="pt('Chick cost')">
        <template #body="{ data }">
          <template v-if="!data.chick_cost"><span class="muted">—</span></template>
          <template v-else>
            {{ money(data.chick_cost) }}
            <div class="muted sm">{{ money(data.cost_per_bird) }}/{{ pt('bird') }}</div>
          </template>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.status === 'active' ? pt('Active') : pt('Closed')"
            :severity="data.status === 'active' ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No flock batches yet.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Batch') : pt('Place Batch')"
      modal
      :style="{ width: '46rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Batch code') }} *</label>
          <InputText v-model="form.batch_code" />
          <small v-if="errors.batch_code" class="err">{{ errors.batch_code[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Bird type') }} *</label>
          <Select v-model="form.bird_type" :options="BIRD_TYPES" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Breed / strain') }}</label>
          <InputText v-model="form.breed" :placeholder="pt('Cobb, Ross, BV-300')" />
        </div>
        <div class="fld">
          <label>{{ pt('Placed on') }} *</label>
          <DatePicker v-model="form.placed_on" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld">
          <label>{{ pt('Birds placed') }} *</label>
          <InputNumber v-model="form.opening_birds" :min="1" />
          <small class="hint">{{ pt('Never changes — every ratio is measured against it.') }}</small>
          <small v-if="errors.opening_birds" class="err">{{ errors.opening_birds[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Birds sold') }}</label>
          <InputNumber v-model="form.sold_birds" :min="0" />
        </div>
        <div class="fld">
          <label>{{ pt('Chick cost (total)') }}</label>
          <InputNumber v-model="form.chick_cost" :min="0" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Hatchery') }}</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter showClear :placeholder="pt('Supplier')" />
        </div>
        <div class="fld">
          <label>{{ pt('Shed / warehouse') }}</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" filter showClear :placeholder="pt('Where eggs land, feed is drawn')" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }} *</label>
          <Select
            v-model="form.status"
            :options="[
              { label: pt('Active'), value: 'active' },
              { label: pt('Closed'), value: 'closed' },
            ]"
            optionLabel="label"
            optionValue="value"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Closed on') }}{{ form.status === 'closed' ? ' *' : '' }}</label>
          <DatePicker v-model="form.closed_on" dateFormat="yy-mm-dd" showButtonBar :disabled="form.status !== 'closed'" />
          <small v-if="errors.closed_on" class="err">{{ errors.closed_on[0] }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>

      <!-- What the daily log owns. Shown, not editable: the log is the source. -->
      <div v-if="readOnlyCounters" class="from-log">
        <span class="fl-title">{{ pt('From the daily production log') }}</span>
        <div class="fl-row">
          <span>{{ pt('Mortality') }}: <strong>{{ readOnlyCounters.mortality_birds }}</strong></span>
          <span>{{ pt('Culled') }}: <strong>{{ readOnlyCounters.culled_birds }}</strong></span>
          <span>{{ pt('Feed') }}: <strong>{{ readOnlyCounters.feed_consumed_kg }} kg</strong></span>
          <span>{{ pt('Eggs') }}: <strong>{{ readOnlyCounters.eggs_collected }}</strong></span>
        </div>
        <small class="hint">{{ pt('Correct these by editing the production entries they came from.') }}</small>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.stat {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 165px;
}
.stat strong { font-size: 1.35rem; font-weight: 700; }
.s-label { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.stat small { font-size: 0.72rem; color: #6b7280; }
.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.t-search { width: 230px; }
.t-sel { width: 170px; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
.sm { font-size: 0.72rem; }
.from-log {
  margin-top: 1rem; padding: 0.7rem 0.9rem;
  border: 1px dashed var(--surface-border, #e5e7eb); border-radius: 10px;
  display: flex; flex-direction: column; gap: 0.3rem;
}
.fl-title { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.fl-row { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: 0.85rem; }
@media (max-width: 900px) {
  .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .grid { grid-template-columns: 1fr; }
}
</style>
