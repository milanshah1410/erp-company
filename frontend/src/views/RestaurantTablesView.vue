<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('restaurant-tables.create'))
const canEdit = computed(() => auth.can('restaurant-tables.edit'))
const canDelete = computed(() => auth.can('restaurant-tables.delete'))

const tables = ref<any[]>([])
const sections = ref<any[]>([])
const loading = ref(false)

const statusColors: Record<string, string> = {
  free: 'success',
  occupied: 'warn',
  reserved: 'info',
  cleaning: 'secondary',
}

async function load() {
  loading.value = true
  try {
    const [t, s] = await Promise.all([
      http.get('/restaurant-tables', { params: { per_page: 200 } }),
      http.get('/restaurant/sections'),
    ])
    tables.value = t.data.data.data ?? t.data.data
    sections.value = s.data.data
  } finally {
    loading.value = false
  }
}

const sectionOptions = computed(() =>
  sections.value.map((s: any) => ({ label: s.name, value: s.id })),
)

// ---- Table master ----------------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  restaurant_section_id: null as number | null,
  name: '',
  capacity: 4,
  is_active: true,
  sort_order: 0,
})
const form = ref(blank())

function openCreate() {
  editing.value = null
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  errors.value = {}
  form.value = {
    restaurant_section_id: row.restaurant_section_id,
    name: row.name,
    capacity: row.capacity,
    is_active: row.is_active,
    sort_order: row.sort_order,
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) await http.put(`/restaurant-tables/${editing.value.id}`, form.value)
    else await http.post('/restaurant-tables', form.value)
    toast.add({ severity: 'success', summary: pt('Table saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this table?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/restaurant-tables/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Table deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

// ---- Sections ---------------------------------------------------------------------

const sectionDialog = ref(false)
const sectionForm = ref({ name: '', sort_order: 0 })

async function saveSection() {
  try {
    await http.post('/restaurant/sections', sectionForm.value)
    sectionForm.value = { name: '', sort_order: 0 }
    sectionDialog.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save'), life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Tables & Sections')"
      :subtitle="pt('The floor plan the shift runs on')"
    />

    <div class="toolbar">
      <Button v-if="canCreate" :label="pt('New Table')" icon="pi pi-plus" @click="openCreate" />
      <Button v-if="canCreate" :label="pt('New Section')" icon="pi pi-th-large" outlined @click="sectionDialog = true" />
      <span class="muted">{{ sections.length }} {{ pt('sections') }} · {{ tables.length }} {{ pt('tables') }}</span>
    </div>

    <DataTable :value="tables" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column field="name" :header="pt('Table')" />
      <Column :header="pt('Section')">
        <template #body="{ data }">{{ data.section ?? '—' }}</template>
      </Column>
      <Column field="capacity" :header="pt('Seats')" />
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusColors[data.status]" /></template>
      </Column>
      <Column :header="pt('Active')">
        <template #body="{ data }">
          <Tag :value="data.is_active ? pt('Yes') : pt('No')" :severity="data.is_active ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column style="width: 6rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No tables yet.') }}</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Table') : pt('New Table')"
      :style="{ width: '26rem' }"
    >
      <div class="fld">
        <label>{{ pt('Table name') }} *</label>
        <InputText v-model="form.name" class="w-full" :placeholder="pt('e.g. T1')" />
        <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
      </div>
      <div class="fld">
        <label>{{ pt('Section') }}</label>
        <Select v-model="form.restaurant_section_id" :options="sectionOptions" optionLabel="label" optionValue="value" showClear class="w-full" />
      </div>
      <div class="fld">
        <label>{{ pt('Seats') }}</label>
        <InputNumber v-model="form.capacity" :min="1" :max="50" showButtons class="w-full" />
      </div>
      <div class="fld">
        <label>{{ pt('Sort order') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-full" />
      </div>
      <label class="chk">
        <Checkbox v-model="form.is_active" binary inputId="tbl-active" />
        <span>{{ pt('In service') }}</span>
      </label>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog v-model:visible="sectionDialog" modal :header="pt('New Section')" :style="{ width: '22rem' }">
      <div class="fld">
        <label>{{ pt('Section name') }} *</label>
        <InputText v-model="sectionForm.name" class="w-full" :placeholder="pt('e.g. AC Hall')" />
      </div>
      <div class="fld">
        <label>{{ pt('Sort order') }}</label>
        <InputNumber v-model="sectionForm.sort_order" :min="0" class="w-full" />
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="sectionDialog = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :disabled="!sectionForm.name" @click="saveSection" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.muted { color: #6b7280; font-size: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.75rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.err { color: #b91c1c; font-size: 0.75rem; }
.chk { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.85rem; color: #374151; }
</style>
