<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

/**
 * The lab's test master.
 *
 * A custom screen rather than a CrudView resource because of one field the
 * generic form cannot express: a panel's component list. Lipid Profile is one
 * price and five reported parameters, and without somewhere to say which five
 * the whole panel idea collapses back into five separate bills.
 */

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/lab-tests')

const canCreate = computed(() => auth.can('lab-tests.create'))
const canEdit = computed(() => auth.can('lab-tests.edit'))
const canDelete = computed(() => auth.can('lab-tests.delete'))

const search = ref('')
const categoryFilter = ref<string | null>(null)
const panelFilter = ref<string>('')
const categories = ref<string[]>([])
// Every test, for the panel component picker. Loaded once and refreshed on save
// so a test created a moment ago can be dropped into a panel immediately.
const allTests = ref<any[]>([])

const SAMPLE_TYPES = ['blood', 'serum', 'plasma', 'urine', 'stool', 'swab', 'sputum', 'tissue', 'other'].map(
  (v) => ({ label: v.charAt(0).toUpperCase() + v.slice(1), value: v }),
)

const PANEL_OPTIONS = computed(() => [
  { label: pt('All'), value: '' },
  { label: pt('Panels only'), value: '1' },
  { label: pt('Single tests'), value: '0' },
])

async function load() {
  const { data } = await http.get('/lab-tests', {
    params: {
      per_page: 100,
      search: search.value || undefined,
      category: categoryFilter.value || undefined,
      is_panel: panelFilter.value === '' ? undefined : panelFilter.value,
    },
  })
  crud.items.value = data.data ?? data
  categories.value = data.categories ?? []
}

async function loadAllTests() {
  const { data } = await http.get('/lab-tests', { params: { per_page: 200, is_active: true } })
  allTests.value = (data.data ?? data).map((t: any) => ({
    label: `${t.code} — ${t.name}`,
    value: t.id,
    is_panel: t.is_panel,
  }))
}

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  code: '',
  name: '',
  category: '',
  sample_type: null as string | null,
  container: '',
  method: '',
  unit: '',
  reference_range: '',
  ref_low: null as number | null,
  ref_high: null as number | null,
  price: 0,
  tax_percent: 0,
  tat_hours: null as number | null,
  is_panel: false,
  is_active: true,
  notes: '',
  components: [] as number[],
})
const form = ref(blank())

// A panel cannot contain itself, and nesting a panel inside a panel is not
// supported by the expansion in LabOrderService — so neither is offered.
const componentOptions = computed(() =>
  allTests.value.filter((t) => !t.is_panel && t.value !== editing.value?.id),
)

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    code: row.code,
    name: row.name,
    category: row.category ?? '',
    sample_type: row.sample_type,
    container: row.container ?? '',
    method: row.method ?? '',
    unit: row.unit ?? '',
    reference_range: row.reference_range ?? '',
    ref_low: row.ref_low,
    ref_high: row.ref_high,
    price: row.price,
    tax_percent: row.tax_percent,
    tat_hours: row.tat_hours,
    is_panel: row.is_panel,
    is_active: row.is_active,
    notes: row.notes ?? '',
    components: (row.components ?? []).map((c: any) => c.id),
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Test saved'), life: 2500 })
    dialogVisible.value = false
    await Promise.all([load(), loadAllTests()])
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the test'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this test?'),
    text: pt('A test that has already been ordered cannot be deleted — mark it inactive instead.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Test deleted'), life: 2500 })
    await Promise.all([load(), loadAllTests()])
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

function rangeLabel(row: any) {
  if (row.reference_range) return row.reference_range
  if (row.ref_low !== null && row.ref_high !== null) return `${row.ref_low} – ${row.ref_high}`
  if (row.ref_high !== null) return `< ${row.ref_high}`
  if (row.ref_low !== null) return `> ${row.ref_low}`
  return '—'
}

onMounted(async () => {
  await Promise.all([load(), loadAllTests()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Test Master')"
      :subtitle="pt('What the lab offers, what it costs, and what normal looks like')"
    />

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Code, name or category')" @keyup.enter="load" />
      <Select
        v-model="categoryFilter"
        :options="categories"
        showClear
        :placeholder="pt('All categories')"
        class="w-14"
        @change="load"
      />
      <Select
        v-model="panelFilter"
        :options="PANEL_OPTIONS"
        optionLabel="label"
        optionValue="value"
        class="w-12"
        @change="load"
      />
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Test')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Lab Tests"
        filenameBase="lab-tests"
        :rows="crud.items.value"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Test' },
          { key: 'category', label: 'Category' },
          { key: 'sample_type', label: 'Sample' },
          { key: 'unit', label: 'Unit' },
          { key: 'reference_range', label: 'Reference' },
          { key: 'price', label: 'Price' },
          { key: 'tat_hours', label: 'TAT (hrs)' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Test')">
        <template #body="{ data }">
          <strong>{{ data.name }}</strong>
          <Tag v-if="data.is_panel" :value="pt('Panel')" severity="info" class="ml-1" />
          <br />
          <small class="muted">{{ data.code }}{{ data.category ? ` · ${data.category}` : '' }}</small>
        </template>
      </Column>
      <Column :header="pt('Sample')">
        <template #body="{ data }">
          {{ data.sample_type || '—' }}
          <br /><small class="muted">{{ data.container || '' }}</small>
        </template>
      </Column>
      <Column :header="pt('Reference')">
        <template #body="{ data }">
          {{ rangeLabel(data) }}
          <span v-if="data.unit" class="muted"> {{ data.unit }}</span>
        </template>
      </Column>
      <Column :header="pt('Parameters')">
        <template #body="{ data }">
          <span v-if="data.is_panel">{{ data.components_count }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Price')">
        <template #body="{ data }">{{ data.price }}</template>
      </Column>
      <Column :header="pt('TAT')">
        <template #body="{ data }">
          <span v-if="data.tat_hours">{{ data.tat_hours }} {{ pt('hrs') }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.is_active ? pt('Active') : pt('Inactive')"
            :severity="data.is_active ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 7rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No tests match this view.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Test') : pt('New Test')"
      :style="{ width: '48rem' }"
    >
      <h4 class="sec">{{ pt('Identity') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Code') }} *</label>
          <InputText v-model="form.code" class="w-full" />
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Test name') }} *</label>
          <InputText v-model="form.name" class="w-full" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Category') }}</label>
          <InputText v-model="form.category" class="w-full" :placeholder="pt('Biochemistry')" />
          <small class="hint">{{ pt('How the report groups it.') }}</small>
        </div>
      </div>

      <h4 class="sec">{{ pt('Sample') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Sample type') }}</label>
          <Select
            v-model="form.sample_type"
            :options="SAMPLE_TYPES"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Container') }}</label>
          <InputText v-model="form.container" class="w-full" :placeholder="pt('EDTA (lavender top)')" />
        </div>
        <div class="fld">
          <label>{{ pt('Method') }}</label>
          <InputText v-model="form.method" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Turnaround (hours)') }}</label>
          <InputNumber v-model="form.tat_hours" :min="0" class="w-full" />
        </div>
      </div>

      <h4 class="sec">{{ pt('Reporting') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Unit') }}</label>
          <InputText v-model="form.unit" class="w-full" :placeholder="pt('mg/dL')" />
        </div>
        <div class="fld">
          <label>{{ pt('Printed reference range') }}</label>
          <InputText v-model="form.reference_range" class="w-full" :placeholder="pt('13.0 - 17.0')" />
        </div>
        <div class="fld">
          <label>{{ pt('Low bound') }}</label>
          <InputNumber v-model="form.ref_low" :maxFractionDigits="4" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('High bound') }}</label>
          <InputNumber v-model="form.ref_high" :maxFractionDigits="4" class="w-full" />
          <small v-if="errors.ref_high" class="err">{{ errors.ref_high[0] }}</small>
        </div>
        <div class="fld span-2">
          <small class="hint">
            {{
              pt(
                'Only the bounds flag a result high or low. Leave them blank for a qualitative test — a value that cannot be compared is never coloured.',
              )
            }}
          </small>
        </div>
      </div>

      <h4 class="sec">{{ pt('Billing') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Price') }}</label>
          <InputNumber v-model="form.price" :minFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Tax %') }}</label>
          <InputNumber v-model="form.tax_percent" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label class="inline-check">
            <Checkbox v-model="form.is_active" binary /> {{ pt('Active') }}
          </label>
        </div>
      </div>

      <h4 class="sec">{{ pt('Panel') }}</h4>
      <div class="grid">
        <div class="fld span-2">
          <label class="inline-check">
            <Checkbox v-model="form.is_panel" binary />
            {{ pt('This is a panel — billed once, reported as several parameters') }}
          </label>
        </div>
        <div v-if="form.is_panel" class="fld span-2">
          <label>{{ pt('Parameters in this panel') }}</label>
          <MultiSelect
            v-model="form.components"
            :options="componentOptions"
            optionLabel="label"
            optionValue="value"
            filter
            display="chip"
            class="w-full"
            :placeholder="pt('Pick the tests this panel reports')"
          />
          <small class="hint">{{ pt('A panel with no parameters simply reports itself.') }}</small>
        </div>
      </div>

      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
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
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.inline-check { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.35rem; }
.sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.sec:first-child { margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.w-12 { width: 10rem; }
.w-14 { width: 12rem; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
</style>
