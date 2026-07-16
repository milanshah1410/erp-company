<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import DataView from 'primevue/dataview'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import http from '../api/http'
import TableExportButtons from '../components/TableExportButtons.vue'

const router = useRouter()
const items = ref<any[]>([])
const loading = ref(false)
const unreadOnly = ref(false)
const search = ref('')
const page = ref(1)
const lastPage = ref(1)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/notifications', {
      params: { per_page: 20, page: page.value, unread_only: unreadOnly.value || undefined, search: search.value || undefined },
    })
    items.value = data.data ?? []
    lastPage.value = data.meta?.last_page ?? 1
  } finally {
    loading.value = false
  }
}

function reload() {
  page.value = 1
  load()
}

async function markRead(item: any) {
  if (item.read_at) return
  await http.post(`/notifications/${item.id}/read`)
  item.read_at = new Date().toISOString()
}

async function markAllRead() {
  await http.post('/notifications/read-all')
  items.value.forEach((i) => (i.read_at = i.read_at ?? new Date().toISOString()))
}

async function remove(item: any) {
  await http.delete(`/notifications/${item.id}`)
  items.value = items.value.filter((i) => i.id !== item.id)
}

async function open(item: any) {
  await markRead(item)
  if (item.data?.url) router.push(item.data.url)
}

function severity(sev: string) {
  return sev === 'critical' ? 'danger' : sev === 'warning' ? 'warn' : 'info'
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleString()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Notifications</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search notifications..." @keyup.enter="reload" />
        </span>
        <label class="unread-toggle">
          <Checkbox v-model="unreadOnly" binary @change="reload" />
          Unread only
        </label>
        <Button label="Mark all read" icon="pi pi-check" outlined size="small" @click="markAllRead" />
        <TableExportButtons title="Notifications" endpoint="/notifications" size="small"
          :params="() => ({ ...(unreadOnly ? { unread_only: true } : {}), ...(search ? { search } : {}) })"
          :columns="[
            { key: 'data.severity', label: 'Severity' },
            { key: 'data.title', label: 'Title' },
            { key: 'data.message', label: 'Message' },
            { key: 'created_at', label: 'Time' },
          ]" />
      </div>
    </div>

    <DataView :value="items" :loading="loading" dataKey="id">
      <template #list="{ items: rows }">
        <div class="list">
          <div v-for="item in rows" :key="item.id" class="row" :class="{ unread: !item.read_at }">
            <Tag :severity="severity(item.data?.severity)" :value="item.data?.severity ?? 'info'" />
            <div class="row-body" @click="open(item)">
              <div class="row-title">{{ item.data?.title }}</div>
              <div class="row-message">{{ item.data?.message }}</div>
              <div class="row-time">{{ formatDate(item.created_at) }}</div>
            </div>
            <div class="row-actions">
              <Button v-if="!item.read_at" icon="pi pi-check" text rounded size="small" title="Mark read" @click="markRead(item)" />
              <Button icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="remove(item)" />
            </div>
          </div>
        </div>
      </template>
      <template #empty>No notifications found.</template>
    </DataView>

    <div class="pager" v-if="lastPage > 1">
      <Button icon="pi pi-chevron-left" text :disabled="page <= 1" @click="page--; load()" />
      <span>Page {{ page }} of {{ lastPage }}</span>
      <Button icon="pi pi-chevron-right" text :disabled="page >= lastPage" @click="page++; load()" />
    </div>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.header-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}
.search-box {
  position: relative;
  display: inline-flex;
  align-items: center;
}
.search-box i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.search-box :deep(input) {
  padding-left: 2.25rem;
}
.unread-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: #374151;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.row {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 0.85rem 1rem;
}
.row.unread {
  background: #eff6ff;
  border-color: #bfdbfe;
}
.row-body {
  flex: 1;
  cursor: pointer;
  min-width: 0;
}
.row-title {
  font-weight: 600;
  font-size: 0.9rem;
}
.row-message {
  font-size: 0.85rem;
  color: #4b5563;
  margin-top: 0.15rem;
}
.row-time {
  font-size: 0.75rem;
  color: #9ca3af;
  margin-top: 0.3rem;
}
.row-actions {
  display: flex;
  gap: 0.15rem;
  flex-shrink: 0;
}
.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
  font-size: 0.85rem;
  color: #4b5563;
}
</style>
