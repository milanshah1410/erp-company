<script setup lang="ts">
/**
 * The unit register — every machine the shop has sold or is holding, by serial
 * or IMEI.
 *
 * Built around the counter's actual question, which is not "show me the list" but
 * "what is this thing in front of me?". So the lookup panel sits at the top
 * rather than behind a menu, takes a scanned or read-out number, and answers with
 * the machine, what covers it, what that cover pays for, and what has been done
 * to it before — in one call, because at a counter those are one question.
 *
 * The other thing this screen exists for is the fitters' list: sold, needs
 * installing, nobody has been yet. That gap — delivered Tuesday, plumbed in
 * Thursday — is the commonest complaint in the trade, so it is a tile and a
 * one-click filter rather than something to be worked out from dates.
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

const canCreate = computed(() => auth.can('electronics-units.create'))
const canEdit = computed(() => auth.can('electronics-units.edit'))
const canDelete = computed(() => auth.can('electronics-units.delete'))

const STATUSES = [
  { label: 'In stock', value: 'in_stock' },
  { label: 'Sold', value: 'sold' },
  { label: 'Returned', value: 'returned' },
  { label: 'Scrapped', value: 'scrapped' },
]

const STATUS_SEVERITY: Record<string, string> = {
  in_stock: 'info',
  sold: 'success',
  returned: 'warn',
  scrapped: 'danger',
}

const rows = ref<any[]>([])
const summary = ref({ registered: 0, in_stock: 0, sold: 0, awaiting_installation: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const contracts = ref<any[]>([])
const search = ref('')
const statusFilter = ref<string | null>(null)
const awaitingOnly = ref(false)

function statusLabel(v: string) {
  return STATUSES.find((s) => s.value === v)?.label ?? v
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/electronics/units', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        awaiting_installation: awaitingOnly.value ? 1 : undefined,
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
  const [c, p] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: x.phone ? `${x.name} (${x.phone})` : x.name,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    warranty_months: x.warranty_months ?? null,
  }))

  /**
   * AMC contracts are core's, behind the Service module. An electrical goods
   * counter runs this register with no workshop at all, so a 403 here is the
   * expected answer rather than a failure — the picker simply stays empty.
   */
  try {
    const { data } = await http.get('/service-contracts', { params: { per_page: 200 } })
    contracts.value = (data.data ?? data).map((x: any) => ({
      label: `${x.contract_number} — ${x.title ?? ''}`.trim(),
      value: x.id,
    }))
  } catch {
    contracts.value = []
  }
}

// ---- "What is this?" ------------------------------------------------------

const lookupTerm = ref('')
const lookupResult = ref<any>(null)
const lookingUp = ref(false)

async function runLookup() {
  if (!lookupTerm.value.trim()) return
  lookingUp.value = true
  try {
    const { data } = await http.get('/electronics/units/lookup', {
      params: { identifier: lookupTerm.value.trim() },
    })
    lookupResult.value = data
  } finally {
    lookingUp.value = false
  }
}

function clearLookup() {
  lookupTerm.value = ''
  lookupResult.value = null
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  product_id: null as number | null,
  brand: '',
  model_name: '',
  serial_no: '',
  imei_1: '',
  imei_2: '',
  status: 'in_stock',
  is_third_party: false,
  sold_at: null as Date | null,
  delivered_at: null as Date | null,
  installation_required: false,
  installed_at: null as Date | null,
  warranty_months: null as number | null,
  amc_contract_id: null as number | null,
  notes: '',
  is_active: true,
})

const form = ref(blank())

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Picking the article fills the model name and the standard cover from the
 * catalogue. Only when they are still empty — a machine the shop did not sell
 * often has a model the catalogue spells differently, and overwriting what
 * somebody typed off the rating plate would be wrong.
 */
function onProductPicked() {
  const p = products.value.find((x) => x.value === form.value.product_id)
  if (!p) return
  if (!form.value.model_name) form.value.model_name = p.label.replace(/\s*\([^)]*\)\s*$/, '')
  if (form.value.warranty_months === null) form.value.warranty_months = p.warranty_months
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/electronics/units/${row.id}`)
  const u = data.data
  editingId.value = u.id
  form.value = {
    customer_id: u.customer_id,
    product_id: u.product_id,
    brand: u.brand ?? '',
    model_name: u.model_name ?? '',
    serial_no: u.serial_no ?? '',
    imei_1: u.imei_1 ?? '',
    imei_2: u.imei_2 ?? '',
    status: u.status,
    is_third_party: !!u.is_third_party,
    sold_at: u.sold_at ? new Date(u.sold_at) : null,
    delivered_at: u.delivered_at ? new Date(u.delivered_at) : null,
    installation_required: !!u.installation_required,
    installed_at: u.installed_at ? new Date(u.installed_at) : null,
    warranty_months: u.warranty_months,
    amc_contract_id: u.amc_contract_id,
    notes: u.notes ?? '',
    is_active: !!u.is_active,
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      // Empty strings would fail the digits:15 rule on an IMEI the shop simply
      // does not have; null is the honest value for "not stamped".
      serial_no: form.value.serial_no || null,
      imei_1: form.value.imei_1 || null,
      imei_2: form.value.imei_2 || null,
      sold_at: asDate(form.value.sold_at),
      delivered_at: asDate(form.value.delivered_at),
      installed_at: asDate(form.value.installed_at),
    }
    if (editingId.value) {
      await http.put(`/electronics/units/${editingId.value}`, payload)
    } else {
      await http.post('/electronics/units', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Unit saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the unit'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

/** What a fitter does from someone's kitchen floor — one tap, no form. */
async function markInstalled(row: any) {
  try {
    await http.post(`/electronics/units/${row.id}/install`, {})
    await load()
    toast.add({ severity: 'success', summary: pt('Marked installed'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove this unit?'),
    text: pt('Only units with no warranty card and no job card behind them can be removed.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/electronics/units/${row.id}`)
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
      :title="pt('Units & Serials')"
      :subtitle="pt('Every machine sold or held, by serial or IMEI — with its cover, its fitting and its history')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Register Unit')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Registered') }}</span>
        <span class="t-value">{{ summary.registered }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('In stock') }}</span>
        <span class="t-value">{{ summary.in_stock }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Sold') }}</span>
        <span class="t-value">{{ summary.sold }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Awaiting installation') }}</span>
        <span class="t-value warn">{{ summary.awaiting_installation }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('What is this machine?') }}</div>
      <div class="check-row">
        <InputText
          v-model="lookupTerm"
          class="check-term"
          :placeholder="pt('Scan or type the serial / IMEI')"
          @keyup.enter="runLookup"
        />
        <Button
          :label="pt('Look up')"
          icon="pi pi-search"
          :loading="lookingUp"
          :disabled="!lookupTerm.trim()"
          @click="runLookup"
        />
        <Button v-if="lookupResult" :label="pt('Clear')" text @click="clearLookup" />
      </div>

      <div v-if="lookupResult" class="check-result">
        <Message v-if="!lookupResult.found" severity="warn" :closable="false">
          {{ pt('No record of this number. It was not sold here, or it has never been booked in.') }}
        </Message>

        <template v-else>
          <Message :severity="lookupResult.is_covered ? 'success' : 'warn'" :closable="false">
            {{
              lookupResult.is_covered
                ? pt('Covered — check below what the cover actually pays for.')
                : pt('No live cover on this machine. Any work is chargeable.')
            }}
          </Message>

          <div class="found-line">
            <strong>{{ lookupResult.data.label }}</strong>
            <Tag
              :value="pt(statusLabel(lookupResult.data.status))"
              :severity="STATUS_SEVERITY[lookupResult.data.status]"
            />
            <span v-if="lookupResult.data.customer" class="muted">
              {{ lookupResult.data.customer }}
              <template v-if="lookupResult.data.customer_phone">
                · {{ lookupResult.data.customer_phone }}
              </template>
            </span>
          </div>

          <div v-for="w in lookupResult.warranties" :key="w.id" class="check-line">
            <Tag :value="w.warranty_number" :severity="w.is_live ? 'success' : 'secondary'" />
            <span v-if="w.is_live">
              {{ pt('until') }} {{ w.end_date }} · {{ pt(w.coverage) }}
              <template v-if="w.is_onsite"> · {{ pt('onsite') }}</template>
              <template v-else> · {{ pt('carry-in') }}</template>
            </span>
            <span v-else class="muted">{{ w.reason }}</span>
          </div>
          <div v-if="!lookupResult.warranties.length" class="muted">
            {{ pt('No warranty card has ever been issued against this machine.') }}
          </div>

          <div v-if="lookupResult.history.length" class="history">
            <div class="check-title">{{ pt('Previously') }}</div>
            <div v-for="job in lookupResult.history.slice(0, 5)" :key="job.id" class="check-line">
              <Tag :value="job.job_number" severity="secondary" />
              <span class="muted">{{ job.status }}</span>
              <span>{{ job.resolution || job.complaint || '—' }}</span>
              <Tag v-if="job.is_warranty" :value="pt('Warranty')" severity="info" />
            </div>
          </div>
        </template>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Serial, IMEI, model or owner')"
        @keyup.enter="load"
      />
      <Select
        v-model="statusFilter"
        :options="[{ label: pt('All statuses'), value: null }, ...STATUSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('Awaiting installation')"
        :outlined="!awaitingOnly"
        icon="pi pi-wrench"
        @click="
          () => {
            awaitingOnly = !awaitingOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Units & Serials"
        filenameBase="electronics-units"
        :rows="rows"
        :columns="[
          { key: 'identifier', label: 'Identifier' },
          { key: 'brand', label: 'Brand' },
          { key: 'model_name', label: 'Model' },
          { key: 'customer', label: 'Owner' },
          { key: 'status', label: 'Status' },
          { key: 'sold_at', label: 'Sold' },
          { key: 'installed_at', label: 'Installed' },
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
      <Column field="identifier" :header="pt('Identifier')" sortable>
        <template #body="{ data }">
          <div class="strong mono">{{ data.identifier }}</div>
          <small v-if="data.imei_2" class="muted mono">{{ pt('IMEI 2') }}: {{ data.imei_2 }}</small>
        </template>
      </Column>

      <Column field="model_name" :header="pt('Machine')">
        <template #body="{ data }">
          <div>{{ [data.brand, data.model_name].filter(Boolean).join(' ') || '—' }}</div>
          <small v-if="data.is_third_party" class="muted">{{ pt('Not sold here') }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Owner')">
        <template #body="{ data }">
          <div>{{ data.customer || '—' }}</div>
          <small v-if="data.customer_phone" class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>

      <Column field="sold_at" :header="pt('Sold')" sortable>
        <template #body="{ data }">{{ data.sold_at || '—' }}</template>
      </Column>

      <Column field="installed_at" :header="pt('Installation')">
        <template #body="{ data }">
          <Tag
            v-if="data.awaits_installation"
            :value="pt('Pending')"
            severity="warn"
            :title="pt('Sold and needing a fitter, with nobody sent yet')"
          />
          <span v-else-if="data.installed_at">{{ data.installed_at }}</span>
          <span v-else class="muted" :title="pt('Nothing to install')">—</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(statusLabel(data.status))" :severity="STATUS_SEVERITY[data.status]" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.awaits_installation"
            icon="pi pi-check-circle"
            text
            rounded
            severity="success"
            :title="pt('Mark installed')"
            @click="markInstalled(data)"
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
          {{
            pt(
              'Nothing registered yet. A machine has to be on this list before its warranty, its fitting or its repair history can be looked up.',
            )
          }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Unit') : pt('Register Unit')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Article') }}</label>
          <Select
            v-model="form.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onProductPicked"
          />
          <small class="hint">{{ pt('Fills the model and the standard cover. Leave empty for a machine you did not sell.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Brand') }}</label>
          <InputText v-model="form.brand" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Model') }}</label>
          <InputText v-model="form.model_name" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Serial number') }}</label>
          <InputText v-model="form.serial_no" class="w-full mono" />
          <small v-if="errors.serial_no" class="err">{{ errors.serial_no[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('IMEI 1') }}</label>
          <InputText v-model="form.imei_1" class="w-full mono" maxlength="15" />
          <small v-if="errors.imei_1" class="err">{{ errors.imei_1[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('IMEI 2') }}</label>
          <InputText v-model="form.imei_2" class="w-full mono" maxlength="15" />
          <small v-if="errors.imei_2" class="err">{{ errors.imei_2[0] }}</small>
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
          />
          <small class="hint">{{ pt('Empty for stock that has not been sold yet.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select
            v-model="form.status"
            :options="STATUSES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Sold on') }}</label>
          <DatePicker v-model="form.sold_at" date-format="dd/mm/yy" show-icon class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Delivered on') }}</label>
          <DatePicker v-model="form.delivered_at" date-format="dd/mm/yy" show-icon class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Standard cover') }}</label>
          <InputNumber v-model="form.warranty_months" :min="1" suffix=" mo" class="w-full" />
          <small class="hint">{{ pt('Used to fill the end date on a new warranty card.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('AMC contract') }}</label>
          <Select
            v-model="form.amc_contract_id"
            :options="contracts"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            :placeholder="contracts.length ? undefined : pt('No contracts available')"
          />
        </div>

        <div class="fld checks span-2">
          <div class="chk">
            <Checkbox v-model="form.installation_required" input-id="inst" binary />
            <label for="inst">{{ pt('Needs installing') }}</label>
          </div>
          <div class="chk">
            <Checkbox v-model="form.is_third_party" input-id="third" binary />
            <label for="third">{{ pt('Not sold by us') }}</label>
          </div>
          <div class="chk">
            <Checkbox v-model="form.is_active" input-id="active" binary />
            <label for="active">{{ pt('Active') }}</label>
          </div>
        </div>

        <div v-if="form.installation_required" class="fld">
          <label>{{ pt('Installed on') }}</label>
          <DatePicker v-model="form.installed_at" date-format="dd/mm/yy" show-icon class="w-full" />
          <small v-if="errors.installed_at" class="err">{{ errors.installed_at[0] }}</small>
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
.check-term { min-width: 300px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.35rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; flex-wrap: wrap; }
.found-line { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.history { margin-top: 0.5rem; border-top: 1px dashed var(--surface-border, #e5e7eb); padding-top: 0.5rem; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.checks { flex-direction: row; gap: 1.25rem; align-items: center; flex-wrap: wrap; }
.chk { display: flex; gap: 0.4rem; align-items: center; }
.chk label { font-size: 0.8rem; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
small.mono { font-size: 0.72rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
