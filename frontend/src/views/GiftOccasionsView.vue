<script setup lang="ts">
/**
 * The occasion board — the dates this shop's customers buy for.
 *
 * A gift shop's repeat business is one phone call: "your wife's birthday is in
 * nine days, shall I put something aside?" So the screen is a countdown, not a
 * register. It sorts by how soon a date comes round, leads with the number of
 * calls owed today, and puts last year's gift and this year's budget on the row —
 * because those two facts are what make the call work.
 *
 * Note what these are *not*: the customer's own birthday, which core already
 * tracks and mentions on the morning (CRM → Occasions). These belong to wives,
 * mothers, children and bosses, and they are announced days early — a gift has to
 * be chosen, and often ordered.
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
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('gift-occasions.create'))
const canEdit = computed(() => auth.can('gift-occasions.edit'))
const canDelete = computed(() => auth.can('gift-occasions.delete'))

const OCCASIONS = [
  { label: 'Birthday', value: 'birthday' },
  { label: 'Anniversary', value: 'anniversary' },
  { label: 'Wedding', value: 'wedding' },
  { label: 'Festival', value: 'festival' },
  { label: 'Graduation', value: 'graduation' },
  { label: 'Housewarming', value: 'housewarming' },
  { label: 'Other', value: 'other' },
]

const WINDOWS = [
  { label: 'Next 7 days', value: '7' },
  { label: 'Next 30 days', value: '30' },
  { label: 'Next 90 days', value: '90' },
  { label: 'Everything', value: '' },
]

const rows = ref<any[]>([])
const summary = ref({ tracked: 0, active: 0, today: 0, next_7_days: 0, next_30_days: 0, reminder_due: 0 })
const loading = ref(false)
const search = ref('')
const occasionFilter = ref<string | null>(null)
const windowDays = ref<string>('30')
const dueOnly = ref(false)

const customers = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/gift/occasions', {
      params: {
        search: search.value || undefined,
        occasion: occasionFilter.value || undefined,
        within_days: windowDays.value || undefined,
        reminder_due: dueOnly.value ? 1 : undefined,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const { data } = await http.get('/customers', { params: { per_page: 500, is_active: 1 } })
  customers.value = (data.data ?? data).map((x: any) => ({
    label: `${x.name} — ${x.phone ?? ''}`.trim(),
    value: x.id,
  }))
}

// ---- Add / edit -----------------------------------------------------------

const formVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const editingId = ref<number | null>(null)
const form = ref<any>(blank())

function blank() {
  return {
    customer_id: null,
    person_name: '',
    relation: '',
    occasion: 'birthday',
    title: '',
    occasion_date: null as Date | null,
    is_recurring: true,
    // A week: long enough to order something in, short enough that the reminder
    // still feels like news.
    remind_days_before: 7,
    budget: null as number | null,
    preferences: '',
    last_gift: '',
    last_gifted_on: null as Date | null,
    notify_customer: true,
    notes: '',
    is_active: true,
  }
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  formVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/gift/occasions/${row.id}`)
  const d = data.data
  editingId.value = d.id
  errors.value = {}
  form.value = {
    customer_id: d.customer_id,
    person_name: d.person_name ?? '',
    relation: d.relation ?? '',
    occasion: d.occasion,
    title: d.title ?? '',
    occasion_date: d.occasion_date ? new Date(d.occasion_date) : null,
    is_recurring: !!d.is_recurring,
    remind_days_before: d.remind_days_before,
    budget: d.budget,
    preferences: d.preferences ?? '',
    last_gift: d.last_gift ?? '',
    last_gifted_on: d.last_gifted_on ? new Date(d.last_gifted_on) : null,
    notify_customer: !!d.notify_customer,
    notes: d.notes ?? '',
    is_active: !!d.is_active,
  }
  formVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      occasion_date: asDate(form.value.occasion_date),
      last_gifted_on: asDate(form.value.last_gifted_on),
    }
    editingId.value
      ? await http.put(`/gift/occasions/${editingId.value}`, payload)
      : await http.post('/gift/occasions', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Occasion updated' : 'Occasion added'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the occasion'), life: 3500 })
  } finally {
    saving.value = false
  }
}

async function remind(row: any) {
  const res = await Swal.fire({
    title: pt('Remind now?'),
    text: pt('The counter is notified, and the customer is texted if this occasion allows it. Sending now stops tonight’s run repeating it.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Send'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/gift/occasions/${row.id}/remind`)
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function importFromCustomers() {
  const res = await Swal.fire({
    title: pt('Bring dates in from the customer master?'),
    text: pt('Birthdays and anniversaries already on customer records are added to the board. Anything already here is left alone.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Import'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post('/gift/occasions/import')
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Remove this occasion?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/gift/occasions/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

function asDate(v: any): string | undefined {
  if (!v) return undefined
  const d = v instanceof Date ? v : new Date(v)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** The countdown, said the way a shopkeeper says it. */
function countdown(row: any) {
  const d = row.days_until
  if (d === null || d === undefined) return '—'
  if (d < 0) return pt('passed')
  if (d === 0) return pt('today')
  if (d === 1) return pt('tomorrow')
  return `${d} ${pt('days')}`
}

function countdownSeverity(row: any) {
  if (row.reminder_due) return 'danger'
  if (row.days_until !== null && row.days_until <= 7) return 'warn'
  return 'secondary'
}

const exportColumns: ExportColumn[] = [
  { key: 'customer', label: 'Customer' },
  { key: 'person', label: 'For' },
  { key: 'occasion_label', label: 'Occasion' },
  { key: 'next_occurrence', label: 'Next' },
  { key: 'days_until', label: 'Days away' },
  { key: 'budget', label: 'Budget' },
  { key: 'last_gift', label: 'Last gift' },
  { key: 'last_reminded_on', label: 'Last reminded' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Gift Occasions')" :subtitle="pt('Whose date is next, and who we owe a call')">
      <template #actions>
        <Button
          v-if="canCreate"
          :label="pt('Import Dates')"
          icon="pi pi-download"
          severity="secondary"
          outlined
          @click="importFromCustomers"
        />
        <Button v-if="canCreate" :label="pt('Add Occasion')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Calls owed now') }}</span>
        <span class="t-value" :class="{ danger: summary.reminder_due > 0 }">{{ summary.reminder_due }}</span>
        <small class="muted">{{ pt('inside their lead time') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Today') }}</span>
        <span class="t-value">{{ summary.today }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Next 7 days') }}</span>
        <span class="t-value">{{ summary.next_7_days }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Next 30 days') }}</span>
        <span class="t-value">{{ summary.next_30_days }}</span>
        <small class="muted">{{ summary.active }} {{ pt('tracked') }}</small>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search person, customer or last gift')" @keyup.enter="load" />
      <Select
        v-model="occasionFilter"
        :options="OCCASIONS"
        option-label="label"
        option-value="value"
        showClear
        :placeholder="pt('Any occasion')"
        @change="load"
      />
      <Select
        v-model="windowDays"
        :options="WINDOWS"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="dueOnly" binary @change="load" />
        <span>{{ pt('Calls owed only') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="gift-occasions" :title="pt('Gift Occasions')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="days_until" :header="pt('When')" sortable style="width: 8rem">
        <template #body="{ data }">
          <Tag :value="countdown(data)" :severity="countdownSeverity(data)" />
        </template>
      </Column>
      <Column field="person" :header="pt('For')" sortable>
        <template #body="{ data }">
          <div>{{ data.person }}</div>
          <small class="muted">{{ data.occasion_label }}</small>
        </template>
      </Column>
      <Column field="customer" :header="pt('Customer')" sortable>
        <template #body="{ data }">
          <div>{{ data.customer }}</div>
          <small class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>
      <Column field="next_occurrence" :header="pt('Next')" sortable>
        <template #body="{ data }">
          <div>{{ data.next_occurrence }}</div>
          <small class="muted">
            {{ data.is_recurring ? pt('every year') : pt('once') }}
          </small>
        </template>
      </Column>
      <Column field="budget" :header="pt('Budget')" sortable>
        <template #body="{ data }">{{ data.budget ? formatCurrency(data.budget) : '—' }}</template>
      </Column>
      <Column field="last_gift" :header="pt('Last gift')">
        <template #body="{ data }">
          <span v-if="data.last_gift">{{ data.last_gift }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="last_reminded_on" :header="pt('Reminded')" sortable>
        <template #body="{ data }">
          <span v-if="data.last_reminded_on">{{ data.last_reminded_on }}</span>
          <span v-else class="muted">{{ pt('never') }}</span>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 10rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.days_until !== null && data.days_until >= 0"
            icon="pi pi-send"
            text
            rounded
            :severity="data.reminder_due ? 'danger' : 'secondary'"
            :title="pt('Remind now')"
            @click="remind(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
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
          {{ pt('Nothing on the board. Add the dates your regulars buy for — a wife’s birthday, a mother’s anniversary — and the shop gets told a week before, not on the morning.') }}
        </div>
      </template>
    </DataTable>

    <!-- Add / edit -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Edit Occasion') : pt('Add Occasion')"
      :style="{ width: '780px' }"
    >
      <Message severity="info" :closable="false" class="mb">
        {{ pt('Whose date is it? Leave the person blank only if it is the customer’s own — the ones worth recording usually belong to somebody else.') }}
      </Message>

      <div class="grid-form">
        <div class="fld span-2">
          <label>{{ pt('Customer') }} *</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('Who buys the gift?')"
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Whose occasion') }}</label>
          <InputText v-model="form.person_name" class="w-full" :placeholder="pt('Blank = the customer themself')" />
        </div>
        <div class="fld">
          <label>{{ pt('Relation') }}</label>
          <InputText v-model="form.relation" class="w-full" :placeholder="pt('Wife, Mother, Son, Boss')" />
        </div>

        <div class="fld">
          <label>{{ pt('Occasion') }}</label>
          <Select
            v-model="form.occasion"
            :options="OCCASIONS"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Name it (optional)') }}</label>
          <InputText v-model="form.title" class="w-full" :placeholder="pt('Diwali, Silver Jubilee')" />
        </div>

        <div class="fld">
          <label>{{ pt('Date') }} *</label>
          <DatePicker v-model="form.occasion_date" date-format="dd/mm/yy" show-icon class="w-full" />
          <small class="hint">{{ pt('The real date — the year they were born or married. What recurs is worked out from it.') }}</small>
          <small v-if="errors.occasion_date" class="err">{{ errors.occasion_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Tell us how many days before') }}</label>
          <InputNumber v-model="form.remind_days_before" :min="0" :max="90" class="w-full" />
          <small class="hint">{{ pt('A gift has to be chosen, and sometimes ordered. Seven days is the trade default.') }}</small>
        </div>

        <label class="chk">
          <Checkbox v-model="form.is_recurring" binary />
          <span>{{ pt('Comes round every year') }}</span>
        </label>
        <div class="fld">
          <label>{{ pt('Text the customer as well') }}</label>
          <div class="switch-row">
            <ToggleSwitch v-model="form.notify_customer" />
            <small class="hint">{{ pt('Off means the counter is told and the customer is not.') }}</small>
          </div>
        </div>

        <div class="fld">
          <label>{{ pt('Budget they usually spend') }}</label>
          <InputNumber v-model="form.budget" :minFractionDigits="2" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Gifted last time') }}</label>
          <DatePicker v-model="form.last_gifted_on" date-format="dd/mm/yy" show-icon class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('What they were given last time') }}</label>
          <InputText v-model="form.last_gift" class="w-full" :placeholder="pt('Silver photo frame — do not repeat it')" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('What they like') }}</label>
          <Textarea v-model="form.preferences" rows="2" class="w-full" :placeholder="pt('Likes perfume and silver. No chocolates — allergic.')" />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
        <label class="chk span-2">
          <Checkbox v-model="form.is_active" binary />
          <span>{{ pt('Active (on the board and reminded)') }}</span>
        </label>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="formVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.danger { color: #b91c1c; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.switch-row { display: flex; gap: 0.6rem; align-items: center; }

.muted { color: #6b7280; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
