<script setup lang="ts">
/**
 * The warranty register.
 *
 * Built around the one question it exists to answer, in front of a customer who
 * is certain the answer is yes: is this still covered? So the check panel sits
 * at the top rather than behind a menu, and it reports *why* something is not
 * covered — "kilometre limit passed at 45,000 km" is a far better thing to be
 * able to say than "no cover found".
 *
 * Cover runs on two clocks and ends when either stops, so both are shown on
 * every row: the end date, and the kilometres left on the distance limb.
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
import Message from 'primevue/message'
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

const canCreate = computed(() => auth.can('automobile-warranties.create'))
const canEdit = computed(() => auth.can('automobile-warranties.edit'))
const canDelete = computed(() => auth.can('automobile-warranties.delete'))

const TYPES = [
  { label: 'Vehicle', value: 'vehicle' },
  { label: 'Part', value: 'part' },
  { label: 'Repair (our work)', value: 'service' },
]

const PROVIDERS = [
  { label: 'Manufacturer', value: 'manufacturer' },
  { label: 'Dealer / us', value: 'dealer' },
  { label: 'Third party', value: 'third_party' },
]

const rows = ref<any[]>([])
const summary = ref({ registered: 0, active: 0, expiring_30d: 0, claims: 0 })
const loading = ref(false)
const vehicles = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const typeFilter = ref<string | null>(null)
const expiringOnly = ref(false)

function km(v: any) {
  return v === null || v === undefined ? '—' : `${Number(v).toLocaleString()} km`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/automobile/warranties', {
      params: {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        expiring: expiringOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [v, p] = await Promise.all([
    http.get('/automobile/vehicles', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  vehicles.value = (v.data.data ?? v.data).map((x: any) => ({
    label: x.label,
    value: x.id,
    odometer: x.odometer,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
  }))
}

// ---- "Is this covered?" ---------------------------------------------------

const checkVehicle = ref<number | null>(null)
const checkOdometer = ref<number | null>(null)
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkVehicle.value) return
  checking.value = true
  try {
    const { data } = await http.get('/automobile/warranties/check', {
      params: { vehicle_id: checkVehicle.value, odometer: checkOdometer.value ?? undefined },
    })
    checkResult.value = data
  } finally {
    checking.value = false
  }
}

function onCheckVehiclePicked() {
  checkResult.value = null
  const v = vehicles.value.find((x) => x.value === checkVehicle.value)
  checkOdometer.value = v?.odometer ?? null
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  type: 'vehicle',
  provider: 'manufacturer',
  vehicle_id: null as number | null,
  product_id: null as number | null,
  serial_no: '',
  start_date: new Date() as Date | null,
  end_date: null as Date | null,
  coverage_months: null as number | null,
  coverage_km: null as number | null,
  start_odometer: null as number | null,
  terms: '',
  notes: '',
})

const form = ref(blank())

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Filling the months figure moves the end date with it — that is how a
 * manufacturer states cover ("36 months"), and making someone count forward
 * three years on a calendar is how end dates end up a month out.
 */
function onMonthsChanged() {
  if (!form.value.coverage_months || !form.value.start_date) return
  const end = new Date(form.value.start_date)
  end.setMonth(end.getMonth() + Number(form.value.coverage_months))
  form.value.end_date = end
}

function onVehiclePicked() {
  const v = vehicles.value.find((x) => x.value === form.value.vehicle_id)
  if (v && form.value.start_odometer === null) form.value.start_odometer = v.odometer ?? 0
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/automobile/warranties/${row.id}`)
  const w = data.data
  editingId.value = w.id
  form.value = {
    type: w.type,
    provider: w.provider,
    vehicle_id: w.vehicle_id,
    product_id: w.product_id,
    serial_no: w.serial_no ?? '',
    start_date: w.start_date ? new Date(w.start_date) : null,
    end_date: w.end_date ? new Date(w.end_date) : null,
    coverage_months: w.coverage_months,
    coverage_km: w.coverage_km,
    start_odometer: w.start_odometer,
    terms: w.terms ?? '',
    notes: w.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      start_date: asDate(form.value.start_date),
      end_date: asDate(form.value.end_date),
    }
    if (editingId.value) {
      await http.put(`/automobile/warranties/${editingId.value}`, payload)
    } else {
      await http.post('/automobile/warranties', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Warranty registered'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the warranty'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function voidCover(row: any) {
  const res = await Swal.fire({
    title: pt('Void this cover?'),
    text: pt('The customer stops being covered from now on. The record stays for the audit trail.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Void'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/automobile/warranties/${row.id}/void`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this registration?'),
    text: pt('Only covers with no claims against them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/automobile/warranties/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Warranties')"
      :subtitle="pt('What is still covered, by date and by distance — whichever runs out first')"
    >
      <template #actions>
        <Button
          v-if="canCreate"
          :label="pt('Register Warranty')"
          icon="pi pi-plus"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Registered') }}</span>
        <span class="t-value">{{ summary.registered }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('In force') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Expiring in 30 days') }}</span>
        <span class="t-value warn">{{ summary.expiring_30d }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Claims') }}</span>
        <span class="t-value">{{ summary.claims }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('Is this vehicle covered?') }}</div>
      <div class="check-row">
        <Select
          v-model="checkVehicle"
          :options="vehicles"
          option-label="label"
          option-value="value"
          filter
          class="check-vehicle"
          :placeholder="pt('Registration or chassis')"
          @change="onCheckVehiclePicked"
        />
        <InputNumber
          v-model="checkOdometer"
          :min="0"
          suffix=" km"
          :placeholder="pt('Odometer')"
          class="check-odo"
        />
        <Button
          :label="pt('Check')"
          icon="pi pi-verified"
          :loading="checking"
          :disabled="!checkVehicle"
          @click="runCheck"
        />
      </div>

      <div v-if="checkResult" class="check-result">
        <Message :severity="checkResult.is_covered ? 'success' : 'warn'" :closable="false">
          {{
            checkResult.is_covered
              ? pt('Covered — this repair should not be billed to the customer.')
              : pt('No live cover on this vehicle at this reading.')
          }}
        </Message>
        <div v-for="w in checkResult.data" :key="w.id" class="check-line">
          <Tag
            :value="w.warranty_number"
            :severity="w.is_live ? 'success' : 'secondary'"
          />
          <span class="muted">{{ TYPES.find((t) => t.value === w.type)?.label }}</span>
          <span v-if="w.is_live">
            {{ pt('until') }} {{ w.end_date }}
            <template v-if="w.km_remaining !== null"> · {{ km(w.km_remaining) }} {{ pt('left') }}</template>
          </span>
          <span v-else class="muted">{{ w.reason }}</span>
        </div>
        <div v-if="!checkResult.data.length" class="muted">
          {{ pt('No warranty has ever been registered against this vehicle.') }}
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Warranty number, serial, vehicle or owner')"
        @keyup.enter="load"
      />
      <Select
        v-model="typeFilter"
        :options="[{ label: pt('All types'), value: null }, ...TYPES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('Expiring soon')"
        :outlined="!expiringOnly"
        icon="pi pi-clock"
        @click="
          () => {
            expiringOnly = !expiringOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Warranties"
        filenameBase="warranties"
        :rows="rows"
        :columns="[
          { key: 'warranty_number', label: 'Warranty' },
          { key: 'type', label: 'Type' },
          { key: 'vehicle', label: 'Vehicle' },
          { key: 'customer', label: 'Owner' },
          { key: 'start_date', label: 'From' },
          { key: 'end_date', label: 'To' },
          { key: 'coverage_km', label: 'Km limit' },
          { key: 'status', label: 'Status' },
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
      <Column field="warranty_number" :header="pt('Warranty')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.warranty_number }}</div>
          <small class="muted">
            {{ TYPES.find((t) => t.value === data.type)?.label }} ·
            {{ PROVIDERS.find((p) => p.value === data.provider)?.label }}
          </small>
        </template>
      </Column>

      <Column field="vehicle" :header="pt('Covers')">
        <template #body="{ data }">
          <div>{{ data.vehicle || data.part || '—' }}</div>
          <small v-if="data.serial_no" class="muted mono">{{ data.serial_no }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Owner')">
        <template #body="{ data }">{{ data.customer || '—' }}</template>
      </Column>

      <Column field="end_date" :header="pt('Until')" sortable>
        <template #body="{ data }">
          <div>{{ data.end_date }}</div>
          <small class="muted">{{ pt('from') }} {{ data.start_date }}</small>
        </template>
      </Column>

      <Column field="km_remaining" :header="pt('Distance left')">
        <template #body="{ data }">
          <span v-if="data.coverage_km">{{ km(data.km_remaining) }}</span>
          <span v-else class="muted" :title="pt('Time-limited cover only')">—</span>
        </template>
      </Column>

      <Column field="claims_count" :header="pt('Claims')" sortable />

      <Column field="is_live" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="data.is_live ? pt('In force') : pt(data.status === 'void' ? 'Void' : 'Expired')"
            :severity="data.is_live ? 'success' : data.status === 'void' ? 'danger' : 'secondary'"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 10rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && !data.claims_count"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && data.status !== 'void'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Void')"
            @click="voidCover(data)"
          />
          <Button
            v-if="canDelete && !data.claims_count"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{
            pt(
              'Nothing registered yet. A job card only comes up as free work if the cover behind it is on this list.',
            )
          }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Warranty') : pt('Register Warranty')"
      modal
      :style="{ width: '760px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Covers') }} *</label>
          <Select
            v-model="form.type"
            :options="TYPES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Given by') }}</label>
          <Select
            v-model="form.provider"
            :options="PROVIDERS"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Vehicle') }}{{ form.type !== 'part' ? ' *' : '' }}</label>
          <Select
            v-model="form.vehicle_id"
            :options="vehicles"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onVehiclePicked"
          />
          <small v-if="errors.vehicle_id" class="err">{{ errors.vehicle_id[0] }}</small>
        </div>

        <template v-if="form.type === 'part'">
          <div class="fld">
            <label>{{ pt('Part') }}</label>
            <Select
              v-model="form.product_id"
              :options="products"
              option-label="label"
              option-value="value"
              filter
              show-clear
              class="w-full"
            />
            <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
          </div>
          <div class="fld">
            <label>{{ pt('Serial on the part') }}</label>
            <InputText v-model="form.serial_no" class="w-full" />
          </div>
        </template>

        <div class="fld">
          <label>{{ pt('Cover starts') }} *</label>
          <DatePicker v-model="form.start_date" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Months') }}</label>
          <InputNumber
            v-model="form.coverage_months"
            :min="1"
            class="w-full"
            @update:model-value="onMonthsChanged"
          />
          <small class="hint">{{ pt('Sets the end date below.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Cover ends') }} *</label>
          <DatePicker v-model="form.end_date" date-format="dd/mm/yy" class="w-full" />
          <small v-if="errors.end_date" class="err">{{ errors.end_date[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Kilometre limit') }}</label>
          <InputNumber v-model="form.coverage_km" :min="1" suffix=" km" class="w-full" />
          <small class="hint">{{ pt('Cover ends at whichever comes first.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Counting from') }}</label>
          <InputNumber v-model="form.start_odometer" :min="0" suffix=" km" class="w-full" />
          <small v-if="errors.start_odometer" class="err">{{ errors.start_odometer[0] }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Terms') }}</label>
          <Textarea
            v-model="form.terms"
            rows="2"
            auto-resize
            class="w-full"
            :placeholder="pt('What is excluded, and what voids the cover')"
          />
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
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }

.check-panel {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.9rem 1rem;
  margin-bottom: 1rem;
}
.check-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.6rem; }
.check-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.check-vehicle { min-width: 280px; }
.check-odo { width: 160px; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.35rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; flex-wrap: wrap; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
