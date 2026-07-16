<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()

const loading = ref(false)
const rows = ref<any[]>([])
const days = ref(30)
const typeFilter = ref<string | null>(null)

const daysOptions = [
  { label: 'Next 7 days', value: 7 },
  { label: 'Next 30 days', value: 30 },
  { label: 'Next 90 days', value: 90 },
]
const typeOptions = [
  { label: 'Birthday', value: 'birthday' },
  { label: 'Anniversary', value: 'anniversary' },
]

async function load() {
  loading.value = true
  try {
    const params: Record<string, any> = { days: days.value }
    if (typeFilter.value) params.type = typeFilter.value
    const { data } = await http.get('/customers/occasions', { params })
    rows.value = data.data ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to load occasions', life: 4000 })
  } finally {
    loading.value = false
  }
}

function whenLabel(daysAway: number): string {
  if (daysAway === 0) return 'Today'
  if (daysAway === 1) return 'Tomorrow'
  return `in ${daysAway} days`
}

function defaultMessage(row: any): string {
  if (row.type === 'birthday') {
    return `Dear ${row.name}, wishing you a very Happy Birthday! 🎂 Thank you for being a valued customer.`
  }
  return `Dear ${row.name}, Happy Anniversary! 🎉 Thank you for your continued support.`
}

async function wish(row: any) {
  if (!row.phone) {
    toast.add({ severity: 'warn', summary: 'No phone number for this customer', life: 3000 })
    return
  }
  try {
    const { data } = await http.post('/whatsapp/messages', {
      customer_id: row.customer_id,
      template: row.type,
      body: defaultMessage(row),
    })
    window.open(data.data.link, '_blank')
    toast.add({ severity: 'success', summary: 'WhatsApp opened', life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function wishSms(row: any) {
  if (!row.phone) {
    toast.add({ severity: 'warn', summary: 'No phone number for this customer', life: 3000 })
    return
  }
  try {
    await http.post('/sms/messages', {
      customer_id: row.customer_id,
      template: 'festival',
      body: defaultMessage(row),
    })
    toast.add({ severity: 'success', summary: 'Greeting SMS sent', life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Upcoming Occasions</h1>
    </div>

    <div class="toolbar">
      <Select v-model="days" :options="daysOptions" optionLabel="label" optionValue="value" class="filter" @change="load" />
      <Select v-model="typeFilter" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="All" showClear class="filter" @change="load" />
      <span class="toolbar-spacer" />
      <TableExportButtons title="Upcoming Occasions" :rows="rows"
        :columns="[
          { key: 'days_away', label: 'Days Away' },
          { key: 'type', label: 'Type' },
          { key: 'name', label: 'Customer' },
          { key: 'code', label: 'Code' },
          { key: 'phone', label: 'Phone' },
          { key: 'next_date', label: 'Date' },
          { key: 'years', label: 'Years' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="customer_id" removableSort class="p-datatable-sm" :rowClass="() => ''">
      <Column field="days_away" header="When" sortable style="width: 9rem">
        <template #body="{ data }">
          <Tag :severity="data.days_away === 0 ? 'success' : 'info'" :value="whenLabel(data.days_away)" />
        </template>
      </Column>
      <Column field="type" header="Type" sortable style="width: 8rem">
        <template #body="{ data }">
          <Tag :severity="data.type === 'birthday' ? 'warn' : 'success'" :value="data.type" />
        </template>
      </Column>
      <Column field="name" header="Customer" sortable>
        <template #body="{ data }">
          <span>{{ data.name }}</span>
          <span class="muted-sub">{{ data.code }}</span>
        </template>
      </Column>
      <Column field="phone" header="Phone" sortable />
      <Column field="next_date" header="Date" sortable>
        <template #body="{ data }">{{ data.next_date }} <span class="muted-sub">({{ data.years }} yrs)</span></template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button
            v-if="data.phone"
            icon="pi pi-whatsapp" label="Wish" size="small" severity="success" outlined
            @click="wish(data)"
          />
          <Button
            v-if="data.phone"
            icon="pi pi-comment" label="SMS" size="small" severity="info" outlined class="sms-btn"
            @click="wishSms(data)"
          />
        </template>
      </Column>
      <template #empty>No upcoming birthdays or anniversaries in this window.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar-spacer { flex: 1 1 auto; }
.filter { min-width: 160px; }
.muted-sub { display: block; color: #6b7280; font-size: 0.78rem; }
.sms-btn { margin-left: 0.35rem; }
</style>
