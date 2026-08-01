<script setup lang="ts">
/**
 * Marketplace → Module Packs (Volume 11 §Module Marketplace).
 *
 * The Industry / Feature / AI packs we sell. A pack grants only what the product
 * already has — module keys and bumps to metered caps — so this editor composes
 * from those two catalogues and cannot invent a capability.
 *
 * Two consequences the UI states plainly: editing a pack changes what its holders
 * get immediately (entitlement is resolved from the pack row on every read), and a
 * pack listed against a plan is *bundled* with that tier rather than sold to it.
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
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'
import { fmtMoney } from '../billing/shared'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('packs.create'))
const canEdit = computed(() => auth.can('packs.edit'))
const canDelete = computed(() => auth.can('packs.delete'))

const rows = ref<any[]>([])
const meta = ref<any>({ modules: [], plans: [], industries: [], limit_keys: [], types: [], billing_cycles: [] })
const loading = ref(false)
const typeFilter = ref<string | null>(null)
const search = ref('')

const typeLabels: Record<string, string> = {
  industry: 'Industry',
  feature: 'Feature',
  ai: 'AI',
}
const cycleLabels: Record<string, string> = {
  monthly: '/mo',
  yearly: '/yr',
  one_time: 'once',
}

const typeOptions = computed(() =>
  (meta.value.types ?? []).map((t: string) => ({ label: typeLabels[t] ?? t, value: t })),
)
const cycleOptions = computed(() =>
  (meta.value.billing_cycles ?? []).map((c: string) => ({
    label: c === 'one_time' ? 'One-time (perpetual)' : c === 'yearly' ? 'Yearly' : 'Monthly',
    value: c,
  })),
)

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/marketplace/packs', {
      params: { type: typeFilter.value || undefined, search: search.value || undefined },
    })
    rows.value = data.data ?? []
    meta.value = data.meta ?? meta.value
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load packs', life: 4000 })
  } finally {
    loading.value = false
  }
}

// ---- Create / edit -----------------------------------------------------------
const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const form = ref<any>({})
/** Limit bumps as editable rows, so the form isn't a JSON blob. */
const bumps = ref<{ key: string; value: number | null }[]>([])
const errors = ref<Record<string, string[]>>({})

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

function blankForm() {
  return {
    slug: '',
    name: '',
    type: 'feature',
    tagline: '',
    description: '',
    icon: '',
    price: 499,
    billing_cycle: 'monthly',
    currency: 'INR',
    modules: [],
    industry_key: null,
    included_in_plans: [],
    is_active: true,
    sort_order: rows.value.length * 10,
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  bumps.value = []
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  form.value = {
    ...blankForm(),
    ...row,
    price: Number(row.price),
    modules: row.modules ?? [],
    included_in_plans: row.included_in_plans ?? [],
  }
  bumps.value = Object.entries(row.limit_bumps ?? {}).map(([key, value]) => ({ key, value: Number(value) }))
  errors.value = {}
  dialogVisible.value = true
}

function addBump() {
  const used = bumps.value.map((b) => b.key)
  const next = (meta.value.limit_keys ?? []).find((k: string) => !used.includes(k))
  bumps.value.push({ key: next ?? '', value: 5 })
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const limitBumps: Record<string, number> = {}
    for (const b of bumps.value) {
      if (b.key && Number(b.value) > 0) limitBumps[b.key] = Number(b.value)
    }

    const payload = {
      ...form.value,
      currency: (form.value.currency || 'INR').toUpperCase(),
      modules: form.value.modules ?? [],
      limit_bumps: Object.keys(limitBumps).length ? limitBumps : null,
      included_in_plans: form.value.included_in_plans?.length ? form.value.included_in_plans : null,
      industry_key: form.value.type === 'industry' ? form.value.industry_key : null,
    }

    const { data } = editing.value
      ? await centralHttp.put(`/marketplace/packs/${editing.value.id}`, payload)
      : await centralHttp.post('/marketplace/packs', payload)

    toast.add({ severity: 'success', summary: editing.value ? 'Pack updated' : 'Pack created', life: 2500 })
    if (data?.warning) {
      toast.add({ severity: 'warn', summary: 'Heads up', detail: data.warning, life: 9000 })
    }
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) errors.value = e.response.data.errors
    toast.add({ severity: 'error', summary: 'Could not save', detail: e.response?.data?.message || 'Request failed', life: 5000 })
  } finally {
    saving.value = false
  }
}

async function toggle(row: any) {
  try {
    const { data } = await centralHttp.post(`/marketplace/packs/${row.id}/toggle`)
    if (data?.warning) toast.add({ severity: 'warn', summary: 'Delisted', detail: data.warning, life: 8000 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: `Delete "${row.name}"?`,
    text: 'Only a pack nobody has ever held can be deleted — delist the others so existing holders keep their billing history.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/marketplace/packs/${row.id}`)
    toast.add({ severity: 'success', summary: 'Pack deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 5500 })
  }
}

function planLabel(slug: string): string {
  return meta.value.plans?.find((p: any) => p.slug === slug)?.name ?? slug
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <div class="filters">
        <InputText v-model="search" placeholder="Search packs…" class="search" @keyup.enter="load" />
        <Select v-model="typeFilter" :options="typeOptions" optionLabel="label" optionValue="value"
          placeholder="All shelves" showClear class="type-filter" @change="load" />
      </div>
      <Button v-if="canCreate" label="New Pack" icon="pi pi-plus" @click="openCreate" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm"
      paginator :rows="20" :rowsPerPageOptions="[20, 50]" rowGroupMode="subheader" groupRowsBy="type" sortField="type" :sortOrder="1">
      <template #groupheader="{ data }">
        <span class="group-head">{{ typeLabels[data.type] ?? data.type }} packs</span>
      </template>

      <Column header="Pack">
        <template #body="{ data }">
          <div class="pack-cell">
            <i v-if="data.icon" :class="data.icon" class="pack-icon" />
            <div class="cell-2line">
              <span class="strong">{{ data.name }}</span>
              <span class="muted">{{ data.tagline || data.slug }}</span>
            </div>
          </div>
        </template>
      </Column>
      <Column header="Grants">
        <template #body="{ data }">
          <div class="chips">
            <span v-for="m in data.module_labels" :key="m" class="chip">{{ m }}</span>
            <span v-for="(v, k) in data.limit_bumps ?? {}" :key="k" class="chip bump">+{{ v }} {{ String(k).replace('max_', '') }}</span>
            <span v-if="!data.module_labels?.length && !Object.keys(data.limit_bumps ?? {}).length" class="muted">—</span>
          </div>
        </template>
      </Column>
      <Column header="Price" style="width: 9rem">
        <template #body="{ data }">
          <template v-if="Number(data.price) > 0">
            {{ fmtMoney(data.price, data.currency) }} <span class="muted">{{ cycleLabels[data.billing_cycle] }}</span>
          </template>
          <Tag v-else value="Free" severity="secondary" />
        </template>
      </Column>
      <Column header="Bundled with" style="width: 12rem">
        <template #body="{ data }">
          <div class="chips">
            <span v-for="slug in data.included_in_plans" :key="slug" class="chip plan">{{ planLabel(slug) }}</span>
            <span v-if="!data.included_in_plans?.length" class="muted">sold separately</span>
          </div>
        </template>
      </Column>
      <Column header="Held by" style="width: 6rem">
        <template #body="{ data }">
          <span :class="data.live_grants ? 'strong' : 'muted'">{{ data.live_grants }}</span>
        </template>
      </Column>
      <Column header="Listed" style="width: 6rem">
        <template #body="{ data }">
          <Tag :severity="data.is_active ? 'success' : 'secondary'" :value="data.is_active ? 'Yes' : 'No'" />
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button v-if="canEdit" :icon="data.is_active ? 'pi pi-eye-slash' : 'pi pi-eye'" text rounded size="small"
            :severity="data.is_active ? 'warn' : 'success'" :title="data.is_active ? 'Delist' : 'List'" @click="toggle(data)" />
          <Button v-if="canDelete && !data.live_grants" icon="pi pi-trash" text rounded severity="danger" size="small"
            title="Delete" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No packs yet.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editing ? `Edit ${editing.name}` : 'New Pack'" :style="{ width: '700px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" placeholder="Restaurant Pack" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="form-field">
          <label>Slug</label>
          <InputText v-model="form.slug" :class="{ invalid: fieldError('slug') }" placeholder="derived from the name" />
          <small v-if="fieldError('slug')" class="error-text">{{ fieldError('slug') }}</small>
        </div>

        <div class="form-field">
          <label>Shelf <span class="req">*</span></label>
          <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" />
        </div>
        <div class="form-field">
          <label v-if="form.type === 'industry'">Industry</label>
          <Select v-if="form.type === 'industry'" v-model="form.industry_key" :options="meta.industries"
            optionLabel="label" optionValue="key" showClear filter placeholder="Which vertical" />
        </div>

        <div class="form-field">
          <label>Price <span class="req">*</span></label>
          <InputNumber v-model="form.price" :min="0" :minFractionDigits="0" :maxFractionDigits="2"
            :class="{ invalid: fieldError('price') }" />
          <small class="hint">0 = free (an entitlement you hand out, not sell).</small>
        </div>
        <div class="form-field">
          <label>Billing <span class="req">*</span></label>
          <Select v-model="form.billing_cycle" :options="cycleOptions" optionLabel="label" optionValue="value" />
        </div>

        <div class="form-field full">
          <label>Modules unlocked</label>
          <MultiSelect v-model="form.modules" :options="meta.modules" optionLabel="label" optionValue="key"
            placeholder="Pick the modules this pack switches on" display="chip" filter :maxSelectedLabels="4"
            :class="{ invalid: fieldError('modules') }" />
          <small v-if="fieldError('modules')" class="error-text">{{ fieldError('modules') }}</small>
          <small v-else class="hint">Granting the pack switches these on in the workspace's menu; revoking it takes them away.</small>
        </div>

        <div class="form-field full">
          <label>Limit bumps</label>
          <small class="hint">Additive headroom on the metered caps. Two packs with the same bump stack.</small>
          <div v-for="(b, idx) in bumps" :key="idx" class="bump-row">
            <Select v-model="b.key" :options="meta.limit_keys" placeholder="limit" class="bump-key" />
            <InputNumber v-model="b.value" :min="1" showButtons class="bump-value" />
            <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="bumps.splice(idx, 1)" />
          </div>
          <Button label="Add a limit bump" icon="pi pi-plus" text size="small" class="add-bump" @click="addBump" />
          <small v-if="fieldError('limit_bumps')" class="error-text">{{ fieldError('limit_bumps') }}</small>
        </div>

        <div class="form-field full">
          <label>Included in plans</label>
          <MultiSelect v-model="form.included_in_plans" :options="meta.plans" optionLabel="name" optionValue="slug"
            placeholder="Sold separately to everyone" display="chip" />
          <small class="hint">A tier listed here gets the pack's entitlement for free — nothing to buy, and no grant row. Use it when the tier's price already covers these modules.</small>
        </div>

        <div class="form-field">
          <label>Tagline</label>
          <InputText v-model="form.tagline" placeholder="Tables, kitchen tickets and running bills" />
        </div>
        <div class="form-field">
          <label>Icon class</label>
          <InputText v-model="form.icon" placeholder="pi pi-shop" />
        </div>

        <div class="form-field full">
          <label>Description</label>
          <Textarea v-model="form.description" rows="3" autoResize />
        </div>

        <div class="form-field">
          <label>Sort order</label>
          <InputNumber v-model="form.sort_order" :min="0" showButtons />
        </div>
        <div class="form-field">
          <label>Visibility</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="pk-active" />
            <label for="pk-active">Listed in the marketplace</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editing ? 'Update' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.85rem; }
.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.search { width: 200px; }
.type-filter { width: 150px; }
.group-head { font-weight: 600; font-size: 0.82rem; color: #4b5563; text-transform: uppercase; letter-spacing: 0.03em; }
.pack-cell { display: flex; align-items: center; gap: 0.6rem; }
.pack-icon { color: #6366f1; font-size: 1.05rem; }
.cell-2line { display: flex; flex-direction: column; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; font-size: 0.8rem; }
.chips { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.chip { background: #f3f4f6; border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.72rem; color: #374151; }
.chip.bump { background: #ecfdf5; color: #047857; }
.chip.plan { background: #eef2ff; color: #4338ca; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.5rem; }
.form-field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-field :deep(.p-inputtext), .form-field :deep(.p-inputnumber), .form-field :deep(.p-select),
.form-field :deep(.p-multiselect), .form-field :deep(textarea) { width: 100%; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
.bump-row { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.35rem; }
.bump-key { flex: 2; }
.bump-value { flex: 1; }
.add-bump { align-self: flex-start; margin-top: 0.35rem; }
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
</style>
