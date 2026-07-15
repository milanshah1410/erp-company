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

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('marketing-campaigns.create'))
const canEdit = computed(() => auth.can('marketing-campaigns.edit'))
const canDelete = computed(() => auth.can('marketing-campaigns.delete'))

const items = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const perPage = ref(15)
const search = ref('')

const statusColors: Record<string, string> = {
  draft: 'secondary', sending: 'info', sent: 'success', failed: 'danger',
}
const audienceLabels: Record<string, string> = {
  all: 'All customers', category: 'Customer category', manual: 'Manual list',
}

const audienceOptions = [
  { label: 'All customers (with email)', value: 'all' },
  { label: 'A customer category', value: 'category' },
  { label: 'Manual address list', value: 'manual' },
]

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
      params: { per_page: perPage.value, page: currentPage.value, search: search.value || undefined },
    })
    items.value = data.data ?? []
    total.value = data.total ?? items.value.length
    currentPage.value = data.current_page ?? currentPage.value
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Failed to load campaigns', life: 3500 })
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  currentPage.value = e.page + 1
  perPage.value = e.rows
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
    toast.add({ severity: 'error', summary: 'Could not load campaign', life: 3000 })
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
    toast.add({ severity: 'error', summary: 'Preview failed', detail: e.response?.data?.message ?? '', life: 3500 })
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
      toast.add({ severity: 'success', summary: 'Campaign updated', life: 2500 })
    } else {
      await http.post('/marketing-campaigns', buildPayload())
      toast.add({ severity: 'success', summary: 'Campaign created', life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message ?? 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function sendCampaign(row: any) {
  const res = await Swal.fire({
    title: `Send "${row.name}"?`,
    text: 'This will email every recipient in the selected audience.',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Send now',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/marketing-campaigns/${row.id}/send`)
    toast.add({ severity: 'success', summary: 'Campaign sending', detail: data.message, life: 4000 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot send', detail: e.response?.data?.message ?? 'Failed', life: 5000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.name}"?`, icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/marketing-campaigns/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Delete failed', detail: e.response?.data?.message ?? 'Failed', life: 4000 })
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
    <div class="header">
      <h1>Marketing Campaigns</h1>
      <Button v-if="canCreate" label="New Campaign" icon="pi pi-megaphone" @click="openCreate" />
    </div>

    <p class="hint">
      <i class="pi pi-info-circle" />
      Compose an email blast and send it to all customers, a customer category, or a manual address list.
      Delivery is queued; per-recipient status appears in the Email Log.
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search name / subject..." @keyup.enter="reload" />
      </span>
      <Button label="Search" outlined @click="reload" />
    </div>

    <DataTable
      :value="items" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm"
    >
      <Column field="name" header="Name" />
      <Column field="subject" header="Subject" />
      <Column header="Audience" style="width: 12rem">
        <template #body="{ data }">
          {{ audienceLabels[data.audience] ?? data.audience }}
          <span v-if="data.customer_category" class="cat">· {{ data.customer_category }}</span>
        </template>
      </Column>
      <Column header="Recipients" style="width: 9rem">
        <template #body="{ data }">
          <span v-if="data.status === 'draft'">—</span>
          <span v-else>{{ data.sent_count }} / {{ data.recipient_count }}<span v-if="data.failed_count" class="fail"> ({{ data.failed_count }} failed)</span></span>
        </template>
      </Column>
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button v-if="canEdit && ['draft', 'failed'].includes(data.status)" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canCreate && data.status !== 'sending'" icon="pi pi-send" text rounded size="small" severity="success" title="Send" @click="sendCampaign(data)" />
          <Button v-if="canDelete && data.status !== 'sending'" icon="pi pi-trash" text rounded size="small" severity="danger" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No campaigns yet.</template>
    </DataTable>

    <!-- Compose dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Campaign' : 'New Campaign'" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>Campaign Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="err">{{ fieldError('name') }}</small>
        </div>
        <div class="fld full">
          <label>Subject <span class="req">*</span></label>
          <InputText v-model="form.subject" :class="{ invalid: fieldError('subject') }" />
          <small v-if="fieldError('subject')" class="err">{{ fieldError('subject') }}</small>
        </div>
        <div class="fld">
          <label>Audience</label>
          <Select v-model="form.audience" :options="audienceOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld" v-if="form.audience === 'category'">
          <label>Customer Category <span class="req">*</span></label>
          <Select v-model="form.customer_category_id" :options="categories" optionLabel="label" optionValue="value" filter placeholder="Select" :class="{ invalid: fieldError('customer_category_id') }" />
          <small v-if="fieldError('customer_category_id')" class="err">{{ fieldError('customer_category_id') }}</small>
        </div>
        <div class="fld full" v-if="form.audience === 'manual'">
          <label>Recipients <small class="muted">(one per line: email or email,Name)</small> <span class="req">*</span></label>
          <Textarea v-model="form.manual_text" rows="4" placeholder="jane@acme.com,Jane&#10;info@beta.com" :class="{ invalid: fieldError('manual_recipients') }" />
          <small v-if="fieldError('manual_recipients')" class="err">{{ fieldError('manual_recipients') }}</small>
        </div>
        <div class="fld full">
          <label>Email Body <small class="muted">(HTML allowed)</small> <span class="req">*</span></label>
          <Textarea v-model="form.body" rows="7" :class="{ invalid: fieldError('body') }" />
          <small v-if="fieldError('body')" class="err">{{ fieldError('body') }}</small>
        </div>
        <div class="preview-row">
          <Button label="Preview recipients" icon="pi pi-users" text size="small" :loading="previewing" @click="preview" />
          <span v-if="previewCount !== null" class="preview-count">{{ previewCount }} recipient(s)</span>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Save' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
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
