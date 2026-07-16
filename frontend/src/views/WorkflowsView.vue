<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import InputSwitch from 'primevue/inputswitch'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()
const canCreate = computed(() => auth.can('workflows.create'))
const canEdit = computed(() => auth.can('workflows.edit'))
const canDelete = computed(() => auth.can('workflows.delete'))

interface Condition { field: string; operator: string; value: string }
interface Action { type: string; value?: string; field?: string; to?: string; title?: string; message?: string }
interface Workflow {
  id?: number
  name: string
  description?: string | null
  trigger: string
  conditions: Condition[]
  then_actions: Action[]
  else_actions: Action[]
  is_active: boolean
}

interface TriggerMeta { key: string; label: string; fields: string[] }
interface Option { key: string; label: string }

const workflows = ref<Workflow[]>([])
const triggers = ref<TriggerMeta[]>([])
const operators = ref<string[]>([])
const actionTypes = ref<Option[]>([])
const recipients = ref<Option[]>([])
const loading = ref(true)

const editing = ref<Workflow | null>(null)
const saving = ref(false)
const testResult = ref<{ branch: string; would_run: string[] } | null>(null)
const testSample = ref<Record<string, string>>({})

const currentTrigger = computed(() => triggers.value.find((t) => t.key === editing.value?.trigger))

async function load() {
  loading.value = true
  try {
    const [list, meta] = await Promise.all([http.get('/workflows'), http.get('/workflows/meta')])
    workflows.value = list.data.data
    triggers.value = meta.data.triggers
    operators.value = meta.data.operators
    actionTypes.value = meta.data.action_types
    recipients.value = meta.data.recipients
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load workflows', life: 4000 })
  } finally {
    loading.value = false
  }
}
onMounted(load)

function blank(): Workflow {
  return {
    name: '',
    description: '',
    trigger: triggers.value[0]?.key ?? '',
    conditions: [],
    then_actions: [],
    else_actions: [],
    is_active: true,
  }
}

function newWorkflow() {
  editing.value = blank()
  testResult.value = null
  testSample.value = {}
}
function edit(w: Workflow) {
  editing.value = JSON.parse(JSON.stringify(w))
  testResult.value = null
  testSample.value = {}
}
function cancel() {
  editing.value = null
}

function addCondition() {
  editing.value?.conditions.push({ field: currentTrigger.value?.fields[0] ?? '', operator: '=', value: '' })
}
function addAction(branch: 'then_actions' | 'else_actions') {
  editing.value?.[branch].push({ type: 'notify', to: 'creator', message: '' })
}

async function runTest() {
  if (!editing.value) return
  try {
    const { data } = await http.post('/workflows/test', {
      conditions: editing.value.conditions,
      then_actions: editing.value.then_actions,
      else_actions: editing.value.else_actions,
      sample: testSample.value,
    })
    testResult.value = data
  } catch {
    toast.add({ severity: 'error', summary: 'Test failed', life: 3000 })
  }
}

async function save() {
  if (!editing.value) return
  if (!editing.value.name || !editing.value.trigger) {
    toast.add({ severity: 'warn', summary: 'Name and trigger are required', life: 3000 })
    return
  }
  saving.value = true
  try {
    if (editing.value.id) {
      await http.put(`/workflows/${editing.value.id}`, editing.value)
    } else {
      await http.post('/workflows', editing.value)
    }
    toast.add({ severity: 'success', summary: 'Workflow saved', life: 2500 })
    editing.value = null
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Save failed', detail: e.response?.data?.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(w: Workflow) {
  const res = await Swal.fire({ title: `Delete "${w.name}"?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/workflows/${w.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
    load()
  } catch {
    toast.add({ severity: 'error', summary: 'Delete failed', life: 3000 })
  }
}

function triggerLabel(key: string) {
  return triggers.value.find((t) => t.key === key)?.label ?? key
}
</script>

<template>
  <div class="workflows">
    <div class="head">
      <div>
        <h1>Workflow Builder</h1>
        <p class="subtitle">Automate approvals and notifications with IF / THEN / ELSE rules.</p>
      </div>
      <span v-if="!editing" class="head-actions">
        <TableExportButtons title="Workflows" :rows="workflows"
          :columns="[
            { key: 'name', label: 'Name' },
            { key: 'trigger', label: 'Trigger', format: (v) => triggerLabel(String(v)) },
            { key: 'description', label: 'Description' },
            { key: 'is_active', label: 'Active', format: (v) => (v ? 'Yes' : 'No') },
          ]" />
        <Button v-if="canCreate" label="New Workflow" icon="pi pi-plus" @click="newWorkflow" />
      </span>
    </div>

    <!-- List -->
    <div v-if="!editing" class="list">
      <div v-if="loading" class="empty">Loading…</div>
      <div v-else-if="!workflows.length" class="empty">No workflows yet. Create one to automate a process.</div>
      <div v-for="w in workflows" :key="w.id" class="wf-card">
        <div class="wf-main">
          <div class="wf-name">
            {{ w.name }}
            <span class="badge" :class="{ off: !w.is_active }">{{ w.is_active ? 'Active' : 'Inactive' }}</span>
          </div>
          <div class="wf-meta">
            <span class="chip">{{ triggerLabel(w.trigger) }}</span>
            <span class="muted">{{ w.conditions.length }} condition(s) · {{ w.then_actions.length }} then · {{ w.else_actions.length }} else</span>
          </div>
          <p v-if="w.description" class="wf-desc">{{ w.description }}</p>
        </div>
        <div class="wf-actions">
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded @click="edit(w)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" @click="remove(w)" />
        </div>
      </div>
    </div>

    <!-- Builder -->
    <div v-else class="builder">
      <div class="grid-2">
        <div class="field">
          <label>Name</label>
          <InputText v-model="editing.name" placeholder="e.g. High-value expense approval" />
        </div>
        <div class="field">
          <label>Trigger (WHEN)</label>
          <Select v-model="editing.trigger" :options="triggers" optionLabel="label" optionValue="key" />
        </div>
      </div>
      <div class="field">
        <label>Description</label>
        <InputText v-model="editing.description" placeholder="Optional" />
      </div>
      <div class="field inline">
        <label>Active</label>
        <InputSwitch v-model="editing.is_active" />
      </div>

      <!-- IF -->
      <div class="section">
        <div class="section-head">
          <h3><span class="kw if">IF</span> all conditions match</h3>
          <Button label="Add condition" icon="pi pi-plus" text size="small" @click="addCondition" />
        </div>
        <p v-if="!editing.conditions.length" class="muted small">No conditions — the THEN branch always runs.</p>
        <div v-for="(c, i) in editing.conditions" :key="i" class="rule-row">
          <Select v-model="c.field" :options="currentTrigger?.fields || []" placeholder="Field" class="grow" />
          <Select v-model="c.operator" :options="operators" placeholder="Op" class="op" />
          <InputText v-model="c.value" placeholder="Value" class="grow" />
          <Button icon="pi pi-times" text rounded severity="danger" @click="editing.conditions.splice(i, 1)" />
        </div>
      </div>

      <!-- THEN -->
      <div class="section">
        <div class="section-head">
          <h3><span class="kw then">THEN</span> do</h3>
          <Button label="Add action" icon="pi pi-plus" text size="small" @click="addAction('then_actions')" />
        </div>
        <div v-for="(a, i) in editing.then_actions" :key="i" class="rule-row">
          <Select v-model="a.type" :options="actionTypes" optionLabel="label" optionValue="key" class="op-wide" />
          <template v-if="a.type === 'set_status'">
            <InputText v-model="a.value" placeholder="status e.g. pending" class="grow" />
          </template>
          <template v-else-if="a.type === 'set_field'">
            <InputText v-model="a.field" placeholder="field" class="grow" />
            <InputText v-model="a.value" placeholder="value" class="grow" />
          </template>
          <template v-else-if="a.type === 'notify'">
            <Select v-model="a.to" :options="recipients" optionLabel="label" optionValue="key" placeholder="To" class="op-wide" />
            <InputText v-model="a.message" placeholder="Message" class="grow" />
          </template>
          <Button icon="pi pi-times" text rounded severity="danger" @click="editing.then_actions.splice(i, 1)" />
        </div>
      </div>

      <!-- ELSE -->
      <div class="section">
        <div class="section-head">
          <h3><span class="kw else">ELSE</span> do</h3>
          <Button label="Add action" icon="pi pi-plus" text size="small" @click="addAction('else_actions')" />
        </div>
        <div v-for="(a, i) in editing.else_actions" :key="i" class="rule-row">
          <Select v-model="a.type" :options="actionTypes" optionLabel="label" optionValue="key" class="op-wide" />
          <template v-if="a.type === 'set_status'">
            <InputText v-model="a.value" placeholder="status e.g. approved" class="grow" />
          </template>
          <template v-else-if="a.type === 'set_field'">
            <InputText v-model="a.field" placeholder="field" class="grow" />
            <InputText v-model="a.value" placeholder="value" class="grow" />
          </template>
          <template v-else-if="a.type === 'notify'">
            <Select v-model="a.to" :options="recipients" optionLabel="label" optionValue="key" placeholder="To" class="op-wide" />
            <InputText v-model="a.message" placeholder="Message" class="grow" />
          </template>
          <Button icon="pi pi-times" text rounded severity="danger" @click="editing.else_actions.splice(i, 1)" />
        </div>
      </div>

      <!-- Test -->
      <div class="section test">
        <h3><i class="pi pi-flask" /> Test with sample values</h3>
        <div class="sample-grid">
          <div v-for="f in currentTrigger?.fields || []" :key="f" class="field">
            <label>{{ f }}</label>
            <InputText v-model="testSample[f]" :placeholder="f" />
          </div>
        </div>
        <Button label="Run test" icon="pi pi-play" outlined size="small" @click="runTest" />
        <div v-if="testResult" class="test-result">
          <span class="badge branch">{{ testResult.branch }} branch runs</span>
          <ul>
            <li v-for="(r, i) in testResult.would_run" :key="i">{{ r }}</li>
            <li v-if="!testResult.would_run.length" class="muted">No actions on this branch.</li>
          </ul>
        </div>
      </div>

      <div class="builder-actions">
        <Button label="Cancel" severity="secondary" outlined @click="cancel" />
        <Button label="Save Workflow" icon="pi pi-check" :loading="saving" @click="save" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.workflows h1 {
  font-size: 1.6rem;
  font-weight: 600;
}
.subtitle {
  color: #6b7280;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}
.head-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.empty {
  color: #6b7280;
  padding: 2rem;
  text-align: center;
  background: var(--surface, #fff);
  border: 1px dashed var(--border, #e3ded3);
  border-radius: 12px;
}
.wf-card {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem 1.25rem;
  margin-bottom: 0.75rem;
}
.wf-name {
  font-weight: 600;
  font-size: 1.05rem;
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.wf-meta {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.35rem;
}
.wf-desc {
  color: #6b7280;
  font-size: 0.85rem;
  margin: 0.4rem 0 0;
}
.chip {
  background: rgba(61, 99, 124, 0.12);
  color: var(--ink-blue, #2c4a5e);
  padding: 0.15rem 0.55rem;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: 600;
}
.badge {
  background: #4c8b6c;
  color: #fff;
  padding: 0.1rem 0.5rem;
  border-radius: 20px;
  font-size: 0.68rem;
  font-weight: 700;
}
.badge.off {
  background: #9ca3af;
}
.badge.branch {
  background: var(--safety-amber, #e8a33d);
  color: #23292f;
}
.muted {
  color: #9ca3af;
  font-size: 0.8rem;
}
.small {
  font-size: 0.82rem;
}

/* Builder */
.builder {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.5rem;
  max-width: 860px;
}
.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.field {
  margin-bottom: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field.inline {
  flex-direction: row;
  align-items: center;
  gap: 0.75rem;
}
.field label {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--graphite, #1e2328);
}
.section {
  border-top: 1px solid var(--border, #e8e4da);
  padding-top: 1rem;
  margin-top: 1rem;
}
.section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.section h3 {
  font-size: 1rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.kw {
  font-family: 'IBM Plex Mono', monospace;
  font-weight: 700;
  padding: 0.1rem 0.5rem;
  border-radius: 6px;
  font-size: 0.85rem;
}
.kw.if {
  background: rgba(61, 99, 124, 0.15);
  color: var(--ink-blue, #2c4a5e);
}
.kw.then {
  background: rgba(76, 139, 108, 0.16);
  color: #4c8b6c;
}
.kw.else {
  background: rgba(193, 80, 46, 0.14);
  color: #c1502e;
}
.rule-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-top: 0.6rem;
}
.grow {
  flex: 1;
}
.op {
  width: 90px;
}
.op-wide {
  width: 190px;
}
.test {
  background: rgba(232, 163, 61, 0.06);
  border-radius: 10px;
  padding: 1rem;
}
.sample-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 0.75rem;
  margin: 0.5rem 0 0.75rem;
}
.test-result {
  margin-top: 0.75rem;
}
.test-result ul {
  margin: 0.5rem 0 0;
  padding-left: 1.2rem;
  font-size: 0.85rem;
}
.builder-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1.5rem;
}
</style>
