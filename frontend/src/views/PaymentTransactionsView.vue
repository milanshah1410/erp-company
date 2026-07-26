<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

/**
 * Payment links / online-collection transactions (SRS §16): staff generate a
 * hosted pay link against a sales invoice, share it, and track its status here.
 * A paid link is reconciled server-side into a payment voucher against the
 * invoice — the "Voucher" column links the two.
 */
const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('payments.create'))
const canEdit = computed(() => auth.can('payments.edit'))

const crud = ref(useCrud('/payment-transactions'))
const search = ref('')
const statusFilter = ref<string | null>(null)

const statusOptions = [
  { label: 'All', value: null },
  { label: 'Created', value: 'created' },
  { label: 'Pending', value: 'pending' },
  { label: 'Paid', value: 'paid' },
  { label: 'Failed', value: 'failed' },
  { label: 'Expired', value: 'expired' },
  { label: 'Cancelled', value: 'cancelled' },
]

const statusColor: Record<string, string> = {
  created: 'secondary', pending: 'info', paid: 'success',
  failed: 'danger', expired: 'warn', cancelled: 'contrast',
}

function money(amount: any, currency: string): string {
  const n = Number(amount ?? 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'INR' }).format(n)
  } catch {
    return `${currency} ${n.toFixed(2)}`
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  crud.value.fetch(params)
}

function onPage(e: any) {
  crud.value.currentPage = e.page + 1
  crud.value.perPage = e.rows
  reload()
}

async function copyLink(row: any) {
  try {
    await navigator.clipboard.writeText(row.pay_url)
    toast.add({ severity: 'success', summary: 'Link copied', life: 2000 })
  } catch {
    toast.add({ severity: 'warn', summary: row.pay_url, life: 6000 })
  }
}

function openLink(row: any) {
  window.open(row.pay_url, '_blank')
}

async function cancel(row: any) {
  const res = await Swal.fire({
    title: 'Cancel this payment link?', icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Cancel link',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/payment-transactions/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Cancelled', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

// --- New link dialog ---
const dialogVisible = ref(false)
const creating = ref(false)
const invoices = ref<any[]>([])
const created = ref<any>(null)
const form = ref<any>({ invoice_id: null, amount: null })
const errors = ref<Record<string, string[]>>({})

const selectedInvoice = computed(() => invoices.value.find((i) => i.id === form.value.invoice_id) || null)

async function openNew() {
  form.value = { invoice_id: null, amount: null }
  created.value = null
  errors.value = {}
  dialogVisible.value = true
  try {
    const { data } = await http.get('/invoices', { params: { per_page: 100 } })
    invoices.value = (data.data ?? [])
      .filter((i: any) => i.status !== 'paid' && Number(i.balance_due) > 0)
      .map((i: any) => ({
        id: i.id,
        label: `${i.invoice_number} — ${i.customer?.name ?? 'Customer'} (${money(i.balance_due, 'INR')} due)`,
        balance_due: Number(i.balance_due),
      }))
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load invoices', life: 4000 })
  }
}

function onInvoicePick() {
  form.value.amount = selectedInvoice.value?.balance_due ?? null
}

async function createLink() {
  creating.value = true
  errors.value = {}
  try {
    const { data } = await http.post(`/invoices/${form.value.invoice_id}/payment-link`, {
      amount: form.value.amount || undefined,
    })
    created.value = data.data
    toast.add({ severity: 'success', summary: 'Payment link created', life: 2500 })
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    creating.value = false
  }
}

async function copyCreated() {
  if (!created.value) return
  try {
    await navigator.clipboard.writeText(created.value.pay_url)
    toast.add({ severity: 'success', summary: 'Link copied', life: 2000 })
  } catch { /* ignore */ }
}

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Payment Links">
      <template #actions>
        <Button v-if="canCreate" label="New Payment Link" icon="pi pi-link" @click="openNew" />
      </template>
    </PageHeader>

    <p class="hint">
      <i class="pi pi-info-circle" />
      Generate a secure link for an invoice and share it by WhatsApp, SMS or email. When the customer pays, the
      receipt is booked against the invoice automatically.
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search invoice / payment id..." @keyup.enter="reload" />
      </span>
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" @change="reload" style="width: 11rem" />
      <Button label="Search" outlined @click="reload" />
      <TableExportButtons title="Payment Links" endpoint="/payment-transactions"
        :params="() => ({ search: search || undefined, status: statusFilter || undefined })"
        :columns="[
          { key: 'created_at', label: 'Date' },
          { key: 'invoice.invoice_number', label: 'Invoice' },
          { key: 'customer.name', label: 'Customer' },
          { key: 'amount', label: 'Amount' },
          { key: 'gateway_label', label: 'Gateway' },
          { key: 'status', label: 'Status' },
          { key: 'voucher_number', label: 'Voucher' },
        ]" />
    </div>

    <DataTable
      :value="crud.items" :loading="crud.loading" lazy paginator
      :rows="crud.perPage" :totalRecords="crud.total" :rowsPerPageOptions="[15, 30, 50, 100]"
      dataKey="id" @page="onPage" class="p-datatable-sm"
    >
      <Column header="Date" style="width: 10rem">
        <template #body="{ data }">{{ new Date(data.created_at).toLocaleDateString() }}</template>
      </Column>
      <Column header="Invoice">
        <template #body="{ data }">{{ data.invoice?.invoice_number ?? '—' }}</template>
      </Column>
      <Column header="Customer">
        <template #body="{ data }">{{ data.customer?.name ?? '—' }}</template>
      </Column>
      <Column header="Amount" style="width: 9rem">
        <template #body="{ data }">{{ money(data.amount, data.currency) }}</template>
      </Column>
      <Column header="Gateway" style="width: 8rem">
        <template #body="{ data }">{{ data.gateway_label ?? '—' }}</template>
      </Column>
      <Column header="Status" style="width: 8rem">
        <template #body="{ data }">
          <Tag :severity="statusColor[data.status] ?? 'secondary'" :value="data.status" />
        </template>
      </Column>
      <Column header="Voucher" style="width: 8rem">
        <template #body="{ data }">{{ data.voucher_number ?? '—' }}</template>
      </Column>
      <Column header="Actions" style="width: 10rem">
        <template #body="{ data }">
          <Button icon="pi pi-copy" text rounded size="small" title="Copy pay link" @click="copyLink(data)" />
          <Button icon="pi pi-external-link" text rounded size="small" title="Open pay page" @click="openLink(data)" />
          <Button
            v-if="canEdit && !['paid', 'cancelled', 'expired'].includes(data.status)"
            icon="pi pi-times" text rounded severity="danger" size="small" title="Cancel link" @click="cancel(data)"
          />
        </template>
      </Column>
      <template #empty>No payment links yet.</template>
    </DataTable>

    <!-- New link dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="New Payment Link" :style="{ width: '480px' }">
      <div v-if="!created" class="form">
        <div class="fld">
          <label>Invoice <span class="req">*</span></label>
          <Select
            v-model="form.invoice_id" :options="invoices" optionLabel="label" optionValue="id"
            filter showClear placeholder="Select an unpaid invoice"
            :class="{ invalid: errors.invoice_id }" @update:modelValue="onInvoicePick"
          />
        </div>
        <div class="fld">
          <label>Amount</label>
          <InputNumber v-model="form.amount" :min="0" :minFractionDigits="2" :maxFractionDigits="2" :disabled="!form.invoice_id" />
          <small class="muted">Defaults to the full balance due; lower it for a part-payment.</small>
          <small v-if="errors.amount" class="error-text">{{ errors.amount[0] }}</small>
        </div>
      </div>

      <div v-else class="done">
        <i class="pi pi-check-circle done-ico" />
        <p class="done-msg">Payment link ready. Share it with your customer:</p>
        <div class="link-box">
          <InputText :modelValue="created.pay_url" readonly />
          <Button icon="pi pi-copy" @click="copyCreated" />
        </div>
      </div>

      <template #footer>
        <template v-if="!created">
          <Button label="Cancel" text @click="dialogVisible = false" />
          <Button label="Create Link" icon="pi pi-link" :loading="creating" :disabled="!form.invoice_id" @click="createLink" />
        </template>
        <Button v-else label="Done" @click="dialogVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1rem; max-width: 760px; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; align-items: center; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 260px; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(.p-select), .fld :deep(.p-inputnumber) { width: 100%; }
.muted { color: #6b7280; font-size: 0.78rem; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
.done { text-align: center; padding: 0.75rem 0; }
.done-ico { font-size: 2.25rem; color: #059669; }
.done-msg { margin: 0.6rem 0 1rem; color: #374151; font-size: 0.9rem; }
.link-box { display: flex; gap: 0.4rem; }
.link-box :deep(.p-inputtext) { flex: 1; }
</style>
