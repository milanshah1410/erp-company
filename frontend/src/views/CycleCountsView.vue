<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/cycle-counts')

const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('cycle-counts.create'))
const canEdit = computed(() => auth.can('cycle-counts.edit'))
const canComplete = computed(() => auth.can('cycle-counts.approve'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  completed: 'success',
  cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}

// --- Count sheet dialog (create draft / enter counts / view variance report) ---
const sheetVisible = ref(false)
const saving = ref(false)
const loadingStock = ref(false)
const sheet = ref<any>({ id: null, status: 'draft', warehouse_id: null, count_date: today(), notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

const isNew = computed(() => !sheet.value.id)
const isDraft = computed(() => sheet.value.status === 'draft')

const summary = computed(() => {
  const items = sheet.value.items || []
  const withVariance = items.filter((i: any) => i.variance !== null && Number(i.variance) !== 0)
  return {
    total: items.length,
    counted: items.filter((i: any) => i.counted_quantity !== null && i.counted_quantity !== undefined).length,
    withVariance: withVariance.length,
    varianceQty: items.reduce((s: number, i: any) => s + (Number(i.variance) || 0), 0),
    varianceValue: items.reduce((s: number, i: any) => s + (Number(i.variance) || 0) * (Number(i.unit_cost) || 0), 0),
  }
})

function openCreate() {
  sheet.value = { id: null, status: 'draft', warehouse_id: null, count_date: today(), notes: '', items: [] }
  errors.value = {}
  sheetVisible.value = true
}

async function openSheet(row: any) {
  try {
    const { data } = await http.get(`/cycle-counts/${row.id}`)
    sheet.value = data.data
    errors.value = {}
    sheetVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load cycle count', life: 3000 })
  }
}

/** Pre-fill the sheet with every stocked item in the selected warehouse. */
async function loadStockedItems() {
  if (!sheet.value.warehouse_id) {
    toast.add({ severity: 'warn', summary: 'Select a warehouse first', life: 2500 })
    return
  }
  loadingStock.value = true
  try {
    const existing = new Set(sheet.value.items.map((i: any) => `${i.product_id}:${i.variant_id ?? ''}`))
    let page = 1
    let added = 0
    for (;;) {
      const { data } = await http.get('/stock', { params: { warehouse_id: sheet.value.warehouse_id, per_page: 100, page } })
      const rows = data.data ?? []
      for (const s of rows) {
        const key = `${s.product_id}:${s.variant_id ?? ''}`
        if (existing.has(key)) continue
        existing.add(key)
        added++
        sheet.value.items.push({
          product_id: s.product_id,
          product: s.product,
          variant_id: s.variant_id,
          system_quantity: s.quantity,
          counted_quantity: null,
          variance: null,
          unit_cost: s.average_cost,
          notes: '',
        })
      }
      if (!data.meta || page >= data.meta.last_page) break
      page++
    }
    toast.add({ severity: added ? 'success' : 'info', summary: added ? `${added} item(s) added to the sheet` : 'No new items to add', life: 2500 })
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load stock', life: 3000 })
  } finally {
    loadingStock.value = false
  }
}

function addLine() {
  sheet.value.items.push({ product_id: null, product: null, variant_id: null, system_quantity: null, counted_quantity: null, variance: null, unit_cost: 0, notes: '' })
}
function removeLine(i: number | string) {
  sheet.value.items.splice(Number(i), 1)
}
function productName(line: any) {
  if (line.product) return `${line.product.sku ? line.product.sku + ' — ' : ''}${line.product.name}`
  const p = products.value.find((x) => x.id === line.product_id)
  return p ? `${p.sku ? p.sku + ' — ' : ''}${p.name}` : ''
}

async function saveSheet(showToast = true) {
  saving.value = true
  errors.value = {}
  const body = {
    warehouse_id: sheet.value.warehouse_id,
    count_date: sheet.value.count_date,
    notes: sheet.value.notes,
    items: sheet.value.items.map((i: any) => ({
      product_id: i.product_id,
      variant_id: i.variant_id,
      counted_quantity: i.counted_quantity,
      notes: i.notes || null,
    })),
  }
  try {
    if (isNew.value) {
      const { data } = await http.post('/cycle-counts', body)
      sheet.value = data.data
    } else {
      const { data } = await http.put(`/cycle-counts/${sheet.value.id}`, body)
      sheet.value = data.data
    }
    if (showToast) toast.add({ severity: 'success', summary: 'Count sheet saved', life: 2500 })
    reload()
    return true
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      const msg = e.response.data.message || Object.values(errors.value)[0]?.[0]
      if (msg) toast.add({ severity: 'error', summary: 'Validation', detail: msg, life: 4000 })
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
    }
    return false
  } finally {
    saving.value = false
  }
}

async function completeSheet() {
  // Persist the latest counted quantities before posting variances.
  if (!(await saveSheet(false))) return
  const res = await Swal.fire({
    title: `Complete ${sheet.value.count_number}?`,
    text: 'Variances will be posted to the stock ledger and on-hand quantities updated. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Complete count',
  })
  if (!res.isConfirmed) return
  saving.value = true
  try {
    const { data } = await http.post(`/cycle-counts/${sheet.value.id}/complete`)
    sheet.value = data.data
    toast.add({ severity: 'success', summary: 'Cycle count completed — stock verified', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Error', detail, life: 4500 })
  } finally {
    saving.value = false
  }
}

async function cancelCount(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.count_number}?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Cancel count' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/cycle-counts/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  crud.fetch({ status: statusFilter.value || undefined, search: search.value || undefined })
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}
function qty(v: any) {
  return v === null || v === undefined ? '—' : Number(v).toLocaleString('en-IN', { maximumFractionDigits: 3 })
}
function money(v: any) {
  return v === null || v === undefined ? '—' : formatCurrency(v)
}
function varianceClass(v: any) {
  const n = Number(v)
  if (!v || !n) return 'v-zero'
  return n > 0 ? 'v-pos' : 'v-neg'
}

onMounted(async () => {
  const [w, p] = await Promise.all([
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Cycle Counts" :count="crud.total.value" />

    <div class="toolbar no-print">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
      <TableExportButtons title="Cycle Counts" endpoint="/cycle-counts"
        :params="() => ({ status: statusFilter || undefined, search: search || undefined })"
        :columns="[
          { key: 'count_number', label: 'Count #' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'count_date', label: 'Date' },
          { key: 'items_count', label: 'Items' },
          { key: 'status', label: 'Status' },
        ]" />
      <Button v-if="canCreate" label="New Cycle Count" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="count_number" header="Count #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column field="count_date" header="Date" />
      <Column field="items_count" header="Items" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 14rem">
        <template #body="{ data }">
          <Button v-if="canEdit && data.status === 'draft'" label="Count" size="small" icon="pi pi-pencil" @click="openSheet(data)" />
          <Button v-if="data.status !== 'draft'" label="Variance Report" size="small" outlined icon="pi pi-chart-bar" @click="openSheet(data)" />
          <Button v-if="canEdit && data.status === 'draft'" label="Cancel" size="small" text severity="danger" @click="cancelCount(data)" style="margin-left: 4px" />
        </template>
      </Column>
      <template #empty>No cycle counts yet.</template>
    </DataTable>

    <!-- Count sheet / variance report dialog -->
    <Dialog v-model:visible="sheetVisible" modal :header="isNew ? 'New Cycle Count' : `${sheet.count_number} — ${isDraft ? 'Count Sheet' : 'Variance Report'}`" :style="{ width: '860px' }">
      <div class="form-top">
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="sheet.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value"
            placeholder="Select warehouse" :disabled="!isDraft || sheet.items.length > 0" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Count Date *</label>
          <input type="date" v-model="sheet.count_date" class="date-input" :disabled="!isDraft" />
        </div>
      </div>

      <div class="lines-head">
        <h3 class="lines-title">Count Sheet</h3>
        <div v-if="isDraft" class="line-actions">
          <Button label="Load stocked items" icon="pi pi-download" size="small" outlined :loading="loadingStock" @click="loadStockedItems" />
          <Button label="Add Line" icon="pi pi-plus" size="small" text @click="addLine" />
        </div>
      </div>

      <div class="sheet-scroll">
        <table class="lines">
          <thead>
            <tr>
              <th style="width: 34%">Product</th>
              <th>System Qty</th>
              <th>Counted Qty</th>
              <th>Variance</th>
              <th v-if="!isDraft">Variance Value</th>
              <th>Remark</th>
              <th v-if="isDraft"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(line, i) in sheet.items" :key="line.id ?? `n${i}`">
              <td>
                <Select v-if="isDraft && !line.product" v-model="line.product_id" :options="products" optionLabel="name" optionValue="id"
                  filter placeholder="Product" class="w-full" />
                <span v-else>{{ productName(line) }}</span>
              </td>
              <td class="qty">{{ qty(line.system_quantity) }}</td>
              <td>
                <InputNumber v-if="isDraft" v-model="line.counted_quantity" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num" placeholder="—" />
                <span v-else class="qty">{{ qty(line.counted_quantity) }}</span>
              </td>
              <td class="qty" :class="varianceClass(isDraft ? null : line.variance)">
                <template v-if="!isDraft">{{ qty(line.variance) }}</template>
                <template v-else-if="line.counted_quantity !== null && line.counted_quantity !== undefined && line.system_quantity !== null">
                  {{ qty(Number(line.counted_quantity) - Number(line.system_quantity)) }}
                </template>
                <template v-else>—</template>
              </td>
              <td v-if="!isDraft" class="qty" :class="varianceClass(line.variance)">{{ money(line.variance_value ?? (Number(line.variance) || 0) * (Number(line.unit_cost) || 0)) }}</td>
              <td>
                <input v-if="isDraft" v-model="line.notes" class="date-input remark" placeholder="Optional" />
                <span v-else>{{ line.notes || '—' }}</span>
              </td>
              <td v-if="isDraft"><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
            </tr>
            <tr v-if="!sheet.items.length">
              <td colspan="7" class="empty-row">No items on this count sheet yet — load stocked items or add lines manually.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="summary">
        <span>Items: <strong>{{ summary.total }}</strong></span>
        <span>Counted: <strong>{{ summary.counted }}/{{ summary.total }}</strong></span>
        <template v-if="!isDraft">
          <span>With variance: <strong>{{ summary.withVariance }}</strong></span>
          <span>Net variance: <strong :class="varianceClass(summary.varianceQty)">{{ qty(summary.varianceQty) }}</strong></span>
          <span>Variance value: <strong :class="varianceClass(summary.varianceValue)">{{ money(summary.varianceValue) }}</strong></span>
        </template>
      </div>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="sheet.notes" rows="2" :disabled="!isDraft" />
      </div>

      <template #footer>
        <Button label="Close" text @click="sheetVisible = false" />
        <template v-if="isDraft">
          <Button v-if="canCreate || canEdit" label="Save Draft" outlined :loading="saving" @click="saveSheet()" />
          <Button v-if="canComplete && !isNew" label="Complete & Post Variance" icon="pi pi-check" :loading="saving"
            :disabled="!sheet.items.length" @click="completeSheet" />
        </template>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.status-filter { width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-top { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-head { display: flex; justify-content: space-between; align-items: center; margin: 1.25rem 0 0.5rem; }
.lines-title { font-size: 1rem; margin: 0; }
.line-actions { display: flex; gap: 0.4rem; }
.sheet-scroll { max-height: 380px; overflow-y: auto; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: #fff; }
.lines td { padding: 0.35rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 100px; }
.qty { font-variant-numeric: tabular-nums; white-space: nowrap; }
.remark { width: 130px; padding: 0.4rem 0.5rem; font-size: 0.85rem; }
.empty-row { text-align: center; color: #6b7280; padding: 1rem 0; }
.v-pos { color: #16a34a; }
.v-neg { color: #dc2626; }
.v-zero { color: #6b7280; }
.summary { display: flex; gap: 1.5rem; margin-top: 0.9rem; font-size: 0.9rem; color: #374151; flex-wrap: wrap; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
</style>
