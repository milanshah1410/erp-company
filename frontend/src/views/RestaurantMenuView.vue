<script setup lang="ts">
/**
 * The menu card, seen from behind the counter.
 *
 * This is the product master filtered to dishes, plus the four attributes only a
 * menu cares about. The action it is built around is the sold-out toggle: that is
 * what someone actually opens this screen to do, several times a service, so it
 * is one tap in the table rather than a row → dialog → save.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canEdit = computed(() => auth.can('restaurant-menu.edit'))

const rows = ref<any[]>([])
const summary = ref({ items: 0, sold_out: 0, unmarked: 0, costed: 0 })
const loading = ref(false)
const categories = ref<any[]>([])
const selected = ref<any[]>([])

const search = ref('')
const categoryId = ref<number | null>(null)
const station = ref<string | null>(null)
const foodType = ref<string | null>(null)

const STATIONS = [
  { label: 'Kitchen', value: 'kitchen' },
  { label: 'Bar', value: 'bar' },
  { label: 'Tandoor', value: 'tandoor' },
  { label: 'Dessert', value: 'dessert' },
  { label: 'Chinese', value: 'chinese' },
]

const FOOD_TYPES = [
  { label: 'Veg', value: 'veg' },
  { label: 'Non-veg', value: 'non_veg' },
  { label: 'Egg', value: 'egg' },
  { label: 'Vegan', value: 'vegan' },
]

/** The green/red dot an Indian menu is expected to carry. */
function foodDot(type: string | null) {
  switch (type) {
    case 'veg':
      return { colour: '#0a8f3c', label: pt('Veg') }
    case 'vegan':
      return { colour: '#0a8f3c', label: pt('Vegan') }
    case 'non_veg':
      return { colour: '#c62828', label: pt('Non-veg') }
    case 'egg':
      return { colour: '#e0a800', label: pt('Egg') }
    default:
      return null
  }
}

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/restaurant-menu', {
      params: {
        search: search.value || undefined,
        category_id: categoryId.value ?? undefined,
        kitchen_station: station.value ?? undefined,
        food_type: foodType.value ?? undefined,
        per_page: 200,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadCategories() {
  const { data } = await http.get('/categories', { params: { per_page: 200 } })
  categories.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
}

/**
 * Toggle one dish. The row is updated from the response rather than optimistically,
 * so a rejected write (no permission, module off) cannot leave the counter looking
 * at a switch that says something the kitchen never heard.
 */
async function toggle(row: any) {
  const next = !row.is_available
  try {
    const { data } = await http.post(`/restaurant-menu/${row.id}/availability`, {
      is_available: next,
    })
    Object.assign(row, data.data)
    summary.value.sold_out += next ? -1 : 1
    toast.add({
      severity: next ? 'success' : 'warn',
      summary: next ? pt('Back on the menu') : pt('Marked sold out'),
      detail: row.name,
      life: 2000,
    })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not update the menu'),
      life: 3000,
    })
  }
}

/** "The whole tandoor is off tonight." */
async function bulkSet(isAvailable: boolean) {
  if (!selected.value.length) return
  try {
    await http.post('/restaurant-menu/bulk-availability', {
      product_ids: selected.value.map((r) => r.id),
      is_available: isAvailable,
    })
    selected.value = []
    await load()
    toast.add({ severity: 'success', summary: pt('Menu updated'), life: 2000 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not update the menu'),
      life: 3000,
    })
  }
}

// ---- Menu attributes ------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({})

function openEdit(row: any) {
  form.value = {
    id: row.id,
    name: row.name,
    food_type: row.food_type,
    kitchen_station: row.kitchen_station,
    prep_minutes: row.prep_minutes,
    menu_sort_order: row.menu_sort_order,
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    await http.put(`/restaurant-menu/${form.value.id}`, {
      food_type: form.value.food_type,
      kitchen_station: form.value.kitchen_station,
      prep_minutes: form.value.prep_minutes,
      menu_sort_order: form.value.menu_sort_order,
    })
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Menu item updated'), life: 2000 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save'),
      life: 3000,
    })
  } finally {
    saving.value = false
  }
}

// ---- QR codes -------------------------------------------------------------

const qrVisible = ref(false)
const qrLoading = ref(false)
const qrCodes = ref<any[]>([])

async function openQr() {
  qrVisible.value = true
  qrLoading.value = true
  try {
    const { data } = await http.get('/restaurant-menu/qr-codes')
    qrCodes.value = data.data
  } finally {
    qrLoading.value = false
  }
}

/** Print just the QR sheet, so a shop can stick one on every table. */
function printQr() {
  const html = qrCodes.value
    .map(
      (c) => `
        <div class="qr-card">
          <img src="${c.qr}" alt="">
          <div class="qr-label">${c.label}</div>
          <div class="qr-hint">${pt('Scan for the menu')}</div>
        </div>`,
    )
    .join('')

  const w = window.open('', '_blank', 'width=900,height=700')
  if (!w) return
  w.document.write(`
    <html><head><title>${pt('Menu QR Codes')}</title><style>
      body { font-family: system-ui, sans-serif; display: flex; flex-wrap: wrap; gap: 18px; padding: 18px; }
      .qr-card { border: 1px solid #ccc; border-radius: 10px; padding: 14px; text-align: center; width: 210px; }
      .qr-card img { width: 170px; height: 170px; }
      .qr-label { font-weight: 600; margin-top: 8px; }
      .qr-hint { font-size: 12px; color: #666; }
      @media print { .qr-card { break-inside: avoid; } }
    </style></head><body>${html}</body></html>`)
  w.document.close()
  w.focus()
  w.print()
}

onMounted(() => {
  load()
  loadCategories()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Menu')" icon="pi pi-book">
      <template #actions>
        <Button
          :label="pt('QR Menu')"
          icon="pi pi-qrcode"
          severity="secondary"
          outlined
          @click="openQr"
        />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Menu items') }}</span>
        <span class="t-value">{{ summary.items }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.sold_out > 0 }">
        <span class="t-label">{{ pt('Sold out') }}</span>
        <span class="t-value">{{ summary.sold_out }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Unmarked (veg/non-veg)') }}</span>
        <span class="t-value">{{ summary.unmarked }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('With a recipe') }}</span>
        <span class="t-value">{{ summary.costed }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search dish or SKU')" @keyup.enter="load" />
      <Select
        v-model="categoryId"
        :options="categories"
        option-label="label"
        option-value="value"
        :placeholder="pt('Category')"
        show-clear
        @change="load"
      />
      <Select
        v-model="station"
        :options="STATIONS"
        option-label="label"
        option-value="value"
        :placeholder="pt('Station')"
        show-clear
        @change="load"
      />
      <Select
        v-model="foodType"
        :options="FOOD_TYPES"
        option-label="label"
        option-value="value"
        :placeholder="pt('Veg / Non-veg')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />

      <template v-if="canEdit && selected.length">
        <Button
          :label="pt('Mark sold out')"
          icon="pi pi-ban"
          severity="danger"
          outlined
          @click="bulkSet(false)"
        />
        <Button
          :label="pt('Back on menu')"
          icon="pi pi-check"
          severity="success"
          outlined
          @click="bulkSet(true)"
        />
      </template>

      <TableExportButtons
        title="Menu"
        filenameBase="menu"
        :rows="rows"
        :columns="[
          { key: 'sku', label: 'SKU' },
          { key: 'name', label: 'Dish' },
          { key: 'category', label: 'Category' },
          { key: 'food_type', label: 'Veg / Non-veg' },
          { key: 'kitchen_station', label: 'Station' },
          { key: 'prep_minutes', label: 'Prep (min)' },
          { key: 'selling_price', label: 'Price' },
          { key: 'is_available', label: 'Available' },
        ]"
      />
    </div>

    <DataTable
      v-model:selection="selected"
      :value="rows"
      :loading="loading"
      data-key="id"
      paginator
      :rows="25"
      striped-rows
      responsive-layout="scroll"
    >
      <Column selection-mode="multiple" header-style="width:3rem" />

      <Column field="name" :header="pt('Dish')" sortable>
        <template #body="{ data }">
          <div class="dish-cell">
            <span
              v-if="foodDot(data.food_type)"
              class="food-dot"
              :style="{ borderColor: foodDot(data.food_type)!.colour }"
              :title="foodDot(data.food_type)!.label"
            >
              <i :style="{ background: foodDot(data.food_type)!.colour }" />
            </span>
            <div>
              <div :class="{ 'sold-out-name': !data.is_available }">{{ data.name }}</div>
              <small class="muted">{{ data.sku }}</small>
            </div>
          </div>
        </template>
      </Column>

      <Column field="category" :header="pt('Category')" sortable />

      <Column field="kitchen_station" :header="pt('Station')" sortable>
        <template #body="{ data }">
          <Tag v-if="data.kitchen_station" :value="pt(data.kitchen_station)" severity="secondary" />
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="prep_minutes" :header="pt('Prep')" sortable>
        <template #body="{ data }">
          <span v-if="data.prep_minutes">{{ data.prep_minutes }} {{ pt('min') }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="selling_price" :header="pt('Price')" sortable>
        <template #body="{ data }">{{ money(data.selling_price) }}</template>
      </Column>

      <Column field="has_recipe" :header="pt('Recipe')">
        <template #body="{ data }">
          <i
            v-if="data.has_recipe"
            class="pi pi-check-circle"
            style="color: var(--p-green-500)"
            :title="pt('Costed from a recipe')"
          />
          <span v-else class="muted" :title="pt('No recipe — food cost unknown')">—</span>
        </template>
      </Column>

      <Column :header="pt('Available')" style="width: 9rem">
        <template #body="{ data }">
          <ToggleSwitch
            :model-value="data.is_available"
            :disabled="!canEdit"
            @update:model-value="toggle(data)"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 6rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit"
            icon="pi pi-pencil"
            text
            rounded
            :title="pt('Menu attributes')"
            @click="openEdit(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">{{ pt('No dishes match those filters.') }}</div>
      </template>
    </DataTable>

    <!-- Menu attributes -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="form.name"
      modal
      :style="{ width: '460px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Veg / Non-veg') }}</label>
          <Select
            v-model="form.food_type"
            :options="FOOD_TYPES"
            option-label="label"
            option-value="value"
            :placeholder="pt('Not marked')"
            show-clear
          />
          <small v-if="errors.food_type" class="p-error">{{ errors.food_type[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Kitchen station') }}</label>
          <Select
            v-model="form.kitchen_station"
            :options="STATIONS"
            option-label="label"
            option-value="value"
            :placeholder="pt('Main kitchen')"
            show-clear
          />
          <small class="muted">{{ pt('Decides which KOT this dish prints on.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Prep minutes') }}</label>
          <InputNumber v-model="form.prep_minutes" :min="0" :max="600" show-buttons />
          <small class="muted">{{ pt('Shown on the QR menu so a guest knows the wait.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Menu order') }}</label>
          <InputNumber v-model="form.menu_sort_order" :min="0" show-buttons />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- QR codes -->
    <Dialog
      v-model:visible="qrVisible"
      :header="pt('Menu QR Codes')"
      modal
      :style="{ width: '760px' }"
    >
      <p class="muted qr-intro">
        {{
          pt(
            'Stick one on each table. Scanning opens a read-only menu — guests cannot order from it.',
          )
        }}
      </p>

      <div v-if="qrLoading" class="empty-state">{{ pt('Loading…') }}</div>
      <div v-else class="qr-grid">
        <div v-for="c in qrCodes" :key="c.token" class="qr-item">
          <img :src="c.qr" :alt="c.label" />
          <div class="qr-item-label">{{ c.label }}</div>
          <a :href="c.url" target="_blank" rel="noopener" class="qr-item-url">{{ c.url }}</a>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="qrVisible = false" />
        <Button :label="pt('Print')" icon="pi pi-print" :disabled="qrLoading" @click="printQr" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #c62828; background: rgba(198, 40, 40, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.p-error { color: #b91c1c; font-size: 0.72rem; }

.dish-cell {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

/* The square-outline-with-a-dot mark used on Indian menus. */
.food-dot {
  width: 14px;
  height: 14px;
  border: 2px solid;
  border-radius: 2px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
}
.food-dot i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  display: block;
}

.sold-out-name {
  text-decoration: line-through;
  opacity: 0.6;
}

.qr-intro {
  margin-bottom: 1rem;
}

.qr-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 1rem;
}
.qr-item {
  border: 1px solid var(--p-content-border-color);
  border-radius: 10px;
  padding: 0.8rem;
  text-align: center;
}
.qr-item img {
  width: 100%;
  max-width: 140px;
  height: auto;
}
.qr-item-label {
  font-weight: 600;
  margin-top: 0.4rem;
}
.qr-item-url {
  font-size: 0.7rem;
  word-break: break-all;
  color: #6b7280;
}
</style>
