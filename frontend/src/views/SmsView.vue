<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('sms.create'))
const canDelete = computed(() => auth.can('sms.delete'))

const crud = ref(useCrud('/sms/messages'))
const search = ref('')
const statusFilter = ref<string | null>(null)
const templateFilter = ref<string | null>(null)

const templateColors: Record<string, string> = {
  manual: 'secondary', otp: 'contrast', invoice: 'info', dispatch: 'help',
  'payment-reminder': 'warn', festival: 'success',
}
const statusColors: Record<string, string> = {
  queued: 'secondary', sent: 'success', delivered: 'success', failed: 'danger',
}

const templateFilterOptions = [
  { label: 'OTP', value: 'otp' },
  { label: 'Invoice', value: 'invoice' },
  { label: 'Dispatch', value: 'dispatch' },
  { label: 'Payment Reminder', value: 'payment-reminder' },
  { label: 'Festival', value: 'festival' },
  { label: 'Manual', value: 'manual' },
]
const statusFilterOptions = [
  { label: 'Sent', value: 'sent' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Failed', value: 'failed' },
]

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  if (templateFilter.value) params.template = templateFilter.value
  crud.value.fetch(params)
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

// --- Customer options (shared by compose + festival) ---
const customerOptions = ref<any[]>([])
async function loadCustomers() {
  try {
    const { data } = await http.get('/customers', { params: { per_page: 200 } })
    customerOptions.value = (data.data ?? []).map((c: any) => ({
      label: `${c.name} (${c.code})`, value: c.id, phone: c.phone,
    }))
  } catch {
    /* non-blocking */
  }
}

// --- Compose dialog (manual / invoice / payment reminder) ---
const dialogVisible = ref(false)
const sending = ref(false)
const mode = ref<'manual' | 'payment-reminder' | 'invoice'>('manual')
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})
const invoiceOptions = ref<any[]>([])

const modeOptions = [
  { label: 'Manual message', value: 'manual' },
  { label: 'Payment reminder', value: 'payment-reminder' },
  { label: 'Invoice notification', value: 'invoice' },
]

function openCompose() {
  mode.value = 'manual'
  form.value = { customer_id: null, phone: '', body: '', invoice_id: null }
  invoiceOptions.value = []
  errors.value = {}
  dialogVisible.value = true
}

function onCustomerPick(id: number) {
  const c = customerOptions.value.find((o) => o.value === id)
  if (c?.phone) form.value.phone = c.phone
  if (mode.value === 'invoice' && id) loadInvoices(id)
}

async function loadInvoices(customerId: number) {
  try {
    const { data } = await http.get('/invoices', { params: { customer_id: customerId, per_page: 50 } })
    invoiceOptions.value = (data.data ?? []).map((i: any) => ({
      label: `${i.invoice_number} · ${i.status}`, value: i.id,
    }))
  } catch {
    invoiceOptions.value = []
  }
}

function onModeChange() {
  errors.value = {}
  if (mode.value === 'invoice' && form.value.customer_id) loadInvoices(form.value.customer_id)
}

async function submitCompose() {
  sending.value = true
  errors.value = {}
  try {
    if (mode.value === 'manual') {
      await http.post('/sms/messages', {
        customer_id: form.value.customer_id, phone: form.value.phone || null,
        template: 'manual', body: form.value.body,
      })
    } else if (mode.value === 'payment-reminder') {
      if (!form.value.customer_id) throw { response: { data: { message: 'Select a customer.' } } }
      await http.post('/sms/payment-reminder', { customer_id: form.value.customer_id })
    } else if (mode.value === 'invoice') {
      if (!form.value.invoice_id) throw { response: { data: { message: 'Select an invoice.' } } }
      await http.post(`/sms/invoices/${form.value.invoice_id}`, {})
    }
    toast.add({ severity: 'success', summary: 'SMS sent', life: 2500 })
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to send', life: 4000 })
  } finally {
    sending.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Festival greetings dialog ---
const festivalVisible = ref(false)
const festivalSending = ref(false)
const festival = ref<any>({ festival: '', customer_ids: [], message: '' })

function openFestival() {
  festival.value = { festival: '', customer_ids: [], message: '' }
  festivalVisible.value = true
}

async function sendFestival() {
  if (!festival.value.festival) {
    toast.add({ severity: 'warn', summary: 'Enter a festival name', life: 2500 })
    return
  }
  const count = festival.value.customer_ids.length
  const res = await Swal.fire({
    title: `Send greetings?`,
    text: count > 0 ? `To ${count} selected customer(s).` : 'To ALL active customers with a phone number.',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Send',
  })
  if (!res.isConfirmed) return

  festivalSending.value = true
  try {
    const { data } = await http.post('/sms/festival', {
      festival: festival.value.festival,
      customer_ids: festival.value.customer_ids.length ? festival.value.customer_ids : null,
      message: festival.value.message || null,
    })
    toast.add({ severity: 'success', summary: data.message || 'Greetings sent', life: 3500 })
    festivalVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    festivalSending.value = false
  }
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
      <h1>SMS</h1>
      <div class="header-actions">
        <Button v-if="canCreate" label="Festival Greetings" icon="pi pi-gift" severity="success" outlined @click="openFestival" />
        <Button v-if="canCreate" label="New SMS" icon="pi pi-send" @click="openCompose" />
      </div>
    </div>

    <p class="hint">
      <i class="pi pi-info-circle" />
      Sends go through the configured gateway. With the default <b>log</b> driver messages are recorded here without a
      gateway account; switch to MSG91 under <b>Settings → SMS Gateway</b> to send for real.
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search phone / text..." @keyup.enter="reload" />
      </span>
      <Select v-model="templateFilter" :options="templateFilterOptions" optionLabel="label" optionValue="value" placeholder="All types" showClear class="filter" @change="reload" />
      <Select v-model="statusFilter" :options="statusFilterOptions" optionLabel="label" optionValue="value" placeholder="All status" showClear class="filter" @change="reload" />
      <Button label="Search" outlined @click="reload" />
    </div>

    <DataTable
      :value="crud.items" :loading="crud.loading" lazy paginator
      :rows="crud.perPage" :totalRecords="crud.total" :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id" @page="onPage" class="p-datatable-sm"
    >
      <Column header="Date" style="width: 11rem">
        <template #body="{ data }">{{ new Date(data.created_at).toLocaleString() }}</template>
      </Column>
      <Column header="Customer">
        <template #body="{ data }">{{ data.customer?.name ?? '—' }}</template>
      </Column>
      <Column field="phone" header="Phone" style="width: 10rem" />
      <Column header="Type" style="width: 9rem">
        <template #body="{ data }">
          <Tag :severity="templateColors[data.template] ?? 'secondary'" :value="data.template" />
        </template>
      </Column>
      <Column field="body" header="Message" />
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status] ?? 'secondary'" :value="data.status" />
          <i v-if="data.error" class="pi pi-exclamation-circle err-icon" :title="data.error" />
        </template>
      </Column>
      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No messages yet.</template>
    </DataTable>

    <!-- Compose dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="Send SMS" :style="{ width: '540px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>Type</label>
          <Select v-model="mode" :options="modeOptions" optionLabel="label" optionValue="value" @update:modelValue="onModeChange" />
        </div>

        <div class="fld" :class="{ full: mode !== 'manual' }">
          <label>Customer <span v-if="mode !== 'manual'" class="req">*</span></label>
          <Select
            v-model="form.customer_id" :options="customerOptions"
            optionLabel="label" optionValue="value" filter showClear
            :placeholder="mode === 'manual' ? 'Optional' : 'Select customer'"
            @update:modelValue="onCustomerPick"
          />
        </div>

        <div v-if="mode === 'manual'" class="fld">
          <label>Phone <span class="req">*</span></label>
          <InputText v-model="form.phone" placeholder="e.g. 9812345678" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>

        <div v-if="mode === 'invoice'" class="fld full">
          <label>Invoice <span class="req">*</span></label>
          <Select v-model="form.invoice_id" :options="invoiceOptions" optionLabel="label" optionValue="value" filter placeholder="Select an invoice" />
          <small class="muted">The message body is generated from the invoice template in Settings.</small>
        </div>

        <div v-if="mode === 'manual'" class="fld full">
          <label>Message <span class="req">*</span></label>
          <Textarea v-model="form.body" rows="4" :class="{ invalid: fieldError('body') }" />
          <small v-if="fieldError('body')" class="error-text">{{ fieldError('body') }}</small>
        </div>

        <div v-if="mode === 'payment-reminder'" class="fld full">
          <small class="muted">A reminder with the customer's current outstanding balance is generated from the template in Settings.</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Send SMS" icon="pi pi-send" :loading="sending" @click="submitCompose" />
      </template>
    </Dialog>

    <!-- Festival greetings dialog -->
    <Dialog v-model:visible="festivalVisible" modal header="Festival Greetings" :style="{ width: '560px' }">
      <div class="form-grid">
        <div class="fld full">
          <label>Festival / Occasion <span class="req">*</span></label>
          <InputText v-model="festival.festival" placeholder="e.g. Diwali, Uttarayan, New Year" />
        </div>
        <div class="fld full">
          <label>Recipients</label>
          <MultiSelect
            v-model="festival.customer_ids" :options="customerOptions" optionLabel="label" optionValue="value"
            filter display="chip" placeholder="Leave empty to send to all active customers"
          />
          <small class="muted">Only customers with a phone number receive the message.</small>
        </div>
        <div class="fld full">
          <label>Custom message (optional)</label>
          <Textarea v-model="festival.message" rows="3" placeholder="Leave empty to use the template. Placeholders: {name} {festival} {business}" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="festivalVisible = false" />
        <Button label="Send Greetings" icon="pi pi-gift" severity="success" :loading="festivalSending" @click="sendFestival" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.5rem; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 260px; }
.filter { min-width: 140px; }
.err-icon { color: #dc2626; margin-left: 0.35rem; cursor: help; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(.p-multiselect),
.fld :deep(textarea) { width: 100%; }
.req { color: #dc2626; }
.muted { color: #6b7280; font-size: 0.78rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
