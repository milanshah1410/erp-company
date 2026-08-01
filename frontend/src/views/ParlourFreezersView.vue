<script setup lang="ts">
/**
 * An ice-cream parlour's freezers and the flavours in them.
 *
 * A projection, not a module: a flavour is a product variant, a freezer is a
 * warehouse location and shelf life is a batch — all three already existed. What
 * this screen adds is the parlour's question, which is never "what is the on-hand
 * quantity of SKU ICE-042" but "how many tubs of butterscotch are in the front
 * freezer and which one goes off first".
 *
 * Sorted soonest-to-expire, because that ordering *is* the list of what to sell
 * today. Moving a tub between freezers is a stock transfer and stays in Inventory —
 * a second way to move stock would reconcile differently from the first.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()

const freezers = ref<any[]>([])
const rows = ref<any[]>([])
const summary = ref({ lines: 0, quantity: 0, flavours: 0, expiring_14d: 0, expired: 0 })
const locations = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)

const selectedFreezer = ref<number | null>(null)
const expiryFilter = ref<number | null>(null)

const setupVisible = ref(false)
const setupForm = ref<{ location_id: number | null; is_freezer: boolean; target_temp_c: number | null }>({
  location_id: null,
  is_freezer: true,
  target_temp_c: -18,
})

const EXPIRY_WINDOWS = computed(() => [
  { label: pt('Within 7 days'), value: 7 },
  { label: pt('Within 14 days'), value: 14 },
  { label: pt('Within 30 days'), value: 30 },
])

async function load() {
  loading.value = true
  try {
    const [fz, fl] = await Promise.all([
      http.get('/parlour/freezers'),
      http.get('/parlour/flavours', {
        params: {
          freezer_id: selectedFreezer.value || undefined,
          expiring_within_days: expiryFilter.value ?? undefined,
        },
      }),
    ])
    freezers.value = fz.data.data
    rows.value = fl.data.data
    summary.value = fl.data.summary
  } finally {
    loading.value = false
  }
}

async function loadLocations() {
  const { data } = await http.get('/parlour/locations')
  locations.value = data.data
}

function pickFreezer(id: number | null) {
  selectedFreezer.value = selectedFreezer.value === id ? null : id
  load()
}

function openSetup() {
  setupForm.value = { location_id: null, is_freezer: true, target_temp_c: -18 }
  setupVisible.value = true
  loadLocations()
}

async function saveSetup() {
  if (!setupForm.value.location_id) return

  saving.value = true
  try {
    await http.post(`/parlour/freezers/${setupForm.value.location_id}`, {
      is_freezer: setupForm.value.is_freezer,
      target_temp_c: setupForm.value.target_temp_c,
    })
    setupVisible.value = false
    await Promise.all([load(), loadLocations()])
    toast.add({ severity: 'success', summary: pt('Freezer updated'), life: 2500 })
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Could not update the location'),
      detail: e.response?.data?.message,
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

/** Days-to-expiry drives the badge, and negative means it has already gone. */
function expirySeverity(days: number | null) {
  if (days === null) return 'secondary'
  if (days < 0) return 'danger'
  if (days <= 7) return 'warn'
  return 'success'
}

const locationOptions = computed(() =>
  locations.value.map((l) => ({
    label: `${l.code} — ${l.name}${l.is_freezer ? ' (' + pt('freezer') + ')' : ''}`,
    value: l.id,
  })),
)

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Freezers & Flavours')"
      :subtitle="pt('A flavour is a variant and a freezer is a location — this is the parlour-shaped view over them')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Freezers') }}</span>
        <span class="t-value">{{ freezers.length }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Flavours in stock') }}</span>
        <span class="t-value">{{ summary.flavours }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Total quantity') }}</span>
        <span class="t-value">{{ summary.quantity }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.expiring_14d > 0 }">
        <span class="t-label">{{ pt('Expiring in 14 days') }}</span>
        <span class="t-value">{{ summary.expiring_14d }}</span>
      </div>
      <div class="tile" :class="{ danger: summary.expired > 0 }">
        <span class="t-label">{{ pt('Already expired') }}</span>
        <span class="t-value">{{ summary.expired }}</span>
      </div>
    </div>

    <div v-if="freezers.length" class="freezer-strip">
      <button
        v-for="f in freezers"
        :key="f.id"
        class="freezer"
        :class="{ picked: selectedFreezer === f.id }"
        @click="pickFreezer(f.id)"
      >
        <span class="f-name">{{ f.name }}</span>
        <span class="f-meta">
          {{ f.code }} · {{ f.warehouse }}
          <template v-if="f.target_temp_c !== null"> · {{ f.target_temp_c }}°C</template>
        </span>
        <span class="f-stock">{{ f.quantity }} · {{ f.flavours }} {{ pt('flavours') }}</span>
      </button>
    </div>

    <div class="toolbar">
      <Select
        v-model="expiryFilter"
        :options="EXPIRY_WINDOWS"
        option-label="label"
        option-value="value"
        :placeholder="pt('Any expiry')"
        show-clear
        @change="load"
      />
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
      <Button icon="pi pi-cog" severity="secondary" :label="pt('Set up Freezers')" @click="openSetup" />
      <TableExportButtons
        title="Freezer Stock"
        filenameBase="freezer-stock"
        :rows="rows"
        :columns="[
          { key: 'product', label: 'Product' },
          { key: 'flavour', label: 'Flavour' },
          { key: 'freezer', label: 'Freezer' },
          { key: 'quantity', label: 'Quantity' },
          { key: 'nearest_expiry', label: 'Nearest expiry' },
          { key: 'days_to_expiry', label: 'Days left' },
          { key: 'batches', label: 'Batches' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" striped-rows responsive-layout="scroll">
      <Column field="flavour" :header="pt('Flavour')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.flavour || data.product }}</strong>
            <small v-if="data.flavour" class="muted">{{ data.product }}</small>
          </div>
        </template>
      </Column>
      <Column field="freezer" :header="pt('Freezer')" sortable />
      <Column field="quantity" :header="pt('Quantity')" sortable />
      <Column field="nearest_expiry" :header="pt('Nearest expiry')" sortable>
        <template #body="{ data }">
          <span v-if="data.nearest_expiry">
            {{ data.nearest_expiry }}
            <Tag
              :value="data.days_to_expiry < 0
                ? pt('{d}d ago').replace('{d}', String(Math.abs(data.days_to_expiry)))
                : pt('{d}d').replace('{d}', String(data.days_to_expiry))"
              :severity="expirySeverity(data.days_to_expiry)"
              class="tiny"
            />
          </span>
          <span v-else class="muted">{{ pt('not batched') }}</span>
        </template>
      </Column>
      <Column field="batches" :header="pt('Batches')" sortable>
        <template #body="{ data }">
          <span v-if="data.batches">{{ data.batches }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ freezers.length
            ? pt('Nothing in the freezers matches that filter.')
            : pt('No locations are marked as freezers yet — set one up to see flavour-wise stock.') }}
        </div>
      </template>
    </DataTable>

    <Dialog v-model:visible="setupVisible" modal :header="pt('Set up Freezers')" :style="{ width: '30rem' }">
      <div class="form">
        <p class="muted">
          {{ pt('Locations themselves are created in Inventory → Warehouse Locations. This only marks one as a freezer.') }}
        </p>

        <div class="field">
          <label>{{ pt('Location') }}</label>
          <Select
            v-model="setupForm.location_id"
            :options="locationOptions"
            option-label="label"
            option-value="value"
            filter
          />
        </div>

        <div class="field-inline">
          <Checkbox v-model="setupForm.is_freezer" binary input-id="is-freezer" />
          <label for="is-freezer">{{ pt('This location is a freezer') }}</label>
        </div>

        <div v-if="setupForm.is_freezer" class="field">
          <label>{{ pt('Target temperature (°C)') }}</label>
          <InputNumber v-model="setupForm.target_temp_c" :min="-60" :max="30" :max-fraction-digits="2" />
          <small class="hint">{{ pt('Negative for a freezer — −18 is the usual ice-cream target') }}</small>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="setupVisible = false" />
        <Button :label="pt('Save')" :loading="saving" :disabled="!setupForm.location_id" @click="saveSetup" />
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
.freezer-strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.freezer { text-align: left; border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.7rem 0.8rem; background: transparent; cursor: pointer; display: flex; flex-direction: column; gap: 0.15rem; color: inherit; font: inherit; }
.freezer:hover { border-color: #93c5fd; }
.freezer.picked { border-color: #2563eb; background: rgba(37, 99, 235, 0.07); }
.f-name { font-weight: 600; }
.f-meta { font-size: 0.7rem; color: #6b7280; }
.f-stock { font-size: 0.8rem; margin-top: 0.2rem; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.tiny { font-size: 0.65rem; margin-left: 0.35rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { font-size: 0.7rem; color: #9ca3af; }
</style>
