<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import OverlayPanel from 'primevue/overlaypanel'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import http from '../api/http'

const router = useRouter()
const panel = ref<any>(null)
const unreadCount = ref(0)
const items = ref<any[]>([])
const loading = ref(false)

async function loadUnreadCount() {
  try {
    const { data } = await http.get('/notifications/unread-count')
    unreadCount.value = data.count ?? 0
  } catch {
    // ignore transient failures; next poll will retry
  }
}

async function loadList() {
  loading.value = true
  try {
    const { data } = await http.get('/notifications', { params: { per_page: 10 } })
    items.value = data.data ?? []
  } finally {
    loading.value = false
  }
}

function toggle(event: Event) {
  panel.value?.toggle(event)
  loadList()
}

async function openItem(item: any) {
  if (!item.read_at) {
    try {
      await http.post(`/notifications/${item.id}/read`)
      item.read_at = new Date().toISOString()
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    } catch {
      // non-fatal; the item just stays marked unread
    }
  }
  panel.value?.hide()
  if (item.data?.url) router.push(item.data.url)
}

function viewAll() {
  panel.value?.hide()
  router.push('/notifications')
}

async function markAllRead() {
  try {
    await http.post('/notifications/read-all')
    items.value.forEach((i) => (i.read_at = i.read_at ?? new Date().toISOString()))
    unreadCount.value = 0
  } catch {
    // non-fatal
  }
}

function timeAgo(iso: string) {
  const diffMs = Date.now() - new Date(iso).getTime()
  const mins = Math.round(diffMs / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.round(hours / 24)}d ago`
}

function severityColor(sev: string) {
  return sev === 'critical' ? '#c1502e' : sev === 'warning' ? '#e8a33d' : '#2c4a5e'
}

let poll: any = null
onMounted(() => {
  loadUnreadCount()
  poll = setInterval(loadUnreadCount, 30000)
})
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <div class="bell-wrap">
    <button class="bell-btn" @click="toggle">
      <i class="pi pi-bell" />
      <Badge v-if="unreadCount > 0" :value="unreadCount > 99 ? '99+' : unreadCount" severity="danger" class="bell-badge" />
    </button>

    <OverlayPanel ref="panel" class="bell-panel">
      <div class="panel-header">
        <span>Notifications</span>
        <Button v-if="unreadCount > 0" label="Mark all read" text size="small" @click="markAllRead" />
      </div>

      <div v-if="loading" class="panel-empty">Loading…</div>
      <div v-else-if="!items.length" class="panel-empty">No notifications yet.</div>
      <div v-else class="panel-list">
        <button v-for="item in items" :key="item.id" class="panel-item" :class="{ unread: !item.read_at }" @click="openItem(item)">
          <span class="dot" :style="{ background: severityColor(item.data?.severity) }" />
          <span class="item-body">
            <span class="item-title">{{ item.data?.title }}</span>
            <span class="item-message">{{ item.data?.message }}</span>
            <span class="item-time">{{ timeAgo(item.created_at) }}</span>
          </span>
        </button>
      </div>
      <div class="panel-footer">
        <Button label="View all" text size="small" @click="viewAll" />
      </div>
    </OverlayPanel>
  </div>
</template>

<style scoped>
.bell-wrap {
  position: relative;
}
.bell-btn {
  position: relative;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  color: #2c4a5e;
  padding: 0.4rem;
  border-radius: 6px;
}
.bell-btn:hover {
  background: rgba(44, 74, 94, 0.08);
}
.bell-badge {
  position: absolute;
  top: 0;
  right: 0;
  transform: translate(30%, -30%);
}
.bell-panel :deep(.p-overlaypanel-content) {
  padding: 0;
}
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #f3f4f6;
  font-weight: 600;
  font-size: 0.9rem;
}
.panel-empty {
  padding: 1.5rem;
  text-align: center;
  color: #6b7280;
  font-size: 0.85rem;
  width: 320px;
}
.panel-list {
  max-height: 400px;
  overflow-y: auto;
  width: 340px;
  display: flex;
  flex-direction: column;
}
.panel-item {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  padding: 0.7rem 1rem;
  border: none;
  border-bottom: 1px solid #f9fafb;
  background: #fff;
  text-align: left;
  cursor: pointer;
  width: 100%;
}
.panel-item:hover {
  background: #f9fafb;
}
.panel-item.unread {
  background: rgba(232, 163, 61, 0.08);
}
.panel-item.unread:hover {
  background: rgba(232, 163, 61, 0.15);
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-top: 0.35rem;
  flex-shrink: 0;
}
.item-body {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}
.item-title {
  font-size: 0.85rem;
  font-weight: 600;
  color: #111827;
}
.item-message {
  font-size: 0.8rem;
  color: #4b5563;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.item-time {
  font-size: 0.72rem;
  color: #9ca3af;
}
.panel-footer {
  display: flex;
  justify-content: center;
  padding: 0.4rem;
  border-top: 1px solid #f3f4f6;
}
</style>
