<script setup lang="ts">
/**
 * The gift-voucher register.
 *
 * Built around the question that gets asked at the counter with the customer
 * standing there — "is this card good, and for how much?" — so the check box
 * sits at the top rather than behind a menu, and it reports *why* a card is
 * refused. "Expired on 12 June" is a far better thing to be able to say than
 * "invalid".
 *
 * There is no redeem button, deliberately. A voucher is only ever drawn down
 * against a bill, at the till; a redeem action here would let someone spend a
 * card with no sale behind it, which is a hole in the books rather than a
 * convenience. What this screen does is issue, look up, and cancel.
 *
 * The outstanding-balance tile is the one an accountant asks for: money already
 * banked against goods not yet handed over, which is a real liability and is
 * reported nowhere else.
 */
import { ref, computed, onMounted } from 'vue'
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
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import type { ExportColumn } from '../composables/useTableExport'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('gift-vouchers.create'))
const canEdit = computed(() => auth.can('gift-vouchers.edit'))
const canDelete = computed(() => auth.can('gift-vouchers.delete'))

const STATUSES = [
  { label: 'Active', value: 'active' },
  { label: 'Fully redeemed', value: 'redeemed' },
  { label: 'Expired', value: 'expired' },
  { label: 'Cancelled', value: 'cancelled' },
]

const rows = ref<any[]>([])
const summary = ref({ active: 0, outstanding: 0, issued_value: 0, redeemed_value: 0, expiring_30d: 0 })
const loading = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const expiringOnly = ref(false)
const customers = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/gift-vouchers', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        expiring: expiringOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const { data } = await http.get('/customers', { params: { per_page: 500, is_active: 1 } })
  customers.value = (data.data ?? data).map((x: any) => ({
    label: `${x.name} — ${x.phone ?? ''}`.trim(),
    value: x.id,
  }))
}

// ---- "Is this card good?" -------------------------------------------------

const checkCode = ref('')
const checkResult = ref<any>(null)
const checking = ref(false)

async function runCheck() {
  if (!checkCode.value.trim()) return
  checking.value = true
  try {
    const { data } = await http.get('/gift-vouchers/check', { params: { code: checkCode.value } })
    checkResult.value = data
  } catch (e: any) {
    // A 404 is a real answer here, not an error — the card is not one of ours.
    checkResult.value = e.response?.data ?? { found: false, is_spendable: false, reason: pt('Lookup failed') }
  } finally {
    checking.value = false
  }
}

// ---- Issue / edit ---------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>(blank())

function blank() {
  const expiry = new Date()
  // A year is the usual term, and a card with no expiry is an open-ended
  // liability the shop carries for ever — so one is defaulted rather than left
  // blank. It can still be cleared for a shop that does not expire cards.
  expiry.setFullYear(expiry.getFullYear() + 1)
  return {
    code: '',
    customer_id: null,
    recipient_name: '',
    recipient_phone: '',
    face_value: null,
    expires_on: expiry,
    notes: '',
  }
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/gift-vouchers/${row.id}`)
  const v = data.data
  editingId.value = v.id
  form.value = {
    code: v.code,
    customer_id: v.customer_id,
    recipient_name: v.recipient_name ?? '',
    recipient_phone: v.recipient_phone ?? '',
    face_value: v.face_value,
    expires_on: v.expires_on ? new Date(v.expires_on) : null,
    notes: v.notes ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: any = {
      customer_id: form.value.customer_id,
      recipient_name: form.value.recipient_name || null,
      recipient_phone: form.value.recipient_phone || null,
      expires_on: asDate(form.value.expires_on),
      notes: form.value.notes || null,
    }
    if (editingId.value) {
      // face_value and code are absent on purpose: the balance is derived from
      // the redemption ledger, so a card issued for the wrong amount is
      // cancelled and reissued rather than edited into agreement.
      await http.put(`/gift-vouchers/${editingId.value}`, payload)
    } else {
      await http.post('/gift-vouchers', {
        ...payload,
        code: form.value.code || undefined,
        face_value: form.value.face_value,
      })
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: editingId.value ? pt('Voucher updated') : pt('Voucher issued'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the voucher'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

// ---- History --------------------------------------------------------------

const historyVisible = ref(false)
const historyVoucher = ref<any>(null)

async function openHistory(row: any) {
  const { data } = await http.get(`/gift-vouchers/${row.id}`)
  historyVoucher.value = data.data
  historyVisible.value = true
}

async function cancelVoucher(row: any) {
  const res = await Swal.fire({
    title: pt('Cancel this voucher?'),
    text: pt('The remaining balance stops being spendable. The record and its history stay.'),
    input: 'text',
    inputPlaceholder: pt('Reason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Cancel Voucher'),
    cancelButtonText: pt('Keep It'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.post(`/gift-vouchers/${row.id}/cancel`, { reason: res.value || undefined })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this voucher?'),
    text: pt('Only vouchers that have never been used can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/gift-vouchers/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

function asDate(v: any): string | null {
  if (!v) return null
  const d = v instanceof Date ? v : new Date(v)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function statusTag(row: any) {
  if (row.status === 'cancelled') return { label: pt('Cancelled'), severity: 'contrast' }
  if (row.status === 'redeemed') return { label: pt('Redeemed'), severity: 'secondary' }
  if (!row.is_spendable) return { label: pt('Expired'), severity: 'danger' }
  return { label: pt('Active'), severity: 'success' }
}

const exportColumns: ExportColumn[] = [
  { key: 'code', label: 'Code' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'recipient_name', label: 'Recipient' },
  { key: 'face_value', label: 'Face value' },
  { key: 'balance', label: 'Balance' },
  { key: 'issued_on', label: 'Issued' },
  { key: 'expires_on', label: 'Expires' },
  { key: 'status', label: 'Status' },
]

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Gift Vouchers')"
      :subtitle="pt('Money the shop has already been paid, against goods not yet sold')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Issue Voucher')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Active cards') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <!-- The liability. Reported nowhere else in the ERP. -->
        <span class="t-label">{{ pt('Outstanding balance') }}</span>
        <span class="t-value">{{ formatCurrency(summary.outstanding) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Redeemed to date') }}</span>
        <span class="t-value">{{ formatCurrency(summary.redeemed_value) }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Expiring in 30 days') }}</span>
        <span class="t-value warn">{{ summary.expiring_30d }}</span>
      </div>
    </div>

    <!-- The counter question -->
    <div class="check-panel">
      <div class="check-title">{{ pt('Is this voucher good?') }}</div>
      <div class="check-row">
        <InputText
          v-model="checkCode"
          class="check-code"
          :placeholder="pt('Type or scan the code on the card')"
          @keyup.enter="runCheck"
        />
        <Button :label="pt('Check')" icon="pi pi-search" :loading="checking" @click="runCheck" />
      </div>
      <div v-if="checkResult" class="check-result">
        <div v-if="!checkResult.found" class="check-line">
          <Tag :value="pt('Not found')" severity="danger" />
          <span>{{ checkResult.reason }}</span>
        </div>
        <template v-else>
          <div class="check-line">
            <Tag
              :value="checkResult.is_spendable ? pt('Good') : pt('Cannot be used')"
              :severity="checkResult.is_spendable ? 'success' : 'danger'"
            />
            <span class="strong">{{ formatCurrency(checkResult.data.balance) }}</span>
            <span class="muted">
              {{ pt('of') }} {{ formatCurrency(checkResult.data.face_value) }}
            </span>
            <span v-if="checkResult.data.expires_on" class="muted">
              · {{ pt('valid to') }} {{ checkResult.data.expires_on }}
            </span>
          </div>
          <div v-if="checkResult.reason" class="check-line muted">{{ checkResult.reason }}</div>
        </template>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Search code, recipient or customer')" @keyup.enter="load" />
      <Select
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        showClear
        :placeholder="pt('All statuses')"
        @change="load"
      />
      <label class="chk">
        <Checkbox v-model="expiringOnly" binary @change="load" />
        <span>{{ pt('Expiring soon') }}</span>
      </label>
      <Button icon="pi pi-refresh" text rounded :title="pt('Refresh')" @click="load" />
      <div class="spacer" />
      <TableExportButtons :rows="rows" :columns="exportColumns" filename="gift-vouchers" :title="pt('Gift Vouchers')" />
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" data-key="id" size="small" striped-rows>
      <Column field="code" :header="pt('Code')" sortable>
        <template #body="{ data }"><span class="mono">{{ data.code }}</span></template>
      </Column>
      <Column field="recipient_name" :header="pt('For')" sortable>
        <template #body="{ data }">
          <div>{{ data.recipient_name || data.customer_name || '—' }}</div>
          <small class="muted">{{ data.recipient_phone }}</small>
        </template>
      </Column>
      <Column field="face_value" :header="pt('Face value')" sortable>
        <template #body="{ data }">{{ formatCurrency(data.face_value) }}</template>
      </Column>
      <Column field="balance" :header="pt('Balance')" sortable>
        <template #body="{ data }">
          <span class="strong">{{ formatCurrency(data.balance) }}</span>
        </template>
      </Column>
      <Column field="issued_on" :header="pt('Issued')" sortable />
      <Column field="expires_on" :header="pt('Expires')" sortable>
        <template #body="{ data }">{{ data.expires_on || pt('No expiry') }}</template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :value="statusTag(data).label" :severity="statusTag(data).severity" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-history" text rounded :title="pt('History')" @click="openHistory(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
          <Button
            v-if="canDelete && data.status !== 'cancelled'"
            icon="pi pi-ban"
            text
            rounded
            severity="warn"
            :title="pt('Cancel')"
            @click="cancelVoucher(data)"
          />
          <Button
            v-if="canDelete"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No vouchers issued yet. A card issued here can be tendered at the counter like cash, and what is left on it stays on it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Issue / edit -->
    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editingId ? pt('Edit Voucher') : pt('Issue Gift Voucher')"
      :style="{ width: '620px' }"
    >
      <div class="grid-form">
        <div class="fld span-2">
          <label>{{ pt('Code') }}</label>
          <InputText v-model="form.code" :disabled="!!editingId" :placeholder="pt('Leave blank to generate one')" />
          <small class="hint">{{ pt('Type the number off a pre-printed card, or leave it empty and one will be generated.') }}</small>
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Face value') }} *</label>
          <InputNumber v-model="form.face_value" :disabled="!!editingId" :minFractionDigits="2" class="w-full" />
          <small v-if="editingId" class="hint">{{ pt('Cannot be changed — cancel and reissue instead.') }}</small>
          <small v-if="errors.face_value" class="err">{{ errors.face_value[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Valid until') }}</label>
          <DatePicker v-model="form.expires_on" date-format="dd/mm/yy" show-icon show-button-bar class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Bought by') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            showClear
            class="w-full"
            :placeholder="pt('Optional — the customer who bought it')"
          />
        </div>

        <div class="fld">
          <label>{{ pt('Recipient name') }}</label>
          <InputText v-model="form.recipient_name" />
        </div>
        <div class="fld">
          <label>{{ pt('Recipient phone') }}</label>
          <InputText v-model="form.recipient_phone" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editingId ? pt('Save') : pt('Issue')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- History -->
    <Dialog v-model:visible="historyVisible" modal :header="pt('Voucher History')" :style="{ width: '620px' }">
      <template v-if="historyVoucher">
        <div class="detail-strip">
          <span class="mono strong">{{ historyVoucher.code }}</span>
          <span class="muted">
            {{ formatCurrency(historyVoucher.balance) }} {{ pt('of') }}
            {{ formatCurrency(historyVoucher.face_value) }}
          </span>
          <Tag :value="statusTag(historyVoucher).label" :severity="statusTag(historyVoucher).severity" />
        </div>

        <Message v-if="historyVoucher.reason" severity="warn" :closable="false" class="mb">
          {{ historyVoucher.reason }}
        </Message>

        <table v-if="historyVoucher.redemptions.length" class="history">
          <thead>
            <tr>
              <th>{{ pt('When') }}</th>
              <th>{{ pt('Amount') }}</th>
              <th>{{ pt('Against') }}</th>
            </tr>
          </thead>
          <tbody>
            <!-- Signed: a spend is positive, value put back on a return is
                 negative. One ledger, so the balance is always reconstructible. -->
            <tr v-for="r in historyVoucher.redemptions" :key="r.id">
              <td>{{ new Date(r.redeemed_at).toLocaleString() }}</td>
              <td :class="r.amount < 0 ? 'credit' : 'debit'">{{ formatCurrency(Math.abs(r.amount)) }}</td>
              <td>
                <div>{{ r.invoice_number || '—' }}</div>
                <small class="muted">{{ r.reference }}</small>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty-state">{{ pt('Never used — the full value is still on the card.') }}</div>
      </template>

      <template #footer>
        <Button :label="pt('Close')" text @click="historyVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }

.check-panel { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1rem; }
.check-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.6rem; }
.check-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.check-code { min-width: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.check-result { margin-top: 0.7rem; display: flex; flex-direction: column; gap: 0.35rem; }
.check-line { display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; flex-wrap: wrap; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.spacer { flex: 1; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }

.grid-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }

.detail-strip { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.8rem; }
.history { width: 100%; border-collapse: collapse; }
.history th, .history td { border-bottom: 1px solid var(--surface-border, #e5e7eb); padding: 0.45rem 0.5rem; font-size: 0.85rem; text-align: left; }
.history th { font-weight: 600; color: #374151; font-size: 0.75rem; }
.debit { font-weight: 600; }
.credit { font-weight: 600; color: #15803d; }

.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.mb { margin-bottom: 0.9rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
