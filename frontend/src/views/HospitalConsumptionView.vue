<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import SelectButton from 'primevue/selectbutton'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

/**
 * Department-wise consumption — the report the hospital store exists to produce.
 *
 * Only issued quantities count. An approved-but-unissued line is a promise, not
 * consumption, and including it would overstate every ward's spend for as long
 * as the store is behind.
 */

const pt = usePt()

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)

const departments = ref<any[]>([])
const warehouses = ref<any[]>([])

const startOfMonth = () => {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1)
}

const from = ref<Date | null>(startOfMonth())
const to = ref<Date | null>(new Date())
const departmentId = ref<number | null>(null)
const warehouseId = ref<number | null>(null)
const groupBy = ref<'department' | 'product'>('department')

const GROUP_OPTIONS = [
  { label: 'By department', value: 'department' },
  { label: 'By item', value: 'product' },
]

function iso(d: Date | null) {
  if (!d) return undefined
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/hospital-consumption', {
      params: {
        from: iso(from.value),
        to: iso(to.value),
        department_id: departmentId.value || undefined,
        warehouse_id: warehouseId.value || undefined,
        group_by: groupBy.value,
      },
    })
    rows.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

async function loadLookups() {
  const [d, w] = await Promise.all([
    http.get('/departments', { params: { per_page: 200 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  departments.value = (d.data.data ?? d.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Drill-down --------------------------------------------------------------

const detailVisible = ref(false)
const detailRows = ref<any[]>([])
const detailLabel = ref('')
const detailTotal = ref(0)

async function drill(row: any) {
  // Only the department cut drills down: "which items did this ward use" is a
  // question with an answer, while "which ward used this item" is the other
  // grouping and is one click away already.
  if (groupBy.value !== 'department') return

  detailLabel.value = row.label
  const { data } = await http.get('/hospital-consumption/detail', {
    params: { department_id: row.id, from: iso(from.value), to: iso(to.value) },
  })
  detailRows.value = data.data
  detailTotal.value = data.meta.total_value
  detailVisible.value = true
}

onMounted(async () => {
  await Promise.all([loadLookups(), load()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Department Consumption')"
      :subtitle="pt('What each ward actually drew from the store, and what it cost')"
    />

    <div class="toolbar">
      <DatePicker v-model="from" dateFormat="yy-mm-dd" :placeholder="pt('From')" showIcon />
      <DatePicker v-model="to" dateFormat="yy-mm-dd" :placeholder="pt('To')" showIcon />
      <Select
        v-model="departmentId"
        :options="departments"
        optionLabel="label"
        optionValue="value"
        filter
        showClear
        :placeholder="pt('All departments')"
        class="w-14"
      />
      <Select
        v-model="warehouseId"
        :options="warehouses"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All stores')"
        class="w-14"
      />
      <SelectButton
        v-model="groupBy"
        :options="GROUP_OPTIONS"
        optionLabel="label"
        optionValue="value"
        :allowEmpty="false"
        @change="load"
      />
      <Button :label="pt('Run')" icon="pi pi-refresh" @click="load" />
      <TableExportButtons
        title="Department Consumption"
        filenameBase="hospital-consumption"
        :rows="rows"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'label', label: groupBy === 'product' ? 'Item' : 'Department' },
          { key: 'quantity', label: 'Quantity' },
          { key: 'value', label: 'Value' },
        ]"
      />
    </div>

    <div class="cards">
      <div class="card">
        <span class="k">{{ pt('Period') }}</span>
        <strong>{{ meta.from }} → {{ meta.to }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Total quantity') }}</span>
        <strong>{{ meta.total_quantity ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Total value') }}</span>
        <strong>{{ meta.total_value ?? 0 }}</strong>
      </div>
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column :header="groupBy === 'product' ? pt('Item') : pt('Department')">
        <template #body="{ data }">
          <strong>{{ data.label }}</strong>
          <br /><small class="muted">{{ data.code }}</small>
        </template>
      </Column>
      <Column :header="groupBy === 'product' ? pt('Departments') : pt('Indents')">
        <template #body="{ data }">{{ data.departments ?? data.indents }}</template>
      </Column>
      <Column field="quantity" :header="pt('Quantity')" />
      <Column field="value" :header="pt('Value')" />
      <Column v-if="groupBy === 'department'" :header="''" style="width: 6rem">
        <template #body="{ data }">
          <Button :label="pt('Detail')" text size="small" @click="drill(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('Nothing was issued in this period.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="`${detailLabel} — ${pt('issued items')}`"
      :style="{ width: '52rem' }"
    >
      <DataTable :value="detailRows" dataKey="indent_number" class="p-datatable-sm">
        <Column field="indent_date" :header="pt('Date')" />
        <Column field="indent_number" :header="pt('Indent')" />
        <Column :header="pt('Item')">
          <template #body="{ data }">
            {{ data.product }}
            <br /><small class="muted">{{ data.sku }}{{ data.batch_number ? ` · ${data.batch_number}` : '' }}</small>
          </template>
        </Column>
        <Column field="quantity" :header="pt('Qty')" />
        <Column field="unit_cost" :header="pt('Unit cost')" />
        <Column field="value" :header="pt('Value')" />
        <template #empty>{{ pt('Nothing issued to this department in the period.') }}</template>
      </DataTable>
      <div class="total">{{ pt('Total') }}: {{ detailTotal }}</div>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.cards { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem 1rem; min-width: 10rem; }
.card .k { display: block; font-size: 0.72rem; color: #6b7280; }
.card strong { font-size: 1rem; }
.muted { color: #6b7280; }
.w-14 { width: 12rem; }
.total { text-align: right; font-weight: 600; margin-top: 0.75rem; }
</style>
