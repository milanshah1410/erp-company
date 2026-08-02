<script setup lang="ts">
/**
 * The institution register — the schools, colleges and offices this shop
 * supplies.
 *
 * Built around what a shop needs to know in April rather than around an address
 * book. Three things lead: how many students, how many of this year's class lists
 * are actually published, and whether the account demands a purchase-order number
 * — because that last one is the difference between a bill that gets paid in
 * October and one that does not.
 *
 * The two discount rates sit together on the form on purpose. There are two
 * buyers here and only one of them is a person, and typing the school's bulk rate
 * into the parents' box gives away margin on every one of four hundred sets
 * before anyone notices.
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
import DatePicker from 'primevue/datepicker'
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

const canCreate = computed(() => auth.can('stationery-institutions.create'))
const canEdit = computed(() => auth.can('stationery-institutions.edit'))
const canDelete = computed(() => auth.can('stationery-institutions.delete'))

const TYPES = [
  { label: 'School', value: 'school' },
  { label: 'College', value: 'college' },
  { label: 'Coaching class', value: 'coaching' },
  { label: 'Office', value: 'office' },
  { label: 'Government', value: 'government' },
  { label: 'NGO / Trust', value: 'ngo' },
  { label: 'Other', value: 'other' },
]

const rows = ref<any[]>([])
const summary = ref({ institutions: 0, active: 0, authorised: 0, students: 0, published_kits: 0 })
const loading = ref(false)
const customers = ref<any[]>([])
const search = ref('')
const typeFilter = ref<string | null>(null)
const authorisedOnly = ref(false)

function typeLabel(v: string) {
  return TYPES.find((t) => t.value === v)?.label ?? v
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stationery/institutions', {
      params: {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        authorised: authorisedOnly.value ? 1 : undefined,
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
  const { data } = await http.get('/customers', { params: { per_page: 500, is_active: 1 } })
  customers.value = (data.data ?? data).map((c: any) => ({
    label: `${c.name} (${c.code})`,
    value: c.id,
  }))
}

// ---- The season panel -----------------------------------------------------

const seasonVisible = ref(false)
const seasonLoading = ref(false)
const season = ref<any>(null)

async function openSeason(row: any) {
  seasonVisible.value = true
  seasonLoading.value = true
  season.value = null
  try {
    const { data } = await http.get(`/stationery/institutions/${row.id}/season`)
    season.value = data
  } finally {
    seasonLoading.value = false
  }
}

// ---- Editor ---------------------------------------------------------------

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

/** "2026-27" — the way the trade writes a session, and what the API validates. */
function currentSession() {
  const now = new Date()
  // April is the Indian academic year boundary; before it the shop is still
  // supplying last year's session.
  const start = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1
  return `${start}-${String((start + 1) % 100).padStart(2, '0')}`
}

const blank = () => ({
  name: '',
  customer_id: null as number | null,
  type: 'school',
  board: '',
  medium: '',
  academic_year: currentSession(),
  session_starts_on: null as Date | null,
  student_count: null as number | null,
  contact_person: '',
  contact_phone: '',
  contact_email: '',
  address: '',
  is_authorised_vendor: false,
  parent_discount_percent: 0,
  school_discount_percent: 0,
  credit_days: 0,
  requires_po: false,
  notes: '',
  is_active: true,
})

const form = ref(blank())

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function openCreate() {
  form.value = blank()
  editingId.value = null
  errors.value = {}
  dialogVisible.value = true
}

async function openEdit(row: any) {
  errors.value = {}
  const { data } = await http.get(`/stationery/institutions/${row.id}`)
  const i = data.data
  editingId.value = i.id
  form.value = {
    name: i.name,
    customer_id: i.customer_id,
    type: i.type,
    board: i.board ?? '',
    medium: i.medium ?? '',
    academic_year: i.academic_year,
    session_starts_on: i.session_starts_on ? new Date(i.session_starts_on) : null,
    student_count: i.student_count,
    contact_person: i.contact_person ?? '',
    contact_phone: i.contact_phone ?? '',
    contact_email: i.contact_email ?? '',
    address: i.address ?? '',
    is_authorised_vendor: i.is_authorised_vendor,
    parent_discount_percent: i.parent_discount_percent,
    school_discount_percent: i.school_discount_percent,
    credit_days: i.credit_days,
    requires_po: i.requires_po,
    notes: i.notes ?? '',
    is_active: i.is_active,
  }
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form.value, session_starts_on: asDate(form.value.session_starts_on) }
    if (editingId.value) {
      await http.put(`/stationery/institutions/${editingId.value}`, payload)
    } else {
      await http.post('/stationery/institutions', payload)
    }
    dialogVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Institution saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the institution'),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}

async function remove(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this institution?'),
    text: pt('Only accounts with no booklists and no orders can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/stationery/institutions/${row.id}`)
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
      :title="pt('Institutions')"
      :subtitle="pt('The schools, colleges and offices you supply — their session, their roll and what they pay')"
    >
      <template #actions>
        <Button v-if="canCreate" :label="pt('Add Institution')" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Accounts') }}</span>
        <span class="t-value">{{ summary.institutions }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Active') }}</span>
        <span class="t-value">{{ summary.active }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Appointed supplier') }}</span>
        <span class="t-value">{{ summary.authorised }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Students on the books') }}</span>
        <span class="t-value">{{ summary.students }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Published booklists') }}</span>
        <span class="t-value">{{ summary.published_kits }}</span>
      </div>
    </div>

    <div class="toolbar">
      <InputText v-model="search" :placeholder="pt('School, code, board or contact')" @keyup.enter="load" />
      <Select
        v-model="typeFilter"
        :options="[{ label: pt('All types'), value: null }, ...TYPES]"
        option-label="label"
        option-value="value"
        @change="load"
      />
      <Button
        :label="pt('Appointed only')"
        :outlined="!authorisedOnly"
        icon="pi pi-verified"
        @click="
          () => {
            authorisedOnly = !authorisedOnly
            load()
          }
        "
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
      <TableExportButtons
        title="Institutions"
        filenameBase="stationery-institutions"
        :rows="rows"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Institution' },
          { key: 'type', label: 'Type' },
          { key: 'board', label: 'Board' },
          { key: 'academic_year', label: 'Session' },
          { key: 'student_count', label: 'Students' },
          { key: 'published_kits_count', label: 'Published lists' },
          { key: 'parent_discount_percent', label: 'Parent %' },
          { key: 'school_discount_percent', label: 'School %' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="id" paginator :rows="25" striped-rows responsive-layout="scroll">
      <Column field="name" :header="pt('Institution')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.name }}</div>
          <small class="muted">
            {{ data.code }} · {{ pt(typeLabel(data.type)) }}
            <template v-if="data.board"> · {{ data.board }}</template>
          </small>
        </template>
      </Column>

      <Column field="academic_year" :header="pt('Session')" sortable>
        <template #body="{ data }">
          <div>{{ data.academic_year }}</div>
          <small v-if="data.student_count" class="muted">{{ data.student_count }} {{ pt('students') }}</small>
        </template>
      </Column>

      <Column field="published_kits_count" :header="pt('Booklists')" sortable>
        <template #body="{ data }">
          <Tag
            :value="`${data.published_kits_count} / ${data.kits_count}`"
            :severity="data.published_kits_count ? 'success' : 'secondary'"
          />
          <small class="muted block">{{ pt('published') }}</small>
        </template>
      </Column>

      <Column :header="pt('Discounts')">
        <template #body="{ data }">
          <div class="disc">
            <span>{{ pt('Parents') }} <b>{{ data.parent_discount_percent }}%</b></span>
            <span class="muted">{{ pt('School') }} <b>{{ data.school_discount_percent }}%</b></span>
          </div>
        </template>
      </Column>

      <Column field="customer" :header="pt('Bills to')">
        <template #body="{ data }">
          <span v-if="data.customer">{{ data.customer }}</span>
          <!-- No ledger party at all is a real and common state: the school
               publishes a list and every rupee comes from parents. -->
          <small v-else class="muted">{{ pt('Parents only') }}</small>
        </template>
      </Column>

      <Column :header="pt('Flags')">
        <template #body="{ data }">
          <Tag v-if="data.is_authorised_vendor" :value="pt('Appointed')" severity="success" />
          <Tag v-if="data.requires_po" :value="pt('PO required')" severity="warn" />
          <Tag v-if="!data.is_active" :value="pt('Inactive')" severity="secondary" />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 11rem">
        <template #body="{ data }">
          <Button icon="pi pi-calendar" text rounded :title="pt('Season')" @click="openSeason(data)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text rounded :title="pt('Edit')" @click="openEdit(data)" />
          <Button
            v-if="canDelete && !data.kits_count"
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
          {{ pt('No institutions yet. A booklist has to belong to somebody — start with the school that publishes it.') }}
        </div>
      </template>
    </DataTable>

    <!-- Season -->
    <Dialog v-model:visible="seasonVisible" :header="pt('Season')" modal :style="{ width: '860px' }">
      <div v-if="seasonLoading" class="muted">{{ pt('Loading…') }}</div>
      <div v-else-if="season">
        <div class="season-head">
          <span class="strong">{{ season.institution.name }}</span>
          <span class="muted">{{ season.institution.code }} · {{ season.institution.academic_year }}</span>
        </div>

        <h4>{{ pt('Booklists') }}</h4>
        <DataTable :value="season.kits" data-key="id" size="small" striped-rows>
          <Column field="class_label" :header="pt('Class')">
            <template #body="{ data }">
              {{ data.class_label }}<span v-if="data.stream" class="muted"> · {{ data.stream }}</span>
            </template>
          </Column>
          <Column field="kit_number" :header="pt('List')" />
          <Column field="items_count" :header="pt('Items')" />
          <Column field="expected_students" :header="pt('Expected')" />
          <Column field="status" :header="pt('Status')">
            <template #body="{ data }">
              <Tag
                :value="pt(data.status)"
                :severity="data.status === 'published' ? 'success' : data.status === 'draft' ? 'warn' : 'secondary'"
              />
            </template>
          </Column>
          <template #empty>
            <div class="muted pad">{{ pt('No lists for this session yet.') }}</div>
          </template>
        </DataTable>

        <h4>{{ pt('Supply orders') }}</h4>
        <DataTable :value="season.orders" data-key="id" size="small" striped-rows>
          <Column field="so_number" :header="pt('Order')" />
          <Column field="order_date" :header="pt('Date')" />
          <Column field="po_number" :header="pt('Their PO')">
            <template #body="{ data }">
              <span v-if="data.po_number" class="mono">{{ data.po_number }}</span>
              <Tag v-else :value="pt('Missing')" severity="warn" />
            </template>
          </Column>
          <Column field="total" :header="pt('Value')" />
          <Column field="invoice_status" :header="pt('Invoice')">
            <template #body="{ data }">
              <span v-if="data.invoice_number">{{ data.invoice_number }} · {{ pt(data.invoice_status) }}</span>
              <small v-else class="muted">{{ pt('Not billed') }}</small>
            </template>
          </Column>
          <template #empty>
            <div class="muted pad">{{ pt('Nothing supplied to this institution yet.') }}</div>
          </template>
        </DataTable>
      </div>
    </Dialog>

    <!-- Editor -->
    <Dialog
      v-model:visible="dialogVisible"
      :header="editingId ? pt('Edit Institution') : pt('Add Institution')"
      modal
      :style="{ width: '820px' }"
    >
      <div class="grid">
        <div class="fld span-2">
          <label>{{ pt('Institution name') }} *</label>
          <InputText v-model="form.name" class="w-full" :placeholder="pt('Sunrise English School')" />
          <small class="hint">{{ pt('What is printed on the booklist — not the trust that pays the bill.') }}</small>
          <small v-if="errors.name" class="err">{{ errors.name[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Type') }}</label>
          <Select v-model="form.type" :options="TYPES" option-label="label" option-value="value" class="w-full" />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Bills to (customer)') }}</label>
          <Select
            v-model="form.customer_id"
            :options="customers"
            option-label="label"
            option-value="value"
            filter
            show-clear
            class="w-full"
          />
          <small class="hint">
            {{ pt('Leave empty if the school buys nothing itself and every rupee comes from parents.') }}
          </small>
        </div>
        <div class="fld">
          <label>{{ pt('Board') }}</label>
          <InputText v-model="form.board" class="w-full" :placeholder="pt('CBSE / GSEB / ICSE')" />
        </div>

        <div class="fld">
          <label>{{ pt('Session') }} *</label>
          <InputText v-model="form.academic_year" class="w-full" placeholder="2026-27" />
          <small v-if="errors.academic_year" class="err">{{ errors.academic_year[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Session opens') }}</label>
          <DatePicker v-model="form.session_starts_on" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Students') }}</label>
          <InputNumber v-model="form.student_count" :min="0" class="w-full" />
          <small class="hint">{{ pt('What turns a booklist into a purchase quantity.') }}</small>
        </div>

        <div class="fld">
          <label>{{ pt('Medium') }}</label>
          <InputText v-model="form.medium" class="w-full" :placeholder="pt('English / Gujarati')" />
        </div>
        <div class="fld">
          <label>{{ pt('Contact person') }}</label>
          <InputText v-model="form.contact_person" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.contact_phone" class="w-full" />
        </div>
        <div class="fld">
          <label>{{ pt('Email') }}</label>
          <InputText v-model="form.contact_email" class="w-full" />
          <small v-if="errors.contact_email" class="err">{{ errors.contact_email[0] }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Address') }}</label>
          <Textarea v-model="form.address" rows="2" auto-resize class="w-full" />
        </div>

        <!-- The two rates. Together, and labelled by who pays them. -->
        <div class="fld">
          <label>{{ pt('Parent concession %') }}</label>
          <InputNumber v-model="form.parent_discount_percent" :min="0" :max="100" :max-fraction-digits="2" class="w-full" />
          <small class="hint">{{ pt('What every parent gets off this school’s list.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('School rate %') }}</label>
          <InputNumber v-model="form.school_discount_percent" :min="0" :max="100" :max-fraction-digits="2" class="w-full" />
          <small class="hint">{{ pt('What the institution itself buys at.') }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Credit days') }}</label>
          <InputNumber v-model="form.credit_days" :min="0" :max="365" class="w-full" />
        </div>

        <div class="fld span-3 checks">
          <div class="chk">
            <Checkbox v-model="form.is_authorised_vendor" binary input-id="auth" />
            <label for="auth">{{ pt('We are the appointed supplier for this session') }}</label>
          </div>
          <div class="chk">
            <Checkbox v-model="form.requires_po" binary input-id="po" />
            <label for="po">{{ pt('Will not pay without their purchase-order number') }}</label>
          </div>
          <div class="chk">
            <Checkbox v-model="form.is_active" binary input-id="active" />
            <label for="active">{{ pt('Active') }}</label>
          </div>
        </div>

        <Message v-if="form.requires_po" severity="info" :closable="false" class="span-3">
          {{ pt('Orders for this school will be refused until their PO number is entered.') }}
        </Message>

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

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.checks { display: flex; gap: 1.5rem; flex-wrap: wrap; }
.chk { display: flex; gap: 0.4rem; align-items: center; }
.chk label { font-size: 0.8rem; }
.disc { display: flex; flex-direction: column; font-size: 0.8rem; }
.season-head { display: flex; gap: 0.6rem; align-items: baseline; margin-bottom: 0.8rem; }
h4 { margin: 1rem 0 0.5rem; font-size: 0.85rem; }
.pad { padding: 0.8rem; }
.muted { color: #6b7280; }
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
