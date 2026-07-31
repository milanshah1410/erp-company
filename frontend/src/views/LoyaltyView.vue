<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

interface Member {
  customer_id: number
  customer_name: string
  customer_phone: string | null
  balance: number
  earned: number
  redeemed: number
  value: number
  last_activity_at: string | null
}

interface Programme {
  members: number
  balance: number
  earned: number
  redeemed: number
  liability: number
}

const auth = useAuthStore()
const toast = useToast()

const canEditSettings = computed(() => auth.can('settings.edit'))
const canAdjust = computed(() => auth.can('loyalty.create'))

const members = ref<Member[]>([])
const programme = ref<Programme>({ members: 0, balance: 0, earned: 0, redeemed: 0, liability: 0 })
const loading = ref(false)
const search = ref('')
const withBalanceOnly = ref(true)
const totalRecords = ref(0)
const rows = ref(25)
const first = ref(0)

// The programme rules, edited in place and saved through the settings endpoint —
// they are tenant settings, not a resource of their own.
const rules = ref({
  loyalty_enabled: false,
  loyalty_points_per_amount: 1,
  loyalty_earn_amount_unit: 100,
  loyalty_redeem_value: 1,
  loyalty_min_redeem_points: 100,
  loyalty_min_bill_amount: 0,
  loyalty_max_redeem_percent: 50,
})
const savingRules = ref(false)

async function loadRules() {
  const { data } = await http.get('/settings')
  const s = data.data
  rules.value = {
    loyalty_enabled: String(s.loyalty_enabled) === '1',
    loyalty_points_per_amount: Number(s.loyalty_points_per_amount ?? 1),
    loyalty_earn_amount_unit: Number(s.loyalty_earn_amount_unit ?? 100),
    loyalty_redeem_value: Number(s.loyalty_redeem_value ?? 1),
    loyalty_min_redeem_points: Number(s.loyalty_min_redeem_points ?? 100),
    loyalty_min_bill_amount: Number(s.loyalty_min_bill_amount ?? 0),
    loyalty_max_redeem_percent: Number(s.loyalty_max_redeem_percent ?? 50),
  }
}

async function saveRules() {
  savingRules.value = true
  try {
    await http.put('/settings', rules.value)
    toast.add({ severity: 'success', summary: 'Programme updated', life: 2500 })
    await load()
  } catch {
    toast.add({ severity: 'error', summary: 'Could not save the programme rules', life: 4000 })
  } finally {
    savingRules.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/loyalty/members', {
      params: {
        search: search.value || undefined,
        with_balance_only: withBalanceOnly.value ? 1 : 0,
        per_page: rows.value,
        page: Math.floor(first.value / rows.value) + 1,
      },
    })
    members.value = data.data
    totalRecords.value = data.total ?? data.data.length
    if (data.programme) programme.value = data.programme
  } finally {
    loading.value = false
  }
}

function onPage(e: { first: number; rows: number }) {
  first.value = e.first
  rows.value = e.rows
  load()
}

// --- Ledger + manual adjustment for one member ---
const ledgerOpen = ref(false)
const ledgerFor = ref<Member | null>(null)
const ledger = ref<any[]>([])
const ledgerLoading = ref(false)

const entry = ref({ type: 'adjust', points: 0, description: '' })
const saving = ref(false)

const ENTRY_TYPES = [
  { label: 'Adjust (add points)', value: 'adjust' },
  { label: 'Redeem (deduct points)', value: 'redeem' },
  { label: 'Expire (deduct points)', value: 'expire' },
]

async function openLedger(member: Member) {
  ledgerFor.value = member
  ledgerOpen.value = true
  entry.value = { type: 'adjust', points: 0, description: '' }
  ledgerLoading.value = true
  try {
    const { data } = await http.get(`/customers/${member.customer_id}/loyalty`, { params: { per_page: 50 } })
    ledger.value = data.data
  } finally {
    ledgerLoading.value = false
  }
}

async function saveEntry() {
  if (!ledgerFor.value || entry.value.points <= 0) return
  saving.value = true
  try {
    await http.post(`/customers/${ledgerFor.value.customer_id}/loyalty`, entry.value)
    toast.add({ severity: 'success', summary: 'Points updated', life: 2500 })
    await openLedger(ledgerFor.value)
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? 'Could not record the entry',
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

function when(v: string | null) {
  return v ? new Date(v).toLocaleDateString() : '—'
}

/** "1 point per ₹100 spent · ₹1 off per point" — the rules in one readable line. */
const ruleSummary = computed(() => {
  const r = rules.value
  return `${r.loyalty_points_per_amount} point(s) per ${money(r.loyalty_earn_amount_unit)} spent · ${money(r.loyalty_redeem_value)} off per point`
})

onMounted(async () => {
  await loadRules()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      title="Loyalty Points"
      subtitle="Who is holding points, what they are worth, and the rules they were earned under"
    />

    <div v-if="!rules.loyalty_enabled" class="notice">
      <i class="pi pi-info-circle" />
      <span>
        The points programme is switched off — bills stop earning and the counter's redeem box stays
        hidden. Existing balances are kept.
      </span>
    </div>

    <!-- Programme totals. `liability` is what the outstanding balance would cost
         if every member redeemed today — the number an accountant asks for. -->
    <div class="tiles">
      <div class="tile">
        <span class="t-label">Members</span>
        <strong class="t-value">{{ programme.members }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">Points outstanding</span>
        <strong class="t-value">{{ programme.balance.toLocaleString() }}</strong>
      </div>
      <div class="tile accent">
        <span class="t-label">Redemption liability</span>
        <strong class="t-value">{{ money(programme.liability) }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">Points ever earned</span>
        <strong class="t-value">{{ programme.earned.toLocaleString() }}</strong>
      </div>
      <div class="tile">
        <span class="t-label">Points ever redeemed</span>
        <strong class="t-value">{{ programme.redeemed.toLocaleString() }}</strong>
      </div>
    </div>

    <!-- Programme rules -->
    <div class="card">
      <div class="card-head">
        <h3>Programme rules</h3>
        <span class="rule-summary">{{ ruleSummary }}</span>
      </div>

      <div class="rules-grid">
        <div class="field switch-field">
          <label for="loyalty-on">Programme enabled</label>
          <Checkbox v-model="rules.loyalty_enabled" binary inputId="loyalty-on" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>Points earned</label>
          <InputNumber v-model="rules.loyalty_points_per_amount" :min="0" :maxFractionDigits="2" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>…per amount spent</label>
          <InputNumber v-model="rules.loyalty_earn_amount_unit" :min="1" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>Value per point</label>
          <InputNumber v-model="rules.loyalty_redeem_value" :min="0" :maxFractionDigits="2" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>Minimum points to redeem</label>
          <InputNumber v-model="rules.loyalty_min_redeem_points" :min="0" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>Minimum bill to earn</label>
          <InputNumber v-model="rules.loyalty_min_bill_amount" :min="0" :disabled="!canEditSettings" />
        </div>
        <div class="field">
          <label>Max % of a bill paid by points</label>
          <InputNumber v-model="rules.loyalty_max_redeem_percent" :min="0" :max="100" suffix=" %" :disabled="!canEditSettings" />
        </div>
      </div>

      <div v-if="canEditSettings" class="card-actions">
        <Button label="Save rules" icon="pi pi-check" :loading="savingRules" @click="saveRules" />
      </div>
    </div>

    <!-- Members -->
    <div class="toolbar">
      <InputText v-model="search" placeholder="Search name or phone" @keyup.enter="load" />
      <Button label="Search" icon="pi pi-search" outlined @click="load" />
      <label class="inline-check">
        <input v-model="withBalanceOnly" type="checkbox" @change="load" />
        With balance only
      </label>
      <TableExportButtons
        title="Loyalty Members"
        filenameBase="loyalty-members"
        :rows="members"
        :columns="[
          { key: 'customer_name', label: 'Customer' },
          { key: 'customer_phone', label: 'Phone' },
          { key: 'balance', label: 'Balance' },
          { key: 'value', label: 'Value' },
          { key: 'earned', label: 'Earned' },
          { key: 'redeemed', label: 'Redeemed' },
          { key: 'last_activity_at', label: 'Last Activity' },
        ]"
      />
    </div>

    <DataTable
      :value="members"
      :loading="loading"
      dataKey="customer_id"
      class="p-datatable-sm"
      lazy
      paginator
      :rows="rows"
      :first="first"
      :totalRecords="totalRecords"
      :rowsPerPageOptions="[25, 50, 100]"
      @page="onPage"
    >
      <Column header="Customer">
        <template #body="{ data }">
          <strong>{{ data.customer_name }}</strong>
          <br /><small style="color: #6b7280">{{ data.customer_phone || '—' }}</small>
        </template>
      </Column>
      <Column header="Balance">
        <template #body="{ data }">{{ data.balance.toLocaleString() }}</template>
      </Column>
      <Column header="Worth">
        <template #body="{ data }">{{ money(data.value) }}</template>
      </Column>
      <Column header="Earned">
        <template #body="{ data }">{{ data.earned.toLocaleString() }}</template>
      </Column>
      <Column header="Redeemed">
        <template #body="{ data }">{{ data.redeemed.toLocaleString() }}</template>
      </Column>
      <Column header="Last Activity">
        <template #body="{ data }">{{ when(data.last_activity_at) }}</template>
      </Column>
      <Column header="" style="width: 8rem">
        <template #body="{ data }">
          <Button label="Ledger" icon="pi pi-book" text size="small" @click="openLedger(data)" />
        </template>
      </Column>
      <template #empty>No members are holding points yet.</template>
    </DataTable>

    <!-- Per-member ledger + manual entry -->
    <Dialog
      v-model:visible="ledgerOpen"
      modal
      :header="ledgerFor ? `${ledgerFor.customer_name} — points ledger` : 'Points ledger'"
      :style="{ width: '46rem' }"
    >
      <div v-if="canAdjust" class="entry-row">
        <Select v-model="entry.type" :options="ENTRY_TYPES" optionLabel="label" optionValue="value" class="entry-type" />
        <InputNumber v-model="entry.points" :min="0" placeholder="Points" class="entry-points" />
        <Textarea v-model="entry.description" rows="1" placeholder="Reason (optional)" class="entry-note" />
        <Button label="Record" icon="pi pi-plus" :loading="saving" :disabled="entry.points <= 0" @click="saveEntry" />
      </div>

      <DataTable :value="ledger" :loading="ledgerLoading" dataKey="id" class="p-datatable-sm">
        <Column header="Date">
          <template #body="{ data }">{{ when(data.created_at) }}</template>
        </Column>
        <Column field="type" header="Type" />
        <Column header="Points">
          <template #body="{ data }">
            <span :class="data.points >= 0 ? 'pts-up' : 'pts-down'">
              {{ data.points > 0 ? '+' : '' }}{{ data.points }}
            </span>
          </template>
        </Column>
        <Column header="Invoice">
          <template #body="{ data }">{{ data.invoice?.invoice_number ?? '—' }}</template>
        </Column>
        <Column field="description" header="Note" />
        <template #empty>No points movement recorded yet.</template>
      </DataTable>
    </Dialog>
  </div>
</template>

<style scoped>
.notice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid #f0d9a8;
  background: #fdf6e7;
  color: #7a5b1c;
  border-radius: 10px;
  padding: 0.65rem 0.85rem;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.tile {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 0.75rem 0.9rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.tile.accent {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.08);
}
.t-label {
  font-size: 0.75rem;
  color: #6b7280;
}
.t-value {
  font-size: 1.25rem;
  font-weight: 700;
}
.card {
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 1rem;
  margin-bottom: 1.25rem;
}
.card-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 0.75rem;
}
.card-head h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}
.rule-summary {
  font-size: 0.8rem;
  color: #6b7280;
}
.rules-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 0.75rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.field label {
  font-size: 0.78rem;
  color: #374151;
}
.switch-field {
  justify-content: flex-start;
}
.card-actions {
  margin-top: 0.9rem;
}
.toolbar {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.inline-check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
  color: #374151;
}
.entry-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.entry-type {
  width: 12rem;
}
.entry-points {
  width: 8rem;
}
.entry-note {
  flex: 1 1 12rem;
  min-width: 10rem;
}
.pts-up {
  color: #15803d;
  font-weight: 600;
}
.pts-down {
  color: #b91c1c;
  font-weight: 600;
}
</style>
