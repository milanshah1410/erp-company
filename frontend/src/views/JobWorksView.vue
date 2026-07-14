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
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/job-works')

const suppliers = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const canCreate = computed(() => auth.can('job-works.create'))
const canApprove = computed(() => auth.can('job-works.approve'))
const canEdit = computed(() => auth.can('job-works.edit'))
const canDelete = computed(() => auth.can('job-works.delete'))

const statusColors: Record<string, string> = {
  draft: 'secondary',
  issued: 'info',
  partial: 'warn',
  completed: 'success',
  closed: 'contrast',
  cancelled: 'danger',
}
const statusOptions = [
  { label: 'Draft', value: 'draft' },
  { label: 'Issued', value: 'issued' },
  { label: 'Partial', value: 'partial' },
  { label: 'Completed', value: 'completed' },
  { label: 'Closed', value: 'closed' },
  { label: 'Cancelled', value: 'cancelled' },
]

// --- Tabs ---
const tab = ref<'orders' | 'pending' | 'damage' | 'shortage'>('orders')
const tabs = [
  { key: 'orders', label: 'Job Works', icon: 'pi pi-briefcase' },
  { key: 'pending', label: 'Pending', icon: 'pi pi-clock' },
  { key: 'damage', label: 'Damage', icon: 'pi pi-exclamation-triangle' },
  { key: 'shortage', label: 'Shortage', icon: 'pi pi-minus-circle' },
] as const

const reportRows = ref<any[]>([])
const reportLoading = ref(false)

async function loadReport(kind: 'pending' | 'damage' | 'shortage') {
  reportLoading.value = true
  try {
    const { data } = await http.get(`/job-works/report/${kind}`)
    reportRows.value = data.data ?? data
  } finally {
    reportLoading.value = false
  }
}

function switchTab(key: typeof tab.value) {
  tab.value = key
  if (key === 'orders') reload()
  else loadReport(key)
}

const statusFilter = ref<string | null>(null)

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({ supplier_id: null, warehouse_id: null, job_type: '', job_work_date: today(), expected_date: null, notes: '', items: [] })
const errors = ref<Record<string, string[]>>({})

function today() {
  return new Date().toISOString().slice(0, 10)
}

function addLine() {
  form.value.items.push({ product_id: null, finished_product_id: null, issued_qty: 1 })
}
function removeLine(i: number | string) {
  form.value.items.splice(Number(i), 1)
}

function openCreate() {
  editingId.value = null
  form.value = { supplier_id: null, warehouse_id: null, job_type: '', job_work_date: today(), expected_date: null, notes: '', items: [] }
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  try {
    const { data } = await http.get(`/job-works/${row.id}`)
    const j = data.data
    editingId.value = j.id
    form.value = {
      supplier_id: j.supplier_id,
      warehouse_id: j.warehouse_id,
      job_type: j.job_type || '',
      job_work_date: j.job_work_date,
      expected_date: j.expected_date,
      notes: j.notes || '',
      items: j.items.map((i: any) => ({
        product_id: i.product_id,
        finished_product_id: i.finished_product_id,
        issued_qty: Number(i.issued_qty),
      })),
    }
    errors.value = {}
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load job work', life: 3000 })
  }
}

async function saveJobWork() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/job-works/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: 'Job work updated', life: 2500 })
    } else {
      await http.post('/job-works', form.value)
      toast.add({ severity: 'success', summary: 'Job work created as draft', life: 2500 })
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

// --- Receive dialog ---
const receiveVisible = ref(false)
const receiving = ref(false)
const receiveJob = ref<any>(null)
const receiveLines = ref<any[]>([])
const receiveError = ref<string | null>(null)

async function openReceive(row: any) {
  try {
    const { data } = await http.get(`/job-works/${row.id}`)
    receiveJob.value = data.data
    receiveLines.value = data.data.items
      .filter((i: any) => Number(i.pending_qty) > 0)
      .map((i: any) => ({
        item_id: i.id,
        product: i.product,
        finished_product: i.finished_product || i.product,
        pending: Number(i.pending_qty),
        received_qty: Number(i.pending_qty),
        damage_qty: 0,
        shortage_qty: 0,
      }))
    receiveError.value = null
    receiveVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load job work', life: 3000 })
  }
}

function lineTotal(l: any) {
  return Number(l.received_qty || 0) + Number(l.damage_qty || 0) + Number(l.shortage_qty || 0)
}

async function submitReceive() {
  const overfilled = receiveLines.value.find((l) => lineTotal(l) - l.pending > 0.0001)
  if (overfilled) {
    receiveError.value = `${overfilled.product?.name}: received + damage + shortage exceeds pending (${overfilled.pending}).`
    return
  }
  receiving.value = true
  receiveError.value = null
  try {
    await http.post(`/job-works/${receiveJob.value.id}/receive`, {
      items: receiveLines.value.map((l) => ({
        item_id: l.item_id,
        received_qty: l.received_qty || 0,
        damage_qty: l.damage_qty || 0,
        shortage_qty: l.shortage_qty || 0,
      })),
    })
    toast.add({ severity: 'success', summary: 'Material received', life: 3000 })
    receiveVisible.value = false
    reload()
  } catch (e: any) {
    receiveError.value = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed to receive.'
  } finally {
    receiving.value = false
  }
}

// --- Workflow actions ---
async function issueJob(row: any) {
  const res = await Swal.fire({
    title: `Issue material for ${row.job_work_number}?`,
    text: `Material will leave ${row.warehouse?.name} and go to ${row.supplier?.name}.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Issue',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/issue`)
    toast.add({ severity: 'success', summary: 'Material issued to vendor', life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || 'Failed'
    toast.add({ severity: 'error', summary: 'Cannot issue', detail, life: 5000 })
  }
}

async function closeJob(row: any) {
  const res = await Swal.fire({
    title: `Close ${row.job_work_number}?`,
    text: 'Any remaining pending quantity will be written off. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Close job work',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/close`)
    toast.add({ severity: 'success', summary: 'Job work closed', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function cancelJob(row: any) {
  const res = await Swal.fire({ title: `Cancel ${row.job_work_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel job work' })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function removeJob(row: any) {
  const res = await Swal.fire({ title: `Delete ${row.job_work_number}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/job-works/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  crud.fetch(statusFilter.value ? { status: statusFilter.value } : {})
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const [s, w, p] = await Promise.all([
    http.get('/suppliers', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 100 } }),
  ])
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = p.data.data ?? p.data
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Job Work</h1>
      <div class="header-actions">
        <Select v-if="tab === 'orders'" v-model="statusFilter" :options="statusOptions" optionLabel="label"
          optionValue="value" showClear placeholder="All statuses" @change="reload" class="status-filter" />
        <Button v-if="canCreate && tab === 'orders'" label="New Job Work" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <div class="tabbar">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- Orders tab -->
    <DataTable v-if="tab === 'orders'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="job_work_number" header="Job Work #" />
      <Column header="Vendor">
        <template #body="{ data }">{{ data.supplier?.name }}</template>
      </Column>
      <Column header="Warehouse">
        <template #body="{ data }">{{ data.warehouse?.name }}</template>
      </Column>
      <Column field="job_type" header="Type">
        <template #body="{ data }">{{ data.job_type || '—' }}</template>
      </Column>
      <Column field="job_work_date" header="Date" />
      <Column field="items_count" header="Lines" style="width: 5rem" />
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <template v-if="data.status === 'draft'">
            <Button v-if="canApprove" label="Issue" size="small" severity="success" outlined @click="issueJob(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" title="Cancel" @click="cancelJob(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeJob(data)" />
          </template>
          <template v-if="data.status === 'issued' || data.status === 'partial'">
            <Button v-if="canEdit" label="Receive" size="small" severity="success" @click="openReceive(data)" />
            <Button v-if="canApprove" icon="pi pi-lock" size="small" text severity="secondary" title="Close (write off pending)" @click="closeJob(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No job works yet.</template>
    </DataTable>

    <!-- Report tabs -->
    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="id" class="p-datatable-sm" paginator :rows="20">
      <Column field="job_work_number" header="Job Work #" />
      <Column header="Vendor">
        <template #body="{ data }">{{ data.supplier }}</template>
      </Column>
      <Column header="Product">
        <template #body="{ data }">{{ data.product?.name }}</template>
      </Column>
      <Column field="job_work_date" header="Issued On" />
      <Column header="Issued" style="text-align: right">
        <template #body="{ data }">{{ Number(data.issued_qty) }}</template>
      </Column>
      <Column header="Received" style="text-align: right">
        <template #body="{ data }">{{ Number(data.received_qty) }}</template>
      </Column>
      <Column v-if="tab === 'damage'" header="Damage" style="text-align: right">
        <template #body="{ data }"><strong class="dmg">{{ Number(data.damage_qty) }}</strong></template>
      </Column>
      <Column v-if="tab === 'shortage'" header="Shortage" style="text-align: right">
        <template #body="{ data }"><strong class="dmg">{{ Number(data.shortage_qty) }}</strong></template>
      </Column>
      <Column header="Pending" style="text-align: right">
        <template #body="{ data }"><strong class="pend">{{ Number(data.pending_qty) }}</strong></template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <template #empty>Nothing to show.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Job Work' : 'New Job Work'" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>Outside Vendor *</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter placeholder="Vendor (supplier)" />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Warehouse *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" placeholder="Issue / receive from" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>Job Type</label>
          <InputText v-model="form.job_type" placeholder="e.g. Stitching, Washing" />
        </div>
        <div class="fld">
          <label>Job Work Date *</label>
          <input type="date" v-model="form.job_work_date" class="date-input" />
          <small v-if="errors.job_work_date" class="err">{{ errors.job_work_date[0] }}</small>
        </div>
        <div class="fld">
          <label>Expected Return</label>
          <input type="date" v-model="form.expected_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">Material Lines</h3>
      <p class="hint">Issue cost is snapshotted from the warehouse when the job work is issued. Leave "Finished Product" blank if the same product is returned.</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 38%">Material Issued</th><th style="width: 38%">Finished Product Received</th><th>Qty</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter placeholder="Raw material" class="w-full" />
            </td>
            <td>
              <Select v-model="line.finished_product_id" :options="products" optionLabel="name" optionValue="id" filter showClear placeholder="Same as issued" class="w-full" />
            </td>
            <td><InputNumber v-model="line.issued_qty" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button label="Add Line" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>Notes</label>
        <Textarea v-model="form.notes" rows="2" placeholder="Instructions to the vendor, reference, etc." />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save Changes' : 'Create Draft'" :loading="saving" @click="saveJobWork" />
      </template>
    </Dialog>

    <!-- Receive dialog -->
    <Dialog v-model:visible="receiveVisible" modal :header="`Receive Material — ${receiveJob?.job_work_number}`" :style="{ width: '780px' }">
      <p class="hint">Enter the quantities received back, damaged and short for this receipt. Received goods are added to
        <strong>{{ receiveJob?.warehouse?.name }}</strong>. Damage and shortage reduce the pending balance without returning stock.</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 34%">Finished Product</th><th>Pending</th><th>Received</th><th>Damage</th><th>Shortage</th></tr>
        </thead>
        <tbody>
          <tr v-for="line in receiveLines" :key="line.item_id" :class="{ over: lineTotal(line) - line.pending > 0.0001 }">
            <td>
              <div class="prod">{{ line.finished_product?.name }}</div>
              <small v-if="line.finished_product?.id !== line.product?.id" class="from">from {{ line.product?.name }}</small>
            </td>
            <td class="pend-cell">{{ line.pending }}</td>
            <td><InputNumber v-model="line.received_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
            <td><InputNumber v-model="line.damage_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
            <td><InputNumber v-model="line.shortage_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!receiveLines.length" class="hint">Nothing pending on this job work.</p>
      <small v-if="receiveError" class="err block">{{ receiveError }}</small>

      <template #footer>
        <Button label="Cancel" text @click="receiveVisible = false" />
        <Button label="Receive Material" :loading="receiving" :disabled="!receiveLines.length" @click="submitReceive" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 160px; }
.tabbar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.tab { background: none; border: none; padding: 0.6rem 1rem; font: inherit; font-size: 0.9rem; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; display: flex; align-items: center; gap: 0.4rem; }
.tab:hover { color: #111827; }
.tab.active { color: var(--p-primary-color, #6366f1); border-bottom-color: var(--p-primary-color, #6366f1); font-weight: 600; }
.form-top { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { margin-top: 1rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.lines-title { font-size: 1rem; margin: 1.25rem 0 0.25rem; }
.hint { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.5rem; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.35rem; vertical-align: middle; }
.lines :deep(.p-select) { width: 100%; }
.num :deep(input) { width: 110px; }
.num.sm :deep(input) { width: 80px; }
.prod { font-size: 0.9rem; }
.from { color: #6b7280; font-size: 0.75rem; }
.pend-cell { font-weight: 600; }
tr.over td { background: #fef2f2; }
.dmg { color: #dc2626; }
.pend { color: #d97706; }
.err { color: #dc2626; font-size: 0.78rem; }
.err.block { display: block; margin-top: 0.5rem; }
.w-full { width: 100%; }
</style>
