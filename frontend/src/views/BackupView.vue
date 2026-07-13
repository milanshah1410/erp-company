<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('backups.create'))
const canDelete = computed(() => auth.can('backups.delete'))

const backups = ref<any[]>([])
const loading = ref(true)
const running = ref(false)

function formatSize(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let n = bytes
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024
    i++
  }
  return `${n.toFixed(1)} ${units[i]}`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/backups')
    backups.value = data.data ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to load backups', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function runBackup() {
  running.value = true
  try {
    await http.post('/backups/run')
    toast.add({ severity: 'success', summary: 'Backup completed', life: 3000 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Backup failed', detail: e.response?.data?.message || 'Unexpected error', life: 5000 })
  } finally {
    running.value = false
  }
}

async function download(row: any) {
  try {
    const response = await http.get(`/backups/${encodeURIComponent(row.filename)}/download`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.download = row.filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Download failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.filename}"?`,
    text: 'This backup archive will be permanently removed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/backups/${encodeURIComponent(row.filename)}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Backup &amp; Restore</h1>
      <Button v-if="canCreate" label="Run Backup Now" icon="pi pi-cloud-upload" :loading="running" @click="runBackup" />
    </div>

    <p class="note">
      Each backup archive contains a full database dump plus application storage files (uploads, generated documents).
      A backup also runs automatically every night. To restore, download the archive, extract the SQL dump, and import
      it into the database — restoring over a live database is a deliberate, manual step and is not done from here.
    </p>

    <DataTable :value="backups" :loading="loading" dataKey="filename" class="p-datatable-sm">
      <Column field="filename" header="File" />
      <Column header="Size">
        <template #body="{ data }">{{ formatSize(data.size) }}</template>
      </Column>
      <Column field="created_at" header="Created" />
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-download" text rounded size="small" title="Download" @click="download(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>
        <span v-if="!loading">No backups yet — click "Run Backup Now" to create the first one.</span>
      </template>
    </DataTable>
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
.note {
  color: #6b7280;
  font-size: 0.85rem;
  margin-bottom: 1rem;
  max-width: 760px;
}
</style>
