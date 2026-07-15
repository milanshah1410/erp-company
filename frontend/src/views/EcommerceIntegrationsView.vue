<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()
const canEdit = computed(() => auth.can('ecommerce-integrations.edit'))
const canCreate = computed(() => auth.can('ecommerce-integrations.create'))
const canDelete = computed(() => auth.can('ecommerce-integrations.delete'))

interface PlatformMeta {
  platform: string
  label: string
  icon: string
  credentials: { key: string; label: string; type: string; required: boolean }[]
  settings: { key: string; label: string; type: string; required: boolean }[]
  capabilities: { orders: boolean; inventory: boolean; listings: boolean }
  help: string
}

const platforms = ref<PlatformMeta[]>([])
const integrations = ref<any[]>([])
const warehouses = ref<any[]>([])
const loading = ref(false)
const busy = ref<Record<string, boolean>>({})

const statusColors: Record<string, string> = { connected: 'success', disconnected: 'secondary', error: 'danger' }

async function loadAll() {
  loading.value = true
  try {
    const [p, i, w] = await Promise.all([
      http.get('/ecommerce/platforms'),
      http.get('/ecommerce-integrations'),
      http.get('/warehouses'),
    ])
    platforms.value = p.data.data
    integrations.value = i.data.data
    warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ id: x.id, name: x.name }))
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Load failed', detail: e.response?.data?.message || 'Error', life: 4000 })
  } finally {
    loading.value = false
  }
}
onMounted(loadAll)

function metaFor(platform: string): PlatformMeta | undefined {
  return platforms.value.find((p) => p.platform === platform)
}

// --- Connect / edit dialog ---
const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const form = reactive<any>({
  platform: '',
  name: '',
  warehouse_id: null,
  auto_sync_orders: false,
  auto_push_inventory: false,
  settings: {} as Record<string, string>,
  credentials: {} as Record<string, string>,
})
const formMeta = computed(() => metaFor(form.platform))
const savedCreds = ref<Record<string, boolean>>({})

function openConnect(platform: string) {
  editingId.value = null
  form.platform = platform
  form.name = metaFor(platform)?.label + ' Store'
  form.warehouse_id = warehouses.value[0]?.id ?? null
  form.auto_sync_orders = false
  form.auto_push_inventory = false
  form.settings = {}
  form.credentials = {}
  savedCreds.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  form.platform = row.platform
  form.name = row.name
  form.warehouse_id = row.warehouse_id
  form.auto_sync_orders = row.auto_sync_orders
  form.auto_push_inventory = row.auto_push_inventory
  form.settings = { ...(row.settings ?? {}) }
  form.credentials = {}
  savedCreds.value = row.configured_credentials ?? {}
  dialogVisible.value = true
}

async function save() {
  const payload: any = {
    name: form.name,
    warehouse_id: form.warehouse_id,
    settings: form.settings,
    auto_sync_orders: form.auto_sync_orders,
    auto_push_inventory: form.auto_push_inventory,
    credentials: form.credentials,
  }
  try {
    if (editingId.value) {
      await http.put(`/ecommerce-integrations/${editingId.value}`, payload)
    } else {
      payload.platform = form.platform
      await http.post('/ecommerce-integrations', payload)
    }
    toast.add({ severity: 'success', summary: 'Saved', life: 2000 })
    dialogVisible.value = false
    await loadAll()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message || 'Check the fields', life: 5000 })
  }
}

async function act(row: any, action: 'test' | 'sync-orders' | 'push-inventory' | 'sync-listings', label: string) {
  const key = `${row.id}:${action}`
  busy.value = { ...busy.value, [key]: true }
  try {
    const { data } = await http.post(`/ecommerce-integrations/${row.id}/${action}`)
    const detail = data.stats ? JSON.stringify(data.stats) : data.message
    toast.add({ severity: 'success', summary: label, detail, life: 3500 })
    await loadAll()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: `${label} failed`, detail: e.response?.data?.message || 'Error', life: 6000 })
  } finally {
    busy.value = { ...busy.value, [key]: false }
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: `Remove ${row.name}?`,
    text: 'This deletes the connection and its mappings/inbox. Imported sales orders are kept.',
    icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#dc2626',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/ecommerce-integrations/${row.id}`)
    toast.add({ severity: 'success', summary: 'Removed', life: 2000 })
    await loadAll()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function isBusy(row: any, action: string) {
  return !!busy.value[`${row.id}:${action}`]
}
function fmt(dt: string | null) {
  return dt ? new Date(dt).toLocaleString() : '—'
}
</script>

<template>
  <div>
    <div class="header">
      <h1>E-commerce Integration</h1>
      <Button icon="pi pi-refresh" text rounded @click="loadAll" title="Refresh" />
    </div>
    <p class="subtitle">Connect marketplaces and storefronts to pull orders, map listings, and push live stock.</p>

    <!-- Connected integrations -->
    <div v-if="integrations.length" class="grid">
      <div v-for="row in integrations" :key="row.id" class="card">
        <div class="card-head">
          <i :class="`pi ${row.icon}`" />
          <div class="card-title">
            <strong>{{ row.name }}</strong>
            <small class="muted">{{ row.platform_label }}</small>
          </div>
          <Tag :severity="statusColors[row.status]" :value="row.status" />
        </div>

        <div class="caps">
          <Tag v-if="row.capabilities.orders" value="Orders" severity="info" />
          <Tag v-if="row.capabilities.inventory" value="Inventory" severity="info" />
          <Tag v-if="row.capabilities.listings" value="Listings" severity="info" />
          <span class="counts">{{ row.listings_count ?? 0 }} listings · {{ row.pending_orders_count ?? 0 }} pending</span>
        </div>

        <div class="meta-lines">
          <div><span class="muted">Warehouse</span> {{ row.warehouse?.name || '—' }}</div>
          <div><span class="muted">Orders synced</span> {{ fmt(row.last_order_sync_at) }}</div>
          <div><span class="muted">Stock pushed</span> {{ fmt(row.last_inventory_sync_at) }}</div>
          <div v-if="row.last_error" class="err"><i class="pi pi-exclamation-triangle" /> {{ row.last_error }}</div>
        </div>

        <div class="card-actions">
          <Button label="Test" size="small" outlined :loading="isBusy(row, 'test')" :disabled="!canEdit"
            @click="act(row, 'test', 'Connection test')" />
          <Button label="Sync Orders" size="small" text :loading="isBusy(row, 'sync-orders')"
            :disabled="!canEdit || !row.capabilities.orders" @click="act(row, 'sync-orders', 'Order sync')" />
          <Button label="Push Stock" size="small" text :loading="isBusy(row, 'push-inventory')"
            :disabled="!canEdit || !row.capabilities.inventory" @click="act(row, 'push-inventory', 'Inventory push')" />
          <Button label="Sync Listings" size="small" text :loading="isBusy(row, 'sync-listings')"
            :disabled="!canEdit || !row.capabilities.listings" @click="act(row, 'sync-listings', 'Listing sync')" />
          <span class="spacer" />
          <Button icon="pi pi-pencil" size="small" text :disabled="!canEdit" @click="openEdit(row)" title="Edit" />
          <Button icon="pi pi-trash" size="small" text severity="danger" :disabled="!canDelete" @click="remove(row)" title="Remove" />
        </div>
      </div>
    </div>
    <div v-else-if="!loading" class="empty">No channels connected yet. Pick a platform below to get started.</div>

    <!-- Available platforms to connect -->
    <h2 class="section">Add a channel</h2>
    <div class="platform-strip">
      <button v-for="p in platforms" :key="p.platform" class="platform-btn" :disabled="!canCreate" @click="openConnect(p.platform)">
        <i :class="`pi ${p.icon}`" />
        <span>{{ p.label }}</span>
      </button>
    </div>

    <!-- Connect / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit channel' : `Connect ${formMeta?.label}`" :style="{ width: '560px' }">
      <div v-if="formMeta" class="form">
        <p class="help"><i class="pi pi-info-circle" /> {{ formMeta.help }}</p>

        <label>Name</label>
        <InputText v-model="form.name" placeholder="A label for this connection" />

        <label>Warehouse (stock source / order destination)</label>
        <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="name" optionValue="id" placeholder="Default" showClear />

        <template v-for="f in formMeta.settings" :key="f.key">
          <label>{{ f.label }} <span v-if="f.required" class="req">*</span></label>
          <InputText v-model="form.settings[f.key]" :type="f.type === 'password' ? 'password' : 'text'" />
        </template>

        <template v-for="f in formMeta.credentials" :key="f.key">
          <label>{{ f.label }} <span v-if="f.required" class="req">*</span>
            <small v-if="savedCreds[f.key]" class="saved">saved — leave blank to keep</small>
          </label>
          <InputText v-model="form.credentials[f.key]" type="password" autocomplete="new-password"
            :placeholder="savedCreds[f.key] ? '••••••••' : ''" />
        </template>

        <div class="toggles">
          <div class="toggle"><Checkbox v-model="form.auto_sync_orders" :binary="true" inputId="aso" /><label for="aso">Auto-pull orders</label></div>
          <div class="toggle"><Checkbox v-model="form.auto_push_inventory" :binary="true" inputId="api" /><label for="api">Auto-push inventory</label></div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save' : 'Connect'" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin: 0.25rem 0 1.25rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; }
.card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; background: #fff; display: flex; flex-direction: column; gap: 0.75rem; }
.card-head { display: flex; align-items: center; gap: 0.6rem; }
.card-head > i { font-size: 1.4rem; color: #4b5563; }
.card-title { display: flex; flex-direction: column; flex: 1; }
.caps { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
.counts { margin-left: auto; font-size: 0.8rem; color: #6b7280; }
.meta-lines { font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.2rem; }
.meta-lines .muted { display: inline-block; width: 110px; }
.card-actions { display: flex; align-items: center; gap: 0.2rem; flex-wrap: wrap; border-top: 1px solid #f1f5f9; padding-top: 0.6rem; }
.spacer { flex: 1; }
.muted { color: #6b7280; }
.err { color: #dc2626; font-size: 0.8rem; }
.empty { padding: 2rem; text-align: center; color: #6b7280; border: 1px dashed #e5e7eb; border-radius: 12px; }
.section { font-size: 1.1rem; font-weight: 600; margin: 1.75rem 0 0.75rem; }
.platform-strip { display: flex; gap: 0.6rem; flex-wrap: wrap; }
.platform-btn { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; width: 110px; padding: 1rem 0.5rem; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; cursor: pointer; transition: 0.15s; }
.platform-btn:hover:not(:disabled) { border-color: #6366f1; background: #f5f5ff; }
.platform-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.platform-btn i { font-size: 1.5rem; color: #4b5563; }
.platform-btn span { font-size: 0.85rem; }
.form { display: flex; flex-direction: column; gap: 0.35rem; }
.form label { font-size: 0.85rem; font-weight: 600; margin-top: 0.5rem; }
.form .req { color: #dc2626; }
.saved { font-weight: 400; color: #16a34a; margin-left: 0.4rem; }
.help { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.82rem; color: #475569; margin: 0 0 0.5rem; }
.toggles { display: flex; gap: 1.5rem; margin-top: 0.9rem; }
.toggle { display: flex; align-items: center; gap: 0.4rem; }
.toggle label { margin: 0; font-weight: 400; }
</style>
