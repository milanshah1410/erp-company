<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
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
import { useCrud } from '../composables/useCrud'
import { openPdfBlob } from '../utils/blob'
import { formatCurrency } from '../utils/currency'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/products')
const canScanBarcodes = computed(() => auth.can('barcodes.view'))

const search = ref('')
const categoryFilter = ref<number | null>(null)
const activeFilter = ref<boolean | null>(null)
const activeOptions = computed(() => [
  { label: pt('Active'), value: true },
  { label: pt('Inactive'), value: false },
])

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const categories = ref<any[]>([])
const brands = ref<any[]>([])
const units = ref<any[]>([])
const suppliers = ref<any[]>([])

const canCreate = computed(() => auth.can('products.create'))
const canEdit = computed(() => auth.can('products.edit'))
const canDelete = computed(() => auth.can('products.delete'))
const canView = computed(() => auth.can('products.view'))

function money(v: any) {
  return formatCurrency(v)
}

function trackingTags(row: any): string[] {
  const tags: string[] = []
  if (row.track_batch) tags.push(pt('batch'))
  if (row.track_serial) tags.push(pt('serial'))
  if (row.track_expiry) tags.push(pt('expiry'))
  return tags
}

const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

function reload() {
  crud.fetch({
    ...(search.value ? { search: search.value } : {}),
    ...(categoryFilter.value ? { category_id: categoryFilter.value } : {}),
    ...(activeFilter.value !== null ? { is_active: activeFilter.value } : {}),
    ...(sortField.value && sortOrder.value
      ? { sort: sortField.value, direction: sortOrder.value === 1 ? 'asc' : 'desc' }
      : {}),
  })
}

function doSearch() {
  crud.currentPage.value = 1
  reload()
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  crud.currentPage.value = 1
  reload()
}

// --- Create / Edit dialog ---
const dialogVisible = ref(false)
const editing = ref<any>(null)
const form = ref<Record<string, any>>({})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

function blankForm(): Record<string, any> {
  return {
    sku: null,
    name: null,
    barcode: null,
    rfid_tag: null,
    category_id: null,
    brand_id: null,
    unit_id: null,
    hsn_code: null,
    cost_price: 0,
    selling_price: 0,
    tax_percent: 0,
    min_stock_level: 0,
    max_stock_level: 0,
    reorder_level: 0,
    reorder_quantity: 0,
    preferred_supplier_id: null,
    description: null,
    track_batch: false,
    track_serial: false,
    track_expiry: false,
    is_active: true,
    attributes: {},
  }
}

// --- Category attributes (dynamic fields) ---
const attrDefs = ref<any[]>([])

async function loadAttrDefs(categoryId: any) {
  if (!categoryId) {
    attrDefs.value = []
    return
  }
  try {
    const { data } = await http.get(`/categories/${categoryId}/attributes`)
    attrDefs.value = data.data ?? data ?? []
  } catch {
    attrDefs.value = []
  }
}

watch(() => form.value.category_id, async (categoryId) => {
  await loadAttrDefs(categoryId)
  // Keep values whose definition still exists for the selected category, drop the rest.
  const names = new Set(attrDefs.value.map((d: any) => d.name))
  const attrs = form.value.attributes ?? {}
  for (const key of Object.keys(attrs)) {
    if (!names.has(key)) delete attrs[key]
  }
  form.value.attributes = attrs
})

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  const f = blankForm()
  for (const key of Object.keys(f)) {
    f[key] = row[key] ?? f[key]
  }
  // Normalize numeric strings so InputNumber binds correctly.
  for (const key of ['cost_price', 'selling_price', 'tax_percent', 'min_stock_level', 'max_stock_level', 'reorder_level', 'reorder_quantity']) {
    f[key] = f[key] === null || f[key] === undefined ? null : Number(f[key])
  }
  // Copy so edits don't mutate the row until saved.
  f.attributes = { ...(row.attributes ?? {}) }
  form.value = f
  errors.value = {}
  dialogVisible.value = true
  loadAttrDefs(f.category_id)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: pt('Product updated'), life: 2500 })
    } else {
      await crud.create(form.value)
      toast.add({ severity: 'success', summary: pt('Product created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this product?'),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Product deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Image upload (edit dialog only) ---
const uploadingImage = ref(false)
const editImageUrl = computed(() => editing.value?.image_url || null)

async function onImagePicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !editing.value) return
  const fd = new FormData()
  fd.append('image', file)
  uploadingImage.value = true
  try {
    const { data } = await http.post(`/products/${editing.value.id}/image`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const updated = data.data ?? data
    editing.value.image_url = updated.image_url
    editing.value.image = updated.image
    toast.add({ severity: 'success', summary: pt('Image uploaded'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Image upload failed'), life: 4000 })
  } finally {
    uploadingImage.value = false
    input.value = ''
  }
}

// --- Variants dialog ---
const variantsVisible = ref(false)
const variantsProduct = ref<any>(null)
const variants = ref<any[]>([])
const variantsLoading = ref(false)

const variantFormVisible = ref(false)
const variantEditing = ref<any>(null)
const variantForm = ref<Record<string, any>>({})
const variantErrors = ref<Record<string, string[]>>({})
const variantSaving = ref(false)

function blankVariantForm(): Record<string, any> {
  return { sku: null, name: null, barcode: null, rfid_tag: null, cost_price: 0, selling_price: 0, is_active: true }
}

async function openVariants(row: any) {
  variantsProduct.value = row
  variantFormVisible.value = false
  variantsVisible.value = true
  await loadVariants()
}

async function loadVariants() {
  if (!variantsProduct.value) return
  variantsLoading.value = true
  try {
    const { data } = await http.get(`/products/${variantsProduct.value.id}/variants`)
    variants.value = data.data ?? data
  } catch (e: any) {
    variants.value = []
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Could not load variants'), life: 4000 })
  } finally {
    variantsLoading.value = false
  }
}

function openVariantCreate() {
  variantEditing.value = null
  variantForm.value = blankVariantForm()
  variantErrors.value = {}
  variantFormVisible.value = true
}

function openVariantEdit(v: any) {
  variantEditing.value = v
  variantForm.value = {
    sku: v.sku,
    name: v.name,
    barcode: v.barcode,
    rfid_tag: v.rfid_tag,
    cost_price: v.cost_price === null || v.cost_price === undefined ? null : Number(v.cost_price),
    selling_price: v.selling_price === null || v.selling_price === undefined ? null : Number(v.selling_price),
    is_active: !!v.is_active,
  }
  variantErrors.value = {}
  variantFormVisible.value = true
}

async function saveVariant() {
  variantSaving.value = true
  variantErrors.value = {}
  try {
    if (variantEditing.value) {
      await http.put(`/products/${variantsProduct.value.id}/variants/${variantEditing.value.id}`, variantForm.value)
      toast.add({ severity: 'success', summary: pt('Variant updated'), life: 2500 })
    } else {
      await http.post(`/products/${variantsProduct.value.id}/variants`, variantForm.value)
      toast.add({ severity: 'success', summary: pt('Variant created'), life: 2500 })
    }
    variantFormVisible.value = false
    await loadVariants()
  } catch (e: any) {
    if (e.response?.status === 422) {
      variantErrors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    variantSaving.value = false
  }
}

async function confirmDeleteVariant(v: any) {
  const res = await Swal.fire({
    title: pt('Delete this variant?'),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/products/${variantsProduct.value.id}/variants/${v.id}`)
    toast.add({ severity: 'success', summary: pt('Variant deleted'), life: 2500 })
    await loadVariants()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function variantError(key: string): string | null {
  return variantErrors.value[key]?.[0] ?? null
}

// --- Barcode preview / quick print dialog ---
const barcodeVisible = ref(false)
const barcodeProduct = ref<any>(null)
const barcodeImages = ref<{ code128: string | null; qr: string | null }>({ code128: null, qr: null })
const barcodeLoading = ref(false)
const barcodePrintQty = ref(1)
const barcodePrinting = ref(false)

async function openBarcode(row: any) {
  barcodeProduct.value = row
  barcodeImages.value = { code128: null, qr: null }
  barcodePrintQty.value = 1
  barcodeVisible.value = true
  if (!row.barcode) return
  barcodeLoading.value = true
  try {
    const [c128, qr] = await Promise.all([
      http.get(`/products/${row.id}/barcode-image`, { params: { type: 'code128' }, responseType: 'blob' }),
      http.get(`/products/${row.id}/barcode-image`, { params: { type: 'qr' }, responseType: 'blob' }),
    ])
    barcodeImages.value = {
      code128: URL.createObjectURL(c128.data),
      qr: URL.createObjectURL(qr.data),
    }
  } catch {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not render barcode images'), life: 4000 })
  } finally {
    barcodeLoading.value = false
  }
}

async function printBarcodeLabel() {
  if (!barcodeProduct.value) return
  barcodePrinting.value = true
  try {
    const { data } = await http.post(
      '/barcode/print-labels',
      { label_type: 'code128', items: [{ type: 'product', id: barcodeProduct.value.id, quantity: barcodePrintQty.value }] },
      { responseType: 'blob' },
    )
    openPdfBlob(data)
  } catch {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not generate label'), life: 4000 })
  } finally {
    barcodePrinting.value = false
  }
}

onMounted(async () => {
  reload()
  try {
    const [c, b, u, s] = await Promise.all([
      http.get('/categories', { params: { per_page: 100 } }),
      http.get('/brands', { params: { per_page: 100 } }),
      http.get('/units', { params: { per_page: 100 } }),
      http.get('/suppliers', { params: { per_page: 100 } }),
    ])
    categories.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
    brands.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
    units.value = (u.data.data ?? u.data).map((x: any) => ({ label: x.short_name, value: x.id }))
    suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  } catch {
    // option lists stay empty; the dialog still works
  }
})
</script>

<template>
  <div class="products-page">
    <PageHeader :title="pt('Products')"
      :subtitle="pt('Your catalogue, pricing and stock-tracking setup in one place.')"
      :count="crud.total.value" :countLabel="pt('products')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Product')" icon="pi pi-plus" class="add-btn" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar no-print">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search products by name / SKU / barcode...')" @keyup.enter="doSearch" />
        <i v-if="search" class="pi pi-times clear-search" @click="search = ''; doSearch()" />
      </span>
      <Select v-model="categoryFilter" :options="categories" optionLabel="label" optionValue="value" :placeholder="pt('All categories')" showClear class="filter" @change="doSearch" />
      <Select v-model="activeFilter" :options="activeOptions" optionLabel="label" optionValue="value" :placeholder="pt('All status')" showClear class="filter" @change="doSearch" />
      <Button :label="pt('Search')" icon="pi pi-filter" @click="doSearch" />
      <span class="toolbar-spacer" />
      <TableExportButtons :title="pt('Products')" endpoint="/products"
        :params="() => ({
          search: search || undefined,
          category_id: categoryFilter || undefined,
          is_active: activeFilter !== null ? activeFilter : undefined,
        })"
        :columns="[
          { key: 'sku', label: pt('SKU') },
          { key: 'name', label: pt('Name') },
          { key: 'category.name', label: pt('Category') },
          { key: 'selling_price', label: pt('Selling Price') },
          { key: 'is_active', label: pt('Active'), format: (v) => v ? pt('Yes') : pt('No') },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
      class="p-datatable-sm" rowHover>
      <Column field="name" :header="pt('Product')" sortable style="min-width: 15rem">
        <template #body="{ data }">
          <div class="entity-cell">
            <img v-if="data.image_url" :src="data.image_url" :alt="data.name" class="thumb" />
            <span v-else class="thumb thumb-placeholder"><i class="pi pi-image" /></span>
            <div class="entity-meta">
              <span class="entity-name">{{ data.name }}</span>
              <span class="entity-hint" :class="{ mono: !data.brand?.name && data.barcode }">
                {{ data.brand?.name || data.barcode || '—' }}
              </span>
            </div>
          </div>
        </template>
      </Column>
      <Column field="sku" :header="pt('SKU')" sortable style="width: 10rem">
        <template #body="{ data }"><span class="code-chip">{{ data.sku }}</span></template>
      </Column>
      <Column :header="pt('Category')" style="width: 11rem">
        <template #body="{ data }">
          <span v-if="data.category?.name" class="chip">{{ data.category.name }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="selling_price" :header="pt('Selling Price')" sortable style="width: 10rem" bodyClass="num-cell" headerClass="num-head">
        <template #body="{ data }"><span class="amount">{{ money(data.selling_price) }}</span></template>
      </Column>
      <Column :header="pt('Tracking')" style="width: 12rem">
        <template #body="{ data }">
          <template v-if="trackingTags(data).length">
            <span v-for="t in trackingTags(data)" :key="t" class="track-pill">{{ t }}</span>
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Active')" style="width: 8rem">
        <template #body="{ data }"><StatusPill :active="!!data.is_active" /></template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem" bodyClass="num-cell" headerClass="num-head">
        <template #body="{ data }">
          <div class="row-actions">
            <button class="icon-btn" :title="pt('View')" @click="openDetail(data)"><i class="pi pi-eye" /></button>
            <button v-if="canEdit" class="icon-btn edit" :title="pt('Edit')" @click="openEdit(data)"><i class="pi pi-pencil" /></button>
            <button v-if="canView" class="icon-btn" :title="pt('Variants')" @click="openVariants(data)"><i class="pi pi-sitemap" /></button>
            <button v-if="canScanBarcodes" class="icon-btn" :title="pt('Barcode')" @click="openBarcode(data)"><i class="pi pi-qrcode" /></button>
            <button v-if="canDelete" class="icon-btn danger" :title="pt('Delete')" @click="confirmDelete(data)"><i class="pi pi-trash" /></button>
          </div>
        </template>
      </Column>
      <template #empty>
        <div class="empty-state">
          <i class="pi pi-inbox" />
          <p>{{ pt('No products found.') }}</p>
          <Button v-if="canCreate" :label="pt('New Product')" icon="pi pi-plus" text @click="openCreate" />
        </div>
      </template>
    </DataTable>

    <!-- Create / Edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? pt('Edit Product') : pt('New Product')" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>{{ pt('SKU') }} <span class="req">*</span></label>
          <InputText v-model="form.sku" :class="{ invalid: fieldError('sku') }" />
          <small v-if="fieldError('sku')" class="error-text">{{ fieldError('sku') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Barcode') }}</label>
          <InputText v-model="form.barcode" :class="{ invalid: fieldError('barcode') }" />
          <small v-if="fieldError('barcode')" class="error-text">{{ fieldError('barcode') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('RFID Tag') }}</label>
          <InputText v-model="form.rfid_tag" :placeholder="pt('Scan or type EPC / tag ID')" :class="{ invalid: fieldError('rfid_tag') }" />
          <small v-if="fieldError('rfid_tag')" class="error-text">{{ fieldError('rfid_tag') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Category') }}</label>
          <Select v-model="form.category_id" :options="categories" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Category')" showClear :class="{ invalid: fieldError('category_id') }" />
          <small v-if="fieldError('category_id')" class="error-text">{{ fieldError('category_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Brand') }}</label>
          <Select v-model="form.brand_id" :options="brands" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Brand')" showClear :class="{ invalid: fieldError('brand_id') }" />
          <small v-if="fieldError('brand_id')" class="error-text">{{ fieldError('brand_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Unit') }}</label>
          <Select v-model="form.unit_id" :options="units" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Unit')" showClear :class="{ invalid: fieldError('unit_id') }" />
          <small v-if="fieldError('unit_id')" class="error-text">{{ fieldError('unit_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('HSN Code') }}</label>
          <InputText v-model="form.hsn_code" :class="{ invalid: fieldError('hsn_code') }" />
          <small v-if="fieldError('hsn_code')" class="error-text">{{ fieldError('hsn_code') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Cost Price') }}</label>
          <InputNumber v-model="form.cost_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('cost_price') }" />
          <small v-if="fieldError('cost_price')" class="error-text">{{ fieldError('cost_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Selling Price') }}</label>
          <InputNumber v-model="form.selling_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('selling_price') }" />
          <small v-if="fieldError('selling_price')" class="error-text">{{ fieldError('selling_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Tax %') }}</label>
          <InputNumber v-model="form.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" :class="{ invalid: fieldError('tax_percent') }" />
          <small v-if="fieldError('tax_percent')" class="error-text">{{ fieldError('tax_percent') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Min Stock Level') }}</label>
          <InputNumber v-model="form.min_stock_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('min_stock_level') }" />
          <small v-if="fieldError('min_stock_level')" class="error-text">{{ fieldError('min_stock_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Max Stock Level') }}</label>
          <InputNumber v-model="form.max_stock_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('max_stock_level') }" />
          <small v-if="fieldError('max_stock_level')" class="error-text">{{ fieldError('max_stock_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Reorder Level') }}</label>
          <InputNumber v-model="form.reorder_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('reorder_level') }" />
          <small v-if="fieldError('reorder_level')" class="error-text">{{ fieldError('reorder_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Reorder Quantity') }}</label>
          <InputNumber v-model="form.reorder_quantity" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('reorder_quantity') }" />
          <small v-if="fieldError('reorder_quantity')" class="error-text">{{ fieldError('reorder_quantity') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Preferred Supplier') }}</label>
          <Select v-model="form.preferred_supplier_id" :options="suppliers" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Supplier')" showClear :class="{ invalid: fieldError('preferred_supplier_id') }" />
          <small v-if="fieldError('preferred_supplier_id')" class="error-text">{{ fieldError('preferred_supplier_id') }}</small>
        </div>
        <div class="form-field full">
          <label>{{ pt('Description') }}</label>
          <Textarea v-model="form.description" rows="3" :class="{ invalid: fieldError('description') }" />
          <small v-if="fieldError('description')" class="error-text">{{ fieldError('description') }}</small>
        </div>
        <div v-if="attrDefs.length > 0" class="form-field full attr-section">
          <label>{{ pt('Category Attributes') }}</label>
          <div class="attr-grid">
            <div v-for="def in attrDefs" :key="def.name" class="form-field">
              <label>{{ def.name }}{{ def.required ? ' *' : '' }}</label>
              <InputText v-if="def.type === 'text'" v-model="form.attributes[def.name]"
                :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <InputNumber v-else-if="def.type === 'number'" v-model="form.attributes[def.name]"
                :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <Select v-else-if="def.type === 'select'" v-model="form.attributes[def.name]" :options="def.options"
                :placeholder="pt('Select')" showClear :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <InputText v-else v-model="form.attributes[def.name]"
                :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <small v-if="fieldError(`attributes.${def.name}`)" class="error-text">{{ fieldError(`attributes.${def.name}`) }}</small>
            </div>
          </div>
        </div>

        <div class="form-field full checkbox-group">
          <div class="checkbox-row">
            <Checkbox v-model="form.track_batch" binary inputId="track_batch" />
            <label for="track_batch">{{ pt('Track Batch') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.track_serial" binary inputId="track_serial" />
            <label for="track_serial">{{ pt('Track Serial') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.track_expiry" binary inputId="track_expiry" />
            <label for="track_expiry">{{ pt('Track Expiry') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="is_active" />
            <label for="is_active">{{ pt('Active') }}</label>
          </div>
        </div>

        <div v-if="editing" class="form-field full image-section">
          <label>{{ pt('Product Image') }}</label>
          <div class="image-row">
            <img v-if="editImageUrl" :src="editImageUrl" alt="Product image" class="preview" />
            <span v-else class="preview preview-placeholder"><i class="pi pi-image" /></span>
            <div class="image-controls">
              <input type="file" accept="image/*" :disabled="uploadingImage" @change="onImagePicked" />
              <small class="hint">{{ pt('JPG/PNG up to 2MB. Uploads immediately.') }}</small>
              <small v-if="uploadingImage" class="hint">{{ pt('Uploading...') }}</small>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editing ? pt('Update') : pt('Create')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Variants dialog -->
    <Dialog v-model:visible="variantsVisible" modal :header="pt('Variants — {name}', { name: variantsProduct?.name ?? '' })" :style="{ width: '720px' }">
      <div class="variants-toolbar">
        <Button v-if="canEdit" :label="pt('Add Variant')" icon="pi pi-plus" size="small" @click="openVariantCreate" />
      </div>

      <table class="variants-table">
        <thead>
          <tr><th>{{ pt('SKU') }}</th><th>{{ pt('Name') }}</th><th>{{ pt('Cost') }}</th><th>{{ pt('Price') }}</th><th>{{ pt('Active') }}</th><th style="width: 6rem"></th></tr>
        </thead>
        <tbody>
          <tr v-for="v in variants" :key="v.id">
            <td>{{ v.sku }}</td>
            <td>{{ v.name }}</td>
            <td>{{ money(v.cost_price) }}</td>
            <td>{{ money(v.selling_price) }}</td>
            <td><Tag :severity="v.is_active ? 'success' : 'danger'" :value="v.is_active ? pt('Yes') : pt('No')" /></td>
            <td class="actions-cell">
              <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openVariantEdit(v)" />
              <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDeleteVariant(v)" />
            </td>
          </tr>
          <tr v-if="variantsLoading"><td colspan="6" class="empty-cell">{{ pt('Loading...') }}</td></tr>
          <tr v-else-if="!variants.length"><td colspan="6" class="empty-cell">{{ pt('No variants yet.') }}</td></tr>
        </tbody>
      </table>

      <template #footer>
        <Button :label="pt('Close')" text @click="variantsVisible = false" />
      </template>
    </Dialog>

    <!-- Variant create / edit sub-dialog -->
    <Dialog v-model:visible="variantFormVisible" modal :header="variantEditing ? pt('Edit Variant') : pt('Add Variant')" :style="{ width: '460px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>{{ pt('SKU') }} <span class="req">*</span></label>
          <InputText v-model="variantForm.sku" :class="{ invalid: variantError('sku') }" />
          <small v-if="variantError('sku')" class="error-text">{{ variantError('sku') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Name') }}</label>
          <InputText v-model="variantForm.name" :class="{ invalid: variantError('name') }" />
          <small v-if="variantError('name')" class="error-text">{{ variantError('name') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Barcode') }}</label>
          <InputText v-model="variantForm.barcode" :class="{ invalid: variantError('barcode') }" />
          <small v-if="variantError('barcode')" class="error-text">{{ variantError('barcode') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('RFID Tag') }}</label>
          <InputText v-model="variantForm.rfid_tag" :placeholder="pt('Scan or type EPC / tag ID')" :class="{ invalid: variantError('rfid_tag') }" />
          <small v-if="variantError('rfid_tag')" class="error-text">{{ variantError('rfid_tag') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Cost Price') }}</label>
          <InputNumber v-model="variantForm.cost_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: variantError('cost_price') }" />
          <small v-if="variantError('cost_price')" class="error-text">{{ variantError('cost_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Selling Price') }}</label>
          <InputNumber v-model="variantForm.selling_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: variantError('selling_price') }" />
          <small v-if="variantError('selling_price')" class="error-text">{{ variantError('selling_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Active') }}</label>
          <div class="checkbox-row">
            <Checkbox v-model="variantForm.is_active" binary inputId="variant_is_active" />
            <label for="variant_is_active">{{ pt('Enabled') }}</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="variantFormVisible = false" />
        <Button :label="variantEditing ? pt('Update') : pt('Create')" :loading="variantSaving" @click="saveVariant" />
      </template>
    </Dialog>

    <!-- Barcode preview / quick print dialog -->
    <Dialog v-model:visible="barcodeVisible" modal :header="pt('Barcode — {name}', { name: barcodeProduct?.name ?? '' })" :style="{ width: '480px' }">
      <div v-if="!barcodeProduct?.barcode" class="barcode-empty">
        {{ pt('This product has no barcode value set.') }}
      </div>
      <div v-else class="barcode-body">
        <div v-if="barcodeLoading" class="barcode-loading">{{ pt('Loading...') }}</div>
        <div v-else class="barcode-images">
          <div class="barcode-image-box">
            <img v-if="barcodeImages.code128" :src="barcodeImages.code128" alt="CODE128 barcode" />
            <div class="barcode-value">{{ barcodeProduct.barcode }}</div>
          </div>
          <div class="barcode-image-box">
            <img v-if="barcodeImages.qr" :src="barcodeImages.qr" alt="QR code" class="qr-img" />
          </div>
        </div>
        <div class="barcode-print-row">
          <InputNumber v-model="barcodePrintQty" :min="1" :max="500" showButtons buttonLayout="horizontal" style="width: 9rem" />
          <Button :label="pt('Print Label')" icon="pi pi-print" :loading="barcodePrinting" @click="printBarcodeLabel" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="barcodeVisible = false" />
      </template>
    </Dialog>

    <!-- Read-only detail dialog -->
    <DetailDialog v-model:visible="detailVisible" :title="pt('Product Details')" :record="detailRecord" :fields="[
      { key: 'sku', label: pt('SKU') },
      { key: 'name', label: pt('Name') },
      { key: 'category.name', label: pt('Category') },
      { key: 'brand.name', label: pt('Brand') },
      { key: 'unit.short_name', label: pt('Unit') },
      { key: 'hsn_code', label: pt('HSN Code') },
      { key: 'barcode', label: pt('Barcode') },
      { key: 'cost_price', label: pt('Cost Price'), format: (v) => money(v) },
      { key: 'selling_price', label: pt('Selling Price'), format: (v) => money(v) },
      { key: 'tax_percent', label: pt('Tax %') },
      { key: 'reorder_level', label: pt('Reorder Level') },
      { key: 'is_active', label: pt('Active') },
      { key: 'description', label: pt('Description'), full: true },
    ]" />
  </div>
</template>

<style scoped>
/* Screen-specific bits only — the page head, toolbar card, table card and
   cell atoms (.entity-cell, .code-chip, .status-pill, .icon-btn…) all come
   from the shared listing design layer in src/styles/list-design.css. */
.filter {
  min-width: 170px;
}
.search :deep(input) {
  width: 320px;
  padding-right: 2rem;
}
.add-btn {
  font-weight: 600;
  box-shadow: 0 6px 16px rgba(232, 163, 61, 0.28);
}

@media (max-width: 640px) {
  .search :deep(input) {
    width: 100%;
  }
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.form-field.full {
  grid-column: 1 / -1;
}
.form-field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.form-field :deep(.p-inputtext),
.form-field :deep(.p-select),
.form-field :deep(.p-inputnumber) {
  width: 100%;
}
.checkbox-group {
  flex-direction: row;
  flex-wrap: wrap;
  gap: 1.5rem;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.attr-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 1rem;
}
.attr-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.image-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 1rem;
}
.image-row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}
.preview {
  width: 120px;
  height: 120px;
  max-width: 120px;
  object-fit: cover;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.preview-placeholder {
  background: #f3f4f6;
  color: #9ca3af;
  font-size: 1.5rem;
}
.image-controls {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.hint {
  color: #6b7280;
  font-size: 0.78rem;
}
.variants-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.75rem;
}
.variants-table {
  width: 100%;
  border-collapse: collapse;
}
.variants-table th {
  text-align: left;
  font-size: 0.8rem;
  color: #6b7280;
  padding: 0.4rem;
  border-bottom: 1px solid #e5e7eb;
}
.variants-table td {
  padding: 0.35rem 0.4rem;
  vertical-align: middle;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.9rem;
}
.actions-cell {
  white-space: nowrap;
  text-align: right;
}
.empty-cell {
  text-align: center;
  color: #6b7280;
  padding: 1rem;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.barcode-empty {
  color: #6b7280;
  text-align: center;
  padding: 1.5rem 0;
}
.barcode-loading {
  color: #6b7280;
  text-align: center;
  padding: 1.5rem 0;
}
.barcode-images {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-around;
  align-items: center;
  gap: 1rem;
  padding: 1rem 0;
}
.barcode-image-box {
  text-align: center;
  max-width: 200px;
}
.barcode-image-box img {
  max-width: 100%;
  height: auto;
}
.barcode-value {
  font-size: 0.8rem;
  color: #374151;
  margin-top: 0.25rem;
  letter-spacing: 0.5px;
}
.qr-img {
  width: 120px;
  height: 120px;
}
.barcode-print-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
}
</style>
