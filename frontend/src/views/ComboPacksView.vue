<script setup lang="ts">
/**
 * Combo packs: one barcode, several items off the shelf.
 *
 * Three prices are shown side by side because on a grocery margin all three are
 * needed to judge a pack — what it sells for, what its contents are worth bought
 * separately (the gap is the offer), and what they cost the shop (the gap is the
 * money). A pack whose margin has gone negative is flagged rather than merely
 * displayed: it is almost always a component price rise nobody noticed, and it is
 * the most expensive mistake this trade can make.
 *
 * "Buildable" is the number the counter cares about — how many packs could be
 * made from what is on the shelf right now, which is whatever the scarcest
 * component allows.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()
const confirm = useConfirm()

const rows = ref<any[]>([])
const summary = ref({ packs: 0, live: 0, no_saving: 0, below_cost: 0 })
const products = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const search = ref('')

const dialogVisible = ref(false)
const editing = ref<any>(null)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  product_id: null as number | null,
  name: '',
  // Dates are Date objects while the dialog is open — DatePicker will not bind a
  // string — and are flattened to plain days by asDate() on the way to the API.
  starts_on: null as Date | null,
  ends_on: null as Date | null,
  is_active: true,
  notes: '',
  items: [
    { product_id: null, quantity: 1 },
    { product_id: null, quantity: 1 },
  ] as any[],
})

const form = ref(blank())

/** What the lines currently in the dialog are worth and cost, for a live preview. */
const draft = computed(() => {
  let value = 0
  let cost = 0
  for (const line of form.value.items) {
    const product = products.value.find((p) => p.id === line.product_id)
    if (!product) continue
    const qty = Number(line.quantity || 0)
    value += Number(product.selling_price ?? 0) * qty
    cost += Number(product.cost_price ?? 0) * qty
  }
  return { value, cost }
})

const draftPrice = computed(() => {
  const product = products.value.find((p) => p.id === form.value.product_id)
  return product ? Number(product.selling_price) : 0
})

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/combo-packs', {
      params: { search: search.value || undefined },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadProducts() {
  const { data } = await http.get('/products', { params: { per_page: 500, is_active: 1 } })
  products.value = data.data
}

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/combo-packs/${row.id}`)
  editing.value = data.data
  form.value = {
    product_id: data.data.product_id,
    name: data.data.name,
    starts_on: data.data.starts_on ? new Date(data.data.starts_on) : null,
    ends_on: data.data.ends_on ? new Date(data.data.ends_on) : null,
    is_active: data.data.is_active,
    notes: data.data.notes ?? '',
    items: data.data.items.map((i: any) => ({
      product_id: i.product_id,
      quantity: Number(i.quantity),
    })),
  }
  errors.value = {}
  dialogVisible.value = true
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1 })
}

function removeLine(index: number) {
  // Two is the floor the API enforces, so the UI does not offer a third removal.
  if (form.value.items.length <= 2) return
  form.value.items.splice(index, 1)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      starts_on: asDate(form.value.starts_on),
      ends_on: asDate(form.value.ends_on),
    }
    if (editing.value) {
      await http.put(`/combo-packs/${editing.value.id}`, payload)
    } else {
      await http.post('/combo-packs', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Combo pack saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not save the pack'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

/** The date picker hands back a Date; the API wants a plain day. */
function asDate(value: any): string | null {
  if (!value) return null
  if (typeof value === 'string') return value.slice(0, 10)
  const d = new Date(value)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function remove(row: any) {
  confirm.require({
    message: pt('Delete this pack? The product itself is kept — it just stops breaking apart at the till.'),
    header: pt('Delete combo pack'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      await http.delete(`/combo-packs/${row.id}`)
      await load()
      toast.add({ severity: 'success', summary: pt('Combo pack deleted'), life: 2500 })
    },
  })
}

const productOptions = computed(() =>
  products.value.map((p) => ({ label: `${p.sku} — ${p.name}`, value: p.id })),
)

onMounted(() => {
  load()
  loadProducts()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Combo Packs')"
      :subtitle="pt('One barcode at the till, several items off the shelf')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Packs') }}</span>
        <span class="t-value">{{ summary.packs }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Live now') }}</span>
        <span class="t-value">{{ summary.live }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.no_saving > 0 }">
        <span class="t-label">{{ pt('No saving') }}</span>
        <span class="t-value">{{ summary.no_saving }}</span>
      </div>
      <div class="tile" :class="{ danger: summary.below_cost > 0 }">
        <span class="t-label">{{ pt('Below cost') }}</span>
        <span class="t-value">{{ summary.below_cost }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search packs')" @keyup.enter="load" />
      <Button icon="pi pi-search" text @click="load" />
      <Button icon="pi pi-plus" :label="pt('New Pack')" @click="openCreate" />
      <TableExportButtons
        title="Combo Packs"
        filenameBase="combo-packs"
        :rows="rows"
        :columns="[
          { key: 'name', label: 'Pack' },
          { key: 'sku', label: 'SKU' },
          { key: 'price', label: 'Price' },
          { key: 'component_value', label: 'Parts worth' },
          { key: 'saving', label: 'Saving' },
          { key: 'component_cost', label: 'Parts cost' },
          { key: 'margin', label: 'Margin' },
          { key: 'buildable', label: 'Buildable' },
          { key: 'items_count', label: 'Items' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="name" :header="pt('Pack')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.name }}</strong>
            <small class="muted">{{ data.sku }} · {{ data.product_name }}</small>
            <small v-if="data.starts_on || data.ends_on" class="muted">
              {{ data.starts_on ?? '…' }} → {{ data.ends_on ?? '…' }}
            </small>
          </div>
        </template>
      </Column>
      <Column field="items_count" :header="pt('Items')" sortable />
      <Column field="price" :header="pt('Pack price')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.price) }}</template>
      </Column>
      <Column field="component_value" :header="pt('Parts worth')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.component_value) }}</template>
      </Column>
      <Column field="saving" :header="pt('Saving')" sortable>
        <template #body="{ data }">
          <Tag
            :value="`${formatCurrency(data.saving)}${data.saving_percent !== null ? ' · ' + data.saving_percent + '%' : ''}`"
            :severity="data.saving > 0 ? 'success' : 'warn'"
          />
        </template>
      </Column>
      <Column field="margin" :header="pt('Margin')" sortable>
        <template #body="{ data }">
          <Tag
            :value="`${formatCurrency(data.margin)}${data.margin_percent !== null ? ' · ' + data.margin_percent + '%' : ''}`"
            :severity="data.margin > 0 ? 'success' : 'danger'"
          />
        </template>
      </Column>
      <Column field="buildable" :header="pt('Buildable')" sortable>
        <template #body="{ data }">
          <span v-if="data.buildable === null" class="muted">—</span>
          <span v-else :class="{ bad: data.buildable <= 0 }">{{ data.buildable }}</span>
        </template>
      </Column>
      <Column field="is_live" :header="pt('Live')" sortable>
        <template #body="{ data }">
          <Tag
            :value="data.is_live ? pt('Yes') : pt('No')"
            :severity="data.is_live ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column :header="pt('Actions')">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button icon="pi pi-trash" text severity="danger" size="small" @click="remove(data)" />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No combo packs yet.') }}</div>
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Combo Pack') : pt('New Combo Pack')"
      :style="{ width: '48rem' }"
    >
      <div class="form">
        <div class="field">
          <label>{{ pt('Sellable product') }}</label>
          <Select
            v-model="form.product_id"
            :options="productOptions"
            option-label="label"
            option-value="value"
            filter
            :placeholder="pt('The product the pack is scanned as')"
          />
          <small class="hint">{{ pt('This SKU carries the price and the barcode. It never holds stock of its own.') }}</small>
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Pack name') }}</label>
          <InputText v-model="form.name" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Runs from') }}</label>
            <DatePicker v-model="form.starts_on" date-format="yy-mm-dd" show-icon />
          </div>
          <div class="field">
            <label>{{ pt('Runs until') }}</label>
            <DatePicker v-model="form.ends_on" date-format="yy-mm-dd" show-icon />
            <small v-if="errors.ends_on" class="err">{{ errors.ends_on[0] }}</small>
          </div>
        </div>
        <small class="hint">{{ pt('Leave both blank for a pack that runs all year. Outside its dates the product still sells — it just stops breaking apart.') }}</small>

        <div class="lines">
          <div class="lines-head">
            <strong>{{ pt('What it contains') }}</strong>
            <Button icon="pi pi-plus" text size="small" :label="pt('Add item')" @click="addLine" />
          </div>

          <div v-for="(line, index) in form.items" :key="index" class="line">
            <Select
              v-model="line.product_id"
              :options="productOptions"
              option-label="label"
              option-value="value"
              filter
              :placeholder="pt('Item')"
              class="line-product"
            />
            <InputNumber
              v-model="line.quantity"
              :min="0.001"
              :max-fraction-digits="3"
              show-buttons
              class="line-qty"
            />
            <Button
              icon="pi pi-times"
              text
              severity="danger"
              size="small"
              :disabled="form.items.length <= 2"
              @click="removeLine(index)"
            />
          </div>

          <small v-if="errors.items" class="err">{{ errors.items[0] }}</small>

          <div class="preview">
            <span>{{ pt('Parts worth') }}: <strong>{{ formatCurrency(draft.value) }}</strong></span>
            <span>{{ pt('Parts cost') }}: <strong>{{ formatCurrency(draft.cost) }}</strong></span>
            <span>{{ pt('Pack price') }}: <strong>{{ formatCurrency(draftPrice) }}</strong></span>
            <span :class="draft.value - draftPrice > 0 ? 'good' : 'bad'">
              {{ pt('Saving') }}: <strong>{{ formatCurrency(draft.value - draftPrice) }}</strong>
            </span>
            <span :class="draftPrice - draft.cost > 0 ? 'good' : 'bad'">
              {{ pt('Margin') }}: <strong>{{ formatCurrency(draftPrice - draft.cost) }}</strong>
            </span>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>

        <div class="field-inline">
          <Checkbox v-model="form.is_active" binary input-id="pack-active" />
          <label for="pack-active">{{ pt('Active') }}</label>
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
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.danger { border-color: #dc2626; background: rgba(220, 38, 38, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.bad { color: #dc2626; font-weight: 600; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.row .field { flex: 1 1 12rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { color: #6b7280; font-size: 0.72rem; }
.err { color: #dc2626; font-size: 0.75rem; }
.lines { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; }
.lines-head { display: flex; align-items: center; justify-content: space-between; }
.line { display: flex; gap: 0.5rem; align-items: center; }
.line-product { flex: 1 1 auto; min-width: 0; }
.line-qty { width: 8rem; }
.preview { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.8rem; border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.5rem; }
.preview .good { color: #16a34a; }
.preview .bad { color: #dc2626; }
</style>
