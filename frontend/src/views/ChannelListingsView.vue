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
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
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
    variants.value = (data.data ?? data).map((v: any) => ({ id: v.id, label: v.sku || pt('Variant {id}', { id: v.id }) }))
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
    toast.add({ severity: 'success', summary: pt('Saved'), life: 2000 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Save failed'), detail: e.response?.data?.message || pt('Check the fields'), life: 5000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({ title: pt('Remove mapping?'), icon: 'warning', showCancelButton: true, confirmButtonText: pt('Remove'), confirmButtonColor: '#dc2626', cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  await http.delete(`/channel-listings/${row.id}`)
  toast.add({ severity: 'success', summary: pt('Removed'), life: 2000 })
  reload()
}

async function autoMap() {
  if (!integrationFilter.value) {
    toast.add({ severity: 'warn', summary: pt('Pick a channel first'), detail: pt('Select a channel to pull its listings.'), life: 3000 })
    return
  }
  syncing.value = true
  try {
    const { data } = await http.post(`/ecommerce-integrations/${integrationFilter.value}/sync-listings`)
    toast.add({ severity: 'success', summary: pt('Listings synced'), detail: JSON.stringify(data.stats), life: 4000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Sync failed'), detail: e.response?.data?.message || pt('Error'), life: 6000 })
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
    <PageHeader :title="pt('Channel Listings')" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search listing / SKU...')" @keyup.enter="reload" />
        </span>
        <Select v-model="integrationFilter" :options="integrations" optionLabel="name" optionValue="id" showClear
        :placeholder="pt('All channels')" class="filter" @change="reload" />
        <Button :label="pt('Search')" outlined size="small" @click="reload" />
        <Button :label="pt('Auto-map by SKU')" icon="pi pi-sync" outlined size="small" :loading="syncing"
        :disabled="!canSync" @click="autoMap" />
        <Button :label="pt('Map product')" icon="pi pi-plus" size="small" :disabled="!canCreate" @click="openAdd" />
        <TableExportButtons :title="pt('Channel Listings')" endpoint="/channel-listings"
        :params="() => listParams()"
        :columns="[
        { key: 'product.name', label: pt('Product') },
        { key: 'external_id', label: pt('External ID') },
        { key: 'external_sku', label: pt('External SKU') },
        { key: 'listing_title', label: pt('Listing title') },
        { key: 'status', label: pt('Status') },
        ]" />
        <Button icon="pi pi-refresh" text rounded @click="reload" :title="pt('Refresh')" />
        </div>
      </template>
    </PageHeader>
    <p class="subtitle">{{ pt('Link local products to their marketplace listing so orders import and stock pushes correctly.') }}</p>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column :header="pt('Product')">
        <template #body="{ data }">
          <div class="prod"><span>{{ data.product?.name || data.product_id }}</span>
            <small class="muted">{{ data.product?.sku }}<template v-if="data.variant"> · {{ data.variant.sku }}</template></small></div>
        </template>
      </Column>
      <Column field="external_id" :header="pt('External ID')"><template #body="{ data }">{{ data.external_id || '—' }}</template></Column>
      <Column field="external_sku" :header="pt('External SKU')"><template #body="{ data }">{{ data.external_sku || '—' }}</template></Column>
      <Column field="listing_title" :header="pt('Listing title')"><template #body="{ data }">{{ data.listing_title || '—' }}</template></Column>
      <Column :header="pt('Status')"><template #body="{ data }"><Tag :severity="data.status === 'active' ? 'success' : 'secondary'" :value="data.status" /></template></Column>
      <Column :header="pt('Actions')" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button icon="pi pi-pencil" size="small" text :disabled="!canEdit" @click="openEdit(data)" />
          <Button icon="pi pi-trash" size="small" text severity="danger" :disabled="!canDelete" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No listings mapped. Use “Auto-map by SKU” or map a product manually.') }}</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? pt('Edit mapping') : pt('Map product to listing')" :style="{ width: '520px' }">
      <div class="form">
        <label>{{ pt('Channel') }} <span class="req">*</span></label>
        <Select v-model="form.channel_integration_id" :options="integrations" optionLabel="name" optionValue="id" :placeholder="pt('Select channel')" />

        <label>{{ pt('Product') }} <span class="req">*</span></label>
        <AutoComplete v-model="form.product" :suggestions="productMatches" optionLabel="label" dropdown
          :placeholder="pt('Search by name or SKU')" @complete="searchProducts" @item-select="onProductPick" />

        <template v-if="variants.length">
          <label>{{ pt('Variant') }}</label>
          <Select v-model="form.variant_id" :options="variants" optionLabel="label" optionValue="id" showClear :placeholder="pt('No variant')" />
        </template>

        <label>{{ pt('External listing ID') }}</label>
        <InputText v-model="form.external_id" :placeholder="pt('Marketplace product / variant id')" />

        <label>{{ pt('External SKU') }}</label>
        <InputText v-model="form.external_sku" :placeholder="pt('Marketplace SKU (used for stock matching)')" />

        <label>{{ pt('Listing title') }}</label>
        <InputText v-model="form.listing_title" />

        <div class="row">
          <div class="col">
            <label>{{ pt('Price') }}</label>
            <InputNumber v-model="form.price" mode="currency" currency="INR" locale="en-IN" :minFractionDigits="2" />
          </div>
          <div class="col">
            <label>{{ pt('Status') }}</label>
            <Select v-model="form.status" :options="[{label:pt('Active'),value:'active'},{label:pt('Inactive'),value:'inactive'}]" optionLabel="label" optionValue="value" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Listing Details')" :fields="[
      { key: 'product.name', label: pt('Product') },
      { key: 'product.sku', label: pt('SKU') },
      { key: 'external_id', label: pt('External ID') },
      { key: 'external_sku', label: pt('External SKU') },
      { key: 'listing_title', label: pt('Listing Title') },
      { key: 'price', label: pt('Price'), format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'status', label: pt('Status') },
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
