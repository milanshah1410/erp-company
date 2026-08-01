<script setup lang="ts">
/**
 * Input licences — what this shop is legally allowed to sell.
 *
 * Written as a compliance screen rather than a document list. The coverage strip
 * leads because it answers the question that actually matters: is there a current
 * licence behind each kind of controlled stock on the shelves? A lapsed pesticide
 * licence is only interesting because there are forty litres of pesticide in the
 * back room, and that pairing is what the strip shows.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
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

const canCreate = computed(() => auth.can('agri-licences.create'))
const canEdit = computed(() => auth.can('agri-licences.edit'))
const canDelete = computed(() => auth.can('agri-licences.delete'))

const LICENCE_TYPES = [
  // Named the way the licence itself is: a dealer asks for "the Form O", not
  // for "the fertiliser record".
  { label: 'Seed', value: 'seed' },
  { label: 'Fertiliser', value: 'fertilizer' },
  { label: 'Insecticide / Pesticide', value: 'insecticide' },
  { label: 'Other', value: 'other' },
]

const STATUSES = [
  { label: 'Active', value: 'active' },
  { label: 'Expiring', value: 'expiring' },
  { label: 'Expired', value: 'expired' },
  { label: 'Suspended', value: 'suspended' },
  { label: 'Surrendered', value: 'surrendered' },
]

const rows = ref<any[]>([])
const summary = ref<any>({ total: 0, expiring: 0, expired: 0, warning_days: 60, coverage: [] })
const loading = ref(false)
const search = ref('')
const filterType = ref<string | null>(null)
const filterStatus = ref<string | null>(null)

function typeLabel(value: string) {
  return LICENCE_TYPES.find((t) => t.value === value)?.label ?? value
}

/** Colour by how much time is left, not by the stored status. */
function statusSeverity(status: string) {
  switch (status) {
    case 'active':
      return 'success'
    case 'expiring':
      return 'warn'
    case 'expired':
      return 'danger'
    case 'suspended':
      return 'danger'
    default:
      return 'secondary'
  }
}

function expiryText(row: any) {
  if (row.days_to_expiry === null || row.days_to_expiry === undefined) return pt('No expiry')
  if (row.days_to_expiry < 0) return pt('Expired {n} days ago', { n: Math.abs(row.days_to_expiry) })
  if (row.days_to_expiry === 0) return pt('Expires today')
  return pt('{n} days left', { n: row.days_to_expiry })
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/agri-licences', {
      params: {
        search: search.value || undefined,
        licence_type: filterType.value || undefined,
        status: filterStatus.value || undefined,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  licence_type: 'seed',
  licence_number: '',
  form_number: '',
  authority: '',
  holder_name: '',
  premises: '',
  issued_on: null as Date | null,
  valid_till: null as Date | null,
  status: 'active',
  remarks: '',
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
  form.value = blank()
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  form.value = {
    licence_type: row.licence_type,
    licence_number: row.licence_number,
    form_number: row.form_number ?? '',
    authority: row.authority ?? '',
    holder_name: row.holder_name ?? '',
    premises: row.premises ?? '',
    issued_on: parse(row.issued_on),
    valid_till: parse(row.valid_till),
    status: row.status,
    remarks: row.remarks ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = {
    ...form.value,
    issued_on: iso(form.value.issued_on),
    valid_till: iso(form.value.valid_till),
  }
  try {
    if (editingId.value) {
      await http.put(`/agri-licences/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Licence updated'), life: 2500 })
    } else {
      await http.post('/agri-licences', payload)
      toast.add({ severity: 'success', summary: pt('Licence saved'), life: 2500 })
    }
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the licence'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this licence record?'),
    text: pt('Keep expired licences instead — they are part of the shop&apos;s history at an inspection.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/agri-licences/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Licence deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Input Licences')"
      :subtitle="pt('Seed, fertiliser and pesticide licences — and whether the stock on your shelves is covered')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Add Licence')" icon="pi pi-plus" @click="openNew" />
      </template>
    </PageHeader>

    <!-- Coverage: stock on the shelves against a valid licence behind it. -->
    <div class="cover-row">
      <div
        v-for="c in summary.coverage"
        :key="c.licence_type"
        class="cover-card"
        :class="{ risk: c.at_risk, ok: c.covered }"
      >
        <span class="cc-type">{{ typeLabel(c.licence_type) }}</span>
        <strong class="cc-state">
          <i :class="c.covered ? 'pi pi-check-circle' : 'pi pi-exclamation-triangle'" />
          {{ c.covered ? pt('Licensed') : pt('No valid licence') }}
        </strong>
        <span class="cc-meta">
          {{ pt('{n} products stocked', { n: c.products }) }}
          <template v-if="c.valid_till"> · {{ pt('to') }} {{ c.valid_till }}</template>
        </span>
      </div>
    </div>

    <div v-if="summary.expiring || summary.expired" class="alert">
      <i class="pi pi-bell" />
      <span>
        <template v-if="summary.expired">{{ pt('{n} licence(s) have expired.', { n: summary.expired }) }}</template>
        <template v-if="summary.expiring">
          {{ pt('{n} expire within {d} days.', { n: summary.expiring, d: summary.warning_days }) }}
        </template>
        {{ pt('Renewals take weeks — start before the season, not during it.') }}
      </span>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search number, holder, authority')" class="t-search" @keyup.enter="load" />
      <Select
        v-model="filterType"
        :options="LICENCE_TYPES"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All types')"
        showClear
        class="t-sel"
        @change="load"
      />
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
      <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="load" />
      <TableExportButtons
        title="Input Licences"
        filenameBase="agri-licences"
        :rows="rows"
        :columns="[
          { key: 'licence_type', label: 'Type' },
          { key: 'licence_number', label: 'Licence No' },
          { key: 'form_number', label: 'Form' },
          { key: 'authority', label: 'Authority' },
          { key: 'issued_on', label: 'Issued' },
          { key: 'valid_till', label: 'Valid Till' },
          { key: 'effective_status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
      <Column :header="pt('Type')">
        <template #body="{ data }">
          <strong>{{ typeLabel(data.licence_type) }}</strong>
          <small v-if="data.form_number" class="muted"> · {{ data.form_number }}</small>
        </template>
      </Column>
      <Column field="licence_number" :header="pt('Licence No')" />
      <Column field="authority" :header="pt('Authority')" />
      <Column field="holder_name" :header="pt('Holder')" />
      <Column field="issued_on" :header="pt('Issued')" />
      <Column :header="pt('Validity')">
        <template #body="{ data }">
          <div>{{ data.valid_till ?? '—' }}</div>
          <small class="muted">{{ expiryText(data) }}</small>
        </template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="pt(data.effective_status)" :severity="statusSeverity(data.effective_status)" />
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No licences recorded yet.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Licence') : pt('Add Licence')"
      modal
      :style="{ width: '46rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Licence type') }} *</label>
          <Select v-model="form.licence_type" :options="LICENCE_TYPES" optionLabel="label" optionValue="value" />
          <small v-if="errors.licence_type" class="err">{{ errors.licence_type[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Licence number') }} *</label>
          <InputText v-model="form.licence_number" />
          <small v-if="errors.licence_number" class="err">{{ errors.licence_number[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Form number') }}</label>
          <InputText v-model="form.form_number" :placeholder="pt('Form O / Form V')" />
        </div>
        <div class="fld">
          <label>{{ pt('Issuing authority') }}</label>
          <InputText v-model="form.authority" :placeholder="pt('District Agriculture Officer')" />
        </div>
        <div class="fld">
          <label>{{ pt('Licence holder') }}</label>
          <InputText v-model="form.holder_name" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select
            v-model="form.status"
            :options="[
              { label: pt('Active'), value: 'active' },
              { label: pt('Suspended'), value: 'suspended' },
              { label: pt('Surrendered'), value: 'surrendered' },
            ]"
            optionLabel="label"
            optionValue="value"
          />
          <small class="hint">{{ pt('Expiry is worked out from the validity date — there is no “expired” to set.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Issued on') }}</label>
          <DatePicker v-model="form.issued_on" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld">
          <label>{{ pt('Valid till') }}</label>
          <DatePicker v-model="form.valid_till" dateFormat="yy-mm-dd" showButtonBar />
          <small class="hint">{{ pt('Leave blank only if the licence genuinely has no expiry.') }}</small>
          <small v-if="errors.valid_till" class="err">{{ errors.valid_till[0] }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Licensed premises') }}</label>
          <Textarea v-model="form.premises" rows="2" :placeholder="pt('As printed on the licence — a licence covers a place, not a company')" />
        </div>
        <div class="fld wide">
          <label>{{ pt('Remarks') }}</label>
          <Textarea v-model="form.remarks" rows="2" />
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
.cover-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.cover-card {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 190px;
}
.cover-card.ok { border-color: #86c19a; background: rgba(134, 193, 154, 0.1); }
.cover-card.risk { border-color: #f0b4b4; background: #fdf1f1; }
.cc-type { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.cc-state { font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 0.35rem; }
.cc-meta { font-size: 0.72rem; color: #6b7280; }
.alert {
  display: flex; align-items: flex-start; gap: 0.5rem;
  border: 1px solid #e8c07d; background: rgba(232, 192, 125, 0.14);
  border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.82rem; margin-bottom: 1rem;
}
.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.t-search { width: 240px; }
.t-sel { width: 190px; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
@media (max-width: 640px) {
  .grid { grid-template-columns: 1fr; }
}
</style>
