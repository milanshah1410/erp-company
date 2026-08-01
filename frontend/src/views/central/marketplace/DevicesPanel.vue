<script setup lang="ts">
/**
 * Device Marketplace (/platform/marketplace → Devices) —
 * Volume 11 §"Device Marketplace".
 *
 * The same three levers as the integration shelf, applied to hardware, but at
 * two grains: a device class (barcode scanners) and a brand adapter within it
 * (Zebra). Both exist because the two withdrawals mean different things —
 * "we don't support scanners on Starter" is packaging, "Zebra's adapter is
 * misbehaving in the field, stop selling it" is incident response — and an owner
 * should never have to do the second by doing the first.
 *
 * Withdrawing a class withdraws every brand under it. Withdrawing a brand leaves
 * the rest of the class standing. Neither touches hardware already configured:
 * a device's type and brand are create-only, so a terminal on a counter keeps
 * scanning after its brand leaves the catalogue.
 *
 * Counts are of tenants and of configured devices, separately: five scanners in
 * one workspace and five workspaces with one each are very different signals
 * about whether an adapter is worth maintaining.
 */
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { useCentralAuthStore } from '../../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canEdit = computed(() => auth.can('devices.edit'))

type Offering = {
  key: string
  label: string
  is_enabled: boolean
  is_beta: boolean
  plan_slugs: string[] | null
  note: string | null
  tenants_count: number
  devices_count: number
}

type Brand = Offering & { driver: string; models: string }
type DeviceType = Offering & { type: string; icon: string; description: string; drivers: Brand[] }

const types = ref<DeviceType[]>([])
const plans = ref<{ slug: string; name: string }[]>([])
const loading = ref(false)
const savingKey = ref<string | null>(null)

const dialogVisible = ref(false)
const editing = ref<{ key: string; label: string } | null>(null)
const saving = ref(false)
const form = ref<{ plan_slugs: string[]; is_beta: boolean; note: string }>({
  plan_slugs: [],
  is_beta: false,
  note: '',
})

const planOptions = computed(() => plans.value.map((p) => ({ label: p.name, value: p.slug })))
const planNames = computed(() => new Map(plans.value.map((p) => [p.slug, p.name])))

function planLabel(slug: string): string {
  return planNames.value.get(slug) ?? slug
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/marketplace/devices')
    types.value = data.data ?? []
    plans.value = data.plans ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the device catalogue', life: 4000 })
  } finally {
    loading.value = false
  }
}

async function patch(key: string, payload: Record<string, unknown>) {
  savingKey.value = key
  try {
    await centralHttp.patch(`/marketplace/devices/${key}`, payload)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Not changed', detail: e.response?.data?.message || 'Could not update that offering', life: 5000 })
  } finally {
    savingKey.value = null
    await load()
  }
}

function toggle(offering: Offering) {
  patch(offering.key, { is_enabled: !offering.is_enabled })
}

function openEdit(offering: Offering, label: string) {
  editing.value = { key: offering.key, label }
  form.value = {
    plan_slugs: [...(offering.plan_slugs ?? [])],
    is_beta: offering.is_beta,
    note: offering.note ?? '',
  }
  dialogVisible.value = true
}

async function save() {
  if (!editing.value) return
  saving.value = true
  try {
    await centralHttp.patch(`/marketplace/devices/${editing.value.key}`, {
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

onMounted(load)
</script>

<template>
  <div>
    <p class="intro">
      <i class="pi pi-info-circle" />
      The hardware the ERP supports, and which of it you sell. Withdrawing a device class
      withdraws every brand under it; withdrawing one brand leaves the rest of the class on
      sale. Terminals already configured keep working either way — a device's brand is fixed
      at creation, so this only governs what a tenant can add next.
    </p>

    <div v-if="loading" class="loading"><i class="pi pi-spin pi-spinner" /> Loading catalogue…</div>

    <section v-for="type in types" :key="type.key" class="type" :class="{ off: !type.is_enabled }">
      <header class="type-head">
        <div class="ident">
          <i :class="type.icon" class="ident-icon" />
          <div>
            <div class="ident-name">
              {{ type.label }}
              <Tag v-if="type.is_beta" value="beta" severity="warn" class="mini" />
              <Tag v-if="!type.is_enabled" value="withdrawn" severity="danger" class="mini" />
            </div>
            <div class="ident-desc">{{ type.description }}</div>
          </div>
        </div>

        <div class="head-right">
          <div class="counts">
            <span :title="`${type.tenants_count} workspaces have configured one`">
              <i class="pi pi-building" /> {{ type.tenants_count }}
            </span>
            <span :title="`${type.devices_count} devices configured in total`">
              <i class="pi pi-desktop" /> {{ type.devices_count }}
            </span>
          </div>

          <span v-if="type.plan_slugs?.length" class="plans" title="Restricted to these plans">
            <i class="pi pi-lock" />
            <span v-for="slug in type.plan_slugs" :key="slug" class="chip">{{ planLabel(slug) }}</span>
          </span>

          <Button
            v-if="canEdit"
            icon="pi pi-cog"
            text
            rounded
            size="small"
            title="Plan availability, beta flag & note for the whole class"
            @click="openEdit(type, type.label)"
          />
          <ToggleSwitch
            :modelValue="type.is_enabled"
            :disabled="!canEdit || savingKey === type.key"
            :title="type.is_enabled ? 'On sale' : 'Withdrawn — the whole class is hidden from tenants'"
            @update:modelValue="toggle(type)"
          />
        </div>
      </header>

      <p v-if="type.note" class="note">{{ type.note }}</p>

      <table class="brands">
        <thead>
          <tr>
            <th>Brand</th>
            <th>Models</th>
            <th class="num">Tenants</th>
            <th class="num">Devices</th>
            <th>Plans</th>
            <th class="right">On sale</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="brand in type.drivers" :key="brand.key" :class="{ dim: !brand.is_enabled || !type.is_enabled }">
            <td>
              <span class="brand-name">{{ brand.label }}</span>
              <Tag v-if="brand.is_beta" value="beta" severity="warn" class="mini" />
              <small v-if="brand.note" class="brand-note">{{ brand.note }}</small>
            </td>
            <td class="models">{{ brand.models }}</td>
            <td class="num">{{ brand.tenants_count || '—' }}</td>
            <td class="num">{{ brand.devices_count || '—' }}</td>
            <td>
              <template v-if="brand.plan_slugs?.length">
                <span v-for="slug in brand.plan_slugs" :key="slug" class="chip">{{ planLabel(slug) }}</span>
              </template>
              <span v-else class="muted">all</span>
            </td>
            <td class="right actions">
              <Button
                v-if="canEdit"
                icon="pi pi-cog"
                text
                rounded
                size="small"
                title="Plan availability, beta flag & note"
                @click="openEdit(brand, `${type.label} — ${brand.label}`)"
              />
              <ToggleSwitch
                :modelValue="brand.is_enabled"
                :disabled="!canEdit || !type.is_enabled || savingKey === brand.key"
                :title="
                  !type.is_enabled
                    ? 'The whole device class is withdrawn'
                    : brand.is_enabled
                      ? 'On sale'
                      : 'Withdrawn — hidden from tenant device forms'
                "
                @update:modelValue="toggle(brand)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? `${editing.label} — availability` : ''"
      :style="{ width: '520px' }"
      :closable="!saving"
    >
      <div class="form">
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
            Leave empty to offer it on every plan. Devices already configured are never affected.
          </small>
        </div>

        <div class="form-field row">
          <ToggleSwitch v-model="form.is_beta" inputId="device-beta" />
          <label for="device-beta" class="inline">Flag as beta</label>
        </div>

        <div class="form-field">
          <label>Internal note</label>
          <InputText v-model="form.note" placeholder="e.g. firmware 2.1+ only" class="w-full" />
          <small class="hint">Visible on this screen only.</small>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text :disabled="saving" @click="dialogVisible = false" />
        <Button label="Save" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
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
.loading { color: #6b7280; font-size: 0.88rem; padding: 1rem 0; }
.type {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 0.85rem 1rem; margin-bottom: 0.85rem;
}
.type.off { background: #fafafa; border-style: dashed; }
.type-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.ident { display: flex; align-items: flex-start; gap: 0.65rem; min-width: 0; }
.ident-icon { font-size: 1.4rem; color: var(--safety-amber, #e8a33d); margin-top: 0.1rem; }
.ident-name { display: flex; align-items: center; gap: 0.35rem; font-weight: 600; font-size: 1rem; }
.ident-desc { font-size: 0.78rem; color: #6b7280; max-width: 46ch; line-height: 1.4; }
.head-right { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.counts { display: flex; gap: 0.7rem; font-size: 0.78rem; color: #6b7280; }
.counts i { margin-right: 0.2rem; }
.plans { display: inline-flex; align-items: center; gap: 0.25rem; color: #6b7280; font-size: 0.75rem; }
.chip {
  display: inline-block; margin-right: 0.2rem; padding: 0.05rem 0.4rem; border-radius: 999px;
  font-size: 0.7rem; background: var(--surface-2, #f2f0ea); color: #4b5563;
}
.note { margin: 0.5rem 0 0; font-size: 0.75rem; color: #8a5a12; }
.brands { width: 100%; border-collapse: collapse; margin-top: 0.7rem; font-size: 0.82rem; }
.brands th {
  text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;
  color: #9ca3af; font-weight: 600; padding: 0.3rem 0.5rem; border-bottom: 1px solid #f3f4f6;
}
.brands td { padding: 0.4rem 0.5rem; border-bottom: 1px solid #f9fafb; vertical-align: middle; }
.brands tr.dim td { opacity: 0.5; }
.brand-name { font-weight: 500; margin-right: 0.3rem; }
.brand-note { display: block; color: #8a5a12; font-size: 0.7rem; }
.models { color: #6b7280; font-size: 0.76rem; }
.num { text-align: right; width: 5.5rem; color: #4b5563; }
.right { text-align: right; }
.actions { display: flex; align-items: center; justify-content: flex-end; gap: 0.3rem; }
.muted { color: #9ca3af; }
.mini { font-size: 0.66rem; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.form-field { display: flex; flex-direction: column; gap: 0.3rem; }
.form-field.row { flex-direction: row; align-items: center; gap: 0.55rem; }
.form-field label { font-size: 0.8rem; font-weight: 600; }
.form-field label.inline { font-weight: 500; }
.hint { font-size: 0.73rem; color: #6b7280; line-height: 1.4; }
.w-full { width: 100%; }
</style>
