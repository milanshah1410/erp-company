<script setup lang="ts">
/**
 * The milk collection counter, and the rate chart on the wall behind it.
 *
 * The entry form previews the rate and amount from the server as the counter
 * types, using the same arithmetic that will be stored — a preview computed in the
 * browser would eventually disagree with the slip, and the farmer is standing
 * there holding the can.
 *
 * Out-of-band fat is warned about, never refused: rejecting a can is a decision a
 * person takes at the counter, and software that blocks the entry just means the
 * milk is taken and never recorded.
 */
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
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

const tab = ref('entries')

const rows = ref<any[]>([])
const charts = ref<any[]>([])
const farmers = ref<any[]>([])
const farmerRows = ref<any[]>([])
const summary = ref({
  collections: 0, farmers: 0, litres: 0, amount: 0,
  unsettled_amount: 0, avg_fat: 0, morning_litres: 0, evening_litres: 0,
})
const loading = ref(false)
const saving = ref(false)

const from = ref<Date>(new Date())
const to = ref<Date>(new Date())
const shiftFilter = ref<string | null>(null)
const unsettledOnly = ref(false)

const entryVisible = ref(false)
const chartVisible = ref(false)
const editingChart = ref<any>(null)
const errors = ref<Record<string, string[]>>({})
const preview = ref<any>(null)

const MILK_TYPES = computed(() => [
  { label: pt('Cow'), value: 'cow' },
  { label: pt('Buffalo'), value: 'buffalo' },
  { label: pt('Mixed'), value: 'mixed' },
])

const SHIFTS = computed(() => [
  { label: pt('Morning'), value: 'morning' },
  { label: pt('Evening'), value: 'evening' },
])

const BASES = computed(() => [
  { label: pt('Flat rate'), value: 'flat' },
  { label: pt('Fat only'), value: 'fat' },
  { label: pt('Fat + SNF'), value: 'fat_snf' },
])

function ymd(d: Date) {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

const blankEntry = () => ({
  supplier_id: null as number | null,
  collection_date: new Date(),
  shift: new Date().getHours() < 14 ? 'morning' : 'evening',
  milk_type: 'cow',
  quantity_litres: null as number | null,
  fat_percent: null as number | null,
  snf_percent: null as number | null,
  clr: null as number | null,
  rate_per_litre: null as number | null,
  remarks: '',
})

const entry = ref<any>(blankEntry())

const blankChart = () => ({
  name: '',
  milk_type: 'cow',
  basis: 'fat',
  flat_rate: 0,
  rate_per_fat_point: 7,
  rate_per_snf_point: 0,
  min_fat: 3,
  max_fat: 9,
  adjustment_per_litre: 0,
  effective_from: new Date(),
  is_active: true,
})

const chartForm = ref<any>(blankChart())

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/milk-collections', {
      params: {
        from: ymd(from.value),
        to: ymd(to.value),
        shift: shiftFilter.value || undefined,
        unsettled: unsettledOnly.value ? 1 : undefined,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [ch, sup] = await Promise.all([
    http.get('/milk-rate-charts'),
    http.get('/suppliers', { params: { per_page: 500 } }),
  ])
  charts.value = ch.data.data
  farmers.value = sup.data.data
}

async function loadFarmerSummary() {
  const { data } = await http.get('/milk-collections/farmer-summary', {
    params: { from: ymd(from.value), to: ymd(to.value) },
  })
  farmerRows.value = data.data
}

/** Ask the server what the reading is worth, so the slip and the preview agree. */
async function refreshPreview() {
  if (!entry.value.quantity_litres) {
    preview.value = null
    return
  }

  try {
    const { data } = await http.post('/milk-collections/preview', {
      milk_type: entry.value.milk_type,
      quantity_litres: entry.value.quantity_litres,
      fat_percent: entry.value.fat_percent ?? 0,
      snf_percent: entry.value.snf_percent ?? 0,
      collection_date: ymd(entry.value.collection_date),
    })
    preview.value = data.data
  } catch {
    preview.value = null
  }
}

watch(
  () => [entry.value.quantity_litres, entry.value.fat_percent, entry.value.snf_percent, entry.value.milk_type],
  refreshPreview,
)

function openEntry() {
  entry.value = blankEntry()
  preview.value = null
  errors.value = {}
  entryVisible.value = true
}

async function saveEntry() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/milk-collections', {
      ...entry.value,
      collection_date: ymd(entry.value.collection_date),
      rate_per_litre: entry.value.rate_per_litre || undefined,
    })
    entryVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Collection recorded'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not record the collection'),
      detail: e.response?.data?.message,
      life: 4500,
    })
  } finally {
    saving.value = false
  }
}

function settle(row: any) {
  confirm.require({
    message: pt('Settle this farmer for the shown period? It raises one purchase invoice over every unsettled can.'),
    header: pt('Settle farmer'),
    icon: 'pi pi-wallet',
    accept: async () => {
      try {
        const { data } = await http.post('/milk-collections/settle', {
          supplier_id: row.supplier_id,
          from: ymd(from.value),
          to: ymd(to.value),
        })
        await Promise.all([load(), loadFarmerSummary()])
        toast.add({ severity: 'success', summary: data.message, life: 4000 })
      } catch (e: any) {
        toast.add({ severity: 'error', summary: pt('Could not settle'), detail: e.response?.data?.message, life: 4000 })
      }
    },
  })
}

function removeEntry(row: any) {
  confirm.require({
    message: pt('Delete this collection? Adjust the milk stock it brought in separately.'),
    header: pt('Delete collection'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      try {
        const { data } = await http.delete(`/milk-collections/${row.id}`)
        await load()
        toast.add({ severity: 'success', summary: data.message, life: 4000 })
      } catch (e: any) {
        toast.add({ severity: 'error', summary: pt('Could not delete'), detail: e.response?.data?.message, life: 4000 })
      }
    },
  })
}

function openChart(row: any = null) {
  editingChart.value = row
  chartForm.value = row
    ? {
        name: row.name,
        milk_type: row.milk_type,
        basis: row.basis,
        flat_rate: Number(row.flat_rate),
        rate_per_fat_point: Number(row.rate_per_fat_point),
        rate_per_snf_point: Number(row.rate_per_snf_point),
        min_fat: Number(row.min_fat),
        max_fat: Number(row.max_fat),
        adjustment_per_litre: Number(row.adjustment_per_litre),
        effective_from: new Date(row.effective_from),
        is_active: row.is_active,
      }
    : blankChart()
  errors.value = {}
  chartVisible.value = true
}

async function saveChart() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...chartForm.value, effective_from: ymd(chartForm.value.effective_from) }
    if (editingChart.value) {
      await http.put(`/milk-rate-charts/${editingChart.value.id}`, payload)
    } else {
      await http.post('/milk-rate-charts', payload)
    }
    chartVisible.value = false
    await loadRefs()
    toast.add({ severity: 'success', summary: pt('Rate chart saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
  } finally {
    saving.value = false
  }
}

function removeChart(row: any) {
  confirm.require({
    message: pt('Delete this chart? If it has priced collections it is deactivated instead.'),
    header: pt('Delete rate chart'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      const { data } = await http.delete(`/milk-rate-charts/${row.id}`)
      await loadRefs()
      toast.add({ severity: 'success', summary: data.message, life: 3500 })
    },
  })
}

const farmerOptions = computed(() =>
  farmers.value.map((f) => ({ label: `${f.code} — ${f.name}`, value: f.id })),
)

watch(tab, (value) => {
  if (value === 'farmers') loadFarmerSummary()
})

onMounted(() => {
  load()
  loadRefs()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Milk Collection')"
      :subtitle="pt('Milk is bought by the fat in the litre — the chart prices it, and the rate is stored on the can')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Litres') }}</span>
        <span class="t-value">{{ summary.litres }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Farmers') }}</span>
        <span class="t-value">{{ summary.farmers }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Avg fat') }}</span>
        <span class="t-value">{{ summary.avg_fat }}%</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Value') }}</span>
        <span class="t-value">{{ formatCurrency(summary.amount) }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.unsettled_amount > 0 }">
        <span class="t-label">{{ pt('Unsettled') }}</span>
        <span class="t-value">{{ formatCurrency(summary.unsettled_amount) }}</span>
      </div>
    </div>

    <div class="toolbar">
      <DatePicker v-model="from" date-format="yy-mm-dd" show-icon :placeholder="pt('From')" @date-select="load" />
      <DatePicker v-model="to" date-format="yy-mm-dd" show-icon :placeholder="pt('To')" @date-select="load" />
      <Select
        v-model="shiftFilter"
        :options="SHIFTS"
        option-label="label"
        option-value="value"
        :placeholder="pt('Both shifts')"
        show-clear
        @change="load"
      />
      <div class="field-inline">
        <Checkbox v-model="unsettledOnly" binary input-id="unsettled" @change="load" />
        <label for="unsettled">{{ pt('Unsettled only') }}</label>
      </div>
      <Button icon="pi pi-plus" :label="pt('Record Collection')" @click="openEntry" />
    </div>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="entries">{{ pt('Collections') }}</Tab>
        <Tab value="farmers">{{ pt('Farmer summary') }}</Tab>
        <Tab value="charts">{{ pt('Rate charts') }}</Tab>
      </TabList>

      <TabPanels>
        <TabPanel value="entries">
          <div class="sub-toolbar">
            <span class="muted">
              {{ pt('Morning') }} {{ summary.morning_litres }} L ·
              {{ pt('Evening') }} {{ summary.evening_litres }} L
            </span>
            <TableExportButtons
              title="Milk Collections"
              filenameBase="milk-collections"
              :rows="rows"
              :columns="[
                { key: 'collection_number', label: 'No.' },
                { key: 'collection_date', label: 'Date' },
                { key: 'shift', label: 'Shift' },
                { key: 'farmer', label: 'Farmer' },
                { key: 'milk_type', label: 'Type' },
                { key: 'quantity_litres', label: 'Litres' },
                { key: 'fat_percent', label: 'Fat %' },
                { key: 'snf_percent', label: 'SNF %' },
                { key: 'rate_per_litre', label: 'Rate' },
                { key: 'amount', label: 'Amount' },
              ]"
            />
          </div>

          <DataTable :value="rows" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
            <Column field="collection_date" :header="pt('Date')" sortable />
            <Column field="shift" :header="pt('Shift')" sortable>
              <template #body="{ data }">{{ pt(data.shift) }}</template>
            </Column>
            <Column field="farmer" :header="pt('Farmer')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <span>{{ data.farmer }}</span>
                  <small class="muted">{{ data.farmer_code }} · {{ pt(data.milk_type) }}</small>
                </div>
              </template>
            </Column>
            <Column field="quantity_litres" :header="pt('Litres')" sortable />
            <Column :header="pt('Quality')">
              <template #body="{ data }">
                <span>{{ pt('Fat') }} {{ data.fat_percent }}%</span>
                <small class="muted"> · {{ pt('SNF') }} {{ data.snf_percent }}%</small>
              </template>
            </Column>
            <Column field="rate_per_litre" :header="pt('Rate / L')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.rate_per_litre) }}</template>
            </Column>
            <Column field="amount" :header="pt('Amount')" sortable>
              <template #body="{ data }">
                <strong>{{ formatCurrency(data.amount) }}</strong>
              </template>
            </Column>
            <Column :header="pt('Settled')">
              <template #body="{ data }">
                <Tag
                  :value="data.is_settled ? pt('Paid') : pt('Due')"
                  :severity="data.is_settled ? 'success' : 'warn'"
                />
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button
                  v-if="!data.is_settled"
                  icon="pi pi-trash"
                  text
                  severity="danger"
                  size="small"
                  @click="removeEntry(data)"
                />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('No collections in that range.') }}</div>
            </template>
          </DataTable>
        </TabPanel>

        <TabPanel value="farmers">
          <DataTable :value="farmerRows" data-key="supplier_id" striped-rows responsive-layout="scroll">
            <Column field="supplier" :header="pt('Farmer')" sortable />
            <Column field="collections" :header="pt('Cans')" sortable />
            <Column field="litres" :header="pt('Litres')" sortable />
            <Column field="avg_fat" :header="pt('Avg fat')" sortable>
              <template #body="{ data }">{{ data.avg_fat }}%</template>
            </Column>
            <Column field="avg_snf" :header="pt('Avg SNF')" sortable>
              <template #body="{ data }">{{ data.avg_snf }}%</template>
            </Column>
            <Column field="amount" :header="pt('Total')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.amount) }}</template>
            </Column>
            <Column field="unsettled" :header="pt('Unsettled')" sortable>
              <template #body="{ data }">
                <strong :class="{ due: data.unsettled > 0 }">{{ formatCurrency(data.unsettled) }}</strong>
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button
                  v-if="data.unsettled > 0"
                  icon="pi pi-wallet"
                  text
                  size="small"
                  :label="pt('Settle')"
                  @click="settle(data)"
                />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('Nothing collected in that range.') }}</div>
            </template>
          </DataTable>
        </TabPanel>

        <TabPanel value="charts">
          <div class="sub-toolbar">
            <Button icon="pi pi-plus" :label="pt('New Rate Chart')" @click="openChart()" />
          </div>

          <DataTable :value="charts" data-key="id" striped-rows responsive-layout="scroll">
            <Column field="name" :header="pt('Chart')" sortable />
            <Column field="milk_type" :header="pt('Milk')" sortable>
              <template #body="{ data }">{{ pt(data.milk_type) }}</template>
            </Column>
            <Column field="basis" :header="pt('Basis')" sortable>
              <template #body="{ data }">{{ pt(data.basis) }}</template>
            </Column>
            <Column :header="pt('Rates')">
              <template #body="{ data }">
                <span v-if="data.basis === 'flat'">{{ formatCurrency(data.flat_rate) }} / L</span>
                <span v-else>
                  {{ pt('fat') }} × {{ data.rate_per_fat_point }}
                  <span v-if="data.basis === 'fat_snf'"> + {{ pt('snf') }} × {{ data.rate_per_snf_point }}</span>
                </span>
              </template>
            </Column>
            <Column :header="pt('Band')">
              <template #body="{ data }">{{ data.min_fat }}%–{{ data.max_fat }}%</template>
            </Column>
            <Column field="example_rate_at_6_fat" :header="pt('At 6% fat')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.example_rate_at_6_fat) }}</template>
            </Column>
            <Column field="effective_from" :header="pt('Effective from')" sortable />
            <Column :header="pt('Active')">
              <template #body="{ data }">
                <Tag
                  :value="data.is_active ? pt('Yes') : pt('No')"
                  :severity="data.is_active ? 'success' : 'secondary'"
                />
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button icon="pi pi-pencil" text size="small" @click="openChart(data)" />
                <Button icon="pi pi-trash" text severity="danger" size="small" @click="removeChart(data)" />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('No rate charts yet — collections cannot be priced without one.') }}</div>
            </template>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <!-- Record a can -->
    <Dialog v-model:visible="entryVisible" modal :header="pt('Record Collection')" :style="{ width: '36rem' }">
      <div class="form">
        <div class="field">
          <label>{{ pt('Farmer') }}</label>
          <Select
            v-model="entry.supplier_id"
            :options="farmerOptions"
            option-label="label"
            option-value="value"
            filter
          />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Date') }}</label>
            <DatePicker v-model="entry.collection_date" date-format="yy-mm-dd" show-icon />
            <small v-if="errors.collection_date" class="err">{{ errors.collection_date[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Shift') }}</label>
            <Select v-model="entry.shift" :options="SHIFTS" option-label="label" option-value="value" />
          </div>
          <div class="field">
            <label>{{ pt('Milk') }}</label>
            <Select v-model="entry.milk_type" :options="MILK_TYPES" option-label="label" option-value="value" />
          </div>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Litres') }}</label>
            <InputNumber v-model="entry.quantity_litres" :min="0" :max-fraction-digits="3" />
            <small v-if="errors.quantity_litres" class="err">{{ errors.quantity_litres[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Fat %') }}</label>
            <InputNumber v-model="entry.fat_percent" :min="0" :max="15" :max-fraction-digits="2" />
            <small v-if="errors.fat_percent" class="err">{{ errors.fat_percent[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('SNF %') }}</label>
            <InputNumber v-model="entry.snf_percent" :min="0" :max="15" :max-fraction-digits="2" />
          </div>
        </div>

        <Message v-if="preview && !preview.in_band" severity="warn" :closable="false">
          {{ pt('This reading is outside the chart\'s acceptance band. Recording it anyway is allowed.') }}
        </Message>

        <Message v-if="preview && !preview.chart" severity="warn" :closable="false">
          {{ preview.message }}
        </Message>

        <div v-if="preview" class="preview">
          <span>{{ pt('Chart') }}: <strong>{{ preview.chart?.name ?? '—' }}</strong></span>
          <span>{{ pt('Rate') }}: <strong>{{ formatCurrency(preview.rate_per_litre) }}</strong></span>
          <span>{{ pt('Amount') }}: <strong>{{ formatCurrency(preview.amount) }}</strong></span>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Override rate / L') }}</label>
            <InputNumber v-model="entry.rate_per_litre" :min="0" :max-fraction-digits="4" />
            <small class="hint">{{ pt('Leave blank to use the chart') }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Remarks') }}</label>
            <InputText v-model="entry.remarks" />
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="entryVisible = false" />
        <Button :label="pt('Record')" :loading="saving" @click="saveEntry" />
      </template>
    </Dialog>

    <!-- Rate chart -->
    <Dialog
      v-model:visible="chartVisible"
      modal
      :header="editingChart ? pt('Edit Rate Chart') : pt('New Rate Chart')"
      :style="{ width: '34rem' }"
    >
      <div class="form">
        <div class="field">
          <label>{{ pt('Name') }}</label>
          <InputText v-model="chartForm.name" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Milk type') }}</label>
            <Select v-model="chartForm.milk_type" :options="MILK_TYPES" option-label="label" option-value="value" />
          </div>
          <div class="field">
            <label>{{ pt('Basis') }}</label>
            <Select v-model="chartForm.basis" :options="BASES" option-label="label" option-value="value" />
          </div>
        </div>

        <div v-if="chartForm.basis === 'flat'" class="field">
          <label>{{ pt('Flat rate / L') }}</label>
          <InputNumber v-model="chartForm.flat_rate" :min="0" :max-fraction-digits="4" />
        </div>

        <div v-else class="row">
          <div class="field">
            <label>{{ pt('Rate per fat point') }}</label>
            <InputNumber v-model="chartForm.rate_per_fat_point" :min="0" :max-fraction-digits="4" />
          </div>
          <div v-if="chartForm.basis === 'fat_snf'" class="field">
            <label>{{ pt('Rate per SNF point') }}</label>
            <InputNumber v-model="chartForm.rate_per_snf_point" :min="0" :max-fraction-digits="4" />
          </div>
        </div>

        <div class="row3">
          <div class="field">
            <label>{{ pt('Min fat %') }}</label>
            <InputNumber v-model="chartForm.min_fat" :min="0" :max="15" :max-fraction-digits="2" />
          </div>
          <div class="field">
            <label>{{ pt('Max fat %') }}</label>
            <InputNumber v-model="chartForm.max_fat" :min="0" :max="15" :max-fraction-digits="2" />
          </div>
          <div class="field">
            <label>{{ pt('Adjustment / L') }}</label>
            <InputNumber v-model="chartForm.adjustment_per_litre" :max-fraction-digits="4" />
            <small class="hint">{{ pt('Negative to deduct') }}</small>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Effective from') }}</label>
          <DatePicker v-model="chartForm.effective_from" date-format="yy-mm-dd" show-icon />
        </div>

        <div class="field-inline">
          <Checkbox v-model="chartForm.is_active" binary input-id="chart-active" />
          <label for="chart-active">{{ pt('Active') }}</label>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="chartVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="saveChart" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.sub-toolbar { display: flex; gap: 0.75rem; align-items: center; justify-content: space-between; flex-wrap: wrap; margin: 1rem 0; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; font-size: 0.8rem; }
.due { color: #dc2626; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.row3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
.hint { font-size: 0.7rem; color: #9ca3af; }
.err { color: #dc2626; font-size: 0.75rem; }
.preview { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.85rem; border: 1px dashed var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.6rem 0.75rem; }
</style>
