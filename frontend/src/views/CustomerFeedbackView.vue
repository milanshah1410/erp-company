<script setup lang="ts">
/**
 * Customer feedback & CSAT — the shop's own measure of itself (retail base §6).
 *
 * The screen is ordered by what a shop owner actually does with it: the score
 * first, then the complaints that nobody has picked up, then the book. A table
 * sorted by date with the score buried in a corner would be a report; this is
 * meant to be a worklist.
 *
 * Two ways to add to it, matching the two ways feedback arrives: "Record" writes
 * down what somebody said at the counter, "Ask customers" sends links against
 * recent bills. Both are here rather than one being hidden in a settings screen,
 * because a shop that only ever uses the second is measuring its own diligence.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('customer-feedback.create'))
const canEdit = computed(() => auth.can('customer-feedback.edit'))
const canDelete = computed(() => auth.can('customer-feedback.delete'))

const STATUSES = [
  { label: 'Awaiting answer', value: 'pending' },
  { label: 'Answered', value: 'submitted' },
  { label: 'Acknowledged', value: 'acknowledged' },
  { label: 'Resolved', value: 'resolved' },
]

const CHANNELS = [
  { label: 'At the counter', value: 'counter' },
  { label: 'Link', value: 'link' },
  { label: 'SMS', value: 'sms' },
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'Email', value: 'email' },
  { label: 'Portal', value: 'portal' },
]

const RATINGS = [
  { label: '5 — Great', value: 5 },
  { label: '4 — Good', value: 4 },
  { label: '3 — Okay', value: 3 },
  { label: '2 — Poor', value: 2 },
  { label: '1 — Bad', value: 1 },
]

const TAG_OPTIONS = ['Staff', 'Price', 'Product quality', 'Waiting time', 'Cleanliness', 'Delivery']

const FACE: Record<number, string> = { 1: '😠', 2: '🙁', 3: '😐', 4: '🙂', 5: '😄' }

function labelOf(list: { label: string; value: any }[], value: any) {
  return list.find((o) => o.value === value)?.label ?? value ?? '—'
}

function statusSeverity(status: string) {
  switch (status) {
    case 'resolved':
      return 'success'
    case 'acknowledged':
      return 'info'
    case 'submitted':
      return 'warn'
    default:
      return 'secondary'
  }
}

/** Colour by the score, which is the only thing a row is scanned for. */
function ratingSeverity(rating: number | null) {
  if (rating === null) return 'secondary'
  if (rating >= 4) return 'success'
  if (rating === 3) return 'warn'
  return 'danger'
}

// ---- The book ---------------------------------------------------------------

const rows = ref<any[]>([])
const summary = ref<any>({ responses: 0, csat_percent: null, average_rating: null, detractors: 0, pending: 0, needs_attention: 0, ratings: {}, tags: {} })
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(25)

const search = ref('')
const statusFilter = ref<string | null>(null)
const channelFilter = ref<string | null>(null)
const ratingFilter = ref<number | null>(null)
const attentionOnly = ref(false)
const commentsOnly = ref(false)
const dateFrom = ref('')
const dateTo = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/customer-feedback', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        channel: channelFilter.value || undefined,
        rating: ratingFilter.value || undefined,
        needs_attention: attentionOnly.value ? 1 : undefined,
        with_comment: commentsOnly.value ? 1 : undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        page: page.value,
        per_page: perPage.value,
      },
    })
    rows.value = data.data ?? []
    summary.value = data.summary ?? summary.value
    total.value = data.meta?.total ?? rows.value.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function applyFilters() {
  page.value = 1
  load()
}

/** The rating spread, as a share of answers — an average hides the shape. */
const spread = computed(() => {
  const counts = summary.value.ratings ?? {}
  const answered = Number(summary.value.responses ?? 0)
  return [5, 4, 3, 2, 1].map((r) => ({
    rating: r,
    count: Number(counts[r] ?? 0),
    percent: answered > 0 ? Math.round((Number(counts[r] ?? 0) / answered) * 100) : 0,
  }))
})

const topTags = computed(() => Object.entries(summary.value.tags ?? {}) as [string, number][])

// ---- Record what was said at the counter ------------------------------------

const recordDialog = ref(false)
const recordSaving = ref(false)
const recordErrors = ref<Record<string, string[]>>({})
const customers = ref<any[]>([])
const editingId = ref<number | null>(null)

const blankRecord = () => ({
  customer_id: null as number | null,
  customer_name: '',
  customer_phone: '',
  invoice_id: null as number | null,
  channel: 'counter',
  rating: null as number | null,
  comment: '',
  tags: [] as string[],
})
const recordForm = ref(blankRecord())

async function loadCustomers() {
  if (customers.value.length) return
  try {
    const { data } = await http.get('/customers', { params: { per_page: 200 } })
    customers.value = data.data ?? []
  } catch {
    // A picker that failed to load is not a reason to block the dialog — the
    // name and phone fields still take a walk-in.
  }
}

async function openRecord() {
  editingId.value = null
  recordErrors.value = {}
  recordForm.value = blankRecord()
  recordDialog.value = true
  await loadCustomers()
}

async function openEdit(row: any) {
  editingId.value = row.id
  recordErrors.value = {}
  recordForm.value = {
    customer_id: row.customer_id,
    customer_name: row.customer ?? '',
    customer_phone: row.customer_phone ?? '',
    invoice_id: row.invoice_id,
    channel: row.channel,
    rating: row.rating,
    comment: row.comment ?? '',
    tags: [...(row.tags ?? [])],
  }
  recordDialog.value = true
  await loadCustomers()
}

function toggleFormTag(tag: string) {
  const i = recordForm.value.tags.indexOf(tag)
  if (i >= 0) recordForm.value.tags.splice(i, 1)
  else recordForm.value.tags.push(tag)
}

async function saveRecord() {
  recordSaving.value = true
  recordErrors.value = {}
  const payload = {
    ...recordForm.value,
    customer_name: recordForm.value.customer_name || undefined,
    customer_phone: recordForm.value.customer_phone || undefined,
    comment: recordForm.value.comment || undefined,
    tags: recordForm.value.tags.length ? recordForm.value.tags : undefined,
  }
  try {
    if (editingId.value) {
      await http.put(`/customer-feedback/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Feedback updated'), life: 2500 })
    } else {
      await http.post('/customer-feedback', payload)
      toast.add({ severity: 'success', summary: pt('Feedback recorded'), life: 2500 })
    }
    recordDialog.value = false
    await load()
  } catch (e: any) {
    recordErrors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the feedback'), life: 4000 })
  } finally {
    recordSaving.value = false
  }
}

// ---- Ask customers ---------------------------------------------------------

const askDialog = ref(false)
const askLoading = ref(false)
const askSending = ref(false)
const askDays = ref(14)
const askSendSms = ref(true)
const askRows = ref<any[]>([])
const askSelected = ref<any[]>([])

async function openAsk() {
  askDialog.value = true
  askSelected.value = []
  await loadAskable()
}

async function loadAskable() {
  askLoading.value = true
  try {
    const { data } = await http.get('/customer-feedback/askable', { params: { days: askDays.value } })
    askRows.value = data.data ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not load recent bills'), life: 4000 })
  } finally {
    askLoading.value = false
  }
}

async function sendAsks() {
  if (!askSelected.value.length) return
  askSending.value = true
  try {
    const { data } = await http.post('/customer-feedback/request-links', {
      invoice_ids: askSelected.value.map((r) => r.id),
      channel: askSendSms.value ? 'sms' : 'link',
      send_sms: askSendSms.value,
    })
    toast.add({ severity: 'success', summary: data.message, life: 4500 })
    // Skipped bills are reported rather than silently dropped: a shop that
    // selected forty and got twelve links needs to know why.
    if (data.skipped?.length) {
      toast.add({
        severity: 'info',
        summary: pt('{n} bill(s) skipped', { n: data.skipped.length }),
        detail: data.skipped.slice(0, 5).map((s: any) => `${s.invoice_number}: ${s.reason}`).join('\n'),
        life: 7000,
      })
    }
    askDialog.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not send the requests'), life: 4000 })
  } finally {
    askSending.value = false
  }
}

async function resend(row: any) {
  try {
    const { data } = await http.post(`/customer-feedback/${row.id}/resend`)
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not resend'), life: 4000 })
  }
}

/**
 * Hand the ask to WhatsApp. The server builds the wa.me link and logs it; the
 * browser opens it. Opened in a new tab rather than navigated to, because losing
 * the register mid-round is how a counter stops chasing the rest of the list.
 */
async function sendWhatsapp(row: any) {
  try {
    const { data } = await http.post(`/customer-feedback/${row.id}/whatsapp`)
    window.open(data.data.link, '_blank')
    toast.add({ severity: 'success', summary: data.message, life: 3000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not open WhatsApp'), life: 4000 })
  }
}

async function copyLink(row: any) {
  if (!row.link) return
  try {
    await navigator.clipboard.writeText(row.link)
    toast.add({ severity: 'success', summary: pt('Link copied — paste it into WhatsApp'), life: 3000 })
  } catch {
    // Clipboard is blocked outside a secure context; show it so it can be copied by hand.
    await Swal.fire({ title: pt('Feedback link'), text: row.link, icon: 'info' })
  }
}

// ---- Reply -----------------------------------------------------------------

const replyDialog = ref(false)
const replySaving = ref(false)
const replyRow = ref<any>(null)
const replyForm = ref({ response: '', status: 'acknowledged' as 'acknowledged' | 'resolved' })

function openReply(row: any) {
  replyRow.value = row
  replyForm.value = { response: row.response ?? '', status: row.status === 'resolved' ? 'resolved' : 'acknowledged' }
  replyDialog.value = true
}

async function saveReply() {
  if (!replyRow.value) return
  replySaving.value = true
  try {
    const { data } = await http.post(`/customer-feedback/${replyRow.value.id}/respond`, replyForm.value)
    toast.add({ severity: 'success', summary: data.message, life: 2500 })
    replyDialog.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not record the reply'), life: 4000 })
  } finally {
    replySaving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Remove this feedback?'),
    text: pt('It stops counting towards your score.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.delete(`/customer-feedback/${row.id}`)
    toast.add({ severity: 'success', summary: pt('Removed'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not remove it'), life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader :title="pt('Customer Feedback')" :subtitle="pt('What your customers say, and how the shop scores')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('Ask Customers')" icon="pi pi-send" @click="openAsk" />
        <Button v-if="canCreate" :label="pt('Record')" icon="pi pi-plus" outlined @click="openRecord" />
      </template>
    </PageHeader>

    <!-- The score, first. -->
    <div class="stat-row">
      <div class="stat big" :class="{ good: Number(summary.csat_percent) >= 80, bad: summary.csat_percent !== null && Number(summary.csat_percent) < 60 }">
        <span>{{ pt('CSAT') }}</span>
        <strong>{{ summary.csat_percent === null ? '—' : summary.csat_percent + '%' }}</strong>
        <small class="muted">{{ pt('answers rated 4 or 5') }}</small>
      </div>
      <div class="stat">
        <span>{{ pt('Average rating') }}</span>
        <strong>{{ summary.average_rating ?? '—' }}</strong>
      </div>
      <div class="stat">
        <span>{{ pt('Answers') }}</span>
        <strong>{{ summary.responses }}</strong>
      </div>
      <div class="stat" :class="{ warn: Number(summary.needs_attention) > 0 }">
        <span>{{ pt('Unhappy, unanswered') }}</span>
        <strong>{{ summary.needs_attention }}</strong>
      </div>
      <div class="stat">
        <span>{{ pt('Awaiting answer') }}</span>
        <strong>{{ summary.pending }}</strong>
      </div>
    </div>

    <div class="panels">
      <!-- The shape behind the average. -->
      <div class="panel">
        <h3>{{ pt('How the answers spread') }}</h3>
        <div v-for="s in spread" :key="s.rating" class="bar-row">
          <span class="bar-face">{{ FACE[s.rating] }}</span>
          <div class="bar"><div class="bar-fill" :class="'r' + s.rating" :style="{ width: s.percent + '%' }" /></div>
          <span class="bar-n">{{ s.count }}</span>
        </div>
        <p v-if="!Number(summary.responses)" class="muted small">{{ pt('No answers yet.') }}</p>
      </div>

      <!-- What it is about. The only screen in the app that answers "why". -->
      <div class="panel">
        <h3>{{ pt('What they mention') }}</h3>
        <div v-if="topTags.length" class="tag-cloud">
          <span v-for="[tag, count] in topTags" :key="tag" class="tag-chip">
            {{ tag }} <b>{{ count }}</b>
          </span>
        </div>
        <p v-else class="muted small">{{ pt('Nothing tagged yet — customers pick these on the feedback page.') }}</p>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search customer, bill or comment')" class="t-search" @keyup.enter="applyFilters" />
      <Select v-model="statusFilter" :options="STATUSES" optionLabel="label" optionValue="value" :placeholder="pt('Any status')" showClear class="t-sel" @change="applyFilters" />
      <Select v-model="ratingFilter" :options="RATINGS" optionLabel="label" optionValue="value" :placeholder="pt('Any rating')" showClear class="t-sel" @change="applyFilters" />
      <Select v-model="channelFilter" :options="CHANNELS" optionLabel="label" optionValue="value" :placeholder="pt('Any channel')" showClear class="t-sel" @change="applyFilters" />
      <input type="date" v-model="dateFrom" class="date-input" @change="applyFilters" />
      <input type="date" v-model="dateTo" class="date-input" @change="applyFilters" />
      <label class="chk">
        <Checkbox v-model="attentionOnly" binary @change="applyFilters" />
        {{ pt('Needs attention') }}
      </label>
      <label class="chk">
        <Checkbox v-model="commentsOnly" binary @change="applyFilters" />
        {{ pt('With a comment') }}
      </label>
      <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="applyFilters" />
      <TableExportButtons
        title="Customer Feedback"
        filenameBase="customer-feedback"
        :rows="rows"
        :columns="[
          { key: 'created_at', label: 'Received' },
          { key: 'customer', label: 'Customer' },
          { key: 'invoice_number', label: 'Bill' },
          { key: 'rating', label: 'Rating' },
          { key: 'comment', label: 'Comment' },
          { key: 'channel', label: 'Channel' },
          { key: 'status', label: 'Status' },
          { key: 'response', label: 'Reply' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :rowsPerPageOptions="[25, 50, 100]"
      dataKey="id"
      class="p-datatable-sm"
      responsiveLayout="scroll"
      @page="onPage"
    >
      <Column :header="pt('Rating')" style="width: 8rem">
        <template #body="{ data }">
          <template v-if="data.rating">
            <span class="face-inline">{{ FACE[data.rating] }}</span>
            <Tag :value="String(data.rating)" :severity="ratingSeverity(data.rating)" />
          </template>
          <small v-else class="muted">{{ pt('Awaiting') }}</small>
        </template>
      </Column>
      <Column :header="pt('Customer')">
        <template #body="{ data }">
          <div>{{ data.customer || pt('Walk-in') }}</div>
          <small v-if="data.customer_phone" class="muted">{{ data.customer_phone }}</small>
        </template>
      </Column>
      <Column :header="pt('About')">
        <template #body="{ data }">
          <span v-if="data.invoice_number">{{ data.invoice_number }}</span>
          <span v-else-if="data.job_number">{{ data.job_number }}</span>
          <span v-else class="muted">{{ pt('General') }}</span>
        </template>
      </Column>
      <Column :header="pt('What they said')">
        <template #body="{ data }">
          <div v-if="data.comment" class="cmt-cell">{{ data.comment }}</div>
          <span v-else class="muted">—</span>
          <div v-if="data.tags?.length" class="row-tags">
            <span v-for="t in data.tags" :key="t" class="row-tag">{{ t }}</span>
          </div>
        </template>
      </Column>
      <Column :header="pt('Channel')" style="width: 8rem">
        <template #body="{ data }"><small class="muted">{{ labelOf(CHANNELS, data.channel) }}</small></template>
      </Column>
      <Column :header="pt('Status')" style="width: 10rem">
        <template #body="{ data }">
          <Tag :value="labelOf(STATUSES, data.status)" :severity="statusSeverity(data.status)" />
          <div v-if="data.responded_by" class="muted small">{{ pt('by') }} {{ data.responded_by }}</div>
        </template>
      </Column>
      <Column style="width: 12rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit && data.submitted_at"
            :label="data.response ? pt('Reply') : pt('Reply')"
            size="small"
            outlined
            @click="openReply(data)"
          />
          <!-- Chasing an outstanding ask, three doors: SMS again, hand it to
               WhatsApp, or copy the link to paste anywhere. -->
          <Button
            v-if="canCreate && !data.submitted_at"
            icon="pi pi-refresh"
            text
            rounded
            size="small"
            v-tooltip.top="pt('Send the link again by SMS')"
            @click="resend(data)"
          />
          <Button
            v-if="canCreate && !data.submitted_at && data.customer_phone"
            icon="pi pi-whatsapp"
            text
            rounded
            size="small"
            severity="success"
            v-tooltip.top="pt('Ask on WhatsApp')"
            @click="sendWhatsapp(data)"
          />
          <Button
            v-if="data.link"
            icon="pi pi-copy"
            text
            rounded
            size="small"
            v-tooltip.top="pt('Copy the link')"
            @click="copyLink(data)"
          />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>{{ pt('No feedback yet. Ask a few recent customers how it went.') }}</template>
    </DataTable>

    <!-- ============ Record what was said ============ -->
    <Dialog
      v-model:visible="recordDialog"
      :header="editingId ? pt('Edit Feedback') : pt('Record Feedback')"
      modal
      :style="{ width: '38rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Customer') }}</label>
          <Select v-model="recordForm.customer_id" :options="customers" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Walk-in')" />
        </div>
        <div class="fld">
          <label>{{ pt('Or their name') }}</label>
          <InputText v-model="recordForm.customer_name" :placeholder="pt('For a walk-in')" />
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="recordForm.customer_phone" />
        </div>
        <div class="fld">
          <label>{{ pt('How it reached you') }}</label>
          <Select v-model="recordForm.channel" :options="CHANNELS" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld wide">
          <label>{{ pt('Rating') }} *</label>
          <div class="face-pick">
            <button
              v-for="r in [1, 2, 3, 4, 5]"
              :key="r"
              type="button"
              class="face-btn"
              :class="{ on: recordForm.rating === r }"
              @click="recordForm.rating = r"
            >
              {{ FACE[r] }}
            </button>
          </div>
          <small v-if="recordErrors.rating" class="err">{{ recordErrors.rating[0] }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('What they said') }}</label>
          <Textarea v-model="recordForm.comment" rows="3" />
        </div>
        <div class="fld wide">
          <label>{{ pt('What it was about') }}</label>
          <div class="chips">
            <button
              v-for="t in TAG_OPTIONS"
              :key="t"
              type="button"
              class="chip"
              :class="{ on: recordForm.tags.includes(t) }"
              @click="toggleFormTag(t)"
            >
              {{ t }}
            </button>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="recordDialog = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="recordSaving" @click="saveRecord" />
      </template>
    </Dialog>

    <!-- ============ Ask customers ============ -->
    <Dialog v-model:visible="askDialog" :header="pt('Ask Customers for Feedback')" modal :style="{ width: '50rem' }">
      <div class="ask-bar">
        <label>
          {{ pt('Bills from the last') }}
          <select v-model.number="askDays" class="days" @change="loadAskable">
            <option :value="3">3</option>
            <option :value="7">7</option>
            <option :value="14">14</option>
            <option :value="30">30</option>
          </select>
          {{ pt('days') }}
        </label>
        <label class="chk">
          <Checkbox v-model="askSendSms" binary />
          {{ pt('Send the link by SMS now') }}
        </label>
      </div>
      <p class="hint">
        {{ pt('Only bills with a phone number and no request already sent are listed. One request per bill — a customer can answer once.') }}
      </p>

      <DataTable
        v-model:selection="askSelected"
        :value="askRows"
        :loading="askLoading"
        dataKey="id"
        class="p-datatable-sm"
        scrollable
        scrollHeight="22rem"
      >
        <Column selectionMode="multiple" style="width: 3rem" />
        <Column field="invoice_number" :header="pt('Bill')" />
        <Column field="invoice_date" :header="pt('Date')" />
        <Column field="customer" :header="pt('Customer')" />
        <Column field="phone" :header="pt('Phone')" />
        <Column :header="pt('Value')">
          <template #body="{ data }">₹{{ Number(data.total).toLocaleString('en-IN') }}</template>
        </Column>
        <template #empty>{{ pt('No bills to ask about in this window.') }}</template>
      </DataTable>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="askDialog = false" />
        <Button
          :label="pt('Ask {n} customer(s)', { n: askSelected.length })"
          icon="pi pi-send"
          :disabled="!askSelected.length"
          :loading="askSending"
          @click="sendAsks"
        />
      </template>
    </Dialog>

    <!-- ============ Reply ============ -->
    <Dialog v-model:visible="replyDialog" :header="pt('Reply to Customer')" modal :style="{ width: '36rem' }">
      <div v-if="replyRow" class="reply-head">
        <span class="face-inline">{{ FACE[replyRow.rating] }}</span>
        <div>
          <strong>{{ replyRow.customer || pt('Walk-in') }}</strong>
          <div class="muted small">{{ replyRow.comment || pt('No comment left.') }}</div>
        </div>
      </div>

      <div class="grid">
        <div class="fld wide">
          <label>{{ pt('Your reply') }} *</label>
          <Textarea v-model="replyForm.response" rows="4" :placeholder="pt('What you told the customer, and what you are doing about it.')" />
        </div>
        <div class="fld wide">
          <label>{{ pt('Mark as') }}</label>
          <div class="radio-row">
            <label><input type="radio" value="acknowledged" v-model="replyForm.status" /> {{ pt('Acknowledged — we have read it') }}</label>
            <label><input type="radio" value="resolved" v-model="replyForm.status" /> {{ pt('Resolved — we have dealt with it') }}</label>
          </div>
          <small class="hint">{{ pt('Kept separate on purpose: reading a complaint and fixing it are different promises.') }}</small>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="replyDialog = false" />
        <Button :label="pt('Save Reply')" icon="pi pi-check" :loading="replySaving" @click="saveReply" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.stat {
  border: 1px solid var(--border, #e8e4da); border-radius: 10px;
  padding: 0.6rem 1rem; display: flex; flex-direction: column; gap: 0.15rem; min-width: 150px;
}
.stat span { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.stat strong { font-size: 1.15rem; }
.stat.big strong { font-size: 1.7rem; }
.stat.good { border-color: #86c19a; background: rgba(134, 193, 154, 0.12); }
.stat.bad { border-color: #f0b4b4; background: #fdf1f1; }
.stat.warn { border-color: #e8c07d; background: rgba(232, 192, 125, 0.12); }

.panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.75rem; margin-bottom: 1.1rem; }
.panel { border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.85rem 1rem; }
.panel h3 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin: 0 0 0.65rem; }
.bar-row { display: flex; align-items: center; gap: 0.55rem; margin-bottom: 0.35rem; }
.bar-face { font-size: 1rem; width: 1.4rem; }
.bar { flex: 1; height: 8px; background: #f1efe9; border-radius: 999px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 999px; background: #9ca3af; }
.bar-fill.r5, .bar-fill.r4 { background: #86c19a; }
.bar-fill.r3 { background: #e8c07d; }
.bar-fill.r2, .bar-fill.r1 { background: #d98d8d; }
.bar-n { font-size: 0.75rem; color: #6b7280; min-width: 2rem; text-align: right; }

.tag-cloud { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.tag-chip {
  border: 1px solid var(--border, #e8e4da); border-radius: 999px;
  padding: 0.25rem 0.65rem; font-size: 0.8rem; color: #374151;
}
.tag-chip b { color: #111827; }

.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.t-search { width: 240px; }
.t-sel { width: 165px; }
.date-input { padding: 0.5rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: 0.85rem; }
.chk { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: #374151; white-space: nowrap; }

.face-inline { font-size: 1.15rem; margin-right: 0.35rem; }
.cmt-cell { max-width: 26rem; white-space: normal; }
.row-tags { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.2rem; }
.row-tag { font-size: 0.68rem; color: #4b5563; background: #f4f2ec; border-radius: 999px; padding: 0.05rem 0.45rem; }

.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.hint { font-size: 0.75rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
.small { font-size: 0.72rem; }

.face-pick { display: flex; gap: 0.4rem; }
.face-btn {
  background: none; border: 1px solid var(--border, #e8e4da); border-radius: 10px;
  font-size: 1.5rem; line-height: 1; padding: 0.4rem 0.6rem; cursor: pointer;
}
.face-btn.on { border-color: var(--safety-amber, #e8a33d); background: rgba(232, 163, 61, 0.14); }

.chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.chip {
  background: none; border: 1px solid var(--border, #e8e4da); border-radius: 999px;
  padding: 0.3rem 0.7rem; font-size: 0.8rem; color: #374151; cursor: pointer;
}
.chip.on { border-color: var(--ink-blue, #2c4a5e); background: rgba(44, 74, 94, 0.1); font-weight: 600; }

.ask-bar { display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.4rem; font-size: 0.85rem; }
.days { padding: 0.25rem 0.4rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

.reply-head {
  display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.9rem;
  border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.6rem 0.85rem;
}
.radio-row { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.82rem; color: #374151; }

@media (max-width: 640px) {
  .grid { grid-template-columns: 1fr; }
}
</style>
