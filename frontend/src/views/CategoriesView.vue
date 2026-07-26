<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Tree from 'primevue/tree'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import type { TreeNode } from 'primevue/treenode'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canCreate = computed(() => auth.can('categories.create'))
const canEdit = computed(() => auth.can('categories.edit'))
const canDelete = computed(() => auth.can('categories.delete'))

// --- Tree state ---
const loading = ref(false)
const categories = ref<any[]>([])
const nodes = ref<TreeNode[]>([])
const expandedKeys = ref<Record<string, boolean>>({})

function toNode(cat: any): TreeNode {
  return {
    key: String(cat.id),
    label: cat.name,
    data: cat,
    children: (cat.children || []).map(toNode),
  }
}

function collectExpanded(list: TreeNode[], acc: Record<string, boolean>) {
  for (const n of list) {
    acc[n.key as string] = true
    if (n.children?.length) collectExpanded(n.children, acc)
  }
}

async function loadTree() {
  loading.value = true
  try {
    const { data } = await http.get('/categories-tree')
    categories.value = data.data ?? []
    nodes.value = categories.value.map(toNode)
    const exp: Record<string, boolean> = {}
    collectExpanded(nodes.value, exp)
    expandedKeys.value = exp
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed to load categories'), life: 4000 })
  } finally {
    loading.value = false
  }
}

// Flattened rows (name / parent / product count) for CSV export + print.
const exportRows = computed(() => {
  const out: any[] = []
  const walk = (list: any[], parentName: string) => {
    for (const c of list) {
      out.push({
        name: c.name,
        parent: parentName || '—',
        products_count: c.products_count ?? 0,
        is_active: c.is_active ? pt('Yes') : pt('No'),
      })
      if (c.children?.length) walk(c.children, c.name)
    }
  }
  walk(categories.value, '')
  return out
})

// Flatten the tree (depth-first) with indent level, for parent Select options.
function flatten(list: any[], depth = 0, acc: any[] = []): any[] {
  for (const cat of list) {
    acc.push({ id: cat.id, name: cat.name, depth })
    if (cat.children?.length) flatten(cat.children, depth + 1, acc)
  }
  return acc
}

// Collect the ids of a category and all of its descendants (client-side circular-parent guard).
function collectIds(cat: any, acc: number[] = []): number[] {
  acc.push(cat.id)
  for (const child of cat.children || []) collectIds(child, acc)
  return acc
}

// --- Create / Edit dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<any>(null)
const parentContextLabel = ref(pt('— none —'))
const form = ref<any>({ name: '', parent_id: null, description: '', is_active: true, attributes: [] })
const errors = ref<Record<string, string[]>>({})
const inheritedNames = ref<string[]>([])

const parentOptions = computed(() => {
  if (!editing.value) return []
  const excluded = collectIds(editing.value)
  return flatten(categories.value)
    .filter((c) => !excluded.includes(c.id))
    .map((c) => ({ label: `${'— '.repeat(c.depth)}${c.name}`, value: c.id }))
})

function blankRow() {
  return { name: '', type: 'text', optionsText: '', required: false }
}

const typeOptions = computed(() => [
  { label: pt('Text'), value: 'text' },
  { label: pt('Number'), value: 'number' },
  { label: pt('Select'), value: 'select' },
])

function addRow() {
  form.value.attributes.push(blankRow())
}
function removeRow(i: number | string) {
  form.value.attributes.splice(Number(i), 1)
}

function openCreateRoot() {
  editing.value = null
  parentContextLabel.value = pt('— none —')
  inheritedNames.value = []
  form.value = { name: '', parent_id: null, description: '', is_active: true, attributes: [blankRow()] }
  errors.value = {}
  dialogVisible.value = true
}

function openAddChild(parent: any) {
  editing.value = null
  parentContextLabel.value = parent.name
  inheritedNames.value = []
  form.value = { name: '', parent_id: parent.id, description: '', is_active: true, attributes: [blankRow()] }
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(cat: any) {
  editing.value = cat
  parentContextLabel.value = ''
  form.value = {
    name: cat.name,
    parent_id: cat.parent_id ?? null,
    description: cat.description || '',
    is_active: !!cat.is_active,
    attributes: (cat.attributes || []).map((a: any) => ({
      name: a.name,
      type: a.type || 'text',
      optionsText: Array.isArray(a.options) ? a.options.join(', ') : '',
      required: !!a.required,
    })),
  }
  errors.value = {}
  inheritedNames.value = []
  dialogVisible.value = true

  if (cat.parent_id) {
    try {
      const { data } = await http.get(`/categories/${cat.id}/attributes`)
      const ownNames = (cat.attributes || []).map((a: any) => a.name)
      inheritedNames.value = (data.data ?? [])
        .map((d: any) => d.name)
        .filter((n: string) => !ownNames.includes(n))
    } catch {
      inheritedNames.value = []
    }
  }
}

function buildPayload() {
  return {
    name: form.value.name,
    parent_id: form.value.parent_id,
    description: form.value.description || null,
    is_active: form.value.is_active,
    attributes: form.value.attributes
      .filter((r: any) => String(r.name || '').trim() !== '')
      .map((r: any) => {
        const def: any = { name: String(r.name).trim(), type: r.type, required: !!r.required }
        if (r.type === 'select') {
          def.options = String(r.optionsText || '')
            .split(',')
            .map((s: string) => s.trim())
            .filter(Boolean)
        }
        return def
      }),
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await http.put(`/categories/${editing.value.id}`, buildPayload())
      toast.add({ severity: 'success', summary: pt('Category updated'), life: 2500 })
    } else {
      await http.post('/categories', buildPayload())
      toast.add({ severity: 'success', summary: pt('Category created'), life: 2500 })
    }
    dialogVisible.value = false
    loadTree()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      const nested = Object.keys(errors.value).filter((k) => k.startsWith('attributes.'))
      if (nested.length) {
        const detail = nested.map((k) => errors.value[k].join(' ')).join(' ')
        toast.add({ severity: 'error', summary: pt('Attribute errors'), detail, life: 6000 })
      }
      if (!Object.keys(errors.value).length && e.response.data.message) {
        toast.add({ severity: 'error', summary: pt('Error'), detail: e.response.data.message, life: 4000 })
      }
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(cat: any) {
  const res = await Swal.fire({
    title: pt('Delete "{name}"?', { name: cat.name }),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/categories/${cat.id}`)
    toast.add({ severity: 'success', summary: pt('Deleted'), life: 2500 })
    loadTree()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

onMounted(loadTree)
</script>

<template>
  <div>
    <PageHeader :title="pt('Categories')">
      <template #actions>
        <div class="header-actions">
        <TableExportButtons :title="pt('Categories')" :rows="exportRows" size="small" :columns="[
        { key: 'name', label: pt('Name') },
        { key: 'parent', label: pt('Parent') },
        { key: 'products_count', label: pt('Products') },
        { key: 'is_active', label: pt('Active') },
        ]" />
        <Button v-if="canCreate" :label="pt('New Root Category')" icon="pi pi-plus" @click="openCreateRoot" />
        </div>
      </template>
    </PageHeader>

    <div class="tree-card">
      <Tree :value="nodes" v-model:expandedKeys="expandedKeys" :loading="loading" class="cat-tree"
        :filter="true" filterMode="lenient" :filterPlaceholder="pt('Search categories...')">
        <template #default="{ node }">
          <div class="node-row">
            <span class="node-name">{{ node.label }}</span>
            <span class="node-count">({{ pt('{n} products', { n: node.data.products_count ?? 0 }) }})</span>
            <span class="node-tags">
              <Tag v-for="attr in node.data.attributes || []" :key="attr.name" :value="attr.name" severity="info" />
              <Tag v-if="!node.data.is_active" :value="pt('inactive')" severity="danger" />
            </span>
            <span class="node-actions">
              <Button v-if="canCreate" icon="pi pi-plus" text rounded size="small" :title="pt('Add sub-category')" @click.stop="openAddChild(node.data)" />
              <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" :title="pt('Edit')" @click.stop="openEdit(node.data)" />
              <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" :title="pt('Delete')" @click.stop="confirmDelete(node.data)" />
            </span>
          </div>
        </template>
      </Tree>
      <p v-if="!loading && !nodes.length" class="empty">{{ pt('No categories yet.') }}</p>
    </div>

    <!-- Create / Edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? pt('Edit Category') : pt('New Category')" :style="{ width: '600px' }">
      <div class="form-grid">
        <div class="fld">
          <label>{{ pt('Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Parent Category') }}</label>
          <Select
            v-if="editing"
            v-model="form.parent_id"
            :options="parentOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="pt('— none (root) —')"
            showClear
            :class="{ invalid: fieldError('parent_id') }"
          />
          <span v-else class="parent-static">{{ parentContextLabel }}</span>
          <small v-if="fieldError('parent_id')" class="error-text">{{ fieldError('parent_id') }}</small>
        </div>

        <div class="fld full">
          <label>{{ pt('Description') }}</label>
          <Textarea v-model="form.description" rows="2" :class="{ invalid: fieldError('description') }" />
          <small v-if="fieldError('description')" class="error-text">{{ fieldError('description') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Status') }}</label>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="cat_is_active" />
            <label for="cat_is_active">{{ pt('Active') }}</label>
          </div>
        </div>
      </div>

      <h3 class="attrs-title">{{ pt('Attribute Definitions') }}</h3>
      <p v-if="inheritedNames.length" class="inherited">
        {{ pt('Inherited attributes: {names} (from ancestors)', { names: inheritedNames.join(', ') }) }}
      </p>
      <table class="attrs">
        <thead>
          <tr><th style="width: 30%">{{ pt('Name') }}</th><th style="width: 20%">{{ pt('Type') }}</th><th>{{ pt('Options') }}</th><th style="width: 5rem">{{ pt('Required') }}</th><th style="width: 2.5rem"></th></tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in form.attributes" :key="i">
            <td><InputText v-model="row.name" :placeholder="pt('e.g. Color')" class="w-full" /></td>
            <td><Select v-model="row.type" :options="typeOptions" optionLabel="label" optionValue="value" class="w-full" /></td>
            <td>
              <InputText v-if="row.type === 'select'" v-model="row.optionsText" :placeholder="pt('Comma-separated values')" class="w-full" />
              <span v-else class="na">—</span>
            </td>
            <td class="center"><Checkbox v-model="row.required" binary /></td>
            <td><Button icon="pi pi-times" text rounded severity="danger" size="small" @click="removeRow(i)" /></td>
          </tr>
          <tr v-if="!form.attributes.length">
            <td colspan="5" class="no-attrs">{{ pt('No attribute definitions.') }}</td>
          </tr>
        </tbody>
      </table>
      <Button :label="pt('Add attribute')" icon="pi pi-plus" text size="small" @click="addRow" />

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editing ? pt('Update') : pt('Create')" :loading="saving" @click="save" />
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
.header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.tree-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 0.5rem;
}
.cat-tree {
  border: none;
  padding: 0;
}
.node-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2rem;
}
.node-name {
  font-weight: 500;
}
.node-count {
  color: #6b7280;
  font-size: 0.8rem;
}
.node-tags {
  display: flex;
  gap: 4px;
}
.node-tags :deep(.p-tag) {
  font-size: 0.65rem;
  padding: 1px 6px;
}
.node-actions {
  display: flex;
  visibility: hidden;
  margin-left: 0.25rem;
}
.node-row:hover .node-actions {
  visibility: visible;
}
.empty {
  color: #6b7280;
  text-align: center;
  padding: 1rem;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.fld.full {
  grid-column: 1 / -1;
}
.fld label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.fld :deep(.p-inputtext),
.fld :deep(.p-select),
.fld :deep(textarea) {
  width: 100%;
}
.parent-static {
  padding: 0.55rem 0;
  color: #374151;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.35rem;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.attrs-title {
  font-size: 1rem;
  margin: 1.25rem 0 0.4rem;
}
.inherited {
  color: #6b7280;
  font-size: 0.82rem;
  margin-bottom: 0.5rem;
}
.attrs {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 0.25rem;
}
.attrs th {
  text-align: left;
  font-size: 0.8rem;
  color: #6b7280;
  padding: 0.4rem;
  border-bottom: 1px solid #e5e7eb;
}
.attrs td {
  padding: 0.35rem;
  vertical-align: middle;
}
.attrs .w-full {
  width: 100%;
}
.attrs .center {
  text-align: center;
}
.na {
  color: #9ca3af;
}
.no-attrs {
  text-align: center;
  color: #6b7280;
  font-size: 0.85rem;
  padding: 0.75rem;
}
</style>
