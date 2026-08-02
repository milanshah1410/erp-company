<script setup lang="ts">
/**
 * The IT warranty register.
 *
 * Built around the one question it exists to answer, in front of a customer who
 * is certain the answer is yes: is this still covered — and does the cover pay
 * for someone to come out? So the check panel sits at the top rather than behind
 * a menu, it accepts a bare serial number (which is all the customer on the
 * phone has), and it reports *why* something is not covered.
 *
 * Service level is on every row for the same reason. "In warranty" is only half
 * an answer: carry-in means the customer drives over, on-site means the shop
 * puts an engineer in a rickshaw, and only one of those was paid for.
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

const canCreate = computed(() => auth.can('it-warranties.create'))
const canEdit = computed(() => auth.can('it-warranties.edit'))
const canDelete = computed(() => auth.can('it-warranties.delete'))

const TYPES = [
  { label: 'Machine', value: 'product' },
  { label: 'Part', value: 'part' },
  { label: 'Repair (our work)', value: 'service' },
  { label: 'Extended plan', value: 'extended' },
]

const PROVIDERS = [
  { label: 'Manufacturer', value: 'manufacturer' },
  { label: 'Dealer / us', value: 'dealer' },
  { label: 'Third party', value: 'third_party' },
]

const LEVELS = [
  { label: 'Carry in', value: 'carry_in' },
  { label: 'On site', value: 'onsite' },
  { label: 'On site — next business day', value: 'nbd_onsite' },
]

const rows = ref<any[]>([])
const summary = ref({ registered: 0, active: 0, expiring_30d: 0, onsite: 0, claims: 0 })
const loading = ref(false)
const assets = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const typeFilter = ref<string | null>(null)
const expiringOnly = ref(false)

function levelLabel(v: string) {
  return LEVELS.find((l) => l.value === v)?.label ?? v
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/computer-it/warranties', {
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
  const [a, p] = await Promise.all([
    http.get('/computer-it/assets', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  assets.value = (a.data.data ?? a.data).map((x: any) => ({
    label: `${x.asset_tag} — ${x.label}`,
    value: x.id,
    serial_no: x.serial_no,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
}

// ---- "Is this covered?" ---------------------------------------------------

const checkAsset = ref<number | null>(null)
const checkSerial = ref('')
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkAsset.value && !checkSerial.value) return
  checking.value = true
  try {
    const { data } = await http.get('/computer-it/warranties/check', {
      params: {
        asset_id: checkAsset.value ?? undefined,
        serial_no: checkSerial.value || undefined,
      },
    })
    checkResult.value = data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    checking.value = false
  }
}

function onCheckAssetPicked() {
  checkResult.value = null
  const a = assets.value.find((x) => x.value === checkAsset.value)
  checkSerial.value = a?.serial_no ?? ''
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  type: 'product',
  provider: 'manufacturer',
  service_level: 'carry_in',
  asset_id: null as number | null,
  product_id: null as number | null,
  serial_no: '',
  start_date: new Date() as Date | null,
  end_date: null as Date | null,
  coverage_months: null as number | null,
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
 * Filling the months figure moves the end date with it — that is how cover is
 * quoted ("3 years on site"), and making someone count forward three years on a
 * calendar is how end dates end up a month out.
 */
function onMonthsChanged() {
  if (!form.value.coverage_months || !form.value.start_date) return
  const end = new Date(form.value.start_date)
  end.setMonth(end.getMonth() + Number(form.value.coverage_months))
  form.value.end_date = end
}

function onAssetPicked() {
  const a = assets.value.find((x) => x.value === form.value.asset_id)
  if (a && !form.value.serial_no) form.value.serial_no = a.serial_no ?? ''
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/computer-it/warranties/${row.id}`)
  const w = data.data
  editingId.value = w.id
  form.value = {
    type: w.type,
    provider: w.provider,
    service_level: w.service_level,
    asset_id: w.asset_id,
    product_id: w.product_id,
    serial_no: w.serial_no ?? '',
    start_date: w.start_date ? new Date(w.start_date) : null,
    end_date: w.end_date ? new Date(w.end_date) : null,
    coverage_months: w.coverage_months,
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
      await http.put(`/computer-it/warranties/${editingId.value}`, payload)
    } else {
      await http.post('/computer-it/warranties', payload)
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
    await http.post(`/computer-it/warranties/${row.id}/void`, { reason: res.value || undefined })
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
    await http.delete(`/computer-it/warranties/${row.id}`)
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
      :title="pt('IT Warranties')"
      :subtitle="pt('What is still covered — and whether the cover pays for an engineer to travel')"
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
        <span class="t-label">{{ pt('On-site cover') }}</span>
        <span class="t-value">{{ summary.onsite }}</span>
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
      <div class="check-title">{{ pt('Is this machine covered?') }}</div>
      <div class="check-row">
        <Select
          v-model="checkAsset"
          :options="assets"
          option-label="label"
          option-value="value"
          filter
          show-clear
          class="check-asset"
          :placeholder="pt('Asset tag or model')"
          @change="onCheckAssetPicked"
        />
        <InputText
          v-model="checkSerial"
          :placeholder="pt('…or just the serial number')"
          class="check-serial mono"
          @keyup.enter="runCheck"
        />
        <Button
          :label="pt('Check')"
          icon="pi pi-verified"
          :loading="checking"
          :disabled="!checkAsset && !checkSerial"
          @click="runCheck"
        />
      </div>

      <div v-if="checkResult" class="check-result">
        <Message :severity="checkResult.is_covered ? 'success' : 'warn'" :closable="false">
          {{
            checkResult.is_covered
              ? checkResult.covers_onsite
                ? pt('Covered, including a site visit — this repair should not be billed.')
                : pt('Covered, carry-in only — the machine has to come to us.')
              : pt('Nothing covers this machine today.')
          }}
        </Message>
        <div v-if="checkResult.amc" class="check-line">
          <Tag :value="checkResult.amc.contract_number" severity="info" />
          <span>{{ pt('On AMC until') }} {{ checkResult.amc.end_date }}</span>
          <span v-if="checkResult.amc.visits_remaining !== null" class="muted">
            · {{ checkResult.amc.visits_remaining }} {{ pt('visits left') }}
          </span>
        </div>
        <div v-for="w in checkResult.data" :key="w.id" class="check-line">
          <Tag :value="w.warranty_number" :severity="w.is_live ? 'success' : 'secondary'" />
          <span class="muted">{{ pt(TYPES.find((t) => t.value === w.type)?.label ?? w.type) }}</span>
          <span v-if="w.is_live">
            {{ pt('until') }} {{ w.end_date }} · {{ pt(levelLabel(w.service_level)) }}
            <template v-if="w.days_remaining"> · {{ w.days_remaining }} {{ pt('days left') }}</template>
          </span>
          <span v-else class="muted">{{ w.reason }}</span>
        </div>
        <div v-if="!checkResult.data.length && !checkResult.amc" class="muted">
          {{ pt('No cover has ever been registered against this machine.') }}
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Warranty number, serial, machine or customer')"
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
        title="IT Warranties"
        filenameBase="it-warranties"
        :rows="rows"
        :columns="[
          { key: 'warranty_number', label: 'Warranty' },
          { key: 'type', label: 'Type' },
          { key: 'asset', label: 'Machine' },
          { key: 'customer', label: 'Customer' },
          { key: 'service_level', label: 'Service level' },
          { key: 'start_date', label: 'From' },
          { key: 'end_date', label: 'To' },
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
            {{ pt(TYPES.find((t) => t.value === data.type)?.label ?? data.type) }} ·
            {{ pt(PROVIDERS.find((p) => p.value === data.provider)?.label ?? data.provider) }}
          </small>
        </template>
      </Column>

      <Column field="asset" :header="pt('Covers')">
        <template #body="{ data }">
          <div>{{ data.asset || data.part || '—' }}</div>
          <small v-if="data.serial_no" class="muted mono">{{ data.serial_no }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">{{ data.customer || '—' }}</template>
      </Column>

      <Column field="service_level" :header="pt('Service level')" sortable>
        <template #body="{ data }">
          <Tag
            :value="pt(levelLabel(data.service_level))"
            :severity="data.covers_onsite ? 'info' : 'secondary'"
          />
        </template>
      </Column>

      <Column field="end_date" :header="pt('Until')" sortable>
        <template #body="{ data }">
          <div>{{ data.end_date }}</div>
          <small class="muted">{{ pt('from') }} {{ data.start_date }}</small>
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
          {{ pt('Nothing registered yet. A job card only comes up as free work if the cover behind it is on this list.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Warranty') : pt('Register Warranty')"
      modal
      :style="{ width: '780px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Covers') }} *</label>
          <Select v-model="form.type" :options="TYPES" option-label="label" option-value="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Given by') }}</label>
          <Select v-model="form.provider" :options="PROVIDERS" option-label="label" option-value="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Service level') }}</label>
          <Select v-model="form.service_level" :options="LEVELS" option-label="label" option-value="value" class="w-full" />
          <small class="hint">{{ pt('Decides whether an engineer may be sent.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Machine') }}{{ form.type !== 'part' ? ' *' : '' }}</label>
          <Select
            v-model="form.asset_id"
            :options="assets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onAssetPicked"
          />
          <small v-if="errors.asset_id" class="err">{{ errors.asset_id[0] }}</small>
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
          </div>
        </template>
        <div class="fld">
          <label>{{ pt('Serial') }}</label>
          <InputText v-model="form.serial_no" class="w-full mono" />
          <small class="hint">{{ pt('What a claim will be matched on.') }}</small>
        </div>

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
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }

.check-panel { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1rem; }
.check-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.6rem; }
.check-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.check-asset { min-width: 280px; }
.check-serial { width: 220px; }
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
