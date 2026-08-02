<script setup lang="ts">
/**
 * The tester register.
 *
 * Every row here is a unit that has left saleable stock and will never be
 * billed: the testers on the counter, the sample a member of staff took, the
 * unit opened to check a supplier complaint. Nothing else in the ERP has a shape
 * for that, which is why this screen exists rather than a stock adjustment with
 * a note on it.
 *
 * Opening one writes the stock off, so the form is deliberately short — a
 * counter assistant does this between customers, and a register that takes two
 * minutes to fill in is a register nobody fills in. The PAO defaults from the
 * article and is overridable, because the symbol on the pack in their hand wins.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Textarea from 'primevue/textarea'
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

const canCreate = computed(() => auth.can('cosmetic-shelf-life.create'))
const canEdit = computed(() => auth.can('cosmetic-shelf-life.edit'))
const canDelete = computed(() => auth.can('cosmetic-shelf-life.delete'))

const PURPOSES = [
  { label: 'Counter tester', value: 'tester' },
  { label: 'Demo unit', value: 'demo' },
  { label: 'Staff sample', value: 'staff' },
  { label: 'Quality check', value: 'quality' },
]

const STATUSES = [
  { label: 'Open', value: 'open' },
  { label: 'Used up', value: 'finished' },
  { label: 'Written off', value: 'discarded' },
]

const rows = ref<any[]>([])
const meta = ref<any>({ overdue: 0, total: 0 })
const loading = ref(false)
const statusFilter = ref<string | null>('open')
const purposeFilter = ref<string | null>(null)
const warehouseFilter = ref<number | null>(null)

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const productIndex = ref<Record<number, any>>({})

function purposeLabel(v: string) {
  return PURPOSES.find((p) => p.value === v)?.label ?? v
}

function iso(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/cosmetics/open-units', {
      params: {
        status: statusFilter.value || undefined,
        purpose: purposeFilter.value || undefined,
        warehouse_id: warehouseFilter.value || undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [p, w] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 200 } }),
  ])
  const list = p.data.data ?? p.data
  products.value = list.map((x: any) => ({ label: `${x.name} (${x.sku})`, value: x.id }))
  productIndex.value = Object.fromEntries(list.map((x: any) => [x.id, x]))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Opening a unit --------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({
  product_id: null,
  warehouse_id: null,
  purpose: 'tester',
  quantity: 1,
  opened_on: new Date(),
  pao_months: null,
  notes: '',
})

function openCreate() {
  errors.value = {}
  form.value = {
    product_id: null,
    warehouse_id: warehouses.value[0]?.value ?? null,
    purpose: 'tester',
    quantity: 1,
    opened_on: new Date(),
    pao_months: null,
    notes: '',
  }
  dialogVisible.value = true
}

/**
 * The PAO follows the article the moment one is picked. It is only a default —
 * the service snapshots whatever is submitted, so a pack whose symbol disagrees
 * with the product master can be recorded honestly.
 */
function onProductPicked() {
  const product = productIndex.value[form.value.product_id]
  form.value.pao_months = product?.pao_months ?? null
}

const paoPreview = computed(() => {
  const months = Number(form.value.pao_months)
  if (!months || !form.value.opened_on) return null
  const d = new Date(form.value.opened_on)
  d.setMonth(d.getMonth() + months)
  return iso(d)
})

async function save() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/cosmetics/open-units', {
      ...form.value,
      opened_on: iso(form.value.opened_on),
    })
    toast.add({ severity: 'success', summary: pt('Unit opened and written off stock.'), life: 3000 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data?.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not record that.'), life: 4500 })
  } finally {
    saving.value = false
  }
}

// ---- Closing a unit --------------------------------------------------------

/**
 * Two endings, and they are not the same one: used up is a tester that did its
 * job, written off is one binned with product still in it. Keeping them apart is
 * the only way a shop can tell "our testers work" from "we bin them twice a
 * year", so the screen asks rather than guessing from the date.
 */
async function close(row: any, status: 'finished' | 'discarded') {
  const res = await Swal.fire({
    title: status === 'finished' ? pt('Mark as used up?') : pt('Write this tester off?'),
    text:
      status === 'finished'
        ? pt('The unit was used up as intended. Stock left the shelf when it was opened, so nothing further is posted.')
        : pt('The unit is being binned with product still in it — usually because its period after opening has run out.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: status === 'finished' ? pt('Used up') : pt('Write off'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: status === 'finished' ? '#15803d' : '#b45309',
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/cosmetics/open-units/${row.id}/close`, { status })
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove this entry?'),
    text: pt('Only for a mis-keyed row. The stock write-off it made stays on the ledger.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/cosmetics/open-units/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

function statusTag(row: any) {
  if (row.status === 'finished') return { value: pt('Used up'), severity: 'secondary' }
  if (row.status === 'discarded') return { value: pt('Written off'), severity: 'contrast' }
  if (row.is_overdue) return { value: pt('Past PAO'), severity: 'danger' }
  if (row.days_left !== null && row.days_left <= 30) return { value: pt('{n} days left', { n: row.days_left }), severity: 'warn' }
  if (row.days_left === null) return { value: pt('No PAO'), severity: 'secondary' }
  return { value: pt('{n} days left', { n: row.days_left }), severity: 'success' }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Testers & Open Units')"
      :subtitle="pt('Stock that has left the shelf, will never be billed, and dies on the day it was opened plus its PAO')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Open a Unit')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Shown') }}</span>
        <span class="t-value">{{ meta.total ?? 0 }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Past their PAO') }}</span>
        <span class="t-value bad">{{ meta.overdue ?? 0 }}</span>
        <span class="t-note">{{ pt('should already be in the bin') }}</span>
      </div>
    </div>

    <div class="toolbar">
      <Select
        v-model="statusFilter"
        :options="[{ label: pt('All statuses'), value: null }, ...STATUSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Select
        v-model="purposeFilter"
        :options="[{ label: pt('All purposes'), value: null }, ...PURPOSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Select
        v-model="warehouseFilter"
        :options="[{ label: pt('All warehouses'), value: null }, ...warehouses]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <div class="spacer" />
      <TableExportButtons
        title="Cosmetics Testers"
        filenameBase="cosmetics-testers"
        :rows="rows"
        :columns="[
          { key: 'product_name', label: 'Article' },
          { key: 'product_sku', label: 'SKU' },
          { key: 'purpose', label: 'Opened as' },
          { key: 'opened_on', label: 'Opened' },
          { key: 'pao_months', label: 'PAO (months)' },
          { key: 'expires_on', label: 'Due' },
          { key: 'days_left', label: 'Days left' },
          { key: 'status', label: 'Status' },
          { key: 'warehouse_name', label: 'Warehouse' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" size="small" data-key="id" row-hover>
      <template #empty>
        <div class="empty-state">{{ pt('No units recorded for this filter.') }}</div>
      </template>

      <Column :header="pt('Article')">
        <template #body="{ data }">
          <div class="strong">{{ data.product_name }}</div>
          <div class="muted mono">
            {{ data.product_sku }}<template v-if="data.variant_name"> · {{ data.variant_name }}</template>
          </div>
        </template>
      </Column>
      <Column :header="pt('Opened as')">
        <template #body="{ data }"><Tag :value="pt(purposeLabel(data.purpose))" severity="secondary" /></template>
      </Column>
      <Column field="opened_on" :header="pt('Opened')" />
      <Column :header="pt('PAO')">
        <template #body="{ data }">{{ data.pao_months ? `${data.pao_months}M` : '—' }}</template>
      </Column>
      <Column field="expires_on" :header="pt('Due')" />
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag v-bind="statusTag(data)" /></template>
      </Column>
      <Column field="warehouse_name" :header="pt('Warehouse')" />
      <Column field="opened_by" :header="pt('By')" />
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <div class="actions">
            <template v-if="data.status === 'open' && canEdit">
              <Button
                icon="pi pi-check"
                text
                size="small"
                v-tooltip.top="pt('Used up')"
                @click="close(data, 'finished')"
              />
              <Button
                icon="pi pi-trash"
                text
                size="small"
                severity="warn"
                v-tooltip.top="pt('Write off')"
                @click="close(data, 'discarded')"
              />
            </template>
            <Button
              v-if="data.status === 'open' && canDelete"
              icon="pi pi-times"
              text
              size="small"
              severity="danger"
              v-tooltip.top="pt('Remove mis-keyed entry')"
              @click="remove(data)"
            />
            <span v-if="data.status !== 'open'" class="muted">{{ data.closed_on }}</span>
          </div>
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="pt('Open a Unit')" :style="{ width: '40rem' }">
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Article') }} *</label>
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
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Warehouse') }} *</label>
          <Select
            v-model="form.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            class="w-full"
          />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Opened as') }}</label>
          <Select
            v-model="form.purpose"
            :options="PURPOSES"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Quantity') }}</label>
          <InputNumber v-model="form.quantity" :min="0.001" :max-fraction-digits="3" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Opened on') }} *</label>
          <DatePicker v-model="form.opened_on" date-format="dd/mm/yy" :max-date="new Date()" class="w-full" />
          <small v-if="errors.opened_on" class="err">{{ errors.opened_on[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Period after opening (months)') }}</label>
          <InputNumber v-model="form.pao_months" :min="1" :max="120" class="w-full" />
          <small class="hint">
            {{ pt('Defaults from the article — the "6M" in the open-jar symbol. Override it if the pack says otherwise.') }}
          </small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
        </div>
      </div>

      <div class="preview">
        <template v-if="paoPreview">
          <i class="pi pi-clock" />
          {{ pt('This unit has to come off the counter by {d}.', { d: paoPreview }) }}
        </template>
        <template v-else>
          <i class="pi pi-info-circle" />
          {{ pt('No period after opening on this article, so it will sit in the register without a due date.') }}
        </template>
      </div>

      <div class="preview warn">
        <i class="pi pi-exclamation-triangle" />
        {{ pt('Saving writes the unit off stock as a sample. It cannot be sold afterwards.') }}
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button
          :label="pt('Open Unit')"
          :loading="saving"
          :disabled="!form.product_id || !form.warehouse_id"
          @click="save"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.bad { color: #b91c1c; }
.t-note { font-size: 0.7rem; color: #9ca3af; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1 1 auto; }
.actions { display: flex; gap: 0.15rem; align-items: center; }

.grid { display: grid; grid-template-columns: repeat(3, minmax(150px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.preview { margin-top: 0.9rem; font-size: 0.8rem; color: #374151; display: flex; gap: 0.45rem; align-items: center; }
.preview.warn { color: #b45309; }
.muted { color: #6b7280; font-size: 0.75rem; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
