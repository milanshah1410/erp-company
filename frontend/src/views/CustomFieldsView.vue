<script setup lang="ts">
/**
 * The field builder.
 *
 * A shop whose trade the module catalogue does not name — a book store, a toy
 * shop, an electrical dealer — makes the standard masters its own here, rather
 * than waiting for a column. What is defined on this screen appears on the
 * matching form, list and export immediately.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import { invalidateCustomFieldSchema } from '../composables/useCustomFields'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/custom-fields')

const canCreate = computed(() => auth.can('custom-fields.create'))
const canEdit = computed(() => auth.can('custom-fields.edit'))
const canDelete = computed(() => auth.can('custom-fields.delete'))

const TYPES = [
  { label: 'Text', value: 'text' },
  { label: 'Long text', value: 'textarea' },
  { label: 'Whole number', value: 'number' },
  { label: 'Decimal', value: 'decimal' },
  { label: 'Date', value: 'date' },
  { label: 'Dropdown (one)', value: 'select' },
  { label: 'Dropdown (many)', value: 'multiselect' },
  { label: 'Yes / No', value: 'boolean' },
  { label: 'Email', value: 'email' },
  { label: 'Link', value: 'url' },
]

const CHOICE_TYPES = ['select', 'multiselect']
const TEXT_TYPES = ['text', 'textarea']
const NUMERIC_TYPES = ['number', 'decimal']

const entities = ref<{ key: string; label: string; description: string }[]>([])
const modules = ref<{ key: string; label: string }[]>([])
const entityFilter = ref<string | null>(null)

const entityOptions = computed(() =>
  entities.value.map((e) => ({ label: e.label, value: e.key })),
)
const moduleOptions = computed(() =>
  modules.value.map((m) => ({ label: m.label, value: m.key })),
)

function load() {
  return crud.fetch({ entity: entityFilter.value || undefined, per_page: 200 })
}

async function loadPickers() {
  const { data } = await http.get('/custom-fields/entities')
  entities.value = data.data ?? []
  modules.value = data.modules ?? []
}

// ---- Create / edit --------------------------------------------------------

const dialogVisible = ref(false)
const editing = ref<any>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const blank = () => ({
  entity: entityFilter.value ?? 'product',
  key: '',
  label: '',
  help_text: '',
  type: 'text' as string,
  options: [] as { value: string; label: string }[],
  default_value: '',
  is_required: false,
  min_value: null as number | null,
  max_value: null as number | null,
  pattern: '',
  show_in_list: false,
  is_searchable: false,
  module: null as string | null,
  sort_order: 0,
  is_active: true,
})
const form = ref(blank())

const isChoice = computed(() => CHOICE_TYPES.includes(form.value.type))
const isText = computed(() => TEXT_TYPES.includes(form.value.type))
const isNumeric = computed(() => NUMERIC_TYPES.includes(form.value.type))

// Bounds mean string length on text types and magnitude on numeric ones — the
// label says which, rather than leaving an admin to guess what "Maximum: 13"
// does to a text box.
const minLabel = computed(() => (isText.value ? pt('Minimum length') : pt('Minimum value')))
const maxLabel = computed(() => (isText.value ? pt('Maximum length') : pt('Maximum value')))

function slugify(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^([0-9])/, 'f$1')
    .slice(0, 60)
}

/** Suggest the key from the label until the admin types one themselves. */
function onLabelInput() {
  if (!editing.value && !keyTouched.value) form.value.key = slugify(form.value.label)
}
const keyTouched = ref(false)

function openCreate() {
  editing.value = null
  keyTouched.value = false
  form.value = blank()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  keyTouched.value = true
  errors.value = {}
  form.value = {
    entity: row.entity,
    key: row.key,
    label: row.label,
    help_text: row.help_text ?? '',
    type: row.type,
    options: (row.options ?? []).map((o: any) =>
      typeof o === 'string' ? { value: o, label: o } : { value: o.value, label: o.label ?? o.value },
    ),
    default_value: row.default_value ?? '',
    is_required: row.is_required,
    min_value: row.min_value,
    max_value: row.max_value,
    pattern: row.pattern ?? '',
    show_in_list: row.show_in_list,
    is_searchable: row.is_searchable,
    module: row.module,
    sort_order: row.sort_order,
    is_active: row.is_active,
  }
  dialogVisible.value = true
}

function addOption() {
  form.value.options.push({ value: '', label: '' })
}

function removeOption(index: number) {
  form.value.options.splice(index, 1)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload: Record<string, any> = {
      ...form.value,
      help_text: form.value.help_text || null,
      default_value: form.value.default_value || null,
      pattern: form.value.pattern || null,
      // A choice list only makes sense on a dropdown; sending one with a text
      // field would leave dead data behind after a type change.
      options: isChoice.value
        ? form.value.options
            .filter((o) => o.value.trim() !== '')
            .map((o) => ({ value: o.value.trim(), label: (o.label || o.value).trim() }))
        : null,
      min_value: isText.value || isNumeric.value ? form.value.min_value : null,
      max_value: isText.value || isNumeric.value ? form.value.max_value : null,
    }

    if (editing.value) await crud.update(editing.value.id, payload)
    else await crud.create(payload)

    // Forms cache the schema for the life of the page; this is the edit that
    // makes those copies wrong.
    invalidateCustomFieldSchema(form.value.entity)

    toast.add({ severity: 'success', summary: pt('Custom field saved'), life: 2500 })
    dialogVisible.value = false
    await load()
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the custom field'),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const ok = await Swal.fire({
    title: pt('Remove this field?'),
    text: pt(
      'Values already saved under this key are kept and reappear if you create the field again.',
    ),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Remove'),
    cancelButtonText: pt('Cancel'),
  })
  if (!ok.isConfirmed) return
  try {
    await crud.remove(row.id)
    invalidateCustomFieldSchema(row.entity)
    toast.add({ severity: 'success', summary: pt('Custom field removed'), life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Delete failed'), life: 4000 })
  }
}

function typeLabel(value: string): string {
  return TYPES.find((t) => t.value === value)?.label ?? value
}

onMounted(async () => {
  await loadPickers()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Custom Fields')"
      :subtitle="pt('Extra fields this business keeps — they appear on the matching form, list and export straight away')"
    />

    <div class="toolbar">
      <Select
        v-model="entityFilter"
        :options="entityOptions"
        optionLabel="label"
        optionValue="value"
        showClear
        :placeholder="pt('All records')"
        class="picker"
        @change="load"
      />
      <Button v-if="canCreate" :label="pt('New Field')" icon="pi pi-plus" @click="openCreate" />
      <TableExportButtons
        title="Custom Fields"
        filenameBase="custom-fields"
        :rows="crud.items.value"
        :columns="[
          { key: 'entity_label', label: 'Record' },
          { key: 'label', label: 'Field' },
          { key: 'key', label: 'Key' },
          { key: 'type', label: 'Type' },
          { key: 'module', label: 'Module' },
        ]"
      />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" dataKey="id" class="p-datatable-sm">
      <Column :header="pt('Record')" field="entity_label" style="width: 9rem" />
      <Column :header="pt('Field')">
        <template #body="{ data }">
          <strong>{{ data.label }}</strong>
          <span v-if="data.is_required" class="req"> *</span>
          <br /><small class="muted mono">{{ data.key }}</small>
        </template>
      </Column>
      <Column :header="pt('Type')">
        <template #body="{ data }">
          {{ pt(typeLabel(data.type)) }}
          <br v-if="(data.options ?? []).length" />
          <small v-if="(data.options ?? []).length" class="muted">
            {{ data.options.length }} {{ pt('choices') }}
          </small>
        </template>
      </Column>
      <Column :header="pt('Shown in')">
        <template #body="{ data }">
          <Tag v-if="data.show_in_list" :value="pt('In list')" severity="info" class="mini" />
          <Tag v-if="data.is_searchable" :value="pt('In search')" severity="secondary" class="mini" />
          <span v-if="!data.show_in_list && !data.is_searchable" class="muted">{{ pt('Form only') }}</span>
        </template>
      </Column>
      <Column :header="pt('Module')">
        <template #body="{ data }">
          <span v-if="data.module" class="mono">{{ data.module }}</span>
          <span v-else class="muted">{{ pt('Always') }}</span>
        </template>
      </Column>
      <Column :header="pt('Status')" style="width: 7rem">
        <template #body="{ data }">
          <Tag
            :value="data.is_active ? pt('Active') : pt('Off')"
            :severity="data.is_active ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 7rem">
        <template #body="{ data }">
          <Button v-if="canEdit" icon="pi pi-pencil" text size="small" @click="openEdit(data)" />
          <Button
            v-if="canDelete"
            icon="pi pi-trash"
            text
            size="small"
            severity="danger"
            @click="remove(data)"
          />
        </template>
      </Column>
      <template #empty>
        {{ pt('No custom fields yet. Add one to put this trade’s own details on the standard forms.') }}
      </template>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? pt('Edit Custom Field') : pt('New Custom Field')"
      :style="{ width: '48rem' }"
    >
      <h4 class="sec">{{ pt('What and where') }}</h4>
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Attach to') }} *</label>
          <Select
            v-model="form.entity"
            :options="entityOptions"
            optionLabel="label"
            optionValue="value"
            :disabled="!!editing"
            class="w-full"
          />
          <small v-if="editing" class="hint">
            {{ pt('Fixed after creation — values are already stored against it.') }}
          </small>
          <small v-if="errors.entity" class="err">{{ errors.entity[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Label') }} *</label>
          <InputText v-model="form.label" class="w-full" @input="onLabelInput" />
          <small v-if="errors.label" class="err">{{ errors.label[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Key') }} *</label>
          <InputText
            v-model="form.key"
            :disabled="!!editing"
            class="w-full mono"
            @input="keyTouched = true"
          />
          <small class="hint">{{ pt('Lowercase, no spaces — e.g. isbn_number.') }}</small>
          <small v-if="errors.key" class="err">{{ errors.key[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Type') }} *</label>
          <Select
            v-model="form.type"
            :options="TYPES"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
          <small v-if="errors.type" class="err">{{ errors.type[0] }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Help text') }}</label>
          <InputText v-model="form.help_text" class="w-full" />
          <small class="hint">{{ pt('Shown under the input on the form.') }}</small>
        </div>
      </div>

      <template v-if="isChoice">
        <h4 class="sec">{{ pt('Choices') }}</h4>
        <div v-for="(opt, i) in form.options" :key="i" class="opt-row">
          <InputText v-model="opt.value" :placeholder="pt('Stored value')" class="mono" />
          <InputText v-model="opt.label" :placeholder="pt('Shown label (optional)')" />
          <Button icon="pi pi-times" text severity="danger" size="small" @click="removeOption(i)" />
        </div>
        <Button :label="pt('Add choice')" icon="pi pi-plus" text size="small" @click="addOption" />
        <small v-if="errors.options" class="err block">{{ errors.options[0] }}</small>
      </template>

      <h4 class="sec">{{ pt('Rules') }}</h4>
      <div class="grid">
        <div class="fld">
          <label class="inline">
            <Checkbox v-model="form.is_required" binary />
            {{ pt('Required') }}
          </label>
          <small class="hint">{{ pt('The record cannot be saved without it.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Default value') }}</label>
          <InputText v-model="form.default_value" class="w-full" />
          <small class="hint">{{ pt('Pre-filled on new records.') }}</small>
        </div>
        <template v-if="isText || isNumeric">
          <div class="fld">
            <label>{{ minLabel }}</label>
            <InputNumber v-model="form.min_value" class="w-full" />
          </div>
          <div class="fld">
            <label>{{ maxLabel }}</label>
            <InputNumber v-model="form.max_value" class="w-full" />
            <small v-if="errors.max_value" class="err">{{ errors.max_value[0] }}</small>
          </div>
        </template>
        <div v-if="form.type === 'text'" class="fld span-2">
          <label>{{ pt('Pattern') }}</label>
          <InputText v-model="form.pattern" class="w-full mono" placeholder="[0-9]{13}" />
          <small class="hint">
            {{ pt('Optional regular expression the whole value must match — worth refusing an ISBN or IMEI at entry.') }}
          </small>
          <small v-if="errors.pattern" class="err">{{ errors.pattern[0] }}</small>
        </div>
      </div>

      <h4 class="sec">{{ pt('Where it shows') }}</h4>
      <div class="grid">
        <div class="fld">
          <label class="inline">
            <Checkbox v-model="form.show_in_list" binary />
            {{ pt('Column on the list screen') }}
          </label>
        </div>
        <div class="fld">
          <label class="inline">
            <Checkbox v-model="form.is_searchable" binary />
            {{ pt('Included in search') }}
          </label>
        </div>
        <div class="fld">
          <label class="inline">
            <Checkbox v-model="form.is_active" binary />
            {{ pt('Active') }}
          </label>
          <small class="hint">{{ pt('Switch off to hide it without losing saved values.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Only with module') }}</label>
          <Select
            v-model="form.module"
            :options="moduleOptions"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="pt('Always shown')"
            class="w-full"
          />
          <small class="hint">{{ pt('Hidden when that module is switched off.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sort order') }}</label>
          <InputNumber v-model="form.sort_order" :min="0" :max="9999" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" icon="pi pi-check" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.picker { min-width: 14rem; }
.muted { color: #6b7280; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }
.req { color: #b91c1c; }
.mini { margin-right: 0.25rem; }
.sec { margin: 1.1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.sec:first-child { margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.fld label.inline { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 2.2rem; }
.opt-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.5rem; margin-bottom: 0.4rem; }
.w-full { width: 100%; }
.hint { font-size: 0.72rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.75rem; }
.err.block { display: block; margin-top: 0.3rem; }
</style>
