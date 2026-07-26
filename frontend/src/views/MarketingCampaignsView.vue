<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()
const auth = useAuthStore()

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const canCreate = computed(() => auth.can('marketing-campaigns.create'))
const canEdit = computed(() => auth.can('marketing-campaigns.edit'))
const canDelete = computed(() => auth.can('marketing-campaigns.delete'))

const items = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const perPage = ref(15)
const search = ref('')
const statusFilter = ref<string | null>(null)
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const statusColors: Record<string, string> = {
  draft: 'secondary', sending: 'info', sent: 'success', failed: 'danger',
}
const statusFilterOptions = computed(() => [
  { label: pt('Draft'), value: 'draft' },
  { label: pt('Sending'), value: 'sending' },
  { label: pt('Sent'), value: 'sent' },
  { label: pt('Failed'), value: 'failed' },
])
const audienceLabels: Record<string, string> = {
  all: 'All customers', category: 'Customer category', manual: 'Manual list',
}

const audienceOptions = computed(() => [
  { label: pt('All customers (with email)'), value: 'all' },
  { label: pt('A customer category'), value: 'category' },
  { label: pt('Manual address list'), value: 'manual' },
])

const categories = ref<any[]>([])

async function loadCategories() {
  try {
    const { data } = await http.get('/customer-categories', { params: { per_page: 100 } })
    categories.value = (data.data ?? data).map((c: any) => ({ label: c.name, value: c.id }))
  } catch {
    /* non-blocking */
  }
}

async function reload() {
  loading.value = true
  try {
    const { data } = await http.get('/marketing-campaigns', {
      params: {
        per_page: perPage.value, page: currentPage.value, search: search.value || undefined, status: statusFilter.value || undefined,
        ...(sortField.value && sortOrder.value ? { sort: sortField.value, direction: sortOrder.value === 1 ? 'asc' : 'desc' } : {}),
      },
    })
    items.value = data.data ?? []
    total.value = data.total ?? items.value.length
    currentPage.value = data.current_page ?? currentPage.value
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Failed to load campaigns'), life: 3500 })
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  currentPage.value = e.page + 1
  perPage.value = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  currentPage.value = 1
  reload()
}

// --- Compose dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const form = ref<any>({ name: '', subject: '', body: '', audience: 'all', customer_category_id: null, manual_text: '' })
const errors = ref<Record<string, string[]>>({})
const previewCount = ref<number | null>(null)
const previewing = ref(false)

function parseManual(text: string): { email: string; name: string | null }[] {
  return text
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .map((line) => {
      const [email, ...rest] = line.split(',')
      return { email: email.trim(), name: rest.join(',').trim() || null }
    })
}

function buildPayload() {
  const payload: any = {
    name: form.value.name,
    subject: form.value.subject,
    body: form.value.body,
    audience: form.value.audience,
    customer_category_id: form.value.audience === 'category' ? form.value.customer_category_id : null,
  }
  if (form.value.audience === 'manual') payload.manual_recipients = parseManual(form.value.manual_text)
  return payload
}

function openCreate() {
  editingId.value = null
  form.value = { name: '', subject: '', body: '', audience: 'all', customer_category_id: null, manual_text: '' }
  errors.value = {}
  previewCount.value = null
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  previewCount.value = null
  try {
    const { data } = await http.get(`/marketing-campaigns/${row.id}`)
    const c = data.data
    editingId.value = c.id
    form.value = {
      name: c.name, subject: c.subject, body: c.body, audience: c.audience,
      customer_category_id: c.customer_category_id,
      manual_text: (c.manual_recipients ?? []).map((r: any) => (r.name ? `${r.email},${r.name}` : r.email)).join('\n'),
    }
    dialogVisible.value = true
  } catch {
    toast.add({ severity: 'error', summary: pt('Could not load campaign'), life: 3000 })
  }
}

async function preview() {
  previewing.value = true
  try {
    const p: any = { audience: form.value.audience }
    if (form.value.audience === 'category') p.customer_category_id = form.value.customer_category_id
    if (form.value.audience === 'manual') p.manual_recipients = parseManual(form.value.manual_text)
    const { data } = await http.post('/marketing-campaigns/preview', p)
    previewCount.value = data.data.count
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Preview failed'), detail: e.response?.data?.message ?? '', life: 3500 })
  } finally {
    previewing.value = false
  }
}

// Recompute the preview lazily whenever the audience changes.
watch(() => form.value.audience, () => { previewCount.value = null })

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editingId.value) {
      await http.put(`/marketing-campaigns/${editingId.value}`, buildPayload())
      toast.add({ severity: 'success', summary: pt('Campaign updated'), life: 2500 })
    } else {
      await http.post('/marketing-campaigns', buildPayload())
      toast.add({ severity: 'success', summary: pt('Campaign created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Save failed'), detail: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function sendCampaign(row: any) {
  const res = await Swal.fire({
    title: pt('Send "{name}"?', { name: row.name }),
    text: pt('This will email every recipient in the selected audience.'),
    icon: 'question', showCancelButton: true, confirmButtonText: pt('Send now'), cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/marketing-campaigns/${row.id}/send`)
    toast.add({ severity: 'success', summary: pt('Campaign sending'), detail: data.message, life: 4000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Cannot send'), detail: e.response?.data?.message ?? pt('Failed'), life: 5000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: pt('Delete "{name}"?', { name: row.name }), icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: pt('Delete'), cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/marketing-campaigns/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Delete failed'), detail: e.response?.data?.message ?? pt('Failed'), life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

onMounted(() => {
  loadCategories()
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Marketing Campaigns')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Campaign')" icon="pi pi-megaphone" @click="openCreate" />
      </template>
    </PageHeader>

    <p class="hint">
      <i class="pi pi-info-circle" />
      {{ pt('Compose an email blast and send it to all customers, a customer category, or a manual address list. Delivery is queued; per-recipient status appears in the Email Log.') }}
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search name / subject...')" @keyup.enter="reload" />
      </span>
      <Select v-model="statusFilter" :options="statusFilterOptions" optionLabel="label" optionValue="value" :placeholder="pt('All status')" showClear class="filter" @change="reload" />
      <Button :label="pt('Search')" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons :title="pt('Marketing Campaigns')" endpoint="/marketing-campaigns"
        :params="() => ({ search: search || undefined, status: statusFilter || undefined })"
        :columns="[
          { key: 'name', label: pt('Name') },
          { key: 'subject', label: pt('Subject') },
          { key: 'audience', label: pt('Audience') },
          { key: 'sent_count', label: pt('Sent') },
          { key: 'recipient_count', label: pt('Recipients') },
          { key: 'status', label: pt('Status') },
        ]" />
    </div>

    <DataTable
      :value="items" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
    >
      <Column field="name" :header="pt('Name')" sortable />
      <Column field="subject" :header="pt('Subject')" sortable />
      <Column :header="pt('Audience')" style="width: 12rem">
        <template #body="{ data }">
          {{ pt(audienceLabels[data.audience] ?? data.audience) }}
          <span v-if="data.customer_category" class="cat">· {{ data.customer_category }}</span>
        </template>
      </Column>
      <Column field="recipient_count" :header="pt('Recipients')" style="width: 9rem" sortable>
        <template #body="{ data }">
          <span v-if="data.status === 'draft'">—</span>
          <span v-else>{{ data.sent_count }} / {{ data.recipient_count }}<span v-if="data.failed_count" class="fail"> ({{ pt('{n} failed', { n: data.failed_count }) }})</span></span>
        </template>
      </Column>
      <Column field="status" :header="pt('Status')" style="width: 8rem" sortable>
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="pt(data.status)" /></template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" :title="pt('View')" @click="openDetail(data)" />
          <Button v-if="canEdit && ['draft', 'failed'].includes(data.status)" icon="pi pi-pencil" text rounded size="small" :title="pt('Edit')" @click="openEdit(data)" />
          <Button v-if="canCreate && data.status !== 'sending'" icon="pi pi-send" text rounded size="small" severity="success" :title="pt('Send')" @click="sendCampaign(data)" />
          <Button v-if="canDelete && data.status !== 'sending'" icon="pi pi-trash" text rounded size="small" severity="danger" :title="pt('Delete')" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No campaigns yet.') }}</template>
    </DataTable>

    <!-- Compose dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? pt('Edit Campaign') : pt('New Campaign')" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>{{ pt('Campaign Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="err">{{ fieldError('name') }}</small>
        </div>
        <div class="fld full">
          <label>{{ pt('Subject') }} <span class="req">*</span></label>
          <InputText v-model="form.subject" :class="{ invalid: fieldError('subject') }" />
          <small v-if="fieldError('subject')" class="err">{{ fieldError('subject') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Audience') }}</label>
          <Select v-model="form.audience" :options="audienceOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld" v-if="form.audience === 'category'">
          <label>{{ pt('Customer Category') }} <span class="req">*</span></label>
          <Select v-model="form.customer_category_id" :options="categories" optionLabel="label" optionValue="value" filter :placeholder="pt('Select')" :class="{ invalid: fieldError('customer_category_id') }" />
          <small v-if="fieldError('customer_category_id')" class="err">{{ fieldError('customer_category_id') }}</small>
        </div>
        <div class="fld full" v-if="form.audience === 'manual'">
          <label>{{ pt('Recipients') }} <small class="muted">{{ pt('(one per line: email or email,Name)') }}</small> <span class="req">*</span></label>
          <Textarea v-model="form.manual_text" rows="4" placeholder="jane@acme.com,Jane&#10;info@beta.com" :class="{ invalid: fieldError('manual_recipients') }" />
          <small v-if="fieldError('manual_recipients')" class="err">{{ fieldError('manual_recipients') }}</small>
        </div>
        <div class="fld full">
          <label>{{ pt('Email Body') }} <small class="muted">{{ pt('(HTML allowed)') }}</small> <span class="req">*</span></label>
          <Textarea v-model="form.body" rows="7" :class="{ invalid: fieldError('body') }" />
          <small v-if="fieldError('body')" class="err">{{ fieldError('body') }}</small>
        </div>
        <div class="preview-row">
          <Button :label="pt('Preview recipients')" icon="pi pi-users" text size="small" :loading="previewing" @click="preview" />
          <span v-if="previewCount !== null" class="preview-count">{{ pt('{n} recipient(s)', { n: previewCount }) }}</span>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editingId ? pt('Save') : pt('Create')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" :title="pt('Campaign Details')" :fields="[
      { key: 'name', label: pt('Name') },
      { key: 'subject', label: pt('Subject') },
      { key: 'audience', label: pt('Audience'), format:(v)=>pt(audienceLabels[v] ?? v) },
      { key: 'customer_category', label: pt('Category') },
      { key: 'recipient_count', label: pt('Recipients') },
      { key: 'sent_count', label: pt('Sent') },
      { key: 'failed_count', label: pt('Failed') },
      { key: 'status', label: pt('Status') },
      { key: 'body', label: pt('Email Body'), full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1rem; }
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar-spacer { flex: 1 1 auto; }
.filter { min-width: 150px; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 280px; }
.cat { color: #6b7280; font-size: 0.8rem; }
.fail { color: #dc2626; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(.p-select), .fld :deep(textarea) { width: 100%; }
.muted { color: #9ca3af; font-weight: 400; }
.req { color: #dc2626; }
.err { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
.preview-row { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.75rem; }
.preview-count { font-size: 0.85rem; color: #2563eb; font-weight: 500; }
</style>
