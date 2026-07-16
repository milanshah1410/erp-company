<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import AutoComplete from 'primevue/autocomplete'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/channel-listings')
const canCreate = computed(() => auth.can('channel-listings.create'))
const canEdit = computed(() => auth.can('channel-listings.edit'))
const canDelete = computed(() => auth.can('channel-listings.delete'))
const canSync = computed(() => auth.can('ecommerce-integrations.edit'))

const integrations = ref<any[]>([])
const integrationFilter = ref<number | null>(null)
const search = ref('')
const syncing = ref(false)

async function loadIntegrations() {
  const { data } = await http.get('/ecommerce-integrations')
  integrations.value = data.data.map((i: any) => ({ id: i.id, name: `${i.name} (${i.platform_label})`, listings: i.capabilities?.listings }))
}

function listParams() {
  return {
    ...(integrationFilter.value ? { channel_integration_id: integrationFilter.value } : {}),
    ...(search.value ? { search: search.value } : {}),
  }
}
function reload() {
  crud.fetch(listParams())
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

// Product autocomplete
const productMatches = ref<any[]>([])
async function searchProducts(e: any) {
  const { data } = await http.get('/products', { params: { search: e.query, per_page: 15 } })
  productMatches.value = (data.data ?? data).map((p: any) => ({ id: p.id, sku: p.sku, name: p.name, has_variants: p.has_variants, label: `${p.name} (${p.sku})` }))
}

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

// Dialog
const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({ channel_integration_id: null, product: null, variant_id: null, external_id: '', external_sku: '', listing_title: '', price: null, status: 'active' })
const variants = ref<any[]>([])

async function loadVariants(productId: number) {
  variants.value = []
  try {
    const { data } = await http.get(`/products/${productId}/variants`)
    variants.value = (data.data ?? data).map((v: any) => ({ id: v.id, label: v.sku || `Variant ${v.id}` }))
  } catch { /* ignore */ }
}

function openAdd() {
  editingId.value = null
  form.value = { channel_integration_id: integrationFilter.value, product: null, variant_id: null, external_id: '', external_sku: '', listing_title: '', price: null, status: 'active' }
  variants.value = []
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = {
    channel_integration_id: row.channel_integration_id,
    product: row.product ? { id: row.product.id, label: `${row.product.name} (${row.product.sku})`, has_variants: false } : null,
    variant_id: row.variant_id, external_id: row.external_id || '', external_sku: row.external_sku || '',
    listing_title: row.listing_title || '', price: row.price, status: row.status,
  }
  variants.value = []
  if (row.product?.id) loadVariants(row.product.id)
  dialogVisible.value = true
}
function onProductPick(e: any) {
  form.value.variant_id = null
  if (e.value?.id && e.value.has_variants) loadVariants(e.value.id)
  else variants.value = []
}

async function save() {
  const payload: any = {
    channel_integration_id: form.value.channel_integration_id,
    product_id: form.value.product?.id,
    variant_id: form.value.variant_id,
    external_id: form.value.external_id || null,
    external_sku: form.value.external_sku || null,
    listing_title: form.value.listing_title || null,
    price: form.value.price,
    status: form.value.status,
  }
  try {
    if (editingId.value) await http.put(`/channel-listings/${editingId.value}`, payload)
    else await http.post('/channel-listings', payload)
    toast.add({ severity: 'success', summary: 'Saved', life: 2000 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message || 'Check the fields', life: 5000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({ title: 'Remove mapping?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  await http.delete(`/channel-listings/${row.id}`)
  toast.add({ severity: 'success', summary: 'Removed', life: 2000 })
  reload()
}

async function autoMap() {
  if (!integrationFilter.value) {
    toast.add({ severity: 'warn', summary: 'Pick a channel first', detail: 'Select a channel to pull its listings.', life: 3000 })
    return
  }
  syncing.value = true
  try {
    const { data } = await http.post(`/ecommerce-integrations/${integrationFilter.value}/sync-listings`)
    toast.add({ severity: 'success', summary: 'Listings synced', detail: JSON.stringify(data.stats), life: 4000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Sync failed', detail: e.response?.data?.message || 'Error', life: 6000 })
  } finally {
    syncing.value = false
  }
}

onMounted(async () => {
  await loadIntegrations()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Channel Listings</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search listing / SKU..." @keyup.enter="reload" />
        </span>
        <Select v-model="integrationFilter" :options="integrations" optionLabel="name" optionValue="id" showClear
          placeholder="All channels" class="filter" @change="reload" />
        <Button label="Search" outlined size="small" @click="reload" />
        <Button label="Auto-map by SKU" icon="pi pi-sync" outlined size="small" :loading="syncing"
          :disabled="!canSync" @click="autoMap" />
        <Button label="Map product" icon="pi pi-plus" size="small" :disabled="!canCreate" @click="openAdd" />
        <TableExportButtons title="Channel Listings" endpoint="/channel-listings"
          :params="() => listParams()"
          :columns="[
            { key: 'product.name', label: 'Product' },
            { key: 'external_id', label: 'External ID' },
            { key: 'external_sku', label: 'External SKU' },
            { key: 'listing_title', label: 'Listing title' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button icon="pi pi-refresh" text rounded @click="reload" title="Refresh" />
      </div>
    </div>
    <p class="subtitle">Link local products to their marketplace listing so orders import and stock pushes correctly.</p>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }">
          <div class="prod"><span>{{ data.product?.name || data.product_id }}</span>
            <small class="muted">{{ data.product?.sku }}<template v-if="data.variant"> · {{ data.variant.sku }}</template></small></div>
        </template>
      </Column>
      <Column field="external_id" header="External ID"><template #body="{ data }">{{ data.external_id || '—' }}</template></Column>
      <Column field="external_sku" header="External SKU"><template #body="{ data }">{{ data.external_sku || '—' }}</template></Column>
      <Column field="listing_title" header="Listing title"><template #body="{ data }">{{ data.listing_title || '—' }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :severity="data.status === 'active' ? 'success' : 'secondary'" :value="data.status" /></template></Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button icon="pi pi-pencil" size="small" text :disabled="!canEdit" @click="openEdit(data)" />
          <Button icon="pi pi-trash" size="small" text severity="danger" :disabled="!canDelete" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>No listings mapped. Use “Auto-map by SKU” or map a product manually.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit mapping' : 'Map product to listing'" :style="{ width: '520px' }">
      <div class="form">
        <label>Channel <span class="req">*</span></label>
        <Select v-model="form.channel_integration_id" :options="integrations" optionLabel="name" optionValue="id" placeholder="Select channel" />

        <label>Product <span class="req">*</span></label>
        <AutoComplete v-model="form.product" :suggestions="productMatches" optionLabel="label" dropdown
          placeholder="Search by name or SKU" @complete="searchProducts" @item-select="onProductPick" />

        <template v-if="variants.length">
          <label>Variant</label>
          <Select v-model="form.variant_id" :options="variants" optionLabel="label" optionValue="id" showClear placeholder="No variant" />
        </template>

        <label>External listing ID</label>
        <InputText v-model="form.external_id" placeholder="Marketplace product / variant id" />

        <label>External SKU</label>
        <InputText v-model="form.external_sku" placeholder="Marketplace SKU (used for stock matching)" />

        <label>Listing title</label>
        <InputText v-model="form.listing_title" />

        <div class="row">
          <div class="col">
            <label>Price</label>
            <InputNumber v-model="form.price" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" />
          </div>
          <div class="col">
            <label>Status</label>
            <Select v-model="form.status" :options="[{label:'Active',value:'active'},{label:'Inactive',value:'inactive'}]" optionLabel="label" optionValue="value" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Listing Details" :fields="[
      { key: 'product.name', label: 'Product' },
      { key: 'product.sku', label: 'SKU' },
      { key: 'external_id', label: 'External ID' },
      { key: 'external_sku', label: 'External SKU' },
      { key: 'listing_title', label: 'Listing Title' },
      { key: 'price', label: 'Price', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: 'Status' },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.filter { width: 190px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.subtitle { color: #6b7280; margin: 0.25rem 0 1rem; }
.prod { display: flex; flex-direction: column; }
.muted { color: #6b7280; font-size: 0.8rem; }
.form { display: flex; flex-direction: column; gap: 0.35rem; }
.form label { font-size: 0.85rem; font-weight: 600; margin-top: 0.5rem; }
.form .req { color: #dc2626; }
.row { display: flex; gap: 1rem; }
.col { flex: 1; display: flex; flex-direction: column; gap: 0.35rem; }
:deep(.p-autocomplete) { width: 100%; }
:deep(.p-autocomplete-input) { width: 100%; }
:deep(.p-inputnumber) { width: 100%; }
</style>
