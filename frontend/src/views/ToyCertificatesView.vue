<script setup lang="ts">
/**
 * The toy shop's certificate register.
 *
 * Built around the fact that nothing on a shelf looks any different the day a
 * licence expires. A BIS licence under the Toys (Quality Control) Order belongs
 * to the manufacturer, runs out on a date nobody has in a diary, and the shop
 * finds out during an inspection — so the screen leads with what is lapsing
 * rather than with an alphabetical list, and the counter check sits at the top
 * where a doubt can be settled in one lookup.
 *
 * Status is derived from the date on every read, never from the stored flag: a
 * row saying "active" about paperwork that expired a fortnight ago is exactly
 * the failure this register exists to prevent.
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

const canCreate = computed(() => auth.can('toy-safety.create'))
const canEdit = computed(() => auth.can('toy-safety.edit'))
const canDelete = computed(() => auth.can('toy-safety.delete'))

const STANDARDS = [
  { label: 'BIS / IS 9873', value: 'bis' },
  { label: 'EN 71', value: 'en71' },
  { label: 'ASTM F963', value: 'astm' },
  { label: 'ISO 8124', value: 'iso8124' },
  { label: 'CE marking', value: 'ce' },
  { label: 'Other', value: 'other' },
]

const rows = ref<any[]>([])
const summary = ref({ registered: 0, live: 0, expiring_30d: 0, expired: 0, revoked: 0 })
const loading = ref(false)
const search = ref('')
const standardFilter = ref<string | null>(null)
const expiringOnly = ref(false)

const products = ref<any[]>([])
const brands = ref<any[]>([])
const suppliers = ref<any[]>([])

function standardLabel(v: string) {
  return STANDARDS.find((s) => s.value === v)?.label ?? v
}

function iso(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function parseDate(value: string | null) {
  return value ? new Date(value + 'T00:00:00') : null
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/toys/certificates', {
      params: {
        search: search.value || undefined,
        standard: standardFilter.value || undefined,
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
  const [p, b, s] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/brands', { params: { per_page: 500 } }),
    http.get('/suppliers', { params: { per_page: 500 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
  brands.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- "May we sell this?" ---------------------------------------------------

const checkProduct = ref<number | null>(null)
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkProduct.value) return
  checking.value = true
  try {
    const { data } = await http.get(`/toys/compliance/${checkProduct.value}`)
    checkResult.value = data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    checking.value = false
  }
}

const checkSeverity = computed(() => {
  if (!checkResult.value) return 'info'
  if (checkResult.value.blocks_sale) return 'error'
  if (checkResult.value.certificate_state === 'live') return 'success'
  if (checkResult.value.certificate_state === 'not_required') return 'info'
  return 'warn'
})

const checkMessage = computed(() => {
  const r = checkResult.value
  if (!r) return ''
  if (r.blocks_sale) return pt('The till will refuse this article — its certificate is not in order.')
  switch (r.certificate_state) {
    case 'live':
      return pt('Certified and covered today.')
    case 'not_required':
      return pt('This class needs no certificate.')
    case 'expired':
      return pt('The certificate behind this article has lapsed. The sale is allowed, but the paperwork is not.')
    default:
      return pt('No certificate is on file for this article.')
  }
})

// ---- Editor ----------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  certificate_number: '',
  standard: 'bis',
  product_id: null as number | null,
  brand_id: null as number | null,
  supplier_id: null as number | null,
  issued_by: '',
  licence_no: '',
  issued_on: null as Date | null,
  expires_on: null as Date | null,
  document_path: '',
  notes: '',
})

const form = ref(blank())

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/toys/certificates/${row.id}`)
  const c = data.data
  editingId.value = c.id
  form.value = {
    certificate_number: c.certificate_number,
    standard: c.standard,
    product_id: c.product_id,
    brand_id: c.brand_id,
    supplier_id: c.supplier_id,
    issued_by: c.issued_by ?? '',
    licence_no: c.licence_no ?? '',
    issued_on: parseDate(c.issued_on),
    expires_on: parseDate(c.expires_on),
    document_path: c.document_path ?? '',
    notes: c.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      issued_on: iso(form.value.issued_on),
      expires_on: iso(form.value.expires_on),
    }
    if (editingId.value) {
      await http.put(`/toys/certificates/${editingId.value}`, payload)
    } else {
      await http.post('/toys/certificates', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Certificate saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the certificate'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function revoke(row: any) {
  const res = await Swal.fire({
    title: pt('Withdraw this certificate?'),
    text: pt('Everything it covers stops being certified from now on. The record stays for the audit trail.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Withdraw'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/toys/certificates/${row.id}/revoke`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this certificate?'),
    text: pt('Withdrawing keeps the record; deleting removes it entirely.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/toys/certificates/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

function statusSeverity(row: any) {
  if (row.status === 'revoked') return 'danger'
  if (!row.is_live) return 'danger'
  return row.days_remaining !== null && row.days_remaining <= 30 ? 'warn' : 'success'
}

function statusText(row: any) {
  if (row.status === 'revoked') return pt('Withdrawn')
  if (!row.is_live) return pt('Expired')
  if (row.days_remaining === null) return pt('Open-ended')
  return pt('{n} days left', { n: row.days_remaining })
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Safety Certificates')"
      :subtitle="pt('The licences and test reports behind what you are allowed to sell')"
    >
      <template #actions>
        <Button
          v-if="canCreate"
          :label="pt('File Certificate')"
          icon="pi pi-plus"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('On file') }}</span>
        <span class="t-value">{{ summary.registered }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Live today') }}</span>
        <span class="t-value">{{ summary.live }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Expiring in 30 days') }}</span>
        <span class="t-value warn">{{ summary.expiring_30d }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Expired') }}</span>
        <span class="t-value bad">{{ summary.expired }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Withdrawn') }}</span>
        <span class="t-value">{{ summary.revoked }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('May we sell this?') }}</div>
      <div class="check-row">
        <Select
          v-model="checkProduct"
          :options="products"
          option-label="label"
          option-value="value"
          filter
          show-clear
          class="check-product"
          :placeholder="pt('Article name or SKU')"
          @change="checkResult = null"
        />
        <Button
          :label="pt('Check')"
          icon="pi pi-verified"
          :loading="checking"
          :disabled="!checkProduct"
          @click="runCheck"
        />
      </div>

      <div v-if="checkResult" class="check-result">
        <Message :severity="checkSeverity" :closable="false">{{ checkMessage }}</Message>
        <div v-if="checkResult.age_grade" class="check-line">
          <Tag :value="checkResult.age_grade.label" severity="info" />
          <span class="muted">{{ checkResult.age_grade.range }}</span>
        </div>
        <div v-if="checkResult.safety_category" class="check-line">
          <Tag :value="checkResult.safety_category.label" severity="warn" />
          <span v-for="h in checkResult.safety_category.hazards" :key="h" class="muted">· {{ h }}</span>
        </div>
        <div v-for="(w, i) in checkResult.warnings" :key="i" class="check-line warnline">
          <i class="pi pi-exclamation-triangle" /> {{ w }}
        </div>
        <div v-if="checkResult.certificate" class="check-line">
          <Tag :value="checkResult.certificate.certificate_number" severity="secondary" />
          <span class="muted">
            {{ standardLabel(checkResult.certificate.standard) }} ·
            {{ pt('covers') }} {{ checkResult.certificate.covers }}
            <template v-if="checkResult.certificate.expires_on">
              · {{ pt('until') }} {{ checkResult.certificate.expires_on }}
            </template>
          </span>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Certificate number, licence, lab, article or brand')"
        @keyup.enter="load"
      />
      <Select
        v-model="standardFilter"
        :options="[{ label: pt('All standards'), value: null }, ...STANDARDS]"
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
        title="Toy Safety Certificates"
        filenameBase="toy-safety-certificates"
        :rows="rows"
        :columns="[
          { key: 'certificate_number', label: 'Certificate' },
          { key: 'standard', label: 'Standard' },
          { key: 'covers', label: 'Covers' },
          { key: 'covers_name', label: 'Subject' },
          { key: 'issued_by', label: 'Issued by' },
          { key: 'expires_on', label: 'Expires' },
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
      <Column field="certificate_number" :header="pt('Certificate')" sortable>
        <template #body="{ data }">
          <div class="strong mono">{{ data.certificate_number }}</div>
          <small class="muted">{{ standardLabel(data.standard) }}</small>
        </template>
      </Column>

      <Column field="covers_name" :header="pt('Covers')">
        <template #body="{ data }">
          <div>{{ data.covers_name || '—' }}</div>
          <small class="muted">{{ pt(data.covers) }}</small>
        </template>
      </Column>

      <Column field="issued_by" :header="pt('Issued by')">
        <template #body="{ data }">
          <div>{{ data.issued_by || '—' }}</div>
          <small v-if="data.licence_no" class="muted mono">{{ data.licence_no }}</small>
        </template>
      </Column>

      <Column field="expires_on" :header="pt('Expires')" sortable>
        <template #body="{ data }">
          <div>{{ data.expires_on || pt('Open-ended') }}</div>
          <small v-if="data.issued_on" class="muted">{{ pt('from') }} {{ data.issued_on }}</small>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusText(data)" :severity="statusSeverity(data)" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 10rem">
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
            v-if="canDelete && data.status !== 'revoked'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Withdraw')"
            @click="revoke(data)"
          />
          <Button
            v-if="canDelete"
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
          {{ pt('Nothing on file. A toy that legally needs a certificate is one an inspection can stop you selling.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Certificate') : pt('File Certificate')"
      modal
      :style="{ width: '780px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Certificate number') }} *</label>
          <InputText v-model="form.certificate_number" class="w-full mono" />
          <small v-if="errors.certificate_number" class="err">{{ errors.certificate_number[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Standard') }} *</label>
          <Select
            v-model="form.standard"
            :options="STANDARDS"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Manufacturer licence no.') }}</label>
          <InputText v-model="form.licence_no" class="w-full mono" placeholder="CM/L-7712345" />
        </div>

        <div class="fld span-3">
          <Message severity="info" :closable="false">
            {{ pt('Say what it covers. A licence issued for a whole brand goes on the brand; a test report for one article goes on the article. The narrowest one wins at the till.') }}
          </Message>
        </div>

        <div class="fld">
          <label>{{ pt('Article') }}</label>
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
          <label>{{ pt('Brand') }}</label>
          <Select
            v-model="form.brand_id"
            :options="brands"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Supplier') }}</label>
          <Select
            v-model="form.supplier_id"
            :options="suppliers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Issued by') }}</label>
          <InputText v-model="form.issued_by" class="w-full" :placeholder="pt('Bureau of Indian Standards')" />
        </div>
        <div class="fld">
          <label>{{ pt('Issued on') }}</label>
          <DatePicker v-model="form.issued_on" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Expires on') }}</label>
          <DatePicker v-model="form.expires_on" date-format="dd/mm/yy" class="w-full" />
          <small class="hint">{{ pt('Leave blank only for a declaration that genuinely never expires.') }}</small>
          <small v-if="errors.expires_on" class="err">{{ errors.expires_on[0] }}</small>
        </div>

        <div class="fld span-3">
          <label>{{ pt('Scanned document') }}</label>
          <InputText v-model="form.document_path" class="w-full" :placeholder="pt('Path to the stored scan')" />
        </div>
        <div class="fld span-3">
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
.t-value.bad { color: #b91c1c; }

.check-panel { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1rem; }
.check-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.6rem; }
.check-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.check-product { min-width: 320px; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.35rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; flex-wrap: wrap; }
.warnline { color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 0.75rem; }
.span-3 { grid-column: 1 / -1; }
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
