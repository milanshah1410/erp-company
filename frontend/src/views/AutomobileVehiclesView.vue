<script setup lang="ts">
/**
 * The vehicle register — every vehicle this workshop has ever touched.
 *
 * Written as a work list rather than a filing cabinet. What a service manager
 * opens it for is "who should I be ringing today", so service-due and
 * expiring-papers are one-click filters at the top rather than something to be
 * assembled from a search; the register that can only be searched is a slower
 * way of asking the customer.
 *
 * Opening a vehicle shows its service history, because that is the screen an
 * advisor reads out at the counter to justify the estimate they are about to
 * give.
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
import Checkbox from 'primevue/checkbox'
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

const canCreate = computed(() => auth.can('automobile-vehicles.create'))
const canEdit = computed(() => auth.can('automobile-vehicles.edit'))
const canDelete = computed(() => auth.can('automobile-vehicles.delete'))

const FUEL_TYPES = [
  { label: 'Petrol', value: 'petrol' },
  { label: 'Diesel', value: 'diesel' },
  { label: 'CNG', value: 'cng' },
  { label: 'LPG', value: 'lpg' },
  { label: 'Electric', value: 'electric' },
  { label: 'Hybrid', value: 'hybrid' },
]

const TRANSMISSIONS = [
  { label: 'Manual', value: 'manual' },
  { label: 'Automatic', value: 'automatic' },
  { label: 'AMT', value: 'amt' },
  { label: 'CVT', value: 'cvt' },
]

const rows = ref<any[]>([])
const summary = ref({ vehicles: 0, active: 0, service_due: 0, documents_expiring: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const search = ref('')
/** null = the whole register; the other two are the work lists. */
const filter = ref<null | 'service_due' | 'documents_expiring'>(null)

function money(v: any) {
  return formatCurrency(v)
}

function km(v: any) {
  return v === null || v === undefined ? '—' : `${Number(v).toLocaleString()} km`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/automobile/vehicles', {
      params: {
        search: search.value || undefined,
        service_due: filter.value === 'service_due' ? 1 : undefined,
        documents_expiring: filter.value === 'documents_expiring' ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

function setFilter(next: null | 'service_due' | 'documents_expiring') {
  filter.value = filter.value === next ? null : next
  load()
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 500 } })
  customers.value = (data.data ?? data).map((c: any) => ({
    label: c.phone ? `${c.name} (${c.phone})` : c.name,
    value: c.id,
  }))
}

/** Papers running out, as a readable list for the table cell. */
function documentLabels(docs: Record<string, string> | undefined) {
  return Object.keys(docs ?? {}).map((k) => pt(k === 'puc' ? 'PUC' : k === 'fitness' ? 'Fitness' : 'Insurance'))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  registration_no: '',
  vin: '',
  engine_no: '',
  make: '',
  model: '',
  variant: '',
  manufacture_year: null as number | null,
  fuel_type: null as string | null,
  transmission: null as string | null,
  colour: '',
  odometer: 0,
  registration_date: null as Date | null,
  insurance_provider: '',
  insurance_policy_no: '',
  insurance_expiry: null as Date | null,
  puc_expiry: null as Date | null,
  fitness_expiry: null as Date | null,
  service_interval_days: null as number | null,
  service_interval_km: null as number | null,
  notes: '',
  is_active: true,
})

const form = ref(blank())

function toDate(v: string | null) {
  return v ? new Date(v) : null
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/automobile/vehicles/${row.id}`)
  const v = data.data
  editingId.value = v.id
  form.value = {
    ...blank(),
    customer_id: v.customer_id,
    registration_no: v.registration_no ?? '',
    vin: v.vin ?? '',
    engine_no: v.engine_no ?? '',
    make: v.make ?? '',
    model: v.model ?? '',
    variant: v.variant ?? '',
    manufacture_year: v.manufacture_year,
    fuel_type: v.fuel_type,
    transmission: v.transmission,
    colour: v.colour ?? '',
    odometer: v.odometer,
    registration_date: toDate(v.registration_date),
    insurance_provider: v.insurance_provider ?? '',
    insurance_policy_no: v.insurance_policy_no ?? '',
    insurance_expiry: toDate(v.insurance_expiry),
    puc_expiry: toDate(v.puc_expiry),
    fitness_expiry: toDate(v.fitness_expiry),
    service_interval_days: v.service_interval_days,
    service_interval_km: v.service_interval_km,
    notes: v.notes ?? '',
    is_active: v.is_active,
  }
  dialogVisible.value = true
}

function asDate(d: Date | null) {
  if (!d) return null
  // Date-only fields, sent as a plain local date: toISOString() would shift a
  // 1 January expiry into 31 December for anyone east of UTC.
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      registration_date: asDate(form.value.registration_date),
      insurance_expiry: asDate(form.value.insurance_expiry),
      puc_expiry: asDate(form.value.puc_expiry),
      fitness_expiry: asDate(form.value.fitness_expiry),
    }
    if (editingId.value) {
      await http.put(`/automobile/vehicles/${editingId.value}`, payload)
    } else {
      await http.post('/automobile/vehicles', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Vehicle saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the vehicle'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove this vehicle?'),
    text: pt('Only vehicles with no job cards can be removed. Otherwise mark it inactive.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/automobile/vehicles/${row.id}`)
    await load()
    toast.add({ severity: 'success', summary: pt('Vehicle removed'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// ---- Service history ------------------------------------------------------

const historyVisible = ref(false)
const historyLoading = ref(false)
const historyVehicle = ref<any>(null)
const historyRows = ref<any[]>([])
const historySummary = ref({ visits: 0, lifetime_spend: 0, last_visit_at: null as string | null })
const historyWarranties = ref<any[]>([])

async function openHistory(row: any) {
  historyVehicle.value = row
  historyVisible.value = true
  historyLoading.value = true
  try {
    const [history, detail] = await Promise.all([
      http.get(`/automobile/vehicles/${row.id}/history`),
      http.get(`/automobile/vehicles/${row.id}`),
    ])
    historyRows.value = history.data.data
    historySummary.value = history.data.summary
    historyWarranties.value = detail.data.data.warranties ?? []
  } finally {
    historyLoading.value = false
  }
}

function dateTime(v: string | null) {
  return v ? new Date(v).toLocaleString() : '—'
}

onMounted(() => {
  load()
  loadCustomers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Vehicles')"
      :subtitle="pt('Every vehicle this workshop has seen — VIN, papers and service history')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Vehicle')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Vehicles') }}</span>
        <span class="t-value">{{ summary.vehicles }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <button
        class="tile clickable"
        :class="{ on: filter === 'service_due' }"
        @click="setFilter('service_due')"
      >
        <span class="t-label">{{ pt('Service due') }}</span>
        <span class="t-value warn">{{ summary.service_due }}</span>
      </button>
      <button
        class="tile clickable"
        :class="{ on: filter === 'documents_expiring' }"
        @click="setFilter('documents_expiring')"
      >
        <span class="t-label">{{ pt('Papers expiring') }}</span>
        <span class="t-value warn">{{ summary.documents_expiring }}</span>
      </button>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Registration, chassis, make or owner')"
        @keyup.enter="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Vehicles"
        filenameBase="vehicles"
        :rows="rows"
        :columns="[
          { key: 'registration_no', label: 'Registration' },
          { key: 'vin', label: 'VIN' },
          { key: 'make', label: 'Make' },
          { key: 'model', label: 'Model' },
          { key: 'customer', label: 'Owner' },
          { key: 'odometer', label: 'Odometer' },
          { key: 'next_service_due_at', label: 'Service due' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="id"
      paginator
      :rows="25"
      striped-rows
      responsive-layout="scroll"
    >
      <Column field="registration_no" :header="pt('Vehicle')" sortable>
        <template #body="{ data }">
          <div>
            <div class="strong">{{ data.registration_no || pt('Unregistered') }}</div>
            <small class="muted">{{ [data.make, data.model, data.variant].filter(Boolean).join(' ') || '—' }}</small>
          </div>
        </template>
      </Column>

      <Column field="vin" :header="pt('Chassis / VIN')">
        <template #body="{ data }">
          <span class="mono">{{ data.vin || '—' }}</span>
        </template>
      </Column>

      <Column field="customer" :header="pt('Owner')" sortable>
        <template #body="{ data }">
          <div v-if="data.customer">
            <div>{{ data.customer }}</div>
            <small class="muted">{{ data.customer_phone }}</small>
          </div>
          <span v-else class="muted" :title="pt('Stock vehicle — not yet handed over')">—</span>
        </template>
      </Column>

      <Column field="odometer" :header="pt('Odometer')" sortable>
        <template #body="{ data }">{{ km(data.odometer) }}</template>
      </Column>

      <Column field="next_service_due_at" :header="pt('Next service')" sortable>
        <template #body="{ data }">
          <Tag
            v-if="data.is_service_due"
            :value="pt('Due')"
            severity="warn"
            :title="data.next_service_due_at || km(data.next_service_odometer)"
          />
          <span v-else-if="data.next_service_due_at">{{ data.next_service_due_at }}</span>
          <span v-else class="muted" :title="pt('No service interval set on this vehicle')">—</span>
        </template>
      </Column>

      <Column :header="pt('Papers')">
        <template #body="{ data }">
          <div v-if="documentLabels(data.expiring_documents).length" class="chips">
            <Tag
              v-for="doc in documentLabels(data.expiring_documents)"
              :key="doc"
              :value="doc"
              severity="danger"
            />
          </div>
          <span v-else class="muted">{{ pt('OK') }}</span>
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            icon="pi pi-history"
            text
            rounded
            :title="pt('Service history')"
            @click="openHistory(data)"
          />
          <Button
            v-if="canEdit"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Remove')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No vehicles yet. A job card is opened against a vehicle, so start here.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Vehicle') : pt('New Vehicle')"
      modal
      :style="{ width: '900px' }"
    >
      <h4 class="section-title">{{ pt('Identity') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Registration number') }}</label>
          <InputText v-model="form.registration_no" class="w-full" placeholder="GJ01AB1234" />
          <small v-if="errors.registration_no" class="err">{{ errors.registration_no[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Chassis / VIN') }}</label>
          <InputText v-model="form.vin" class="w-full" maxlength="17" />
          <small class="hint">{{ pt('17 characters. I, O and Q are never used.') }}</small>
          <small v-if="errors.vin" class="err">{{ errors.vin[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Engine number') }}</label>
          <InputText v-model="form.engine_no" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Owner') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            :placeholder="pt('Leave empty for a stock vehicle')"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Registered on') }}</label>
          <DatePicker v-model="form.registration_date" date-format="dd/mm/yy" class="w-full" />
        </div>
      </div>

      <h4 class="section-title">{{ pt('Specification') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Make') }}</label>
          <InputText v-model="form.make" class="w-full" placeholder="Maruti Suzuki" />
        </div>
        <div class="fld">
          <label>{{ pt('Model') }}</label>
          <InputText v-model="form.model" class="w-full" placeholder="Swift" />
        </div>
        <div class="fld">
          <label>{{ pt('Variant') }}</label>
          <InputText v-model="form.variant" class="w-full" placeholder="VDi" />
        </div>
        <div class="fld">
          <label>{{ pt('Year') }}</label>
          <InputNumber v-model="form.manufacture_year" :use-grouping="false" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Fuel') }}</label>
          <Select
            v-model="form.fuel_type"
            :options="FUEL_TYPES"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Transmission') }}</label>
          <Select
            v-model="form.transmission"
            :options="TRANSMISSIONS"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Colour') }}</label>
          <InputText v-model="form.colour" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Odometer') }}</label>
          <InputNumber v-model="form.odometer" :min="0" suffix=" km" class="w-full" />
          <small class="hint">{{ pt('Readings only ever move forward.') }}</small>
        </div>
      </div>

      <h4 class="section-title">{{ pt('Papers') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Insurer') }}</label>
          <InputText v-model="form.insurance_provider" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Policy number') }}</label>
          <InputText v-model="form.insurance_policy_no" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Insurance expiry') }}</label>
          <DatePicker v-model="form.insurance_expiry" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('PUC expiry') }}</label>
          <DatePicker v-model="form.puc_expiry" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Fitness expiry') }}</label>
          <DatePicker v-model="form.fitness_expiry" date-format="dd/mm/yy" class="w-full" />
        </div>
      </div>

      <h4 class="section-title">{{ pt('Service schedule') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Every') }}</label>
          <InputNumber v-model="form.service_interval_days" :min="1" suffix=" days" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Or every') }}</label>
          <InputNumber v-model="form.service_interval_km" :min="100" suffix=" km" class="w-full" />
          <small class="hint">{{ pt('Whichever comes first sets the next due date.') }}</small>
        </div>
        <div class="fld">
          <label>&nbsp;</label>
          <label class="inline-check">
            <Checkbox v-model="form.is_active" binary />
            {{ pt('Active') }}
          </label>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Service history -->
    <Dialog
      v-model:visible="historyVisible"
      :header="pt('Service History')"
      modal
      :style="{ width: '1000px' }"
    >
      <div v-if="historyVehicle" class="history-head">
        <div>
          <div class="strong">{{ historyVehicle.label }}</div>
          <small class="muted">{{ km(historyVehicle.odometer) }}</small>
        </div>
        <div class="history-stats">
          <div><span class="muted">{{ pt('Visits') }}</span> <strong>{{ historySummary.visits }}</strong></div>
          <div>
            <span class="muted">{{ pt('Lifetime spend') }}</span>
            <strong>{{ money(historySummary.lifetime_spend) }}</strong>
          </div>
          <div>
            <span class="muted">{{ pt('Last visit') }}</span>
            <strong>{{ dateTime(historySummary.last_visit_at) }}</strong>
          </div>
        </div>
      </div>

      <div v-if="historyWarranties.length" class="warranty-strip">
        <span class="muted">{{ pt('Cover') }}:</span>
        <Tag
          v-for="w in historyWarranties"
          :key="w.id"
          :value="`${w.warranty_number} · ${w.end_date}`"
          :severity="w.is_live ? 'success' : 'secondary'"
          :title="w.km_remaining !== null ? `${w.km_remaining} km left` : ''"
        />
      </div>

      <DataTable
        :value="historyRows"
        :loading="historyLoading"
        data-key="id"
        striped-rows
        responsive-layout="scroll"
      >
        <Column field="job_number" :header="pt('Job card')" />
        <Column field="received_at" :header="pt('In')">
          <template #body="{ data }">{{ dateTime(data.received_at) }}</template>
        </Column>
        <Column field="odometer_in" :header="pt('Odometer')">
          <template #body="{ data }">{{ km(data.odometer_out ?? data.odometer_in) }}</template>
        </Column>
        <Column field="complaint" :header="pt('Work')">
          <template #body="{ data }">
            <div>{{ data.resolution || data.complaint || '—' }}</div>
            <small v-if="data.parts?.length" class="muted">
              {{ data.parts.map((p: any) => `${p.name} × ${p.quantity}`).join(', ') }}
            </small>
          </template>
        </Column>
        <Column field="technician" :header="pt('Technician')">
          <template #body="{ data }">{{ data.technician || '—' }}</template>
        </Column>
        <Column field="total" :header="pt('Total')">
          <template #body="{ data }">
            <span v-if="data.is_warranty" class="muted">{{ pt('Warranty') }}</span>
            <span v-else>{{ money(data.total) }}</span>
          </template>
        </Column>
        <template #empty>
          <div class="empty-state">{{ pt('This vehicle has never been in.') }}</div>
        </template>
      </DataTable>

      <template #footer>
        <Button :label="pt('Close')" text @click="historyVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.75rem 0.9rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  text-align: left;
  background: none;
  font: inherit;
  color: inherit;
}
.tile.clickable { cursor: pointer; }
.tile.on { border-color: var(--p-primary-color, #3b82f6); box-shadow: 0 0 0 1px var(--p-primary-color, #3b82f6); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.section-title { margin: 1.2rem 0 0.5rem; font-size: 0.9rem; }
.chips { display: flex; gap: 0.25rem; flex-wrap: wrap; }

.history-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 0.8rem;
}
.history-stats { display: flex; gap: 1.25rem; font-size: 0.85rem; }
.history-stats div { display: flex; flex-direction: column; }
.warranty-strip {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 0.8rem;
  font-size: 0.8rem;
}
</style>
