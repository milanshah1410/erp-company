<script setup lang="ts">
/**
 * The install base — every machine this shop is responsible for.
 *
 * The register exists to answer questions a sales history cannot: what does this
 * customer run, where is each machine, and what is still covered on it. Cover
 * and AMC therefore appear on every row rather than behind a second lookup —
 * that answer is needed while the customer is still on the phone.
 *
 * The history panel puts calls and job cards on one timeline on purpose. A
 * customer asking "what have you done to this thing?" does not distinguish
 * between a phone call that fixed it and a week on the bench.
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

const canCreate = computed(() => auth.can('it-assets.create'))
const canEdit = computed(() => auth.can('it-assets.edit'))
const canDelete = computed(() => auth.can('it-assets.delete'))

const CATEGORIES = [
  { label: 'Laptop', value: 'laptop' },
  { label: 'Desktop', value: 'desktop' },
  { label: 'Server', value: 'server' },
  { label: 'Printer', value: 'printer' },
  { label: 'Network', value: 'network' },
  { label: 'Peripheral', value: 'peripheral' },
  { label: 'UPS', value: 'ups' },
  { label: 'Software', value: 'software' },
  { label: 'Other', value: 'other' },
]

const STATUSES = [
  { label: 'In stock', value: 'in_stock' },
  { label: 'Deployed', value: 'deployed' },
  { label: 'In service', value: 'in_service' },
  { label: 'Standby / loaner', value: 'standby' },
  { label: 'Retired', value: 'retired' },
]

const STATUS_SEVERITY: Record<string, string> = {
  in_stock: 'secondary',
  deployed: 'success',
  in_service: 'warn',
  standby: 'info',
  retired: 'danger',
}

const LEVEL_LABEL: Record<string, string> = {
  carry_in: 'Carry-in',
  onsite: 'On site',
  nbd_onsite: 'On site — next business day',
}

const rows = ref<any[]>([])
const summary = ref({ registered: 0, deployed: 0, in_service: 0, on_amc: 0, service_due: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const products = ref<any[]>([])
const contracts = ref<any[]>([])
const search = ref('')
const categoryFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const dueOnly = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/computer-it/assets', {
      params: {
        search: search.value || undefined,
        category: categoryFilter.value || undefined,
        status: statusFilter.value || undefined,
        service_due: dueOnly.value ? 1 : undefined,
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
  const [c, p, k] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/service-contracts', { params: { per_page: 300 } }).catch(() => ({ data: { data: [] } })),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
  contracts.value = (k.data.data ?? k.data ?? []).map((x: any) => ({
    label: `${x.contract_number} — ${x.title ?? ''}`.trim(),
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
  product_id: null as number | null,
  category: 'laptop',
  make: '',
  model: '',
  serial_no: '',
  specification: '',
  os: '',
  mac_address: '',
  site: '',
  department: '',
  assigned_user: '',
  purchase_date: null as Date | null,
  installed_on: null as Date | null,
  amc_contract_id: null as number | null,
  status: 'deployed',
  service_interval_days: null as number | null,
  notes: '',
})

const form = ref(blank())

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
  const { data } = await http.get(`/computer-it/assets/${row.id}`)
  const a = data.data
  editingId.value = a.id
  form.value = {
    customer_id: a.customer_id,
    product_id: a.product_id,
    category: a.category,
    make: a.make ?? '',
    model: a.model ?? '',
    serial_no: a.serial_no ?? '',
    specification: a.specification ?? '',
    os: a.os ?? '',
    mac_address: a.mac_address ?? '',
    site: a.site ?? '',
    department: a.department ?? '',
    assigned_user: a.assigned_user ?? '',
    purchase_date: a.purchase_date ? new Date(a.purchase_date) : null,
    installed_on: a.installed_on ? new Date(a.installed_on) : null,
    amc_contract_id: a.amc_contract_id,
    status: a.status,
    service_interval_days: a.service_interval_days,
    notes: a.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      purchase_date: asDate(form.value.purchase_date),
      installed_on: asDate(form.value.installed_on),
      serial_no: form.value.serial_no || null,
    }
    if (editingId.value) {
      await http.put(`/computer-it/assets/${editingId.value}`, payload)
    } else {
      await http.post('/computer-it/assets', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Machine saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the machine'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

// ---- History --------------------------------------------------------------

const historyVisible = ref(false)
const historyRows = ref<any[]>([])
const historyAsset = ref<any>(null)
const historyLoading = ref(false)

async function openHistory(row: any) {
  historyVisible.value = true
  historyLoading.value = true
  historyRows.value = []
  historyAsset.value = row
  try {
    const { data } = await http.get(`/computer-it/assets/${row.id}/history`)
    historyRows.value = data.data
    historyAsset.value = { ...row, ...data.asset }
  } finally {
    historyLoading.value = false
  }
}

function when(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this machine?'),
    text: pt('Only machines with no calls or job cards against them can be deleted. Retire the rest.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/computer-it/assets/${row.id}`)
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
      :title="pt('Installed Machines')"
      :subtitle="pt('What each customer runs, where it sits, and what still covers it')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Add Machine')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('On the register') }}</span>
        <span class="t-value">{{ summary.registered }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Deployed') }}</span>
        <span class="t-value">{{ summary.deployed }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('On the bench') }}</span>
        <span class="t-value">{{ summary.in_service }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('On AMC') }}</span>
        <span class="t-value">{{ summary.on_amc }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Preventive due') }}</span>
        <span class="t-value warn">{{ summary.service_due }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Tag, serial, model, user or site')"
        @keyup.enter="load"
      />
      <Select
        v-model="categoryFilter"
        :options="[{ label: pt('All kinds'), value: null }, ...CATEGORIES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Select
        v-model="statusFilter"
        :options="[{ label: pt('Any status'), value: null }, ...STATUSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('Preventive due')"
        :outlined="!dueOnly"
        icon="pi pi-clock"
        @click="
          () => {
            dueOnly = !dueOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Installed Machines"
        filenameBase="installed-machines"
        :rows="rows"
        :columns="[
          { key: 'asset_tag', label: 'Tag' },
          { key: 'label', label: 'Machine' },
          { key: 'serial_no', label: 'Serial' },
          { key: 'customer', label: 'Customer' },
          { key: 'site', label: 'Site' },
          { key: 'assigned_user', label: 'User' },
          { key: 'status', label: 'Status' },
          { key: 'next_service_due_at', label: 'Preventive due' },
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
      <Column field="asset_tag" :header="pt('Machine')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.label }}</div>
          <small class="muted mono">{{ data.asset_tag }}</small>
        </template>
      </Column>

      <Column field="customer" :header="pt('Customer')">
        <template #body="{ data }">
          <div>{{ data.customer || pt('Unallocated') }}</div>
          <small v-if="data.site" class="muted">{{ data.site }}</small>
        </template>
      </Column>

      <Column field="assigned_user" :header="pt('Used by')">
        <template #body="{ data }">
          <div>{{ data.assigned_user || '—' }}</div>
          <small v-if="data.department" class="muted">{{ data.department }}</small>
        </template>
      </Column>

      <Column field="warranty" :header="pt('Cover')">
        <template #body="{ data }">
          <template v-if="data.warranty">
            <Tag :value="pt('In warranty')" severity="success" />
            <small class="muted">
              {{ data.warranty.end_date }} ·
              {{ pt(LEVEL_LABEL[data.warranty.service_level] ?? data.warranty.service_level) }}
            </small>
          </template>
          <template v-else-if="data.amc?.is_live">
            <Tag :value="pt('On AMC')" severity="info" />
            <small class="muted">
              {{ data.amc.contract_number }}
              <template v-if="data.amc.visits_remaining !== null">
                · {{ data.amc.visits_remaining }} {{ pt('visits left') }}
              </template>
            </small>
          </template>
          <span v-else class="muted">{{ pt('Chargeable') }}</span>
        </template>
      </Column>

      <Column field="next_service_due_at" :header="pt('Preventive')" sortable>
        <template #body="{ data }">
          <span v-if="!data.next_service_due_at" class="muted">—</span>
          <span v-else :class="{ late: data.is_service_due }">{{ data.next_service_due_at }}</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="pt(STATUSES.find((s) => s.value === data.status)?.label ?? data.status)"
            :severity="STATUS_SEVERITY[data.status] ?? 'secondary'"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 10rem">
        <template #body="{ data }">
          <Button
            icon="pi pi-history"
            text
            rounded
            :title="pt('History')"
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
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing on the register yet. Add the machines you support — a call can be logged without one, but nothing will remember what happened to it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Machine') : pt('Add Machine')"
      modal
      :style="{ width: '860px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Kind') }}</label>
          <Select v-model="form.category" :options="CATEGORIES" option-label="label" option-value="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Make') }}</label>
          <InputText v-model="form.make" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Model') }}</label>
          <InputText v-model="form.model" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Serial number') }}</label>
          <InputText v-model="form.serial_no" class="w-full mono" />
          <small v-if="errors.serial_no" class="err">{{ errors.serial_no[0] }}</small>
          <small v-else class="hint">{{ pt('Blank is fine for an assembled machine.') }}</small>
        </div>

        <div class="fld span-2">
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
          <small class="hint">{{ pt('Leave empty for stock not yet delivered.') }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Catalogue product') }}</label>
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

        <div class="fld span-2">
          <label>{{ pt('Specification') }}</label>
          <Textarea
            v-model="form.specification"
            rows="2"
            auto-resize
            class="w-full"
            :placeholder="pt('i5-1235U · 16 GB · 512 GB NVMe')"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Operating system') }}</label>
          <InputText v-model="form.os" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('MAC address') }}</label>
          <InputText v-model="form.mac_address" class="w-full mono" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Site') }}</label>
          <InputText v-model="form.site" class="w-full" :placeholder="pt('Branch, floor, room')" />
        </div>
        <div class="fld">
          <label>{{ pt('Department') }}</label>
          <InputText v-model="form.department" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Used by') }}</label>
          <InputText v-model="form.assigned_user" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('Bought on') }}</label>
          <DatePicker v-model="form.purchase_date" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Installed on') }}</label>
          <DatePicker v-model="form.installed_on" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select v-model="form.status" :options="STATUSES" option-label="label" option-value="value" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('AMC contract') }}</label>
          <Select
            v-model="form.amc_contract_id"
            :options="contracts"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
          <small class="hint">{{ pt('Work booked under a live contract is done and not billed again.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Preventive every') }}</label>
          <InputNumber v-model="form.service_interval_days" :min="1" :max="730" suffix=" days" class="w-full" />
          <small class="hint">{{ pt('Sets when the next visit falls due.') }}</small>
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

    <!-- History -->
    <Dialog
      v-model:visible="historyVisible"
      :header="pt('History') + (historyAsset ? ` — ${historyAsset.label}` : '')"
      modal
      :style="{ width: '780px' }"
    >
      <div v-if="historyLoading" class="muted">{{ pt('Loading…') }}</div>
      <div v-else-if="!historyRows.length" class="empty-state">
        {{ pt('Nothing has happened to this machine yet.') }}
      </div>
      <div v-else class="timeline">
        <div v-for="row in historyRows" :key="`${row.kind}-${row.id}`" class="event">
          <div class="event-head">
            <Tag
              :value="row.kind === 'call' ? pt('Call') : pt('Job card')"
              :severity="row.kind === 'call' ? 'info' : 'secondary'"
            />
            <span class="strong">{{ row.reference }}</span>
            <span class="muted">{{ when(row.at) }}</span>
            <Tag :value="pt(row.status)" severity="secondary" />
            <Tag
              v-if="row.kind === 'job' && row.type === 'warranty'"
              :value="pt('Warranty')"
              severity="success"
            />
            <Tag
              v-else-if="row.kind === 'call' && !row.chargeable"
              :value="pt('Covered')"
              severity="success"
            />
          </div>
          <div class="event-body">{{ row.summary }}</div>
          <div v-if="row.resolution" class="event-res">{{ row.resolution }}</div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="historyVisible = false" />
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

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.late { color: #b45309; font-weight: 600; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }

.timeline { display: flex; flex-direction: column; gap: 0.75rem; }
.event { border-left: 3px solid var(--surface-border, #e5e7eb); padding: 0.15rem 0 0.15rem 0.8rem; }
.event-head { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; font-size: 0.85rem; }
.event-body { margin-top: 0.3rem; font-size: 0.85rem; }
.event-res { margin-top: 0.2rem; font-size: 0.8rem; color: #6b7280; }
</style>
