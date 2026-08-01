<script setup lang="ts">
/**
 * Renders the tenant's custom fields for one entity as form inputs.
 *
 * Drop it at the bottom of any create/edit dialog:
 *
 *   <CustomFieldsPanel entity="product" v-model="form.custom_fields" :errors="errors" />
 *
 * It renders nothing at all when the tenant has defined no fields, so a screen
 * can include it unconditionally — which is the point: adding "ISBN" to a book
 * store's product form is a row in the field builder, not a frontend change.
 */
import { computed, onMounted, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import { usePt } from '../i18n/pt'
import { useCustomFields, type CustomFieldSchema } from '../composables/useCustomFields'

const props = withDefaults(
  defineProps<{
    entity: string
    modelValue: Record<string, any> | null | undefined
    errors?: Record<string, string[]>
    /** Section heading above the fields. Pass '' to render them bare. */
    heading?: string
    /** Render in one column (narrow dialogs). */
    dense?: boolean
  }>(),
  { errors: () => ({}), heading: undefined, dense: false },
)

const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, any>): void }>()

const pt = usePt()
const { schema, load, blank } = useCustomFields(props.entity)

onMounted(async () => {
  await load()
  // A dialog that opened before the schema arrived has an empty bag; fill in
  // the defaults it should have started with, without clobbering typed input.
  emit('update:modelValue', { ...blank(), ...(props.modelValue ?? {}) })
})

// Switching entity on a mounted panel is unusual but cheap to support.
watch(() => props.entity, () => load())

const values = computed(() => props.modelValue ?? {})

function set(key: string, value: any) {
  emit('update:modelValue', { ...values.value, [key]: value })
}

function errorFor(field: CustomFieldSchema): string | null {
  return props.errors?.[`custom_fields.${field.key}`]?.[0] ?? null
}

/** DatePicker works in Date objects; the API stores and returns 'YYYY-MM-DD'. */
function asDate(value: any): Date | null {
  if (!value) return null
  const d = new Date(value)
  return isNaN(d.getTime()) ? null : d
}

function fromDate(d: Date | null): string | null {
  if (!d) return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function asArray(value: any): any[] {
  return Array.isArray(value) ? value : value == null || value === '' ? [] : [value]
}
</script>

<template>
  <div v-if="schema.length" class="cf-panel">
    <h4 v-if="heading !== ''" class="cf-sec">{{ pt(heading ?? 'Additional details') }}</h4>

    <div class="cf-grid" :class="{ dense }">
      <div
        v-for="field in schema"
        :key="field.key"
        class="cf-fld"
        :class="{ 'span-2': field.type === 'textarea' }"
      >
        <label :for="`cf-${field.key}`">
          {{ field.label }}<span v-if="field.required" class="req"> *</span>
        </label>

        <Textarea
          v-if="field.type === 'textarea'"
          :id="`cf-${field.key}`"
          :modelValue="values[field.key] ?? ''"
          rows="2"
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <InputNumber
          v-else-if="field.type === 'number'"
          :id="`cf-${field.key}`"
          :modelValue="values[field.key] ?? null"
          :min="field.min ?? undefined"
          :max="field.max ?? undefined"
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <InputNumber
          v-else-if="field.type === 'decimal'"
          :id="`cf-${field.key}`"
          :modelValue="values[field.key] ?? null"
          :min="field.min ?? undefined"
          :max="field.max ?? undefined"
          :minFractionDigits="2"
          :maxFractionDigits="4"
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <DatePicker
          v-else-if="field.type === 'date'"
          :id="`cf-${field.key}`"
          :modelValue="asDate(values[field.key])"
          dateFormat="yy-mm-dd"
          showButtonBar
          class="w-full"
          @update:modelValue="set(field.key, fromDate($event as Date | null))"
        />

        <Select
          v-else-if="field.type === 'select'"
          :id="`cf-${field.key}`"
          :modelValue="values[field.key] ?? null"
          :options="field.options"
          optionLabel="label"
          optionValue="value"
          showClear
          filter
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <MultiSelect
          v-else-if="field.type === 'multiselect'"
          :id="`cf-${field.key}`"
          :modelValue="asArray(values[field.key])"
          :options="field.options"
          optionLabel="label"
          optionValue="value"
          display="chip"
          filter
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <div v-else-if="field.type === 'boolean'" class="cf-check">
          <Checkbox
            :inputId="`cf-${field.key}`"
            :modelValue="!!values[field.key]"
            binary
            @update:modelValue="set(field.key, $event)"
          />
          <label :for="`cf-${field.key}`" class="cf-check-label">{{ pt('Yes') }}</label>
        </div>

        <InputText
          v-else
          :id="`cf-${field.key}`"
          :modelValue="values[field.key] ?? ''"
          :type="field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'"
          class="w-full"
          @update:modelValue="set(field.key, $event)"
        />

        <small v-if="field.help_text" class="cf-hint">{{ field.help_text }}</small>
        <small v-if="errorFor(field)" class="cf-err">{{ errorFor(field) }}</small>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cf-panel { margin-top: 0.5rem; }
.cf-sec { margin: 1rem 0 0.5rem; font-size: 0.9rem; font-weight: 600; }
.cf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
.cf-grid.dense { grid-template-columns: 1fr; }
.span-2 { grid-column: 1 / -1; }
.cf-fld { display: flex; flex-direction: column; gap: 0.3rem; }
.cf-fld label { font-size: 0.78rem; color: #374151; }
.req { color: #b91c1c; }
.cf-check { display: flex; align-items: center; gap: 0.4rem; min-height: 2.4rem; }
.cf-check-label { font-size: 0.8rem; }
.w-full { width: 100%; }
.cf-hint { font-size: 0.72rem; color: #6b7280; }
.cf-err { color: #b91c1c; font-size: 0.75rem; }
</style>
