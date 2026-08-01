<script setup lang="ts">
/**
 * The herd register.
 *
 * A yield sheet, not a card index: a dairy keeps animal records to find out which
 * animals are earning their feed, so the recent-output columns sit beside the
 * particulars rather than behind a drill-down. Blank output is shown as a dash and
 * never as zero — an animal nobody has logged is not an animal that produced
 * nothing, and the difference decides whether you look at the cow or at the
 * milker.
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
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('livestock-animals.create'))
const canEdit = computed(() => auth.can('livestock-animals.edit'))
const canDelete = computed(() => auth.can('livestock-animals.delete'))

const SPECIES = [
  { label: 'Cow', value: 'cow' },
  { label: 'Buffalo', value: 'buffalo' },
  { label: 'Goat', value: 'goat' },
  { label: 'Sheep', value: 'sheep' },
  { label: 'Other', value: 'other' },
]

const STATUSES = [
  { label: 'Milking', value: 'active' },
  { label: 'Dry', value: 'dry' },
  { label: 'Sold', value: 'sold' },
  { label: 'Dead', value: 'dead' },
  { label: 'Culled', value: 'culled' },
]

const rows = ref<any[]>([])
const summary = ref<any>({ total: 0, milking: 0, dry: 0, exited: 0, window_days: 30, daily_average: 0, avg_fat: null })
const warehouses = ref<any[]>([])
const suppliers = ref<any[]>([])
const loading = ref(false)
const search = ref('')
const filterStatus = ref<string | null>(null)
const filterSpecies = ref<string | null>(null)

function statusLabel(value: string) {
  return pt(STATUSES.find((s) => s.value === value)?.label ?? value)
}

function statusSeverity(value: string) {
  switch (value) {
    case 'active':
      return 'success'
    case 'dry':
      return 'warn'
    case 'sold':
      return 'info'
    default:
      return 'danger'
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/livestock-animals', {
      params: {
        search: search.value || undefined,
        status: filterStatus.value || undefined,
        species: filterSpecies.value || undefined,
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

const blank = () => ({
  tag_number: '',
  name: '',
  species: 'cow',
  breed: '',
  gender: 'female',
  date_of_birth: null as Date | null,
  acquired_on: null as Date | null,
  acquisition_type: 'purchased',
  acquisition_cost: null as number | null,
  supplier_id: null as number | null,
  warehouse_id: null as number | null,
  dam_tag: '',
  sire_tag: '',
  lactation_number: null as number | null,
  status: 'active',
  exited_on: null as Date | null,
  notes: '',
})

const form = ref(blank())

/** Statuses that mean the animal has left, and therefore need a date. */
const hasExited = computed(() => ['sold', 'dead', 'culled'].includes(form.value.status))

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
  form.value = blank()
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  form.value = {
    tag_number: row.tag_number,
    name: row.name ?? '',
    species: row.species,
    breed: row.breed ?? '',
    gender: row.gender,
    date_of_birth: parse(row.date_of_birth),
    acquired_on: parse(row.acquired_on),
    acquisition_type: row.acquisition_type,
    acquisition_cost: row.acquisition_cost ?? null,
    supplier_id: row.supplier_id ?? null,
    warehouse_id: row.warehouse_id ?? null,
    dam_tag: row.dam_tag ?? '',
    sire_tag: row.sire_tag ?? '',
    lactation_number: row.lactation_number ?? null,
    status: row.status,
    exited_on: parse(row.exited_on),
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = {
    ...form.value,
    date_of_birth: iso(form.value.date_of_birth),
    acquired_on: iso(form.value.acquired_on),
    exited_on: iso(form.value.exited_on),
  }
  try {
    if (editingId.value) {
      await http.put(`/livestock-animals/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Animal updated'), life: 2500 })
    } else {
      await http.post('/livestock-animals', payload)
      toast.add({ severity: 'success', summary: pt('Animal added to the register'), life: 2500 })
    }
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the animal'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Remove {tag} from the register?', { tag: row.tag_number }),
    text: pt('For an animal that was sold or died, set its status instead — that keeps its yield history. Deleting also deletes its production entries.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/livestock-animals/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Animal removed'), life: 2500 })
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
      :title="pt('Animals')"
      :subtitle="pt('The herd, per head — with what each animal has actually produced')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Add Animal')" icon="pi pi-plus" @click="openNew" />
      </template>
    </PageHeader>

    <div class="stat-row">
      <div class="stat">
        <span class="s-label">{{ pt('On the farm') }}</span>
        <strong>{{ summary.milking + summary.dry }}</strong>
        <small>{{ pt('{m} milking · {d} dry', { m: summary.milking, d: summary.dry }) }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Daily average') }}</span>
        <strong>{{ summary.daily_average }}</strong>
        <small>{{ pt('per recorded day, last {n} days', { n: summary.window_days }) }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Average fat') }}</span>
        <strong>{{ summary.avg_fat === null ? '—' : summary.avg_fat + '%' }}</strong>
        <small>{{ pt('across logged sessions') }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Left the herd') }}</span>
        <strong>{{ summary.exited }}</strong>
        <small>{{ pt('sold, dead or culled') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search tag, name, breed')" class="t-search" @keyup.enter="load" />
      <Select
        v-model="filterStatus"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All statuses')"
        showClear
        class="t-sel"
        @change="load"
      />
      <Select
        v-model="filterSpecies"
        :options="SPECIES"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All species')"
        showClear
        class="t-sel"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="load" />
      <TableExportButtons
        title="Animals"
        filenameBase="livestock-animals"
        :rows="rows"
        :columns="[
          { key: 'tag_number', label: 'Tag' },
          { key: 'name', label: 'Name' },
          { key: 'species', label: 'Species' },
          { key: 'breed', label: 'Breed' },
          { key: 'age_months', label: 'Age (months)' },
          { key: 'lactation_number', label: 'Lactation' },
          { key: 'window_quantity', label: 'Output (30d)' },
          { key: 'per_session', label: 'Per session' },
          { key: 'avg_fat', label: 'Avg fat %' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
      <Column :header="pt('Tag')">
        <template #body="{ data }">
          <strong>{{ data.tag_number }}</strong>
          <small v-if="data.name" class="muted"> · {{ data.name }}</small>
        </template>
      </Column>
      <Column :header="pt('Animal')">
        <template #body="{ data }">
          {{ pt(SPECIES.find((s) => s.value === data.species)?.label ?? data.species) }}
          <small v-if="data.breed" class="muted"> · {{ data.breed }}</small>
        </template>
      </Column>
      <Column :header="pt('Age')">
        <template #body="{ data }">
          <template v-if="data.age_months === null">—</template>
          <template v-else>{{ pt('{n} mo', { n: data.age_months }) }}</template>
        </template>
      </Column>
      <Column field="lactation_number" :header="pt('Lact.')" />
      <Column :header="pt('Output (30d)')">
        <template #body="{ data }">
          <template v-if="data.window_quantity === null"><span class="muted">—</span></template>
          <template v-else>
            <strong>{{ data.window_quantity }}</strong>
            <small class="muted"> · {{ data.per_session }}{{ pt('/session') }}</small>
          </template>
        </template>
      </Column>
      <Column :header="pt('Fat')">
        <template #body="{ data }">{{ data.avg_fat === null ? '—' : data.avg_fat + '%' }}</template>
      </Column>
      <Column :header="pt('Last logged')">
        <template #body="{ data }">{{ data.last_log ?? '—' }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No animals on the register yet.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Animal') : pt('Add Animal')"
      modal
      :style="{ width: '48rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Tag number') }} *</label>
          <InputText v-model="form.tag_number" />
          <small v-if="errors.tag_number" class="err">{{ errors.tag_number[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Name') }}</label>
          <InputText v-model="form.name" />
        </div>
        <div class="fld">
          <label>{{ pt('Species') }} *</label>
          <Select v-model="form.species" :options="SPECIES" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Breed') }}</label>
          <InputText v-model="form.breed" :placeholder="pt('HF, Jersey, Murrah')" />
        </div>
        <div class="fld">
          <label>{{ pt('Gender') }}</label>
          <Select
            v-model="form.gender"
            :options="[
              { label: pt('Female'), value: 'female' },
              { label: pt('Male'), value: 'male' },
            ]"
            optionLabel="label"
            optionValue="value"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Date of birth') }}</label>
          <DatePicker v-model="form.date_of_birth" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld">
          <label>{{ pt('How acquired') }}</label>
          <Select
            v-model="form.acquisition_type"
            :options="[
              { label: pt('Purchased'), value: 'purchased' },
              { label: pt('Born on the farm'), value: 'born' },
            ]"
            optionLabel="label"
            optionValue="value"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Acquired on') }}</label>
          <DatePicker v-model="form.acquired_on" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld">
          <label>{{ pt('Cost') }}</label>
          <InputNumber v-model="form.acquisition_cost" :min="0" :maxFractionDigits="2" />
        </div>
        <div class="fld">
          <label>{{ pt('Bought from') }}</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter showClear :placeholder="pt('Supplier')" />
        </div>
        <div class="fld">
          <label>{{ pt('Shed / warehouse') }}</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" filter showClear :placeholder="pt('Where its output lands')" />
        </div>
        <div class="fld">
          <label>{{ pt('Lactation number') }}</label>
          <InputNumber v-model="form.lactation_number" :min="0" :max="20" />
        </div>
        <div class="fld">
          <label>{{ pt('Dam tag') }}</label>
          <InputText v-model="form.dam_tag" />
          <small class="hint">{{ pt('Free text — the dam may no longer be on this farm.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sire tag') }}</label>
          <InputText v-model="form.sire_tag" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }} *</label>
          <Select v-model="form.status" :options="STATUSES" optionLabel="label" optionValue="value" />
          <small class="hint">{{ pt('Dry is not inactive — a dry animal is still on the farm and still eating.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Left the herd on') }}{{ hasExited ? ' *' : '' }}</label>
          <DatePicker v-model="form.exited_on" dateFormat="yy-mm-dd" showButtonBar :disabled="!hasExited" />
          <small v-if="errors.exited_on" class="err">{{ errors.exited_on[0] }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
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
@media (max-width: 900px) {
  .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .grid { grid-template-columns: 1fr; }
}
</style>
