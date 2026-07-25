<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import GoogleAddressAutocomplete from '../components/GoogleAddressAutocomplete.vue'
import type { PlaceResult } from '../components/GoogleAddressAutocomplete.vue'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Tree from 'primevue/tree'
import type { TreeNode } from 'primevue/treenode'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const canView = computed(() => auth.can('warehouses.view'))
const canCreate = computed(() => auth.can('warehouses.create'))
const canEdit = computed(() => auth.can('warehouses.edit'))
const canDelete = computed(() => auth.can('warehouses.delete'))
const canViewStock = computed(() => auth.can('stock.view'))

// --- Warehouse list ---
const crud = ref(useCrud('/warehouses'))
const search = ref('')
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const typeOptions = [
  { label: 'Main', value: 'main' },
  { label: 'Branch', value: 'branch' },
  { label: 'Transit', value: 'transit' },
  { label: 'Damaged', value: 'damaged' },
]

function typeSeverity(type: string): string {
  switch (type) {
    case 'main':
      return 'info'
    case 'branch':
      return 'success'
    case 'transit':
      return 'warn'
    case 'damaged':
      return 'danger'
    default:
      return 'secondary'
  }
}

function typeLabel(type: string): string {
  return typeOptions.find((o) => o.value === type)?.label ?? type
}

function reload() {
  crud.value.fetch({
    ...(search.value ? { search: search.value } : {}),
    ...(sortField.value && sortOrder.value ? { sort: sortField.value, direction: sortOrder.value === 1 ? 'asc' : 'desc' } : {}),
  })
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  crud.value.currentPage = 1
  reload()
}

// --- Warehouse create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    code: '',
    name: '',
    type: 'main',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    address_line2: '',
    landmark: '',
    area: '',
    city: '',
    state: '',
    country: '',
    pincode: '',
    latitude: null,
    longitude: null,
    google_place_id: '',
    is_active: true,
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    code: row.code,
    name: row.name,
    type: row.type,
    contact_person: row.contact_person || '',
    phone: row.phone || '',
    email: row.email || '',
    address: row.address || '',
    address_line2: row.address_line2 || '',
    landmark: row.landmark || '',
    area: row.area || '',
    city: row.city || '',
    state: row.state || '',
    country: row.country || '',
    pincode: row.pincode || '',
    latitude: row.latitude ?? null,
    longitude: row.longitude ?? null,
    google_place_id: row.google_place_id || '',
    is_active: !!row.is_active,
  }
  errors.value = {}
  dialogVisible.value = true
}

// Google Places selection → auto-fill the address block (user can still edit).
function onPlaceSelected(place: PlaceResult) {
  form.value.address = place.formatted_address || form.value.address
  form.value.area = place.area || form.value.area
  form.value.city = place.city || form.value.city
  form.value.state = place.state || form.value.state
  form.value.country = place.country || form.value.country
  form.value.pincode = place.postal_code || form.value.pincode
  form.value.latitude = place.latitude ?? form.value.latitude
  form.value.longitude = place.longitude ?? form.value.longitude
  form.value.google_place_id = place.place_id || form.value.google_place_id
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.value.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: 'Warehouse updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Warehouse created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      if (!Object.keys(errors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.name}"?`,
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

// --- Locations dialog ---
const locationsVisible = ref(false)
const locationsWarehouse = ref<any>(null)
const locationsLoading = ref(false)
const locations = ref<any[]>([])
const locationNodes = ref<TreeNode[]>([])
const expandedKeys = ref<Record<string, boolean>>({})
const locView = ref<'tree' | 'stock'>('tree')

const locationTypeOptions = [
  { label: 'Zone', value: 'zone' },
  { label: 'Rack', value: 'rack' },
  { label: 'Shelf', value: 'shelf' },
  { label: 'Bin', value: 'bin' },
]

const childType: Record<string, string> = { zone: 'rack', rack: 'shelf', shelf: 'bin' }

function locTypeSeverity(type: string): string {
  switch (type) {
    case 'zone':
      return 'info'
    case 'rack':
      return 'secondary'
    case 'shelf':
      return 'warn'
    case 'bin':
      return 'success'
    default:
      return 'secondary'
  }
}

function toNode(loc: any): TreeNode {
  return {
    key: String(loc.id),
    label: loc.name,
    data: loc,
    children: (loc.children || []).map(toNode),
  }
}

function collectExpanded(list: TreeNode[], acc: Record<string, boolean>) {
  for (const n of list) {
    acc[n.key as string] = true
    if (n.children?.length) collectExpanded(n.children, acc)
  }
}

async function loadLocations() {
  if (!locationsWarehouse.value) return
  locationsLoading.value = true
  try {
    const { data } = await http.get(`/warehouses/${locationsWarehouse.value.id}/locations`)
    locations.value = data.data ?? []
    locationNodes.value = locations.value.map(toNode)
    const exp: Record<string, boolean> = {}
    collectExpanded(locationNodes.value, exp)
    expandedKeys.value = exp
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to load locations', life: 4000 })
  } finally {
    locationsLoading.value = false
  }
}

function openLocations(row: any) {
  locationsWarehouse.value = row
  locations.value = []
  locationNodes.value = []
  locView.value = 'tree'
  stockRows.value = []
  stockLoaded.value = false
  locationsVisible.value = true
  loadLocations()
}

// Flatten the location tree (depth-first) with indent level, for parent Select options.
function flattenLocations(list: any[], depth = 0, acc: any[] = []): any[] {
  for (const loc of list) {
    acc.push({ id: loc.id, code: loc.code, name: loc.name, depth })
    if (loc.children?.length) flattenLocations(loc.children, depth + 1, acc)
  }
  return acc
}

// Collect the ids of a location and all of its descendants (client-side circular-parent guard).
function collectIds(loc: any, acc: number[] = []): number[] {
  acc.push(loc.id)
  for (const child of loc.children || []) collectIds(child, acc)
  return acc
}

// --- Location create / edit sub-dialog ---
const locDialogVisible = ref(false)
const locSaving = ref(false)
const locEditing = ref<any>(null)
const locParentLabel = ref('— root —')
const locForm = ref<any>({ code: '', name: '', type: 'zone', parent_id: null, is_active: true })
const locErrors = ref<Record<string, string[]>>({})

const locParentOptions = computed(() => {
  if (!locEditing.value) return []
  const excluded = collectIds(locEditing.value)
  return flattenLocations(locations.value)
    .filter((l) => !excluded.includes(l.id))
    .map((l) => ({ label: `${'— '.repeat(l.depth)}${l.code} — ${l.name}`, value: l.id }))
})

function openLocCreateRoot() {
  locEditing.value = null
  locParentLabel.value = '— root —'
  locForm.value = { code: '', name: '', type: 'zone', parent_id: null, is_active: true }
  locErrors.value = {}
  locDialogVisible.value = true
}

function openLocAddChild(parent: any) {
  locEditing.value = null
  locParentLabel.value = `${parent.code} — ${parent.name}`
  locForm.value = {
    code: '',
    name: '',
    type: childType[parent.type] ?? 'bin',
    parent_id: parent.id,
    is_active: true,
  }
  locErrors.value = {}
  locDialogVisible.value = true
}

function openLocEdit(loc: any) {
  locEditing.value = loc
  locParentLabel.value = ''
  locForm.value = {
    code: loc.code,
    name: loc.name,
    type: loc.type,
    parent_id: loc.parent_id ?? null,
    is_active: !!loc.is_active,
  }
  locErrors.value = {}
  locDialogVisible.value = true
}

async function saveLocation() {
  if (!locationsWarehouse.value) return
  locSaving.value = true
  locErrors.value = {}
  const base = `/warehouses/${locationsWarehouse.value.id}/locations`
  try {
    if (locEditing.value) {
      await http.put(`${base}/${locEditing.value.id}`, locForm.value)
      toast.add({ severity: 'success', summary: 'Location updated', life: 2500 })
    } else {
      await http.post(base, locForm.value)
      toast.add({ severity: 'success', summary: 'Location created', life: 2500 })
    }
    locDialogVisible.value = false
    loadLocations()
  } catch (e: any) {
    if (e.response?.status === 422) {
      locErrors.value = e.response.data.errors || {}
      if (!Object.keys(locErrors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
    }
  } finally {
    locSaving.value = false
  }
}

async function confirmDeleteLocation(loc: any) {
  const res = await Swal.fire({
    title: `Delete "${loc.name}"?`,
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/warehouses/${locationsWarehouse.value.id}/locations/${loc.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    loadLocations()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

function locFieldError(key: string): string | null {
  return locErrors.value[key]?.[0] ?? null
}

// --- Stock by location ---
const stockRows = ref<any[]>([])
const stockLoading = ref(false)
const stockLoaded = ref(false)

async function loadStock() {
  if (!locationsWarehouse.value) return
  stockLoading.value = true
  try {
    const { data } = await http.get(`/warehouses/${locationsWarehouse.value.id}/location-stock`)
    stockRows.value = data.data ?? []
    stockLoaded.value = true
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to load stock', life: 4000 })
  } finally {
    stockLoading.value = false
  }
}

function switchView(view: 'tree' | 'stock') {
  locView.value = view
  if (view === 'stock' && !stockLoaded.value) loadStock()
}

onMounted(reload)
</script>

<template>
  <div>
    <div class="header">
      <h1>Warehouses</h1>
      <Button v-if="canCreate" label="New Warehouse" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="Warehouses" endpoint="/warehouses" :params="() => (search ? { search } : {})"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Name' },
          { key: 'type', label: 'Type' },
          { key: 'city', label: 'City' },
          { key: 'is_active', label: 'Active', format: (v) => (v ? 'Yes' : 'No') },
        ]" />
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
      removableSort
      :sortField="sortField ?? undefined"
      :sortOrder="sortOrder ?? undefined"
      @sort="onSort"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="code" header="Code" sortable />
      <Column field="name" header="Name" sortable />
      <Column field="type" header="Type">
        <template #body="{ data }">
          <Tag :severity="typeSeverity(data.type)" :value="typeLabel(data.type)" />
        </template>
      </Column>
      <Column field="city" header="City" />
      <Column field="is_active" header="Active">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'danger'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button v-if="canView" icon="pi pi-map-marker" text rounded size="small" title="Locations" @click="openLocations(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No records found.</template>
    </DataTable>

    <!-- Warehouse create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Warehouse' : 'New Warehouse'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Code <span class="req">*</span></label>
          <InputText v-model="form.code" :class="{ invalid: fieldError('code') }" />
          <small v-if="fieldError('code')" class="error-text">{{ fieldError('code') }}</small>
        </div>

        <div class="fld">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="fld">
          <label>Type <span class="req">*</span></label>
          <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="Select Type" :class="{ invalid: fieldError('type') }" />
          <small v-if="fieldError('type')" class="error-text">{{ fieldError('type') }}</small>
        </div>

        <div class="fld">
          <label>Contact Person</label>
          <InputText v-model="form.contact_person" :class="{ invalid: fieldError('contact_person') }" />
          <small v-if="fieldError('contact_person')" class="error-text">{{ fieldError('contact_person') }}</small>
        </div>

        <div class="fld">
          <label>Mobile</label>
          <InputText v-model="form.phone" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>

        <div class="fld">
          <label>Email</label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>

        <div class="fld full">
          <label>Address Line 1 <span class="req">*</span></label>
          <GoogleAddressAutocomplete
            v-model="form.address"
            placeholder="Start typing to search address..."
            :invalid="!!fieldError('address')"
            @place-selected="onPlaceSelected"
          />
          <small v-if="fieldError('address')" class="error-text">{{ fieldError('address') }}</small>
        </div>

        <div class="fld full">
          <label>Address Line 2</label>
          <InputText v-model="form.address_line2" :class="{ invalid: fieldError('address_line2') }" />
          <small v-if="fieldError('address_line2')" class="error-text">{{ fieldError('address_line2') }}</small>
        </div>

        <div class="fld">
          <label>Landmark</label>
          <InputText v-model="form.landmark" :class="{ invalid: fieldError('landmark') }" />
          <small v-if="fieldError('landmark')" class="error-text">{{ fieldError('landmark') }}</small>
        </div>

        <div class="fld">
          <label>Area / Locality</label>
          <InputText v-model="form.area" :class="{ invalid: fieldError('area') }" />
          <small v-if="fieldError('area')" class="error-text">{{ fieldError('area') }}</small>
        </div>

        <div class="fld">
          <label>City <span class="req">*</span></label>
          <InputText v-model="form.city" :class="{ invalid: fieldError('city') }" />
          <small v-if="fieldError('city')" class="error-text">{{ fieldError('city') }}</small>
        </div>

        <div class="fld">
          <label>State <span class="req">*</span></label>
          <InputText v-model="form.state" :class="{ invalid: fieldError('state') }" />
          <small v-if="fieldError('state')" class="error-text">{{ fieldError('state') }}</small>
        </div>

        <div class="fld">
          <label>Country <span class="req">*</span></label>
          <InputText v-model="form.country" :class="{ invalid: fieldError('country') }" />
          <small v-if="fieldError('country')" class="error-text">{{ fieldError('country') }}</small>
        </div>

        <div class="fld">
          <label>Pincode <span class="req">*</span></label>
          <InputText v-model="form.pincode" :class="{ invalid: fieldError('pincode') }" />
          <small v-if="fieldError('pincode')" class="error-text">{{ fieldError('pincode') }}</small>
        </div>

        <div class="fld">
          <label>Latitude</label>
          <InputText v-model="form.latitude" :class="{ invalid: fieldError('latitude') }" />
          <small v-if="fieldError('latitude')" class="error-text">{{ fieldError('latitude') }}</small>
        </div>

        <div class="fld">
          <label>Longitude</label>
          <InputText v-model="form.longitude" :class="{ invalid: fieldError('longitude') }" />
          <small v-if="fieldError('longitude')" class="error-text">{{ fieldError('longitude') }}</small>
        </div>

        <div class="fld">
          <label>Status</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="wh_is_active" />
            <label for="wh_is_active">Active</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Locations dialog -->
    <Dialog
      v-model:visible="locationsVisible"
      modal
      :header="`Locations — ${locationsWarehouse?.name ?? ''}`"
      :style="{ width: '700px' }"
    >
      <div class="loc-toolbar">
        <div class="view-switch">
          <Button label="Tree" size="small" :outlined="locView !== 'tree'" @click="switchView('tree')" />
          <Button v-if="canViewStock" label="Stock by location" size="small" :outlined="locView !== 'stock'" @click="switchView('stock')" />
        </div>
        <Button v-if="canCreate && locView === 'tree'" label="Add Zone/Location" icon="pi pi-plus" size="small" @click="openLocCreateRoot" />
      </div>

      <template v-if="locView === 'tree'">
        <Tree :value="locationNodes" v-model:expandedKeys="expandedKeys" :loading="locationsLoading" class="loc-tree">
          <template #default="{ node }">
            <div class="node-row">
              <Tag :severity="locTypeSeverity(node.data.type)" :value="node.data.type" />
              <span class="node-code">{{ node.data.code }}</span>
              <span class="node-name">{{ node.data.name }}</span>
              <span v-if="node.data.stock_quantity > 0" class="node-qty">qty: {{ node.data.stock_quantity }}</span>
              <Tag v-if="!node.data.is_active" value="inactive" severity="danger" />
              <span class="node-actions">
                <Button
                  v-if="canCreate && node.data.type !== 'bin'"
                  icon="pi pi-plus"
                  text
                  rounded
                  size="small"
                  title="Add child location"
                  @click.stop="openLocAddChild(node.data)"
                />
                <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click.stop="openLocEdit(node.data)" />
                <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click.stop="confirmDeleteLocation(node.data)" />
              </span>
            </div>
          </template>
        </Tree>
        <p v-if="!locationsLoading && !locationNodes.length" class="empty">No locations yet.</p>
      </template>

      <template v-else>
        <DataTable :value="stockRows" :loading="stockLoading" dataKey="id" class="p-datatable-sm">
          <Column header="Location code">
            <template #body="{ data }">{{ data.location?.code }}</template>
          </Column>
          <Column header="Location name">
            <template #body="{ data }">{{ data.location?.name }}</template>
          </Column>
          <Column header="Product">
            <template #body="{ data }">
              <span>{{ data.product?.name }}</span>
              <span class="muted-sku">{{ data.product?.sku }}</span>
            </template>
          </Column>
          <Column header="Qty">
            <template #body="{ data }">{{ data.quantity }}</template>
          </Column>
          <template #empty>No stock placed at locations yet.</template>
        </DataTable>
      </template>
    </Dialog>

    <!-- Location create / edit sub-dialog -->
    <Dialog v-model:visible="locDialogVisible" modal :header="locEditing ? 'Edit Location' : 'New Location'" :style="{ width: '480px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Code <span class="req">*</span></label>
          <InputText v-model="locForm.code" :class="{ invalid: locFieldError('code') }" />
          <small v-if="locFieldError('code')" class="error-text">{{ locFieldError('code') }}</small>
        </div>

        <div class="fld">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="locForm.name" :class="{ invalid: locFieldError('name') }" />
          <small v-if="locFieldError('name')" class="error-text">{{ locFieldError('name') }}</small>
        </div>

        <div class="fld">
          <label>Type <span class="req">*</span></label>
          <Select v-model="locForm.type" :options="locationTypeOptions" optionLabel="label" optionValue="value" :class="{ invalid: locFieldError('type') }" />
          <small v-if="locFieldError('type')" class="error-text">{{ locFieldError('type') }}</small>
        </div>

        <div class="fld">
          <label>Parent</label>
          <Select
            v-if="locEditing"
            v-model="locForm.parent_id"
            :options="locParentOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="— root —"
            showClear
            :class="{ invalid: locFieldError('parent_id') }"
          />
          <span v-else class="parent-static">{{ locParentLabel }}</span>
          <small v-if="locFieldError('parent_id')" class="error-text">{{ locFieldError('parent_id') }}</small>
        </div>

        <div class="fld">
          <label>Status</label>
          <div class="checkbox-row">
            <Checkbox v-model="locForm.is_active" binary inputId="loc_is_active" />
            <label for="loc_is_active">Active</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="locDialogVisible = false" />
        <Button :label="locEditing ? 'Update' : 'Create'" :loading="locSaving" @click="saveLocation" />
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
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.toolbar-spacer {
  flex: 1 1 auto;
}
.search {
  position: relative;
  display: flex;
  align-items: center;
}
.search i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.search :deep(input) {
  padding-left: 2.25rem;
  width: 280px;
}
.loc-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.75rem;
}
.view-switch {
  display: flex;
  gap: 0.35rem;
}
.loc-tree {
  border: none;
  padding: 0;
}
.node-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2rem;
}
.node-row :deep(.p-tag) {
  font-size: 0.65rem;
  padding: 1px 6px;
}
.node-code {
  font-weight: 600;
  font-family: monospace;
  font-size: 0.85rem;
}
.node-name {
  font-weight: 500;
}
.node-qty {
  color: #6b7280;
  font-size: 0.8rem;
}
.node-actions {
  display: flex;
  visibility: hidden;
  margin-left: 0.25rem;
}
.node-row:hover .node-actions {
  visibility: visible;
}
.empty {
  color: #6b7280;
  text-align: center;
  padding: 1rem;
}
.muted-sku {
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
.fld :deep(textarea) {
  width: 100%;
}
.parent-static {
  padding: 0.55rem 0;
  color: #374151;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.35rem;
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
