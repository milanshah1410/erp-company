<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Rating from 'primevue/rating'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'
import { slaSeverity, slaLabel } from './analytics/shared'

const toast = useToast()
const auth = useCentralAuthStore()

// ---- Tabs --------------------------------------------------------------------
const tabs = computed(() =>
  [
    { key: 'health', label: 'Tenant Health', icon: 'pi pi-heart', visible: auth.can('support.view') },
    { key: 'tickets', label: 'Tickets', icon: 'pi pi-ticket', visible: auth.can('tickets.view') },
  ].filter((t) => t.visible),
)
const activeTab = ref('')

const canCreateTickets = computed(() => auth.can('tickets.create'))
const canEditTickets = computed(() => auth.can('tickets.edit'))
const canCloseTickets = computed(() => auth.can('tickets.close'))

function apiError(e: any, fallback: string) {
  toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || fallback, life: 4000 })
}

function fmtDate(v: string | null | undefined): string {
  return v ? v.slice(0, 16).replace('T', ' ') : '—'
}

function fmtBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[i]}`
}

/** "in 3h 20m" / "4h 05m overdue" for whichever SLA clock is still running. */
function fmtCountdown(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) return ''
  const overdue = minutes < 0
  const total = Math.abs(minutes)
  const h = Math.floor(total / 60)
  const m = total % 60
  const span = h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`
  return overdue ? `${span} overdue` : `in ${span}`
}

const healthSeverity: Record<string, string> = { healthy: 'success', warning: 'warn', at_risk: 'danger' }
const healthLabel: Record<string, string> = { healthy: 'Healthy', warning: 'Warning', at_risk: 'At risk' }
const ticketStatusSeverity: Record<string, string> = { open: 'danger', pending: 'warn', resolved: 'success', closed: 'secondary' }
const prioritySeverity: Record<string, string> = { urgent: 'danger', high: 'warn', normal: 'info', low: 'secondary' }

// ---- Tenant health -------------------------------------------------------------
const healthRows = ref<any[]>([])
const healthSummary = ref<any>(null)
const healthLoading = ref(false)
const healthFilter = ref<string | null>(null)
const healthSearch = ref('')

const healthFilterOptions = [
  { label: 'At risk', value: 'at_risk' },
  { label: 'Warning', value: 'warning' },
  { label: 'Healthy', value: 'healthy' },
]

const filteredHealthRows = computed(() => {
  const q = healthSearch.value.trim().toLowerCase()
  return healthRows.value.filter((r) => {
    if (healthFilter.value && r.health !== healthFilter.value) return false
    if (q && !`${r.company_name} ${r.email}`.toLowerCase().includes(q)) return false
    return true
  })
})

async function loadHealth() {
  healthLoading.value = true
  try {
    const { data } = await centralHttp.get('/support/health')
    healthRows.value = data.data
    healthSummary.value = data.summary
  } catch (e: any) {
    apiError(e, 'Could not load tenant health')
  } finally {
    healthLoading.value = false
  }
}

// ---- Tenant drill-down (troubleshooting) ---------------------------------------
const detailDialog = ref(false)
const detail = ref<any>(null)
const detailLoading = ref(false)

async function inspectTenant(row: any) {
  detailDialog.value = true
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await centralHttp.get(`/support/health/${row.id}`)
    detail.value = data.data
  } catch (e: any) {
    detailDialog.value = false
    apiError(e, 'Could not load tenant details')
  } finally {
    detailLoading.value = false
  }
}

// ---- Tickets --------------------------------------------------------------------
const tickets = ref<any[]>([])
const ticketsTotal = ref(0)
const ticketsPage = ref(1)
const ticketsSummary = ref<any>(null)
const ticketsLoading = ref(false)
const ticketStatus = ref<string | null>('open_any')
const ticketPriority = ref<string | null>(null)
const ticketSla = ref<string | null>(null)
const ticketSearch = ref('')
const PER_PAGE = 15

// SLA/CSAT cuts of the queue. Server-side, because they are date predicates on
// the stored clocks — filtering after pagination would return short pages.
const slaOptions = [
  { label: 'SLA breached', value: 'breached' },
  { label: 'Due soon', value: 'due_soon' },
  { label: 'Awaiting rating', value: 'unrated' },
  { label: 'Rated', value: 'rated' },
]

const statusOptions = [
  { label: 'Open + pending', value: 'open_any' },
  { label: 'Open', value: 'open' },
  { label: 'Pending', value: 'pending' },
  { label: 'Resolved', value: 'resolved' },
  { label: 'Closed', value: 'closed' },
]
const priorityOptions = ['urgent', 'high', 'normal', 'low'].map((p) => ({ label: p[0].toUpperCase() + p.slice(1), value: p }))
const categoryOptions = ['general', 'billing', 'technical', 'onboarding', 'data'].map((c) => ({ label: c[0].toUpperCase() + c.slice(1), value: c }))

async function loadTickets(page = 1) {
  ticketsLoading.value = true
  ticketsPage.value = page
  try {
    const params: Record<string, any> = { page, per_page: PER_PAGE }
    if (ticketStatus.value) params.status = ticketStatus.value
    if (ticketPriority.value) params.priority = ticketPriority.value
    if (ticketSla.value) params.sla = ticketSla.value
    if (ticketSearch.value.trim()) params.search = ticketSearch.value.trim()
    const { data } = await centralHttp.get('/support/tickets', { params })
    tickets.value = data.data
    ticketsTotal.value = data.meta.total
    ticketsSummary.value = data.summary
  } catch (e: any) {
    apiError(e, 'Could not load tickets')
  } finally {
    ticketsLoading.value = false
  }
}

// Agents for the assignee dropdowns (id + name of active operators).
const agents = ref<any[]>([])
async function loadAgents() {
  if (agents.value.length) return
  try {
    const { data } = await centralHttp.get('/support/agents')
    agents.value = data.data
  } catch {
    /* dropdown stays empty — assignment is optional */
  }
}

// ---- Ticket detail / thread ------------------------------------------------------
const ticketDialog = ref(false)
const ticket = ref<any>(null)
const ticketLoading = ref(false)
const ticketEdit = ref<any>({ status: '', priority: '', assigned_to: null })
const ticketSaving = ref(false)
const replyBody = ref('')
const replyInternal = ref(false)
const replyAsTenant = ref(false)
const replySending = ref(false)
const closeNote = ref('')
const closing = ref(false)

// ---- Attachments -------------------------------------------------------------------
const replyFiles = ref<File[]>([])
const ticketFiles = ref<File[]>([])
const uploading = ref(false)
const replyFileInput = ref<HTMLInputElement | null>(null)
const ticketFileInput = ref<HTMLInputElement | null>(null)
const newFileInput = ref<HTMLInputElement | null>(null)
const newFiles = ref<File[]>([])

function pickFiles(target: 'reply' | 'ticket' | 'new', event: Event) {
  const input = event.target as HTMLInputElement
  const chosen = Array.from(input.files ?? [])
  if (target === 'reply') replyFiles.value = chosen
  else if (target === 'ticket') ticketFiles.value = chosen
  else newFiles.value = chosen
}

function clearFileInput(el: HTMLInputElement | null) {
  if (el) el.value = ''
}

/**
 * Stream an attachment through the API client rather than linking to it: the
 * download route is bearer-authenticated, so a plain href would 401.
 */
async function downloadAttachment(a: any) {
  try {
    const res = await centralHttp.get(`/support/tickets/${ticket.value.id}/attachments/${a.id}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const link = document.createElement('a')
    link.href = url
    link.download = a.name
    link.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    apiError(e, 'Could not download that file')
  }
}

async function uploadTicketFiles() {
  if (!ticketFiles.value.length) return
  uploading.value = true
  try {
    const form = new FormData()
    ticketFiles.value.forEach((f) => form.append('files[]', f))
    await centralHttp.post(`/support/tickets/${ticket.value.id}/attachments`, form)
    ticketFiles.value = []
    clearFileInput(ticketFileInput.value)
    await openTicket(ticket.value)
    toast.add({ severity: 'success', summary: 'Files attached', life: 2500 })
  } catch (e: any) {
    apiError(e, 'Upload failed')
  } finally {
    uploading.value = false
  }
}

async function removeAttachment(a: any) {
  try {
    await centralHttp.delete(`/support/tickets/${ticket.value.id}/attachments/${a.id}`)
    await openTicket(ticket.value)
  } catch (e: any) {
    apiError(e, 'Could not remove that file')
  }
}

// ---- CSAT rating -------------------------------------------------------------------
const ratingValue = ref<number | undefined>(undefined)
const ratingComment = ref('')
const ratingSaving = ref(false)

async function saveRating() {
  if (!ratingValue.value) return
  ratingSaving.value = true
  try {
    const { data } = await centralHttp.post(`/support/tickets/${ticket.value.id}/rating`, {
      rating: ratingValue.value,
      comment: ratingComment.value.trim() || null,
    })
    Object.assign(ticket.value, data.data)
    toast.add({ severity: 'success', summary: 'Rating recorded', life: 2500 })
    await loadTickets(ticketsPage.value)
  } catch (e: any) {
    apiError(e, 'Could not save the rating')
  } finally {
    ratingSaving.value = false
  }
}

async function openTicket(row: any) {
  ticketDialog.value = true
  ticketLoading.value = true
  ticket.value = null
  replyBody.value = ''
  replyInternal.value = false
  replyAsTenant.value = false
  replyFiles.value = []
  ticketFiles.value = []
  clearFileInput(replyFileInput.value)
  clearFileInput(ticketFileInput.value)
  closeNote.value = ''
  try {
    const { data } = await centralHttp.get(`/support/tickets/${row.id}`)
    ticket.value = data.data
    ticketEdit.value = { status: data.data.status, priority: data.data.priority, assigned_to: data.data.assigned_to }
    ratingValue.value = data.data.rating ?? undefined
    ratingComment.value = data.data.rating_comment ?? ''
  } catch (e: any) {
    ticketDialog.value = false
    apiError(e, 'Could not load ticket')
  } finally {
    ticketLoading.value = false
  }
}

const ticketDirty = computed(() =>
  ticket.value && (
    ticketEdit.value.status !== ticket.value.status ||
    ticketEdit.value.priority !== ticket.value.priority ||
    ticketEdit.value.assigned_to !== ticket.value.assigned_to
  ),
)

async function saveTicket() {
  ticketSaving.value = true
  try {
    const payload: Record<string, any> = {
      priority: ticketEdit.value.priority,
      assigned_to: ticketEdit.value.assigned_to,
    }
    // "closed" is not a triage status — the Close button handles that path.
    if (ticketEdit.value.status !== 'closed') payload.status = ticketEdit.value.status
    const { data } = await centralHttp.put(`/support/tickets/${ticket.value.id}`, payload)
    Object.assign(ticket.value, data.data)
    toast.add({ severity: 'success', summary: 'Ticket updated', life: 2500 })
    await loadTickets(ticketsPage.value)
  } catch (e: any) {
    apiError(e, 'Update failed')
  } finally {
    ticketSaving.value = false
  }
}

async function sendReply() {
  if (!replyBody.value.trim()) return
  replySending.value = true
  try {
    // Multipart whenever files ride along; a plain JSON body otherwise, so the
    // common case does not pay for a FormData round-trip.
    const form = new FormData()
    form.append('body', replyBody.value)
    form.append('is_internal', replyInternal.value ? '1' : '0')
    form.append('author_type', replyAsTenant.value ? 'tenant' : 'operator')
    replyFiles.value.forEach((f) => form.append('files[]', f))

    await centralHttp.post(`/support/tickets/${ticket.value.id}/replies`, replyFiles.value.length ? form : {
      body: replyBody.value,
      is_internal: replyInternal.value,
      author_type: replyAsTenant.value ? 'tenant' : 'operator',
    })
    replyBody.value = ''
    replyInternal.value = false
    replyAsTenant.value = false
    replyFiles.value = []
    clearFileInput(replyFileInput.value)
    await openTicket(ticket.value)
    await loadTickets(ticketsPage.value)
  } catch (e: any) {
    apiError(e, 'Reply failed')
  } finally {
    replySending.value = false
  }
}

async function closeTicket() {
  closing.value = true
  try {
    await centralHttp.post(`/support/tickets/${ticket.value.id}/close`, closeNote.value.trim() ? { note: closeNote.value } : {})
    toast.add({ severity: 'success', summary: 'Ticket closed', life: 2500 })
    ticketDialog.value = false
    await loadTickets(ticketsPage.value)
  } catch (e: any) {
    apiError(e, 'Close failed')
  } finally {
    closing.value = false
  }
}

// ---- New ticket -------------------------------------------------------------------
const newDialog = ref(false)
const newSaving = ref(false)
const newForm = ref<any>({})
const newErrors = ref<Record<string, string[]>>({})

const tenantOptions = computed(() => healthRows.value.map((r) => ({ label: r.company_name, value: r.id })))

function fieldError(key: string): string | null {
  return newErrors.value[key]?.[0] ?? null
}

async function openNewTicket(tenantId: number | null = null) {
  newForm.value = {
    tenant_id: tenantId, subject: '', body: '', category: 'general', priority: 'normal',
    contact_name: '', contact_email: '', assigned_to: null,
  }
  newErrors.value = {}
  newFiles.value = []
  clearFileInput(newFileInput.value)
  newDialog.value = true
  loadAgents()
  // Tenant options come from the health scorecard — make sure it's loaded.
  if (!healthRows.value.length) await loadHealth()
}

async function createTicket() {
  newSaving.value = true
  newErrors.value = {}
  try {
    const payload: Record<string, any> = { ...newForm.value }
    if (!payload.contact_name) delete payload.contact_name
    if (!payload.contact_email) delete payload.contact_email

    let body: any = payload
    if (newFiles.value.length) {
      const form = new FormData()
      Object.entries(payload).forEach(([k, v]) => {
        if (v !== null && v !== undefined) form.append(k, String(v))
      })
      newFiles.value.forEach((f) => form.append('files[]', f))
      body = form
    }

    const { data } = await centralHttp.post('/support/tickets', body)
    toast.add({ severity: 'success', summary: `Ticket #${data.data.id} opened`, life: 2500 })
    newDialog.value = false
    activeTab.value = 'tickets'
    await loadTickets(1)
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      newErrors.value = e.response.data.errors
    } else {
      apiError(e, 'Could not open ticket')
    }
  } finally {
    newSaving.value = false
  }
}

// ---- Lazy per-tab loading -----------------------------------------------------------
const loadedTabs = new Set<string>()

watch(activeTab, async (tab) => {
  if (!tab || loadedTabs.has(tab)) return
  loadedTabs.add(tab)
  if (tab === 'health') await loadHealth()
  if (tab === 'tickets') {
    loadAgents()
    await loadTickets()
  }
})

onMounted(() => {
  activeTab.value = tabs.value[0]?.key ?? ''
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Support &amp; Customer Success</h1>
      <Button v-if="canCreateTickets" label="New Ticket" icon="pi pi-plus" size="small" @click="openNewTicket()" />
    </div>

    <div class="tab-bar">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="tab"
        :class="{ active: activeTab === tab.key }"
        type="button"
        @click="activeTab = tab.key"
      >
        <i :class="tab.icon" />
        {{ tab.label }}
      </button>
    </div>

    <!-- ============================== Tenant health ============================== -->
    <section v-if="activeTab === 'health'">
      <div class="section-head">
        <div class="stats">
          <div class="stat">
            <span class="stat-value">{{ healthSummary?.total ?? '—' }}</span>
            <span class="stat-label">Tenants</span>
          </div>
          <div class="stat">
            <span class="stat-value ok">{{ healthSummary?.healthy ?? '—' }}</span>
            <span class="stat-label">Healthy</span>
          </div>
          <div class="stat">
            <span class="stat-value warn">{{ healthSummary?.warning ?? '—' }}</span>
            <span class="stat-label">Warning</span>
          </div>
          <div class="stat">
            <span class="stat-value danger">{{ healthSummary?.at_risk ?? '—' }}</span>
            <span class="stat-label">At risk</span>
          </div>
          <div class="stat">
            <span class="stat-value">{{ healthSummary?.open_tickets ?? '—' }}</span>
            <span class="stat-label">Open tickets</span>
          </div>
        </div>
        <div class="actions wrap">
          <InputText v-model="healthSearch" placeholder="Search tenant…" class="w-14" />
          <Select v-model="healthFilter" :options="healthFilterOptions" optionLabel="label" optionValue="value"
            placeholder="All health levels" showClear class="w-12" />
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="healthLoading" @click="loadHealth" />
        </div>
      </div>

      <DataTable :value="filteredHealthRows" :loading="healthLoading" dataKey="id" class="p-datatable-sm" paginator :rows="15">
        <Column field="company_name" header="Tenant" sortable>
          <template #body="{ data }">
            <div class="tenant-cell">
              <span class="tenant-name">{{ data.company_name }}</span>
              <span class="muted">{{ data.email }}</span>
            </div>
          </template>
        </Column>
        <Column field="health" header="Health" sortable style="width: 8rem">
          <template #body="{ data }">
            <Tag :severity="healthSeverity[data.health]" :value="healthLabel[data.health]" />
          </template>
        </Column>
        <Column header="Signals">
          <template #body="{ data }">
            <span v-if="data.reasons.length" class="reasons">{{ data.reasons.join(' · ') }}</span>
            <span v-else class="muted">No issues detected</span>
          </template>
        </Column>
        <Column field="plan" header="Plan" style="width: 10rem">
          <template #body="{ data }">
            <div class="tenant-cell">
              <span>{{ data.plan ?? '—' }}</span>
              <span class="muted">{{ data.subscription_status ?? '' }}</span>
            </div>
          </template>
        </Column>
        <Column field="users_count" header="Users" sortable style="width: 6rem" />
        <Column field="open_tickets" header="Tickets" sortable style="width: 6.5rem">
          <template #body="{ data }">
            <span :class="{ 'has-tickets': data.open_tickets > 0 }">{{ data.open_tickets }}</span>
          </template>
        </Column>
        <Column field="last_activity_at" header="Last activity" sortable style="width: 11rem">
          <template #body="{ data }">
            <span class="muted">{{ fmtDate(data.last_activity_at) }}</span>
          </template>
        </Column>
        <Column header="Actions" style="width: 7rem">
          <template #body="{ data }">
            <Button icon="pi pi-search" text rounded size="small" title="Inspect / troubleshoot" @click="inspectTenant(data)" />
            <Button v-if="canCreateTickets" icon="pi pi-ticket" text rounded size="small" title="New ticket for this tenant"
              @click="openNewTicket(data.id)" />
          </template>
        </Column>
        <template #empty>No tenants match.</template>
      </DataTable>

      <!-- Troubleshooting drill-down -->
      <Dialog v-model:visible="detailDialog" modal :header="detail?.tenant?.company_name ?? 'Tenant'" :style="{ width: '860px' }">
        <p v-if="detailLoading" class="muted">Loading…</p>
        <div v-else-if="detail">
          <div class="detail-head">
            <Tag :severity="healthSeverity[detail.health]" :value="healthLabel[detail.health]" />
            <Tag :value="detail.tenant.status" severity="secondary" />
            <span class="muted">since {{ fmtDate(detail.tenant.created_at) }}</span>
          </div>
          <ul v-if="detail.reasons.length" class="reason-list">
            <li v-for="(r, i) in detail.reasons" :key="i"><i class="pi pi-exclamation-triangle" /> {{ r }}</li>
          </ul>

          <div class="detail-grid">
            <div class="card">
              <div class="card-title">Workspace</div>
              <dl class="kv">
                <dt>Email</dt><dd>{{ detail.tenant.email }}</dd>
                <dt>Phone</dt><dd>{{ detail.tenant.phone || '—' }}</dd>
                <dt>Users</dt><dd>{{ detail.usage.users_total }} ({{ detail.usage.users_added_30d }} added in 30d)</dd>
                <dt>Last activity</dt><dd>{{ fmtDate(detail.usage.last_activity_at) }}</dd>
              </dl>
            </div>
            <div class="card">
              <div class="card-title">Subscription</div>
              <dl v-if="detail.subscription" class="kv">
                <dt>Plan</dt><dd>{{ detail.subscription.plan ?? '—' }}</dd>
                <dt>Status</dt><dd>{{ detail.subscription.status }}</dd>
                <dt>Trial ends</dt><dd>{{ fmtDate(detail.subscription.trial_ends_at) }}</dd>
                <dt>Period ends</dt><dd>{{ fmtDate(detail.subscription.current_period_end) }}</dd>
                <dt>Cancelled</dt><dd>{{ fmtDate(detail.subscription.cancelled_at) }}</dd>
              </dl>
              <p v-else class="muted">No subscription record.</p>
            </div>
          </div>

          <template v-if="detail.subscription?.invoices?.length">
            <h3 class="sub-heading">Recent invoices</h3>
            <DataTable :value="detail.subscription.invoices" class="p-datatable-sm">
              <Column field="id" header="#" style="width: 4rem" />
              <Column header="Amount">
                <template #body="{ data }">{{ data.currency }} {{ data.amount }}</template>
              </Column>
              <Column field="status" header="Status" style="width: 8rem">
                <template #body="{ data }">
                  <Tag :severity="data.status === 'paid' ? 'success' : data.status === 'failed' ? 'danger' : 'secondary'" :value="data.status" />
                </template>
              </Column>
              <Column header="Paid at" style="width: 11rem">
                <template #body="{ data }"><span class="muted">{{ fmtDate(data.paid_at) }}</span></template>
              </Column>
              <Column header="Created" style="width: 11rem">
                <template #body="{ data }"><span class="muted">{{ fmtDate(data.created_at) }}</span></template>
              </Column>
            </DataTable>
          </template>

          <h3 class="sub-heading">Recent tickets</h3>
          <DataTable :value="detail.tickets" class="p-datatable-sm">
            <Column field="id" header="#" style="width: 4rem" />
            <Column field="subject" header="Subject" />
            <Column field="priority" header="Priority" style="width: 8rem">
              <template #body="{ data }"><Tag :severity="prioritySeverity[data.priority]" :value="data.priority" /></template>
            </Column>
            <Column field="status" header="Status" style="width: 8rem">
              <template #body="{ data }"><Tag :severity="ticketStatusSeverity[data.status]" :value="data.status" /></template>
            </Column>
            <Column header="Last activity" style="width: 11rem">
              <template #body="{ data }"><span class="muted">{{ fmtDate(data.last_activity_at) }}</span></template>
            </Column>
            <template #empty>No tickets for this tenant.</template>
          </DataTable>

          <h3 class="sub-heading">Recent workspace activity</h3>
          <div v-if="detail.recent_activity.length" class="activity-list">
            <div v-for="(a, i) in detail.recent_activity" :key="i" class="activity-row">
              <span class="muted mono ts">{{ fmtDate(a.created_at) }}</span>
              <Tag :value="a.log" severity="secondary" class="mini-tag" />
              <span class="activity-desc">{{ a.description }}</span>
            </div>
          </div>
          <p v-else class="muted">No activity recorded for this workspace.</p>
        </div>
        <template #footer>
          <Button v-if="canCreateTickets && detail" label="New ticket" icon="pi pi-ticket" outlined
            @click="detailDialog = false; openNewTicket(detail.tenant.id)" />
          <Button label="Close" text @click="detailDialog = false" />
        </template>
      </Dialog>
    </section>

    <!-- ============================== Tickets ============================== -->
    <section v-if="activeTab === 'tickets'">
      <div class="section-head">
        <div class="stats">
          <div class="stat">
            <span class="stat-value danger">{{ ticketsSummary?.open ?? '—' }}</span>
            <span class="stat-label">Open</span>
          </div>
          <div class="stat">
            <span class="stat-value warn">{{ ticketsSummary?.pending ?? '—' }}</span>
            <span class="stat-label">Pending</span>
          </div>
          <div class="stat">
            <span class="stat-value ok">{{ ticketsSummary?.resolved ?? '—' }}</span>
            <span class="stat-label">Resolved</span>
          </div>
          <div class="stat">
            <span class="stat-value">{{ ticketsSummary?.unassigned ?? '—' }}</span>
            <span class="stat-label">Unassigned</span>
          </div>
          <div class="stat">
            <span class="stat-value danger">{{ ticketsSummary?.sla_breached_open ?? '—' }}</span>
            <span class="stat-label">SLA breached</span>
          </div>
          <div class="stat" title="Share of settled tickets resolved inside their target">
            <span class="stat-value" :class="(ticketsSummary?.sla_compliance ?? 100) >= 90 ? 'ok' : 'warn'">
              {{ ticketsSummary?.sla_compliance === null || ticketsSummary?.sla_compliance === undefined
                ? '—' : `${ticketsSummary.sla_compliance}%` }}
            </span>
            <span class="stat-label">SLA met</span>
          </div>
          <div class="stat" :title="`${ticketsSummary?.awaiting_rating ?? 0} settled ticket(s) not yet rated`">
            <span class="stat-value">
              {{ ticketsSummary?.rating_average ? `${ticketsSummary.rating_average}★` : '—' }}
            </span>
            <span class="stat-label">CSAT ({{ ticketsSummary?.ratings_count ?? 0 }})</span>
          </div>
        </div>
        <div class="actions wrap">
          <InputText v-model="ticketSearch" placeholder="Search subject or tenant…" class="w-14" @keyup.enter="loadTickets(1)" />
          <Select v-model="ticketStatus" :options="statusOptions" optionLabel="label" optionValue="value"
            placeholder="All statuses" showClear class="w-12" @change="loadTickets(1)" />
          <Select v-model="ticketPriority" :options="priorityOptions" optionLabel="label" optionValue="value"
            placeholder="All priorities" showClear class="w-11" @change="loadTickets(1)" />
          <Select v-model="ticketSla" :options="slaOptions" optionLabel="label" optionValue="value"
            placeholder="SLA / rating" showClear class="w-12" @change="loadTickets(1)" />
          <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="ticketsLoading" @click="loadTickets(ticketsPage)" />
        </div>
      </div>

      <DataTable :value="tickets" :loading="ticketsLoading" dataKey="id" class="p-datatable-sm"
        lazy paginator :rows="PER_PAGE" :totalRecords="ticketsTotal"
        @page="(e: any) => loadTickets(e.page + 1)">
        <Column field="id" header="#" style="width: 4rem" />
        <Column field="subject" header="Subject">
          <template #body="{ data }">
            <a class="subject-link" href="#" @click.prevent="openTicket(data)">{{ data.subject }}</a>
          </template>
        </Column>
        <Column field="tenant_name" header="Tenant" style="width: 13rem">
          <template #body="{ data }">{{ data.tenant_name ?? `Tenant #${data.tenant_id}` }}</template>
        </Column>
        <Column field="category" header="Category" style="width: 8rem">
          <template #body="{ data }"><span class="muted">{{ data.category }}</span></template>
        </Column>
        <Column field="priority" header="Priority" style="width: 7.5rem">
          <template #body="{ data }"><Tag :severity="prioritySeverity[data.priority]" :value="data.priority" /></template>
        </Column>
        <Column field="status" header="Status" style="width: 7.5rem">
          <template #body="{ data }"><Tag :severity="ticketStatusSeverity[data.status]" :value="data.status" /></template>
        </Column>
        <Column header="SLA" style="width: 9.5rem">
          <template #body="{ data }">
            <div class="sla-cell">
              <Tag :severity="slaSeverity[data.sla.state]" :value="slaLabel[data.sla.state]" />
              <span v-if="data.sla.minutes_to_due !== null" class="muted">
                {{ fmtCountdown(data.sla.minutes_to_due) }}
              </span>
              <span v-else class="muted">{{ data.sla.tier_label }}</span>
            </div>
          </template>
        </Column>
        <Column header="CSAT" style="width: 6rem">
          <template #body="{ data }">
            <span v-if="data.rating" class="csat">{{ data.rating }}★</span>
            <span v-else class="muted">—</span>
          </template>
        </Column>
        <Column field="assignee_name" header="Assignee" style="width: 10rem">
          <template #body="{ data }">
            <span :class="{ muted: !data.assignee_name }">{{ data.assignee_name ?? 'Unassigned' }}</span>
          </template>
        </Column>
        <Column field="last_activity_at" header="Last activity" style="width: 10.5rem">
          <template #body="{ data }"><span class="muted">{{ fmtDate(data.last_activity_at) }}</span></template>
        </Column>
        <Column header="" style="width: 4rem">
          <template #body="{ data }">
            <Button icon="pi pi-eye" text rounded size="small" title="Open ticket" @click="openTicket(data)" />
          </template>
        </Column>
        <template #empty>No tickets match.</template>
      </DataTable>

      <!-- Ticket detail / thread -->
      <Dialog v-model:visible="ticketDialog" modal :header="ticket ? `#${ticket.id} — ${ticket.subject}` : 'Ticket'" :style="{ width: '760px' }">
        <p v-if="ticketLoading" class="muted">Loading…</p>
        <div v-else-if="ticket">
          <div class="detail-head">
            <Tag :severity="ticketStatusSeverity[ticket.status]" :value="ticket.status" />
            <Tag :severity="prioritySeverity[ticket.priority]" :value="ticket.priority" />
            <span class="muted">{{ ticket.tenant_name ?? `Tenant #${ticket.tenant_id}` }} · {{ ticket.category }}
              <template v-if="ticket.contact_name"> · {{ ticket.contact_name }}</template>
              <template v-if="ticket.contact_email"> ({{ ticket.contact_email }})</template>
            </span>
          </div>

          <!-- SLA: the tier the tenant's plan bought, and where each clock stands. -->
          <div class="sla-bar" :class="ticket.sla.state">
            <Tag :severity="slaSeverity[ticket.sla.state]" :value="slaLabel[ticket.sla.state]" />
            <span class="sla-tier">{{ ticket.sla.tier_label }} SLA</span>
            <span class="sla-clock">
              <strong>First response</strong>
              <template v-if="ticket.sla.first_response_at">
                answered {{ fmtDate(ticket.sla.first_response_at) }}
              </template>
              <template v-else>due {{ fmtDate(ticket.sla.response_due_at) }}</template>
              <Tag :severity="slaSeverity[ticket.sla.response]" :value="slaLabel[ticket.sla.response]" class="mini-tag" />
            </span>
            <span class="sla-clock">
              <strong>Resolution</strong>
              <template v-if="ticket.resolved_at">settled {{ fmtDate(ticket.resolved_at) }}</template>
              <template v-else>due {{ fmtDate(ticket.sla.resolution_due_at) }}</template>
              <Tag :severity="slaSeverity[ticket.sla.resolution]" :value="slaLabel[ticket.sla.resolution]" class="mini-tag" />
            </span>
          </div>

          <div v-if="canEditTickets && ticket.status !== 'closed'" class="triage-row">
            <Select v-model="ticketEdit.status" :options="statusOptions.slice(1, 4)" optionLabel="label" optionValue="value" class="w-10" />
            <Select v-model="ticketEdit.priority" :options="priorityOptions" optionLabel="label" optionValue="value" class="w-10" />
            <Select v-model="ticketEdit.assigned_to" :options="agents" optionLabel="name" optionValue="id"
              placeholder="Unassigned" showClear class="w-12" />
            <Button label="Save" size="small" :disabled="!ticketDirty" :loading="ticketSaving" @click="saveTicket" />
          </div>

          <!-- Files attached to the ticket itself, as opposed to a single reply. -->
          <div v-if="ticket.attachments?.length || canEditTickets" class="files-box">
            <div class="files-head">
              <strong>Attachments</strong>
              <span class="muted">{{ ticket.attachments?.length ?? 0 }} on the ticket</span>
            </div>
            <div v-if="ticket.attachments?.length" class="file-list">
              <span v-for="a in ticket.attachments" :key="a.id" class="file-chip">
                <i :class="a.is_image ? 'pi pi-image' : 'pi pi-file'" />
                <button type="button" class="file-name" :title="a.name" @click="downloadAttachment(a)">{{ a.name }}</button>
                <span class="muted">{{ fmtBytes(a.size) }}</span>
                <Button v-if="canEditTickets" icon="pi pi-times" text rounded size="small"
                  severity="secondary" title="Remove" @click="removeAttachment(a)" />
              </span>
            </div>
            <div v-if="canEditTickets" class="file-add">
              <input ref="ticketFileInput" type="file" multiple class="file-input" @change="pickFiles('ticket', $event)" />
              <Button label="Attach" icon="pi pi-paperclip" size="small" outlined
                :disabled="!ticketFiles.length" :loading="uploading" @click="uploadTicketFiles" />
            </div>
          </div>

          <div class="thread">
            <div v-for="m in ticket.messages" :key="m.id" class="message" :class="{ internal: m.is_internal, tenant: m.author_type === 'tenant' }">
              <div class="message-head">
                <strong>{{ m.author_name }}</strong>
                <Tag v-if="m.is_internal" value="internal note" severity="warn" class="mini-tag" />
                <Tag v-else-if="m.author_type === 'tenant'" value="customer" severity="info" class="mini-tag" />
                <span class="muted">{{ fmtDate(m.created_at) }}</span>
              </div>
              <p class="message-body">{{ m.body }}</p>
              <div v-if="m.attachments?.length" class="file-list inline">
                <span v-for="a in m.attachments" :key="a.id" class="file-chip">
                  <i :class="a.is_image ? 'pi pi-image' : 'pi pi-file'" />
                  <button type="button" class="file-name" :title="a.name" @click="downloadAttachment(a)">{{ a.name }}</button>
                  <span class="muted">{{ fmtBytes(a.size) }}</span>
                </span>
              </div>
            </div>
          </div>

          <template v-if="canEditTickets">
            <div class="reply-box">
              <Textarea v-model="replyBody" rows="3" autoResize placeholder="Write a reply or note…" />
              <div class="reply-controls">
                <div class="checkbox-row">
                  <Checkbox v-model="replyInternal" binary inputId="reply-internal" :disabled="replyAsTenant" />
                  <label for="reply-internal">Internal note</label>
                </div>
                <div class="checkbox-row">
                  <Checkbox v-model="replyAsTenant" binary inputId="reply-tenant" :disabled="replyInternal" />
                  <label for="reply-tenant" title="Transcribe a reply that arrived by email/phone">Log as customer reply</label>
                </div>
                <input ref="replyFileInput" type="file" multiple class="file-input" @change="pickFiles('reply', $event)" />
                <Button label="Send" icon="pi pi-send" size="small" :disabled="!replyBody.trim()" :loading="replySending" @click="sendReply" />
              </div>
            </div>
          </template>

          <!-- CSAT. Only a settled ticket can be rated; before that the question
               has no answer, so the box explains itself rather than disappearing. -->
          <div v-if="canEditTickets" class="rating-box">
            <div class="files-head">
              <strong>Customer satisfaction</strong>
              <span v-if="ticket.rated_at" class="muted">recorded {{ fmtDate(ticket.rated_at) }}</span>
            </div>
            <template v-if="ticket.status === 'resolved' || ticket.status === 'closed'">
              <div class="rating-row">
                <Rating v-model="ratingValue" :stars="5" />
                <InputText v-model="ratingComment" placeholder="What the customer said (optional)" class="rating-note" />
                <Button :label="ticket.rating ? 'Update' : 'Save'" size="small" :disabled="!ratingValue"
                  :loading="ratingSaving" @click="saveRating" />
              </div>
              <p class="muted">
                Transcribe the score the customer gave — by email, phone or the follow-up survey.
              </p>
            </template>
            <p v-else class="muted">
              Available once the ticket is resolved or closed.
            </p>
          </div>

          <div v-if="canCloseTickets && ticket.status !== 'closed'" class="close-box">
            <InputText v-model="closeNote" placeholder="Closing note (optional)" />
            <Button label="Close ticket" icon="pi pi-lock" size="small" severity="danger" outlined :loading="closing" @click="closeTicket" />
          </div>
          <p v-else-if="ticket.status === 'closed'" class="muted">
            Closed {{ fmtDate(ticket.closed_at) }}<template v-if="canEditTickets"> — a new reply will reopen it.</template>
          </p>
        </div>
      </Dialog>

      <!-- New ticket -->
    </section>

    <Dialog v-model:visible="newDialog" modal header="New Support Ticket" :style="{ width: '560px' }">
      <div class="form-grid">
        <div class="form-field full">
          <label>Tenant <span class="req">*</span></label>
          <Select v-model="newForm.tenant_id" :options="tenantOptions" optionLabel="label" optionValue="value"
            placeholder="Select tenant" filter :class="{ invalid: fieldError('tenant_id') }" />
          <small v-if="fieldError('tenant_id')" class="error-text">{{ fieldError('tenant_id') }}</small>
        </div>
        <div class="form-field full">
          <label>Subject <span class="req">*</span></label>
          <InputText v-model="newForm.subject" :class="{ invalid: fieldError('subject') }" />
          <small v-if="fieldError('subject')" class="error-text">{{ fieldError('subject') }}</small>
        </div>
        <div class="form-field">
          <label>Category</label>
          <Select v-model="newForm.category" :options="categoryOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label>Priority</label>
          <Select v-model="newForm.priority" :options="priorityOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label>Contact name</label>
          <InputText v-model="newForm.contact_name" />
        </div>
        <div class="form-field">
          <label>Contact email</label>
          <InputText v-model="newForm.contact_email" :class="{ invalid: fieldError('contact_email') }" />
          <small v-if="fieldError('contact_email')" class="error-text">{{ fieldError('contact_email') }}</small>
        </div>
        <div class="form-field full">
          <label>Assign to</label>
          <Select v-model="newForm.assigned_to" :options="agents" optionLabel="name" optionValue="id"
            placeholder="Unassigned" showClear />
        </div>
        <div class="form-field full">
          <label>Description <span class="req">*</span></label>
          <Textarea v-model="newForm.body" rows="4" autoResize :class="{ invalid: fieldError('body') }" />
          <small v-if="fieldError('body')" class="error-text">{{ fieldError('body') }}</small>
        </div>
        <div class="form-field full">
          <label>Attachments</label>
          <input ref="newFileInput" type="file" multiple class="file-input" @change="pickFiles('new', $event)" />
          <small v-if="fieldError('files.0')" class="error-text">{{ fieldError('files.0') }}</small>
          <small class="muted">Screenshots, logs or exports the issue refers to.</small>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="newDialog = false" />
        <Button label="Open ticket" :loading="newSaving" @click="createTicket" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }

.tab-bar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1.25rem; flex-wrap: wrap; }
.tab {
  display: flex; align-items: center; gap: 0.45rem; padding: 0.55rem 0.9rem; border: none; background: none;
  font-size: 0.9rem; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: #1f2937; }
.tab.active { color: #b45309; border-bottom-color: #e8a33d; }

.section-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.actions { display: flex; gap: 0.5rem; align-items: center; }
.actions.wrap { flex-wrap: wrap; }
.sub-heading { font-size: 1.02rem; font-weight: 600; margin: 1.5rem 0 0.6rem; }

.stats { display: flex; gap: 1.75rem; flex-wrap: wrap; }
.stat { display: flex; flex-direction: column; line-height: 1.2; }
.stat-value { font-size: 1.4rem; font-weight: 700; }
.stat-value.ok { color: #16a34a; }
.stat-value.warn { color: #d97706; }
.stat-value.danger { color: #dc2626; }
.stat-label { font-size: 0.75rem; color: #6b7280; }

.muted { color: #9ca3af; font-size: 0.82rem; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8rem; }
.mini-tag { font-size: 0.66rem; }
.mini-tag :deep(.p-tag-label) { font-size: 0.66rem; }

.tenant-cell { display: flex; flex-direction: column; line-height: 1.3; }
.tenant-name { font-weight: 500; }
.reasons { font-size: 0.82rem; color: #b45309; }
.has-tickets { font-weight: 700; color: #b45309; }
.subject-link { color: #1f2937; font-weight: 500; text-decoration: none; }
.subject-link:hover { color: #b45309; text-decoration: underline; }

.detail-head { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
.reason-list { margin: 0 0 1rem; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.3rem; }
.reason-list li { font-size: 0.86rem; color: #b45309; display: flex; align-items: center; gap: 0.45rem; }
.reason-list i { font-size: 0.8rem; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.9rem 1rem; }
.card-title { font-weight: 600; font-size: 0.92rem; margin-bottom: 0.4rem; display: block; }
.kv { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.9rem; font-size: 0.84rem; margin: 0; }
.kv dt { color: #6b7280; }
.kv dd { margin: 0; overflow-wrap: anywhere; }

.activity-list { display: flex; flex-direction: column; gap: 0.3rem; }
.activity-row { display: flex; align-items: center; gap: 0.6rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.4rem 0.7rem; }
.activity-row .ts { flex-shrink: 0; }
.activity-desc { font-size: 0.84rem; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.triage-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }

.thread { display: flex; flex-direction: column; gap: 0.6rem; max-height: 340px; overflow-y: auto; padding-right: 0.25rem; }
.message { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.6rem 0.85rem; }
.message.internal { background: #fffbeb; border-color: #fde68a; }
.message.tenant { background: #eff6ff; border-color: #bfdbfe; }
.message-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem; font-size: 0.85rem; }
.message-body { margin: 0; font-size: 0.88rem; white-space: pre-wrap; word-break: break-word; }

.reply-box { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
.reply-box :deep(.p-textarea) { width: 100%; }
.reply-controls { display: flex; align-items: center; gap: 1.25rem; }
.reply-controls .p-button { margin-left: auto; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

.close-box { margin-top: 1rem; padding-top: 0.9rem; border-top: 1px dashed #e5e7eb; display: flex; gap: 0.5rem; align-items: center; }
.close-box :deep(.p-inputtext) { flex: 1; }

/* ---- SLA ------------------------------------------------------------------ */
.sla-cell { display: flex; flex-direction: column; gap: 0.15rem; align-items: flex-start; }
.csat { font-weight: 600; color: #b45309; }
.sla-bar {
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  background: #f9fafb; border: 1px solid #e5e7eb; border-left: 3px solid #9ca3af;
  border-radius: 8px; padding: 0.5rem 0.8rem; margin-bottom: 0.9rem; font-size: 0.82rem;
}
.sla-bar.breached { border-left-color: #dc2626; background: #fef2f2; }
.sla-bar.due_soon { border-left-color: #d97706; background: #fffbeb; }
.sla-bar.met { border-left-color: #16a34a; }
.sla-tier { font-weight: 600; color: #374151; }
.sla-clock { display: flex; align-items: center; gap: 0.4rem; color: #6b7280; }
.sla-clock strong { color: #374151; font-weight: 600; }

/* ---- Attachments ----------------------------------------------------------- */
.files-box, .rating-box {
  margin-top: 1rem; padding: 0.7rem 0.85rem;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
}
.files-head { display: flex; align-items: baseline; gap: 0.6rem; margin-bottom: 0.45rem; font-size: 0.88rem; }
.file-list { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.file-list.inline { margin-top: 0.5rem; }
.file-chip {
  display: inline-flex; align-items: center; gap: 0.4rem;
  background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 999px;
  padding: 0.15rem 0.3rem 0.15rem 0.6rem; font-size: 0.8rem; max-width: 100%;
}
.file-chip i { color: #6b7280; font-size: 0.8rem; }
.file-name {
  background: none; border: none; padding: 0; cursor: pointer; color: #1d4ed8;
  font-size: 0.8rem; max-width: 15rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.file-name:hover { text-decoration: underline; }
.file-add { margin-top: 0.55rem; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.file-input { font-size: 0.8rem; max-width: 100%; }
.file-input::file-selector-button {
  border: 1px solid #d1d5db; background: #fff; border-radius: 6px;
  padding: 0.25rem 0.6rem; margin-right: 0.5rem; cursor: pointer; font-size: 0.8rem;
}

.rating-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.35rem; }
.rating-note { flex: 1; min-width: 12rem; }

.w-10 { width: 10rem; }
.w-11 { width: 11rem; }
.w-12 { width: 12rem; }
.w-14 { width: 14rem; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-textarea), .form-field :deep(.p-select) { width: 100%; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }

@media (max-width: 720px) {
  .detail-grid { grid-template-columns: 1fr; }
  .form-grid { grid-template-columns: 1fr; }
}
</style>
