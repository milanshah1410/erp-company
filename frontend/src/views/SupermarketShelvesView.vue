<script setup lang="ts">
/**
 * The shop floor: aisles, shelves, what belongs on each and what is missing.
 *
 * Three screens off one component, chosen by the route's `mode` meta, because
 * they are three views of the same data and splitting them into three files
 * would have meant three copies of the aisle strip and the shelf picker:
 *
 *  - `floor`  — the aisle map, what is gapped, and the date check. Labelled
 *    "Aisles & Shelves" rather than "Shop Floor": Manufacturing's nav already
 *    means machines by that phrase.
 *  - `round`  — the replenishment list: what to bring, from where, worst first.
 *  - `layout` — the planogram for one shelf, which is where it is edited.
 *
 * The round is the screen that earns the module. Everything else here describes
 * the floor; that one tells somebody what to do next, in the order a supermarket
 * actually wants it walked — proportion of the minimum, not raw shortfall, so a
 * bread shelf with nothing on it outranks a tinned-goods bay that is short a
 * hundred units of four hundred.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()
const route = useRoute()
const auth = useAuthStore()

type Mode = 'floor' | 'round' | 'layout'
const mode = computed<Mode>(() => (route.meta.mode as Mode) || 'floor')

const aisles = ref<any[]>([])
const floorSummary = ref({ aisles: 0, shelves: 0, lines: 0, gapped_lines: 0, empty_lines: 0 })
const gaps = ref<any[]>([])
const gapSummary = ref({ lines: 0, empty: 0, low: 0, units_to_pick: 0 })
const planogram = ref<any[]>([])
const expiring = ref<any[]>([])

const warehouses = ref<any[]>([])
const locations = ref<any[]>([])
const products = ref<any[]>([])

const warehouseId = ref<number | null>(null)
const shelfId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)

const canLayout = computed(() => auth.can('shelf-planogram.edit'))
const canFill = computed(() => auth.can('stock.edit'))

/** Every shelf across the aisles, flattened for the pickers. */
const shelves = computed<any[]>(() => aisles.value.flatMap((a) => a.shelves))

const shelfOptions = computed(() =>
  shelves.value.map((s) => ({ label: `${s.address} — ${s.name}`, value: s.id })),
)

const currentShelf = computed(() => shelves.value.find((s) => s.id === shelfId.value) || null)

// --- Loading ----------------------------------------------------------------

async function loadFloor() {
  const { data } = await http.get('/shelves', { params: { warehouse_id: warehouseId.value || undefined } })
  aisles.value = data.data
  floorSummary.value = data.summary
}

async function loadGaps() {
  const { data } = await http.get('/shelves/gaps', {
    params: { warehouse_id: warehouseId.value || undefined, shelf_id: shelfId.value || undefined },
  })
  gaps.value = data.data
  gapSummary.value = data.summary
}

async function loadPlanogram() {
  if (!shelfId.value) {
    planogram.value = []
    return
  }
  const { data } = await http.get(`/shelves/${shelfId.value}/planogram`)
  planogram.value = data.data
}

async function loadExpiring() {
  const { data } = await http.get('/shelves/expiring', {
    params: { warehouse_id: warehouseId.value || undefined, within_days: 14 },
  })
  expiring.value = data.data
}

async function load() {
  loading.value = true
  try {
    await loadFloor()
    if (mode.value === 'floor') await loadExpiring()
    if (mode.value === 'round') await loadGaps()
    if (mode.value === 'layout') await loadPlanogram()
  } finally {
    loading.value = false
  }
}

async function loadReferenceData() {
  const [wh, pr] = await Promise.all([http.get('/warehouses'), http.get('/products', { params: { per_page: 500 } })])
  warehouses.value = wh.data.data ?? wh.data
  products.value = pr.data.data ?? pr.data
}

/**
 * Every location in the chosen warehouse, shelf or not — the setup dialog turns
 * one into a shelf, so it has to be able to see the ones that are not yet.
 */
async function loadLocations() {
  if (!warehouseId.value) {
    locations.value = []
    return
  }
  const { data } = await http.get(`/warehouses/${warehouseId.value}/locations-flat`)
  locations.value = data.data
}

// --- Marking a location as a shelf ------------------------------------------

const setupVisible = ref(false)
const setupForm = ref<{ location_id: number | null; is_shelf: boolean; aisle: string; bay: string; shelf_level: number | null }>({
  location_id: null,
  is_shelf: true,
  aisle: '',
  bay: '',
  shelf_level: null,
})

function openSetup() {
  setupForm.value = { location_id: null, is_shelf: true, aisle: '', bay: '', shelf_level: null }
  setupVisible.value = true
  loadLocations()
}

async function saveSetup() {
  if (!setupForm.value.location_id) return
  saving.value = true
  try {
    await http.put(`/shelves/${setupForm.value.location_id}`, {
      is_shelf: setupForm.value.is_shelf,
      aisle: setupForm.value.aisle || null,
      bay: setupForm.value.bay || null,
      shelf_level: setupForm.value.shelf_level,
    })
    setupVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Shelf updated'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Could not update the shelf'), detail: e.response?.data?.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

// --- Planogram lines ---------------------------------------------------------

const placeVisible = ref(false)
const placeForm = ref<any>({})

function openPlacement(row: any = null) {
  placeForm.value = row
    ? { id: row.id, location_id: row.location_id, product_id: row.product_id, variant_id: row.variant_id, facings: row.facings, min_qty: row.min_qty, max_qty: row.max_qty, sequence: row.sequence, is_active: row.is_active }
    : { id: null, location_id: shelfId.value, product_id: null, variant_id: null, facings: 1, min_qty: 0, max_qty: 0, sequence: (planogram.value.length + 1) * 10, is_active: true }
  placeVisible.value = true
}

async function savePlacement() {
  saving.value = true
  try {
    const body = { ...placeForm.value }
    delete body.id
    if (placeForm.value.id) await http.put(`/shelf-placements/${placeForm.value.id}`, body)
    else await http.post('/shelf-placements', body)
    placeVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Planogram saved'), life: 2500 })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not save the placement'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

async function removePlacement(row: any) {
  await http.delete(`/shelf-placements/${row.id}`)
  await load()
  toast.add({ severity: 'success', summary: pt('Placement removed'), life: 2000 })
}

// --- Filling a facing --------------------------------------------------------

const fillVisible = ref(false)
const fillRow = ref<any>(null)
const fillQty = ref<number | null>(null)
const fillFrom = ref<number | null>(null)

function openFill(row: any) {
  fillRow.value = row
  // Pre-filled with the top-up to a full shelf: that is the answer in almost
  // every case, and typing it again on every line would make the round slower
  // than a clipboard.
  fillQty.value = row.suggested_qty
  fillFrom.value = null
  fillVisible.value = true
  if (!locations.value.length) loadLocations()
}

async function confirmFill() {
  if (!fillRow.value || !fillQty.value) return
  saving.value = true
  try {
    await http.post(`/shelf-placements/${fillRow.value.id}/replenish`, {
      quantity: fillQty.value,
      from_location_id: fillFrom.value || undefined,
    })
    fillVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Shelf filled'), life: 2500 })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not fill the shelf'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

// --- Presentation ------------------------------------------------------------

function fillSeverity(status: string) {
  return status === 'empty' ? 'danger' : status === 'low' ? 'warn' : 'success'
}

function fillLabel(status: string) {
  return status === 'empty' ? pt('Empty') : status === 'low' ? pt('Low') : pt('OK')
}

function expirySeverity(days: number | null) {
  if (days === null) return 'secondary'
  if (days < 0) return 'danger'
  if (days <= 7) return 'warn'
  return 'success'
}

const productOptions = computed(() =>
  products.value.map((p) => ({ label: `${p.sku} — ${p.name}`, value: p.id })),
)

const locationOptions = computed(() =>
  locations.value.map((l) => ({ label: `${l.code} — ${l.name}`, value: l.id })),
)

const heading = computed(() =>
  mode.value === 'round' ? pt('Replenishment') : mode.value === 'layout' ? pt('Shelf Planogram') : pt('Aisles & Shelves'),
)

const subheading = computed(() =>
  mode.value === 'round'
    ? pt('What to bring out of the backroom, worst gap first')
    : mode.value === 'layout'
      ? pt('What belongs on each shelf, how many facings and the fill range it is kept within')
      : pt('Aisles and shelves, with what has run down on each'),
)

watch(warehouseId, async () => {
  shelfId.value = null
  await Promise.all([load(), loadLocations()])
})
watch(shelfId, () => {
  if (mode.value !== 'floor') load()
})
watch(mode, () => load())

onMounted(async () => {
  await loadReferenceData()
  warehouseId.value = warehouses.value[0]?.id ?? null
  await load()
})
</script>

<template>
  <div>
    <PageHeader :title="heading" :subtitle="subheading" />

    <div class="toolbar">
      <Select
        v-model="warehouseId"
        :options="warehouses"
        option-label="name"
        option-value="id"
        :placeholder="pt('Store')"
      />
      <Select
        v-if="mode !== 'floor'"
        v-model="shelfId"
        :options="shelfOptions"
        option-label="label"
        option-value="value"
        :placeholder="mode === 'layout' ? pt('Choose a shelf') : pt('All shelves')"
        :show-clear="mode === 'round'"
        filter
      />
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
      <Button
        v-if="canLayout && mode !== 'round'"
        icon="pi pi-cog"
        severity="secondary"
        :label="pt('Set up Shelves')"
        @click="openSetup"
      />
      <Button
        v-if="canLayout && mode === 'layout' && shelfId"
        icon="pi pi-plus"
        :label="pt('Place a Product')"
        @click="openPlacement()"
      />
    </div>

    <!-- ================= Floor ================= -->
    <template v-if="mode === 'floor'">
      <div class="tiles">
        <div class="tile">
          <span class="t-label">{{ pt('Aisles') }}</span>
          <span class="t-value">{{ floorSummary.aisles }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Shelves') }}</span>
          <span class="t-value">{{ floorSummary.shelves }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Lines on the floor') }}</span>
          <span class="t-value">{{ floorSummary.lines }}</span>
        </div>
        <div class="tile" :class="{ accent: floorSummary.gapped_lines > 0 }">
          <span class="t-label">{{ pt('Below minimum') }}</span>
          <span class="t-value">{{ floorSummary.gapped_lines }}</span>
        </div>
        <div class="tile" :class="{ danger: floorSummary.empty_lines > 0 }">
          <span class="t-label">{{ pt('Empty facings') }}</span>
          <span class="t-value">{{ floorSummary.empty_lines }}</span>
        </div>
      </div>

      <div v-for="aisle in aisles" :key="aisle.aisle" class="aisle">
        <div class="aisle-head">
          <strong>{{ pt('Aisle') }} {{ aisle.aisle }}</strong>
          <span class="muted">
            {{ aisle.lines }} {{ pt('lines') }}
            <template v-if="aisle.gapped_lines"> · {{ aisle.gapped_lines }} {{ pt('gapped') }}</template>
          </span>
        </div>
        <div class="shelf-strip">
          <div v-for="s in aisle.shelves" :key="s.id" class="shelf" :class="{ gapped: s.gapped_lines > 0, bare: s.empty_lines > 0 }">
            <span class="s-name">{{ s.name }}</span>
            <span class="s-meta">{{ s.address }} · {{ s.code }}</span>
            <span class="s-stat">
              {{ s.lines }} {{ pt('lines') }} · {{ s.facings }} {{ pt('facings') }}
            </span>
            <span v-if="s.gapped_lines" class="s-gap">
              {{ s.gapped_lines }} {{ pt('to fill') }}
              <template v-if="s.empty_lines"> ({{ s.empty_lines }} {{ pt('empty') }})</template>
            </span>
          </div>
        </div>
      </div>

      <div v-if="!aisles.length && !loading" class="empty-state">
        {{ pt('No shelves yet. Create locations on the Warehouses screen, then mark them as shelves here.') }}
      </div>

      <h3 v-if="expiring.length" class="section">{{ pt('Date check — expiring within 14 days') }}</h3>
      <DataTable v-if="expiring.length" :value="expiring" :loading="loading" striped-rows responsive-layout="scroll">
        <Column field="product" :header="pt('Product')" sortable />
        <Column field="address" :header="pt('Where')" sortable />
        <Column field="quantity" :header="pt('On shelf')" sortable />
        <Column field="batch_number" :header="pt('Batch')" />
        <Column field="expiry_date" :header="pt('Expires')" sortable />
        <Column field="days_to_expiry" :header="pt('Days left')" sortable>
          <template #body="{ data }">
            <Tag :severity="expirySeverity(data.days_to_expiry)" :value="String(data.days_to_expiry)" />
          </template>
        </Column>
      </DataTable>
    </template>

    <!-- ================= Round ================= -->
    <template v-else-if="mode === 'round'">
      <div class="tiles">
        <div class="tile" :class="{ accent: gapSummary.lines > 0 }">
          <span class="t-label">{{ pt('Lines to fill') }}</span>
          <span class="t-value">{{ gapSummary.lines }}</span>
        </div>
        <div class="tile" :class="{ danger: gapSummary.empty > 0 }">
          <span class="t-label">{{ pt('Empty') }}</span>
          <span class="t-value">{{ gapSummary.empty }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Low') }}</span>
          <span class="t-value">{{ gapSummary.low }}</span>
        </div>
        <div class="tile">
          <span class="t-label">{{ pt('Units to pick') }}</span>
          <span class="t-value">{{ gapSummary.units_to_pick }}</span>
        </div>
      </div>

      <div class="toolbar">
        <TableExportButtons
          title="Replenishment Round"
          filenameBase="replenishment-round"
          :rows="gaps"
          :columns="[
            { key: 'address', label: 'Where' },
            { key: 'sku', label: 'SKU' },
            { key: 'name', label: 'Product' },
            { key: 'on_shelf', label: 'On shelf' },
            { key: 'min_qty', label: 'Minimum' },
            { key: 'max_qty', label: 'Full' },
            { key: 'suggested_qty', label: 'Bring' },
          ]"
        />
      </div>

      <DataTable :value="gaps" :loading="loading" striped-rows responsive-layout="scroll">
        <Column field="status" :header="pt('State')" sortable>
          <template #body="{ data }">
            <Tag :severity="fillSeverity(data.status)" :value="fillLabel(data.status)" />
          </template>
        </Column>
        <Column field="address" :header="pt('Where')" sortable>
          <template #body="{ data }">
            <div class="stack">
              <strong>{{ data.address }}</strong>
              <small class="muted">{{ data.shelf }}</small>
            </div>
          </template>
        </Column>
        <Column field="name" :header="pt('Product')" sortable>
          <template #body="{ data }">
            <div class="stack">
              <strong>{{ data.name }}</strong>
              <small class="muted">{{ data.sku }}</small>
            </div>
          </template>
        </Column>
        <Column field="on_shelf" :header="pt('On shelf')" sortable />
        <Column field="min_qty" :header="pt('Minimum')" sortable />
        <Column field="suggested_qty" :header="pt('Bring')" sortable>
          <template #body="{ data }">
            <strong>{{ data.suggested_qty }}</strong>
          </template>
        </Column>
        <Column v-if="canFill" :header="pt('Fill')" style="width: 8rem">
          <template #body="{ data }">
            <Button icon="pi pi-arrow-up" text :label="pt('Fill')" @click="openFill(data)" />
          </template>
        </Column>
      </DataTable>

      <div v-if="!gaps.length && !loading" class="empty-state">
        {{ pt('Nothing to fill — every facing is at or above its minimum.') }}
      </div>
    </template>

    <!-- ================= Layout ================= -->
    <template v-else>
      <div v-if="!shelfId" class="empty-state">{{ pt('Choose a shelf to see what belongs on it.') }}</div>

      <template v-else>
        <div class="tiles">
          <div class="tile">
            <span class="t-label">{{ pt('Shelf') }}</span>
            <span class="t-value small">{{ currentShelf?.name }}</span>
          </div>
          <div class="tile">
            <span class="t-label">{{ pt('Address') }}</span>
            <span class="t-value small">{{ currentShelf?.address }}</span>
          </div>
          <div class="tile">
            <span class="t-label">{{ pt('Lines') }}</span>
            <span class="t-value">{{ planogram.length }}</span>
          </div>
          <div class="tile">
            <span class="t-label">{{ pt('Facings') }}</span>
            <span class="t-value">{{ planogram.reduce((n, r) => n + r.facings, 0) }}</span>
          </div>
        </div>

        <DataTable :value="planogram" :loading="loading" striped-rows responsive-layout="scroll">
          <Column field="sequence" :header="pt('#')" sortable style="width: 5rem" />
          <Column field="name" :header="pt('Product')" sortable>
            <template #body="{ data }">
              <div class="stack">
                <strong>{{ data.name }}</strong>
                <small class="muted">{{ data.sku }}</small>
              </div>
            </template>
          </Column>
          <Column field="facings" :header="pt('Facings')" sortable />
          <Column field="min_qty" :header="pt('Minimum')" sortable />
          <Column field="max_qty" :header="pt('Full')" sortable />
          <Column field="on_shelf" :header="pt('On shelf')" sortable>
            <template #body="{ data }">
              <span>{{ data.on_shelf }}</span>
              <Tag class="tiny" :severity="fillSeverity(data.status)" :value="fillLabel(data.status)" />
            </template>
          </Column>
          <Column field="is_active" :header="pt('Active')">
            <template #body="{ data }">
              <i :class="data.is_active ? 'pi pi-check text-green' : 'pi pi-times muted'" />
            </template>
          </Column>
          <Column v-if="canLayout" :header="pt('Actions')" style="width: 9rem">
            <template #body="{ data }">
              <Button icon="pi pi-pencil" text @click="openPlacement(data)" />
              <Button icon="pi pi-trash" text severity="danger" @click="removePlacement(data)" />
            </template>
          </Column>
        </DataTable>
      </template>
    </template>

    <!-- ================= Dialogs ================= -->
    <Dialog v-model:visible="setupVisible" modal :header="pt('Set up a Shelf')" :style="{ width: '30rem' }">
      <div class="form">
        <p class="hint">
          {{ pt('Shelves are warehouse locations. Create them on the Warehouses screen, then mark one here and give it the address a shopper would use.') }}
        </p>

        <div class="field">
          <label>{{ pt('Location') }}</label>
          <Select v-model="setupForm.location_id" :options="locationOptions" option-label="label" option-value="value" filter />
        </div>

        <div class="field-inline">
          <Checkbox v-model="setupForm.is_shelf" binary input-id="is-shelf" />
          <label for="is-shelf">{{ pt('Customers pick from this location') }}</label>
        </div>

        <template v-if="setupForm.is_shelf">
          <div class="field-row">
            <div class="field">
              <label>{{ pt('Aisle') }}</label>
              <InputText v-model="setupForm.aisle" />
            </div>
            <div class="field">
              <label>{{ pt('Bay') }}</label>
              <InputText v-model="setupForm.bay" />
            </div>
            <div class="field">
              <label>{{ pt('Level') }}</label>
              <InputNumber v-model="setupForm.shelf_level" :min="1" :max="20" />
            </div>
          </div>
          <small class="hint">{{ pt('Level 1 is the bottom shelf. This is the address printed on shelf-edge labels.') }}</small>
        </template>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="setupVisible = false" />
        <Button :label="pt('Save')" :loading="saving" :disabled="!setupForm.location_id" @click="saveSetup" />
      </template>
    </Dialog>

    <Dialog v-model:visible="placeVisible" modal :header="pt('Shelf Placement')" :style="{ width: '30rem' }">
      <div class="form">
        <div class="field">
          <label>{{ pt('Product') }}</label>
          <Select v-model="placeForm.product_id" :options="productOptions" option-label="label" option-value="value" filter />
        </div>
        <div class="field-row">
          <div class="field">
            <label>{{ pt('Facings') }}</label>
            <InputNumber v-model="placeForm.facings" :min="1" :max="999" />
          </div>
          <div class="field">
            <label>{{ pt('Order on the bay') }}</label>
            <InputNumber v-model="placeForm.sequence" :min="0" />
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>{{ pt('Minimum') }}</label>
            <InputNumber v-model="placeForm.min_qty" :min="0" :max-fraction-digits="3" />
          </div>
          <div class="field">
            <label>{{ pt('Full shelf') }}</label>
            <InputNumber v-model="placeForm.max_qty" :min="0" :max-fraction-digits="3" />
          </div>
        </div>
        <small class="hint">
          {{ pt('Below the minimum, the line joins the replenishment round; the round tops it back up to a full shelf.') }}
        </small>
        <div class="field-inline">
          <Checkbox v-model="placeForm.is_active" binary input-id="pl-active" />
          <label for="pl-active">{{ pt('Active') }}</label>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="placeVisible = false" />
        <Button :label="pt('Save')" :loading="saving" :disabled="!placeForm.product_id" @click="savePlacement" />
      </template>
    </Dialog>

    <Dialog v-model:visible="fillVisible" modal :header="pt('Fill the Shelf')" :style="{ width: '28rem' }">
      <div v-if="fillRow" class="form">
        <p class="hint">
          <strong>{{ fillRow.name }}</strong> — {{ fillRow.address }}<br />
          {{ pt('On shelf') }}: {{ fillRow.on_shelf }} · {{ pt('Full shelf') }}: {{ fillRow.max_qty }}
        </p>
        <div class="field">
          <label>{{ pt('Quantity to bring') }}</label>
          <InputNumber v-model="fillQty" :min="0.001" :max-fraction-digits="3" />
        </div>
        <div class="field">
          <label>{{ pt('Pick from') }}</label>
          <Select
            v-model="fillFrom"
            :options="locationOptions"
            option-label="label"
            option-value="value"
            :placeholder="pt('Wherever holds the most (usually the backroom)')"
            show-clear
            filter
          />
          <small class="hint">
            {{ pt('This moves stock inside the store — the warehouse total does not change. Use a stock transfer to move between stores.') }}
          </small>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="fillVisible = false" />
        <Button :label="pt('Fill')" :loading="saving" :disabled="!fillQty" @click="confirmFill" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.tile.danger { border-color: #dc2626; background: rgba(220, 38, 38, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.small { font-size: 0.95rem; }
.aisle { margin-bottom: 1.25rem; }
.aisle-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.4rem; }
.shelf-strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.6rem; }
.shelf { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.7rem 0.8rem; display: flex; flex-direction: column; gap: 0.15rem; }
.shelf.gapped { border-color: #e8a33d; background: rgba(232, 163, 61, 0.07); }
.shelf.bare { border-color: #dc2626; background: rgba(220, 38, 38, 0.06); }
.s-name { font-weight: 600; }
.s-meta { font-size: 0.7rem; color: #6b7280; }
.s-stat { font-size: 0.8rem; margin-top: 0.2rem; }
.s-gap { font-size: 0.75rem; font-weight: 600; color: #b45309; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.section { margin: 1.5rem 0 0.6rem; font-size: 0.95rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.tiny { font-size: 0.65rem; margin-left: 0.35rem; }
.text-green { color: #16a34a; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; flex: 1; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-row { display: flex; gap: 0.6rem; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { font-size: 0.72rem; color: #9ca3af; line-height: 1.5; }
</style>
