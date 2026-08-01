<script setup lang="ts">
/**
 * Plan management for one workspace (/platform/tenants → Plan).
 *
 * The plan *catalogue* lives at /platform/plans and the billing ledgers under
 * Billing → Subscriptions; neither answers "what is this customer on, and move
 * them", which is the question asked from the tenant list. This dialog is that
 * one action, against the existing subscription endpoints.
 *
 * The part worth the screen space is the comparison: a downgrade is only safe if
 * the workspace already fits inside the smaller plan, so the target's caps are
 * shown against real usage and anything that would land over the line is called
 * out before saving. Nothing is deleted either way — going over a cap only blocks
 * *new* records (EnforcePlanLimit), so this is a warning, not a refusal.
 *
 * Dates, offline payments and cancellation stay in Billing → Subscriptions. This
 * is deliberately not a second copy of that screen.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'

const props = defineProps<{ tenant: any | null }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('subscriptions.edit'))

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

type Plan = {
  id: number
  slug: string
  name: string
  price: string | number
  currency: string | null
  billing_cycle: string
  is_custom: boolean
  is_active: boolean
  feature_limits: Record<string, any> | null
  modules: string[]
  modules_all: boolean
}

type LimitRow = { key: string; label: string; usage: number }

const subscription = ref<any | null>(null)
const plans = ref<Plan[]>([])
const usage = ref<LimitRow[]>([])
const targetPlanId = ref<number | null>(null)

/** Only sellable plans are offered — a retired tier is history, not a choice. */
const planOptions = computed(() =>
  plans.value
    .filter((p) => p.is_active)
    .map((p) => ({
      label: p.is_custom ? `${p.name} (quote-based)` : `${p.name} — ${money(p)}`,
      value: p.id,
    })),
)

const currentPlan = computed(() => plans.value.find((p) => p.id === subscription.value?.plan_id) ?? null)
const targetPlan = computed(() => plans.value.find((p) => p.id === targetPlanId.value) ?? null)
const changed = computed(() => !!targetPlanId.value && targetPlanId.value !== subscription.value?.plan_id)

function money(plan: Plan): string {
  const symbol = plan.currency === 'USD' ? '$' : plan.currency === 'EUR' ? '€' : '₹'
  return `${symbol}${Number(plan.price ?? 0).toLocaleString('en-IN')}/${plan.billing_cycle === 'yearly' ? 'yr' : 'mo'}`
}

function limitOf(plan: Plan | null, key: string): number | null {
  const value = plan?.feature_limits?.[key]
  return typeof value === 'number' ? value : null
}

function moduleCount(plan: Plan | null): string {
  if (!plan) return '—'
  return plan.modules_all ? `All ${plan.modules.length}` : String(plan.modules.length)
}

/**
 * Per-resource comparison of the two plans against what the workspace uses.
 * `tight` marks a target cap the tenant is already past.
 */
const comparison = computed(() => {
  if (!targetPlan.value) return []
  return usage.value.map((row) => {
    const to = limitOf(targetPlan.value, row.key)
    return {
      label: row.label,
      usage: row.usage,
      from: limitOf(currentPlan.value, row.key),
      to,
      tight: to !== null && row.usage > to,
    }
  })
})

/** Modules the target plan sells that the current one doesn't, and vice versa. */
const moduleDelta = computed(() => {
  if (!targetPlan.value || !currentPlan.value) return { gained: [] as string[], lost: [] as string[] }
  const from = new Set(currentPlan.value.modules)
  const to = new Set(targetPlan.value.modules)
  return {
    gained: targetPlan.value.modules.filter((m) => !from.has(m)),
    lost: currentPlan.value.modules.filter((m) => !to.has(m)),
  }
})

const blockers = computed(() => comparison.value.filter((c) => c.tight))

watch(
  () => props.tenant,
  (tenant) => {
    if (tenant) {
      visible.value = true
      void load(tenant)
    }
  },
  { immediate: true },
)

watch(visible, (open) => {
  if (!open) emit('close')
})

async function load(tenant: any) {
  loading.value = true
  subscription.value = null
  usage.value = []
  try {
    // The plan catalogue and the tenant's usage are both needed to render a
    // meaningful change; fetch them alongside the subscription itself.
    const [planRes, limitRes] = await Promise.all([
      centralHttp.get('/billing/plans'),
      centralHttp.get(`/tenants/${tenant.id}/limits`),
    ])
    plans.value = planRes.data.data ?? []
    usage.value = (limitRes.data.data?.resources ?? []).map((r: any) => ({
      key: r.key,
      label: r.label,
      usage: r.usage,
    }))

    if (tenant.subscription_id) {
      const { data } = await centralHttp.get(`/billing/subscriptions/${tenant.subscription_id}`)
      subscription.value = data.data
      targetPlanId.value = data.data.plan_id
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the plan', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!subscription.value || !targetPlan.value) return

  const warning = blockers.value.length
    ? `<br><br><b>${targetPlan.value.name} is tighter than current usage:</b><br>` +
      blockers.value.map((b) => `${b.label}: ${b.usage} in use, cap ${b.to}`).join('<br>') +
      '<br><small>Nothing is deleted — the workspace just cannot create more until it is under the cap.</small>'
    : ''

  const res = await Swal.fire({
    title: `Move to ${targetPlan.value.name}?`,
    html:
      `<b>${props.tenant?.company_name}</b> moves from ${currentPlan.value?.name ?? '—'} to ` +
      `${targetPlan.value.name}. Entitlement changes immediately.${warning}`,
    icon: blockers.value.length ? 'warning' : 'question',
    showCancelButton: true,
    confirmButtonText: 'Change plan',
    confirmButtonColor: blockers.value.length ? '#dc2626' : undefined,
  })
  if (!res.isConfirmed) return

  saving.value = true
  try {
    const { data } = await centralHttp.patch(`/billing/subscriptions/${subscription.value.id}`, {
      plan_id: targetPlan.value.id,
    })
    subscription.value = data.data
    targetPlanId.value = data.data.plan_id
    toast.add({ severity: 'success', summary: 'Plan changed', detail: `Now on ${data.data.plan_name}.`, life: 3000 })
    emit('saved')
    visible.value = false
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Plan change failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

function statusSeverity(status: string): 'success' | 'warn' | 'danger' | 'secondary' {
  if (status === 'active') return 'success'
  if (status === 'trialing') return 'warn'
  if (status === 'past_due' || status === 'suspended') return 'danger'
  return 'secondary'
}

function cap(value: number | null): string {
  return value === null ? 'Unlimited' : String(value)
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Plan — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '620px' }"
    :closable="!saving"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading plan…</div>

    <div v-else-if="!subscription" class="empty">
      <i class="pi pi-exclamation-triangle" />
      <p>
        This workspace has no subscription on record, so there is no plan to manage.
        Provisioning normally creates one — check Billing → Subscriptions.
      </p>
    </div>

    <template v-else>
      <div class="current">
        <div class="current-row">
          <span>Current plan</span>
          <b>{{ subscription.plan_name ?? '—' }}<template v-if="currentPlan"> · {{ money(currentPlan) }}</template></b>
        </div>
        <div class="current-row">
          <span>Subscription</span>
          <Tag :value="subscription.status" :severity="statusSeverity(subscription.status)" />
        </div>
        <div class="current-row">
          <span>Trial ends</span>
          <b>{{ subscription.trial_ends_at ?? '—' }}</b>
        </div>
        <div class="current-row">
          <span>Period ends</span>
          <b>{{ subscription.current_period_end ?? '—' }}</b>
        </div>
      </div>

      <p v-if="subscription.pending_plan_name" class="pending">
        <i class="pi pi-clock" />
        The tenant already asked to move to <b>{{ subscription.pending_plan_name }}</b> and hasn't paid
        yet. Changing the plan here applies immediately and leaves that request unpaid.
      </p>

      <label class="field-label">Move to</label>
      <Select
        v-model="targetPlanId"
        :options="planOptions"
        optionLabel="label"
        optionValue="value"
        :disabled="!canEdit"
        class="plan-select"
      />
      <p v-if="targetPlan?.is_custom" class="hint">
        A quote-based plan carries no list price — record the agreed amount as an offline
        payment in Billing → Subscriptions.
      </p>

      <template v-if="changed && targetPlan">
        <div class="compare">
          <div class="compare-head">
            <span>Resource</span>
            <span>In use</span>
            <span>{{ currentPlan?.name ?? 'Now' }}</span>
            <span>{{ targetPlan.name }}</span>
          </div>
          <div v-for="row in comparison" :key="row.label" class="compare-row" :class="{ tight: row.tight }">
            <span>{{ row.label }}</span>
            <span>{{ row.usage }}</span>
            <span class="muted">{{ cap(row.from) }}</span>
            <span>
              {{ cap(row.to) }}
              <i v-if="row.tight" class="pi pi-exclamation-triangle" title="Already past this cap" />
            </span>
          </div>
          <div class="compare-row">
            <span>Modules</span>
            <span class="muted">—</span>
            <span class="muted">{{ moduleCount(currentPlan) }}</span>
            <span>{{ moduleCount(targetPlan) }}</span>
          </div>
        </div>

        <p v-if="moduleDelta.lost.length" class="note lost">
          <i class="pi pi-arrow-down" />
          Loses {{ moduleDelta.lost.length }} module(s): {{ moduleDelta.lost.join(', ') }}. Anything
          switched on beyond the new plan stops being available unless it was comped in Access.
        </p>
        <p v-else-if="moduleDelta.gained.length" class="note gained">
          <i class="pi pi-arrow-up" />
          Gains {{ moduleDelta.gained.length }} module(s): {{ moduleDelta.gained.join(', ') }}. The
          tenant still has to switch them on.
        </p>

        <p v-if="blockers.length" class="note lost">
          <i class="pi pi-exclamation-triangle" />
          Usage is already past {{ blockers.length }} cap(s) on {{ targetPlan.name }}. Existing
          records are untouched; only new ones are blocked. Consider a limit override instead.
        </p>
      </template>
    </template>

    <template #footer>
      <Button label="Close" text :disabled="saving" @click="visible = false" />
      <Button
        v-if="canEdit && subscription"
        label="Change plan"
        :disabled="!changed"
        :loading="saving"
        @click="save"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.loading,
.empty {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 1.6rem 1rem;
  justify-content: center;
  color: #6b7280;
  text-align: center;
}
.empty i {
  font-size: 1.4rem;
  color: var(--safety-amber, #e8a33d);
}
.empty p {
  margin: 0;
  font-size: 0.85rem;
  line-height: 1.5;
}
.current {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem 1rem;
  padding: 0.7rem 0.85rem;
  margin-bottom: 1rem;
  border-radius: 10px;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
  font-size: 0.82rem;
}
.current-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.current-row span {
  color: var(--muted, #6f7680);
}
.pending {
  display: flex;
  align-items: flex-start
  ;
  gap: 0.45rem;
  margin: 0 0 1rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.79rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.field-label {
  display: block;
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.35rem;
}
.plan-select {
  width: 100%;
}
.hint {
  margin: 0.4rem 0 0;
  font-size: 0.76rem;
  color: var(--muted, #6b7280);
}
.compare {
  margin-top: 1.1rem;
  border: 1px solid var(--border, #e3ded3);
  border-radius: 10px;
  overflow: hidden;
  font-size: 0.8rem;
}
.compare-head,
.compare-row {
  display: grid;
  grid-template-columns: 1.4fr 0.7fr 1fr 1fr;
  gap: 0.5rem;
  padding: 0.42rem 0.7rem;
  align-items: center;
}
.compare-head {
  background: var(--surface-2, #f2f0ea);
  font-weight: 700;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--muted, #6f7680);
}
.compare-row + .compare-row,
.compare-head + .compare-row {
  border-top: 1px solid var(--border, #e3ded3);
}
.compare-row.tight {
  background: color-mix(in srgb, #dc2626 7%, transparent);
  color: #b91c1c;
  font-weight: 600;
}
.compare-row.tight i {
  margin-left: 0.2rem;
}
.muted {
  color: var(--muted, #9ca3af);
}
.note {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0.8rem 0 0;
  font-size: 0.78rem;
  line-height: 1.45;
}
.note.lost {
  color: #b91c1c;
}
.note.gained {
  color: #15803d;
}
@media (max-width: 640px) {
  .current {
    grid-template-columns: 1fr;
  }
}
</style>
