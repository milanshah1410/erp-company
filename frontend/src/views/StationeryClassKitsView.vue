<script setup lang="ts">
/**
 * Class booklists — the screen a stationery shop lives on from April to June.
 *
 * The whole design serves one interaction: somebody says "Class 5, Sunrise", and
 * within a few seconds the counter has twenty-two priced lines, knows which three
 * are short, and can hand over a total. So the supply panel is the primary
 * surface, it opens straight from a row, and it shows the shortfall *before* the
 * total rather than after — because telling a parent the price and then telling
 * them what is missing is how a queue turns into an argument.
 *
 * Two states are shown loudly on purpose. A draft list cannot be sold from, and
 * the reason is that a half-typed booklist is indistinguishable from a finished
 * one until someone goes home short. And the "sets" figure is the single most
 * consequential number on the supply panel: it is 1 for a parent and 400 for a
 * school, and it is what makes the bulk slab price apply at all.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
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

const canCreate = computed(() => auth.can('stationery-kits.create'))
const canEdit = computed(() => auth.can('stationery-kits.edit'))
const canDelete = computed(() => auth.can('stationery-kits.delete'))
const canSell = computed(() => auth.can('sales.create'))

const rows = ref<any[]>([])
const summary = ref({ lists: 0, published: 0, draft: 0, expected_sets: 0 })
const loading = ref(false)
const institutions = ref<any[]>([])
const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const customers = ref<any[]>([])

const search = ref('')
const statusFilter = ref<string | null>(null)
const institutionFilter = ref<number | null>(null)

const STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Published', value: 'published' },
  { label: 'Archived', value: 'archived' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stationery/class-kits', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        institution_id: institutionFilter.value || undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const [i, p, w, c] = await Promise.all([
    http.get('/stationery/institutions', { params: { per_page: 500, is_active: 1 } }),
    http.get('/products', { params: { per_page: 1000, is_active: 1 } }),
    http.get('/warehouses', { params: { per_page: 100 } }),
    http.get('/customers', { params: { per_page: 500, is_active: 1 } }),
  ])
  institutions.value = (i.data.data ?? i.data).map((x: any) => ({
    label: x.name,
    value: x.id,
    requires_po: x.requires_po,
    customer_id: x.customer_id,
  }))
  products.value = (p.data.data ?? p.data).map((x: any) => ({
    label: `${x.name} (${x.sku})`,
    value: x.id,
    price: x.selling_price,
  }))
  warehouses.value = (w.data.data ?? w.data).map((x: any) => ({ label: x.name, value: x.id }))
  customers.value = (c.data.data ?? c.data).map((x: any) => ({ label: `${x.name} (${x.code})`, value: x.id }))
  if (warehouses.value.length && !supply.value.warehouse_id) supply.value.warehouse_id = warehouses.value[0].value
}

// ---- The supply panel — the counter's whole job ---------------------------

const supplyVisible = ref(false)
const supplyKit = ref<any>(null)
const estimating = ref(false)
const committing = ref(false)
const estimate = ref<any>(null)

const supply = ref({
  warehouse_id: null as number | null,
  customer_id: null as number | null,
  sets: 1,
  include_optional: false,
  po_number: '',
})

async function openSupply(row: any) {
  supplyKit.value = row
  estimate.value = null
  supply.value = {
    warehouse_id: warehouses.value[0]?.value ?? null,
    // A school's own order defaults to the ledger party behind the account; a
    // parent's sale needs whoever is at the counter, which is why this is
    // clearable rather than fixed.
    customer_id: institutions.value.find((i) => i.value === row.institution_id)?.customer_id ?? null,
    sets: 1,
    include_optional: false,
    po_number: '',
  }
  supplyVisible.value = true
  await runEstimate()
}

async function runEstimate() {
  if (!supplyKit.value || !supply.value.warehouse_id) return
  estimating.value = true
  try {
    const { data } = await http.post(`/stationery/class-kits/${supplyKit.value.id}/estimate`, {
      warehouse_id: supply.value.warehouse_id,
      customer_id: supply.value.customer_id || undefined,
      sets: supply.value.sets,
      include_optional: supply.value.include_optional,
    })
    estimate.value = data
  } catch (e: any) {
    estimate.value = null
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    estimating.value = false
  }
}

async function commit(kind: 'quote' | 'order') {
  if (!supplyKit.value) return
  committing.value = true
  try {
    const { data } = await http.post(`/stationery/class-kits/${supplyKit.value.id}/${kind}`, {
      warehouse_id: supply.value.warehouse_id,
      customer_id: supply.value.customer_id || undefined,
      sets: supply.value.sets,
      include_optional: supply.value.include_optional,
      po_number: supply.value.po_number || undefined,
    })
    toast.add({ severity: 'success', summary: data.message, life: 4000 })
    supplyVisible.value = false
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not raise the document'), life: 5000 })
  } finally {
    committing.value = false
  }
}

/** The school on this list demands a PO, and the box is empty. */
const poMissing = computed(() => {
  const inst = institutions.value.find((i) => i.value === supplyKit.value?.institution_id)
  return !!inst?.requires_po && !supply.value.po_number
})

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

function currentSession() {
  const now = new Date()
  const start = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1
  return `${start}-${String((start + 1) % 100).padStart(2, '0')}`
}

const blank = () => ({
  institution_id: null as number | null,
  academic_year: currentSession(),
  class_label: '',
  stream: '',
  title: '',
  discount_percent: 0,
  is_mandatory_set: false,
  expected_students: null as number | null,
  notes: '',
  items: [] as any[],
})

const form = ref(blank())

function addLine() {
  form.value.items.push({ product_id: null, quantity: 1, is_optional: false, remark: '' })
}

function removeLine(index: number) {
  form.value.items.splice(index, 1)
}

function openCreate() {
  form.value = blank()
  addLine()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/stationery/class-kits/${row.id}`)
  const k = data.data
  editingId.value = k.id
  form.value = {
    institution_id: k.institution_id,
    academic_year: k.academic_year,
    class_label: k.class_label,
    stream: k.stream ?? '',
    title: k.title ?? '',
    discount_percent: k.discount_percent,
    is_mandatory_set: k.is_mandatory_set,
    expected_students: k.expected_students,
    notes: k.notes ?? '',
    items: k.items.map((i: any) => ({
      product_id: i.product_id,
      quantity: i.quantity,
      is_optional: i.is_optional,
      remark: i.remark ?? '',
    })),
  }
  if (!form.value.items.length) addLine()
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = {
      ...form.value,
      items: form.value.items
        .filter((i: any) => i.product_id)
        .map((i: any, index: number) => ({ ...i, sort_order: index })),
    }
    if (editingId.value) {
      await http.put(`/stationery/class-kits/${editingId.value}`, payload)
    } else {
      await http.post('/stationery/class-kits', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Booklist saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the booklist'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function publish(row: any) {
  try {
    await http.post(`/stationery/class-kits/${row.id}/publish`)
    await load()
    toast.add({ severity: 'success', summary: pt('The counter can now sell from this list.'), life: 3000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 5000 })
  }
}

async function archive(row: any) {
  const res = await Swal.fire({
    title: pt('Archive this list?'),
    text: pt('The counter stops being able to sell from it. It stays readable, which is what next year’s list is built from.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Archive'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return

  await http.post(`/stationery/class-kits/${row.id}/archive`)
  await load()
}

async function rollForward(row: any) {
  const [y] = row.academic_year.split('-').map(Number)
  const suggested = `${y + 1}-${String((y + 2) % 100).padStart(2, '0')}`

  const res = await Swal.fire({
    title: pt('Copy into which session?'),
    input: 'text',
    inputValue: suggested,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Copy'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed || !res.value) return

  try {
    await http.post(`/stationery/class-kits/${row.id}/roll-forward`, { academic_year: res.value })
    await load()
    toast.add({ severity: 'success', summary: pt('Copied as a draft — check it against this year’s list.'), life: 4000 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 5000 })
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this booklist?'),
    text: pt('Only lists with no orders or quotations against them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/stationery/class-kits/${row.id}`)
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Class Booklists')"
      :subtitle="pt('What each class needs, as a document — so “Class 5, Sunrise” is a bill and not twenty minutes of typing')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Booklist')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Lists') }}</span>
        <span class="t-value">{{ summary.lists }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Published') }}</span>
        <span class="t-value">{{ summary.published }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Still draft') }}</span>
        <span class="t-value warn">{{ summary.draft }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Sets expected') }}</span>
        <span class="t-value">{{ summary.expected_sets }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('Class, list number or school')" @keyup.enter="load" />
      <Select
        v-model="institutionFilter"
        :options="[{ label: pt('All institutions'), value: null }, ...institutions]"
        option-label="label"
        option-value="value"
        filter
        @change="load"
      />
      <Select
        v-model="statusFilter"
        :options="[{ label: pt('Any status'), value: null }, ...STATUSES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Class Booklists"
        filenameBase="stationery-booklists"
        :rows="rows"
        :columns="[
          { key: 'kit_number', label: 'List' },
          { key: 'institution', label: 'Institution' },
          { key: 'class_label', label: 'Class' },
          { key: 'stream', label: 'Stream' },
          { key: 'academic_year', label: 'Session' },
          { key: 'items_count', label: 'Items' },
          { key: 'expected_students', label: 'Expected' },
          { key: 'status', label: 'Status' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" paginator :rows="25" striped-rows responsive-layout="scroll">
      <Column field="class_label" :header="pt('Class')" sortable>
        <template #body="{ data }">
          <div class="strong">
            {{ data.class_label }}<span v-if="data.stream" class="muted"> · {{ data.stream }}</span>
          </div>
          <small class="muted">{{ data.kit_number }} · {{ data.academic_year }}</small>
        </template>
      </Column>

      <Column field="institution" :header="pt('Institution')">
        <template #body="{ data }">
          <span v-if="data.institution">{{ data.institution }}</span>
          <!-- A list with no school behind it is a real, sellable list. -->
          <small v-else class="muted">{{ pt('Generic list') }}</small>
          <small v-if="data.board" class="muted block">{{ data.board }}</small>
        </template>
      </Column>

      <Column field="items_count" :header="pt('Items')" sortable />

      <Column field="expected_students" :header="pt('Expected sets')" sortable>
        <template #body="{ data }">{{ data.expected_students ?? '—' }}</template>
      </Column>

      <Column :header="pt('Terms')">
        <template #body="{ data }">
          <Tag v-if="data.is_mandatory_set" :value="pt('Whole set')" severity="info" />
          <span v-if="data.discount_percent > 0" class="muted small">{{ data.discount_percent }}% {{ pt('set discount') }}</span>
        </template>
      </Column>

      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag
            :value="pt(data.status === 'published' ? 'Published' : data.status === 'draft' ? 'Draft' : 'Archived')"
            :severity="data.status === 'published' ? 'success' : data.status === 'draft' ? 'warn' : 'secondary'"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 14rem">
        <template #body="{ data }">
          <Button
            v-if="data.is_sellable"
            icon="pi pi-calculator"
            text
            rounded
            severity="success"
            :title="pt('Supply against this list')"
            @click="openSupply(data)"
          />
          <Button
            v-if="canEdit && data.status === 'draft'"
            icon="pi pi-check-circle"
            text
            rounded
            :title="pt('Publish')"
            @click="publish(data)"
          />
          <Button v-if="canEdit && data.status !== 'archived'" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
          <Button v-if="canCreate" icon="pi pi-copy" text rounded :title="pt('Copy to next session')" @click="rollForward(data)" />
          <Button
            v-if="canDelete && data.status === 'published'"
            icon="pi pi-inbox"
            text
            rounded
            severity="warn"
            :title="pt('Archive')"
            @click="archive(data)"
          />
          <Button
            v-if="canDelete && data.status === 'draft'"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            :title="pt('Delete')"
            @click="remove(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No booklists yet. This is the screen the season runs on — start with one class from one school.') }}
        </div>
      </template>
    </DataTable>

    <!-- Supply -->
    <Dialog
      v-model:visible="supplyVisible"
      :header="pt('Supply against this list')"
      modal
      :style="{ width: '940px' }"
    >
      <div v-if="supplyKit" class="supply-head">
        <span class="strong">{{ supplyKit.class_label }}</span>
        <span class="muted">{{ supplyKit.institution || pt('Generic list') }} · {{ supplyKit.academic_year }}</span>
      </div>

      <div class="supply-controls">
        <div class="fld">
          <label>{{ pt('Sets') }}</label>
          <InputNumber v-model="supply.sets" :min="1" class="w-full" @update:model-value="runEstimate" />
          <small class="hint">{{ pt('1 for a parent, the roll for a school.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Buyer') }}</label>
          <Select
            v-model="supply.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
            @change="runEstimate"
          />
          <small class="hint">{{ pt('Decides the slab rate.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('From warehouse') }}</label>
          <Select
            v-model="supply.warehouse_id"
            :options="warehouses"
            option-label="label"
            option-value="value"
            class="w-full"
            @change="runEstimate"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Their PO number') }}</label>
          <InputText v-model="supply.po_number" class="w-full mono" />
        </div>
        <div class="chk">
          <Checkbox v-model="supply.include_optional" binary input-id="opt" @change="runEstimate" />
          <label for="opt">{{ pt('Include optional items') }}</label>
        </div>
      </div>

      <!-- Shortfall before the total, deliberately. -->
      <Message
        v-if="estimate?.shortfalls?.length"
        severity="warn"
        :closable="false"
      >
        {{ pt('Short on the shelf') }}:
        <span v-for="(s, idx) in estimate.shortfalls" :key="s.kit_item_id">
          <template v-if="idx"> · </template>{{ s.name }} ({{ s.short_by }})
        </span>
      </Message>

      <DataTable v-if="estimate" :value="estimate.lines" data-key="kit_item_id" size="small" striped-rows :loading="estimating">
        <Column field="name" :header="pt('Item')">
          <template #body="{ data }">
            <div>{{ data.name }}</div>
            <small v-if="data.catalogue_name !== data.name" class="muted">{{ data.catalogue_name }}</small>
            <Tag v-if="data.is_optional" :value="pt('Optional')" severity="secondary" />
          </template>
        </Column>
        <Column field="quantity" :header="pt('Qty')" />
        <Column field="available" :header="pt('On shelf')">
          <template #body="{ data }">
            <span :class="{ short: data.available < data.quantity }">{{ data.available }}</span>
          </template>
        </Column>
        <Column field="unit_price" :header="pt('Rate')" />
        <Column field="tax_percent" :header="pt('GST %')" />
        <Column field="line_total" :header="pt('Amount')" />
      </DataTable>

      <div v-if="estimate" class="totals">
        <span>{{ pt('Gross') }} <b>{{ estimate.header.subtotal }}</b></span>
        <span>{{ pt('Discount') }} <b>{{ estimate.header.item_discount_total + estimate.header.discount_amount }}</b>
          <small class="muted">({{ estimate.discount_percent }}%)</small>
        </span>
        <span>{{ pt('GST') }} <b>{{ estimate.header.tax_amount }}</b></span>
        <span class="grand">{{ pt('Total') }} <b>{{ estimate.header.total }}</b></span>
      </div>

      <template #footer>
        <Button :label="pt('Close')" text @click="supplyVisible = false" />
        <Button
          v-if="canSell"
          :label="pt('Raise Quotation')"
          icon="pi pi-file"
          outlined
          :loading="committing"
          :disabled="!estimate"
          @click="commit('quote')"
        />
        <Button
          v-if="canSell"
          :label="pt('Raise School Order')"
          icon="pi pi-check"
          :loading="committing"
          :disabled="!estimate || poMissing"
          :title="poMissing ? pt('This school will not pay without their PO number.') : ''"
          @click="commit('order')"
        />
      </template>
    </Dialog>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Booklist') : pt('New Booklist')"
      modal
      :style="{ width: '960px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Institution') }}</label>
          <Select
            v-model="form.institution_id"
            :options="institutions"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
          <small class="hint">{{ pt('Leave empty for one of your own generic lists.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Session') }} *</label>
          <InputText v-model="form.academic_year" class="w-full" placeholder="2026-27" />
          <small v-if="errors.academic_year" class="err">{{ errors.academic_year[0] }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Class') }} *</label>
          <InputText v-model="form.class_label" class="w-full" :placeholder="pt('Class 5')" />
          <small v-if="errors.class_label" class="err">{{ errors.class_label[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Stream') }}</label>
          <InputText v-model="form.stream" class="w-full" :placeholder="pt('Science / Commerce')" />
        </div>
        <div class="fld">
          <label>{{ pt('Expected sets') }}</label>
          <InputNumber v-model="form.expected_students" :min="0" class="w-full" />
          <small class="hint">{{ pt('Feeds the season buying list.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Title') }}</label>
          <InputText v-model="form.title" class="w-full" :placeholder="pt('Class 5 — complete set')" />
        </div>
        <div class="fld">
          <label>{{ pt('Set discount %') }}</label>
          <InputNumber v-model="form.discount_percent" :min="0" :max="100" :max-fraction-digits="2" class="w-full" />
          <small class="hint">{{ pt('Added to the school’s concession.') }}</small>
        </div>

        <div class="fld span-3 chk">
          <Checkbox v-model="form.is_mandatory_set" binary input-id="mand" />
          <label for="mand">{{ pt('The school insists on the whole set (optional items become compulsory)') }}</label>
        </div>

        <div class="span-3">
          <div class="lines-head">
            <label>{{ pt('Items') }}</label>
            <Button :label="pt('Add line')" icon="pi pi-plus" text size="small" @click="addLine" />
          </div>
          <div v-for="(line, index) in form.items" :key="index" class="line">
            <Select
              v-model="line.product_id"
              :options="products"
              option-label="label"
              option-value="value"
              filter
              class="line-product"
              :placeholder="pt('Product')"
            />
            <InputNumber v-model="line.quantity" :min="0.001" :max-fraction-digits="3" class="line-qty" />
            <InputText
              v-model="line.remark"
              class="line-remark"
              :placeholder="pt('What the school called it')"
            />
            <div class="chk">
              <Checkbox v-model="line.is_optional" binary :input-id="`opt-${index}`" />
              <label :for="`opt-${index}`">{{ pt('Optional') }}</label>
            </div>
            <Button icon="pi pi-times" text rounded severity="danger" @click="removeLine(index)" />
          </div>
          <small class="hint">
            {{ pt('The remark is kept verbatim — the parent is holding the sheet it was copied from and will compare.') }}
          </small>
        </div>

        <div class="fld span-3">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="form.notes" rows="2" auto-resize class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="pt('Save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.chk { display: flex; gap: 0.4rem; align-items: center; }
.chk label { font-size: 0.8rem; }

.supply-head { display: flex; gap: 0.6rem; align-items: baseline; margin-bottom: 0.8rem; }
.supply-controls { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; align-items: end; margin-bottom: 0.9rem; }
.totals { display: flex; gap: 1.5rem; flex-wrap: wrap; justify-content: flex-end; margin-top: 0.8rem; font-size: 0.85rem; }
.totals .grand { font-size: 1rem; }
.short { color: #b91c1c; font-weight: 600; }

.lines-head { display: flex; justify-content: space-between; align-items: center; }
.line { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.45rem; flex-wrap: wrap; }
.line-product { flex: 2 1 240px; }
.line-qty { width: 90px; }
.line-remark { flex: 2 1 200px; }

.muted { color: #6b7280; }
.small { font-size: 0.75rem; }
.block { display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }

@media (max-width: 720px) {
  .grid { grid-template-columns: 1fr; }
  .span-2 { grid-column: 1 / -1; }
}
</style>
