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
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/boms')

const products = ref<any[]>([])

const canCreate = computed(() => auth.can('boms.create'))
const canEdit = computed(() => auth.can('boms.edit'))
const canDelete = computed(() => auth.can('boms.delete'))

function money(v: number | string) {
  return formatCurrency(v)
}

const search = ref('')
const activeFilter = ref<string | null>(null)
const activeOptions = computed(() => [
  { label: pt('Active'), value: '1' },
  { label: pt('Inactive'), value: '0' },
])

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

// --- Create / edit dialog ---
const editVisible = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blankForm())

function blankForm() {
  return {
    id: null as number | null,
    name: '',
    product_id: null,
    output_quantity: 1,
    is_default: false,
    is_active: true,
    notes: '',
    items: [] as any[],
  }
}

const isEditing = computed(() => !!form.value.id)

const materialCost = computed(() =>
  form.value.items.reduce(
    (sum: number, m: any) => sum + (Number(m.quantity) || 0) * (Number(m.unit_cost) || 0),
    0,
  ),
)

function addItem() {
  form.value.items.push({ product_id: null, quantity: 1, wastage_percent: 0, unit_cost: 0, remarks: '' })
}
function removeItem(i: number | string) {
  form.value.items.splice(Number(i), 1)
}
function onItemProductPick(line: any) {
  const p = products.value.find((x) => x.id === line.product_id)
  if (p) line.unit_cost = Number(p.cost_price) || 0
}

function openCreate() {
  form.value = blankForm()
  errors.value = {}
  editVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  try {
    const { data } = await http.get(`/boms/${row.id}`)
    const b = data.data
    form.value = {
      id: b.id,
      name: b.name,
      product_id: b.product_id,
      output_quantity: Number(b.output_quantity),
      is_default: !!b.is_default,
      is_active: !!b.is_active,
      notes: b.notes || '',
      items: (b.items || []).map((m: any) => ({
        product_id: m.product_id,
        quantity: Number(m.quantity),
        wastage_percent: Number(m.wastage_percent) || 0,
        unit_cost: Number(m.unit_cost),
        remarks: m.remarks || '',
      })),
    }
    editVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: pt('Could not load BOM'), life: 3000 })
  }
}

async function saveBom() {
  saving.value = true
  errors.value = {}
  const payload = {
    name: form.value.name,
    product_id: form.value.product_id,
    output_quantity: form.value.output_quantity,
    is_default: form.value.is_default,
    is_active: form.value.is_active,
    notes: form.value.notes,
    items: form.value.items,
  }
  try {
    if (isEditing.value) {
      await http.put(`/boms/${form.value.id}`, payload)
      toast.add({ severity: 'success', summary: pt('BOM updated'), life: 2500 })
    } else {
      await http.post('/boms', payload)
      toast.add({ severity: 'success', summary: pt('Bill of materials created'), life: 2500 })
    }
    editVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function deleteBom(row: any) {
  const res = await Swal.fire({
    title: pt('Delete {n}?', { n: row.bom_number }),
    text: row.name,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/boms/${row.id}`)
    toast.add({ severity: 'success', summary: pt('BOM deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Cannot delete'), detail: e.response?.data?.message || pt('Failed'), life: 5000 })
  }
}

function reload() {
  crud.fetch({ search: search.value || undefined, is_active: activeFilter.value ?? undefined })
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const p = await http.get('/products', { params: { per_page: 100 } })
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Bills of Materials')" :count="crud.total.value" />

    <div class="toolbar no-print">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
      </span>
      <Button :label="pt('Search')" outlined @click="reload" />
      <Select v-model="activeFilter" :options="activeOptions" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All')" @change="reload" class="status-filter" />
      <TableExportButtons :title="pt('Bills of Materials')" endpoint="/boms"
        :params="() => ({ search: search || undefined, is_active: activeFilter ?? undefined })"
        :columns="[
          { key: 'bom_number', label: pt('BOM #') },
          { key: 'name', label: pt('Name') },
          { key: 'product.name', label: pt('Finished Product') },
          { key: 'output_quantity', label: pt('Output Qty') },
          { key: 'items_count', label: pt('Components') },
          { key: 'material_cost', label: pt('Material Cost') },
          { key: 'is_active', label: pt('Active'), format: (v) => v ? pt('Yes') : pt('No') },
        ]" />
      <Button v-if="canCreate" :label="pt('New BOM')" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="bom_number" :header="pt('BOM #')" />
      <Column field="name" :header="pt('Name')" />
      <Column :header="pt('Finished Product')"><template #body="{ data }">{{ data.product?.name }}</template></Column>
      <Column :header="pt('Output Qty')"><template #body="{ data }">{{ Number(data.output_quantity) }}</template></Column>
      <Column :header="pt('Components')"><template #body="{ data }">{{ data.items_count ?? 0 }}</template></Column>
      <Column :header="pt('Material Cost')"><template #body="{ data }">{{ money(data.material_cost) }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag v-if="data.is_default" severity="info" :value="pt('Default')" style="margin-right: 4px" />
          <Tag :severity="data.is_active ? 'success' : 'secondary'" :value="data.is_active ? pt('Active') : pt('Inactive')" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canEdit" :label="pt('Edit')" size="small" outlined @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="deleteBom(data)" style="margin-left: 4px" />
        </template>
      </Column>
      <template #empty>{{ pt('No bills of materials yet.') }}</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="editVisible" modal :header="isEditing ? pt('Edit BOM') : pt('New BOM')" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>{{ pt('Name') }} *</label>
          <InputText v-model="form.name" :placeholder="pt('e.g. Kids T-shirt — v1')" />
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Finished Product') }} *</label>
          <Select v-model="form.product_id" :options="products" optionLabel="name" optionValue="id" filter :placeholder="pt('Select product')" />
          <small v-if="errors.product_id" class="err">{{ errors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Output Quantity') }} *</label>
          <InputNumber v-model="form.output_quantity" :minFractionDigits="0" :maxFractionDigits="3" class="w-full" />
          <small class="muted">{{ pt('Units produced by one full run of this recipe.') }}</small>
          <small v-if="errors.output_quantity" class="err">{{ errors.output_quantity[0] }}</small>
        </div>
        <div class="fld toggles">
          <label class="chk"><Checkbox v-model="form.is_default" :binary="true" /> <span>{{ pt('Default BOM for this product') }}</span></label>
          <label class="chk"><Checkbox v-model="form.is_active" :binary="true" /> <span>{{ pt('Active') }}</span></label>
        </div>
      </div>

      <h3 class="lines-title">{{ pt('Components') }}</h3>
      <table class="lines">
        <thead>
          <tr><th style="width: 34%">{{ pt('Material') }}</th><th>{{ pt('Qty') }}</th><th>{{ pt('Wastage %') }}</th><th>{{ pt('Unit Cost') }}</th><th>{{ pt('Line Cost') }}</th><th>{{ pt('Remarks') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(m, i) in form.items" :key="i">
            <td>
              <Select v-model="m.product_id" :options="products" optionLabel="name" optionValue="id" filter :placeholder="pt('Material')" class="w-full" @change="onItemProductPick(m)" />
            </td>
            <td><InputNumber v-model="m.quantity" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><InputNumber v-model="m.wastage_percent" :min="0" :max="100" :minFractionDigits="0" :maxFractionDigits="2" class="num-sm" /></td>
            <td><InputNumber v-model="m.unit_cost" :minFractionDigits="2" :maxFractionDigits="4" class="num" /></td>
            <td class="line-total">{{ money((m.quantity || 0) * (m.unit_cost || 0)) }}</td>
            <td><input v-model="m.remarks" class="text-input" placeholder="—" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeItem(i)" /></td>
          </tr>
          <tr v-if="!form.items.length"><td colspan="7" class="muted" style="text-align: center; padding: 0.75rem">{{ pt('No components — add the raw materials this recipe consumes.') }}</td></tr>
        </tbody>
      </table>
      <Button :label="pt('Add Component')" icon="pi pi-plus" text size="small" @click="addItem" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="grand">{{ pt('Material Cost per run:') }} <strong>{{ money(materialCost) }}</strong></div>

      <div class="fld full">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="form.notes" rows="2" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="editVisible = false" />
        <Button :label="isEditing ? pt('Save Changes') : pt('Create BOM')" :loading="saving" @click="saveBom" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('BOM Details')" :fields="[
      { key: 'bom_number', label: pt('BOM #') },
      { key: 'name', label: pt('Name') },
      { key: 'product.name', label: pt('Finished Product') },
      { key: 'output_quantity', label: pt('Output Qty') },
      { key: 'items_count', label: pt('Components') },
      { key: 'material_cost', label: pt('Material Cost'), format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'is_default', label: pt('Default') },
      { key: 'is_active', label: pt('Active') },
      { key: 'notes', label: pt('Notes'), full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.status-filter { width: 140px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-top { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld.toggles { justify-content: center; gap: 0.6rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.chk { display: flex; align-items: center; gap: 0.5rem; font-weight: 400; cursor: pointer; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputnumber), .fld :deep(.p-inputtext) { width: 100%; }
.text-input { padding: 0.45rem 0.55rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; width: 100%; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 90px; }
.num-sm :deep(input) { width: 70px; }
.line-total { font-size: 0.9rem; white-space: nowrap; }
.grand { text-align: right; margin-top: 0.75rem; font-size: 1.05rem; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.muted { color: #6b7280; font-size: 0.78rem; }
.w-full :deep(input) { width: 100%; }
</style>
