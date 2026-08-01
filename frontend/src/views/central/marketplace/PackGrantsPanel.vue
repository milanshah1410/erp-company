<script setup lang="ts">
/**
 * Marketplace → Pack Grants (Volume 11 §Module Marketplace).
 *
 * Who holds which pack, and the operator-side sale: pick a workspace, pick a pack,
 * and the entitlement and the money are recorded together — a purchased grant
 * raises a GST invoice, a comped one is free by definition.
 *
 * Plan-bundled packs never appear as rows. They entitle without a grant, resolved
 * from the tenant's plan on every read, so a downgrade removes them with no
 * bookkeeping to chase. The picker labels them so nobody sells one twice.
 */
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney, fmtDate } from '../billing/shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canAssign = computed(() => auth.can('packs.assign'))

const rows = ref<any[]>([])
const packs = ref<any[]>([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const perPage = ref(20)
const search = ref('')
const statusFilter = ref<string | null>('active')
const packFilter = ref<number | null>(null)

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'Expired', value: 'expired' },
]
const sourceSeverity: Record<string, string> = {
  purchased: 'success',
  comped: 'info',
  bundled: 'secondary',
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/marketplace/assignments', {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        pack_id: packFilter.value || undefined,
      },
    })
    rows.value = data.data ?? []
    packs.value = data.packs ?? []
    total.value = data.meta?.total ?? rows.value.length
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load grants', life: 4000 })
  } finally {
    loading.value = false
  }
}

function onPage(event: any) {
  page.value = event.page + 1
  perPage.value = event.rows
  load()
}

let searchTimer: number | undefined
watch([search, statusFilter, packFilter], () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    load()
  }, 400)
})

// ---- Grant a pack ------------------------------------------------------------
const grantVisible = ref(false)
const granting = ref(false)
const tenantQuery = ref('')
const tenantOptions = ref<any[]>([])
const tenantLoading = ref(false)
const selectedTenant = ref<any>(null)
const catalogue = ref<any[]>([])
const entitlement = ref<any>({})
const form = ref<any>({})
const errors = ref<Record<string, string[]>>({})

const sourceOptions = [
  { label: 'Purchased — raise an invoice', value: 'purchased' },
  { label: 'Comped — free of charge', value: 'comped' },
]

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function openGrant() {
  selectedTenant.value = null
  tenantQuery.value = ''
  tenantOptions.value = []
  catalogue.value = []
  entitlement.value = {}
  form.value = {
    pack_id: null, source: 'purchased', price: null, starts_at: null, ends_at: null,
    coupon_code: '', bill: true, notes: '',
  }
  errors.value = {}
  grantVisible.value = true
}

/** Tenant lookup reuses the platform tenant list — no separate search endpoint. */
async function searchTenants() {
  const q = tenantQuery.value.trim()
  if (q.length < 2) {
    tenantOptions.value = []
    return
  }
  tenantLoading.value = true
  try {
    const { data } = await centralHttp.get('/tenants', { params: { search: q, per_page: 15 } })
    tenantOptions.value = data.data ?? []
  } catch {
    tenantOptions.value = []
  } finally {
    tenantLoading.value = false
  }
}

async function pickTenant(tenant: any) {
  selectedTenant.value = tenant
  tenantOptions.value = []
  try {
    const { data } = await centralHttp.get(`/marketplace/tenants/${tenant.id}/packs`)
    catalogue.value = data.data ?? []
    entitlement.value = data.entitlement ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load this workspace\'s packs', life: 4000 })
  }
}

const selectablePacks = computed(() =>
  catalogue.value.map((p: any) => ({
    ...p,
    // Held and bundled packs stay visible but unselectable — seeing why a pack
    // can't be sold is more useful than the pack vanishing.
    label: p.name,
    note: p.held ? `already held (${p.grant_source})` : p.bundled_with_plan ? 'included in their plan' : null,
  })),
)

const chosenPack = computed(() => catalogue.value.find((p: any) => p.id === form.value.pack_id))

/** Price defaults to the pack's, and is forced to 0 for a comped grant. */
watch(() => form.value.pack_id, () => {
  if (chosenPack.value) form.value.price = Number(chosenPack.value.price)
})
watch(() => form.value.source, (source) => {
  if (source === 'comped') form.value.price = 0
  else if (chosenPack.value) form.value.price = Number(chosenPack.value.price)
})

function toApiDate(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

async function grant() {
  granting.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.post(`/marketplace/tenants/${selectedTenant.value.id}/packs`, {
      pack_id: form.value.pack_id,
      source: form.value.source,
      price: form.value.source === 'comped' ? 0 : form.value.price,
      starts_at: toApiDate(form.value.starts_at),
      ends_at: toApiDate(form.value.ends_at),
      coupon_code: form.value.coupon_code || null,
      bill: form.value.source === 'purchased' ? form.value.bill : false,
      notes: form.value.notes || null,
    })
    toast.add({ severity: 'success', summary: 'Pack granted', detail: data.message, life: 6000 })
    grantVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not grant', detail: e.response?.data?.message || 'Request failed', life: 6000 })
  } finally {
    granting.value = false
  }
}

async function revoke(row: any) {
  const res = await Swal.fire({
    title: `Revoke ${row.pack_name} from ${row.tenant_name}?`,
    input: 'text',
    inputLabel: 'Reason (optional)',
    text: 'The modules disappear from their workspace immediately. Any invoice already raised stays on the books — refund it separately if that is the intent.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Revoke',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await centralHttp.delete(`/marketplace/assignments/${row.id}`, {
      data: { reason: res.value || null },
    })
    toast.add({ severity: 'success', summary: 'Revoked', detail: data.message, life: 6000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  }
}

async function resync(row: any) {
  try {
    await centralHttp.post(`/marketplace/tenants/${row.tenant_id}/packs/resync`)
    toast.add({ severity: 'success', summary: 'Entitlement re-synced', detail: `${row.tenant_name}'s pack modules were re-applied.`, life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4500 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <div class="filters">
        <InputText v-model="search" placeholder="Search workspace…" class="search" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value"
          placeholder="All states" showClear class="small-filter" />
        <Select v-model="packFilter" :options="packs" optionLabel="name" optionValue="id"
          placeholder="All packs" showClear filter class="pack-filter" />
      </div>
      <Button v-if="canAssign" label="Grant a Pack" icon="pi pi-plus" @click="openGrant" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm"
      lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[20, 50]" @page="onPage">
      <Column header="Workspace">
        <template #body="{ data }">
          <span class="strong">{{ data.tenant_name ?? `#${data.tenant_id}` }}</span>
        </template>
      </Column>
      <Column header="Pack">
        <template #body="{ data }">
          <div class="cell-2line">
            <span>{{ data.pack_name }}</span>
            <span class="muted cap">{{ data.pack_type }}</span>
          </div>
        </template>
      </Column>
      <Column header="Grants">
        <template #body="{ data }">
          <div class="chips">
            <span v-for="m in data.modules" :key="m" class="chip">{{ m }}</span>
            <span v-for="(v, k) in data.limit_bumps ?? {}" :key="k" class="chip bump">+{{ v }} {{ String(k).replace('max_', '') }}</span>
          </div>
        </template>
      </Column>
      <Column header="How" style="width: 8rem">
        <template #body="{ data }">
          <Tag :value="data.source" :severity="sourceSeverity[data.source] ?? 'secondary'" class="cap" />
        </template>
      </Column>
      <Column header="Price" style="width: 8rem">
        <template #body="{ data }">
          <div class="cell-2line">
            <span>{{ Number(data.price) > 0 ? fmtMoney(data.price, data.currency) : '—' }}</span>
            <span v-if="data.invoice_number" class="muted">{{ data.invoice_number }} · {{ data.invoice_status }}</span>
          </div>
        </template>
      </Column>
      <Column header="Term" style="width: 10rem">
        <template #body="{ data }">
          <span class="muted-sm">{{ data.starts_at ?? '—' }} → {{ data.ends_at ?? 'until revoked' }}</span>
        </template>
      </Column>
      <Column header="State" style="width: 7rem">
        <template #body="{ data }">
          <Tag :value="data.status" :severity="data.status === 'active' ? 'success' : 'secondary'" class="cap" />
          <div v-if="data.cancelled_at" class="muted">{{ fmtDate(data.cancelled_at) }}</div>
        </template>
      </Column>
      <Column header="Actions" style="width: 7rem">
        <template #body="{ data }">
          <Button v-if="canAssign && data.status === 'active'" icon="pi pi-times-circle" text rounded severity="danger"
            size="small" title="Revoke" @click="revoke(data)" />
          <Button v-if="canAssign" icon="pi pi-refresh" text rounded size="small"
            title="Re-apply this workspace's pack entitlement" @click="resync(data)" />
        </template>
      </Column>
      <template #empty>No pack grants yet.</template>
    </DataTable>

    <Dialog v-model:visible="grantVisible" modal header="Grant a pack" :style="{ width: '620px' }">
      <div class="form-grid">
        <div class="form-field full">
          <label>Workspace <span class="req">*</span></label>
          <template v-if="selectedTenant">
            <div class="picked">
              <span class="strong">{{ selectedTenant.company_name }}</span>
              <Button icon="pi pi-times" text rounded size="small" @click="selectedTenant = null" />
            </div>
          </template>
          <template v-else>
            <InputText v-model="tenantQuery" placeholder="Search by company name or email…" @input="searchTenants" />
            <div v-if="tenantLoading" class="muted">Searching…</div>
            <ul v-else-if="tenantOptions.length" class="tenant-list">
              <li v-for="t in tenantOptions" :key="t.id" @click="pickTenant(t)">
                <span>{{ t.company_name }}</span>
                <span class="muted">{{ t.email }}</span>
              </li>
            </ul>
          </template>
        </div>

        <template v-if="selectedTenant">
          <div class="form-field full">
            <label>Pack <span class="req">*</span></label>
            <Select v-model="form.pack_id" :options="selectablePacks" optionLabel="label" optionValue="id"
              :optionDisabled="(p: any) => !p.purchasable" placeholder="Choose a pack" filter
              :class="{ invalid: fieldError('pack') }">
              <template #option="{ option }">
                <div class="opt">
                  <span>{{ option.name }}</span>
                  <span v-if="option.note" class="muted">{{ option.note }}</span>
                  <span v-else class="muted">{{ fmtMoney(option.price, option.currency) }}</span>
                </div>
              </template>
            </Select>
            <small v-if="fieldError('pack')" class="error-text">{{ fieldError('pack') }}</small>
          </div>

          <Message v-if="chosenPack" severity="secondary" :closable="false" class="full pack-summary">
            Unlocks {{ chosenPack.module_labels?.join(', ') || 'no modules' }}
            <template v-if="Object.keys(chosenPack.limit_bumps ?? {}).length">
              and raises {{ Object.entries(chosenPack.limit_bumps).map(([k, v]) => `${String(k).replace('max_', '')} by ${v}`).join(', ') }}
            </template>.
          </Message>

          <div class="form-field">
            <label>How <span class="req">*</span></label>
            <Select v-model="form.source" :options="sourceOptions" optionLabel="label" optionValue="value" />
          </div>
          <div class="form-field">
            <label>Price</label>
            <InputNumber v-model="form.price" :min="0" :disabled="form.source === 'comped'"
              :minFractionDigits="0" :maxFractionDigits="2" />
            <small class="hint">{{ form.source === 'comped' ? 'A comped pack is free.' : 'Defaults to the pack price — override for a negotiated rate.' }}</small>
          </div>

          <div class="form-field">
            <label>Starts</label>
            <DatePicker v-model="form.starts_at" showIcon dateFormat="yy-mm-dd" />
            <small class="hint">Empty = today.</small>
          </div>
          <div class="form-field">
            <label>Ends</label>
            <DatePicker v-model="form.ends_at" showIcon dateFormat="yy-mm-dd" :class="{ invalid: fieldError('ends_at') }" />
            <small v-if="fieldError('ends_at')" class="error-text">{{ fieldError('ends_at') }}</small>
            <small v-else class="hint">Empty = runs until revoked. Set a date for a trial or fixed term.</small>
          </div>

          <template v-if="form.source === 'purchased'">
            <div class="form-field">
              <label>Coupon code</label>
              <InputText v-model="form.coupon_code" :class="{ invalid: fieldError('coupon_code') }"
                @input="form.coupon_code = form.coupon_code.toUpperCase()" />
              <small v-if="fieldError('coupon_code')" class="error-text">{{ fieldError('coupon_code') }}</small>
              <small v-else class="hint">Must be spendable on packs.</small>
            </div>
            <div class="form-field">
              <label>Invoice</label>
              <div class="checkbox-row">
                <Checkbox v-model="form.bill" binary inputId="pg-bill" />
                <label for="pg-bill">Raise a GST invoice</label>
              </div>
              <small class="hint">Uncheck only if the money was collected some other way.</small>
            </div>
          </template>

          <div class="form-field full">
            <label>Notes</label>
            <Textarea v-model="form.notes" rows="2" autoResize placeholder="Deal reference, who approved the concession…" />
          </div>
        </template>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="grantVisible = false" />
        <Button label="Grant pack" :disabled="!selectedTenant || !form.pack_id" :loading="granting" @click="grant" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.search { width: 200px; }
.small-filter { width: 130px; }
.pack-filter { width: 180px; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.muted-sm { color: #9ca3af; font-size: 0.8rem; }
.cap { text-transform: capitalize; }
.cell-2line { display: flex; flex-direction: column; }
.chips { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.chip { background: #f3f4f6; border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.72rem; color: #374151; }
.chip.bump { background: #ecfdf5; color: #047857; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
.form-field.full, .full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(.p-select),
.form-field :deep(.p-datepicker), .form-field :deep(textarea) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.picked { display: flex; align-items: center; justify-content: space-between; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.4rem 0.6rem; }
.tenant-list { list-style: none; margin: 0.25rem 0 0; padding: 0; border: 1px solid #e5e7eb; border-radius: 6px; max-height: 190px; overflow-y: auto; }
.tenant-list li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0.6rem; cursor: pointer; font-size: 0.85rem; }
.tenant-list li:hover { background: #f3f4f6; }
.opt { display: flex; justify-content: space-between; gap: 1rem; width: 100%; }
.pack-summary { margin: 0; font-size: 0.8rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
