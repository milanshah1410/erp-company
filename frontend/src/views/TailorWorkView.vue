<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

// 'pending' → open work (Pending Work screen); 'completed' → finished work.
const mode = computed(() => (route.meta.workMode as string) || 'pending')
const isPending = computed(() => mode.value === 'pending')

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')

const tailors = ref<{ label: string; value: number }[]>([])
const products = ref<{ label: string; value: number }[]>([])
const warehouses = ref<{ label: string; value: number }[]>([])

const canCreate = computed(() => auth.can('tailor-works.create'))
const canEdit = computed(() => auth.can('tailor-works.edit'))
const canDelete = computed(() => auth.can('tailor-works.delete'))

// --- Issue work dialog ---
const issueVisible = ref(false)
const savingIssue = ref(false)
const editingId = ref<number | null>(null)
const issueErrors = ref<Record<string, string[]>>({})
const issueForm = ref<any>({ tailor_id: null, product_id: null, issued_qty: null, rate: null, due_date: null, notes: '' })

// --- Complete work dialog ---
const completeVisible = ref(false)
const savingComplete = ref(false)
const completeErrors = ref<Record<string, string[]>>({})
const completeTarget = ref<any>(null)
const completeForm = ref<any>({ qty: null, rejected_qty: 0, reject_action: 'rework', rejection_reason: '', completed_at: new Date(), received_to_stock: false, warehouse_id: null, notes: '' })

const rejectActionOptions = [
  { label: 'Rework (redo, stays pending)', value: 'rework' },
  { label: 'Scrap (write off)', value: 'scrap' },
]

function money(v: any) {
  return formatCurrency(v)
}

function statusSeverity(status: string) {
  return { pending: 'warn', partial: 'info', completed: 'success', cancelled: 'danger' }[status] || 'secondary'
}

function fieldError(errs: Record<string, string[]>, key: string): string | null {
  return errs[key]?.[0] ?? null
}

function toDateString(d: Date | null): string | undefined {
  return d ? d.toISOString().slice(0, 10) : undefined
}

async function loadOptions() {
  const [t, p, w] = await Promise.all([
    http.get('/tailors', { params: { per_page: 100, is_active: true } }),
    http.get('/products', { params: { per_page: 100 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
  ])
  tailors.value = (t.data.data ?? t.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({ label: `${x.sku} — ${x.name}`, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/tailor-works', {
      params: {
        status: mode.value,
        search: search.value || undefined,
        page: page.value,
        per_page: perPage.value,
      },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function openIssue() {
  editingId.value = null
  issueForm.value = { tailor_id: null, product_id: null, issued_qty: null, rate: null, due_date: null, notes: '' }
  issueErrors.value = {}
  issueVisible.value = true
}

function openEdit(row: any) {
  editingId.value = row.id
  issueForm.value = {
    tailor_id: row.tailor_id,
    product_id: row.product_id,
    issued_qty: Number(row.issued_qty),
    rate: Number(row.rate),
    due_date: row.due_date ? new Date(row.due_date) : null,
    notes: row.notes || '',
  }
  issueErrors.value = {}
  issueVisible.value = true
}

async function saveIssue() {
  savingIssue.value = true
  issueErrors.value = {}
  try {
    const payload = {
      tailor_id: issueForm.value.tailor_id,
      product_id: issueForm.value.product_id,
      issued_qty: issueForm.value.issued_qty,
      rate: issueForm.value.rate ?? undefined,
      due_date: toDateString(issueForm.value.due_date),
      notes: issueForm.value.notes || undefined,
    }
    if (editingId.value) {
      await http.put(`/tailor-works/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Work updated', life: 2500 })
    } else {
      await http.post('/tailor-works', payload)
      toast.add({ severity: 'success', summary: 'Work issued', life: 2500 })
    }
    issueVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) issueErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    savingIssue.value = false
  }
}

function openComplete(row: any) {
  completeTarget.value = row
  completeForm.value = {
    qty: Number(row.remaining_qty),
    rejected_qty: 0,
    reject_action: 'rework',
    rejection_reason: '',
    completed_at: new Date(),
    received_to_stock: false,
    warehouse_id: null,
    notes: '',
  }
  completeErrors.value = {}
  completeVisible.value = true
}

async function saveComplete() {
  if (!completeTarget.value) return
  savingComplete.value = true
  completeErrors.value = {}
  try {
    const rejected = Number(completeForm.value.rejected_qty) || 0
    const payload = {
      qty: completeForm.value.qty,
      rejected_qty: rejected || undefined,
      reject_action: rejected > 0 ? completeForm.value.reject_action : undefined,
      rejection_reason: rejected > 0 ? (completeForm.value.rejection_reason || undefined) : undefined,
      completed_at: toDateString(completeForm.value.completed_at),
      received_to_stock: completeForm.value.received_to_stock,
      warehouse_id: completeForm.value.received_to_stock ? completeForm.value.warehouse_id : undefined,
      notes: completeForm.value.notes || undefined,
    }
    await http.post(`/tailor-works/${completeTarget.value.id}/complete`, payload)
    toast.add({ severity: 'success', summary: 'Completion recorded', life: 2500 })
    completeVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) completeErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    savingComplete.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this work order?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/tailor-works/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

watch(mode, () => {
  page.value = 1
  search.value = ''
  load()
})

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>{{ isPending ? 'Pending Work' : 'Completed Work' }}</h1>
      <Button v-if="isPending && canCreate" label="Issue Work" icon="pi pi-plus" @click="openIssue" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search work #, tailor, style..." @keyup.enter="load" />
      </span>
      <Button label="Search" outlined @click="load" />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id"
      @page="onPage"
      class="p-datatable-sm"
    >
      <Column field="work_number" header="Work #" />
      <Column field="tailor_name" header="Tailor" />
      <Column field="product_name" header="Style / Product" />
      <Column header="Rate">
        <template #body="{ data }">{{ money(data.rate) }}</template>
      </Column>
      <Column header="Issued">
        <template #body="{ data }">{{ Number(data.issued_qty) }}</template>
      </Column>
      <Column header="Completed">
        <template #body="{ data }">{{ Number(data.completed_qty) }}</template>
      </Column>
      <Column header="Rejected">
        <template #body="{ data }">
          <span :class="{ bad: Number(data.rejected_qty) > 0 }">{{ Number(data.rejected_qty) }}</span>
        </template>
      </Column>
      <Column v-if="isPending" header="Remaining">
        <template #body="{ data }">{{ Number(data.remaining_qty) }}</template>
      </Column>
      <Column header="Earned">
        <template #body="{ data }">{{ money(data.earned) }}</template>
      </Column>
      <Column field="due_date" header="Due" />
      <Column header="Status">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column header="Actions" style="width: 11rem">
        <template #body="{ data }">
          <template v-if="isPending">
            <Button v-if="canEdit" label="Complete" icon="pi pi-check" text size="small" @click="openComplete(data)" />
            <Button v-if="canEdit && Number(data.completed_qty) === 0" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
            <Button v-if="canDelete && Number(data.completed_qty) === 0" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <template #empty>{{ isPending ? 'No pending work.' : 'No completed work yet.' }}</template>
    </DataTable>

    <!-- Issue / edit work -->
    <Dialog v-model:visible="issueVisible" modal :header="editingId ? 'Edit Work Order' : 'Issue Work'" :style="{ width: '520px' }">
      <div class="form">
        <div class="field">
          <label>Tailor <span class="req">*</span></label>
          <Select v-model="issueForm.tailor_id" :options="tailors" optionLabel="label" optionValue="value" filter
            placeholder="Select tailor" :class="{ invalid: fieldError(issueErrors, 'tailor_id') }" />
          <small v-if="fieldError(issueErrors, 'tailor_id')" class="error-text">{{ fieldError(issueErrors, 'tailor_id') }}</small>
        </div>
        <div class="field">
          <label>Style / Product <span class="req">*</span></label>
          <Select v-model="issueForm.product_id" :options="products" optionLabel="label" optionValue="value" filter
            placeholder="Select product" :class="{ invalid: fieldError(issueErrors, 'product_id') }" />
          <small v-if="fieldError(issueErrors, 'product_id')" class="error-text">{{ fieldError(issueErrors, 'product_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Issued Qty <span class="req">*</span></label>
            <InputNumber v-model="issueForm.issued_qty" :min="0" :class="{ invalid: fieldError(issueErrors, 'issued_qty') }" />
            <small v-if="fieldError(issueErrors, 'issued_qty')" class="error-text">{{ fieldError(issueErrors, 'issued_qty') }}</small>
          </div>
          <div class="field">
            <label>Rate / piece</label>
            <InputNumber v-model="issueForm.rate" mode="currency" currency="INR" locale="en-IN" :min="0" />
            <small class="hint">Leave blank to use the style's rate card.</small>
          </div>
        </div>
        <div class="field">
          <label>Due Date</label>
          <DatePicker v-model="issueForm.due_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="issueForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="issueVisible = false" />
        <Button :label="editingId ? 'Update' : 'Issue'" :loading="savingIssue" @click="saveIssue" />
      </template>
    </Dialog>

    <!-- Complete work -->
    <Dialog v-model:visible="completeVisible" modal header="Record Completion" :style="{ width: '460px' }">
      <div v-if="completeTarget" class="form">
        <p class="ctx">
          {{ completeTarget.work_number }} · {{ completeTarget.tailor_name }} · {{ completeTarget.product_name }}
          <br /><small>Remaining: {{ Number(completeTarget.remaining_qty) }} @ {{ money(completeTarget.rate) }}/pc</small>
        </p>
        <div class="field">
          <label>Accepted Qty <span class="req">*</span></label>
          <InputNumber v-model="completeForm.qty" :min="0" :max="Number(completeTarget.remaining_qty)"
            :class="{ invalid: fieldError(completeErrors, 'qty') }" />
          <small v-if="fieldError(completeErrors, 'qty')" class="error-text">{{ fieldError(completeErrors, 'qty') }}</small>
          <small v-else class="hint">Good pieces that pass QC — only these are paid.</small>
        </div>
        <div class="field">
          <label>Rejected Qty (QC fail)</label>
          <InputNumber v-model="completeForm.rejected_qty" :min="0"
            :class="{ invalid: fieldError(completeErrors, 'rejected_qty') }" />
          <small v-if="fieldError(completeErrors, 'rejected_qty')" class="error-text">{{ fieldError(completeErrors, 'rejected_qty') }}</small>
        </div>
        <template v-if="Number(completeForm.rejected_qty) > 0">
          <div class="field">
            <label>Reject Action <span class="req">*</span></label>
            <Select v-model="completeForm.reject_action" :options="rejectActionOptions" optionLabel="label" optionValue="value" />
            <small class="hint">Rework returns the pieces to pending; scrap writes them off.</small>
          </div>
          <div class="field">
            <label>Rejection Reason</label>
            <InputText v-model="completeForm.rejection_reason" placeholder="e.g. stitching defect" />
          </div>
        </template>
        <div class="field">
          <label>Completion Date</label>
          <DatePicker v-model="completeForm.completed_at" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
        </div>
        <div class="field checkbox-row">
          <Checkbox v-model="completeForm.received_to_stock" binary inputId="rts" />
          <label for="rts">Receive finished goods into stock</label>
        </div>
        <div v-if="completeForm.received_to_stock" class="field">
          <label>Warehouse <span class="req">*</span></label>
          <Select v-model="completeForm.warehouse_id" :options="warehouses" optionLabel="label" optionValue="value"
            placeholder="Select warehouse" :class="{ invalid: fieldError(completeErrors, 'warehouse_id') }" />
          <small v-if="fieldError(completeErrors, 'warehouse_id')" class="error-text">{{ fieldError(completeErrors, 'warehouse_id') }}</small>
        </div>
        <div class="field">
          <label>Notes</label>
          <Textarea v-model="completeForm.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="completeVisible = false" />
        <Button label="Save" :loading="savingComplete" @click="saveComplete" />
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
  width: 320px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 0.5rem;
}
.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.field :deep(.p-inputnumber),
.field :deep(.p-select),
.field :deep(.p-datepicker),
.field :deep(.p-inputtext) {
  width: 100%;
}
.checkbox-row {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}
.ctx {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  padding: 0.65rem 0.85rem;
  font-size: 0.9rem;
  color: #334155;
}
.req {
  color: #dc2626;
}
.hint {
  color: #64748b;
  font-size: 0.75rem;
}
.muted {
  color: #9ca3af;
}
.bad {
  color: #dc2626;
  font-weight: 600;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext) {
  border-color: #dc2626 !important;
}
</style>
