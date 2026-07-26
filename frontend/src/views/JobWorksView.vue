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
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/job-works')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

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
const statusOptions = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Issued'), value: 'issued' },
  { label: pt('Partial'), value: 'partial' },
  { label: pt('Completed'), value: 'completed' },
  { label: pt('Closed'), value: 'closed' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

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
const search = ref('')

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
    toast.add({ severity: 'error', summary: pt('Could not load job work'), life: 3000 })
  }
}

async function saveJobWork() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/job-works/${editingId.value}`, form.value)
      toast.add({ severity: 'success', summary: pt('Job work updated'), life: 2500 })
    } else {
      await http.post('/job-works', form.value)
      toast.add({ severity: 'success', summary: pt('Job work created as draft'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
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
    toast.add({ severity: 'error', summary: pt('Could not load job work'), life: 3000 })
  }
}

function lineTotal(l: any) {
  return Number(l.received_qty || 0) + Number(l.damage_qty || 0) + Number(l.shortage_qty || 0)
}

async function submitReceive() {
  const overfilled = receiveLines.value.find((l) => lineTotal(l) - l.pending > 0.0001)
  if (overfilled) {
    receiveError.value = pt('{name}: received + damage + shortage exceeds pending ({pending}).', { name: overfilled.product?.name, pending: overfilled.pending })
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
    toast.add({ severity: 'success', summary: pt('Material received'), life: 3000 })
    receiveVisible.value = false
    reload()
  } catch (e: any) {
    receiveError.value = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || pt('Failed to receive.')
  } finally {
    receiving.value = false
  }
}

// --- Workflow actions ---
async function issueJob(row: any) {
  const res = await Swal.fire({
    title: pt('Issue material for {n}?', { n: row.job_work_number }),
    text: pt('Material will leave {w} and go to {s}.', { w: row.warehouse?.name, s: row.supplier?.name }),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Issue'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/issue`)
    toast.add({ severity: 'success', summary: pt('Material issued to vendor'), life: 3000 })
    reload()
  } catch (e: any) {
    const detail = e.response?.data?.errors?.items?.[0] || e.response?.data?.message || pt('Failed')
    toast.add({ severity: 'error', summary: pt('Cannot issue'), detail, life: 5000 })
  }
}

async function closeJob(row: any) {
  const res = await Swal.fire({
    title: pt('Close {n}?', { n: row.job_work_number }),
    text: pt('Any remaining pending quantity will be written off. This cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Close job work'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/close`)
    toast.add({ severity: 'success', summary: pt('Job work closed'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}

async function cancelJob(row: any) {
  const res = await Swal.fire({ title: pt('Cancel {n}?', { n: row.job_work_number }), icon: 'warning', showCancelButton: true, confirmButtonText: pt('Cancel job work'), cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  try {
    await http.post(`/job-works/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: pt('Cancelled'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}

async function removeJob(row: any) {
  const res = await Swal.fire({ title: pt('Delete {n}?', { n: row.job_work_number }), icon: 'warning', showCancelButton: true, confirmButtonText: pt('Delete'), cancelButtonText: pt('Cancel') })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/job-works/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}

function reload() {
  crud.fetch({ status: statusFilter.value || undefined, search: search.value || undefined })
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
    <PageHeader :title="pt('Job Work')" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span v-if="tab === 'orders'" class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
        </span>
        <Button v-if="tab === 'orders'" :label="pt('Search')" outlined @click="reload" />
        <Select v-if="tab === 'orders'" v-model="statusFilter" :options="statusOptions" optionLabel="label"
        optionValue="value" showClear :placeholder="pt('All statuses')" @change="reload" class="status-filter" />
        <TableExportButtons v-if="tab === 'orders'" :title="pt('Job Work')" endpoint="/job-works"
        :params="() => ({ status: statusFilter || undefined, search: search || undefined })"
        :columns="[
        { key: 'job_work_number', label: pt('Job Work #') },
        { key: 'supplier.name', label: pt('Vendor') },
        { key: 'warehouse.name', label: pt('Warehouse') },
        { key: 'job_type', label: pt('Type') },
        { key: 'job_work_date', label: pt('Date') },
        { key: 'items_count', label: pt('Lines') },
        { key: 'status', label: pt('Status') },
        ]" />
        <Button v-if="canCreate && tab === 'orders'" :label="pt('New Job Work')" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </PageHeader>

    <div class="tabbar">
      <button v-for="t in tabs" :key="t.key" class="tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        <i :class="t.icon" /> {{ pt(t.label) }}
      </button>
    </div>

    <!-- Orders tab -->
    <DataTable v-if="tab === 'orders'" :value="crud.items.value" :loading="crud.loading.value" lazy paginator
      :rows="crud.perPage.value" :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id"
      @page="onPage" class="p-datatable-sm">
      <Column field="job_work_number" :header="pt('Job Work #')" />
      <Column :header="pt('Vendor')">
        <template #body="{ data }">{{ data.supplier?.name }}</template>
      </Column>
      <Column :header="pt('Warehouse')">
        <template #body="{ data }">{{ data.warehouse?.name }}</template>
      </Column>
      <Column field="job_type" :header="pt('Type')">
        <template #body="{ data }">{{ data.job_type || '—' }}</template>
      </Column>
      <Column field="job_work_date" :header="pt('Date')" />
      <Column field="items_count" :header="pt('Lines')" style="width: 5rem" />
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="pt(data.status)" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <template v-if="data.status === 'draft'">
            <Button v-if="canApprove" :label="pt('Issue')" size="small" severity="success" outlined @click="issueJob(data)" />
            <Button v-if="canEdit" icon="pi pi-pencil" size="small" text @click="openEdit(data)" />
            <Button v-if="canEdit" icon="pi pi-ban" size="small" text severity="warn" :title="pt('Cancel')" @click="cancelJob(data)" />
            <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="removeJob(data)" />
          </template>
          <template v-if="data.status === 'issued' || data.status === 'partial'">
            <Button v-if="canEdit" :label="pt('Receive')" size="small" severity="success" @click="openReceive(data)" />
            <Button v-if="canApprove" icon="pi pi-lock" size="small" text severity="secondary" :title="pt('Close (write off pending)')" @click="closeJob(data)" />
          </template>
        </template>
      </Column>
      <template #empty>{{ pt('No job works yet.') }}</template>
    </DataTable>

    <!-- Report tabs -->
    <DataTable v-else :value="reportRows" :loading="reportLoading" dataKey="id" class="p-datatable-sm" paginator :rows="20">
      <Column field="job_work_number" :header="pt('Job Work #')" />
      <Column :header="pt('Vendor')">
        <template #body="{ data }">{{ data.supplier }}</template>
      </Column>
      <Column :header="pt('Product')">
        <template #body="{ data }">{{ data.product?.name }}</template>
      </Column>
      <Column field="job_work_date" :header="pt('Issued On')" />
      <Column :header="pt('Issued')" style="text-align: right">
        <template #body="{ data }">{{ Number(data.issued_qty) }}</template>
      </Column>
      <Column :header="pt('Received')" style="text-align: right">
        <template #body="{ data }">{{ Number(data.received_qty) }}</template>
      </Column>
      <Column v-if="tab === 'damage'" :header="pt('Damage')" style="text-align: right">
        <template #body="{ data }"><strong class="dmg">{{ Number(data.damage_qty) }}</strong></template>
      </Column>
      <Column v-if="tab === 'shortage'" :header="pt('Shortage')" style="text-align: right">
        <template #body="{ data }"><strong class="dmg">{{ Number(data.shortage_qty) }}</strong></template>
      </Column>
      <Column :header="pt('Pending')" style="text-align: right">
        <template #body="{ data }"><strong class="pend">{{ Number(data.pending_qty) }}</strong></template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="pt(data.status)" /></template>
      </Column>
      <template #empty>{{ pt('Nothing to show.') }}</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? pt('Edit Job Work') : pt('New Job Work')" :style="{ width: '820px' }">
      <div class="form-top">
        <div class="fld">
          <label>{{ pt('Outside Vendor') }} *</label>
          <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter :placeholder="pt('Vendor (supplier)')" />
          <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Warehouse') }} *</label>
          <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value" :placeholder="pt('Issue / receive from')" />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Job Type') }}</label>
          <InputText v-model="form.job_type" :placeholder="pt('e.g. Stitching, Washing')" />
        </div>
        <div class="fld">
          <label>{{ pt('Job Work Date') }} *</label>
          <input type="date" v-model="form.job_work_date" class="date-input" />
          <small v-if="errors.job_work_date" class="err">{{ errors.job_work_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Expected Return') }}</label>
          <input type="date" v-model="form.expected_date" class="date-input" />
        </div>
      </div>

      <h3 class="lines-title">{{ pt('Material Lines') }}</h3>
      <p class="hint">{{ pt('Issue cost is snapshotted from the warehouse when the job work is issued. Leave "Finished Product" blank if the same product is returned.') }}</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 38%">{{ pt('Material Issued') }}</th><th style="width: 38%">{{ pt('Finished Product Received') }}</th><th>{{ pt('Qty') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select v-model="line.product_id" :options="products" optionLabel="name" optionValue="id" filter :placeholder="pt('Raw material')" class="w-full" />
            </td>
            <td>
              <Select v-model="line.finished_product_id" :options="products" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Same as issued')" class="w-full" />
            </td>
            <td><InputNumber v-model="line.issued_qty" :minFractionDigits="0" :maxFractionDigits="3" class="num" /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add Line')" icon="pi pi-plus" text size="small" @click="addLine" />
      <small v-if="errors.items" class="err block">{{ errors.items[0] }}</small>

      <div class="fld full">
        <label>{{ pt('Notes') }}</label>
        <Textarea v-model="form.notes" rows="2" :placeholder="pt('Instructions to the vendor, reference, etc.')" />
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editingId ? pt('Save Changes') : pt('Create Draft')" :loading="saving" @click="saveJobWork" />
      </template>
    </Dialog>

    <!-- Receive dialog -->
    <Dialog v-model:visible="receiveVisible" modal :header="pt('Receive Material — {n}', { n: receiveJob?.job_work_number })" :style="{ width: '780px' }">
      <p class="hint">{{ pt('Enter the quantities received back, damaged and short for this receipt. Received goods are added to') }}
        <strong>{{ receiveJob?.warehouse?.name }}</strong>. {{ pt('Damage and shortage reduce the pending balance without returning stock.') }}</p>
      <table class="lines">
        <thead>
          <tr><th style="width: 34%">{{ pt('Finished Product') }}</th><th>{{ pt('Pending') }}</th><th>{{ pt('Received') }}</th><th>{{ pt('Damage') }}</th><th>{{ pt('Shortage') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="line in receiveLines" :key="line.item_id" :class="{ over: lineTotal(line) - line.pending > 0.0001 }">
            <td>
              <div class="prod">{{ line.finished_product?.name }}</div>
              <small v-if="line.finished_product?.id !== line.product?.id" class="from">{{ pt('from {n}', { n: line.product?.name }) }}</small>
            </td>
            <td class="pend-cell">{{ line.pending }}</td>
            <td><InputNumber v-model="line.received_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
            <td><InputNumber v-model="line.damage_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
            <td><InputNumber v-model="line.shortage_qty" :min="0" :minFractionDigits="0" :maxFractionDigits="3" class="num sm" /></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!receiveLines.length" class="hint">{{ pt('Nothing pending on this job work.') }}</p>
      <small v-if="receiveError" class="err block">{{ receiveError }}</small>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="receiveVisible = false" />
        <Button :label="pt('Receive Material')" :loading="receiving" :disabled="!receiveLines.length" @click="submitReceive" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Job Work Details')" :fields="[
      { key: 'job_work_number', label: pt('Job Work #') },
      { key: 'supplier.name', label: pt('Vendor') },
      { key: 'warehouse.name', label: pt('Warehouse') },
      { key: 'job_type', label: pt('Job Type') },
      { key: 'job_work_date', label: pt('Job Work Date') },
      { key: 'expected_date', label: pt('Expected Return') },
      { key: 'items_count', label: pt('Lines') },
      { key: 'status', label: pt('Status') },
      { key: 'notes', label: pt('Notes'), full: true },
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
