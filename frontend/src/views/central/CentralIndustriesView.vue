<script setup lang="ts">
/**
 * Industry templates (/platform/industries) — the vertical catalogue the SaaS
 * owner sells (Volume 11 §"Industry Templates").
 *
 * A template is packaging, not code: a vertical's name, the trades offered under
 * it, and the modules a workspace in it starts with. Editing one changes what the
 * *next* signup is offered; live workspaces are untouched, because provisioning
 * reads a template once and the tenant owns its module selection from then on.
 * The screen says so, since "will this reconfigure my customers?" is the first
 * question anyone asks before touching it.
 *
 * Plugin-backed verticals (Food & Beverage) are marked as such: their trades come
 * from code and are shown read-only here.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'

const toast = useToast()
const auth = useCentralAuthStore()

const canCreate = computed(() => auth.can('industries.create'))
const canEdit = computed(() => auth.can('industries.edit'))
const canDelete = computed(() => auth.can('industries.delete'))

type Template = {
  id: number
  key: string
  label: string
  icon: string
  description: string | null
  modules: string[]
  trades: string[]
  plugin_key: string | null
  is_system: boolean
  is_active: boolean
  sort_order: number
  tenants_count: number
}

const templates = ref<Template[]>([])
const availableTrades = ref<any[]>([])
const availableModules = ref<any[]>([])
const loading = ref(false)

const dialogVisible = ref(false)
const editing = ref<Template | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const form = ref<any>(blankForm())

function blankForm() {
  return {
    label: '',
    icon: 'pi pi-briefcase',
    description: '',
    trades: [] as string[],
    modules: [] as string[],
    sort_order: 0,
    is_active: true,
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

const tradeOptions = computed(() =>
  availableTrades.value.map((t: any) => ({ label: t.label, value: t.key })),
)
const moduleOptions = computed(() =>
  availableModules.value.map((m: any) => ({ label: m.label, value: m.key })),
)

const tradeLabels = computed(() => {
  const map = new Map<string, string>()
  for (const t of availableTrades.value) map.set(t.key, t.label)
  return map
})

function tradeLabel(key: string): string {
  return tradeLabels.value.get(key) ?? key
}

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/industries')
    templates.value = data.data ?? []
    availableTrades.value = data.available_trades ?? []
    availableModules.value = data.available_modules ?? []
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load industries', life: 4000 })
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  form.value.sort_order = (templates.value.at(-1)?.sort_order ?? 0) + 10
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: Template) {
  editing.value = row
  form.value = {
    label: row.label,
    icon: row.icon,
    description: row.description ?? '',
    trades: [...row.trades],
    modules: [...row.modules],
    sort_order: row.sort_order,
    is_active: row.is_active,
  }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: any = { ...form.value }
    // A plugin owns its vertical's trades; don't send a list the API will ignore.
    if (editing.value?.plugin_key) delete payload.trades

    if (editing.value) {
      await centralHttp.put(`/industries/${editing.value.id}`, payload)
    } else {
      await centralHttp.post('/industries', payload)
    }
    toast.add({ severity: 'success', summary: editing.value ? 'Industry updated' : 'Industry created', life: 2500 })
    dialogVisible.value = false
    await load()
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

async function toggle(row: Template) {
  try {
    await centralHttp.post(`/industries/${row.id}/toggle`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not change it', life: 4000 })
  }
}

async function confirmDelete(row: Template) {
  const res = await Swal.fire({
    title: `Delete "${row.label}"?`,
    text: 'It disappears from the signup picker. Workspaces already classified under it keep working.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Delete',
  })
  if (!res.isConfirmed) return
  try {
    await centralHttp.delete(`/industries/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Not deleted', detail: e.response?.data?.message || 'Delete failed', life: 5000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Industry Templates</h1>
      <Button v-if="canCreate" label="New Industry" icon="pi pi-plus" @click="openCreate" />
    </div>

    <p class="intro">
      <i class="pi pi-info-circle" />
      These are the verticals a new signup can choose from, and what each one starts a
      workspace with. Editing a template changes what the next signup is offered — it
      never reconfigures workspaces already sold. A plan is still the ceiling: a trade
      asking for a module the package doesn't sell simply doesn't get it.
    </p>

    <DataTable :value="templates" :loading="loading" dataKey="id" class="p-datatable-sm">
      <Column field="label" header="Industry" sortable>
        <template #body="{ data }">
          <div class="name">
            <i :class="data.icon" />
            <span>{{ data.label }}</span>
            <Tag v-if="data.plugin_key" value="plugin" severity="info" class="mini" :title="`Trades come from the ${data.plugin_key} plugin`" />
            <Tag v-else-if="data.is_system" value="built-in" severity="secondary" class="mini" />
          </div>
          <small class="muted">{{ data.description }}</small>
        </template>
      </Column>
      <Column field="key" header="Key" style="width: 9rem">
        <template #body="{ data }"><code class="key">{{ data.key }}</code></template>
      </Column>
      <Column header="Trades" style="width: 18rem">
        <template #body="{ data }">
          <span v-if="!data.trades.length" class="muted">—</span>
          <span v-for="key in data.trades" :key="key" class="chip">{{ tradeLabel(key) }}</span>
        </template>
      </Column>
      <Column header="Modules" sortable :field="'modules.length'" style="width: 7rem">
        <template #body="{ data }">{{ data.modules.length || '—' }}</template>
      </Column>
      <Column field="tenants_count" header="Tenants" sortable style="width: 7rem" />
      <Column header="Active" style="width: 6rem">
        <template #body="{ data }">
          <ToggleSwitch
            :modelValue="data.is_active"
            :disabled="!canEdit"
            :title="data.is_active ? 'Shown on the signup picker' : 'Hidden from new signups'"
            @update:modelValue="toggle(data)"
          />
        </template>
      </Column>
      <Column header="Actions" style="width: 7rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" title="Edit" @click="openEdit(data)" />
          <Button
            v-if="canDelete && !data.is_system"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            size="small"
            title="Delete"
            @click="confirmDelete(data)"
          />
        </template>
      </Column>
      <template #empty>No industry templates yet.</template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? `Edit ${editing.label}` : 'New Industry Template'"
      :style="{ width: '600px' }"
      :closable="!saving"
    >
      <div class="form-grid">
        <div class="form-field full">
          <label>Name <span class="req">*</span></label>
          <InputText v-model="form.label" :class="{ invalid: fieldError('label') }" />
          <small v-if="fieldError('label')" class="error-text">{{ fieldError('label') }}</small>
          <small v-else-if="!editing" class="hint">The key is derived from this and never changes afterwards.</small>
        </div>

        <div class="form-field">
          <label>Icon</label>
          <InputText v-model="form.icon" placeholder="pi pi-briefcase" />
          <small class="hint"><i :class="form.icon" /> PrimeIcons class</small>
        </div>
        <div class="form-field">
          <label>Order</label>
          <InputNumber v-model="form.sort_order" :min="0" :useGrouping="false" />
        </div>

        <div class="form-field full">
          <label>Description</label>
          <InputText v-model="form.description" :class="{ invalid: fieldError('description') }" />
          <small v-if="fieldError('description')" class="error-text">{{ fieldError('description') }}</small>
        </div>

        <div class="form-field full">
          <label>Trades</label>
          <MultiSelect
            v-model="form.trades"
            :options="tradeOptions"
            optionLabel="label"
            optionValue="value"
            :disabled="!!editing?.plugin_key"
            display="chip"
            filter
            placeholder="Trades offered under this industry"
          />
          <small v-if="editing?.plugin_key" class="hint">
            Owned by the {{ editing.plugin_key }} plugin — its trades are declared in code.
          </small>
          <small v-else class="hint">Each trade brings its own module preset when a signup picks it.</small>
        </div>

        <div class="form-field full">
          <label>Default modules</label>
          <MultiSelect
            v-model="form.modules"
            :options="moduleOptions"
            optionLabel="label"
            optionValue="value"
            display="chip"
            filter
            placeholder="Used when a signup names the industry but no trade"
          />
          <small class="hint">A picked trade always wins over this list.</small>
        </div>

        <div class="form-field full">
          <div class="switch-row">
            <ToggleSwitch v-model="form.is_active" inputId="ind-active" />
            <label for="ind-active">Offer this industry on the signup picker</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" text :disabled="saving" @click="dialogVisible = false" />
        <Button :label="editing ? 'Save' : 'Create'" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
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
.name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 500;
}
.name i {
  color: var(--safety-amber, #e8a33d);
}
.mini {
  font-size: 0.68rem;
}
.muted {
  color: #9ca3af;
}
.key {
  font-size: 0.75rem;
  color: #6b7280;
}
.chip {
  display: inline-block;
  margin: 0 0.25rem 0.25rem 0;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.72rem;
  background: var(--surface-2, #f2f0ea);
  border: 1px solid var(--border, #e3ded3);
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
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
.form-field :deep(.p-multiselect),
.form-field :deep(.p-inputnumber) {
  width: 100%;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.switch-row label {
  font-weight: 400;
}
.req {
  color: #dc2626;
}
.hint {
  color: #6b7280;
  font-size: 0.75rem;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
@media (max-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
