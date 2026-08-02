<script setup lang="ts">
/**
 * The handset register — every phone the shop has sold or is holding, by IMEI.
 *
 * Built around the counter's actual question, which is never "show me the list" but
 * "what is this thing in front of me?". So the lookup panel sits at the top rather
 * than behind a menu, takes a scanned or read-out number, and answers with the
 * handset, what covers it, what is still owed on it and what has been done to it
 * before — in one call, because at a counter those are one question.
 *
 * The second thing it exists for is the mis-keying list. An IMEI carries its own
 * check digit, and a transposed pair is silent: the handset is registered, cover is
 * written against it, and it only surfaces months later when a customer arrives with
 * a claim and nothing matches. So a bad check digit is a tile and a one-click filter
 * — never a refusal, because refurbished and grey-import stock genuinely does not
 * check out.
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

const canCreate = computed(() => auth.can('mobile-handsets.create'))
const canEdit = computed(() => auth.can('mobile-handsets.edit'))
const canDelete = computed(() => auth.can('mobile-handsets.delete'))

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
const summary = ref({ registered: 0, in_stock: 0, sold: 0, third_party: 0, suspect: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const statusFilter = ref<string | null>(null)
const suspectOnly = ref(false)

function statusLabel(v: string) {
  return STATUSES.find((s) => s.value === v)?.label ?? v
}

function money(v: any) {
  return v === null || v === undefined ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/mobile/handsets', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        suspect: suspectOnly.value ? 1 : undefined,
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
}

// ---- "What is this handset?" ---------------------------------------------

const lookupImei = ref('')
const lookupResult = ref<any>(null)
const lookingUp = ref(false)

async function runLookup() {
  if (!lookupImei.value.trim()) return
  lookingUp.value = true
  try {
    const { data } = await http.get('/mobile/handsets/lookup', {
      params: { imei: lookupImei.value.trim() },
    })
    lookupResult.value = data
  } finally {
    lookingUp.value = false
  }
}

function clearLookup() {
  lookupImei.value = ''
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
  storage: '',
  colour: '',
  imei_1: '',
  imei_2: '',
  serial_no: '',
  status: 'in_stock',
  is_third_party: false,
  sold_at: null as Date | null,
  warranty_months: null as number | null,
  notes: '',
})

const form = ref(blank())

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Picking the catalogue line fills the model and the standard cover, because both
 * are already known and re-keying them at a counter is how a warranty card ends up
 * with the wrong term on it.
 */
function onProductPicked() {
  const p = products.value.find((x) => x.value === form.value.product_id)
  if (!p) return
  if (!form.value.model_name) form.value.model_name = p.label.replace(/\s*\([^)]*\)$/, '')
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
  const { data } = await http.get(`/mobile/handsets/${row.id}`)
  const h = data.data
  editingId.value = h.id
  form.value = {
    customer_id: h.customer_id,
    product_id: h.product_id,
    brand: h.brand ?? '',
    model_name: h.model_name ?? '',
    storage: h.storage ?? '',
    colour: h.colour ?? '',
    imei_1: h.imei_1 ?? '',
    imei_2: h.imei_2 ?? '',
    serial_no: h.serial_no ?? '',
    status: h.status,
    is_third_party: h.is_third_party,
    sold_at: h.sold_at ? new Date(h.sold_at) : null,
    warranty_months: h.warranty_months,
    notes: h.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, sold_at: asDate(form.value.sold_at) }
    const { data } = editingId.value
      ? await http.put(`/mobile/handsets/${editingId.value}`, payload)
      : await http.post('/mobile/handsets', payload)

    dialogVisible.value = false
    await load()

    // The check digit is advisory, so a bad one is reported after the row is
    // safely saved rather than instead of saving it.
    if (data.imei_valid === false) {
      toast.add({
        severity: 'warn',
        summary: pt('Registered, but check the IMEI'),
        detail: pt('That number does not pass its own check digit — worth re-reading off the handset.'),
        life: 6000,
      })
    } else {
      toast.add({ severity: 'success', summary: pt('Handset registered'), life: 2500 })
    }
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the handset'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove this handset?'),
    text: pt('Only a handset with no cover, finance or repairs against it can be removed. Otherwise mark it returned or scrapped.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/mobile/handsets/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
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
      :title="pt('Handsets & IMEI')"
      :subtitle="pt('Every handset by IMEI — who has it, what covers it, what is owed on it')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Register Handset')" icon="pi pi-plus" @click="openCreate" />
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
        <span class="t-label">{{ pt('Not sold by us') }}</span>
        <span class="t-value">{{ summary.third_party }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('IMEI looks wrong') }}</span>
        <span class="t-value" :class="{ warn: summary.suspect > 0 }">{{ summary.suspect }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('What is this handset?') }}</div>
      <div class="check-row">
        <InputText
          v-model="lookupImei"
          class="check-imei"
          :placeholder="pt('Scan or key the IMEI')"
          @keyup.enter="runLookup"
        />
        <Button
          :label="pt('Look up')"
          icon="pi pi-search"
          :loading="lookingUp"
          :disabled="!lookupImei.trim()"
          @click="runLookup"
        />
        <Button v-if="lookupResult" :label="pt('Clear')" text @click="clearLookup" />
      </div>

      <div v-if="lookupResult" class="check-result">
        <Message :severity="lookupResult.handset ? 'success' : 'warn'" :closable="false">
          <template v-if="lookupResult.handset">
            {{ lookupResult.handset.label }} ·
            {{ statusLabel(lookupResult.handset.status) }}
            <template v-if="lookupResult.handset.customer"> · {{ lookupResult.handset.customer }}</template>
          </template>
          <template v-else>
            {{ pt('This IMEI is not in the register. Anything found below was recorded against the number alone.') }}
          </template>
        </Message>

        <Message v-if="!lookupResult.imei_valid" severity="warn" :closable="false">
          {{ pt('That number does not pass its own check digit — worth re-reading it off the handset.') }}
        </Message>

        <div class="panels">
          <div class="panel">
            <div class="p-title">{{ pt('Warranty') }}</div>
            <div v-if="!lookupResult.warranties.length" class="muted">{{ pt('No card issued.') }}</div>
            <div v-for="w in lookupResult.warranties" :key="w.id" class="p-line">
              <Tag :value="w.warranty_number" :severity="w.is_live ? 'success' : 'secondary'" />
              <span class="muted">{{ w.source }}</span>
              <span>{{ pt('until') }} {{ w.end_date }}</span>
            </div>
          </div>

          <div class="panel">
            <div class="p-title">{{ pt('Insurance') }}</div>
            <div v-if="!lookupResult.policies.length" class="muted">{{ pt('No policy sold.') }}</div>
            <div v-for="p in lookupResult.policies" :key="p.id" class="p-line">
              <Tag :value="p.policy_number" :severity="p.is_live ? 'success' : 'secondary'" />
              <span class="muted">{{ p.provider }}</span>
              <span v-if="p.is_live">
                {{ p.perils.join(', ') }}
                <template v-if="p.deductible"> · {{ pt('excess') }} {{ money(p.deductible) }}</template>
              </span>
              <span v-else class="muted">{{ pt('not in force') }}</span>
            </div>
          </div>

          <div class="panel">
            <div class="p-title">{{ pt('EMI') }}</div>
            <div v-if="!lookupResult.emi_plans.length" class="muted">{{ pt('No finance file.') }}</div>
            <div v-for="e in lookupResult.emi_plans" :key="e.id" class="p-line">
              <Tag :value="e.plan_number" :severity="e.overdue_count ? 'danger' : 'info'" />
              <span class="muted">{{ e.financier_name || pt('Our own finance') }}</span>
              <span>{{ e.paid_count }}/{{ e.tenure_months }} · {{ money(e.outstanding) }} {{ pt('left') }}</span>
            </div>
          </div>

          <div class="panel">
            <div class="p-title">{{ pt('Repairs') }}</div>
            <div v-if="!lookupResult.repairs.length" class="muted">{{ pt('Never been in.') }}</div>
            <div v-for="r in lookupResult.repairs" :key="r.id" class="p-line">
              <Tag :value="r.job_number" severity="secondary" />
              <span class="muted">{{ r.status }}</span>
              <span>{{ r.is_warranty ? pt('free') : money(r.total) }}</span>
            </div>
          </div>
        </div>

        <div v-if="lookupResult.trade_in" class="trade-in">
          {{ pt('This handset has been valued for part-exchange before') }} —
          {{ lookupResult.trade_in.buyback_number }} ({{ lookupResult.trade_in.status }},
          {{ pt('grade') }} {{ lookupResult.trade_in.grade }}, {{ money(lookupResult.trade_in.offered_value) }}).
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('IMEI, model, serial or owner')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="[{ label: pt('All statuses'), value: null }, ...STATUSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('IMEI looks wrong')"
        :outlined="!suspectOnly"
        icon="pi pi-exclamation-triangle"
        @click="
          () => {
            suspectOnly = !suspectOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Handsets"
        filenameBase="handsets"
        :rows="rows"
        :columns="[
          { key: 'imei_1', label: 'IMEI' },
          { key: 'brand', label: 'Brand' },
          { key: 'model_name', label: 'Model' },
          { key: 'customer', label: 'Owner' },
          { key: 'status', label: 'Status' },
          { key: 'sold_at', label: 'Sold' },
          { key: 'standard_cover_until', label: 'Cover until' },
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
      <Column field="imei_1" :header="pt('IMEI')" sortable>
        <template #body="{ data }">
          <div class="mono strong">
            {{ data.imei_1 || '—' }}
            <i
              v-if="data.imei_1 && !data.imei_valid"
              class="pi pi-exclamation-triangle warn-icon"
              :title="pt('Fails its own check digit')"
            />
          </div>
          <small v-if="data.imei_2" class="muted mono">{{ pt('SIM 2') }} {{ data.imei_2 }}</small>
          <small v-else-if="data.serial_no" class="muted mono">{{ data.serial_no }}</small>
        </template>
      </Column>

      <Column field="model_name" :header="pt('Handset')">
        <template #body="{ data }">
          <div>{{ [data.brand, data.model_name].filter(Boolean).join(' ') || '—' }}</div>
          <small class="muted">{{ [data.storage, data.colour].filter(Boolean).join(' · ') }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Owner')">
        <template #body="{ data }">
          <div>{{ data.customer || '—' }}</div>
          <small v-if="data.is_third_party" class="muted">{{ pt('not sold by us') }}</small>
        </template>
      </Column>

      <Column field="sold_at" :header="pt('Sold')" sortable>
        <template #body="{ data }">{{ data.sold_at || '—' }}</template>
      </Column>

      <Column field="standard_cover_until" :header="pt('Standard cover')">
        <template #body="{ data }">
          <span v-if="data.standard_cover_until">{{ data.standard_cover_until }}</span>
          <span v-else class="muted" :title="pt('No standard term recorded, or not yet sold')">—</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(statusLabel(data.status))" :severity="STATUS_SEVERITY[data.status]" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 8rem">
        <template #body="{ data }">
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
              'Nothing registered yet. Book handsets in by IMEI and every question afterwards — cover, EMI, repairs — is answerable from the number on the back.',
            )
          }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Handset') : pt('Register Handset')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Catalogue line') }}</label>
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
          <small class="hint">{{ pt('Fills the model and the standard warranty term.') }}</small>
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
          <label>{{ pt('Storage') }}</label>
          <InputText v-model="form.storage" class="w-full" placeholder="128GB" />
        </div>
        <div class="fld">
          <label>{{ pt('Colour') }}</label>
          <InputText v-model="form.colour" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('IMEI 1') }}</label>
          <InputText v-model="form.imei_1" class="w-full mono" />
          <small v-if="errors.imei_1" class="err">{{ errors.imei_1[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('IMEI 2') }}</label>
          <InputText v-model="form.imei_2" class="w-full mono" />
          <small v-if="errors.imei_2" class="err">{{ errors.imei_2[0] }}</small>
          <small v-else class="hint">{{ pt('Dual-SIM handsets only.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Serial number') }}</label>
          <InputText v-model="form.serial_no" class="w-full mono" />
          <small v-if="errors.serial_no" class="err">{{ errors.serial_no[0] }}</small>
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
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Sold on') }}</label>
          <DatePicker v-model="form.sold_at" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Standard warranty') }}</label>
          <InputNumber v-model="form.warranty_months" :min="0" suffix=" mo" class="w-full" />
        </div>

        <div class="fld span-2 check-line">
          <Checkbox v-model="form.is_third_party" binary input-id="tp" />
          <label for="tp">{{ pt('We did not sell this handset (repair or insurance only)') }}</label>
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
.check-imei { min-width: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.5rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; }

.panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; }
.panel { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.6rem 0.7rem; }
.p-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 0.4rem; }
.p-line { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; font-size: 0.82rem; margin-bottom: 0.25rem; }
.trade-in { font-size: 0.82rem; color: #6b7280; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.warn-icon { color: #b45309; font-size: 0.75rem; margin-left: 0.25rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
