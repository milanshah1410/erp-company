<script setup lang="ts">
/**
 * Happy hours — the menu price on a clock.
 *
 * Every row carries a live badge computed by the API using the same code the till
 * prices with, because the only question a manager asks of this screen is "is my
 * four-o'clock offer actually running?" — and answering that from the frontend's
 * own idea of the time would let the screen say yes while the bills said no.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
const toast = useToast()
const confirm = useConfirm()

const rows = ref<any[]>([])
const summary = ref({ offers: 0, active: 0, live_now: 0 })
const categories = ref<any[]>([])
const products = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)

const dialogVisible = ref(false)
const editing = ref<any>(null)
const errors = ref<Record<string, string[]>>({})

/** ISO-8601 day numbers, the same vocabulary the API stores. */
const DAYS = computed(() => [
  { label: pt('Mon'), value: 1 },
  { label: pt('Tue'), value: 2 },
  { label: pt('Wed'), value: 3 },
  { label: pt('Thu'), value: 4 },
  { label: pt('Fri'), value: 5 },
  { label: pt('Sat'), value: 6 },
  { label: pt('Sun'), value: 7 },
])

const SCOPES = computed(() => [
  { label: pt('Whole menu'), value: 'all' },
  { label: pt('One category'), value: 'category' },
  { label: pt('Picked items'), value: 'products' },
])

const APPLIES = computed(() => [
  { label: pt('All order types'), value: 'all' },
  { label: pt('Dine-in only'), value: 'dine_in' },
  { label: pt('Takeaway only'), value: 'takeaway' },
  { label: pt('Delivery only'), value: 'delivery' },
])

const DISCOUNTS = computed(() => [
  { label: pt('Percent off'), value: 'percent' },
  { label: pt('Flat amount off'), value: 'flat' },
])

const blank = () => ({
  name: '',
  days: [1, 2, 3, 4, 5],
  start_time: '16:00',
  end_time: '19:00',
  valid_from: null as Date | null,
  valid_to: null as Date | null,
  scope: 'all',
  category_id: null as number | null,
  discount_type: 'percent',
  discount_value: 20,
  applies_to: 'all',
  priority: 0,
  is_active: true,
  product_ids: [] as number[],
})

const form = ref(blank())

function dayLabels(days: number[]) {
  if (!days?.length) return pt('Every day')
  return days
    .slice()
    .sort()
    .map((d) => DAYS.value.find((x) => x.value === d)?.label ?? d)
    .join(', ')
}

function ymd(d: Date | null) {
  if (!d) return null
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/menu-happy-hours')
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [cats, prods] = await Promise.all([
    http.get('/categories', { params: { per_page: 200 } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
  ])
  categories.value = cats.data.data
  products.value = prods.data.data
}

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    name: row.name,
    days: row.days ?? [],
    start_time: row.start_time,
    end_time: row.end_time,
    valid_from: row.valid_from ? new Date(row.valid_from) : null,
    valid_to: row.valid_to ? new Date(row.valid_to) : null,
    scope: row.scope,
    category_id: row.category_id,
    discount_type: row.discount_type,
    discount_value: Number(row.discount_value),
    applies_to: row.applies_to,
    priority: row.priority,
    is_active: row.is_active,
    product_ids: row.product_ids ?? [],
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      valid_from: ymd(form.value.valid_from),
      valid_to: ymd(form.value.valid_to),
    }

    if (editing.value) {
      await http.put(`/menu-happy-hours/${editing.value.id}`, payload)
    } else {
      await http.post('/menu-happy-hours', payload)
    }

    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Offer saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not save the offer'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

function remove(row: any) {
  confirm.require({
    message: pt('Delete this offer? Bills it already priced are not changed.'),
    header: pt('Delete offer'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      await http.delete(`/menu-happy-hours/${row.id}`)
      await load()
      toast.add({ severity: 'success', summary: pt('Offer deleted'), life: 2500 })
    },
  })
}

const productOptions = computed(() =>
  products.value.map((p) => ({ label: `${p.sku} — ${p.name}`, value: p.id })),
)

const categoryOptions = computed(() =>
  categories.value.map((c) => ({ label: c.name, value: c.id })),
)

onMounted(() => {
  load()
  loadRefs()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Happy Hours')"
      :subtitle="pt('Evaluated when a ticket is priced — nobody has to remember to switch an offer on')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Offers') }}</span>
        <span class="t-value">{{ summary.offers }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.live_now > 0 }">
        <span class="t-label">{{ pt('Running now') }}</span>
        <span class="t-value">{{ summary.live_now }}</span>
      </div>
    </div>

    <div class="toolbar">
      <Button icon="pi pi-plus" :label="pt('New Offer')" @click="openCreate" />
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="name" :header="pt('Offer')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.name }}</strong>
            <small class="muted">{{ dayLabels(data.days) }}</small>
          </div>
        </template>
      </Column>
      <Column :header="pt('Window')">
        <template #body="{ data }">
          {{ data.start_time }}–{{ data.end_time }}
          <Tag v-if="data.crosses_midnight" :value="pt('overnight')" severity="secondary" class="tiny" />
        </template>
      </Column>
      <Column :header="pt('Applies to')">
        <template #body="{ data }">
          <div class="stack">
            <span>
              {{ data.scope === 'all' ? pt('Whole menu') : data.scope === 'category' ? data.category : pt('{n} items').replace('{n}', String(data.products_count)) }}
            </span>
            <small class="muted">{{ pt(data.applies_to) }}</small>
          </div>
        </template>
      </Column>
      <Column :header="pt('Discount')">
        <template #body="{ data }">
          {{ data.discount_type === 'percent' ? data.discount_value + '%' : data.discount_value }}
        </template>
      </Column>
      <Column field="priority" :header="pt('Priority')" sortable />
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag v-if="data.is_live_now" :value="pt('Live now')" severity="success" />
          <Tag v-else-if="data.is_active" :value="pt('Waiting')" severity="secondary" />
          <Tag v-else :value="pt('Off')" severity="danger" />
        </template>
      </Column>
      <Column :header="pt('Actions')">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button icon="pi pi-trash" text severity="danger" size="small" @click="remove(data)" />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No happy hours configured.') }}</div>
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Offer') : pt('New Offer')"
      :style="{ width: '40rem' }"
    >
      <div class="form">
        <div class="field">
          <label>{{ pt('Name') }}</label>
          <InputText v-model="form.name" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Days') }}</label>
          <MultiSelect
            v-model="form.days"
            :options="DAYS"
            option-label="label"
            option-value="value"
            display="chip"
          />
          <small v-if="errors.days" class="err">{{ errors.days[0] }}</small>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('From') }}</label>
            <InputText v-model="form.start_time" placeholder="16:00" />
            <small v-if="errors.start_time" class="err">{{ errors.start_time[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('To') }}</label>
            <InputText v-model="form.end_time" placeholder="19:00" />
            <small class="hint">{{ pt('An end before the start runs through midnight') }}</small>
            <small v-if="errors.end_time" class="err">{{ errors.end_time[0] }}</small>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Discount') }}</label>
            <Select v-model="form.discount_type" :options="DISCOUNTS" option-label="label" option-value="value" />
          </div>
          <div class="field">
            <label>{{ pt('Value') }}</label>
            <InputNumber v-model="form.discount_value" :min="0" :max-fraction-digits="2" />
            <small v-if="errors.discount_value" class="err">{{ errors.discount_value[0] }}</small>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Scope') }}</label>
          <Select v-model="form.scope" :options="SCOPES" option-label="label" option-value="value" />
        </div>

        <div v-if="form.scope === 'category'" class="field">
          <label>{{ pt('Category') }}</label>
          <Select
            v-model="form.category_id"
            :options="categoryOptions"
            option-label="label"
            option-value="value"
            filter
          />
          <small v-if="errors.category_id" class="err">{{ errors.category_id[0] }}</small>
        </div>

        <div v-if="form.scope === 'products'" class="field">
          <label>{{ pt('Items') }}</label>
          <MultiSelect
            v-model="form.product_ids"
            :options="productOptions"
            option-label="label"
            option-value="value"
            filter
            display="chip"
          />
          <small v-if="errors.product_ids" class="err">{{ errors.product_ids[0] }}</small>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Order types') }}</label>
            <Select v-model="form.applies_to" :options="APPLIES" option-label="label" option-value="value" />
          </div>
          <div class="field">
            <label>{{ pt('Priority') }}</label>
            <InputNumber v-model="form.priority" :min="0" />
            <small class="hint">{{ pt('Highest wins when two offers overlap') }}</small>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Runs from') }}</label>
            <DatePicker v-model="form.valid_from" date-format="yy-mm-dd" show-icon show-button-bar />
          </div>
          <div class="field">
            <label>{{ pt('Runs until') }}</label>
            <DatePicker v-model="form.valid_to" date-format="yy-mm-dd" show-icon show-button-bar />
          </div>
        </div>

        <div class="field-inline">
          <Checkbox v-model="form.is_active" binary input-id="hh-active" />
          <label for="hh-active">{{ pt('Active') }}</label>
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
.tile.accent { border-color: #16a34a; background: rgba(22, 163, 74, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.tiny { font-size: 0.65rem; margin-left: 0.35rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { font-size: 0.7rem; color: #9ca3af; }
.err { color: #dc2626; font-size: 0.75rem; }
</style>
