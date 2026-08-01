<script setup lang="ts">
/**
 * Paid membership cards, and the tiers a shop sells.
 *
 * One screen with two tabs, because they are one job: a café sells three tiers and
 * looks at who is on them, and splitting that across two nav entries would be two
 * clicks to answer one question.
 *
 * Cards expiring inside a month lead the summary — that list is the renewal call
 * sheet, and it is the only reason anybody opens this screen unprompted.
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
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()
const confirm = useConfirm()

const tab = ref('members')

const members = ref<any[]>([])
const plans = ref<any[]>([])
const customers = ref<any[]>([])
const summary = ref({ active: 0, expired: 0, cancelled: 0, expiring_30d: 0, revenue: 0 })
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)

const sellVisible = ref(false)
const planVisible = ref(false)
const editingPlan = ref<any>(null)
const errors = ref<Record<string, string[]>>({})

const STATUSES = computed(() => [
  { label: pt('Active'), value: 'active' },
  { label: pt('Expired'), value: 'expired' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

const sellForm = ref<any>({
  customer_id: null,
  membership_plan_id: null,
  starts_on: null,
  price: null,
  raise_invoice: true,
  notes: '',
})

const blankPlan = () => ({
  code: '',
  name: '',
  description: '',
  price: 0,
  duration_days: 365,
  discount_percent: 0,
  loyalty_multiplier: 1,
  benefits: '',
  is_active: true,
})

const planForm = ref<any>(blankPlan())

async function loadMembers() {
  loading.value = true
  try {
    const { data } = await http.get('/memberships', {
      params: { search: search.value || undefined, status: statusFilter.value || undefined },
    })
    members.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPlans() {
  const { data } = await http.get('/membership-plans')
  plans.value = data.data
}

async function loadCustomers() {
  const { data } = await http.get('/customers', { params: { per_page: 500 } })
  customers.value = data.data
}

function openSell() {
  sellForm.value = {
    customer_id: null,
    membership_plan_id: plans.value.find((p) => p.is_active)?.id ?? null,
    starts_on: null,
    price: null,
    raise_invoice: true,
    notes: '',
  }
  errors.value = {}
  sellVisible.value = true
}

async function sell() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/memberships', sellForm.value)
    sellVisible.value = false
    await Promise.all([loadMembers(), loadPlans()])
    toast.add({ severity: 'success', summary: pt('Membership sold'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: pt('Could not sell the membership'),
      detail: e.response?.data?.message,
      life: 4500,
    })
  } finally {
    saving.value = false
  }
}

function renew(row: any) {
  confirm.require({
    message: pt('Renew this card? It extends from the later of today and its current expiry.'),
    header: pt('Renew membership'),
    icon: 'pi pi-refresh',
    accept: async () => {
      try {
        await http.post(`/memberships/${row.id}/renew`, { raise_invoice: true })
        await loadMembers()
        toast.add({ severity: 'success', summary: pt('Membership renewed'), life: 2500 })
      } catch (e: any) {
        toast.add({ severity: 'error', summary: pt('Could not renew'), detail: e.response?.data?.message, life: 4000 })
      }
    },
  })
}

function cancel(row: any) {
  confirm.require({
    message: pt('Cancel this card? It stops entitling immediately. Refunds are raised through Sales.'),
    header: pt('Cancel membership'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      await http.post(`/memberships/${row.id}/cancel`, {})
      await loadMembers()
      toast.add({ severity: 'success', summary: pt('Membership cancelled'), life: 2500 })
    },
  })
}

function openPlan(row: any = null) {
  editingPlan.value = row
  planForm.value = row
    ? {
        code: row.code,
        name: row.name,
        description: row.description ?? '',
        price: Number(row.price),
        duration_days: row.duration_days,
        discount_percent: Number(row.discount_percent),
        loyalty_multiplier: Number(row.loyalty_multiplier),
        benefits: row.benefits ?? '',
        is_active: row.is_active,
      }
    : blankPlan()
  errors.value = {}
  planVisible.value = true
}

async function savePlan() {
  saving.value = true
  errors.value = {}
  try {
    if (editingPlan.value) {
      await http.put(`/membership-plans/${editingPlan.value.id}`, planForm.value)
    } else {
      await http.post('/membership-plans', planForm.value)
    }
    planVisible.value = false
    await loadPlans()
    toast.add({ severity: 'success', summary: pt('Plan saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
  } finally {
    saving.value = false
  }
}

function removePlan(row: any) {
  confirm.require({
    message: pt('Delete this tier? If members still hold it, it is deactivated instead.'),
    header: pt('Delete tier'),
    icon: 'pi pi-exclamation-triangle',
    accept: async () => {
      const { data } = await http.delete(`/membership-plans/${row.id}`)
      await loadPlans()
      toast.add({ severity: 'success', summary: data.message, life: 3500 })
    },
  })
}

const customerOptions = computed(() =>
  customers.value.map((c) => ({ label: `${c.name}${c.phone ? ' · ' + c.phone : ''}`, value: c.id })),
)

const planOptions = computed(() =>
  plans.value
    .filter((p) => p.is_active)
    .map((p) => ({ label: `${p.name} — ${formatCurrency(p.price)} / ${p.duration_days}d`, value: p.id })),
)

onMounted(() => {
  loadMembers()
  loadPlans()
  loadCustomers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Memberships')"
      :subtitle="pt('A card is bought, not earned — it discounts from the first sip and expires on its own date')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Active cards') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile" :class="{ accent: summary.expiring_30d > 0 }">
        <span class="t-label">{{ pt('Expiring in 30 days') }}</span>
        <span class="t-value">{{ summary.expiring_30d }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Expired') }}</span>
        <span class="t-value">{{ summary.expired }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Membership revenue') }}</span>
        <span class="t-value">{{ formatCurrency(summary.revenue) }}</span>
      </div>
    </div>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="members">{{ pt('Members') }}</Tab>
        <Tab value="plans">{{ pt('Tiers') }}</Tab>
      </TabList>

      <TabPanels>
        <TabPanel value="members">
          <div class="toolbar">
            <InputText v-model="search" :placeholder="pt('Member number, name or phone')" @keyup.enter="loadMembers" />
            <Select
              v-model="statusFilter"
              :options="STATUSES"
              option-label="label"
              option-value="value"
              :placeholder="pt('All statuses')"
              show-clear
              @change="loadMembers"
            />
            <Button icon="pi pi-search" text @click="loadMembers" />
            <Button icon="pi pi-plus" :label="pt('Sell Membership')" @click="openSell" />
            <TableExportButtons
              title="Memberships"
              filenameBase="memberships"
              :rows="members"
              :columns="[
                { key: 'member_number', label: 'Member no.' },
                { key: 'customer', label: 'Customer' },
                { key: 'phone', label: 'Phone' },
                { key: 'plan', label: 'Tier' },
                { key: 'discount_percent', label: 'Discount %' },
                { key: 'starts_on', label: 'From' },
                { key: 'ends_on', label: 'Until' },
                { key: 'status', label: 'Status' },
                { key: 'price_paid', label: 'Paid' },
              ]"
            />
          </div>

          <DataTable :value="members" :loading="loading" data-key="id" striped-rows responsive-layout="scroll">
            <Column field="member_number" :header="pt('Member no.')" sortable />
            <Column field="customer" :header="pt('Customer')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <span>{{ data.customer }}</span>
                  <small class="muted">{{ data.phone }}</small>
                </div>
              </template>
            </Column>
            <Column field="plan" :header="pt('Tier')" sortable>
              <template #body="{ data }">
                {{ data.plan }}
                <small class="muted">· {{ data.discount_percent }}%</small>
              </template>
            </Column>
            <Column field="ends_on" :header="pt('Valid until')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <span>{{ data.ends_on }}</span>
                  <small
                    class="muted"
                    :class="{ soon: data.days_remaining >= 0 && data.days_remaining <= 30, late: data.days_remaining < 0 }"
                  >
                    {{ data.days_remaining >= 0
                      ? pt('{d} days left').replace('{d}', String(data.days_remaining))
                      : pt('lapsed {d} days ago').replace('{d}', String(Math.abs(data.days_remaining))) }}
                  </small>
                </div>
              </template>
            </Column>
            <Column field="price_paid" :header="pt('Paid')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.price_paid) }}</template>
            </Column>
            <Column :header="pt('Status')">
              <template #body="{ data }">
                <Tag
                  v-if="data.is_entitling"
                  :value="pt('Entitling')"
                  severity="success"
                />
                <Tag v-else :value="pt(data.status)" :severity="data.status === 'cancelled' ? 'danger' : 'secondary'" />
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button
                  v-if="data.status !== 'cancelled'"
                  icon="pi pi-refresh"
                  text
                  size="small"
                  v-tooltip.top="pt('Renew')"
                  @click="renew(data)"
                />
                <Button
                  v-if="data.status === 'active'"
                  icon="pi pi-ban"
                  text
                  severity="danger"
                  size="small"
                  v-tooltip.top="pt('Cancel')"
                  @click="cancel(data)"
                />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('Nobody holds a membership yet.') }}</div>
            </template>
          </DataTable>
        </TabPanel>

        <TabPanel value="plans">
          <div class="toolbar">
            <Button icon="pi pi-plus" :label="pt('New Tier')" @click="openPlan()" />
          </div>

          <DataTable :value="plans" data-key="id" striped-rows responsive-layout="scroll">
            <Column field="name" :header="pt('Tier')" sortable>
              <template #body="{ data }">
                <div class="stack">
                  <strong>{{ data.name }}</strong>
                  <small class="muted">{{ data.code }}</small>
                </div>
              </template>
            </Column>
            <Column field="price" :header="pt('Price')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.price) }}</template>
            </Column>
            <Column field="duration_days" :header="pt('Validity')" sortable>
              <template #body="{ data }">{{ data.duration_days }} {{ pt('days') }}</template>
            </Column>
            <Column field="discount_percent" :header="pt('Discount')" sortable>
              <template #body="{ data }">{{ data.discount_percent }}%</template>
            </Column>
            <Column field="loyalty_multiplier" :header="pt('Points ×')" sortable />
            <Column field="active_members" :header="pt('Active members')" sortable />
            <Column field="revenue" :header="pt('Revenue')" sortable>
              <template #body="{ data }">{{ formatCurrency(data.revenue) }}</template>
            </Column>
            <Column :header="pt('Active')">
              <template #body="{ data }">
                <Tag
                  :value="data.is_active ? pt('Yes') : pt('No')"
                  :severity="data.is_active ? 'success' : 'secondary'"
                />
              </template>
            </Column>
            <Column :header="pt('Actions')">
              <template #body="{ data }">
                <Button icon="pi pi-pencil" text size="small" @click="openPlan(data)" />
                <Button icon="pi pi-trash" text severity="danger" size="small" @click="removePlan(data)" />
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">{{ pt('No membership tiers yet.') }}</div>
            </template>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <!-- Sell a card -->
    <Dialog v-model:visible="sellVisible" modal :header="pt('Sell Membership')" :style="{ width: '32rem' }">
      <div class="form">
        <div class="field">
          <label>{{ pt('Customer') }}</label>
          <Select
            v-model="sellForm.customer_id"
            :options="customerOptions"
            option-label="label"
            option-value="value"
            filter
          />
          <small v-if="errors.customer_id" class="err">{{ errors.customer_id[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Tier') }}</label>
          <Select
            v-model="sellForm.membership_plan_id"
            :options="planOptions"
            option-label="label"
            option-value="value"
          />
          <small v-if="errors.membership_plan_id" class="err">{{ errors.membership_plan_id[0] }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Price charged') }}</label>
          <InputNumber v-model="sellForm.price" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt("Leave blank to charge the tier's own price") }}</small>
        </div>

        <div class="field-inline">
          <Checkbox v-model="sellForm.raise_invoice" binary input-id="mem-invoice" />
          <label for="mem-invoice">{{ pt('Raise an invoice') }}</label>
        </div>
        <small class="hint">{{ pt('Uncheck to comp a card — it still gets an expiry date') }}</small>

        <div class="field">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="sellForm.notes" rows="2" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="sellVisible = false" />
        <Button :label="pt('Sell')" :loading="saving" @click="sell" />
      </template>
    </Dialog>

    <!-- Tier -->
    <Dialog
      v-model:visible="planVisible"
      modal
      :header="editingPlan ? pt('Edit Tier') : pt('New Tier')"
      :style="{ width: '34rem' }"
    >
      <div class="form">
        <div class="row">
          <div class="field">
            <label>{{ pt('Code') }}</label>
            <InputText v-model="planForm.code" />
            <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
          </div>
          <div class="field">
            <label>{{ pt('Name') }}</label>
            <InputText v-model="planForm.name" />
            <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Price') }}</label>
            <InputNumber v-model="planForm.price" :min="0" :max-fraction-digits="2" />
          </div>
          <div class="field">
            <label>{{ pt('Validity (days)') }}</label>
            <InputNumber v-model="planForm.duration_days" :min="1" :max="3650" />
            <small class="hint">{{ pt('365 for a year, 90 for a quarter') }}</small>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>{{ pt('Discount %') }}</label>
            <InputNumber v-model="planForm.discount_percent" :min="0" :max="100" :max-fraction-digits="2" />
          </div>
          <div class="field">
            <label>{{ pt('Loyalty points ×') }}</label>
            <InputNumber v-model="planForm.loyalty_multiplier" :min="0" :max-fraction-digits="2" />
            <small class="hint">{{ pt('1 = members earn like everyone else') }}</small>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Benefits') }}</label>
          <Textarea v-model="planForm.benefits" rows="2" :placeholder="pt('Perks staff honour by hand')" />
        </div>

        <div class="field-inline">
          <Checkbox v-model="planForm.is_active" binary input-id="plan-active" />
          <label for="plan-active">{{ pt('Active') }}</label>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="planVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="savePlan" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #e8a33d; background: rgba(232, 163, 61, 0.08); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; }
.soon { color: #e8a33d; font-weight: 600; }
.late { color: #dc2626; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { font-size: 0.7rem; color: #9ca3af; }
.err { color: #dc2626; font-size: 0.75rem; }
</style>
