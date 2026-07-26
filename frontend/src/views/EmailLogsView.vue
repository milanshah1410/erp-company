<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const perPage = ref(15)
const search = ref('')
const mailableFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

const statusColors: Record<string, string> = {
  queued: 'secondary', sent: 'success', failed: 'danger',
}
const mailableLabels: Record<string, string> = {
  invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation',
  campaign: 'Campaign', test: 'Test',
}
const mailableOptions = [
  { label: 'All types', value: null },
  { label: 'Invoice', value: 'invoice' },
  { label: 'Purchase Order', value: 'purchase_order' },
  { label: 'Quotation', value: 'quotation' },
  { label: 'Campaign', value: 'campaign' },
]
const statusOptions = [
  { label: 'All statuses', value: null },
  { label: 'Sent', value: 'sent' },
  { label: 'Queued', value: 'queued' },
  { label: 'Failed', value: 'failed' },
]

async function reload() {
  loading.value = true
  try {
    const { data } = await http.get('/email/logs', {
      params: {
        per_page: perPage.value, page: currentPage.value,
        search: search.value || undefined,
        mailable: mailableFilter.value || undefined,
        status: statusFilter.value || undefined,
        ...(sortField.value && sortOrder.value ? { sort: sortField.value, direction: sortOrder.value === 1 ? 'asc' : 'desc' } : {}),
      },
    })
    items.value = data.data ?? []
    total.value = data.total ?? items.value.length
    currentPage.value = data.current_page ?? currentPage.value
  } catch {
    toast.add({ severity: 'error', summary: 'Failed to load email log', life: 3500 })
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

onMounted(reload)
</script>

<template>
  <div>
    <PageHeader title="Email Log" />

    <p class="hint">
      <i class="pi pi-info-circle" />
      Every email the system sends — document PDFs and marketing campaigns — is recorded here with its delivery status.
    </p>

    <div class="toolbar">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search recipient / subject..." @keyup.enter="reload" />
      </span>
      <Select v-model="mailableFilter" :options="mailableOptions" optionLabel="label" optionValue="value" @change="reload" />
      <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" @change="reload" />
      <Button label="Search" outlined @click="reload" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="Email Log" filenameBase="email-log" endpoint="/email/logs"
        :params="() => ({
          ...(search ? { search } : {}),
          ...(mailableFilter ? { mailable: mailableFilter } : {}),
          ...(statusFilter ? { status: statusFilter } : {}),
        })"
        :columns="[
          { key: 'created_at', label: 'Date' },
          { key: 'mailable', label: 'Type' },
          { key: 'to_name', label: 'To Name' },
          { key: 'to_email', label: 'To Email' },
          { key: 'subject', label: 'Subject' },
          { key: 'status', label: 'Status' },
          { key: 'by', label: 'By' },
        ]" />
    </div>

    <DataTable
      :value="items" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
    >
      <Column header="Date" style="width: 12rem">
        <template #body="{ data }">{{ new Date(data.created_at).toLocaleString() }}</template>
      </Column>
      <Column field="mailable" header="Type" style="width: 9rem" sortable>
        <template #body="{ data }"><Tag severity="info" :value="mailableLabels[data.mailable] ?? data.mailable" /></template>
      </Column>
      <Column field="to_email" header="To" sortable>
        <template #body="{ data }">
          <div>{{ data.to_name || data.to_email }}</div>
          <div v-if="data.to_name" class="sub">{{ data.to_email }}</div>
        </template>
      </Column>
      <Column field="subject" header="Subject" sortable />
      <Column field="status" header="Status" style="width: 8rem" sortable>
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="data.status" />
          <i v-if="data.error" class="pi pi-exclamation-circle err-icon" :title="data.error" />
        </template>
      </Column>
      <Column field="by" header="By" style="width: 9rem" />
      <template #empty>No emails sent yet.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.hint { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.82rem; margin-bottom: 1rem; }
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar-spacer { flex: 1 1 auto; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 260px; }
.sub { font-size: 0.78rem; color: #9ca3af; }
.err-icon { color: #dc2626; margin-left: 0.4rem; cursor: help; }
</style>
