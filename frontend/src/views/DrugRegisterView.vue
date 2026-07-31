<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const loading = ref(false)
const summary = ref({ entries: 0, prescriptions: 0, by_schedule: {} as Record<string, number> })
const range = ref({ from: '', to: '' })

// Defaults to the current month — the range a counter is usually asked for.
const from = ref<Date | null>(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const to = ref<Date | null>(new Date())
const schedule = ref<string | null>(null)

const SCHEDULES = [
  { label: 'Schedule H1', value: 'h1' },
  { label: 'Schedule X', value: 'x' },
]

function iso(d: Date | null) {
  if (!d) return undefined
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/drug-register', {
      params: { from: iso(from.value), to: iso(to.value), schedule: schedule.value || undefined, per_page: 500 },
    })
    rows.value = data.data
    summary.value = data.summary
    range.value = data.range
  } finally {
    loading.value = false
  }
}

/** The register is produced on paper during an inspection. */
function printRegister() {
  window.print()
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Drug Register (Schedule H1 / X)')"
      :subtitle="pt('The statutory record of every scheduled medicine dispensed — retain for three years')"
    />

    <div class="notice">
      <i class="pi pi-info-circle" />
      <span>
        {{ pt('Built from what was actually dispensed, not from invoices, so a later product rename or reclassification cannot rewrite it.') }}
      </span>
    </div>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Entries in range') }}</span>
        <strong class="t-value">{{ summary.entries }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Prescriptions') }}</span>
        <strong class="t-value">{{ summary.prescriptions }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">{{ pt('Range') }}</span>
        <strong class="t-value small">{{ range.from }} → {{ range.to }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="from" dateFormat="yy-mm-dd" :placeholder="pt('From')" class="filter-date" />
      <DatePicker v-model="to" dateFormat="yy-mm-dd" :placeholder="pt('To')" class="filter-date" />
      <Select
        v-model="schedule"
        :options="SCHEDULES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('Both schedules')"
        class="filter"
      />
      <Button :label="pt('Show')" icon="pi pi-search" @click="load" />
      <Button :label="pt('Print')" icon="pi pi-print" outlined @click="printRegister" />
      <TableExportButtons
        title="Drug Register (Schedule H1 / X)"
        filenameBase="drug-register"
        :rows="rows"
        :columns="[
          { key: 'dispensed_on', label: 'Date' },
          { key: 'drug_schedule', label: 'Schedule' },
          { key: 'product_name', label: 'Medicine' },
          { key: 'quantity', label: 'Qty' },
          { key: 'patient_name', label: 'Patient' },
          { key: 'patient_age', label: 'Age' },
          { key: 'patient_gender', label: 'Gender' },
          { key: 'doctor_name', label: 'Prescriber' },
          { key: 'doctor_registration_no', label: 'Reg No' },
          { key: 'prescription_number', label: 'Rx No' },
          { key: 'invoice_number', label: 'Invoice' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column field="dispensed_on" :header="pt('Date')" />
      <Column :header="pt('Schedule')">
        <template #body="{ data }"><Tag :value="data.drug_schedule.toUpperCase()" severity="warn" /></template>
      </Column>
      <Column field="product_name" :header="pt('Medicine')" />
      <Column field="quantity" :header="pt('Qty')" />
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
          <br /><small class="muted">{{ data.doctor_registration_no || '—' }}</small>
        </template>
      </Column>
      <Column field="prescription_number" :header="pt('Rx No')" />
      <Column field="invoice_number" :header="pt('Invoice')" />
      <template #empty>{{ pt('No scheduled medicine was dispensed in this range.') }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.notice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid #cfe3f0;
  background: #f2f8fc;
  color: #2c4a5e;
  border-radius: 10px;
  padding: 0.6rem 0.85rem;
  font-size: 0.82rem;
  margin-bottom: 1rem;
}
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.small { font-size: 0.95rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.filter { width: 180px; }
.filter-date { width: 150px; }
.muted { color: #6b7280; }
</style>
