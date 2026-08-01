<script setup lang="ts">
/**
 * Daily production — the farm's book.
 *
 * Keyed twice a day by whoever is in the shed, so the entry form sits at the top
 * of the screen rather than behind a button: the common case is "log this
 * milking", not "browse past milkings".
 *
 * Saving an entry moves stock — produce in, feed out — and the screen says so,
 * because a milker changing a quantity is changing inventory. Deleting an entry
 * reverses those movements, which is why the confirmation spells that out instead
 * of asking a generic "are you sure".
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
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('farm-production.create'))
const canEdit = computed(() => auth.can('farm-production.edit'))
const canDelete = computed(() => auth.can('farm-production.delete'))

const SESSIONS = [
  { label: 'Morning', value: 'morning' },
  { label: 'Evening', value: 'evening' },
  { label: 'Whole day', value: 'day' },
]

const rows = ref<any[]>([])
const summary = ref<any>({ by_product: [], feed_quantity: 0, mortality: 0, culled: 0, days_recorded: 0, from: '', to: '' })
const loading = ref(false)

const animals = ref<any[]>([])
const flocks = ref<any[]>([])
const products = ref<any[]>([])
const warehouses = ref<any[]>([])

const filterFrom = ref<Date | null>(null)
const filterTo = ref<Date | null>(null)
const filterSource = ref<string | null>(null)

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/farm-production', {
      params: {
        from: iso(filterFrom.value) || undefined,
        to: iso(filterTo.value) || undefined,
        source: filterSource.value || undefined,
        per_page: 200,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [a, f, p, w] = await Promise.all([
    // Only what is still on the farm: logging output against a sold cow is a
    // keying slip, and leaving her in the list invites it.
    http.get('/livestock-animals', { params: { per_page: 200, on_farm: 1 } }),
    http.get('/livestock-flocks', { params: { per_page: 200, status: 'active' } }),
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 200 } }),
  ])
  animals.value = a.data.data.map((x: any) => ({
    label: `${x.tag_number}${x.name ? ' · ' + x.name : ''}`,
    value: x.id,
    warehouse_id: x.warehouse_id,
  }))
  flocks.value = f.data.data.map((x: any) => ({
    label: `${x.batch_code} · ${x.bird_type}`,
    value: x.id,
    warehouse_id: x.warehouse_id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    agri_input_type: x.agri_input_type,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

/** Feed pickers list feed products first, but never hide the rest. */
const feedProducts = computed(() => [
  ...products.value.filter((p) => p.agri_input_type === 'feed'),
  ...products.value.filter((p) => p.agri_input_type !== 'feed'),
])

// ---- Entry ----------------------------------------------------------------

const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  log_date: new Date(),
  session: 'morning',
  source_type: 'animal' as 'animal' | 'flock',
  animal_id: null as number | null,
  flock_id: null as number | null,
  product_id: null as number | null,
  warehouse_id: null as number | null,
  quantity: null as number | null,
  unit_cost: null as number | null,
  fat_percent: null as number | null,
  snf_percent: null as number | null,
  feed_product_id: null as number | null,
  feed_quantity: null as number | null,
  mortality_count: 0,
  culled_count: 0,
  remarks: '',
})

const form = ref(blank())

const isFlock = computed(() => form.value.source_type === 'flock')

/**
 * Default the warehouse to the shed the animal or batch belongs to, so the
 * commonest entry is two taps and produce cannot silently pool into the wrong
 * store on a two-site farm.
 */
function onSourceChange() {
  const source = isFlock.value
    ? flocks.value.find((f) => f.value === form.value.flock_id)
    : animals.value.find((a) => a.value === form.value.animal_id)
  if (source?.warehouse_id && !form.value.warehouse_id) {
    form.value.warehouse_id = source.warehouse_id
  }
}

function switchSource(type: 'animal' | 'flock') {
  form.value.source_type = type
  form.value.animal_id = null
  form.value.flock_id = null
  if (type === 'animal') {
    // Mortality belongs to a flock; an animal's death is a status on its record.
    form.value.mortality_count = 0
    form.value.culled_count = 0
  }
}

function reset() {
  const keep = {
    log_date: form.value.log_date,
    session: form.value.session,
    source_type: form.value.source_type,
    product_id: form.value.product_id,
    warehouse_id: form.value.warehouse_id,
    feed_product_id: form.value.feed_product_id,
  }
  form.value = { ...blank(), ...keep }
  editingId.value = null
  errors.value = {}
}

function parse(value: string | null) {
  return value ? new Date(value + 'T00:00:00') : null
}

function openEdit(row: any) {
  editingId.value = row.id
  errors.value = {}
  form.value = {
    log_date: parse(row.log_date) ?? new Date(),
    session: row.session,
    source_type: row.source_type,
    animal_id: row.animal_id,
    flock_id: row.flock_id,
    product_id: row.product_id,
    warehouse_id: row.warehouse_id,
    quantity: row.quantity,
    unit_cost: row.unit_cost,
    fat_percent: row.fat_percent,
    snf_percent: row.snf_percent,
    feed_product_id: row.feed_product_id,
    feed_quantity: row.feed_quantity || null,
    mortality_count: row.mortality_count,
    culled_count: row.culled_count,
    remarks: row.remarks ?? '',
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = {
    ...form.value,
    log_date: iso(form.value.log_date),
    animal_id: isFlock.value ? null : form.value.animal_id,
    flock_id: isFlock.value ? form.value.flock_id : null,
    feed_quantity: form.value.feed_product_id ? form.value.feed_quantity : 0,
  }
  try {
    if (editingId.value) {
      await http.put(`/farm-production/${editingId.value}`, payload)
      toast.add({
        severity: 'success',
        summary: pt('Entry updated'),
        detail: pt('Stock movements were reversed and re-posted.'),
        life: 3000,
      })
    } else {
      await http.post('/farm-production', payload)
      toast.add({
        severity: 'success',
        summary: pt('Entry recorded'),
        detail: pt('Produce added to stock; feed drawn down.'),
        life: 3000,
      })
    }
    reset()
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the entry'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this entry?'),
    text: pt('Its stock movements will be reversed: the produce comes back out of stock and the feed goes back in.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete and reverse'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/farm-production/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Entry deleted and reversed'), life: 3000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

onMounted(async () => {
  await Promise.all([load(), loadRefs()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Daily Production')"
      :subtitle="pt('What the farm produced, ate and lost — each entry moves stock as it is saved')"
    />

    <!-- Entry form first: this screen exists to be typed into. -->
    <div v-if="canCreate || editingId" class="entry">
      <div class="entry-head">
        <h3>{{ editingId ? pt('Edit entry') : pt('Record output') }}</h3>
        <div class="src-toggle">
          <Button
            :label="pt('Animal')"
            size="small"
            :outlined="isFlock"
            :disabled="!!editingId"
            @click="switchSource('animal')"
          />
          <Button
            :label="pt('Flock')"
            size="small"
            :outlined="!isFlock"
            :disabled="!!editingId"
            @click="switchSource('flock')"
          />
        </div>
      </div>

      <div class="entry-grid">
        <div class="fld">
          <label>{{ pt('Date') }} *</label>
          <DatePicker v-model="form.log_date" dateFormat="yy-mm-dd" showButtonBar />
          <small v-if="errors.log_date" class="err">{{ errors.log_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Session') }} *</label>
          <Select
            v-model="form.session"
            :options="SESSIONS.map((s) => ({ label: pt(s.label), value: s.value }))"
            optionLabel="label"
            optionValue="value"
          />
        </div>
        <div class="fld">
          <label>{{ isFlock ? pt('Flock batch') : pt('Animal') }} *</label>
          <Select
            v-if="isFlock"
            v-model="form.flock_id"
            :options="flocks"
            optionLabel="label"
            optionValue="value"
            filter
            :placeholder="pt('Select batch')"
            @change="onSourceChange"
          />
          <Select
            v-else
            v-model="form.animal_id"
            :options="animals"
            optionLabel="label"
            optionValue="value"
            filter
            :placeholder="pt('Select animal')"
            @change="onSourceChange"
          />
          <small v-if="errors.animal_id" class="err">{{ errors.animal_id[0] }}</small>
          <small v-if="errors.flock_id" class="err">{{ errors.flock_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Produced') }} *</label>
          <Select
            v-model="form.product_id"
            :options="products"
            optionLabel="label"
            optionValue="value"
            filter
            :placeholder="pt('Milk, eggs, manure')"
          />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Quantity') }} *</label>
          <InputNumber v-model="form.quantity" :min="0" :maxFractionDigits="3" />
          <small v-if="errors.quantity" class="err">{{ errors.quantity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Into warehouse') }} *</label>
          <Select
            v-model="form.warehouse_id"
            :options="warehouses"
            optionLabel="label"
            optionValue="value"
            filter
            :placeholder="pt('Shed / store')"
          />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>

        <template v-if="!isFlock">
          <div class="fld">
            <label>{{ pt('Fat %') }}</label>
            <InputNumber v-model="form.fat_percent" :min="0" :max="100" :maxFractionDigits="2" />
            <small class="hint">{{ pt('What a dairy pays on.') }}</small>
          </div>
          <div class="fld">
            <label>{{ pt('SNF %') }}</label>
            <InputNumber v-model="form.snf_percent" :min="0" :max="100" :maxFractionDigits="2" />
          </div>
        </template>

        <template v-if="isFlock">
          <div class="fld">
            <label>{{ pt('Birds lost') }}</label>
            <InputNumber v-model="form.mortality_count" :min="0" />
          </div>
          <div class="fld">
            <label>{{ pt('Birds culled') }}</label>
            <InputNumber v-model="form.culled_count" :min="0" />
            <small v-if="errors.mortality_count" class="err">{{ errors.mortality_count[0] }}</small>
          </div>
        </template>

        <div class="fld">
          <label>{{ pt('Feed used') }}</label>
          <Select
            v-model="form.feed_product_id"
            :options="feedProducts"
            optionLabel="label"
            optionValue="value"
            filter
            showClear
            :placeholder="pt('Feed product')"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Feed quantity') }}</label>
          <InputNumber v-model="form.feed_quantity" :min="0" :maxFractionDigits="3" :disabled="!form.feed_product_id" />
          <small v-if="errors.feed_quantity" class="err">{{ errors.feed_quantity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Unit cost') }}</label>
          <InputNumber v-model="form.unit_cost" :min="0" :maxFractionDigits="4" />
          <small class="hint">{{ pt('Blank uses the product\'s cost price.') }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Remarks') }}</label>
          <InputText v-model="form.remarks" />
        </div>
      </div>

      <div class="entry-actions">
        <Button
          :label="editingId ? pt('Update entry') : pt('Record entry')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!form.product_id || form.quantity === null"
          @click="save"
        />
        <Button v-if="editingId" :label="pt('Cancel edit')" text @click="reset" />
        <span class="note">
          <i class="pi pi-info-circle" />
          {{ pt('Saving posts stock: produce in, feed out. Editing reverses and re-posts.') }}
        </span>
      </div>
    </div>

    <!-- Period totals, per product: litres and dozens do not add up together. -->
    <div class="stat-row">
      <div v-for="p in summary.by_product" :key="p.product" class="stat">
        <span class="s-label">{{ p.product }}</span>
        <strong>{{ p.quantity }}</strong>
        <small>{{ pt('{n} entries', { n: p.entries }) }}</small>
      </div>
      <div class="stat">
        <span class="s-label">{{ pt('Feed drawn') }}</span>
        <strong>{{ summary.feed_quantity }}</strong>
        <small>{{ pt('over {n} recorded days', { n: summary.days_recorded }) }}</small>
      </div>
      <div v-if="summary.mortality || summary.culled" class="stat warn">
        <span class="s-label">{{ pt('Birds lost') }}</span>
        <strong>{{ summary.mortality }}</strong>
        <small>{{ pt('{n} culled', { n: summary.culled }) }}</small>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="filterFrom" dateFormat="yy-mm-dd" showButtonBar :placeholder="pt('From')" class="t-date" @update:modelValue="load" />
      <DatePicker v-model="filterTo" dateFormat="yy-mm-dd" showButtonBar :placeholder="pt('To')" class="t-date" @update:modelValue="load" />
      <Select
        v-model="filterSource"
        :options="[
          { label: pt('Animals'), value: 'animal' },
          { label: pt('Flocks'), value: 'flock' },
        ]"
        optionLabel="label"
        optionValue="value"
        :placeholder="pt('All sources')"
        showClear
        class="t-sel"
        @change="load"
      />
      <Button icon="pi pi-refresh" :label="pt('Refresh')" outlined @click="load" />
      <TableExportButtons
        title="Daily Production"
        filenameBase="farm-production"
        :rows="rows"
        :columns="[
          { key: 'log_date', label: 'Date' },
          { key: 'session', label: 'Session' },
          { key: 'source', label: 'Source' },
          { key: 'product', label: 'Produced' },
          { key: 'quantity', label: 'Quantity' },
          { key: 'fat_percent', label: 'Fat %' },
          { key: 'snf_percent', label: 'SNF %' },
          { key: 'feed_product', label: 'Feed' },
          { key: 'feed_quantity', label: 'Feed qty' },
          { key: 'mortality_count', label: 'Lost' },
          { key: 'culled_count', label: 'Culled' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
      <Column :header="pt('Date')">
        <template #body="{ data }">
          {{ data.log_date }}
          <div class="muted sm">{{ pt(SESSIONS.find((s) => s.value === data.session)?.label ?? data.session) }}</div>
        </template>
      </Column>
      <Column :header="pt('Source')">
        <template #body="{ data }">
          <strong>{{ data.source ?? '—' }}</strong>
          <div class="muted sm">
            {{ data.source_type === 'animal' ? (data.animal_name ?? pt('Animal')) : (data.bird_type ?? pt('Flock')) }}
          </div>
        </template>
      </Column>
      <Column :header="pt('Produced')">
        <template #body="{ data }">
          {{ data.product }}
          <div class="muted sm">{{ data.warehouse }}</div>
        </template>
      </Column>
      <Column :header="pt('Quantity')">
        <template #body="{ data }"><strong>{{ data.quantity }}</strong></template>
      </Column>
      <Column :header="pt('Fat / SNF')">
        <template #body="{ data }">
          <template v-if="data.fat_percent === null && data.snf_percent === null"><span class="muted">—</span></template>
          <template v-else>
            {{ data.fat_percent ?? '—' }}% / {{ data.snf_percent ?? '—' }}%
            <div v-if="data.kg_fat !== null" class="muted sm">{{ data.kg_fat }} kg {{ pt('fat') }}</div>
          </template>
        </template>
      </Column>
      <Column :header="pt('Feed')">
        <template #body="{ data }">
          <template v-if="!data.feed_product_id"><span class="muted">—</span></template>
          <template v-else>
            {{ data.feed_quantity }}
            <div class="muted sm">{{ data.feed_product }}</div>
          </template>
        </template>
      </Column>
      <Column :header="pt('Losses')">
        <template #body="{ data }">
          <template v-if="!data.mortality_count && !data.culled_count"><span class="muted">—</span></template>
          <template v-else>
            <Tag :value="String(data.mortality_count)" severity="danger" />
            <small v-if="data.culled_count" class="muted"> +{{ data.culled_count }} {{ pt('culled') }}</small>
          </template>
        </template>
      </Column>
      <Column :header="pt('Stock')">
        <template #body="{ data }">
          <Tag
            :value="data.posted_at ? pt('Posted') : pt('Not posted')"
            :severity="data.posted_at ? 'success' : 'warn'"
          />
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('Nothing recorded for this period.') }}</template>
    </DataTable>
  </div>
</template>

<style scoped>
.entry { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem; }
.entry-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.85rem; flex-wrap: wrap; }
.entry-head h3 { margin: 0; font-size: 1rem; font-weight: 600; }
.src-toggle { display: flex; gap: 0.4rem; }
.entry-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.85rem; }
.entry-actions { display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }
.note { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: #6b7280; }
.stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.stat {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.7rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 160px;
}
.stat.warn { border-color: #f0b4b4; background: #fdf1f1; }
.stat strong { font-size: 1.35rem; font-weight: 700; }
.s-label { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.stat small { font-size: 0.72rem; color: #6b7280; }
.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.t-date { width: 150px; }
.t-sel { width: 160px; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
.sm { font-size: 0.72rem; }
@media (max-width: 1100px) {
  .entry-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 820px) {
  .entry-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 560px) {
  .entry-grid { grid-template-columns: 1fr; }
}
</style>
