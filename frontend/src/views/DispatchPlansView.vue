<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/dispatch-plans')

const vehicles = ref<any[]>([])
const drivers = ref<any[]>([])
const routes = ref<any[]>([])
const customers = ref<any[]>([])

const canCreate = computed(() => auth.can('dispatch-plans.create'))
const canEdit = computed(() => auth.can('dispatch-plans.edit'))
const canDelete = computed(() => auth.can('dispatch-plans.delete'))
const canSms = computed(() => auth.can('sms.create'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  dispatched: 'warn',
  delivered: 'success',
  cancelled: 'danger',
}

const search = ref('')
const statusFilter = ref<string | null>(null)
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Dispatched', value: 'dispatched' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}
function blankForm() {
  return {
    dispatch_date: today(),
    vehicle_id: null,
    driver_id: null,
    route_id: null,
    remarks: '',
    items: [] as any[],
  }
}
function newLine() {
  return { customer_id: null, sales_order_id: null, delivery_address: '', packages: 1, weight_kg: null, cod_amount: 0 }
}
function addLine() {
  form.value.items.push(newLine())
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/dispatch-plans/${row.id}`)
    const p = data.data
    editingId.value = p.id
    form.value = {
      dispatch_date: p.dispatch_date,
      vehicle_id: p.vehicle_id,
      driver_id: p.driver_id,
      route_id: p.route_id,
      remarks: p.remarks || '',
      items: p.items.map((i: any) => ({
        customer_id: i.customer_id,
        sales_order_id: i.sales_order_id,
        delivery_address: i.delivery_address || '',
        packages: i.packages,
        weight_kg: i.weight_kg != null ? Number(i.weight_kg) : null,
        cod_amount: i.cod_amount != null ? Number(i.cod_amount) : 0,
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load dispatch plan', life: 3000 })
  }
}

async function savePlan() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/dispatch-plans/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Dispatch plan updated', life: 2500 })
    } else {
      await http.post('/dispatch-plans', form.value)
      toast.add({ severity: 'success', summary: 'Dispatch plan created as draft', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

// --- Workflow actions ---
async function workflow(row: any, action: 'dispatch' | 'deliver' | 'cancel', title: string, verb: string) {
  const res = await Swal.fire({ title: `${title} ${row.dispatch_number}?`, icon: 'question', showCancelButton: true, confirmButtonText: verb })
  if (!res.isConfirmed) return
  try {
    await http.post(`/dispatch-plans/${row.id}/${action}`)
    toast.add({ severity: 'success', summary: `${title} done`, life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function notifySms(row: any) {
  const res = await Swal.fire({
    title: `Send dispatch SMS for ${row.dispatch_number}?`,
    text: 'Every consignment customer with a phone number will be notified.',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Send SMS',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/sms/dispatches/${row.id}`)
    toast.add({ severity: 'success', summary: data.message || 'Dispatch SMS sent', life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to send SMS', life: 4000 })
  }
}

async function removePlan(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.dispatch_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/dispatch-plans/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const [v, d, r, c] = await Promise.all([
    http.get('/vehicles', { params: { per_page: 100 } }),
    http.get('/drivers', { params: { per_page: 100 } }),
    http.get('/delivery-routes', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 100 } }),
  ])
  vehicles.value = (v.data.data ?? v.data).map((x: any) => ({ label: x.registration_no + (x.name ? ` — ${x.name}` : ''), value: x.id }))
  drivers.value = (d.data.data ?? d.data).map((x: any) => ({ label: x.name, value: x.id }))
  routes.value = (r.data.data ?? r.data).map((x: any) => ({ label: x.name, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
  reload()
})
</script>

<template>
  <div>
    <PageHeader title="Dispatch Planning" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="Dispatch Plans" endpoint="/dispatch-plans"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
        { key: 'dispatch_number', label: 'Dispatch #' },
        { key: 'dispatch_date', label: 'Date' },
        { key: 'vehicle', label: 'Vehicle' },
        { key: 'driver', label: 'Driver' },
        { key: 'route', label: 'Route' },
        { key: 'items_count', label: 'Drops' },
        { key: 'status', label: 'Status' },
        ]" />
        <Button v-if="canCreate" label="New Dispatch" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="dispatch_number" header="Dispatch #" />
      <Column field="dispatch_date" header="Date" />
      <Column header="Vehicle">
        <template #body="{ data }">{{ data.vehicle || '—' }}</template>
      </Column>
      <Column header="Driver">
        <template #body="{ data }">{{ data.driver || '—' }}</template>
      </Column>
      <Column header="Route">
        <template #body="{ data }">{{ data.route || '—' }}</template>
      </Column>
      <Column header="Drops" style="width: 6rem">
        <template #body="{ data }">{{ data.items_count }}</template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="data.status === 'draft'">
            <Button v-if="canEdit" label="Dispatch" size="small" severity="success" outlined @click="workflow(data, 'dispatch', 'Dispatch', 'Dispatch')" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="workflow(data, 'cancel', 'Cancel', 'Cancel plan')" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removePlan(data)" />
          </template>
          <Button v-if="canEdit && data.status === 'dispatched'" label="Mark Delivered" size="small" severity="success"
            @click="workflow(data, 'deliver', 'Mark delivered', 'Mark delivered')" />
          <Button v-if="canSms && data.status !== 'draft' && data.status !== 'cancelled'" icon="pi pi-comment"
            size="small" text severity="info" title="Send dispatch SMS" @click="notifySms(data)" />
        </template>
      </Column>
      <template #empty>No dispatch plans yet.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Dispatch Plan' : 'New Dispatch Plan'" :style="{ width: '860px' }">
      <div class="form-top">
        <div class="fld">
          <label>Dispatch Date *</label>
          <input type="date" v-model="form.dispatch_date" class="date-input" />
          <small v-if="errors.dispatch_date" class="err">{{ errors.dispatch_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Vehicle</label>
          <Select v-model="form.vehicle_id" :options="vehicles" optionLabel="label" optionValue="value" showClear filter placeholder="Vehicle" />
        </div>
        <div class="fld">
          <label>Driver</label>
          <Select v-model="form.driver_id" :options="drivers" optionLabel="label" optionValue="value" showClear filter placeholder="Driver" />
        </div>
        <div class="fld">
          <label>Route</label>
          <Select v-model="form.route_id" :options="routes" optionLabel="label" optionValue="value" showClear filter placeholder="Route" />
        </div>
      </div>

      <h3 class="lines-title">Consignments</h3>
      <table class="lines">
        <thead>
          <tr>
            <th style="width: 24%">Customer</th>
            <th style="width: 30%">Delivery Address</th>
            <th>Packages</th>
            <th>Weight (kg)</th>
            <th>COD Amount</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.customer_id" :options="customers" optionLabel="label" optionValue="value" filter showClear
                placeholder="Customer" class="w-full" />
            </td>
            <td><InputText v-model="line.delivery_address" class="w-full" placeholder="Address" /></td>
            <td><InputNumber v-model="line.packages" :min="1" class="num-sm" /></td>
            <td><InputNumber v-model="line.weight_kg" :minFractionDigits="0" :maxFractionDigits="2" class="num-sm" /></td>
            <td><InputNumber v-model="line.cod_amount" :minFractionDigits="0" :maxFractionDigits="2" class="num-sm" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Consignment" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Remarks</label>
        <Textarea v-model="form.remarks" rows="2" placeholder="e.g. loading instructions, contact person" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="savePlan" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Dispatch Plan Details" :fields="[
      { key: 'dispatch_number', label: 'Dispatch #' },
      { key: 'dispatch_date', label: 'Date' },
      { key: 'vehicle', label: 'Vehicle' },
      { key: 'driver', label: 'Driver' },
      { key: 'route', label: 'Route' },
      { key: 'items_count', label: 'Drops' },
      { key: 'status', label: 'Status' },
      { key: 'remarks', label: 'Remarks', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.form-top { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select), .lines :deep(.p-inputtext) { width: 100%; }
.num-sm :deep(input) { width: 90px; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
</style>
