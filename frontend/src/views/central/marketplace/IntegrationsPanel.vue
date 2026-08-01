<script setup lang="ts">
/**
 * Integration Marketplace (/platform/marketplace → Integrations) —
 * Volume 11 §"Integration Marketplace".
 *
 * What the platform can connect to, and what the owner has decided to sell.
 * Three levers per entry, and only three, because the entry itself is code: a
 * connector ships in a release, so this screen can never create or delete one.
 *
 *   on sale   — hides it from every tenant's picker and refuses new connections
 *   plans     — restricts it to the tiers that pay for it
 *   beta      — says so, without withholding it
 *
 * Adoption counts are measured from the tenant tables each integration actually
 * writes to, not from configuration. A live connector sitting at zero after a
 * year is the most useful thing this screen can tell an owner, so the count is
 * clickable: "who?" is the next question, and retiring something is a decision
 * you make by name, not by aggregate.
 *
 * Withdrawing an integration is deliberately not retroactive — tenants already
 * connected keep syncing. Cutting a live channel mid-season is a support action,
 * not a side-effect of editing a price list.
 */
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('integrations.edit'))

type Integration = {
  key: string
  label: string
  category: string
  category_label: string
  icon: string
  vendor: string
  description: string
  status: 'live' | 'planned'
  is_available: boolean
  module: string | null
  configured_at: string | null
  is_enabled: boolean
  is_beta: boolean
  plan_slugs: string[] | null
  note: string | null
  tenants_count: number
}

type PlanOption = { slug: string; name: string }

const items = ref<Integration[]>([])
const plans = ref<PlanOption[]>([])
const categories = ref<Record<string, string>>({})
const loading = ref(false)
const savingKey = ref<string | null>(null)

const editing = ref<Integration | null>(null)
const dialogVisible = ref(false)
const saving = ref(false)
const form = ref<{ plan_slugs: string[]; is_beta: boolean; note: string }>({
  plan_slugs: [],
  is_beta: false,
  note: '',
})

const tenantsFor = ref<Integration | null>(null)
const tenantsVisible = ref(false)
const tenantsLoading = ref(false)
const tenants = ref<any[]>([])

const planOptions = computed(() => plans.value.map((p) => ({ label: p.name, value: p.slug })))
const planNames = computed(() => new Map(plans.value.map((p) => [p.slug, p.name])))

/** Catalogue order is meaningful, so group without re-sorting inside a group. */
const grouped = computed(() => {
  const out: { key: string; label: string; items: Integration[] }[] = []
  for (const item of items.value) {
    let group = out.find((g) => g.key === item.category)
    if (!group) {
      group = { key: item.category, label: item.category_label, items: [] }
      out.push(group)
    }
    group.items.push(item)
  }
  return out
})

const liveCount = computed(() => items.value.filter((i) => i.is_available && i.is_enabled).length)
const adoptedCount = computed(() => items.value.filter((i) => i.tenants_count > 0).length)

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/marketplace/integrations')
    items.value = data.data ?? []
    plans.value = data.plans ?? []
    categories.value = data.categories ?? {}
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the marketplace', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function patch(item: Integration, payload: Record<string, unknown>) {
  savingKey.value = item.key
  try {
    await centralHttp.patch(`/marketplace/integrations/${item.key}`, payload)
    await load()
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: 'Not changed',
      detail: e.response?.data?.message || 'Could not update that integration',
      life: 5000,
    })
    // Reload anyway so an optimistic toggle can't leave the switch lying.
    await load()
  } finally {
    savingKey.value = null
  }
}

function toggle(item: Integration) {
  patch(item, { is_enabled: !item.is_enabled })
}

function openEdit(item: Integration) {
  editing.value = item
  form.value = {
    plan_slugs: [...(item.plan_slugs ?? [])],
    is_beta: item.is_beta,
    note: item.note ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  if (!editing.value) return
  saving.value = true
  try {
    await centralHttp.patch(`/marketplace/integrations/${editing.value.key}`, {
      // An empty selection means every plan; the API normalises [] to null.
      plan_slugs: form.value.plan_slugs,
      is_beta: form.value.is_beta,
      note: form.value.note || null,
    })
    toast.add({ severity: 'success', summary: 'Updated', life: 2200 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Save failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function showTenants(item: Integration) {
  if (!item.tenants_count) return
  tenantsFor.value = item
  tenants.value = []
  tenantsVisible.value = true
  tenantsLoading.value = true
  try {
    const { data } = await centralHttp.get(`/marketplace/integrations/${item.key}/tenants`)
    tenants.value = data.data ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load tenants', life: 4000 })
  } finally {
    tenantsLoading.value = false
  }
}

function planLabel(slug: string): string {
  return planNames.value.get(slug) ?? slug
}

onMounted(load)
</script>

<template>
  <div>
    <p class="intro">
      <i class="pi pi-info-circle" />
      Everything the product can connect to. Switching one off hides it from every tenant's
      picker and refuses new connections — workspaces already connected keep syncing, so this
      is a decision about what you sell next, not a way to cut a customer off. Roadmap entries
      are listed but cannot be put on sale until their connector ships.
    </p>

    <div class="stats">
      <div class="stat">
        <span class="stat-value">{{ liveCount }}</span>
        <span class="stat-label">on sale</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ adoptedCount }}</span>
        <span class="stat-label">with connected tenants</span>
      </div>
      <div class="stat">
        <span class="stat-value">{{ items.filter((i) => i.status === 'planned').length }}</span>
        <span class="stat-label">on the roadmap</span>
      </div>
    </div>

    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading catalogue…</div>

    <section v-for="group in grouped" :key="group.key" class="group">
      <h2 class="group-title">{{ group.label }}</h2>

      <div class="cards">
        <article
          v-for="item in group.items"
          :key="item.key"
          class="card"
          :class="{ off: !item.is_enabled, planned: !item.is_available }"
        >
          <div class="card-head">
            <div class="ident">
              <i :class="item.icon" class="ident-icon" />
              <div>
                <div class="ident-name">
                  {{ item.label }}
                  <Tag v-if="!item.is_available" value="roadmap" severity="secondary" class="mini" />
                  <Tag v-else-if="item.is_beta" value="beta" severity="warn" class="mini" />
                </div>
                <div class="ident-vendor">{{ item.vendor }}</div>
              </div>
            </div>

            <ToggleSwitch
              :modelValue="item.is_enabled"
              :disabled="!canEdit || !item.is_available || savingKey === item.key"
              :title="
                !item.is_available
                  ? 'The connector has not shipped yet'
                  : item.is_enabled
                    ? 'On sale — tenants can connect it'
                    : 'Withdrawn — hidden from tenant pickers'
              "
              @update:modelValue="toggle(item)"
            />
          </div>

          <p class="desc">{{ item.description }}</p>

          <div class="meta">
            <button
              class="adoption"
              :class="{ clickable: item.tenants_count > 0 }"
              :disabled="!item.tenants_count"
              @click="showTenants(item)"
            >
              <i class="pi pi-building" />
              {{ item.tenants_count }} {{ item.tenants_count === 1 ? 'tenant' : 'tenants' }}
            </button>

            <span v-if="item.plan_slugs?.length" class="plans" title="Restricted to these plans">
              <i class="pi pi-lock" />
              <span v-for="slug in item.plan_slugs" :key="slug" class="chip">{{ planLabel(slug) }}</span>
            </span>
            <span v-else class="plans muted"><i class="pi pi-unlock" /> all plans</span>
          </div>

          <div class="card-foot">
            <small v-if="item.note" class="note">{{ item.note }}</small>
            <small v-else-if="item.configured_at" class="muted">{{ item.configured_at }}</small>
            <small v-else class="muted">Not yet available to tenants</small>

            <Button
              v-if="canEdit"
              icon="pi pi-cog"
              text
              rounded
              size="small"
              title="Plan availability, beta flag & note"
              @click="openEdit(item)"
            />
          </div>
        </article>
      </div>
    </section>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? `${editing.label} — availability` : ''"
      :style="{ width: '520px' }"
      :closable="!saving"
    >
      <div v-if="editing" class="form">
        <div class="form-field">
          <label>Available on</label>
          <MultiSelect
            v-model="form.plan_slugs"
            :options="planOptions"
            optionLabel="label"
            optionValue="value"
            display="chip"
            placeholder="Every plan"
            class="w-full"
          />
          <small class="hint">
            Leave empty to offer it on every plan. Restricting it hides it from tenants on other
            tiers and refuses their connect attempts — existing connections are untouched.
          </small>
        </div>

        <div class="form-field row">
          <ToggleSwitch v-model="form.is_beta" inputId="beta" />
          <label for="beta" class="inline">Flag as beta</label>
        </div>
        <small class="hint">Beta shows a badge to operators. It does not withhold the integration.</small>

        <div class="form-field">
          <label>Internal note</label>
          <InputText v-model="form.note" placeholder="e.g. rate-limited above 5k orders/day" class="w-full" />
          <small class="hint">Visible on this screen only.</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text :disabled="saving" @click="dialogVisible = false" />
        <Button label="Save" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="tenantsVisible"
      modal
      :header="tenantsFor ? `${tenantsFor.label} — connected workspaces` : ''"
      :style="{ width: '640px' }"
    >
      <DataTable :value="tenants" :loading="tenantsLoading" class="p-datatable-sm" dataKey="id">
        <Column field="company_name" header="Workspace" sortable />
        <Column field="subdomain" header="Address" style="width: 11rem">
          <template #body="{ data }"><code class="key">{{ data.subdomain }}</code></template>
        </Column>
        <Column field="plan" header="Plan" style="width: 9rem">
          <template #body="{ data }">{{ data.plan ?? '—' }}</template>
        </Column>
        <Column field="status" header="Status" style="width: 7rem">
          <template #body="{ data }">
            <Tag :value="data.status" :severity="data.status === 'active' ? 'success' : 'secondary'" class="mini" />
          </template>
        </Column>
        <template #empty>No workspaces have connected this integration.</template>
      </DataTable>
    </Dialog>
  </div>
</template>

<style scoped>
.intro {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 1rem;
  padding: 0.6rem 0.8rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 30%, transparent);
}
.stats { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.stat {
  display: flex; flex-direction: column; min-width: 8rem;
  padding: 0.6rem 0.9rem; border-radius: 10px;
  background: #fff; border: 1px solid #e5e7eb;
}
.stat-value { font-size: 1.35rem; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: 0.74rem; color: #6b7280; }
.loading { color: #6b7280; font-size: 0.88rem; padding: 1rem 0; }
.group { margin-bottom: 1.5rem; }
.group-title {
  font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  color: #6b7280; margin: 0 0 0.6rem;
}
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 0.75rem; }
.card {
  display: flex; flex-direction: column; gap: 0.55rem;
  padding: 0.85rem 0.95rem; border-radius: 12px;
  background: #fff; border: 1px solid #e5e7eb;
}
.card.off { background: #fafafa; border-style: dashed; }
.card.off .ident-name, .card.off .desc { color: #9ca3af; }
.card.planned { background: #fbfbfd; }
.card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.6rem; }
.ident { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.ident-icon { font-size: 1.35rem; color: var(--safety-amber, #e8a33d); }
.ident-name { display: flex; align-items: center; gap: 0.35rem; font-weight: 600; font-size: 0.95rem; }
.ident-vendor { font-size: 0.72rem; color: #9ca3af; }
.desc { margin: 0; font-size: 0.8rem; line-height: 1.45; color: #4b5563; }
.meta { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; font-size: 0.75rem; }
.adoption {
  display: inline-flex; align-items: center; gap: 0.3rem;
  border: 0; background: transparent; padding: 0; font: inherit; color: #6b7280;
}
.adoption.clickable { color: #2563eb; cursor: pointer; text-decoration: underline; }
.plans { display: inline-flex; align-items: center; gap: 0.25rem; color: #6b7280; }
.chip {
  display: inline-block; padding: 0.05rem 0.4rem; border-radius: 999px;
  font-size: 0.7rem; background: var(--surface-2, #f2f0ea); color: #4b5563;
}
.card-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
  border-top: 1px solid #f3f4f6; padding-top: 0.45rem; margin-top: auto;
}
.note { color: #8a5a12; font-size: 0.73rem; }
.muted { color: #9ca3af; font-size: 0.73rem; }
.mini { font-size: 0.66rem; }
.key { font-size: 0.75rem; color: #6b7280; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.form-field { display: flex; flex-direction: column; gap: 0.3rem; }
.form-field.row { flex-direction: row; align-items: center; gap: 0.55rem; }
.form-field label { font-size: 0.8rem; font-weight: 600; }
.form-field label.inline { font-weight: 500; }
.hint { font-size: 0.73rem; color: #6b7280; line-height: 1.4; }
.w-full { width: 100%; }
</style>
