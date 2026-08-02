<script setup lang="ts">
/**
 * Part-exchange — the old handset taken in against a new one.
 *
 * Follows the trade's own sequence, which is why the statuses lead the screen. A
 * valuation is quoted over the counter and most quotes die there. The ones that do
 * not turn into the shop holding somebody's phone and owing them its value, and that
 * open credit is the number a manager actually wants: every rupee of it is a handset
 * sitting in a drawer that has not been paid for in goods yet.
 *
 * The editor shows the arithmetic live — base, less deductions, plus the brand bonus
 * — because that is exactly what is read out to the customer, and a total they cannot
 * follow is an argument the shop loses.
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

const canCreate = computed(() => auth.can('mobile-buyback.create'))
const canEdit = computed(() => auth.can('mobile-buyback.edit'))
const canDelete = computed(() => auth.can('mobile-buyback.delete'))
const canSettle = computed(() => auth.can('payments.create'))
const canStock = computed(() => auth.can('stock.create'))

const GRADES = [
  { label: 'A — like new', value: 'A' },
  { label: 'B — light marks', value: 'B' },
  { label: 'C — visible wear', value: 'C' },
  { label: 'D — faulty / spares', value: 'D' },
]

const GRADE_SEVERITY: Record<string, string> = { A: 'success', B: 'info', C: 'warn', D: 'danger' }

const STATUS_SEVERITY: Record<string, string> = {
  quoted: 'info',
  accepted: 'warn',
  settled: 'success',
  rejected: 'secondary',
  expired: 'secondary',
}

const rows = ref<any[]>([])
const summary = ref({
  quoted: 0,
  accepted: 0,
  settled: 0,
  open_credit: 0,
  bonus_recoverable: 0,
  awaiting_stock: 0,
})
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const invoices = ref<any[]>([])
const search = ref('')
const openCreditOnly = ref(false)
const unstockedOnly = ref(false)

function money(v: any) {
  return v === null || v === undefined ? '—' : `₹${Number(v).toLocaleString('en-IN')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/mobile/buybacks', {
      params: {
        search: search.value || undefined,
        open_credit: openCreditOnly.value ? 1 : undefined,
        unstocked: unstockedOnly.value ? 1 : undefined,
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
  const [c, p, w, i] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/invoices', { params: { per_page: 200 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({
    label: x.phone ? `${x.name} (${x.phone})` : x.name,
    value: x.id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  invoices.value = (i.data.data ?? i.data).map((x: any) => ({
    label: `${x.invoice_number} — ${money(x.total)}`,
    value: x.id,
  }))
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  brand: '',
  model_name: '',
  imei: '',
  device_serial: '',
  storage: '',
  colour: '',
  age_months: null as number | null,
  grade: 'B',
  defects: '',
  has_box: false,
  has_charger: false,
  has_bill: false,
  base_value: null as number | null,
  deduction: 0 as number | null,
  bonus_value: 0 as number | null,
  quoted_on: new Date() as Date | null,
  valid_until: new Date(Date.now() + 7 * 86400000) as Date | null,
  notes: '',
})

const form = ref(blank())

/** The number read out to the customer. Recomputed here exactly as the server does. */
const offered = computed(() =>
  Math.max(
    0,
    Number(form.value.base_value ?? 0) -
      Number(form.value.deduction ?? 0) +
      Number(form.value.bonus_value ?? 0),
  ),
)

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/mobile/buybacks/${row.id}`)
  const b = data.data
  editingId.value = b.id
  form.value = {
    customer_id: b.customer_id,
    brand: b.brand ?? '',
    model_name: b.model_name ?? '',
    imei: b.imei ?? '',
    device_serial: b.device_serial ?? '',
    storage: b.storage ?? '',
    colour: b.colour ?? '',
    age_months: b.age_months,
    grade: b.grade,
    defects: b.defects ?? '',
    has_box: b.has_box,
    has_charger: b.has_charger,
    has_bill: b.has_bill,
    base_value: b.base_value,
    deduction: b.deduction,
    bonus_value: b.bonus_value,
    quoted_on: b.quoted_on ? new Date(b.quoted_on) : null,
    valid_until: b.valid_until ? new Date(b.valid_until) : null,
    notes: b.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      quoted_on: asDate(form.value.quoted_on),
      valid_until: asDate(form.value.valid_until),
    }
    if (editingId.value) {
      await http.put(`/mobile/buybacks/${editingId.value}`, payload)
    } else {
      await http.post('/mobile/buybacks', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Valuation saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the valuation'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

// ---- Taking it in, settling it, shelving it -------------------------------

const acceptDialog = ref(false)
const accepting = ref(false)
const acceptTarget = ref<any>(null)
const acceptCustomer = ref<number | null>(null)

function openAccept(row: any) {
  acceptTarget.value = row
  acceptCustomer.value = row.customer_id
  acceptDialog.value = true
}

async function doAccept() {
  accepting.value = true
  try {
    await http.post(`/mobile/buybacks/${acceptTarget.value.id}/progress`, {
      status: 'accepted',
      customer_id: acceptCustomer.value,
    })
    acceptDialog.value = false
    await load()
    toast.add({
      severity: 'success',
      summary: pt('Handset taken in'),
      detail: pt('The value is now a credit the customer holds until it is set against a bill.'),
      life: 4000,
    })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.customer_id?.[0] ?? e.response?.data?.message,
      life: 4500,
    })
  } finally {
    accepting.value = false
  }
}

async function reject(row: any) {
  const res = await Swal.fire({
    title: pt('Close this valuation?'),
    text: pt('The customer took their handset away.'),
    input: 'text',
    inputPlaceholder: pt('Reason (optional)'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Close it'),
    cancelButtonText: pt('Back'),
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/mobile/buybacks/${row.id}/progress`, {
      status: 'rejected',
      reason: res.value || undefined,
    })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

const settleDialog = ref(false)
const settling = ref(false)
const settleTarget = ref<any>(null)
const settleInvoice = ref<number | null>(null)

function openSettle(row: any) {
  settleTarget.value = row
  settleInvoice.value = null
  settleDialog.value = true
}

async function doSettle() {
  settling.value = true
  try {
    await http.post(`/mobile/buybacks/${settleTarget.value.id}/settle`, {
      invoice_id: settleInvoice.value,
    })
    settleDialog.value = false
    await load()
    toast.add({
      severity: 'success',
      summary: pt('Trade-in set against the bill'),
      detail: pt('Recorded as a part-exchange tender, so the new handset still bills at its shelf price.'),
      life: 4500,
    })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.invoice_id?.[0] ?? e.response?.data?.message,
      life: 7000,
    })
  } finally {
    settling.value = false
  }
}

const stockDialog = ref(false)
const stocking = ref(false)
const stockTarget = ref<any>(null)
const stockForm = ref({ product_id: null as number | null, warehouse_id: null as number | null })

function openStock(row: any) {
  stockTarget.value = row
  stockForm.value = { product_id: null, warehouse_id: warehouses.value[0]?.value ?? null }
  stockDialog.value = true
}

async function doStock() {
  stocking.value = true
  try {
    await http.post(`/mobile/buybacks/${stockTarget.value.id}/stock`, stockForm.value)
    stockDialog.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Taken into stock'), life: 3000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.errors?.product_id?.[0] ?? e.response?.data?.message,
      life: 5000,
    })
  } finally {
    stocking.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this valuation?'),
    text: pt('Only a quote the shop is not holding a handset against can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/mobile/buybacks/${row.id}`)
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
      :title="pt('Part-Exchange')"
      :subtitle="pt('Old handsets valued, taken in, and set against the new bill')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Value a Handset')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Open quotes') }}</span>
        <span class="t-value">{{ summary.quoted }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Credit we owe') }}</span>
        <span class="t-value" :class="{ warn: summary.open_credit > 0 }">{{ money(summary.open_credit) }}</span>
        <small class="t-note">{{ summary.accepted }} {{ pt('handsets held') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Set against bills') }}</span>
        <span class="t-value">{{ summary.settled }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Brand bonus to recover') }}</span>
        <span class="t-value">{{ money(summary.bonus_recoverable) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Not yet shelved') }}</span>
        <span class="t-value" :class="{ warn: summary.awaiting_stock > 0 }">{{ summary.awaiting_stock }}</span>
        <small class="t-note">{{ pt('owned but unsellable') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Slip number, model, IMEI or customer')" @keyup.enter="load" />
      <Button
        :label="pt('Credit we owe')"
        :outlined="!openCreditOnly"
        icon="pi pi-wallet"
        @click="
          () => {
            openCreditOnly = !openCreditOnly
            load()
          }
        "
      />
      <Button
        :label="pt('Not yet shelved')"
        :outlined="!unstockedOnly"
        icon="pi pi-box"
        @click="
          () => {
            unstockedOnly = !unstockedOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Part-Exchange"
        filenameBase="part-exchange"
        :rows="rows"
        :columns="[
          { key: 'buyback_number', label: 'Slip' },
          { key: 'brand', label: 'Brand' },
          { key: 'model_name', label: 'Model' },
          { key: 'grade', label: 'Grade' },
          { key: 'customer', label: 'Customer' },
          { key: 'offered_value', label: 'Allowed' },
          { key: 'status', label: 'Status' },
          { key: 'invoice_number', label: 'Set against' },
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
      <Column field="buyback_number" :header="pt('Slip')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.buyback_number }}</div>
          <small class="muted">{{ data.quoted_on }}</small>
        </template>
      </Column>

      <Column field="model_name" :header="pt('Handset')">
        <template #body="{ data }">
          <div>{{ [data.brand, data.model_name].filter(Boolean).join(' ') }}</div>
          <small class="muted mono">{{ data.imei || '—' }}</small>
        </template>
      </Column>

      <Column field="grade" :header="pt('Grade')" sortable>
        <template #body="{ data }">
          <Tag :value="data.grade" :severity="GRADE_SEVERITY[data.grade]" />
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">{{ data.customer || pt('Walk-in') }}</template>
      </Column>

      <Column field="offered_value" :header="pt('Allowed')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ money(data.offered_value) }}</div>
          <small class="muted">
            {{ money(data.base_value) }}
            <template v-if="data.deduction"> − {{ money(data.deduction) }}</template>
            <template v-if="data.bonus_value"> + {{ money(data.bonus_value) }}</template>
          </small>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="STATUS_SEVERITY[data.status]" />
          <small v-if="data.status === 'quoted' && !data.is_live" class="err block">
            {{ pt('quote expired') }}
          </small>
          <small v-if="data.invoice_number" class="muted block">{{ data.invoice_number }}</small>
        </template>
      </Column>

      <Column field="in_stock" :header="pt('Shelved')">
        <template #body="{ data }">
          <i v-if="data.in_stock" class="pi pi-check ok-icon" />
          <span v-else-if="['accepted', 'settled'].includes(data.status)" class="muted">{{ pt('no') }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 14rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.status === 'quoted'"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Re-value')"
            @click="openEdit(data)"
          />
          <Button
            v-if="canEdit && data.status === 'quoted'"
            icon="pi pi-check"
            text
            rounded
            severity="success"
            :title="pt('Take the handset in')"
            @click="openAccept(data)"
          />
          <Button
            v-if="canEdit && data.status === 'quoted'"
            icon="pi pi-times"
            text
            rounded
            severity="secondary"
            :title="pt('Customer walked away')"
            @click="reject(data)"
          />
          <Button
            v-if="canSettle && data.status === 'accepted'"
            icon="pi pi-wallet"
            text
            rounded
            severity="warn"
            :title="pt('Set against a bill')"
            @click="openSettle(data)"
          />
          <Button
            v-if="canStock && ['accepted', 'settled'].includes(data.status) && !data.in_stock"
            icon="pi pi-box"
            text
            rounded
            :title="pt('Take into stock')"
            @click="openStock(data)"
          />
          <Button
            v-if="canDelete && ['quoted', 'rejected', 'expired'].includes(data.status)"
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
              'No valuations yet. Value a customer’s old handset here and the allowance settles their new bill as a tender, so the new phone still bills at its shelf price.',
            )
          }}
        </div>
      </template>
    </DataTable>

    <!-- Accept -->
    <Dialog v-model:visible="acceptDialog" :header="pt('Take the Handset In')" modal :style="{ width: '520px' }">
      <div v-if="acceptTarget" class="grid">
        <div class="fld span-2">
          <Message severity="warn" :closable="false">
            {{
              pt(
                'The shop will be holding this handset and owing its value until it is set against a bill. That credit has to belong to a named customer.',
              )
            }}
          </Message>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Handset') }}</label>
          <div class="readout">{{ acceptTarget.label }} — {{ money(acceptTarget.offered_value) }}</div>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="acceptCustomer"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="acceptDialog = false" />
        <Button
          :label="pt('Take it in')"
          :loading="accepting"
          :disabled="!acceptCustomer"
          @click="doAccept"
        />
      </template>
    </Dialog>

    <!-- Settle -->
    <Dialog v-model:visible="settleDialog" :header="pt('Set Against a Bill')" modal :style="{ width: '560px' }">
      <div v-if="settleTarget" class="grid">
        <div class="fld span-2">
          <Message severity="info" :closable="false">
            {{ money(settleTarget.offered_value) }}
            {{
              pt(
                'will be recorded as a part-exchange tender against the bill — not as a discount, so the new handset still bills at its shelf price.',
              )
            }}
          </Message>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Invoice') }} *</label>
          <Select
            v-model="settleInvoice"
            :options="invoices"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
          <small class="hint">
            {{ pt('The bill has to have at least this much still outstanding on it.') }}
          </small>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="settleDialog = false" />
        <Button
          :label="pt('Settle')"
          :loading="settling"
          :disabled="!settleInvoice"
          @click="doSettle"
        />
      </template>
    </Dialog>

    <!-- Take into stock -->
    <Dialog v-model:visible="stockDialog" :header="pt('Take Into Stock')" modal :style="{ width: '560px' }">
      <div v-if="stockTarget" class="grid">
        <div class="fld span-2">
          <Message severity="info" :closable="false">
            {{ pt('Booked in at') }} {{ money(stockTarget.offered_value) }} —
            {{ pt('what the shop allowed for it, so the margin on the resale comes out of ordinary stock costing.') }}
          </Message>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Catalogue line') }} *</label>
          <Select
            v-model="stockForm.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
          <small class="hint">
            {{
              pt(
                'A used handset needs its own line — “iPhone 12 128GB — Refurbished”. A grade D one usually goes to a spares line instead.',
              )
            }}
          </small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Warehouse') }} *</label>
          <Select
            v-model="stockForm.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="stockDialog = false" />
        <Button
          :label="pt('Shelve it')"
          :loading="stocking"
          :disabled="!stockForm.product_id || !stockForm.warehouse_id"
          @click="doStock"
        />
      </template>
    </Dialog>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Re-value Handset') : pt('Value a Handset')"
      modal
      :style="{ width: '840px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Brand') }} *</label>
          <InputText v-model="form.brand" class="w-full" placeholder="Apple / Samsung" />
          <small v-if="errors.brand" class="err">{{ errors.brand[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Model') }} *</label>
          <InputText v-model="form.model_name" class="w-full" placeholder="iPhone 12" />
          <small v-if="errors.model_name" class="err">{{ errors.model_name[0] }}</small>
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
          <label>{{ pt('IMEI') }}</label>
          <InputText v-model="form.imei" class="w-full mono" />
          <small class="hint">{{ pt('Lets the shop see if this handset has been here before.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Serial') }}</label>
          <InputText v-model="form.device_serial" class="w-full mono" />
        </div>
        <div class="fld">
          <label>{{ pt('Age') }}</label>
          <InputNumber v-model="form.age_months" :min="0" suffix=" mo" class="w-full" />
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
          <small class="hint">{{ pt('Not needed to quote. Needed to take the handset in.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Grade') }} *</label>
          <Select
            v-model="form.grade"
            :options="GRADES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld span-3">
          <label>{{ pt('Defects') }}{{ form.grade === 'D' ? ' *' : '' }}</label>
          <InputText
            v-model="form.defects"
            class="w-full"
            :placeholder="pt('Screen burn, battery health 78%, back glass cracked…')"
          />
          <small v-if="errors.defects" class="err">{{ errors.defects[0] }}</small>
        </div>

        <div class="fld span-2 boxes">
          <span class="check-line">
            <Checkbox v-model="form.has_box" binary input-id="bx" />
            <label for="bx">{{ pt('Box') }}</label>
          </span>
          <span class="check-line">
            <Checkbox v-model="form.has_charger" binary input-id="ch" />
            <label for="ch">{{ pt('Charger') }}</label>
          </span>
          <span class="check-line">
            <Checkbox v-model="form.has_bill" binary input-id="bl" />
            <label for="bl">{{ pt('Original bill') }}</label>
          </span>
        </div>

        <div class="fld">
          <label>{{ pt('Base value') }} *</label>
          <InputNumber v-model="form.base_value" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.base_value" class="err">{{ errors.base_value[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Deductions') }}</label>
          <InputNumber v-model="form.deduction" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small v-if="errors.deduction" class="err">{{ errors.deduction[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Brand exchange bonus') }}</label>
          <InputNumber v-model="form.bonus_value" :min="0" mode="currency" currency="INR" locale="en-IN" class="w-full" />
          <small class="hint">{{ pt('Recoverable from the brand — kept separate for that reason.') }}</small>
        </div>
        <div class="fld offer">
          <label>{{ pt('We allow') }}</label>
          <div class="offer-value">{{ money(offered) }}</div>
        </div>

        <div class="fld">
          <label>{{ pt('Quoted on') }}</label>
          <DatePicker v-model="form.quoted_on" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Quote good until') }}</label>
          <DatePicker v-model="form.valid_until" date-format="dd/mm/yy" class="w-full" />
          <small class="hint">{{ pt('Used-handset prices move weekly.') }}</small>
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
.t-note { font-size: 0.68rem; color: #9ca3af; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.boxes { display: flex; flex-direction: row; gap: 1.25rem; align-items: center; flex-wrap: wrap; }
.check-line { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }
.offer { justify-content: flex-end; }
.offer-value { font-size: 1.15rem; font-weight: 700; }
.readout { font-size: 0.9rem; font-weight: 600; }
.muted { color: #6b7280; }
.block { display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.ok-icon { color: #15803d; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
