import { ref, computed } from 'vue'
import http from '../api/http'

/**
 * The tenant's custom fields for one entity.
 *
 * A screen wires them up in three places: the form (CustomFieldsPanel), the
 * list (listColumns) and the export (exportColumns). Everything comes from one
 * schema fetch, and the schema arrives already narrowed to active fields whose
 * module is switched on — the frontend renders what it is given.
 */

export type CustomFieldType =
  | 'text' | 'textarea' | 'number' | 'decimal' | 'date'
  | 'select' | 'multiselect' | 'boolean' | 'email' | 'url'

export interface CustomFieldSchema {
  id: number
  key: string
  label: string
  help_text: string | null
  type: CustomFieldType
  options: { value: string; label: string }[]
  choices: string[]
  default: any
  required: boolean
  min: number | null
  max: number | null
  pattern: string | null
  show_in_list: boolean
  searchable: boolean
  module: string | null
}

/**
 * Cached per entity for the life of the page. The schema changes only when an
 * admin edits it, and a product list that re-fetches it on every keystroke of
 * the search box would pay for nothing.
 */
const cache = new Map<string, CustomFieldSchema[]>()

export function invalidateCustomFieldSchema(entity?: string) {
  if (entity) cache.delete(entity)
  else cache.clear()
}

export function useCustomFields(entity: string) {
  const schema = ref<CustomFieldSchema[]>(cache.get(entity) ?? [])
  const loading = ref(false)

  async function load(force = false) {
    if (!force && cache.has(entity)) {
      schema.value = cache.get(entity)!
      return schema.value
    }
    loading.value = true
    try {
      const { data } = await http.get('/custom-fields/schema', { params: { entity } })
      const rows: CustomFieldSchema[] = data.data ?? []
      cache.set(entity, rows)
      schema.value = rows
      return rows
    } finally {
      loading.value = false
    }
  }

  /** A fresh value bag with every field at its default. */
  function blank(): Record<string, any> {
    const out: Record<string, any> = {}
    for (const f of schema.value) {
      out[f.key] = f.default ?? (f.type === 'multiselect' ? [] : f.type === 'boolean' ? false : null)
    }
    return out
  }

  /** Merge a saved record's values over the defaults, so new fields appear filled-in-blank. */
  function fromRecord(values: Record<string, any> | null | undefined): Record<string, any> {
    return { ...blank(), ...(values ?? {}) }
  }

  /** Fields an admin asked to see as list columns. */
  const listColumns = computed(() => schema.value.filter((f) => f.show_in_list))

  /** Same fields, in the shape TableExportButtons wants. */
  const exportColumns = computed(() =>
    listColumns.value.map((f) => ({ key: `custom_fields.${f.key}`, label: f.label })),
  )

  /** Render one stored value the way its type reads best in a table cell. */
  function display(field: CustomFieldSchema, values: Record<string, any> | null | undefined): string {
    const raw = values?.[field.key]
    if (raw === null || raw === undefined || raw === '') return '—'

    if (field.type === 'boolean') return raw ? 'Yes' : 'No'

    if (field.type === 'multiselect') {
      const list = Array.isArray(raw) ? raw : [raw]
      return list.map((v) => labelFor(field, v)).join(', ') || '—'
    }
    if (field.type === 'select') return labelFor(field, raw)

    return String(raw)
  }

  return { schema, loading, load, blank, fromRecord, listColumns, exportColumns, display }
}

function labelFor(field: CustomFieldSchema, value: any): string {
  return field.options.find((o) => o.value === String(value))?.label ?? String(value)
}
