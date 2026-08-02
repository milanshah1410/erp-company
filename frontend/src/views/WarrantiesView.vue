<script setup lang="ts">
/**
 * Warranty cards and the claims made against them.
 *
 * One component, two routes (/warranties and /warranties/claims), because the
 * two halves are one book to whoever keeps it and splitting them into separate
 * files would have meant two copies of the same lookup, the same customer
 * column and the same status vocabulary.
 *
 * The register leads with the counter question rather than with a table. Someone
 * is standing there holding a six-month-old shoe, and the first thing this screen
 * has to do is answer "is this covered?" — from a bill number, a phone number or
 * a card number, whichever they brought. Everything below that is the book.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
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
const route = useRoute()
const router = useRouter()
const pt = usePt()

const canIssue = computed(() => auth.can('warranties.create'))
const canEditCard = computed(() => auth.can('warranties.edit'))
const canVoid = computed(() => auth.can('warranties.delete'))
const canClaim = computed(() => auth.can('warranty-claims.create'))
const canResolve = computed(() => auth.can('warranty-claims.approve'))

/** Which half of the book is open. Driven by the route so both menu rows land right. */
const tab = computed<'cards' | 'claims'>(() => (route.path.endsWith('/claims') ? 'claims' : 'cards'))

function goTo(next: 'cards' | 'claims') {
  router.push(next === 'claims' ? '/warranties/claims' : '/warranties')
}

const SOURCES = [
  { label: 'Manufacturer', value: 'manufacturer' },
  { label: 'Shop', value: 'shop' },
  { label: 'Extended plan', value: 'extended' },
]

const CARD_STATUSES = [
  { label: 'Active', value: 'active' },
  { label: 'Expired', value: 'expired' },
  { label: 'Claimed', value: 'claimed' },
  { label: 'Void', value: 'void' },
]

const CLAIM_STATUSES = [
  { label: 'Open', value: 'open' },
  { label: 'Assessed', value: 'assessed' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Resolved', value: 'resolved' },
]

const RESOLUTIONS = [
  { label: 'Repaired', value: 'repair' },
  { label: 'Replaced', value: 'replace' },
  { label: 'Refunded', value: 'refund' },
  { label: 'Rejected', value: 'rejected' },
]

// ---- Shared helpers -------------------------------------------------------

function iso(d: Date | null) {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function parseDate(value: string | null) {
  return value ? new Date(value + 'T00:00:00') : null
}

function labelOf(list: { label: string; value: string }[], value: string | null) {
  return list.find((o) => o.value === value)?.label ?? value ?? '—'
}

/**
 * Colour by whether cover is actually live, not by the stored status: a row sits
 * at 'active' while its end date quietly passes, and a green tag on expired
 * cover is how a counter promises something it cannot honour.
 */
function cardSeverity(row: any) {
  if (row.status === 'void') return 'secondary'
  if (row.status === 'claimed') return 'info'
  return row.is_live ? 'success' : 'danger'
}

function cardStateText(row: any) {
  if (row.status === 'void') return pt('Void')
  if (row.status === 'claimed') return pt('Claimed')
  if (row.is_live) return pt('{n} days left', { n: row.days_remaining })
  return pt('Expired')
}

function claimSeverity(status: string) {
  switch (status) {
    case 'resolved':
      return 'success'
    case 'rejected':
      return 'danger'
    case 'approved':
      return 'info'
    case 'assessed':
      return 'warn'
    default:
      return 'secondary'
  }
}

// ---- The counter lookup ---------------------------------------------------

const lookup = ref('')
const lookupRows = ref<any[]>([])
const lookupDone = ref(false)
const lookupLoading = ref(false)

/**
 * One box, three kinds of input. A customer arrives with a bill, a phone number
 * or (rarely) the card itself, and making them pick which one they have before
 * they can search would be a form standing between a shop and its customer.
 * Digits long enough to be a phone number go to the customer search; anything
 * else is tried as a card number and then as a serial.
 */
async function runLookup() {
  const term = lookup.value.trim()
  if (!term) return

  lookupLoading.value = true
  lookupDone.value = false
  try {
    // The endpoint takes exact keys, so the free-text box resolves to one first.
    const params: Record<string, string> = /^[A-Za-z]{1,4}-?\d+$/.test(term)
      ? { warranty_number: term }
      : { serial_no: term }

    const { data } = await http.get('/warranties/check', { params })
    lookupRows.value = data.data ?? []

    // Nothing on the card/serial reading — fall back to the register search,
    // which also matches bill numbers, customer names and phone numbers.
    if (!lookupRows.value.length) {
      const { data: fallback } = await http.get('/warranties', { params: { search: term, per_page: 25 } })
      lookupRows.value = fallback.data ?? []
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Lookup failed'), life: 4000 })
    lookupRows.value = []
  } finally {
    lookupDone.value = true
    lookupLoading.value = false
  }
}

function clearLookup() {
  lookup.value = ''
  lookupRows.value = []
  lookupDone.value = false
}

// ---- Cards ----------------------------------------------------------------

const cards = ref<any[]>([])
const cardSummary = ref<any>({ registered: 0, active: 0, expiring_30d: 0, claims: 0 })
const cardsLoading = ref(false)
const cardSearch = ref('')
const cardStatus = ref<string | null>(null)
const cardSource = ref<string | null>(null)
const expiringOnly = ref(false)

async function loadCards() {
  cardsLoading.value = true
  try {
    const { data } = await http.get('/warranties', {
      params: {
        search: cardSearch.value || undefined,
        status: cardStatus.value || undefined,
        source: cardSource.value || undefined,
        expiring: expiringOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    cards.value = data.data ?? []
    cardSummary.value = data.summary ?? cardSummary.value
  } finally {
    cardsLoading.value = false
  }
}

// ---- Issue from a bill ----------------------------------------------------

const issueVisible = ref(false)
const issueInvoiceNo = ref('')
const issueInvoice = ref<any>(null)
const issueLines = ref<any[]>([])
const issueLoading = ref(false)
const issueSaving = ref(false)

function openIssue() {
  issueInvoiceNo.value = ''
  issueInvoice.value = null
  issueLines.value = []
  issueVisible.value = true
}

/**
 * Resolve the bill number the customer is holding to an invoice, then ask what
 * on it can be registered. Two calls rather than one endpoint that takes a
 * number, because the invoice search already exists and duplicating its matching
 * rules inside the warranty controller would make them drift.
 */
async function findInvoice() {
  const term = issueInvoiceNo.value.trim()
  if (!term) return

  issueLoading.value = true
  try {
    const { data } = await http.get('/invoices', { params: { search: term, per_page: 5 } })
    const invoice = (data.data ?? [])[0]
    if (!invoice) {
      toast.add({ severity: 'warn', summary: pt('No bill matches that number'), life: 3500 })
      issueInvoice.value = null
      issueLines.value = []
      return
    }

    const { data: issuable } = await http.get('/warranties/issuable', { params: { invoice_id: invoice.id } })
    issueInvoice.value = issuable.invoice
    issueLines.value = (issuable.data ?? []).map((line: any) => ({
      ...line,
      // Pre-ticked only where the article actually carries a policy and nothing
      // has been registered yet — the common case is "register what the shop
      // promised", and everything else is a deliberate override.
      selected: line.has_policy && !line.already_issued,
      months: line.warranty_months ?? 6,
      source: 'manufacturer',
      serial_no: line.serials?.[0] ?? null,
    }))
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not read that bill'), life: 4000 })
  } finally {
    issueLoading.value = false
  }
}

const issueSelected = computed(() => issueLines.value.filter((l) => l.selected))

async function issueCards() {
  if (!issueInvoice.value || !issueSelected.value.length) return

  issueSaving.value = true
  try {
    const { data } = await http.post('/warranties/issue', {
      invoice_id: issueInvoice.value.id,
      lines: issueSelected.value.map((l) => ({
        product_id: l.product_id,
        variant_id: l.variant_id,
        serial_no: l.serial_no || null,
        coverage_months: l.months,
        source: l.source,
      })),
    })
    toast.add({ severity: 'success', summary: data.message, life: 3500 })
    issueVisible.value = false
    await loadCards()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not register the cover'), life: 4000 })
  } finally {
    issueSaving.value = false
  }
}

// ---- Card editor (manual issue / correction) ------------------------------

const cardDialog = ref(false)
const cardSaving = ref(false)
const cardEditingId = ref<number | null>(null)
const cardErrors = ref<Record<string, string[]>>({})
const products = ref<any[]>([])
const customers = ref<any[]>([])

const blankCard = () => ({
  customer_id: null as number | null,
  product_id: null as number | null,
  serial_no: '',
  source: 'manufacturer',
  start_date: new Date() as Date | null,
  coverage_months: 6,
  end_date: null as Date | null,
  /**
   * What the card pays for, and where. Defaults are what a card has always
   * meant — parts and labour, carry-in — so a shop that never thinks about the
   * distinction writes the same cards it wrote before.
   *
   * It stops being invisible in the trades where a claim costs labour as well
   * as parts: an appliance dealer's "3 years" is usually parts and labour in
   * year one and the panel only afterwards, and a card that cannot say so hands
   * out a service call the maker never promised to pay for.
   */
  covers_parts: true,
  covers_labour: true,
  is_onsite: false,
  terms: '',
  notes: '',
})

const cardForm = ref(blankCard())

/** Keep the end date in step with the term while the user is choosing one. */
watch(
  () => [cardForm.value.start_date, cardForm.value.coverage_months],
  ([start, months]) => {
    if (!start || !months) return
    const end = new Date(start as Date)
    end.setMonth(end.getMonth() + Number(months))
    cardForm.value.end_date = end
  },
)

async function loadPickers() {
  if (products.value.length) return
  try {
    const [p, c] = await Promise.all([
      http.get('/products', { params: { per_page: 200 } }),
      http.get('/customers', { params: { per_page: 200 } }),
    ])
    products.value = p.data.data ?? []
    customers.value = c.data.data ?? []
  } catch {
    // A picker that failed to load is not a reason to block the dialog; the
    // fields simply stay empty and the counter can still search from the list.
  }
}

async function openCardNew() {
  cardEditingId.value = null
  cardErrors.value = {}
  cardForm.value = blankCard()
  cardDialog.value = true
  await loadPickers()
}

async function openCardEdit(row: any) {
  cardEditingId.value = row.id
  cardErrors.value = {}
  cardForm.value = {
    customer_id: row.customer_id,
    product_id: row.product_id,
    serial_no: row.serial_no ?? '',
    source: row.source,
    start_date: parseDate(row.start_date),
    coverage_months: row.coverage_months ?? 6,
    end_date: parseDate(row.end_date),
    covers_parts: row.covers_parts !== false,
    covers_labour: row.covers_labour !== false,
    is_onsite: !!row.is_onsite,
    terms: row.terms ?? '',
    notes: row.notes ?? '',
  }
  cardDialog.value = true
  await loadPickers()
}

async function saveCard() {
  cardSaving.value = true
  cardErrors.value = {}
  const payload = {
    ...cardForm.value,
    start_date: iso(cardForm.value.start_date),
    end_date: iso(cardForm.value.end_date),
  }
  try {
    if (cardEditingId.value) {
      await http.put(`/warranties/${cardEditingId.value}`, payload)
      toast.add({ severity: 'success', summary: pt('Warranty updated'), life: 2500 })
    } else {
      await http.post('/warranties', payload)
      toast.add({ severity: 'success', summary: pt('Warranty registered'), life: 2500 })
    }
    cardDialog.value = false
    await loadCards()
  } catch (e: any) {
    cardErrors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not save the warranty'), life: 4000 })
  } finally {
    cardSaving.value = false
  }
}

async function voidCard(row: any) {
  const ok = await Swal.fire({
    title: pt('Cancel this cover?'),
    text: pt('The card stays on the register marked void, so the customer can still be shown what happened.'),
    icon: 'warning',
    input: 'text',
    inputPlaceholder: pt('Reason (optional)'),
    showCancelButton: true,
    confirmButtonText: pt('Void'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await http.post(`/warranties/${row.id}/void`, { reason: ok.value || undefined })
    toast.add({ severity: 'success', summary: pt('Cover voided'), life: 2500 })
    await loadCards()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not void the cover'), life: 4000 })
  }
}

// ---- Claims ---------------------------------------------------------------

const claims = ref<any[]>([])
const claimSummary = ref<any>({ total: 0, open: 0, rejected: 0, cost: 0 })
const claimsLoading = ref(false)
const claimSearch = ref('')
const claimStatus = ref<string | null>(null)
const openOnly = ref(false)

async function loadClaims() {
  claimsLoading.value = true
  try {
    const { data } = await http.get('/warranty-claims', {
      params: {
        search: claimSearch.value || undefined,
        status: claimStatus.value || undefined,
        open: openOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    claims.value = data.data ?? []
    claimSummary.value = data.summary ?? claimSummary.value
  } finally {
    claimsLoading.value = false
  }
}

const claimDialog = ref(false)
const claimSaving = ref(false)
const claimErrors = ref<Record<string, string[]>>({})
const claimCard = ref<any>(null)

const claimForm = ref({
  warranty_id: null as number | null,
  reported_on: new Date() as Date | null,
  fault: '',
})

/** Register a claim against a specific card — always reached from the card. */
function openClaim(card: any) {
  claimCard.value = card
  claimErrors.value = {}
  claimForm.value = { warranty_id: card.id, reported_on: new Date(), fault: '' }
  claimDialog.value = true
}

async function saveClaim() {
  claimSaving.value = true
  claimErrors.value = {}
  try {
    await http.post('/warranty-claims', {
      ...claimForm.value,
      reported_on: iso(claimForm.value.reported_on),
    })
    toast.add({ severity: 'success', summary: pt('Claim registered'), life: 2500 })
    claimDialog.value = false
    await Promise.all([loadCards(), tab.value === 'claims' ? loadClaims() : Promise.resolve()])
  } catch (e: any) {
    claimErrors.value = e.response?.data?.errors ?? {}
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not register the claim'), life: 4000 })
  } finally {
    claimSaving.value = false
  }
}

// ---- Settling a claim -----------------------------------------------------

const resolveDialog = ref(false)
const resolveSaving = ref(false)
const resolveClaim = ref<any>(null)
const resolveForm = ref({
  resolution: 'repair',
  resolution_note: '',
  resolved_on: new Date() as Date | null,
  cost: 0,
})

function openResolve(claim: any) {
  resolveClaim.value = claim
  resolveForm.value = { resolution: 'repair', resolution_note: '', resolved_on: new Date(), cost: 0 }
  resolveDialog.value = true
}

/** Replacing or refunding uses the covered item up, so the card closes with it. */
const resolveConsumes = computed(() => ['replace', 'refund'].includes(resolveForm.value.resolution))

async function submitResolve() {
  if (!resolveClaim.value) return
  resolveSaving.value = true
  try {
    await http.post(`/warranty-claims/${resolveClaim.value.id}/resolve`, {
      ...resolveForm.value,
      resolved_on: iso(resolveForm.value.resolved_on),
    })
    toast.add({ severity: 'success', summary: pt('Claim settled'), life: 2500 })
    resolveDialog.value = false
    await loadClaims()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not settle the claim'), life: 4000 })
  } finally {
    resolveSaving.value = false
  }
}

function load() {
  return tab.value === 'claims' ? loadClaims() : loadCards()
}

watch(tab, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Warranty')"
      :subtitle="pt('Cover issued against a sale, and what came back against it')"
    >
      <template #actions>
        <Button
          v-if="tab === 'cards' && canIssue"
          :label="pt('Issue from Bill')"
          icon="pi pi-receipt"
          @click="openIssue"
        />
        <Button
          v-if="tab === 'cards' && canIssue"
          :label="pt('Register Cover')"
          icon="pi pi-plus"
          outlined
          @click="openCardNew"
        />
      </template>
    </PageHeader>

    <div class="tabs">
      <button :class="{ on: tab === 'cards' }" @click="goTo('cards')">
        <i class="pi pi-id-card" /> {{ pt('Warranty Cards') }}
      </button>
      <button :class="{ on: tab === 'claims' }" @click="goTo('claims')">
        <i class="pi pi-replay" /> {{ pt('Claims') }}
        <span v-if="claimSummary.open" class="badge">{{ claimSummary.open }}</span>
      </button>
    </div>

    <!-- ================= Cards ================= -->
    <template v-if="tab === 'cards'">
      <!-- The counter question, above the book. -->
      <div class="lookup">
        <i class="pi pi-search" />
        <InputText
          v-model="lookup"
          :placeholder="pt('Bill number, card number, serial, phone or customer name')"
          class="lk-input"
          @keyup.enter="runLookup"
        />
        <Button :label="pt('Is it covered?')" :loading="lookupLoading" @click="runLookup" />
        <Button v-if="lookupDone" icon="pi pi-times" text @click="clearLookup" />
      </div>

      <div v-if="lookupDone" class="lk-result">
        <div v-if="!lookupRows.length" class="lk-none">
          <i class="pi pi-info-circle" />
          {{ pt('Nothing on the register matches that. The sale may never have been registered — issue cover from the bill.') }}
        </div>
        <div v-for="r in lookupRows" :key="r.id" class="lk-row" :class="{ live: r.is_live }">
          <div class="lk-main">
            <strong>{{ r.item }}</strong>
            <small class="muted">{{ r.warranty_number }} · {{ r.customer ?? pt('Walk-in') }}</small>
          </div>
          <div class="lk-verdict">
            <Tag :value="r.is_live ? pt('Covered') : pt('Not covered')" :severity="r.is_live ? 'success' : 'danger'" />
            <small class="muted">
              {{ r.is_live ? pt('{n} days left', { n: r.days_remaining }) : r.reason }}
            </small>
          </div>
          <Button
            v-if="r.is_live && canClaim"
            :label="pt('Register claim')"
            size="small"
            outlined
            @click="openClaim(r)"
          />
        </div>
      </div>

      <div class="stat-row">
        <div class="stat"><span>{{ pt('Cards registered') }}</span><strong>{{ cardSummary.registered }}</strong></div>
        <div class="stat"><span>{{ pt('In force') }}</span><strong>{{ cardSummary.active }}</strong></div>
        <div class="stat warn"><span>{{ pt('Expiring in 30 days') }}</span><strong>{{ cardSummary.expiring_30d }}</strong></div>
        <div class="stat"><span>{{ pt('Claims made') }}</span><strong>{{ cardSummary.claims }}</strong></div>
      </div>

      <div class="toolbar">
        <InputText v-model="cardSearch" :placeholder="pt('Search card, item, customer or bill')" class="t-search" @keyup.enter="loadCards" />
        <Select v-model="cardStatus" :options="CARD_STATUSES" optionLabel="label" optionValue="value" :placeholder="pt('All statuses')" showClear class="t-sel" @change="loadCards" />
        <Select v-model="cardSource" :options="SOURCES" optionLabel="label" optionValue="value" :placeholder="pt('Any provider')" showClear class="t-sel" @change="loadCards" />
        <label class="chk">
          <Checkbox v-model="expiringOnly" binary @change="loadCards" />
          {{ pt('Expiring soon') }}
        </label>
        <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="loadCards" />
        <TableExportButtons
          title="Warranty Cards"
          filenameBase="warranty-cards"
          :rows="cards"
          :columns="[
            { key: 'warranty_number', label: 'Card No' },
            { key: 'item', label: 'Item' },
            { key: 'customer', label: 'Customer' },
            { key: 'invoice_number', label: 'Bill' },
            { key: 'start_date', label: 'From' },
            { key: 'end_date', label: 'To' },
            { key: 'source', label: 'Provider' },
            { key: 'status', label: 'Status' },
          ]"
        />
      </div>

      <DataTable :value="cards" :loading="cardsLoading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
        <Column field="warranty_number" :header="pt('Card No')" />
        <Column :header="pt('Item')">
          <template #body="{ data }">
            <strong>{{ data.product ?? '—' }}</strong>
            <small v-if="data.variant" class="muted"> · {{ data.variant }}</small>
            <div v-if="data.serial_no" class="muted small">#{{ data.serial_no }}</div>
          </template>
        </Column>
        <Column :header="pt('Customer')">
          <template #body="{ data }">
            <div>{{ data.customer ?? pt('Walk-in') }}</div>
            <small v-if="data.customer_phone" class="muted">{{ data.customer_phone }}</small>
          </template>
        </Column>
        <Column field="invoice_number" :header="pt('Bill')" />
        <Column :header="pt('Cover')">
          <template #body="{ data }">
            <div>{{ data.start_date }} → {{ data.end_date }}</div>
            <small class="muted">
              {{ data.coverage_months ? pt('{n} months', { n: data.coverage_months }) : '—' }}
              · {{ labelOf(SOURCES, data.source) }}
            </small>
            <!--
              Only shown when the cover is narrower than parts-and-labour or is
              attended onsite. A shoe shop never writes either, so its rows stay
              exactly as they were; the shops that do write them need the scope
              visible before they promise anyone a free visit.
            -->
            <div v-if="!data.covers_parts || !data.covers_labour || data.is_onsite" class="muted small">
              {{ pt(data.coverage) }}<template v-if="data.is_onsite"> · {{ pt('onsite') }}</template>
            </div>
          </template>
        </Column>
        <Column :header="pt('Status')">
          <template #body="{ data }">
            <Tag :value="cardStateText(data)" :severity="cardSeverity(data)" />
            <small v-if="data.claims_count" class="muted"> · {{ pt('{n} claim(s)', { n: data.claims_count }) }}</small>
          </template>
        </Column>
        <Column style="width: 9rem">
          <template #body="{ data }">
            <Button
              v-if="canClaim && data.status !== 'claimed' && data.status !== 'void'"
              icon="pi pi-replay"
              text
              size="small"
              v-tooltip.top="pt('Register a claim')"
              @click="openClaim(data)"
            />
            <Button v-if="canEditCard" icon="pi pi-pencil" text size="small" @click="openCardEdit(data)" />
            <Button
              v-if="canVoid && data.status !== 'void'"
              icon="pi pi-ban"
              text
              size="small"
              severity="danger"
              v-tooltip.top="pt('Void this cover')"
              @click="voidCard(data)"
            />
          </template>
        </Column>
        <template #empty>{{ pt('No warranty cards registered yet.') }}</template>
      </DataTable>
    </template>

    <!-- ================= Claims ================= -->
    <template v-else>
      <div class="stat-row">
        <div class="stat"><span>{{ pt('Claims') }}</span><strong>{{ claimSummary.total }}</strong></div>
        <div class="stat warn"><span>{{ pt('Still open') }}</span><strong>{{ claimSummary.open }}</strong></div>
        <div class="stat"><span>{{ pt('Rejected') }}</span><strong>{{ claimSummary.rejected }}</strong></div>
        <!-- The number that turns this register from paperwork into a buying
             decision: what honouring warranties has cost the shop. -->
        <div class="stat"><span>{{ pt('Cost of claims') }}</span><strong>₹{{ Number(claimSummary.cost).toLocaleString('en-IN') }}</strong></div>
      </div>

      <div class="toolbar">
        <InputText v-model="claimSearch" :placeholder="pt('Search claim, card, fault or customer')" class="t-search" @keyup.enter="loadClaims" />
        <Select v-model="claimStatus" :options="CLAIM_STATUSES" optionLabel="label" optionValue="value" :placeholder="pt('All statuses')" showClear class="t-sel" @change="loadClaims" />
        <label class="chk">
          <Checkbox v-model="openOnly" binary @change="loadClaims" />
          {{ pt('Open only') }}
        </label>
        <Button icon="pi pi-search" :label="pt('Filter')" outlined @click="loadClaims" />
        <TableExportButtons
          title="Warranty Claims"
          filenameBase="warranty-claims"
          :rows="claims"
          :columns="[
            { key: 'claim_number', label: 'Claim No' },
            { key: 'warranty_number', label: 'Card No' },
            { key: 'item', label: 'Item' },
            { key: 'customer', label: 'Customer' },
            { key: 'reported_on', label: 'Reported' },
            { key: 'status', label: 'Status' },
            { key: 'resolution', label: 'Outcome' },
            { key: 'cost', label: 'Cost' },
          ]"
        />
      </div>

      <DataTable :value="claims" :loading="claimsLoading" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
        <Column field="claim_number" :header="pt('Claim No')" />
        <Column :header="pt('Item')">
          <template #body="{ data }">
            <strong>{{ data.item ?? '—' }}</strong>
            <div class="muted small">{{ data.warranty_number }}</div>
          </template>
        </Column>
        <Column field="customer" :header="pt('Customer')" />
        <Column :header="pt('Reported')">
          <template #body="{ data }">
            <div>{{ data.reported_on }}</div>
            <!-- Judged against the report date, not today: a claim brought in
                 during the last week of cover is a covered claim however long
                 the shop then took to settle it. -->
            <small v-if="data.covered_at_report === false" class="warn-text">
              {{ pt('Out of warranty on that date') }}
            </small>
          </template>
        </Column>
        <Column :header="pt('Fault')">
          <template #body="{ data }"><span class="fault">{{ data.fault }}</span></template>
        </Column>
        <Column :header="pt('Status')">
          <template #body="{ data }">
            <Tag :value="labelOf(CLAIM_STATUSES, data.status)" :severity="claimSeverity(data.status)" />
            <small v-if="data.resolution" class="muted"> · {{ labelOf(RESOLUTIONS, data.resolution) }}</small>
          </template>
        </Column>
        <Column :header="pt('Cost')">
          <template #body="{ data }">{{ data.cost ? '₹' + Number(data.cost).toLocaleString('en-IN') : '—' }}</template>
        </Column>
        <Column style="width: 8rem">
          <template #body="{ data }">
            <Button
              v-if="canResolve && data.status !== 'resolved' && data.status !== 'rejected'"
              :label="pt('Settle')"
              size="small"
              outlined
              @click="openResolve(data)"
            />
          </template>
        </Column>
        <template #empty>{{ pt('No claims recorded.') }}</template>
      </DataTable>
    </template>

    <!-- ================= Issue from bill ================= -->
    <Dialog v-model:visible="issueVisible" :header="pt('Issue Warranty from a Bill')" modal :style="{ width: '52rem' }">
      <div class="bill-find">
        <InputText v-model="issueInvoiceNo" :placeholder="pt('Bill number')" @keyup.enter="findInvoice" />
        <Button :label="pt('Find bill')" :loading="issueLoading" @click="findInvoice" />
      </div>

      <div v-if="issueInvoice" class="bill-head">
        <strong>{{ issueInvoice.invoice_number }}</strong>
        <span class="muted">{{ issueInvoice.invoice_date }} · {{ issueInvoice.customer ?? pt('Walk-in') }}</span>
      </div>

      <DataTable v-if="issueLines.length" :value="issueLines" dataKey="product_id" class="p-datatable-sm">
        <Column style="width: 3rem">
          <template #body="{ data }">
            <Checkbox v-model="data.selected" binary :disabled="data.already_issued" />
          </template>
        </Column>
        <Column :header="pt('Item')">
          <template #body="{ data }">
            <strong>{{ data.product }}</strong>
            <small v-if="data.variant" class="muted"> · {{ data.variant }}</small>
            <div v-if="data.already_issued" class="muted small">{{ pt('Already registered') }}</div>
            <div v-else-if="!data.has_policy" class="muted small">{{ pt('No policy on this article — set a term to cover it anyway') }}</div>
          </template>
        </Column>
        <Column :header="pt('Serial')" style="width: 11rem">
          <template #body="{ data }"><InputText v-model="data.serial_no" class="w-full" /></template>
        </Column>
        <Column :header="pt('Months')" style="width: 7rem">
          <template #body="{ data }"><InputNumber v-model="data.months" :min="1" :max="600" class="w-full" /></template>
        </Column>
        <Column :header="pt('Provider')" style="width: 11rem">
          <template #body="{ data }">
            <Select v-model="data.source" :options="SOURCES" optionLabel="label" optionValue="value" class="w-full" />
          </template>
        </Column>
      </DataTable>

      <p v-else-if="issueInvoice" class="muted">{{ pt('Nothing on this bill can be registered.') }}</p>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="issueVisible = false" />
        <Button
          :label="pt('Register {n} card(s)', { n: issueSelected.length })"
          icon="pi pi-check"
          :disabled="!issueSelected.length"
          :loading="issueSaving"
          @click="issueCards"
        />
      </template>
    </Dialog>

    <!-- ================= Card editor ================= -->
    <Dialog
      v-model:visible="cardDialog"
      :header="cardEditingId ? pt('Edit Warranty') : pt('Register Cover')"
      modal
      :style="{ width: '46rem' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Item') }} *</label>
          <Select
            v-model="cardForm.product_id"
            :options="products"
            optionLabel="name"
            optionValue="id"
            filter
            :placeholder="pt('Pick the article')"
          />
          <small v-if="cardErrors.product_id" class="err">{{ cardErrors.product_id[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Customer') }}</label>
          <Select v-model="cardForm.customer_id" :options="customers" optionLabel="name" optionValue="id" filter showClear :placeholder="pt('Walk-in')" />
        </div>
        <div class="fld">
          <label>{{ pt('Serial number') }}</label>
          <InputText v-model="cardForm.serial_no" :placeholder="pt('Where the article carries one')" />
        </div>
        <div class="fld">
          <label>{{ pt('Who honours it') }}</label>
          <Select v-model="cardForm.source" :options="SOURCES" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Covered from') }} *</label>
          <DatePicker v-model="cardForm.start_date" dateFormat="yy-mm-dd" showButtonBar />
          <small v-if="cardErrors.start_date" class="err">{{ cardErrors.start_date[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Term (months)') }}</label>
          <InputNumber v-model="cardForm.coverage_months" :min="0" :max="600" />
          <small class="hint">{{ pt('Sets the end date; override it below if the customer was promised a specific one.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Covered until') }} *</label>
          <DatePicker v-model="cardForm.end_date" dateFormat="yy-mm-dd" showButtonBar />
          <small v-if="cardErrors.end_date" class="err">{{ cardErrors.end_date[0] }}</small>
        </div>
        <!--
          What the card actually pays for. Left at parts-and-labour by default,
          which is what every card written before this existed meant — a shoe
          counter never touches these. An appliance dealer does: "parts only"
          after year one is the difference between a free visit and a charged
          one, and it has to be sayable at the moment the card is written.
        -->
        <div class="fld wide scope">
          <label>{{ pt('What it pays for') }}</label>
          <div class="scope-row">
            <div class="chk">
              <Checkbox v-model="cardForm.covers_parts" inputId="w-parts" binary />
              <label for="w-parts">{{ pt('Parts') }}</label>
            </div>
            <div class="chk">
              <Checkbox v-model="cardForm.covers_labour" inputId="w-labour" binary />
              <label for="w-labour">{{ pt('Labour') }}</label>
            </div>
            <div class="chk">
              <Checkbox v-model="cardForm.is_onsite" inputId="w-onsite" binary />
              <label for="w-onsite">{{ pt('Attended at the customer’s address') }}</label>
            </div>
          </div>
          <small v-if="cardErrors.covers_parts" class="err">{{ cardErrors.covers_parts[0] }}</small>
          <small v-else class="hint">
            {{ pt('Leave both ticked unless the cover is narrower — parts-only cover still leaves the call-out chargeable.') }}
          </small>
        </div>
        <div class="fld wide">
          <label>{{ pt('What the cover promises') }}</label>
          <Textarea v-model="cardForm.terms" rows="2" :placeholder="pt('Manufacturing defects only. Wear and tear excluded.')" />
        </div>
        <div class="fld wide">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="cardForm.notes" rows="2" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="cardDialog = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="cardSaving" @click="saveCard" />
      </template>
    </Dialog>

    <!-- ================= Register a claim ================= -->
    <Dialog v-model:visible="claimDialog" :header="pt('Register a Claim')" modal :style="{ width: '38rem' }">
      <div v-if="claimCard" class="claim-card">
        <strong>{{ claimCard.item }}</strong>
        <span class="muted">{{ claimCard.warranty_number }} · {{ claimCard.customer ?? pt('Walk-in') }}</span>
        <Tag
          :value="claimCard.is_live ? pt('In warranty') : pt('Out of warranty')"
          :severity="claimCard.is_live ? 'success' : 'warn'"
        />
      </div>
      <!-- Recorded either way: a shop that decides to honour cover a week late
           should still have the visit on its books. -->
      <p v-if="claimCard && !claimCard.is_live" class="hint-block">
        {{ pt('This cover is not in force. The claim will still be recorded — settle it as a goodwill decision.') }}
      </p>

      <div class="grid">
        <div class="fld">
          <label>{{ pt('Reported on') }} *</label>
          <DatePicker v-model="claimForm.reported_on" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld wide">
          <label>{{ pt('What went wrong') }} *</label>
          <Textarea v-model="claimForm.fault" rows="3" :placeholder="pt('Sole separated from the upper at the toe.')" />
          <small v-if="claimErrors.fault" class="err">{{ claimErrors.fault[0] }}</small>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="claimDialog = false" />
        <Button :label="pt('Register')" icon="pi pi-check" :loading="claimSaving" @click="saveClaim" />
      </template>
    </Dialog>

    <!-- ================= Settle a claim ================= -->
    <Dialog v-model:visible="resolveDialog" :header="pt('Settle Claim')" modal :style="{ width: '38rem' }">
      <div v-if="resolveClaim" class="claim-card">
        <strong>{{ resolveClaim.item }}</strong>
        <span class="muted">{{ resolveClaim.claim_number }} · {{ resolveClaim.fault }}</span>
      </div>

      <div class="grid">
        <div class="fld">
          <label>{{ pt('Outcome') }} *</label>
          <Select v-model="resolveForm.resolution" :options="RESOLUTIONS" optionLabel="label" optionValue="value" />
        </div>
        <div class="fld">
          <label>{{ pt('Settled on') }}</label>
          <DatePicker v-model="resolveForm.resolved_on" dateFormat="yy-mm-dd" showButtonBar />
        </div>
        <div class="fld">
          <label>{{ pt('What it cost the shop') }}</label>
          <InputNumber v-model="resolveForm.cost" mode="decimal" :minFractionDigits="2" :min="0" />
          <small class="hint">{{ pt('For the shop’s own reckoning — no voucher is posted from here.') }}</small>
        </div>
        <div class="fld wide">
          <label>{{ pt('Note') }}</label>
          <Textarea v-model="resolveForm.resolution_note" rows="2" />
        </div>
      </div>

      <p v-if="resolveConsumes" class="hint-block">
        {{ pt('Replacing or refunding uses the covered item up, so this card closes with the claim. Register fresh cover on the replacement.') }}
      </p>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="resolveDialog = false" />
        <Button :label="pt('Settle')" icon="pi pi-check" :loading="resolveSaving" @click="submitResolve" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 0.4rem; margin-bottom: 1rem; border-bottom: 1px solid var(--surface-border, #e5e7eb); }
.tabs button {
  background: none; border: none; border-bottom: 2px solid transparent;
  padding: 0.55rem 0.9rem; cursor: pointer; font-size: 0.85rem; color: #6b7280;
  display: flex; align-items: center; gap: 0.4rem;
}
.tabs button.on { color: var(--p-primary-color, #4f46e5); border-bottom-color: var(--p-primary-color, #4f46e5); font-weight: 600; }
.badge {
  background: #e8c07d; color: #4a3410; border-radius: 999px;
  font-size: 0.68rem; padding: 0.05rem 0.4rem; font-weight: 700;
}

.lookup { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
.lookup > .pi-search { color: #6b7280; }
.lk-input { flex: 1; max-width: 460px; }
.lk-result { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
.lk-none {
  display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; color: #6b7280;
  border: 1px dashed var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.7rem 0.9rem;
}
.lk-row {
  display: flex; align-items: center; gap: 1rem; justify-content: space-between;
  border: 1px solid #f0b4b4; background: #fdf1f1; border-radius: 10px; padding: 0.6rem 0.9rem;
}
.lk-row.live { border-color: #86c19a; background: rgba(134, 193, 154, 0.12); }
.lk-main, .lk-verdict { display: flex; flex-direction: column; gap: 0.15rem; }
.lk-verdict { align-items: flex-end; }

.stat-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.stat {
  border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px;
  padding: 0.6rem 1rem; display: flex; flex-direction: column; gap: 0.15rem; min-width: 150px;
}
.stat span { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.stat strong { font-size: 1.15rem; }
.stat.warn { border-color: #e8c07d; background: rgba(232, 192, 125, 0.12); }

.toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.t-search { width: 260px; }
.t-sel { width: 180px; }
.chk { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: #374151; }
.scope-row { display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center; }

.bill-find { display: flex; gap: 0.5rem; margin-bottom: 0.85rem; }
.bill-find :deep(input) { flex: 1; }
.bill-head { display: flex; gap: 0.6rem; align-items: baseline; margin-bottom: 0.6rem; }

.claim-card {
  display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.85rem;
  border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.6rem 0.9rem;
}
.hint-block {
  font-size: 0.78rem; color: #4a3410; margin: 0 0 0.85rem;
  border: 1px solid #e8c07d; background: rgba(232, 192, 125, 0.14);
  border-radius: 10px; padding: 0.6rem 0.8rem;
}

.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.wide { grid-column: 1 / -1; }
.fld label { font-size: 0.78rem; color: #374151; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.muted { color: #6b7280; }
.small { font-size: 0.72rem; }
.warn-text { color: #92400e; font-size: 0.72rem; }
.fault { display: inline-block; max-width: 22rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.w-full { width: 100%; }

@media (max-width: 640px) {
  .grid { grid-template-columns: 1fr; }
  .lk-row { flex-direction: column; align-items: flex-start; }
  .lk-verdict { align-items: flex-start; }
}
</style>
