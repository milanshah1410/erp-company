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
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const canCreate = computed(() => auth.can('referrals.create'))
const canEdit = computed(() => auth.can('referrals.edit'))
const canDelete = computed(() => auth.can('referrals.delete'))

const crud = ref(useCrud('/referrals'))
const search = ref('')
const statusFilter = ref<string | null>(null)
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const statusOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Joined', value: 'joined' },
  { label: 'Rewarded', value: 'rewarded' },
  { label: 'Cancelled', value: 'cancelled' },
]
const statusColors: Record<string, string> = {
  pending: 'secondary', joined: 'info', rewarded: 'success', cancelled: 'danger',
}

const customerOptions = ref<any[]>([])
async function loadCustomers() {
  try {
    const { data } = await http.get('/customers', { params: { per_page: 100 } })
    customerOptions.value = (data.data ?? []).map((c: any) => ({ label: `${c.name} (${c.code})`, value: c.id }))
  } catch {
    /* non-blocking */
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  if (sortField.value && sortOrder.value) { params.sort = sortField.value; params.direction = sortOrder.value === 1 ? 'asc' : 'desc' }
  crud.value.fetch(params)
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

// --- Create / edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return { referrer_customer_id: null, referred_name: '', referred_phone: '', reward_points: 0, notes: '' }
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
    referrer_customer_id: row.referrer_customer_id,
    referred_name: row.referred_name,
    referred_phone: row.referred_phone || '',
    reward_points: Number(row.reward_points ?? 0),
    notes: row.notes || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.value.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: 'Referral updated', life: 2500 })
    } else {
      await crud.value.create(form.value)
      toast.add({ severity: 'success', summary: 'Referral created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Mark joined dialog ---
const joinVisible = ref(false)
const joining = ref(false)
const joinRow = ref<any>(null)
const joinCustomerId = ref<number | null>(null)

function openJoin(row: any) {
  joinRow.value = row
  joinCustomerId.value = null
  if (!customerOptions.value.length) loadCustomers()
  joinVisible.value = true
}

async function doJoin() {
  if (!joinRow.value || !joinCustomerId.value) return
  joining.value = true
  try {
    await http.post(`/referrals/${joinRow.value.id}/joined`, { referred_customer_id: joinCustomerId.value })
    toast.add({ severity: 'success', summary: 'Marked joined', life: 2500 })
    joinVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    joining.value = false
  }
}

async function reward(row: any) {
  const res = await Swal.fire({
    title: 'Reward the referrer?',
    text: `${row.reward_points} loyalty points will be credited to ${row.referrer?.name ?? 'the referrer'}.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Reward',
    confirmButtonColor: '#16a34a',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/referrals/${row.id}/reward`)
    toast.add({ severity: 'success', summary: 'Referrer rewarded', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this referral?', icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Delete',
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

onMounted(() => {
  loadCustomers()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Referrals</h1>
      <Button v-if="canCreate" label="New Referral" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search referred / referrer..." @keyup.enter="reload" />
      </span>
      <Select
        v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
        placeholder="All statuses" showClear class="filter" @change="reload"
      />
      <Button label="Search" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="Referrals" endpoint="/referrals"
        :params="() => ({ search: search || undefined, status: statusFilter || undefined })"
        :columns="[
          { key: 'referrer.name', label: 'Referrer' },
          { key: 'referred_name', label: 'Referred' },
          { key: 'referred_phone', label: 'Phone' },
          { key: 'referred_customer.name', label: 'Became Customer' },
          { key: 'reward_points', label: 'Reward Pts' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable
      :value="crud.items" :loading="crud.loading" lazy paginator
      :rows="crud.perPage" :totalRecords="crud.total" :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id" @page="onPage" class="p-datatable-sm"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
    >
      <Column header="Referrer">
        <template #body="{ data }">{{ data.referrer?.name ?? '—' }}</template>
      </Column>
      <Column field="referred_name" header="Referred" sortable>
        <template #body="{ data }">
          <span>{{ data.referred_name }}</span>
          <span v-if="data.referred_phone" class="muted-sub">{{ data.referred_phone }}</span>
        </template>
      </Column>
      <Column header="Became Customer">
        <template #body="{ data }">{{ data.referred_customer?.name ?? '—' }}</template>
      </Column>
      <Column field="reward_points" header="Reward Pts" style="text-align: right" />
      <Column field="status" header="Status" sortable>
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button
            v-if="canEdit && data.status === 'pending'"
            icon="pi pi-user-plus" text rounded size="small" severity="info"
            title="Mark joined" @click="openJoin(data)"
          />
          <Button
            v-if="canEdit && data.status === 'joined'"
            icon="pi pi-gift" text rounded size="small" severity="success"
            title="Reward referrer" @click="reward(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No referrals found.</template>
    </DataTable>

    <!-- Create / edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? 'Edit Referral' : 'New Referral'" :style="{ width: '520px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>Referrer (existing customer) <span class="req">*</span></label>
          <Select
            v-model="form.referrer_customer_id" :options="customerOptions"
            optionLabel="label" optionValue="value" filter placeholder="Select customer"
            :class="{ invalid: fieldError('referrer_customer_id') }"
          />
          <small v-if="fieldError('referrer_customer_id')" class="error-text">{{ fieldError('referrer_customer_id') }}</small>
        </div>
        <div class="fld">
          <label>Referred Name <span class="req">*</span></label>
          <InputText v-model="form.referred_name" :class="{ invalid: fieldError('referred_name') }" />
          <small v-if="fieldError('referred_name')" class="error-text">{{ fieldError('referred_name') }}</small>
        </div>
        <div class="fld">
          <label>Referred Phone</label>
          <InputText v-model="form.referred_phone" />
        </div>
        <div class="fld">
          <label>Reward Points</label>
          <InputNumber v-model="form.reward_points" :min="0" />
        </div>
        <div class="fld full">
          <label>Notes</label>
          <Textarea v-model="form.notes" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Mark joined dialog -->
    <Dialog v-model:visible="joinVisible" modal header="Referral Joined" :style="{ width: '440px' }">
      <p class="muted small">Link this referral to the customer created from <strong>{{ joinRow?.referred_name }}</strong>.</p>
      <div class="fld full">
        <label>Customer <span class="req">*</span></label>
        <Select v-model="joinCustomerId" :options="customerOptions" optionLabel="label" optionValue="value" filter placeholder="Select customer" />
      </div>
      <template #footer>
        <Button label="Cancel" text @click="joinVisible = false" />
        <Button label="Mark Joined" :disabled="!joinCustomerId" :loading="joining" @click="doJoin" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Referral Details" :fields="[
      { key: 'referrer.name', label: 'Referrer' },
      { key: 'referred_name', label: 'Referred' },
      { key: 'referred_phone', label: 'Referred Phone' },
      { key: 'referred_customer.name', label: 'Became Customer' },
      { key: 'reward_points', label: 'Reward Points' },
      { key: 'status', label: 'Status' },
      { key: 'notes', label: 'Notes', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar-spacer { flex: 1 1 auto; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 280px; }
.filter { min-width: 160px; }
.muted-sub { display: block; color: #6b7280; font-size: 0.78rem; }
.muted.small { color: #6b7280; font-size: 0.82rem; margin: 0 0 0.75rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-inputnumber),
.fld :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
