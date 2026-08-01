<script setup lang="ts">
/**
 * The morning round sheet.
 *
 * Pre-filled from the standing orders and corrected in place, because a round of
 * two hundred houses cannot be keyed from nothing every day — the person walking it
 * touches the exceptions and leaves the rest, which is why an untouched row counts
 * as delivered.
 *
 * Rows already billed are locked and shown as such. Editing them is refused by the
 * API; showing them greyed rather than hiding them is what stops somebody
 * re-keying a day they think went missing.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()

const rows = ref<any[]>([])
const totals = ref({ doorsteps: 0, litres: 0, amount: 0, skipped: 0 })
const routes = ref<any[]>([])
const loading = ref(false)
const generating = ref(false)

const date = ref<Date>(new Date())
const routeFilter = ref<number | null>(null)
const shiftFilter = ref<string | null>(null)

/** Edits held per row id until saved, so a mis-tap does not fire a request. */
const drafts = ref<Record<number, { quantity: number; status: string; remarks: string }>>({})

const SHIFTS = computed(() => [
  { label: pt('Morning'), value: 'morning' },
  { label: pt('Evening'), value: 'evening' },
])

const STATUSES = computed(() => [
  { label: pt('Delivered'), value: 'delivered' },
  { label: pt('Skipped'), value: 'skipped' },
  { label: pt('Extra'), value: 'extra' },
])

const SEVERITY: Record<string, string> = { delivered: 'success', skipped: 'secondary', extra: 'info' }

function ymd(d: Date) {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/milk-round', {
      params: {
        date: ymd(date.value),
        delivery_route_id: routeFilter.value || undefined,
        shift: shiftFilter.value || undefined,
      },
    })
    rows.value = data.data
    totals.value = data.totals

    drafts.value = {}
    for (const row of data.data) {
      drafts.value[row.id] = {
        quantity: Number(row.quantity),
        status: row.status,
        remarks: row.remarks ?? '',
      }
    }
  } finally {
    loading.value = false
  }
}

async function loadRoutes() {
  try {
    const { data } = await http.get('/delivery-routes', { params: { per_page: 200 } })
    routes.value = data.data
  } catch {
    routes.value = []
  }
}

async function generate() {
  generating.value = true
  try {
    const { data } = await http.post('/milk-round/generate', {
      date: ymd(date.value),
      delivery_route_id: routeFilter.value || undefined,
    })
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Could not write the sheet'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    generating.value = false
  }
}

async function save(row: any) {
  const draft = drafts.value[row.id]
  if (!draft) return

  try {
    await http.post(`/milk-round/${row.id}/record`, {
      quantity: draft.quantity,
      status: draft.status,
      remarks: draft.remarks || undefined,
    })
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Could not record the delivery'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  }
}

/** Has this row been touched since it was loaded? Drives the save button. */
function isDirty(row: any) {
  const draft = drafts.value[row.id]
  if (!draft) return false

  return (
    Number(draft.quantity) !== Number(row.quantity) ||
    draft.status !== row.status ||
    (draft.remarks || '') !== (row.remarks || '')
  )
}

/** Marking a skip zeroes the quantity, the way the API will anyway. */
function onStatusChange(row: any) {
  const draft = drafts.value[row.id]
  if (draft?.status === 'skipped') draft.quantity = 0
}

const routeOptions = computed(() => routes.value.map((r) => ({ label: r.name, value: r.id })))

onMounted(() => {
  load()
  loadRoutes()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Milk Round')"
      :subtitle="pt('Pre-filled from the standing orders — correct the exceptions and leave the rest')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Doorsteps') }}</span>
        <span class="t-value">{{ totals.doorsteps }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Litres') }}</span>
        <span class="t-value">{{ totals.litres }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value') }}</span>
        <span class="t-value">{{ formatCurrency(totals.amount) }}</span>
      </div>
      <div class="tile" :class="{ accent: totals.skipped > 0 }">
        <span class="t-label">{{ pt('Skipped') }}</span>
        <span class="t-value">{{ totals.skipped }}</span>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="date" date-format="yy-mm-dd" show-icon @date-select="load" />
      <Select
        v-model="routeFilter"
        :options="routeOptions"
        option-label="label"
        option-value="value"
        :placeholder="pt('All routes')"
        show-clear
        @change="load"
      />
      <Select
        v-model="shiftFilter"
        :options="SHIFTS"
        option-label="label"
        option-value="value"
        :placeholder="pt('Both shifts')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-refresh" text :label="pt('Reload')" :loading="loading" @click="load" />
      <Button icon="pi pi-list" :label="pt('Write Sheet')" :loading="generating" @click="generate" />
      <TableExportButtons
        title="Milk Round"
        filenameBase="milk-round"
        :rows="rows"
        :columns="[
          { key: 'customer', label: 'Customer' },
          { key: 'phone', label: 'Phone' },
          { key: 'address', label: 'Address' },
          { key: 'product', label: 'Product' },
          { key: 'shift', label: 'Shift' },
          { key: 'quantity', label: 'Litres' },
          { key: 'rate', label: 'Rate' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="customer" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.customer }}</strong>
            <small class="muted">{{ data.address || data.phone }}</small>
          </div>
        </template>
      </Column>
      <Column field="product" :header="pt('Product')">
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.product }}</span>
            <small class="muted">{{ pt(data.shift) }} · {{ data.subscription_number }}</small>
          </div>
        </template>
      </Column>
      <Column :header="pt('Left at the door')" style="width: 11rem">
        <template #body="{ data }">
          <InputNumber
            v-if="!data.is_billed"
            v-model="drafts[data.id].quantity"
            :min="0"
            :max-fraction-digits="3"
            size="small"
            fluid
          />
          <span v-else>{{ data.quantity }}</span>
        </template>
      </Column>
      <Column :header="pt('Status')" style="width: 11rem">
        <template #body="{ data }">
          <Select
            v-if="!data.is_billed"
            v-model="drafts[data.id].status"
            :options="STATUSES"
            option-label="label"
            option-value="value"
            size="small"
            fluid
            @change="onStatusChange(data)"
          />
          <Tag v-else :value="pt(data.status)" :severity="SEVERITY[data.status]" />
        </template>
      </Column>
      <Column :header="pt('Remarks')">
        <template #body="{ data }">
          <InputText v-if="!data.is_billed" v-model="drafts[data.id].remarks" size="small" fluid />
          <span v-else class="muted">{{ data.remarks || '—' }}</span>
        </template>
      </Column>
      <Column field="amount" :header="pt('Amount')" sortable>
        <template #body="{ data }">
          {{ formatCurrency(data.amount) }}
          <Tag v-if="data.is_billed" :value="pt('billed')" severity="info" class="tiny" />
        </template>
      </Column>
      <Column :header="pt('Save')" style="width: 6rem">
        <template #body="{ data }">
          <Button
            v-if="!data.is_billed"
            icon="pi pi-check"
            text
            size="small"
            :disabled="!isDirty(data)"
            v-tooltip.top="pt('Record')"
            @click="save(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing on the sheet for that day. Press “Write Sheet” to generate it from the standing orders.') }}
        </div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.tiny { font-size: 0.65rem; margin-left: 0.35rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
