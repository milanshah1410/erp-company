<script setup lang="ts">
/**
 * Hamper packs — what goes in one, and how many you could build today.
 *
 * The column that earns this screen is "can build": the limiting component,
 * worked out from live stock. In the fortnight before a festival that single
 * number replaces a morning of walking the shelves with a list, and it is why the
 * list is not just a tab on the product master.
 *
 * Contents are edited as a whole list, because that is how a hamper is designed —
 * "chocolate, dry fruit, a brass diya, and the good sweets on top" — not amended
 * one line at a time.
 *
 * Building is a separate, heavier action with its own permission (stock.create):
 * it empties shelves and creates hampers, so it asks for a quantity and a
 * warehouse and then refuses outright if a component is short, naming what is
 * missing. Unwinding a batch is a posted reversal, not an edit.
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

const canCreate = computed(() => auth.can('gift-packs.create'))
const canEdit = computed(() => auth.can('gift-packs.edit'))
const canDelete = computed(() => auth.can('gift-packs.delete'))
// Assembling is stock authority, not merchandising authority — see the route file.
const canBuild = computed(() => auth.can('stock.create'))
const canUnbuild = computed(() => auth.can('stock.edit'))

const rows = ref<any[]>([])
const summary = ref({ packs: 0, active: 0, built_this_month: 0, buildable: 0 })
const loading = ref(false)
const search = ref('')
const activeOnly = ref(false)

const products = ref<any[]>([])
const wrapOptions = ref<any[]>([])
const warehouses = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/gift/packs', {
      params: {
        search: search.value || undefined,
        is_active: activeOnly.value ? 1 : undefined,
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
  const [p, w] = await Promise.all([
    http.get('/products', { params: { per_page: 500, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    is_service: !!x.is_service,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))

  if (auth.can('gift-wraps.view')) {
    const { data } = await http.get('/gift/wrap-options', { params: { per_page: 100, is_active: 1 } })
    wrapOptions.value = (data.data ?? []).map((x: any) => ({ label: x.name, value: x.id }))
  }
}

// ---- Design a pack --------------------------------------------------------

const formVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const editingId = ref<number | null>(null)
const form = ref<any>(blank())

function blank() {
  return {
    code: '',
    name: '',
    product_id: null,
    occasion_tag: '',
    wrap_option_id: null,
    notes: '',
    is_active: true,
    items: [{ product_id: null, quantity: 1, notes: '' }],
  }
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  formVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/gift/packs/${row.id}`)
  const d = data.data
  editingId.value = d.id
  errors.value = {}
  form.value = {
    code: d.code,
    name: d.name,
    product_id: d.product_id,
    occasion_tag: d.occasion_tag ?? '',
    wrap_option_id: d.wrap_option_id,
    notes: d.notes ?? '',
    is_active: !!d.is_active,
    items: (d.items ?? []).map((i: any) => ({
      product_id: i.product_id,
      quantity: Number(i.quantity),
      notes: i.notes ?? '',
      on_hand: i.on_hand,
    })),
  }
  if (!form.value.items.length) form.value.items.push({ product_id: null, quantity: 1, notes: '' })
  formVisible.value = true
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, notes: '' })
}

/** `i` arrives as string|number from the template's v-for index in strict mode. */
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      items: form.value.items
        .filter((l: any) => l.product_id)
        .map((l: any) => ({ product_id: l.product_id, quantity: Number(l.quantity || 0), notes: l.notes || undefined })),
    }
    editingId.value
      ? await http.put(`/gift/packs/${editingId.value}`, payload)
      : await http.post('/gift/packs', payload)
    formVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt(editingId.value ? 'Pack updated' : 'Pack created'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the pack'), life: 3500 })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this pack?'),
    text: pt('A pack that has been built cannot be deleted — switch it off instead, and its build history survives.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/gift/packs/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// ---- Build a batch --------------------------------------------------------

const buildVisible = ref(false)
const building = ref(false)
const buildError = ref<string | null>(null)
const buildPack = ref<any>(null)
const buildForm = ref<any>({ quantity: 1, warehouse_id: null, build_date: new Date(), notes: '' })

function openBuild(row: any) {
  buildPack.value = row
  buildError.value = null
  buildForm.value = {
    quantity: 1,
    warehouse_id: warehouses.value[0]?.value ?? null,
    build_date: new Date(),
    notes: '',
  }
  buildVisible.value = true
}

async function submitBuild() {
  building.value = true
  buildError.value = null
  try {
    const { data } = await http.post(`/gift/packs/${buildPack.value.id}/build`, {
      ...buildForm.value,
      build_date: asDate(buildForm.value.build_date),
    })
    buildVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
  } catch (e: any) {
    // The interesting failure: a shortfall, named component by component. Shown
    // in the dialog rather than as a toast, because it is something to act on.
    buildError.value =
      e.response?.data?.errors?.quantity?.[0] ??
      e.response?.data?.errors?.warehouse_id?.[0] ??
      e.response?.data?.message ??
      pt('Could not build the batch')
  } finally {
    building.value = false
  }
}

// ---- Build history -------------------------------------------------------

const historyVisible = ref(false)
const historyPack = ref<any>(null)
const builds = ref<any[]>([])

async function openHistory(row: any) {
  historyPack.value = row
  const { data } = await http.get(`/gift/packs/${row.id}/builds`, { params: { per_page: 50 } })
  builds.value = data.data
  historyVisible.value = true
}

async function unbuild(build: any) {
  const res = await Swal.fire({
    title: pt('Reverse this build?'),
    text: pt('The hampers come off stock and their contents go back on. Posted as a correction, not as a disassembly.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Reverse It'),
    cancelButtonText: pt('Leave It'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    const { data } = await http.post(`/gift/packs/${historyPack.value.id}/builds/${build.id}/unbuild`)
    await openHistory(historyPack.value)
    await load()
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
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

/** The margin argument a shop actually has: pack price against contents at retail. */
function marginLabel(row: any) {
  const contents = Number(row.contents_value || 0)
  const price = Number(row.selling_price || 0)
  if (!contents || !price) return '—'
  const diff = Math.round(((price - contents) / contents) * 100)
  return `${diff > 0 ? '+' : ''}${diff}%`
}

const exportColumns: ExportColumn[] = [
  { key: 'code', label: 'Code' },
  { key: 'name', label: 'Pack' },
  { key: 'sku', label: 'Hamper SKU' },
  { key: 'item_count', label: 'Items' },
  { key: 'estimated_cost', label: 'Cost to fill' },
  { key: 'contents_value', label: 'Contents at retail' },
  { key: 'selling_price', label: 'Sells for' },
  { key: 'buildable', label: 'Can build' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Hamper Packs')" :subtitle="pt('What goes in a hamper, and how many you could build today')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Pack')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Packs') }}</span>
        <span class="t-value">{{ summary.packs }}</span>
        <small class="muted">{{ summary.active }} {{ pt('live') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Buildable now') }}</span>
        <span class="t-value">{{ summary.buildable }}</span>
        <small class="muted">{{ pt('packs with all contents in stock') }}</small>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Built this month') }}</span>
        <span class="t-value">{{ summary.built_this_month }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search pack, hamper or occasion')" @keyup.enter="load" />
      <label class="chk">
        <Checkbox v-model="activeOnly" binary @change="load" />
        <span>{{ pt('Live packs only') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="gift-packs" :title="pt('Hamper Packs')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="code" :header="pt('Code')" sortable />
      <Column field="name" :header="pt('Pack')" sortable>
        <template #body="{ data }">
          <div>{{ data.name }}</div>
          <small class="muted">{{ data.sku }}<span v-if="data.occasion_tag"> · {{ data.occasion_tag }}</span></small>
        </template>
      </Column>
      <Column field="item_count" :header="pt('Items')" sortable />
      <Column field="estimated_cost" :header="pt('Cost to fill')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.estimated_cost) }}</template>
      </Column>
      <Column field="selling_price" :header="pt('Sells for')" sortable>
        <template #body="{ data }">
          <div>{{ formatCurrency(data.selling_price) }}</div>
          <small class="muted">
            {{ pt('loose') }}: {{ formatCurrency(data.contents_value) }} ({{ marginLabel(data) }})
          </small>
        </template>
      </Column>
      <Column field="buildable" :header="pt('Can build')" sortable>
        <template #body="{ data }">
          <span class="strong" :class="{ danger: data.buildable === 0 }">{{ data.buildable }}</span>
        </template>
      </Column>
      <Column field="is_active" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="data.is_active ? pt('Live') : pt('Off')"
            :severity="data.is_active ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 13rem">
        <template #body="{ data }">
          <Button
            v-if="canBuild && data.is_active"
            icon="pi pi-box"
            text
            rounded
            severity="success"
            :title="pt('Build a batch')"
            @click="openBuild(data)"
          />
          <Button icon="pi pi-history" text rounded :title="pt('Build history')" @click="openHistory(data)" />
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
          {{ pt('No packs yet. A pack is a hamper product plus the list of what goes inside it — building a batch then puts real hampers on the shelf and takes their contents off it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Design -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="editingId ? pt('Edit Pack') : pt('New Pack')"
      :style="{ width: '840px' }"
    >
      <Message severity="info" :closable="false" class="mb">
        {{ pt('The hamper’s price is the product’s own selling price — the till reads that, so it is not repeated here. What the pack owns is the contents.') }}
      </Message>

      <div class="grid-form">
        <div class="fld">
          <label>{{ pt('Code') }} *</label>
          <InputText v-model="form.code" class="w-full" />
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Pack name') }} *</label>
          <InputText v-model="form.name" class="w-full" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('The hamper product (what is scanned at the till)') }} *</label>
          <Select
            v-model="form.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            class="w-full"
            :placeholder="pt('Pick the product the finished hamper is sold as')"
          />
          <small class="hint">{{ pt('One pack per product. Build a batch and this product’s stock goes up.') }}</small>
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Occasion tag') }}</label>
          <InputText v-model="form.occasion_tag" class="w-full" :placeholder="pt('diwali, wedding, corporate')" />
        </div>
        <div v-if="wrapOptions.length" class="fld">
          <label>{{ pt('Presented in') }}</label>
          <Select
            v-model="form.wrap_option_id"
            :options="wrapOptions"
            option-label="label"
            option-value="value"
            showClear
            class="w-full"
          />
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
        <label class="chk span-2">
          <Checkbox v-model="form.is_active" binary />
          <span>{{ pt('Live (offered and buildable)') }}</span>
        </label>
      </div>

      <div class="lines">
        <div class="lines-head">
          <span>{{ pt('What goes in it') }}</span>
          <Button :label="pt('Add item')" icon="pi pi-plus" text size="small" @click="addLine" />
        </div>
        <div v-for="(line, i) in form.items" :key="i" class="line">
          <Select
            v-model="line.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            class="line-product"
            :placeholder="pt('Item')"
          />
          <InputNumber v-model="line.quantity" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="line-qty" />
          <InputText v-model="line.notes" class="line-remarks" :placeholder="pt('Note (optional)')" />
          <small v-if="line.on_hand !== undefined" class="muted stock">
            {{ pt('in stock') }}: {{ line.on_hand }}
          </small>
          <Button icon="pi pi-times" text rounded severity="danger" @click="removeLine(i)" />
        </div>
        <small class="hint">{{ pt('The order is the order a customer reads them in — put the good things first.') }}</small>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="formVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Build -->
    <Dialog v-model:visible="buildVisible" modal :header="pt('Build a Batch')" :style="{ width: '560px' }">
      <div v-if="buildPack">
        <div class="detail-strip">
          <Tag :value="buildPack.code" severity="secondary" />
          <Tag :value="buildPack.name" />
          <Tag :value="`${pt('can build')} ${buildPack.buildable}`" :severity="buildPack.buildable > 0 ? 'success' : 'danger'" />
        </div>

        <Message severity="warn" :closable="false" class="mb">
          {{ pt('This moves stock both ways: the contents come off the shelf and the hampers go on it, at what the contents actually cost.') }}
        </Message>

        <div class="grid-form">
          <div class="fld">
            <label>{{ pt('How many hampers') }} *</label>
            <InputNumber v-model="buildForm.quantity" :min="1" class="w-full" />
          </div>
          <div class="fld">
            <label>{{ pt('From warehouse') }} *</label>
            <Select
              v-model="buildForm.warehouse_id"
              :options="warehouses"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
          <div class="fld">
            <label>{{ pt('Built on') }}</label>
            <DatePicker v-model="buildForm.build_date" date-format="dd/mm/yy" show-icon class="w-full" />
          </div>
          <div class="fld span-2">
            <label>{{ pt('Notes') }}</label>
            <Textarea v-model="buildForm.notes" rows="2" class="w-full" />
          </div>
        </div>

        <Message v-if="buildError" severity="error" :closable="false" class="mt">{{ buildError }}</Message>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="buildVisible = false" />
        <Button :label="pt('Build')" icon="pi pi-box" :loading="building" @click="submitBuild" />
      </template>
    </Dialog>

    <!-- History -->
    <Dialog v-model:visible="historyVisible" modal :header="pt('Build History')" :style="{ width: '720px' }">
      <div v-if="historyPack" class="detail-strip">
        <Tag :value="historyPack.code" severity="secondary" />
        <Tag :value="historyPack.name" />
      </div>

      <DataTable :value="builds" size="small" data-key="id">
        <Column field="build_number" :header="pt('Build #')" />
        <Column field="build_date" :header="pt('Date')" />
        <Column field="quantity" :header="pt('Hampers')" />
        <Column field="warehouse" :header="pt('Warehouse')" />
        <Column field="unit_cost" :header="pt('Cost each')">
          <template #body="{ data }">{{ formatCurrency(data.unit_cost) }}</template>
        </Column>
        <Column field="total_cost" :header="pt('Batch cost')">
          <template #body="{ data }">{{ formatCurrency(data.total_cost) }}</template>
        </Column>
        <Column :header="pt('Status')">
          <template #body="{ data }">
            <Tag
              :value="data.is_posted ? pt('Posted') : pt('Reversed')"
              :severity="data.is_posted ? 'success' : 'contrast'"
            />
          </template>
        </Column>
        <Column :header="pt('Actions')" style="width: 6rem">
          <template #body="{ data }">
            <Button
              v-if="canUnbuild && data.is_posted"
              icon="pi pi-undo"
              text
              rounded
              severity="danger"
              :title="pt('Reverse')"
              @click="unbuild(data)"
            />
          </template>
        </Column>
        <template #empty>
          <div class="empty-state">{{ pt('This pack has never been built.') }}</div>
        </template>
      </DataTable>

      <template #footer>
        <Button :label="pt('Close')" text @click="historyVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.15rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }

.lines { margin-top: 1rem; border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.75rem; }
.lines-head { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.4rem; }
.line { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.45rem; }
.line-product { flex: 3; min-width: 220px; }
.line-qty { width: 100px; }
.line-remarks { flex: 2; min-width: 120px; }
.stock { white-space: nowrap; }

.detail-strip { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.8rem; }

.muted { color: #6b7280; }
.strong { font-weight: 600; }
.danger { color: #b91c1c; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.78rem; }
.mb { margin-bottom: 0.9rem; }
.mt { margin-top: 0.8rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
