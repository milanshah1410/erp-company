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
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/prescriptions')

const canCreate = computed(() => auth.can('prescriptions.create'))
const canEdit = computed(() => auth.can('prescriptions.edit'))
const canDelete = computed(() => auth.can('prescriptions.delete'))

const customers = ref<any[]>([])
const search = ref('')
const dispensableOnly = ref(false)

const GENDERS = [
  { label: 'Male', value: 'male' },
  { label: 'Female', value: 'female' },
  { label: 'Other', value: 'other' },
]

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function load() {
  return crud.fetch({
    search: search.value || undefined,
    dispensable_only: dispensableOnly.value ? 1 : undefined,
  })
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 200, is_active: true } })
  customers.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

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
  doctor_name: '',
  doctor_registration_no: '',
  hospital: '',
  prescribed_on: new Date() as Date | null,
  valid_until: null as Date | null,
  notes: '',
})
const form = ref(blank())

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
    customer_id: row.customer_id,
    patient_name: row.patient_name,
    patient_age: row.patient_age,
    patient_gender: row.patient_gender,
    patient_phone: row.patient_phone ?? '',
    doctor_name: row.doctor_name,
    doctor_registration_no: row.doctor_registration_no ?? '',
    hospital: row.hospital ?? '',
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
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
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
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Prescription deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

// ---- Dispensing history -------------------------------------------------------

const historyVisible = ref(false)
const history = ref<any>(null)

async function openHistory(row: any) {
  historyVisible.value = true
  history.value = null
  const { data } = await http.get(`/prescriptions/${row.id}`)
  history.value = data.data
}

onMounted(async () => {
  await loadCustomers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Prescriptions')"
      :subtitle="pt('The authority a counter needs before dispensing Schedule H, H1 or X medicine')"
    />

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Rx no, patient, phone or doctor')" @keyup.enter="load" />
      <label class="inline-check">
        <input v-model="dispensableOnly" type="checkbox" @change="load" />
        {{ pt('Usable today only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Prescription')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Prescriptions"
        filenameBase="prescriptions"
        :rows="crud.items.value"
        :columns="[
          { key: 'prescription_number', label: 'Rx No' },
          { key: 'patient_name', label: 'Patient' },
          { key: 'doctor_name', label: 'Doctor' },
          { key: 'doctor_registration_no', label: 'Reg No' },
          { key: 'prescribed_on', label: 'Prescribed' },
          { key: 'valid_until', label: 'Valid Until' },
          { key: 'dispenses_count', label: 'Dispensed' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
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
      <Column :header="pt('Prescriber')">
        <template #body="{ data }">
          {{ data.doctor_name }}
          <br /><small class="muted">{{ data.doctor_registration_no || pt('no reg. no') }}</small>
        </template>
      </Column>
      <Column :header="pt('Type')">
        <template #body="{ data }">
          <Tag
            :value="data.is_repeat ? pt('Repeat') : pt('Single use')"
            :severity="data.is_repeat ? 'info' : 'secondary'"
          />
          <br v-if="data.valid_until" />
          <small v-if="data.valid_until" class="muted">{{ pt('till') }} {{ data.valid_until }}</small>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.is_dispensable ? pt('Usable') : pt('Used / expired')"
            :severity="data.is_dispensable ? 'success' : 'danger'"
          />
        </template>
      </Column>
      <Column :header="pt('Dispensed')">
        <template #body="{ data }">
          <Button
            v-if="data.dispenses_count"
            :label="String(data.dispenses_count)"
            icon="pi pi-book"
            text
            size="small"
            @click="openHistory(data)"
          />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 7rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && !data.dispenses_count"
            icon="pi pi-pencil"
            text
            size="small"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && !data.dispenses_count"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No prescriptions match this view.') }}</template>
    </DataTable>

    <!-- Dispensing history -->
    <Dialog
      v-model:visible="historyVisible"
      modal
      :header="history ? `${history.prescription_number} — ${history.patient_name}` : pt('Dispensing history')"
      :style="{ width: '40rem' }"
    >
      <DataTable v-if="history" :value="history.dispenses" dataKey="id" class="p-datatable-sm">
        <Column field="dispensed_on" :header="pt('Date')" />
        <Column field="product_name" :header="pt('Medicine')" />
        <Column :header="pt('Schedule')">
          <template #body="{ data }"><Tag :value="data.drug_schedule.toUpperCase()" severity="warn" /></template>
        </Column>
        <Column field="quantity" :header="pt('Qty')" />
        <Column field="invoice" :header="pt('Invoice')" />
        <template #empty>{{ pt('Nothing dispensed against this prescription yet.') }}</template>
      </DataTable>
    </Dialog>

    <!-- Create / edit -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Prescription') : pt('New Prescription')"
      :style="{ width: '44rem' }"
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
            :placeholder="pt('Walk-in patient')"
            class="w-full"
          />
        </div>
      </div>

      <h4 class="sec">{{ pt('Prescriber') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Doctor name') }} *</label>
          <InputText v-model="form.doctor_name" class="w-full" />
          <small v-if="errors.doctor_name" class="err">{{ errors.doctor_name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Registration no') }}</label>
          <InputText v-model="form.doctor_registration_no" class="w-full" />
          <small class="hint">{{ pt('What makes the register entry auditable.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Hospital / clinic') }}</label>
          <InputText v-model="form.hospital" class="w-full" />
        </div>
      </div>

      <h4 class="sec">{{ pt('Validity') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Prescribed on') }} *</label>
          <DatePicker v-model="form.prescribed_on" dateFormat="yy-mm-dd" class="w-full" />
          <small v-if="errors.prescribed_on" class="err">{{ errors.prescribed_on[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Valid until') }}</label>
          <DatePicker v-model="form.valid_until" dateFormat="yy-mm-dd" showButtonBar class="w-full" />
          <small class="hint">{{ pt('Leave blank for a single-use slip.') }}</small>
          <small v-if="errors.valid_until" class="err">{{ errors.valid_until[0] }}</small>
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
.sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.sec:first-child { margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
</style>
