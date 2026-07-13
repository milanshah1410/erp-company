<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('price-lists.create'))
const canEdit = computed(() => auth.can('price-lists.edit'))
const canDelete = computed(() => auth.can('price-lists.delete'))

const levelOptions = [
  { label: 'MRP', value: 'mrp' },
  { label: 'Wholesale', value: 'wholesale' },
  { label: 'Retail', value: 'retail' },
  { label: 'Discount', value: 'discount' },
]

const levelColors: Record<string, string> = {
  mrp: 'info',
  wholesale: 'warn',
  retail: 'success',
  discount: 'danger',
}

function money(v: number | string | null | undefined) {
  return formatCurrency(v)
}

// --- Price list table + filters ---
const crud = ref(useCrud('/price-lists'))
const filterProduct = ref<number | null>(null)
const filterLevel = ref<string | null>(null)

const products = ref<{ label: string; value: number }[]>([])

async function loadProducts() {
  try {
    const { data } = await http.get('/products', { params: { per_page: 100 } })
    products.value = (data.data ?? data).map((p: any) => ({ label: p.name, value: p.id }))
  } catch {
    products.value = []
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (filterProduct.value) params.product_id = filterProduct.value
  if (filterLevel.value) params.level = filterLevel.value
  crud.value.fetch(params)
}

function onFilterChange() {
  crud.value.currentPage = 1
  reload()
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

const variants = ref<{ label: string; value: number }[]>([])
const variantsLoading = ref(false)

function blankForm() {
  return {
    product_id: null,
    variant_id: null,
    level: 'retail',
    price: 0,
    min_quantity: 1,
  }
}

async function loadVariants(productId: number | null) {
  if (!productId) {
    variants.value = []
    return
  }
  variantsLoading.value = true
  try {
    const { data } = await http.get(`/products/${productId}/variants`)
    variants.value = (data.data ?? data).map((v: any) => ({
      label: v.name ? `${v.sku} — ${v.name}` : v.sku,
      value: v.id,
    }))
  } catch {
    variants.value = []
  } finally {
    variantsLoading.value = false
  }
}

watch(
  () => form.value.product_id,
  async (productId, old) => {
    if (!dialogVisible.value) return
    await loadVariants(productId)
    // Reset the variant when the product changes to one it doesn't belong to.
    if (old !== undefined && !variants.value.some((v) => v.value === form.value.variant_id)) {
      form.value.variant_id = null
    }
  },
)

function openCreate() {
  editing.value = null
  form.value = blankForm()
  if (filterProduct.value) form.value.product_id = filterProduct.value
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    product_id: row.product_id,
    variant_id: row.variant_id,
    level: row.level,
    price: Number(row.price ?? 0),
    min_quantity: Number(row.min_quantity ?? 1),
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.value.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: 'Price updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Price created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this price entry?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await crud.value.remove(row.id)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

onMounted(() => {
  loadProducts()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Price Lists</h1>
      <Button v-if="canCreate" label="New Price" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select
        v-model="filterProduct"
        :options="products"
        optionLabel="label"
        optionValue="value"
        placeholder="All Products"
        filter
        showClear
        class="filter"
        @change="onFilterChange"
      />
      <Select
        v-model="filterLevel"
        :options="levelOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="All Levels"
        showClear
        class="filter"
        @change="onFilterChange"
      />
    </div>

    <DataTable
      :value="crud.items"
      :loading="crud.loading"
      lazy
      paginator
      :rows="crud.perPage"
      :totalRecords="crud.total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column header="Product">
        <template #body="{ data }">
          <span>{{ data.product?.name }}</span>
          <span v-if="data.variant" class="muted-sub">{{ data.variant.sku }}<template v-if="data.variant.name"> — {{ data.variant.name }}</template></span>
        </template>
      </Column>
      <Column header="Level">
        <template #body="{ data }">
          <Tag :severity="levelColors[data.level]" :value="String(data.level).toUpperCase()" />
        </template>
      </Column>
      <Column header="Price">
        <template #body="{ data }">{{ money(data.price) }}</template>
      </Column>
      <Column header="Min Qty">
        <template #body="{ data }">{{ Number(data.min_quantity ?? 1) }}</template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No price entries found.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Price' : 'New Price'" :style="{ width: '540px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>Product <span class="req">*</span></label>
          <Select
            v-model="form.product_id"
            :options="products"
            optionLabel="label"
            optionValue="value"
            placeholder="Select Product"
            filter
            :class="{ invalid: fieldError('product_id') }"
          />
          <small v-if="fieldError('product_id')" class="error-text">{{ fieldError('product_id') }}</small>
        </div>

        <div class="fld full">
          <label>Variant</label>
          <Select
            v-model="form.variant_id"
            :options="variants"
            optionLabel="label"
            optionValue="value"
            :placeholder="variants.length ? 'Whole product (no variant)' : 'No variants for this product'"
            :loading="variantsLoading"
            :disabled="!variants.length"
            showClear
            :class="{ invalid: fieldError('variant_id') }"
          />
          <small v-if="fieldError('variant_id')" class="error-text">{{ fieldError('variant_id') }}</small>
        </div>

        <div class="fld">
          <label>Level <span class="req">*</span></label>
          <Select
            v-model="form.level"
            :options="levelOptions"
            optionLabel="label"
            optionValue="value"
            :class="{ invalid: fieldError('level') }"
          />
          <small v-if="fieldError('level')" class="error-text">{{ fieldError('level') }}</small>
        </div>

        <div class="fld">
          <label>Price <span class="req">*</span></label>
          <InputNumber v-model="form.price" :minFractionDigits="2" :maxFractionDigits="2" :min="0" :class="{ invalid: fieldError('price') }" />
          <small v-if="fieldError('price')" class="error-text">{{ fieldError('price') }}</small>
        </div>

        <div class="fld">
          <label>Min Quantity</label>
          <InputNumber v-model="form.min_quantity" :minFractionDigits="0" :maxFractionDigits="3" :min="0" :class="{ invalid: fieldError('min_quantity') }" />
          <small v-if="fieldError('min_quantity')" class="error-text">{{ fieldError('min_quantity') }}</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.filter {
  min-width: 220px;
}
.muted-sub {
  display: block;
  color: #6b7280;
  font-size: 0.78rem;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.fld.full {
  grid-column: 1 / -1;
}
.fld label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber) {
  width: 100%;
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
</style>
