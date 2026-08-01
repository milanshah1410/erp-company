<script setup lang="ts">
/**
 * Meal deals: one price, several dishes.
 *
 * The list shows the combo price beside what its parts are worth separately,
 * because the gap between them *is* the offer — and a combo whose saving has gone
 * negative is a component price rise nobody noticed, which is why that row is
 * flagged rather than merely displayed.
 *
 * Food cost comes from the components' own recipes, so the margin column means the
 * same thing here as it does on the Recipes screen.
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
const summary = ref({ combos: 0, active: 0, no_saving: 0 })
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
  is_active: true,
  notes: '',
  items: [
    { product_id: null, quantity: 1, is_swappable: false },
    { product_id: null, quantity: 1, is_swappable: false },
  ] as any[],
})

const form = ref(blank())

/** What the lines currently in the dialog are worth, for a live saving preview. */
const draftValue = computed(() =>
  form.value.items.reduce((sum, line) => {
    const product = products.value.find((p) => p.id === line.product_id)
    return sum + (product ? Number(product.selling_price) * Number(line.quantity || 0) : 0)
  }, 0),
)

const draftPrice = computed(() => {
  const product = products.value.find((p) => p.id === form.value.product_id)
  return product ? Number(product.selling_price) : 0
})

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/menu-combos', { params: { search: search.value || undefined } })
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
  const { data } = await http.get(`/menu-combos/${row.id}`)
  editing.value = data.data
  form.value = {
    product_id: data.data.product_id,
    name: data.data.name,
    is_active: data.data.is_active,
    notes: data.data.notes ?? '',
    items: data.data.items.map((i: any) => ({
      product_id: i.product_id,
      quantity: Number(i.quantity),
      is_swappable: i.is_swappable,
    })),
  }
  errors.value = {}
  dialogVisible.value = true
}

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, is_swappable: false })
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
    if (editing.value) {
      await http.put(`/menu-combos/${editing.value.id}`, form.value)
    } else {
      await http.post('/menu-combos', form.value)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Combo saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not save the combo'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

function remove(row: any) {
  confirm.require({
    message: pt('Delete this combo? The product itself is kept — it just stops being a bundle.'),
    header: pt('Delete combo'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      await http.delete(`/menu-combos/${row.id}`)
      await load()
      toast.add({ severity: 'success', summary: pt('Combo deleted'), life: 2500 })
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
      :title="pt('Combos')"
      :subtitle="pt('A combo is a product with a price; this is the statement of what it breaks into')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Combos') }}</span>
        <span class="t-value">{{ summary.combos }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.no_saving > 0 }">
        <span class="t-label">{{ pt('No saving') }}</span>
        <span class="t-value">{{ summary.no_saving }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search combos')" @keyup.enter="load" />
      <Button icon="pi pi-search" text @click="load" />
      <Button icon="pi pi-plus" :label="pt('New Combo')" @click="openCreate" />
      <TableExportButtons
        title="Combos"
        filenameBase="combos"
        :rows="rows"
        :columns="[
          { key: 'name', label: 'Combo' },
          { key: 'sku', label: 'SKU' },
          { key: 'price', label: 'Price' },
          { key: 'component_value', label: 'Parts worth' },
          { key: 'saving', label: 'Saving' },
          { key: 'food_cost', label: 'Food cost' },
          { key: 'items_count', label: 'Items' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
      <Column field="name" :header="pt('Combo')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.name }}</strong>
            <small class="muted">{{ data.sku }} · {{ data.product_name }}</small>
          </div>
        </template>
      </Column>
      <Column field="items_count" :header="pt('Items')" sortable />
      <Column field="price" :header="pt('Combo price')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.price) }}</template>
      </Column>
      <Column field="component_value" :header="pt('Parts worth')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.component_value) }}</template>
      </Column>
      <Column field="saving" :header="pt('Saving')" sortable>
        <template #body="{ data }">
          <Tag
            :value="`${formatCurrency(data.saving)}${data.saving_percent !== null ? ' · ' + data.saving_percent + '%' : ''}`"
            :severity="data.saving > 0 ? 'success' : 'danger'"
          />
        </template>
      </Column>
      <Column field="food_cost_percent" :header="pt('Food cost')" sortable>
        <template #body="{ data }">
          <span v-if="data.food_cost_percent !== null">
            {{ formatCurrency(data.food_cost) }} · {{ data.food_cost_percent }}%
          </span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="is_active" :header="pt('Active')" sortable>
        <template #body="{ data }">
          <Tag
            :value="data.is_active ? pt('Yes') : pt('No')"
            :severity="data.is_active ? 'success' : 'secondary'"
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
        <div class="empty-state">{{ pt('No combos yet.') }}</div>
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Combo') : pt('New Combo')"
      :style="{ width: '46rem' }"
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
            :placeholder="pt('The product the combo is sold as')"
          />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Combo name') }}</label>
          <InputText v-model="form.name" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>

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
            <InputNumber v-model="line.quantity" :min="0.001" :max-fraction-digits="3" show-buttons class="line-qty" />
            <div class="line-swap">
              <Checkbox v-model="line.is_swappable" binary :input-id="`swap-${index}`" />
              <label :for="`swap-${index}`">{{ pt('Swappable') }}</label>
            </div>
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
            <span>{{ pt('Parts worth') }}: <strong>{{ formatCurrency(draftValue) }}</strong></span>
            <span>{{ pt('Combo price') }}: <strong>{{ formatCurrency(draftPrice) }}</strong></span>
            <span :class="draftValue - draftPrice > 0 ? 'good' : 'bad'">
              {{ pt('Saving') }}: <strong>{{ formatCurrency(draftValue - draftPrice) }}</strong>
            </span>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>

        <div class="field-inline">
          <Checkbox v-model="form.is_active" binary input-id="combo-active" />
          <label for="combo-active">{{ pt('Active') }}</label>
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
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.err { color: #dc2626; font-size: 0.75rem; }
.lines { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; }
.lines-head { display: flex; align-items: center; justify-content: space-between; }
.line { display: flex; gap: 0.5rem; align-items: center; }
.line-product { flex: 1 1 auto; min-width: 0; }
.line-qty { width: 8rem; }
.line-swap { display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: #6b7280; white-space: nowrap; }
.preview { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.8rem; border-top: 1px solid var(--surface-border, #e5e7eb); padding-top: 0.5rem; }
.preview .good { color: #16a34a; }
.preview .bad { color: #dc2626; }
</style>
