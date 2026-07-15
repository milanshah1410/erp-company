<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import http from '../api/http'
import { useCrud } from '../composables/useCrud'

const crud = useCrud('/channel-sync-logs')
const integrations = ref<any[]>([])
const integrationFilter = ref<number | null>(null)
const typeFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)

const typeOptions = [
  { label: 'Connection', value: 'connection' },
  { label: 'Orders', value: 'orders' },
  { label: 'Inventory', value: 'inventory' },
  { label: 'Listings', value: 'listings' },
]
const statusOptions = [
  { label: 'Success', value: 'success' },
  { label: 'Partial', value: 'partial' },
  { label: 'Failed', value: 'failed' },
]
const statusColors: Record<string, string> = { success: 'success', partial: 'warn', failed: 'danger' }

async function loadIntegrations() {
  const { data } = await http.get('/ecommerce-integrations')
  integrations.value = data.data.map((i: any) => ({ id: i.id, name: `${i.name} (${i.platform_label})` }))
}
function reload() {
  crud.fetch({
    ...(integrationFilter.value ? { channel_integration_id: integrationFilter.value } : {}),
    ...(typeFilter.value ? { type: typeFilter.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
  })
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}
onMounted(async () => {
  await loadIntegrations()
  reload()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Sync History</h1>
      <div class="header-actions">
        <Select v-model="integrationFilter" :options="integrations" optionLabel="name" optionValue="id" showClear placeholder="All channels" class="filter" @change="reload" />
        <Select v-model="typeFilter" :options="typeOptions" optionLabel="label" optionValue="value" showClear placeholder="All types" class="filter-sm" @change="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear placeholder="All statuses" class="filter-sm" @change="reload" />
        <Button icon="pi pi-refresh" text rounded @click="reload" title="Refresh" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="When"><template #body="{ data }">{{ new Date(data.created_at).toLocaleString() }}</template></Column>
      <Column header="Channel"><template #body="{ data }">{{ data.integration?.name }}<small class="muted cap"> · {{ data.integration?.platform }}</small></template></Column>
      <Column field="type" header="Type"><template #body="{ data }"><span class="cap">{{ data.type }}</span></template></Column>
      <Column field="direction" header="Direction"><template #body="{ data }"><span class="cap">{{ data.direction }}</span></template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template></Column>
      <Column header="Counts">
        <template #body="{ data }">
          <span class="counts">{{ data.processed }} processed<template v-if="data.succeeded"> · {{ data.succeeded }} ok</template><template v-if="data.failed"> · <span class="fail">{{ data.failed }} failed</span></template></span>
        </template>
      </Column>
      <Column field="message" header="Message"><template #body="{ data }">{{ data.message || '—' }}</template></Column>
      <template #empty>No sync runs yet.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.5rem; align-items: center; }
.filter { width: 190px; }
.filter-sm { width: 140px; }
.muted { color: #6b7280; }
.cap { text-transform: capitalize; }
.counts { font-size: 0.85rem; }
.fail { color: #dc2626; }
</style>
