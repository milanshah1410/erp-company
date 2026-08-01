<script setup lang="ts">
/**
 * Company profile of one workspace (/platform/tenants → Profile).
 *
 * Contact details plus the two classification fields the platform reports on:
 * Industry (the vertical it was sold into) and Business Type (the trade within
 * it). Both come from the operator-maintained industry catalogue, so this form
 * offers exactly what /platform/industries currently sells.
 *
 * Changing the trade reclassifies the workspace; it deliberately does NOT
 * re-apply that trade's module preset. Re-onboarding a live workspace behind its
 * back — switching modules on and off under people who are working in it — is
 * never what "fix the industry on this account" means. Modules stay with the
 * Access panel, which says out loud that it changes entitlement.
 */
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'

const props = defineProps<{ tenant: any | null; readonly?: boolean }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const toast = useToast()

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

type Trade = { key: string; label: string; icon: string; description: string }
type Industry = { key: string; label: string; icon: string; description: string; trades: Trade[] }

const industries = ref<Industry[]>([])
const detail = ref<any | null>(null)
const errors = ref<Record<string, string[]>>({})

const states = ref<{ code: string; name: string }[]>([])

const form = ref({
  company_name: '',
  email: '',
  phone: '',
  industry: null as string | null,
  business_type: null as string | null,
  // The tenant as *our* customer: what our GST tax invoices to it are raised
  // against. The GSTIN decides the place of supply — and so whether we charge
  // CGST+SGST or IGST — which is why the manual state only shows without one.
  billing_legal_name: '',
  billing_gstin: '',
  billing_state_code: null as string | null,
  billing_address: '',
})

const industryOptions = computed(() =>
  industries.value.map((i) => ({ label: i.label, value: i.key, icon: i.icon })),
)

/**
 * Trades of the selected industry. With no industry picked yet we offer every
 * trade rather than an empty list — an operator correcting one field shouldn't
 * be forced to re-answer the other.
 */
const tradeOptions = computed(() => {
  const selected = industries.value.find((i) => i.key === form.value.industry)
  const trades = selected ? selected.trades : industries.value.flatMap((i) => i.trades)
  return trades.map((t) => ({ label: t.label, value: t.key, description: t.description }))
})

/** Industry the current trade actually belongs to, when the pair disagrees. */
const tradeIndustry = computed(() => {
  if (!form.value.business_type) return null
  return industries.value.find((i) => i.trades.some((t) => t.key === form.value.business_type)) ?? null
})

const mismatch = computed(
  () => !!form.value.industry && !!tradeIndustry.value && tradeIndustry.value.key !== form.value.industry,
)

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

// Picking an industry whose trade list excludes the current trade clears it,
// rather than saving a pair that contradicts itself.
watch(
  () => form.value.industry,
  (industry) => {
    if (!industry || !form.value.business_type) return
    const selected = industries.value.find((i) => i.key === industry)
    if (selected && !selected.trades.some((t) => t.key === form.value.business_type)) {
      form.value.business_type = null
    }
  },
)

async function load(tenantId: number) {
  loading.value = true
  errors.value = {}
  try {
    const { data } = await centralHttp.get(`/tenants/${tenantId}`)
    detail.value = data.data
    industries.value = data.industries ?? []
    states.value = data.states ?? []
    form.value = {
      company_name: data.data.company_name ?? '',
      email: data.data.email ?? '',
      phone: data.data.phone ?? '',
      industry: data.data.industry ?? null,
      business_type: data.data.business_type ?? null,
      billing_legal_name: data.data.billing_legal_name ?? '',
      billing_gstin: data.data.billing_gstin ?? '',
      billing_state_code: data.data.billing_state_code ?? null,
      billing_address: data.data.billing_address ?? '',
    }
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the profile', life: 4000 })
    visible.value = false
  } finally {
    loading.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function save() {
  if (!props.tenant) return
  saving.value = true
  errors.value = {}
  try {
    await centralHttp.put(`/tenants/${props.tenant.id}`, {
      company_name: form.value.company_name,
      email: form.value.email || null,
      phone: form.value.phone || null,
      industry: form.value.industry,
      business_type: form.value.business_type,
      billing_legal_name: form.value.billing_legal_name || null,
      billing_gstin: form.value.billing_gstin || null,
      // With a GSTIN on file the state is read from it, so sending a manual code
      // too could only introduce a contradiction.
      billing_state_code: form.value.billing_gstin ? null : form.value.billing_state_code,
      billing_address: form.value.billing_address || null,
    })
    toast.add({ severity: 'success', summary: 'Profile updated', life: 2500 })
    emit('saved')
    visible.value = false
  } catch (e: any) {
    if (e.response?.status === 422 && e.response.data.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="`Profile — ${props.tenant?.company_name ?? ''}`"
    :style="{ width: '560px' }"
    :closable="!saving"
  >
    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading profile…</div>

    <template v-else>
      <!-- Identity that is not editable here: the workspace address is the
           tenant's URL and its login identity, changed only by provisioning. -->
      <div class="identity">
        <div class="identity-row">
          <span>Workspace</span>
          <b class="mono">{{ detail?.subdomain ?? '—' }}</b>
        </div>
        <div class="identity-row">
          <span>Plan</span>
          <b>{{ detail?.plan ?? '—' }}</b>
        </div>
        <div class="identity-row">
          <span>Status</span>
          <Tag
            :value="detail?.status"
            :severity="detail?.status === 'active' ? 'success' : detail?.status === 'trial' ? 'warn' : 'danger'"
          />
        </div>
        <div class="identity-row">
          <span>Created</span>
          <b>{{ detail?.created_at ?? '—' }}</b>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-field full">
          <label>Company Name</label>
          <InputText v-model="form.company_name" :disabled="props.readonly" :class="{ invalid: fieldError('company_name') }" />
          <small v-if="fieldError('company_name')" class="error-text">{{ fieldError('company_name') }}</small>
        </div>
        <div class="form-field">
          <label>Email</label>
          <InputText v-model="form.email" :disabled="props.readonly" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
        </div>
        <div class="form-field">
          <label>Phone</label>
          <InputText v-model="form.phone" :disabled="props.readonly" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>
        <div class="form-field">
          <label>Industry</label>
          <Select
            v-model="form.industry"
            :options="industryOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Not classified"
            showClear
            :disabled="props.readonly"
          />
          <small v-if="fieldError('industry')" class="error-text">{{ fieldError('industry') }}</small>
        </div>
        <div class="form-field">
          <label>Business Type</label>
          <Select
            v-model="form.business_type"
            :options="tradeOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Not classified"
            showClear
            :disabled="props.readonly"
          />
          <small v-if="fieldError('business_type')" class="error-text">{{ fieldError('business_type') }}</small>
          <small v-else-if="mismatch" class="hint warn">
            This trade sits under {{ tradeIndustry?.label }} — saving keeps the industry you picked.
          </small>
          <small v-else class="hint">The trade within the industry.</small>
        </div>
      </div>

      <p class="note">
        <i class="pi pi-info-circle" />
        Reclassifying a workspace updates reporting and its own trade setting. It does
        not switch modules on or off — use Access for that.
      </p>

      <div class="section-title">Billing identity</div>
      <p class="section-note">
        How this workspace appears on <em>our</em> invoices to it. The GSTIN decides the place of
        supply, and so whether we charge CGST + SGST or IGST.
      </p>

      <div class="form-grid">
        <div class="form-field full">
          <label>Registered legal name</label>
          <InputText v-model="form.billing_legal_name" :disabled="props.readonly"
            :placeholder="form.company_name" :class="{ invalid: fieldError('billing_legal_name') }" />
          <small class="hint">Blank uses the company name above.</small>
        </div>
        <div class="form-field">
          <label>GSTIN</label>
          <InputText v-model="form.billing_gstin" :disabled="props.readonly" maxlength="15" class="mono"
            placeholder="24ABCDE1234F1Z5" :class="{ invalid: fieldError('billing_gstin') }"
            @input="form.billing_gstin = form.billing_gstin.toUpperCase()" />
          <small v-if="fieldError('billing_gstin')" class="error-text">{{ fieldError('billing_gstin') }}</small>
          <small v-else-if="detail?.place_of_supply_label" class="hint">
            Place of supply: {{ detail.place_of_supply_label }} ({{ detail.place_of_supply }}).
          </small>
          <small v-else class="hint">Unregistered customers can be left blank.</small>
        </div>
        <div v-if="!form.billing_gstin" class="form-field">
          <label>State (place of supply)</label>
          <Select v-model="form.billing_state_code" :options="states" optionLabel="name" optionValue="code"
            :disabled="props.readonly" placeholder="Not set" showClear filter />
          <small v-if="fieldError('billing_state_code')" class="error-text">{{ fieldError('billing_state_code') }}</small>
          <small v-else class="hint">Only needed without a GSTIN.</small>
        </div>
        <div class="form-field full">
          <label>Billing address</label>
          <Textarea v-model="form.billing_address" :disabled="props.readonly" rows="2" autoResize />
        </div>
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
.identity {
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
.identity-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.identity-row span {
  color: var(--muted, #6f7680);
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}
.form-field.full {
  grid-column: 1 / -1;
}
.form-field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.form-field :deep(.p-inputtext),
.form-field :deep(.p-select),
.form-field :deep(textarea) {
  width: 100%;
}
.section-title {
  font-size: 0.82rem;
  font-weight: 600;
  color: #374151;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  margin: 1.25rem 0 0.2rem;
}
.section-note {
  font-size: 0.75rem;
  color: #6b7280;
  margin-bottom: 0.85rem;
}
.hint {
  color: #6b7280;
  font-size: 0.75rem;
}
.hint.warn {
  color: #8a5a12;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.note {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 1rem 0 0;
  font-size: 0.78rem;
  line-height: 1.45;
  color: var(--muted, #6b7280);
}
@media (max-width: 640px) {
  .form-grid,
  .identity {
    grid-template-columns: 1fr;
  }
}
</style>
