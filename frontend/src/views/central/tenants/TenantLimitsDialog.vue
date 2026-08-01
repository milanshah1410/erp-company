<script setup lang="ts">
/**
 * Resource limits of one workspace (/platform/tenants → Limits).
 *
 * Three metered resources — products, warehouses, users — shown as usage against
 * the effective cap, with a per-tenant override beside each. The override exists
 * so a customer can be comped ten extra seats without minting a bespoke plan for
 * them, and so that concession survives a plan change.
 *
 * Blank means "unlimited for this tenant", which is different from clearing the
 * override (the ⟲ button), which hands the resource back to the plan. Only
 * creation is metered, so tightening a cap below current usage never makes
 * existing records unreachable — the panel flags that state instead of hiding it.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'

const props = defineProps<{ tenant: any | null; readonly?: boolean }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const toast = useToast()

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

type Resource = {
  resource: string
  key: string
  label: string
  plan_limit: number | null
  /** Extra headroom bought through a marketplace pack, added to the plan's cap. */
  pack_bump: number | null
  override: number | null
  has_override: boolean
  limit: number | null
  unlimited: boolean
  usage: number
  remaining: number | null
  exceeded: boolean
}

const summary = ref<{ plan: { slug: string; name: string } | null; resources: Resource[]; plan_features: Record<string, any> } | null>(null)

/** Working state: limit key => override value (null = unlimited), absent = plan. */
const overrides = ref<Record<string, number | null>>({})

const resources = computed(() => summary.value?.resources ?? [])

/** Plan feature flags worth showing, minus the module list (Access owns that). */
const planFeatures = computed(() => {
  const features = summary.value?.plan_features ?? {}
  return Object.entries(features)
    .filter(([key]) => key !== 'modules')
    .map(([key, value]) => ({
      key,
      label: key.replace(/_/g, ' '),
      value: typeof value === 'boolean' ? (value ? 'Yes' : 'No') : String(value ?? '—'),
      on: typeof value === 'boolean' ? value : null,
    }))
})

watch(
  () => props.tenant,
  (tenant) => {
    if (tenant) {
      visible.value = true
      void load(tenant.id)
    }
  },
  { immediate: true },
)

watch(visible, (open) => {
  if (!open) emit('close')
})

async function load(tenantId: number) {
  loading.value = true
  try {
    const { data } = await centralHttp.get(`/tenants/${tenantId}/limits`)
    summary.value = data.data
    overrides.value = {}
    for (const row of data.data.resources as Resource[]) {
      if (row.has_override) overrides.value[row.key] = row.override
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load limits', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

function hasOverride(key: string): boolean {
  return Object.prototype.hasOwnProperty.call(overrides.value, key)
}

/**
 * Start overriding a resource, seeded from whatever it currently allows —
 * the plan's cap plus any pack bump, so opening an override never silently
 * takes away headroom the workspace has paid for.
 */
function addOverride(row: Resource) {
  overrides.value = { ...overrides.value, [row.key]: planWithPack(row) }
}

/** The plan's cap raised by the tenant's pack bumps. Null stays unlimited. */
function planWithPack(row: Resource): number | null {
  if (row.plan_limit === null) return null
  return row.plan_limit + (row.pack_bump ?? 0)
}

/** Drop the override — the resource goes back to following the plan. */
function clearOverride(key: string) {
  const next = { ...overrides.value }
  delete next[key]
  overrides.value = next
}

/**
 * What the cap would be once saved, for the live preview under each row.
 * Mirrors TenantLimits::effective(): a pack bump raises the plan's number, an
 * override replaces the result outright.
 */
function effective(row: Resource): number | null {
  return hasOverride(row.key) ? overrides.value[row.key] : planWithPack(row)
}

function usageSeverity(row: Resource): 'success' | 'warn' | 'danger' {
  const limit = effective(row)
  if (limit === null) return 'success'
  if (row.usage > limit) return 'danger'
  return row.usage / Math.max(limit, 1) >= 0.8 ? 'warn' : 'success'
}

function barWidth(row: Resource): string {
  const limit = effective(row)
  if (limit === null || limit === 0) return row.usage > 0 ? '100%' : '0%'
  return `${Math.min(100, Math.round((row.usage / limit) * 100))}%`
}

function describe(row: Resource): string {
  const limit = effective(row)
  if (limit === null) return `${row.usage} used · unlimited`
  if (row.usage > limit) return `${row.usage} used · ${row.usage - limit} over the ${limit} cap`
  return `${row.usage} of ${limit} used`
}

async function save() {
  if (!props.tenant) return
  saving.value = true
  try {
    const { data } = await centralHttp.put(`/tenants/${props.tenant.id}/limits`, {
      overrides: overrides.value,
    })
    summary.value = data.data
    toast.add({ severity: 'success', summary: 'Limits updated', life: 2500 })
    emit('saved')
    visible.value = false
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Limits — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '620px' }"
    :closable="!saving"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading limits…</div>

    <template v-else>
      <p class="intro">
        <i class="pi pi-info-circle" />
        Caps come from the
        <b>{{ summary?.plan?.name ?? 'current' }}</b>
        plan. An override applies to this workspace only and survives a plan change;
        leave it blank for unlimited, or clear it to follow the plan again. Only new
        records are blocked — nothing already created is affected.
      </p>

      <div v-for="row in resources" :key="row.key" class="resource">
        <div class="resource-head">
          <span class="resource-label">{{ row.label }}</span>
          <Tag :severity="usageSeverity(row)" :value="describe(row)" class="usage-tag" />
          <Tag v-if="hasOverride(row.key)" value="override" severity="info" class="usage-tag" />
          <Tag v-else-if="row.pack_bump" :value="`+${row.pack_bump} from a pack`" severity="success" class="usage-tag" />
        </div>

        <div class="meter" :class="usageSeverity(row)">
          <div class="meter-fill" :style="{ width: barWidth(row) }" />
        </div>

        <div class="resource-controls">
          <span class="plan-limit">
            Plan: <b>{{ row.plan_limit === null ? 'Unlimited' : row.plan_limit }}</b>
            <template v-if="row.pack_bump"> + {{ row.pack_bump }} = <b>{{ planWithPack(row) }}</b></template>
          </span>

          <template v-if="hasOverride(row.key)">
            <InputNumber
              v-model="overrides[row.key]"
              :min="0"
              :useGrouping="false"
              placeholder="Unlimited"
              showButtons
              :disabled="props.readonly"
              class="override-input"
            />
            <Button
              v-if="!props.readonly"
              icon="pi pi-undo"
              text
              rounded
              size="small"
              title="Clear the override — follow the plan"
              @click="clearOverride(row.key)"
            />
          </template>
          <Button
            v-else-if="!props.readonly"
            label="Override"
            icon="pi pi-pencil"
            text
            size="small"
            @click="addOverride(row)"
          />
        </div>
      </div>

      <div v-if="planFeatures.length" class="features">
        <div class="features-head">Plan features</div>
        <div class="feature-grid">
          <div v-for="f in planFeatures" :key="f.key" class="feature">
            <span class="feature-label">{{ f.label }}</span>
            <Tag
              :value="f.value"
              :severity="f.on === null ? 'secondary' : f.on ? 'success' : 'secondary'"
            />
          </div>
        </div>
        <p class="note">Change these by changing the plan (Subscription Plans).</p>
      </div>
    </template>

    <template #footer>
      <Button label="Close" text :disabled="saving" @click="visible = false" />
      <Button v-if="!props.readonly && !loading" label="Save" :loading="saving" @click="save" />
    </template>
  </Dialog>
</template>

<style scoped>
.loading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem;
  justify-content: center;
  color: #6b7280;
}
.intro {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 1.1rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.resource {
  padding: 0.7rem 0.85rem;
  border-radius: 10px;
  border: 1px solid var(--border, #e3ded3);
  margin-bottom: 0.7rem;
}
.resource-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.resource-label {
  font-size: 0.88rem;
  font-weight: 600;
}
.usage-tag {
  font-size: 0.72rem;
}
.meter {
  height: 6px;
  border-radius: 999px;
  background: var(--surface-2, #f2f0ea);
  overflow: hidden;
  margin: 0.55rem 0;
}
.meter-fill {
  height: 100%;
  border-radius: 999px;
  background: #16a34a;
  transition: width 0.2s ease;
}
.meter.warn .meter-fill {
  background: var(--safety-amber, #e8a33d);
}
.meter.danger .meter-fill {
  background: #dc2626;
}
.resource-controls {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}
.plan-limit {
  font-size: 0.78rem;
  color: var(--muted, #6f7680);
}
.override-input {
  width: 9.5rem;
}
.override-input :deep(.p-inputtext) {
  width: 100%;
}
.features {
  margin-top: 1.2rem;
}
.features-head {
  font-size: 0.82rem;
  font-weight: 700;
  margin-bottom: 0.5rem;
}
.feature-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 0.4rem 0.9rem;
}
.feature {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.8rem;
}
.feature-label {
  text-transform: capitalize;
  color: var(--muted, #6f7680);
}
.note {
  margin: 0.7rem 0 0;
  font-size: 0.76rem;
  color: var(--muted, #6b7280);
}
</style>
