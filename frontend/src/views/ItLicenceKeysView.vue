<script setup lang="ts">
/**
 * The licence-key register.
 *
 * A key is worth money exactly once, and the register is read over a counter
 * with a customer standing at it — so the list shows masked keys and the full
 * string is fetched one record at a time, on a deliberate click. That is not
 * decoration: a screen full of product keys is a screen anyone walking past can
 * photograph.
 *
 * The other thing this screen is careful about is seats. "Do we have a spare
 * Office licence?" is a question about seats left on a volume key, not about how
 * many keys are in the drawer, and a register that counts rows answers it wrong.
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

const canCreate = computed(() => auth.can('it-licences.create'))
const canEdit = computed(() => auth.can('it-licences.edit'))
const canDelete = computed(() => auth.can('it-licences.delete'))

const TYPES = [
  { label: 'OEM — tied to the machine', value: 'oem' },
  { label: 'Retail — moves with the customer', value: 'retail' },
  { label: 'Volume — many seats, one key', value: 'volume' },
  { label: 'Subscription', value: 'subscription' },
  { label: 'Trial', value: 'trial' },
]

const STATUS_SEVERITY: Record<string, string> = {
  in_stock: 'info',
  allocated: 'success',
  expired: 'warn',
  revoked: 'danger',
}

const rows = ref<any[]>([])
const summary = ref({ registered: 0, in_stock: 0, allocated: 0, seats_free: 0, expiring_30d: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const assets = ref<any[]>([])
const products = ref<any[]>([])
const search = ref('')
const typeFilter = ref<string | null>(null)
const availableOnly = ref(false)
const expiringOnly = ref(false)

function typeLabel(v: string) {
  return TYPES.find((t) => t.value === v)?.label ?? v
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/computer-it/licence-keys', {
      params: {
        search: search.value || undefined,
        licence_type: typeFilter.value || undefined,
        available: availableOnly.value ? 1 : undefined,
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
  const [c, a, p] = await Promise.all([
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
    http.get('/computer-it/assets', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  assets.value = (a.data.data ?? a.data).map((x: any) => ({
    label: `${x.asset_tag} — ${x.label}`,
    value: x.id,
    customer_id: x.customer_id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
}

// ---- Reveal ---------------------------------------------------------------

/**
 * Fetched one record at a time and kept only in this component's memory. The
 * list endpoint never returns a full key, so nothing here can leak one by
 * accident through an export or a screenshot of the table.
 */
const revealed = ref<Record<number, string>>({})

async function reveal(row: any) {
  if (revealed.value[row.id]) {
    delete revealed.value[row.id]
    return
  }
  try {
    const { data } = await http.get(`/computer-it/licence-keys/${row.id}`)
    revealed.value = { ...revealed.value, [row.id]: data.data.licence_key }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function copyKey(row: any) {
  const key = revealed.value[row.id] ?? (await http.get(`/computer-it/licence-keys/${row.id}`)).data.data.licence_key
  try {
    await navigator.clipboard.writeText(key)
    toast.add({ severity: 'success', summary: pt('Key copied'), life: 2000 })
  } catch {
    toast.add({ severity: 'warn', summary: pt('Could not copy — reveal it and copy by hand.'), life: 3000 })
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  software: '',
  version: '',
  publisher: '',
  product_id: null as number | null,
  licence_type: 'retail',
  licence_key: '',
  seats: 1,
  customer_id: null as number | null,
  asset_id: null as number | null,
  vendor: '',
  valid_from: new Date() as Date | null,
  valid_to: null as Date | null,
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
  const { data } = await http.get(`/computer-it/licence-keys/${row.id}`)
  const l = data.data
  editingId.value = l.id
  form.value = {
    software: l.software ?? '',
    version: l.version ?? '',
    publisher: l.publisher ?? '',
    product_id: l.product_id,
    licence_type: l.licence_type,
    licence_key: l.licence_key ?? '',
    seats: l.seats,
    customer_id: l.customer_id,
    asset_id: l.asset_id,
    vendor: l.vendor ?? '',
    valid_from: l.valid_from ? new Date(l.valid_from) : null,
    valid_to: l.valid_to ? new Date(l.valid_to) : null,
    notes: l.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      valid_from: asDate(form.value.valid_from),
      valid_to: asDate(form.value.valid_to),
    }
    if (editingId.value) {
      await http.put(`/computer-it/licence-keys/${editingId.value}`, payload)
    } else {
      await http.post('/computer-it/licence-keys', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Licence saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the licence'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

// ---- Allocation -----------------------------------------------------------

const allocVisible = ref(false)
const allocRow = ref<any>(null)
const allocForm = ref({ customer_id: null as number | null, asset_id: null as number | null, seats: 1 })
const allocating = ref(false)

function openAllocate(row: any) {
  allocRow.value = row
  allocForm.value = { customer_id: row.customer_id, asset_id: row.asset_id, seats: 1 }
  allocVisible.value = true
}

function onAllocAssetPicked() {
  const a = assets.value.find((x) => x.value === allocForm.value.asset_id)
  if (a?.customer_id && !allocForm.value.customer_id) allocForm.value.customer_id = a.customer_id
}

async function allocate() {
  allocating.value = true
  try {
    await http.post(`/computer-it/licence-keys/${allocRow.value.id}/allocate`, allocForm.value)
    allocVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Seat issued'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4500 })
  } finally {
    allocating.value = false
  }
}

async function release(row: any) {
  const res = await Swal.fire({
    title: pt('Take a seat back?'),
    text: pt('Use this when a machine is retired or the seat was issued in error.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Release'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/computer-it/licence-keys/${row.id}/release`, { seats: 1 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function revoke(row: any) {
  const res = await Swal.fire({
    title: pt('Revoke this key?'),
    text: pt('The key stays on the register as proof it was issued, and stops being usable.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Revoke'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/computer-it/licence-keys/${row.id}/revoke`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this licence?'),
    text: pt('Only keys that have never been issued can be deleted. Revoke the rest.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/computer-it/licence-keys/${row.id}`)
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
      :title="pt('Licence Keys')"
      :subtitle="pt('Every key the shop holds, who it went to, and how many seats are left')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Add Licence')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('On the register') }}</span>
        <span class="t-value">{{ summary.registered }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('In stock') }}</span>
        <span class="t-value">{{ summary.in_stock }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Issued') }}</span>
        <span class="t-value">{{ summary.allocated }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Seats free') }}</span>
        <span class="t-value">{{ summary.seats_free }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Renewals in 30 days') }}</span>
        <span class="t-value warn">{{ summary.expiring_30d }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Software, publisher, customer or the last few characters of a key')"
        class="grow"
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
        :label="pt('Available')"
        :outlined="!availableOnly"
        icon="pi pi-check-circle"
        @click="
          () => {
            availableOnly = !availableOnly
            load()
          }
        "
      />
      <Button
        :label="pt('Renewals due')"
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
      <!-- Deliberately exports the masked key, never the real one. -->
      <TableExportButtons
        title="Licence Keys"
        filenameBase="licence-keys"
        :rows="rows"
        :columns="[
          { key: 'software', label: 'Software' },
          { key: 'licence_type', label: 'Type' },
          { key: 'licence_key_masked', label: 'Key' },
          { key: 'customer', label: 'Issued to' },
          { key: 'seats', label: 'Seats' },
          { key: 'seats_used', label: 'Used' },
          { key: 'valid_to', label: 'Valid to' },
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
      <Column field="software" :header="pt('Software')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.software }} {{ data.version }}</div>
          <small class="muted">
            {{ pt(typeLabel(data.licence_type)) }}
            <template v-if="data.publisher"> · {{ data.publisher }}</template>
          </small>
        </template>
      </Column>

      <Column field="licence_key_masked" :header="pt('Key')">
        <template #body="{ data }">
          <span class="mono">{{ revealed[data.id] ?? data.licence_key_masked }}</span>
          <Button
            :icon="revealed[data.id] ? 'pi pi-eye-slash' : 'pi pi-eye'"
            text
            rounded
            size="small"
            :title="revealed[data.id] ? pt('Hide') : pt('Reveal')"
            @click="reveal(data)"
          />
          <Button
            icon="pi pi-copy"
            text
            rounded
            size="small"
            :title="pt('Copy')"
            @click="copyKey(data)"
          />
        </template>
      </Column>

      <Column field="customer" :header="pt('Issued to')">
        <template #body="{ data }">
          <div>{{ data.customer || pt('In stock') }}</div>
          <small v-if="data.asset" class="muted">{{ data.asset }}</small>
        </template>
      </Column>

      <Column field="seats" :header="pt('Seats')" sortable>
        <template #body="{ data }">
          <span :class="{ full: data.seats_remaining === 0 }">
            {{ data.seats_used }} / {{ data.seats }}
          </span>
          <small class="muted">{{ data.seats_remaining }} {{ pt('free') }}</small>
        </template>
      </Column>

      <Column field="valid_to" :header="pt('Valid to')" sortable>
        <template #body="{ data }">
          <span v-if="data.is_perpetual" class="muted">{{ pt('Perpetual') }}</span>
          <span v-else :class="{ full: data.is_expired }">{{ data.valid_to }}</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="STATUS_SEVERITY[data.status] ?? 'secondary'" />
          <Tag
            v-if="!data.is_transferable"
            class="ml"
            :value="pt('Machine-locked')"
            severity="secondary"
            :title="pt('An OEM licence stays with the machine it was activated on')"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.is_available"
            icon="pi pi-send"
            text
            rounded
            :title="pt('Issue a seat')"
            @click="openAllocate(data)"
          />
          <Button
            v-if="canEdit && data.seats_used > 0"
            icon="pi pi-undo"
            text
            rounded
            :title="pt('Release a seat')"
            @click="release(data)"
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
            v-if="canDelete && data.status !== 'revoked'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Revoke')"
            @click="revoke(data)"
          />
          <Button
            v-if="canDelete && data.seats_used === 0"
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
          {{ pt('No licences on the register. Add them as they are bought — a key that only exists in an email is a key that gets sold twice.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Licence') : pt('Add Licence')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Software') }} *</label>
          <InputText v-model="form.software" class="w-full" :placeholder="pt('Windows 11 Pro')" />
          <small v-if="errors.software" class="err">{{ errors.software[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Version') }}</label>
          <InputText v-model="form.version" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Publisher') }}</label>
          <InputText v-model="form.publisher" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Licence key') }} *</label>
          <InputText v-model="form.licence_key" class="w-full mono" />
          <small v-if="errors.licence_key" class="err">{{ errors.licence_key[0] }}</small>
          <small v-else class="hint">{{ pt('Stored once. The list shows it masked.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Type') }}</label>
          <Select v-model="form.licence_type" :options="TYPES" option-label="label" option-value="value" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Seats') }}</label>
          <InputNumber v-model="form.seats" :min="1" class="w-full" />
          <small v-if="errors.seats" class="err">{{ errors.seats[0] }}</small>
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
          <small class="hint">{{ pt('Only if the shop stocks this licence as a SKU.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Issued to') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
          <small class="hint">{{ pt('Leave empty to hold it in stock.') }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Installed on') }}</label>
          <Select
            v-model="form.asset_id"
            :options="assets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Bought from') }}</label>
          <InputText v-model="form.vendor" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Valid from') }}</label>
          <DatePicker v-model="form.valid_from" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Valid to') }}</label>
          <DatePicker v-model="form.valid_to" date-format="dd/mm/yy" show-clear class="w-full" />
          <small class="hint">{{ pt('Leave empty for a perpetual licence.') }}</small>
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

    <!-- Allocate -->
    <Dialog v-model:visible="allocVisible" :header="pt('Issue a seat')" modal :style="{ width: '560px' }">
      <div v-if="allocRow" class="grid">
        <Message class="span-2" severity="info" :closable="false">
          {{ allocRow.software }} {{ allocRow.version }} —
          {{ allocRow.seats_remaining }} {{ pt('of') }} {{ allocRow.seats }} {{ pt('seats free') }}
        </Message>

        <div class="fld span-2">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="allocForm.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
          />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Machine') }}</label>
          <Select
            v-model="allocForm.asset_id"
            :options="assets"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="onAllocAssetPicked"
          />
          <small v-if="!allocRow.is_transferable" class="hint">
            {{ pt('This is an OEM licence — once activated it stays with that machine.') }}
          </small>
        </div>
        <div class="fld">
          <label>{{ pt('Seats') }}</label>
          <InputNumber v-model="allocForm.seats" :min="1" :max="allocRow.seats_remaining" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="allocVisible = false" />
        <Button
          :label="pt('Issue')"
          :loading="allocating"
          :disabled="!allocForm.customer_id"
          @click="allocate"
        />
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
.grow { min-width: 260px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.full { color: #b45309; font-weight: 600; }
.ml { margin-left: 0.35rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
