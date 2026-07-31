<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/service-contracts')

const canCreate = computed(() => auth.can('service-contracts.create'))
const canEdit = computed(() => auth.can('service-contracts.edit'))
const canDelete = computed(() => auth.can('service-contracts.delete'))

const customers = ref<any[]>([])
const search = ref('')
const statusFilter = ref<string | null>(null)
const expiringOnly = ref(false)

const STATUSES = [
  { label: 'Active', value: 'active' },
  { label: 'Expired', value: 'expired' },
  { label: 'Cancelled', value: 'cancelled' },
]
const statusColors: Record<string, string> = {
  active: 'success',
  expired: 'secondary',
  cancelled: 'danger',
}

function money(v: any) {
  return formatCurrency(v)
}

function load() {
  return crud.fetch({
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    // "Expiring soon" is the renewal-chasing view; 30 days is the usual window.
    expiring_within_days: expiringOnly.value ? 30 : undefined,
  })
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 200, is_active: true } })
  customers.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  customer_id: null as number | null,
  title: '',
  start_date: new Date() as Date | null,
  end_date: null as Date | null,
  visits_allowed: 0,
  amount: 0,
  status: 'active',
  notes: '',
})
const form = ref(blank())

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    customer_id: row.customer_id,
    title: row.title,
    start_date: row.start_date ? new Date(row.start_date) : null,
    end_date: row.end_date ? new Date(row.end_date) : null,
    visits_allowed: row.visits_allowed,
    amount: row.amount,
    status: row.status,
    notes: row.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      start_date: iso(form.value.start_date),
      end_date: iso(form.value.end_date),
    }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Contract saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the contract'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this contract?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Contract deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => {
  await loadCustomers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('AMC Contracts')"
      :subtitle="pt('Annual maintenance contracts — jobs booked under a live contract are worked but not billed')"
    />

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Contract no, title or customer')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="filter"
        @change="load"
      />
      <label class="inline-check">
        <input v-model="expiringOnly" type="checkbox" @change="load" />
        {{ pt('Expiring in 30 days') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Contract')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="AMC Contracts"
        filenameBase="amc-contracts"
        :rows="crud.items.value"
        :columns="[
          { key: 'contract_number', label: 'Contract No' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'title', label: 'Title' },
          { key: 'start_date', label: 'Start' },
          { key: 'end_date', label: 'End' },
          { key: 'visits_used', label: 'Visits Used' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Contract')">
        <template #body="{ data }">
          <strong>{{ data.contract_number }}</strong>
          <br /><small class="muted">{{ data.title }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">
          {{ data.customer?.name }}
          <br /><small class="muted">{{ data.customer?.phone }}</small>
        </template>
      </Column>
      <Column :header="pt('Term')">
        <template #body="{ data }">
          {{ data.start_date }} → {{ data.end_date }}
          <br />
          <small v-if="data.days_to_expiry !== null" :class="data.days_to_expiry < 0 ? 'over' : 'muted'">
            {{
              data.days_to_expiry < 0
                ? pt('Expired {n} day(s) ago', { n: Math.abs(data.days_to_expiry) })
                : pt('{n} day(s) left', { n: data.days_to_expiry })
            }}
          </small>
        </template>
      </Column>
      <Column :header="pt('Visits')">
        <template #body="{ data }">
          <template v-if="data.visits_remaining === null">
            {{ data.visits_used }} / {{ pt('unlimited') }}
          </template>
          <template v-else>
            {{ data.visits_used }} / {{ data.visits_allowed }}
            <br /><small class="muted">{{ pt('{n} left', { n: data.visits_remaining }) }}</small>
          </template>
        </template>
      </Column>
      <Column :header="pt('Amount')">
        <template #body="{ data }">{{ money(data.amount) }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="statusColors[data.status]" />
          <Tag v-if="!data.covers_today && data.status === 'active'" :value="pt('Not usable')" severity="warn" class="ml-1" />
        </template>
      </Column>
      <Column :header="pt('Jobs')">
        <template #body="{ data }">{{ data.jobs_count ?? 0 }}</template>
      </Column>
      <Column :header="pt('Actions')" style="width: 7rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No contracts match this view.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Contract') : pt('New Contract')"
      :style="{ width: '40rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Customer') }} *</label>
          <Select v-model="form.customer_id" :options="customers" optionLabel="label" optionValue="value" filter class="w-full" />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Title') }} *</label>
          <InputText v-model="form.title" class="w-full" :placeholder="pt('e.g. AC AMC 2026-27')" />
          <small v-if="errors.title" class="err">{{ errors.title[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Start date') }} *</label>
          <DatePicker v-model="form.start_date" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('End date') }} *</label>
          <DatePicker v-model="form.end_date" dateFormat="yy-mm-dd" class="w-full" />
          <small v-if="errors.end_date" class="err">{{ errors.end_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Visits allowed') }}</label>
          <InputNumber v-model="form.visits_allowed" :min="0" class="w-full" />
          <small class="hint">{{ pt('0 means unlimited visits for the term.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Contract amount') }}</label>
          <InputNumber v-model="form.amount" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <Select v-model="form.status" :options="STATUSES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.filter { width: 180px; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.over { color: #b91c1c; }
.ml-1 { margin-left: 0.25rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
</style>
