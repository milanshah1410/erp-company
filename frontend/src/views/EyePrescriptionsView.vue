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
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
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

const canCreate = computed(() => auth.can('eye-prescriptions.create'))
const canEdit = computed(() => auth.can('eye-prescriptions.edit'))
const canDelete = computed(() => auth.can('eye-prescriptions.delete'))

const rows = ref<any[]>([])
const loading = ref(false)
const customers = ref<any[]>([])
const search = ref('')
const validOnly = ref(false)

const VISION_TYPES = [
  { label: 'Distance', value: 'distance' },
  { label: 'Near', value: 'near' },
  { label: 'Bifocal', value: 'bifocal' },
  { label: 'Progressive', value: 'progressive' },
]
const GENDERS = [
  { label: 'Male', value: 'male' },
  { label: 'Female', value: 'female' },
  { label: 'Other', value: 'other' },
]

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/eye-prescriptions', {
      params: {
        search: search.value || undefined,
        valid_only: validOnly.value ? 1 : undefined,
        per_page: 50,
      },
    })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 200, is_active: true } })
  customers.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Record a test ---------------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  patient_name: '',
  patient_age: null as number | null,
  patient_gender: null as string | null,
  patient_phone: '',
  optometrist_name: '',
  optometrist_registration_no: '',
  vision_type: 'distance',
  right_sph: null as number | null,
  right_cyl: null as number | null,
  right_axis: null as number | null,
  right_add: null as number | null,
  left_sph: null as number | null,
  left_cyl: null as number | null,
  left_axis: null as number | null,
  left_add: null as number | null,
  pd: null as number | null,
  prescribed_on: new Date() as Date | null,
  valid_until: null as Date | null,
  notes: '',
})
const form = ref(blank())

/** ADD only means anything on a bifocal or progressive pair. */
const hasAdd = computed(() => ['bifocal', 'progressive'].includes(form.value.vision_type))

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
    ...blank(),
    customer_id: row.customer_id,
    patient_name: row.patient_name,
    patient_age: row.patient_age,
    patient_gender: row.patient_gender,
    patient_phone: row.patient_phone ?? '',
    optometrist_name: row.optometrist_name ?? '',
    optometrist_registration_no: row.optometrist_registration_no ?? '',
    vision_type: row.vision_type,
    right_sph: row.right.sph,
    right_cyl: row.right.cyl,
    right_axis: row.right.axis,
    right_add: row.right.add,
    left_sph: row.left.sph,
    left_cyl: row.left.cyl,
    left_axis: row.left.axis,
    left_add: row.left.add,
    pd: row.pd,
    prescribed_on: row.prescribed_on ? new Date(row.prescribed_on) : null,
    valid_until: row.valid_until ? new Date(row.valid_until) : null,
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      prescribed_on: iso(form.value.prescribed_on),
      valid_until: iso(form.value.valid_until),
    }
    if (editing.value) await http.put(`/eye-prescriptions/${editing.value.id}`, payload)
    else await http.post('/eye-prescriptions', payload)
    toast.add({ severity: 'success', summary: pt('Prescription saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the prescription'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this prescription?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/eye-prescriptions/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Prescription deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => {
  await loadCustomers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Eye Prescriptions')"
      :subtitle="pt('The powers a pair of spectacles gets ground to — recorded per eye')"
    />

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Rx no, patient or phone')" @keyup.enter="load" />
      <label class="inline-check">
        <Checkbox v-model="validOnly" binary @change="load" /> {{ pt('Not expired') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Prescription')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Eye Prescriptions"
        filenameBase="eye-prescriptions"
        :rows="rows"
        :columns="[
          { key: 'prescription_number', label: 'Rx No' },
          { key: 'patient_name', label: 'Patient' },
          { key: 'vision_type', label: 'Vision' },
          { key: 'right_formatted', label: 'Right' },
          { key: 'left_formatted', label: 'Left' },
          { key: 'pd', label: 'PD' },
          { key: 'prescribed_on', label: 'Tested' },
          { key: 'valid_until', label: 'Valid Until' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Rx')">
        <template #body="{ data }">
          <strong>{{ data.prescription_number }}</strong>
          <br /><small class="muted">{{ data.prescribed_on }}</small>
        </template>
      </Column>
      <Column :header="pt('Patient')">
        <template #body="{ data }">
          {{ data.patient_name }}
          <br />
          <small class="muted">
            {{ [data.patient_age, data.patient_gender].filter(Boolean).join(' · ') || '—' }}
          </small>
        </template>
      </Column>
      <Column :header="pt('Vision')">
        <template #body="{ data }"><Tag :value="data.vision_type" severity="info" /></template>
      </Column>
      <!-- One readable line per eye, the way an optician writes it. -->
      <Column :header="pt('Right (OD)')">
        <template #body="{ data }"><code class="power">{{ data.right_formatted }}</code></template>
      </Column>
      <Column :header="pt('Left (OS)')">
        <template #body="{ data }"><code class="power">{{ data.left_formatted }}</code></template>
      </Column>
      <Column :header="pt('PD')">
        <template #body="{ data }">{{ data.pd ?? '—' }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.is_expired ? pt('Expired') : pt('Valid')"
            :severity="data.is_expired ? 'danger' : 'success'"
          />
          <br v-if="data.orders_count" />
          <small v-if="data.orders_count" class="muted">
            {{ pt('{n} order(s)', { n: data.orders_count }) }}
          </small>
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit && !data.orders_count" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button
            v-if="canDelete && !data.orders_count"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No eye tests recorded yet.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Prescription') : pt('New Prescription')"
      :style="{ width: '48rem' }"
    >
      <h4 class="sec">{{ pt('Patient') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Patient name') }} *</label>
          <InputText v-model="form.patient_name" class="w-full" />
          <small v-if="errors.patient_name" class="err">{{ errors.patient_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Age') }}</label>
          <InputNumber v-model="form.patient_age" :min="0" :max="130" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Gender') }}</label>
          <Select v-model="form.patient_gender" :options="GENDERS" optionLabel="label" optionValue="value" showClear class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.patient_phone" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Customer account') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Walk-in')"
            class="w-full"
          />
        </div>
      </div>

      <h4 class="sec">{{ pt('Powers') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Vision type') }} *</label>
          <Select v-model="form.vision_type" :options="VISION_TYPES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('PD (mm)') }}</label>
          <InputNumber v-model="form.pd" :min="40" :max="85" :maxFractionDigits="1" class="w-full" />
        </div>
      </div>

      <table class="powers">
        <thead>
          <tr>
            <th></th>
            <th>{{ pt('SPH') }}</th>
            <th>{{ pt('CYL') }}</th>
            <th>{{ pt('AXIS') }}</th>
            <th v-if="hasAdd">{{ pt('ADD') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th>{{ pt('Right (OD)') }}</th>
            <td><InputNumber v-model="form.right_sph" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.right_cyl" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.right_axis" :min="0" :max="180" class="p-in axis" /></td>
            <td v-if="hasAdd"><InputNumber v-model="form.right_add" :step="0.25" :min="0" :max="4" :minFractionDigits="2" showButtons class="p-in" /></td>
          </tr>
          <tr>
            <th>{{ pt('Left (OS)') }}</th>
            <td><InputNumber v-model="form.left_sph" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.left_cyl" :step="0.25" :minFractionDigits="2" showButtons class="p-in" /></td>
            <td><InputNumber v-model="form.left_axis" :min="0" :max="180" class="p-in axis" /></td>
            <td v-if="hasAdd"><InputNumber v-model="form.left_add" :step="0.25" :min="0" :max="4" :minFractionDigits="2" showButtons class="p-in" /></td>
          </tr>
        </tbody>
      </table>
      <p class="hint">{{ pt('Powers step in 0.25 dioptres. A cylinder needs an axis between 0 and 180.') }}</p>

      <!-- The server is the authority on what is dispensable; its findings land here. -->
      <div v-if="errors.powers" class="power-errors">
        <div v-for="(msg, i) in errors.powers" :key="i"><i class="pi pi-times-circle" /> {{ msg }}</div>
      </div>

      <h4 class="sec">{{ pt('Test details') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Optometrist') }}</label>
          <InputText v-model="form.optometrist_name" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Registration no') }}</label>
          <InputText v-model="form.optometrist_registration_no" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Tested on') }} *</label>
          <DatePicker v-model="form.prescribed_on" dateFormat="yy-mm-dd" class="w-full" />
          <small v-if="errors.prescribed_on" class="err">{{ errors.prescribed_on[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Valid until') }}</label>
          <DatePicker v-model="form.valid_until" dateFormat="yy-mm-dd" showButtonBar class="w-full" />
          <small class="hint">{{ pt('Most shops re-test after a year or two.') }}</small>
        </div>
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
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.power { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; }
.sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.sec:first-child { margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; margin: 0.4rem 0 0; }
.err { color: #b91c1c; font-size: 0.75rem; }
/* The power grid reads like an optician's card: eyes down, values across. */
.powers { width: 100%; border-collapse: collapse; margin-top: 0.6rem; }
.powers th { font-size: 0.75rem; color: #6b7280; font-weight: 600; text-align: left; padding: 0.25rem 0.4rem; }
.powers tbody th { width: 6.5rem; color: #374151; }
.powers td { padding: 0.2rem 0.4rem; }
.p-in { width: 100%; }
.p-in.axis { max-width: 6rem; }
.power-errors {
  margin-top: 0.6rem; border: 1px solid #f0b4b4; background: #fdf1f1;
  color: #8a2b2b; border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.78rem;
}
.power-errors div { display: flex; align-items: center; gap: 0.35rem; padding: 0.1rem 0; }
</style>
