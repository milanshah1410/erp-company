<script setup lang="ts">
/**
 * The insurance book — screen, liquid and theft cover sold on handsets.
 *
 * Built around the moment it is actually used: somebody puts a cracked phone on the
 * counter and is already certain they are covered. So the check panel sits at the
 * top, takes the IMEI, and reports *why* anything is not in force — "the single
 * claim this policy allows was made in March" is a far better thing to be able to
 * say than "no cover found".
 *
 * It reports warranty cards beside the policies on purpose. A customer does not
 * distinguish the two, and a fault the manufacturer would have fixed free should
 * never be settled as an insurance claim: that spends a once-only policy on
 * something somebody else was going to pay for.
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

const canCreate = computed(() => auth.can('mobile-insurance.create'))
const canEdit = computed(() => auth.can('mobile-insurance.edit'))
const canDelete = computed(() => auth.can('mobile-insurance.delete'))

const rows = ref<any[]>([])
const summary = ref({
  written: 0,
  active: 0,
  expiring_30d: 0,
  theft_cover: 0,
  claims: 0,
  premium_collected: 0,
})
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const handsets = ref<any[]>([])
const search = ref('')
const expiringOnly = ref(false)

function money(v: any) {
  return v === null || v === undefined ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/mobile/insurance', {
      params: {
        search: search.value || undefined,
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
  const [c, p, h] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/mobile/handsets', { params: { per_page: 500 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: x.phone ? `${x.name} (${x.phone})` : x.name,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    selling_price: x.selling_price,
  }))
  handsets.value = (h.data.data ?? h.data).map((x: any) => ({
    label: x.label,
    value: x.id,
    imei: x.imei_1,
    customer_id: x.customer_id,
    product_id: x.product_id,
  }))
}

// ---- "Am I covered?" ------------------------------------------------------

const checkImei = ref('')
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkImei.value.trim()) return
  checking.value = true
  try {
    const { data } = await http.get('/mobile/insurance/check', {
      params: { imei: checkImei.value.trim() },
    })
    checkResult.value = data
  } finally {
    checking.value = false
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  provider: '',
  provider_policy_no: '',
  customer_id: null as number | null,
  handset_id: null as number | null,
  product_id: null as number | null,
  imei: '',
  start_date: new Date() as Date | null,
  end_date: null as Date | null,
  coverage_months: 12 as number | null,
  premium: 0 as number | null,
  sum_insured: null as number | null,
  deductible: 0 as number | null,
  covers_accidental_damage: true,
  covers_liquid_damage: false,
  covers_theft: false,
  claim_limit: 1 as number | null,
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
 * Filling the months figure moves the end date with it — that is how a policy is
 * sold ("one year"), and making somebody count twelve months forward on a calendar
 * is how end dates end up a month out.
 */
function onMonthsChanged() {
  if (!form.value.coverage_months || !form.value.start_date) return
  const end = new Date(form.value.start_date)
  end.setMonth(end.getMonth() + Number(form.value.coverage_months))
  form.value.end_date = end
}

function onHandsetPicked() {
  const h = handsets.value.find((x) => x.value === form.value.handset_id)
  if (!h) return
  if (!form.value.imei) form.value.imei = h.imei ?? ''
  if (!form.value.customer_id) form.value.customer_id = h.customer_id
  if (!form.value.product_id) form.value.product_id = h.product_id
  // The handset's price is the natural sum insured, and it is what the underwriter
  // will ask for.
  const p = products.value.find((x) => x.value === h.product_id)
  if (p && form.value.sum_insured === null) form.value.sum_insured = p.selling_price
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  onMonthsChanged()
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/mobile/insurance/${row.id}`)
  const p = data.data
  editingId.value = p.id
  form.value = {
    provider: p.provider ?? '',
    provider_policy_no: p.provider_policy_no ?? '',
    customer_id: p.customer_id,
    handset_id: p.handset_id,
    product_id: p.product_id,
    imei: p.imei ?? '',
    start_date: p.start_date ? new Date(p.start_date) : null,
    end_date: p.end_date ? new Date(p.end_date) : null,
    coverage_months: p.coverage_months,
    premium: p.premium,
    sum_insured: p.sum_insured,
    deductible: p.deductible,
    covers_accidental_damage: p.covers_accidental_damage,
    covers_liquid_damage: p.covers_liquid_damage,
    covers_theft: p.covers_theft,
    claim_limit: p.claim_limit,
    terms: p.terms ?? '',
    notes: p.notes ?? '',
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
      await http.put(`/mobile/insurance/${editingId.value}`, payload)
    } else {
      await http.post('/mobile/insurance', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Policy recorded'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the policy'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function voidPolicy(row: any) {
  const res = await Swal.fire({
    title: pt('Cancel this policy?'),
    text: pt('The customer stops being covered from now on. The record stays for the audit trail.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Cancel policy'),
    cancelButtonText: pt('Back'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/mobile/insurance/${row.id}/void`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this policy?'),
    text: pt('Only policies with no claims against them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/mobile/insurance/${row.id}`)
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
      :title="pt('Handset Insurance')"
      :subtitle="pt('Screen, liquid and theft cover — what is in force, and how many claims are left on it')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Record Policy')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Policies written') }}</span>
        <span class="t-value">{{ summary.written }}</span>
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
        <span class="t-label">{{ pt('Theft cover live') }}</span>
        <span class="t-value">{{ summary.theft_cover }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Claims made') }}</span>
        <span class="t-value">{{ summary.claims }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Premium collected') }}</span>
        <span class="t-value">{{ money(summary.premium_collected) }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('Is this handset covered?') }}</div>
      <div class="check-row">
        <InputText
          v-model="checkImei"
          class="check-imei"
          :placeholder="pt('Scan or key the IMEI')"
          @keyup.enter="runCheck"
        />
        <Button
          :label="pt('Check')"
          icon="pi pi-shield"
          :loading="checking"
          :disabled="!checkImei.trim()"
          @click="runCheck"
        />
      </div>

      <div v-if="checkResult" class="check-result">
        <Message :severity="checkResult.is_insured ? 'success' : 'warn'" :closable="false">
          {{
            checkResult.is_insured
              ? pt('Insured — the repair can be claimed, less the excess.')
              : pt('No live insurance on this handset.')
          }}
        </Message>

        <Message v-if="checkResult.is_under_warranty" severity="info" :closable="false">
          {{
            pt(
              'This handset is still under warranty. If the fault is covered there, use the warranty — an insurance claim would use up cover the maker was going to honour free.',
            )
          }}
        </Message>

        <div v-for="p in checkResult.data" :key="p.id" class="check-line">
          <Tag :value="p.policy_number" :severity="p.is_live ? 'success' : 'secondary'" />
          <span class="muted">{{ p.provider }}</span>
          <template v-if="p.is_live">
            <span>{{ p.perils.join(', ') }}</span>
            <span class="muted">
              {{ pt('until') }} {{ p.end_date }} ·
              {{ pt('excess') }} {{ money(p.deductible) }}
              <template v-if="p.claims_remaining !== null">
                · {{ p.claims_remaining }} {{ pt('claim(s) left') }}
              </template>
            </span>
          </template>
          <span v-else class="muted">{{ p.reason }}</span>
        </div>

        <div v-if="!checkResult.data.length" class="muted">
          {{ pt('No policy has ever been written against this handset.') }}
        </div>

        <div v-for="w in checkResult.warranties" :key="'w' + w.id" class="check-line">
          <Tag :value="w.warranty_number" :severity="w.is_live ? 'success' : 'secondary'" />
          <span class="muted">{{ pt('warranty') }} · {{ w.source }}</span>
          <span v-if="w.is_live">{{ pt('until') }} {{ w.end_date }}</span>
          <span v-else class="muted">{{ w.reason }}</span>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Policy number, IMEI, provider or customer')" @keyup.enter="load" />
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
        title="Handset Insurance"
        filenameBase="handset-insurance"
        :rows="rows"
        :columns="[
          { key: 'policy_number', label: 'Policy' },
          { key: 'provider', label: 'Insurer' },
          { key: 'imei', label: 'IMEI' },
          { key: 'customer', label: 'Customer' },
          { key: 'start_date', label: 'From' },
          { key: 'end_date', label: 'To' },
          { key: 'premium', label: 'Premium' },
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
      <Column field="policy_number" :header="pt('Policy')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.policy_number }}</div>
          <small class="muted">
            {{ data.provider }}
            <template v-if="data.provider_policy_no"> · {{ data.provider_policy_no }}</template>
          </small>
        </template>
      </Column>

      <Column field="imei" :header="pt('Covers')">
        <template #body="{ data }">
          <div class="mono">{{ data.imei || '—' }}</div>
          <small v-if="data.handset" class="muted">{{ data.handset }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">{{ data.customer || '—' }}</template>
      </Column>

      <Column field="perils" :header="pt('Against')">
        <template #body="{ data }">
          <span v-if="data.perils.length" class="perils">{{ data.perils.join(', ') }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="end_date" :header="pt('Until')" sortable>
        <template #body="{ data }">
          <div>{{ data.end_date }}</div>
          <small class="muted">{{ pt('from') }} {{ data.start_date }}</small>
        </template>
      </Column>

      <Column field="premium" :header="pt('Premium')" sortable>
        <template #body="{ data }">
          <div>{{ money(data.premium) }}</div>
          <small v-if="data.deductible" class="muted">{{ pt('excess') }} {{ money(data.deductible) }}</small>
        </template>
      </Column>

      <Column field="claims_count" :header="pt('Claims')">
        <template #body="{ data }">
          {{ data.claims_count }}
          <small v-if="data.claim_limit" class="muted">/ {{ data.claim_limit }}</small>
        </template>
      </Column>

      <Column field="is_live" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="
              data.is_live
                ? pt('In force')
                : pt(data.status === 'void' ? 'Cancelled' : data.status === 'claimed' ? 'Used up' : 'Expired')
            "
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
            :title="pt('Cancel policy')"
            @click="voidPolicy(data)"
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
              'No policies yet. Cover is where the margin on a handset sale actually is — and a claim can only be honoured if the policy is on this list.',
            )
          }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Policy') : pt('Record Policy')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Insurer') }} *</label>
          <InputText v-model="form.provider" class="w-full" placeholder="Acko / OneAssist / Samsung Care+" />
          <small v-if="errors.provider" class="err">{{ errors.provider[0] }}</small>
          <small v-else class="hint">{{ pt('Who the customer is sent to at claim time.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt("Insurer's policy number") }}</label>
          <InputText v-model="form.provider_policy_no" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Handset') }}</label>
          <Select
            v-model="form.handset_id"
            :options="handsets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onHandsetPicked"
          />
          <small class="hint">{{ pt('Leave empty and key the IMEI below for a phone we did not sell.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('IMEI') }}</label>
          <InputText v-model="form.imei" class="w-full mono" />
          <small v-if="errors.imei" class="err">{{ errors.imei[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
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

        <div class="fld">
          <label>{{ pt('Premium') }} *</label>
          <InputNumber v-model="form.premium" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.premium" class="err">{{ errors.premium[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sum insured') }}</label>
          <InputNumber v-model="form.sum_insured" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Excess per claim') }}</label>
          <InputNumber v-model="form.deductible" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.deductible" class="err">{{ errors.deductible[0] }}</small>
          <small v-else class="hint">{{ pt('What the customer still pays. Tell them now.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Claims allowed') }}</label>
          <InputNumber v-model="form.claim_limit" :min="1" class="w-full" />
          <small class="hint">{{ pt('Usually one. Clear it for unlimited.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Covers') }} *</label>
          <div class="perils-row">
            <span class="check-line">
              <Checkbox v-model="form.covers_accidental_damage" binary input-id="ad" />
              <label for="ad">{{ pt('Accidental damage (cracked screen)') }}</label>
            </span>
            <span class="check-line">
              <Checkbox v-model="form.covers_liquid_damage" binary input-id="ld" />
              <label for="ld">{{ pt('Liquid damage') }}</label>
            </span>
            <span class="check-line">
              <Checkbox v-model="form.covers_theft" binary input-id="th" />
              <label for="th">{{ pt('Theft') }}</label>
            </span>
          </div>
          <small v-if="errors.covers_accidental_damage" class="err">
            {{ errors.covers_accidental_damage[0] }}
          </small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Terms') }}</label>
          <Textarea
            v-model="form.terms"
            rows="2"
            auto-resize
            class="w-full"
            :placeholder="pt('What is excluded, and what voids the policy')"
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
.check-imei { min-width: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.4rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; flex-wrap: wrap; }
.perils-row { display: flex; gap: 1.25rem; flex-wrap: wrap; }
.perils { font-size: 0.82rem; }

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
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
