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
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

/**
 * Ward indents on the central store.
 *
 * Two screens in one, because two people use it: a ward raises and submits,
 * the store approves (often trimming quantities) and issues. The issue dialog
 * shows on-hand beside every line, so the counter sees the shortfall before it
 * tries rather than discovering it from an error with a nurse waiting.
 */

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/hospital-indents')

const canCreate = computed(() => auth.can('hospital-indents.create'))
const canEdit = computed(() => auth.can('hospital-indents.edit'))
const canDelete = computed(() => auth.can('hospital-indents.delete'))
// Approving, rejecting and issuing are one authority: the store's.
const canApprove = computed(() => auth.can('hospital-indents.approve'))

const search = ref('')
const statusFilter = ref<string | null>(null)
const pendingIssueOnly = ref(false)
const summary = ref<any>({})

const departments = ref<any[]>([])
const warehouses = ref<any[]>([])
const products = ref<any[]>([])

const STATUSES = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Submitted'), value: 'submitted' },
  { label: pt('Approved'), value: 'approved' },
  { label: pt('Partially issued'), value: 'partially_issued' },
  { label: pt('Issued'), value: 'issued' },
  { label: pt('Rejected'), value: 'rejected' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

const PRIORITIES = computed(() => [
  { label: pt('Routine'), value: 'routine' },
  { label: pt('Urgent'), value: 'urgent' },
  { label: pt('Emergency'), value: 'emergency' },
])

const STATUS_SEVERITY: Record<string, string> = {
  draft: 'secondary',
  submitted: 'info',
  approved: 'warn',
  partially_issued: 'warn',
  issued: 'success',
  rejected: 'danger',
  cancelled: 'danger',
}

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function load() {
  const { data } = await http.get('/hospital-indents', {
    params: {
      per_page: 50,
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      pending_issue: pendingIssueOnly.value ? 1 : undefined,
    },
  })
  crud.items.value = data.data ?? data
  summary.value = data.summary ?? {}
}

async function loadLookups() {
  const [d, w, p] = await Promise.all([
    http.get('/departments', { params: { per_page: 200 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/products', { params: { per_page: 200, is_active: true } }),
  ])
  departments.value = (d.data.data ?? d.data).map((x: any) => ({ label: x.name, value: x.id }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.sku} — ${x.name}`,
    value: x.id,
  }))
}

// ---- Raise / amend -----------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  department_id: null as number | null,
  warehouse_id: null as number | null,
  indent_date: new Date() as Date | null,
  required_by: null as Date | null,
  priority: 'routine',
  notes: '',
  submit: false,
  // `id` travels with every line so the backend can match rather than re-create
  // — an edit form that lost it would double the ward's request.
  items: [] as { id?: number; product_id: number | null; requested_quantity: number; remarks: string }[],
})
const form = ref(blank())

function addLine() {
  form.value.items.push({ product_id: null, requested_quantity: 1, remarks: '' })
}

function removeLine(index: number) {
  form.value.items.splice(index, 1)
}

function openCreate() {
  editing.value = null
  form.value = blank()
  form.value.warehouse_id = warehouses.value[0]?.value ?? null
  addLine()
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  const { data } = await http.get(`/hospital-indents/${row.id}`)
  const i = data.data
  editing.value = i
  errors.value = {}
  form.value = {
    department_id: i.department_id,
    warehouse_id: i.warehouse_id,
    indent_date: i.indent_date ? new Date(i.indent_date) : null,
    required_by: i.required_by ? new Date(i.required_by) : null,
    priority: i.priority,
    notes: i.notes ?? '',
    submit: false,
    items: i.items.map((x: any) => ({
      id: x.id,
      product_id: x.product_id,
      requested_quantity: x.requested_quantity,
      remarks: x.remarks ?? '',
    })),
  }
  dialogVisible.value = true
}

async function save(submit = false) {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      submit,
      indent_date: iso(form.value.indent_date),
      required_by: iso(form.value.required_by),
      items: form.value.items.filter((l) => l.product_id),
    }
    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)
    toast.add({ severity: 'success', summary: pt('Indent saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the indent'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

// ---- Store actions --------------------------------------------------------------

async function act(row: any, action: string, body: Record<string, any> = {}, confirmText?: string) {
  if (confirmText) {
    const ok = await Swal.fire({
      title: confirmText,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: pt('Yes'),
      cancelButtonText: pt('Cancel'),
    })
    if (!ok.isConfirmed) return
  }

  try {
    const { data } = await http.post(`/hospital-indents/${row.id}/${action}`, body)
    toast.add({ severity: 'success', summary: data.message ?? pt('Done'), life: 3000 })
    await load()
    if (detail.value?.id === row.id && data.data) detail.value = data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Action failed'), life: 4500 })
  }
}

async function reject(row: any) {
  const { value: reason } = await Swal.fire({
    title: pt('Reject this indent?'),
    input: 'text',
    inputLabel: pt('Reason'),
    showCancelButton: true,
    confirmButtonText: pt('Reject'),
    cancelButtonText: pt('Cancel'),
    inputValidator: (v) => (v ? null : pt('A reason is required.')),
  })
  if (!reason) return
  await act(row, 'reject', { reason })
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Delete this indent?'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Indent deleted'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

// ---- Detail: approve + issue -------------------------------------------------------

const detailVisible = ref(false)
const detail = ref<any>(null)
const working = ref(false)
// Per-line quantities being approved / issued, keyed by indent-item id.
const draftQty = ref<Record<number, number>>({})

async function openDetail(row: any) {
  const { data } = await http.get(`/hospital-indents/${row.id}`)
  detail.value = data.data
  seedDraftQuantities()
  detailVisible.value = true
}

function seedDraftQuantities() {
  const map: Record<number, number> = {}
  for (const item of detail.value?.items ?? []) {
    // Before approval the store is agreeing to quantities; after it, releasing
    // them. Either way the sensible default is "all of what is outstanding".
    map[item.id] = detail.value.status === 'approved' || detail.value.status === 'partially_issued'
      ? item.pending_quantity
      : (item.approved_quantity ?? item.requested_quantity)
  }
  draftQty.value = map
}

async function approve() {
  if (!detail.value) return
  working.value = true
  try {
    const items = detail.value.items.map((i: any) => ({ id: i.id, quantity: draftQty.value[i.id] ?? 0 }))
    const { data } = await http.post(`/hospital-indents/${detail.value.id}/approve`, { items })
    detail.value = data.data
    seedDraftQuantities()
    toast.add({ severity: 'success', summary: data.message, life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Approval failed'), life: 4500 })
  } finally {
    working.value = false
  }
}

async function issue() {
  if (!detail.value) return
  working.value = true
  try {
    const items = detail.value.items
      .map((i: any) => ({ id: i.id, quantity: draftQty.value[i.id] ?? 0 }))
      .filter((l: any) => l.quantity > 0)
    const { data } = await http.post(`/hospital-indents/${detail.value.id}/issue`, { items })
    detail.value = data.data
    seedDraftQuantities()
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Issue failed'), life: 5000 })
  } finally {
    working.value = false
  }
}

function shortfall(item: any): boolean {
  return (draftQty.value[item.id] ?? 0) > item.available_quantity
}

onMounted(async () => {
  await Promise.all([loadLookups(), load()])
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Ward Indents')"
      :subtitle="pt('What each department asked the central store for, and what it was issued')"
    />

    <div class="cards">
      <div class="card">
        <span class="k">{{ pt('Awaiting approval') }}</span>
        <strong>{{ summary.awaiting_approval ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Pending issue') }}</span>
        <strong>{{ summary.pending_issue ?? 0 }}</strong>
      </div>
      <div class="card">
        <span class="k">{{ pt('Issued') }}</span>
        <strong>{{ summary.issued ?? 0 }}</strong>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Indent no or department')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All statuses')"
        class="w-14"
        @change="load"
      />
      <label class="inline-check">
        <input v-model="pendingIssueOnly" type="checkbox" @change="load" />
        {{ pt('Pending issue only') }}
      </label>
      <Button :label="pt('Search')" icon="pi pi-search" outlined @click="load" />
      <Button v-if="canCreate" :label="pt('New Indent')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Ward Indents"
        filenameBase="hospital-indents"
        :rows="crud.items.value"
        :columns="[
          { key: 'indent_number', label: 'Indent No' },
          { key: 'indent_date', label: 'Date' },
          { key: 'department', label: 'Department' },
          { key: 'warehouse', label: 'Store' },
          { key: 'priority', label: 'Priority' },
          { key: 'status', label: 'Status' },
          { key: 'requested_total', label: 'Requested' },
          { key: 'issued_total', label: 'Issued' },
          { key: 'issue_value', label: 'Value' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Indent')">
        <template #body="{ data }">
          <strong>{{ data.indent_number }}</strong>
          <Tag
            v-if="data.priority !== 'routine'"
            :value="data.priority"
            :severity="data.priority === 'emergency' ? 'danger' : 'warn'"
            class="ml-1"
          />
          <br /><small class="muted">{{ data.indent_date }}</small>
        </template>
      </Column>
      <Column :header="pt('Department')">
        <template #body="{ data }">
          {{ data.department }}
          <br /><small class="muted">{{ data.warehouse }}</small>
        </template>
      </Column>
      <Column :header="pt('Items')">
        <template #body="{ data }">{{ data.items_count }}</template>
      </Column>
      <Column :header="pt('Requested / Issued')">
        <template #body="{ data }">
          {{ data.requested_total }} / {{ data.issued_total }}
        </template>
      </Column>
      <Column :header="pt('Value')">
        <template #body="{ data }">{{ data.issue_value }}</template>
      </Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="STATUS_SEVERITY[data.status] ?? 'secondary'" />
          <br v-if="data.rejection_reason" />
          <small v-if="data.rejection_reason" class="muted">{{ data.rejection_reason }}</small>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 14rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text size="small" :title="pt('Open')" @click="openDetail(data)" />
          <Button
            v-if="canEdit && data.status === 'draft'"
            icon="pi pi-send"
            text
            size="small"
            :title="pt('Submit to store')"
            @click="act(data, 'submit')"
          />
          <Button
            v-if="canApprove && ['draft', 'submitted'].includes(data.status)"
            icon="pi pi-times"
            text
            size="small"
            severity="danger"
            :title="pt('Reject')"
            @click="reject(data)"
          />
          <Button
            v-if="canEdit && data.is_editable"
            icon="pi pi-pencil"
            text
            size="small"
            @click="openEdit(data)"
          />
          <Button
            v-if="canDelete && data.issued_total === 0"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>{{ pt('No indents match this view.') }}</template>
    </DataTable>

    <!-- Raise / amend -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Indent') : pt('New Ward Indent')"
      :style="{ width: '52rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Department / ward') }} *</label>
          <Select
            v-model="form.department_id"
            :options="departments"
            optionLabel="label"
            optionValue="value"
            filter
            class="w-full"
          />
          <small v-if="errors.department_id" class="err">{{ errors.department_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Issuing store') }} *</label>
          <Select
            v-model="form.warehouse_id"
            :options="warehouses"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
          <small v-if="errors.warehouse_id" class="err">{{ errors.warehouse_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Indent date') }} *</label>
          <DatePicker v-model="form.indent_date" dateFormat="yy-mm-dd" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Required by') }}</label>
          <DatePicker v-model="form.required_by" dateFormat="yy-mm-dd" showButtonBar class="w-full" />
          <small v-if="errors.required_by" class="err">{{ errors.required_by[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Priority') }}</label>
          <Select
            v-model="form.priority"
            :options="PRIORITIES"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
        </div>
      </div>

      <h4 class="sec">{{ pt('Items') }}</h4>
      <small v-if="errors.items" class="err">{{ errors.items[0] }}</small>
      <table class="lines">
        <thead>
          <tr>
            <th>{{ pt('Item') }}</th>
            <th style="width: 8rem">{{ pt('Quantity') }}</th>
            <th>{{ pt('Remarks') }}</th>
            <th style="width: 3rem"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(line, i) in form.items" :key="i">
            <td>
              <Select
                v-model="line.product_id"
                :options="products"
                optionLabel="label"
                optionValue="value"
                filter
                class="w-full"
                :placeholder="pt('Pick an item')"
              />
            </td>
            <td><InputNumber v-model="line.requested_quantity" :min="0" :maxFractionDigits="3" class="w-full" /></td>
            <td><InputText v-model="line.remarks" class="w-full" /></td>
            <td><Button icon="pi pi-times" text size="small" severity="danger" @click="removeLine(i)" /></td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add item')" icon="pi pi-plus" text size="small" @click="addLine" />

      <div class="grid mt">
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save draft')" icon="pi pi-save" outlined :loading="saving" @click="save(false)" />
        <Button :label="pt('Save & submit')" icon="pi pi-send" :loading="saving" @click="save(true)" />
      </template>
    </Dialog>

    <!-- Detail: approve + issue -->
    <Dialog
      v-model:visible="detailVisible"
      modal
      :header="detail ? `${detail.indent_number} — ${detail.department}` : pt('Indent')"
      :style="{ width: '58rem' }"
    >
      <template v-if="detail">
        <div class="meta">
          <span><strong>{{ pt('Status') }}:</strong> {{ detail.status }}</span>
          <span><strong>{{ pt('Store') }}:</strong> {{ detail.warehouse }}</span>
          <span v-if="detail.requested_by"><strong>{{ pt('Raised by') }}:</strong> {{ detail.requested_by }}</span>
          <span v-if="detail.approved_by"><strong>{{ pt('Approved by') }}:</strong> {{ detail.approved_by }}</span>
          <span><strong>{{ pt('Issued value') }}:</strong> {{ detail.issue_value }}</span>
        </div>

        <table class="lines">
          <thead>
            <tr>
              <th>{{ pt('Item') }}</th>
              <th style="width: 6rem">{{ pt('Asked') }}</th>
              <th style="width: 6rem">{{ pt('Allowed') }}</th>
              <th style="width: 6rem">{{ pt('Issued') }}</th>
              <th style="width: 6rem">{{ pt('In store') }}</th>
              <th style="width: 8rem">
                {{ detail.is_issuable ? pt('Issue now') : pt('Approve qty') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in detail.items" :key="item.id">
              <td>
                {{ item.product }}
                <br /><small class="muted">{{ item.sku }}{{ item.batch_number ? ` · ${item.batch_number}` : '' }}</small>
              </td>
              <td>{{ item.requested_quantity }}</td>
              <td>{{ item.approved_quantity ?? '—' }}</td>
              <td>{{ item.issued_quantity }}</td>
              <td :class="{ short: shortfall(item) }">{{ item.available_quantity }}</td>
              <td>
                <InputNumber
                  v-if="canApprove && ['draft', 'submitted', 'approved', 'partially_issued'].includes(detail.status)"
                  v-model="draftQty[item.id]"
                  :min="0"
                  :maxFractionDigits="3"
                  class="w-full"
                />
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>

        <small class="hint">
          {{
            pt(
              'The store may cut a line but never raise it above what was asked for. An issue is refused if the shelf cannot cover it.',
            )
          }}
        </small>
      </template>

      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
        <Button
          v-if="canApprove && detail && ['draft', 'submitted'].includes(detail.status)"
          :label="pt('Approve')"
          icon="pi pi-check"
          :loading="working"
          @click="approve"
        />
        <Button
          v-if="canApprove && detail && detail.is_issuable"
          :label="pt('Issue to ward')"
          icon="pi pi-arrow-right"
          severity="success"
          :loading="working"
          @click="issue"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.cards { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem 1rem; min-width: 9rem; }
.card .k { display: block; font-size: 0.72rem; color: #6b7280; }
.card strong { font-size: 1.15rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.inline-check { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #374151; }
.muted { color: #6b7280; }
.ml-1 { margin-left: 0.35rem; }
.short { color: #b91c1c; font-weight: 600; }
.sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.grid.mt { margin-top: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.w-full { width: 100%; }
.w-14 { width: 12rem; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.lines { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.lines th { text-align: left; font-weight: 600; padding: 0.35rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.3rem 0.35rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.meta { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.82rem; margin-bottom: 0.75rem; }
</style>
