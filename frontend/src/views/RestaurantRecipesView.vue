<script setup lang="ts">
/**
 * Recipes — what each dish is made of.
 *
 * The list is really a food-cost report: the reason a kitchen keeps recipes in
 * the ERP at all is to see the gap between what a plate costs and what it sells
 * for, so that column leads rather than hiding behind a drill-down.
 *
 * Settling a bill consumes these ingredients (RecipeConsumptionService), which is
 * stated on the screen — a chef editing quantities is editing what leaves the
 * store, and that should not be a surprise.
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
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('restaurant-recipes.create'))
const canEdit = computed(() => auth.can('restaurant-recipes.edit'))
const canDelete = computed(() => auth.can('restaurant-recipes.delete'))

const rows = ref<any[]>([])
const summary = ref({ recipes: 0, active: 0, dishes_with_station: 0 })
const loading = ref(false)
const products = ref<any[]>([])
const search = ref('')

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/restaurant-recipes', {
      params: { search: search.value || undefined, per_page: 100 },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadProducts() {
  const { data } = await http.get('/products', { params: { per_page: 500, is_active: 1 } })
  products.value = (data.data ?? data).map((p: any) => ({
    label: `${p.name} (${p.sku})`,
    value: p.id,
    cost_price: p.cost_price,
  }))
}

/**
 * Food cost as a share of the selling price. Under ~35% is healthy in most
 * Indian kitchens, so the tag colours around that rather than around an
 * arbitrary midpoint.
 */
function costSeverity(percent: number | null) {
  if (percent === null || percent === undefined) return 'secondary'
  if (percent <= 35) return 'success'
  if (percent <= 50) return 'warn'
  return 'danger'
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const blankLine = () => ({
  product_id: null as number | null,
  quantity: null as number | null,
  wastage_percent: 0,
  unit_cost: null as number | null,
  remarks: '',
})

const blank = () => ({
  product_id: null as number | null,
  yield_quantity: 1,
  is_active: true,
  notes: '',
  items: [blankLine()],
})

const form = ref(blank())

/** Live roll-up so a chef sees the plate cost while typing, not after saving. */
const totalCost = computed(() =>
  form.value.items.reduce((sum, l) => {
    const qty = Number(l.quantity) || 0
    const waste = Number(l.wastage_percent) || 0
    const cost = Number(l.unit_cost) || 0
    return sum + qty * (1 + waste / 100) * cost
  }, 0),
)

const perServing = computed(() => {
  const y = Number(form.value.yield_quantity) || 1
  return totalCost.value / y
})

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/restaurant-recipes/${row.id}`)
  const r = data.data
  editingId.value = r.id
  form.value = {
    product_id: r.product_id,
    yield_quantity: r.yield_quantity,
    is_active: r.is_active,
    notes: r.notes ?? '',
    items: (r.items ?? []).map((i: any) => ({
      product_id: i.product_id,
      quantity: i.quantity,
      wastage_percent: i.wastage_percent,
      unit_cost: i.unit_cost,
      remarks: i.remarks ?? '',
    })),
  }
  if (!form.value.items.length) form.value.items = [blankLine()]
  dialogVisible.value = true
}

function addLine() {
  form.value.items.push(blankLine())
}

function removeLine(index: number) {
  form.value.items.splice(index, 1)
  if (!form.value.items.length) form.value.items = [blankLine()]
}

/** Default an ingredient's cost from the product master when it is picked. */
function onIngredientPicked(line: any) {
  if (line.unit_cost === null || line.unit_cost === undefined) {
    const p = products.value.find((x) => x.value === line.product_id)
    if (p) line.unit_cost = Number(p.cost_price) || 0
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      items: form.value.items.filter((l) => l.product_id && Number(l.quantity) > 0),
    }
    if (editingId.value) {
      await http.put(`/restaurant-recipes/${editingId.value}`, payload)
    } else {
      await http.post('/restaurant-recipes', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Recipe saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the recipe'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this recipe?'),
    text: pt('Past bills keep their stock movements — only future ones stop consuming.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  await http.delete(`/restaurant-recipes/${row.id}`)
  await load()
  toast.add({ severity: 'success', summary: pt('Recipe deleted'), life: 2000 })
}

onMounted(() => {
  load()
  loadProducts()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Recipes')"
      :subtitle="
        pt('What each dish is made of — settling a bill consumes these ingredients from stock')
      "
    >
      <template #actions>
        <Button
          v-if="canCreate"
          :label="pt('New Recipe')"
          icon="pi pi-plus"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Recipes') }}</span>
        <span class="t-value">{{ summary.recipes }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Kitchen dishes') }}</span>
        <span class="t-value">
          {{ summary.dishes_with_station }}
          <small>{{ pt('with a station set') }}</small>
        </span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search dish')" @keyup.enter="load" />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Recipes"
        filenameBase="recipes"
        :rows="rows"
        :columns="[
          { key: 'dish', label: 'Dish' },
          { key: 'yield_quantity', label: 'Yield' },
          { key: 'cost_per_serving', label: 'Cost / serving' },
          { key: 'selling_price', label: 'Price' },
          { key: 'food_cost_percent', label: 'Food cost %' },
          { key: 'margin', label: 'Margin' },
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
      <Column field="dish" :header="pt('Dish')" sortable>
        <template #body="{ data }">
          <div>
            <div>{{ data.dish }}</div>
            <small class="muted">{{ data.sku }}</small>
          </div>
        </template>
      </Column>

      <Column field="items_count" :header="pt('Ingredients')" sortable />

      <Column field="yield_quantity" :header="pt('Yield')" sortable>
        <template #body="{ data }">
          {{ data.yield_quantity }} {{ pt('serving(s)') }}
        </template>
      </Column>

      <Column field="cost_per_serving" :header="pt('Cost / serving')" sortable>
        <template #body="{ data }">{{ money(data.cost_per_serving) }}</template>
      </Column>

      <Column field="selling_price" :header="pt('Price')" sortable>
        <template #body="{ data }">{{ money(data.selling_price) }}</template>
      </Column>

      <Column field="food_cost_percent" :header="pt('Food cost')" sortable>
        <template #body="{ data }">
          <Tag
            v-if="data.food_cost_percent !== null"
            :value="`${data.food_cost_percent}%`"
            :severity="costSeverity(data.food_cost_percent)"
          />
          <span v-else class="muted" :title="pt('The dish has no selling price')">—</span>
        </template>
      </Column>

      <Column field="margin" :header="pt('Margin')" sortable>
        <template #body="{ data }">
          <span v-if="data.margin !== null">{{ money(data.margin) }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="is_active" :header="pt('Status')">
        <template #body="{ data }">
          <Tag
            :value="data.is_active ? pt('Active') : pt('Inactive')"
            :severity="data.is_active ? 'success' : 'secondary'"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 8rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Edit')"
            @click="openEdit(data)"
          />
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
          {{ pt('No recipes yet. Without one, a dish depletes no ingredients when it is billed.') }}
        </div>
      </template>
    </DataTable>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Recipe') : pt('New Recipe')"
      modal
      :style="{ width: '900px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Dish') }} *</label>
          <Select
            v-model="form.product_id"
            :options="products"
            option-label="label"
            option-value="value"
            filter
            :placeholder="pt('Pick the dish this recipe makes')"
          />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Yield (servings)') }} *</label>
          <InputNumber
            v-model="form.yield_quantity"
            :min="0.001"
            :max-fraction-digits="3"
            show-buttons
          />
          <small class="hint">
            {{ pt('Quantities below make this many servings — write the recipe as the chef does.') }}
          </small>
        </div>

        <div class="fld">
          <label>&nbsp;</label>
          <label class="inline-check">
            <Checkbox v-model="form.is_active" binary />
            {{ pt('Active (consumed when a bill is settled)') }}
          </label>
        </div>
      </div>

      <h4 class="section-title">{{ pt('Ingredients') }}</h4>

      <table class="lines">
        <thead>
          <tr>
            <th style="width: 34%">{{ pt('Ingredient') }}</th>
            <th style="width: 13%">{{ pt('Quantity') }}</th>
            <th style="width: 12%">{{ pt('Wastage %') }}</th>
            <th style="width: 14%">{{ pt('Unit cost') }}</th>
            <th style="width: 13%">{{ pt('Line cost') }}</th>
            <th style="width: 10%">{{ pt('Remarks') }}</th>
            <th style="width: 4%"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select
                v-model="line.product_id"
                :options="products"
                option-label="label"
                option-value="value"
                filter
                class="w-full"
                :placeholder="pt('Pick an ingredient')"
                @change="onIngredientPicked(line)"
              />
            </td>
            <td>
              <InputNumber
                v-model="line.quantity"
                :min="0"
                :max-fraction-digits="4"
                class="w-full"
              />
            </td>
            <td>
              <InputNumber
                v-model="line.wastage_percent"
                :min="0"
                :max="100"
                :max-fraction-digits="2"
                class="w-full"
              />
            </td>
            <td>
              <InputNumber
                v-model="line.unit_cost"
                :min="0"
                :max-fraction-digits="4"
                class="w-full"
              />
            </td>
            <td class="num">
              {{
                money(
                  (Number(line.quantity) || 0) *
                    (1 + (Number(line.wastage_percent) || 0) / 100) *
                    (Number(line.unit_cost) || 0),
                )
              }}
            </td>
            <td>
              <InputText v-model="line.remarks" class="w-full" />
            </td>
            <td>
              <Button
                icon="pi pi-times"
                text
                rounded
                severity="danger"
                @click="removeLine(i)"
              />
            </td>
          </tr>
        </tbody>
      </table>

      <div class="lines-footer">
        <Button
          :label="pt('Add ingredient')"
          icon="pi pi-plus"
          text
          size="small"
          @click="addLine"
        />
        <div class="cost-box">
          <div class="cb-line">
            <span>{{ pt('Batch cost') }}</span>
            <strong>{{ money(totalCost) }}</strong>
          </div>
          <div class="cb-line total">
            <span>{{ pt('Cost per serving') }}</span>
            <strong>{{ money(perServing) }}</strong>
          </div>
        </div>
      </div>

      <small v-if="errors.items" class="err">{{ errors.items[0] }}</small>
      <small v-for="(msg, key) in errors" :key="key">
        <span v-if="String(key).startsWith('items.')" class="err">{{ msg[0] }}</span>
      </small>

      <div class="fld" style="margin-top: 0.8rem">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="form.notes" rows="2" auto-resize />
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
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value small { font-size: 0.8rem; font-weight: 500; color: #6b7280; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }

.section-title { margin: 1.2rem 0 0.5rem; font-size: 0.9rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th {
  text-align: left;
  font-size: 0.72rem;
  color: #6b7280;
  font-weight: 500;
  padding: 0.3rem 0.35rem;
}
.lines td { padding: 0.2rem 0.35rem; vertical-align: middle; }
.lines td.num { text-align: right; font-variant-numeric: tabular-nums; }

.lines-footer {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 1rem;
  margin-top: 0.5rem;
}
.cost-box {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 8px;
  padding: 0.5rem 0.8rem;
  min-width: 230px;
}
.cb-line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.12rem 0; }
.cb-line.total {
  border-top: 1px solid var(--surface-border, #e5e7eb);
  margin-top: 0.3rem;
  padding-top: 0.4rem;
  font-size: 0.95rem;
}
</style>
