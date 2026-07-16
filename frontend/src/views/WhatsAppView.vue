<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('whatsapp.create'))
const canDelete = computed(() => auth.can('whatsapp.delete'))

const crud = ref(useCrud('/whatsapp/messages'))
const search = ref('')
const templateFilter = ref<string | null>(null)
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const templateColors: Record<string, string> = {
  manual: 'secondary', invoice: 'info', 'payment-reminder': 'warn',
  birthday: 'success', anniversary: 'success', promo: 'help',
}

const templateFilterOptions = [
  { label: 'Manual', value: 'manual' },
  { label: 'Invoice', value: 'invoice' },
  { label: 'Payment Reminder', value: 'payment-reminder' },
  { label: 'Promotional', value: 'promo' },
]

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (templateFilter.value) params.template = templateFilter.value
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

// --- Compose dialog ---
const customerOptions = ref<any[]>([])
async function loadCustomers() {
  try {
    const { data } = await http.get('/customers', { params: { per_page: 100 } })
    customerOptions.value = (data.data ?? []).map((c: any) => ({ label: `${c.name} (${c.code})`, value: c.id, phone: c.phone }))
  } catch {
    /* non-blocking */
  }
}

const dialogVisible = ref(false)
const sending = ref(false)
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

const templateOptions = [
  { label: 'Manual', value: 'manual' },
  { label: 'Invoice', value: 'invoice' },
  { label: 'Payment Reminder', value: 'payment-reminder' },
  { label: 'Promotional', value: 'promo' },
]

function openCompose() {
  form.value = { customer_id: null, phone: '', template: 'manual', body: '' }
  errors.value = {}
  dialogVisible.value = true
}

function onCustomerPick(id: number) {
  const c = customerOptions.value.find((o) => o.value === id)
  if (c?.phone) form.value.phone = c.phone
}

async function send() {
  sending.value = true
  errors.value = {}
  try {
    const { data } = await http.post('/whatsapp/messages', {
      customer_id: form.value.customer_id,
      phone: form.value.phone || null,
      template: form.value.template,
      body: form.value.body,
    })
    window.open(data.data.link, '_blank')
    toast.add({ severity: 'success', summary: 'WhatsApp opened', life: 2000 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    sending.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function reopen(row: any) {
  if (row.link) window.open(row.link, '_blank')
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: 'Delete this message log?', icon: 'warning', showCancelButton: true,
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
      <h1>WhatsApp</h1>
      <Button v-if="canCreate" label="New Message" icon="pi pi-whatsapp" @click="openCompose" />
    </div>

    <p class="hint">
      <i class="pi pi-info-circle" />
      Click-to-chat: messages open in WhatsApp Web / app on the device with the number pre-filled. Every message is logged here.
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search phone / text..." @keyup.enter="reload" />
      </span>
      <Select v-model="templateFilter" :options="templateFilterOptions" optionLabel="label" optionValue="value" placeholder="All templates" showClear class="filter" @change="reload" />
      <Button label="Search" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="WhatsApp" endpoint="/whatsapp/messages"
        :params="() => ({ search: search || undefined, template: templateFilter || undefined })"
        :columns="[
          { key: 'created_at', label: 'Date' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'phone', label: 'Phone' },
          { key: 'template', label: 'Template' },
          { key: 'body', label: 'Message' },
        ]" />
    </div>

    <DataTable
      :value="crud.items" :loading="crud.loading" lazy paginator
      :rows="crud.perPage" :totalRecords="crud.total" :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id" @page="onPage" class="p-datatable-sm"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
    >
      <Column field="created_at" header="Date" style="width: 11rem" sortable>
        <template #body="{ data }">{{ new Date(data.created_at).toLocaleString() }}</template>
      </Column>
      <Column header="Customer">
        <template #body="{ data }">{{ data.customer?.name ?? '—' }}</template>
      </Column>
      <Column field="phone" header="Phone" sortable />
      <Column field="template" header="Template" style="width: 9rem" sortable>
        <template #body="{ data }">
          <Tag :severity="templateColors[data.template] ?? 'secondary'" :value="data.template" />
        </template>
      </Column>
      <Column field="body" header="Message" />
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-external-link" text rounded size="small" title="Re-open chat" @click="reopen(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No messages yet.</template>
    </DataTable>

    <!-- Compose dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="Send WhatsApp Message" :style="{ width: '520px' }">
      <div class="form-grid">
        <div class="fld">
          <label>Customer</label>
          <Select
            v-model="form.customer_id" :options="customerOptions"
            optionLabel="label" optionValue="value" filter showClear placeholder="Optional"
            @update:modelValue="onCustomerPick"
          />
        </div>
        <div class="fld">
          <label>Phone <span class="req">*</span></label>
          <InputText v-model="form.phone" placeholder="e.g. 9812345678" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>
        <div class="fld full">
          <label>Template</label>
          <Select v-model="form.template" :options="templateOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld full">
          <label>Message <span class="req">*</span></label>
          <Textarea v-model="form.body" rows="4" :class="{ invalid: fieldError('body') }" />
          <small v-if="fieldError('body')" class="error-text">{{ fieldError('body') }}</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Open WhatsApp" icon="pi pi-whatsapp" :loading="sending" @click="send" />
      </template>
    </Dialog>
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
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
