<script setup lang="ts">
/**
 * The school-supply board — institutional orders and the tenders behind them.
 *
 * There is no school-order *document* here, and there should not be: a school
 * buying five hundred diaries is a sale, so it is an ordinary sales order picked,
 * delivered and invoiced by screens that already work. What did not exist is this
 * view of them.
 *
 * The missing-PO tile leads for a reason. An order raised for a school that will
 * not settle without quoting its own purchase-order number is a bill that cannot
 * be paid, and the only cheap moment to fix it is before the van leaves. Every
 * other system in this trade discovers it in September.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canEdit = computed(() => auth.can('sales.edit'))

const tab = ref('orders')
const rows = ref<any[]>([])
const quotes = ref<any[]>([])
const summary = ref({ orders: 0, open: 0, value: 0, missing_po: 0 })
const loading = ref(false)
const institutions = ref<any[]>([])

const search = ref('')
const statusFilter = ref<string | null>(null)
const institutionFilter = ref<number | null>(null)
const missingPoOnly = ref(false)

const STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stationery/school-orders', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        institution_id: institutionFilter.value || undefined,
        missing_po: missingPoOnly.value ? 1 : undefined,
        per_page: 100,
      },
    })
    rows.value = data.data
    summary.value = data.summary
  } finally {
    loading.value = false
  }
}

async function loadQuotes() {
  const { data } = await http.get('/stationery/school-quotations', {
    params: { institution_id: institutionFilter.value || undefined, per_page: 100 },
  })
  quotes.value = data.data
}

async function loadPickers() {
  const { data } = await http.get('/stationery/institutions', { params: { per_page: 500, is_active: 1 } })
  institutions.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

// ---- Recording the school's PO --------------------------------------------

const poVisible = ref(false)
const poSaving = ref(false)
const poRow = ref<any>(null)
const poForm = ref({ po_number: '', po_date: null as Date | null })

function openPo(row: any) {
  poRow.value = row
  poForm.value = { po_number: row.po_number ?? '', po_date: row.po_date ? new Date(row.po_date) : null }
  poVisible.value = true
}

function asDate(d: Date | null) {
  if (!d) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

async function savePo() {
  poSaving.value = true
  try {
    await http.post(`/stationery/school-orders/${poRow.value.id}/po`, {
      po_number: poForm.value.po_number,
      po_date: asDate(poForm.value.po_date),
    })
    poVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Purchase order recorded'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  } finally {
    poSaving.value = false
  }
}

onMounted(() => {
  load()
  loadQuotes()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('School Orders')"
      :subtitle="pt('Institutional supply orders and the tenders behind them — what is out, what is unpaid, and whose paperwork is missing')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Orders') }}</span>
        <span class="t-value">{{ summary.orders }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Still open') }}</span>
        <span class="t-value">{{ summary.open }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Order value') }}</span>
        <span class="t-value">{{ summary.value }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('PO missing') }}</span>
        <span class="t-value warn">{{ summary.missing_po }}</span>
      </div>
    </div>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="orders">{{ pt('Supply Orders') }}</Tab>
        <Tab value="quotes">{{ pt('Tenders Out') }}</Tab>
      </TabList>

      <TabPanels>
        <TabPanel value="orders">
          <div class="toolbar">
            <InputText v-model="search" :placeholder="pt('Order number, PO or school')" @keyup.enter="load" />
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
            <Button
              :label="pt('PO missing')"
              :outlined="!missingPoOnly"
              icon="pi pi-exclamation-triangle"
              severity="warn"
              @click="
                () => {
                  missingPoOnly = !missingPoOnly
                  load()
                }
              "
            />
            <Button icon="pi pi-search" :label="pt('Search')" @click="load" />
            <TableExportButtons
              title="School Orders"
              filenameBase="stationery-school-orders"
              :rows="rows"
              :columns="[
                { key: 'so_number', label: 'Order' },
                { key: 'order_date', label: 'Date' },
                { key: 'institution', label: 'Institution' },
                { key: 'kit', label: 'List' },
                { key: 'po_number', label: 'Their PO' },
                { key: 'total', label: 'Value' },
                { key: 'status', label: 'Status' },
                { key: 'invoice_number', label: 'Invoice' },
                { key: 'balance_due', label: 'Due' },
              ]"
            />
          </div>

          <DataTable :value="rows" :loading="loading" data-key="id" paginator :rows="25" striped-rows responsive-layout="scroll">
            <Column field="so_number" :header="pt('Order')" sortable>
              <template #body="{ data }">
                <div class="strong">{{ data.so_number }}</div>
                <small class="muted">{{ data.order_date }}</small>
              </template>
            </Column>

            <Column field="institution" :header="pt('Institution')">
              <template #body="{ data }">
                <div>{{ data.institution || data.customer || '—' }}</div>
                <small v-if="data.kit" class="muted">{{ data.kit }} · {{ data.academic_year }}</small>
              </template>
            </Column>

            <Column field="po_number" :header="pt('Their PO')">
              <template #body="{ data }">
                <span v-if="data.po_number" class="mono">{{ data.po_number }}</span>
                <Tag v-else-if="data.po_missing" :value="pt('Missing')" severity="warn" />
                <small v-else class="muted">—</small>
              </template>
            </Column>

            <Column field="total" :header="pt('Value')" sortable />

            <Column field="status" :header="pt('Status')" sortable>
              <template #body="{ data }">
                <Tag
                  :value="pt(data.status)"
                  :severity="data.status === 'delivered' ? 'success' : data.status === 'cancelled' ? 'danger' : 'info'"
                />
              </template>
            </Column>

            <Column field="invoice_number" :header="pt('Invoice')">
              <template #body="{ data }">
                <div v-if="data.invoice_number">
                  {{ data.invoice_number }}
                  <small class="muted block">
                    {{ pt(data.invoice_status) }}
                    <template v-if="data.balance_due"> · {{ pt('due') }} {{ data.balance_due }}</template>
                  </small>
                </div>
                <small v-else class="muted">{{ pt('Not billed') }}</small>
              </template>
            </Column>

            <Column :header="pt('Actions')" style="width: 9rem">
              <template #body="{ data }">
                <Button
                  v-if="canEdit"
                  icon="pi pi-file-edit"
                  text
                  rounded
                  :severity="data.po_missing ? 'warn' : undefined"
                  :title="pt('Record their PO')"
                  @click="openPo(data)"
                />
                <router-link :to="`/sales-orders?id=${data.id}`">
                  <Button icon="pi pi-external-link" text rounded :title="pt('Open the sales order')" />
                </router-link>
              </template>
            </Column>

            <template #empty>
              <div class="empty-state">
                {{ pt('No school orders yet. Raise one from a published booklist.') }}
              </div>
            </template>
          </DataTable>
        </TabPanel>

        <TabPanel value="quotes">
          <DataTable :value="quotes" data-key="id" paginator :rows="25" striped-rows responsive-layout="scroll">
            <Column field="quote_number" :header="pt('Quotation')" sortable>
              <template #body="{ data }">
                <div class="strong">{{ data.quote_number }}</div>
                <small class="muted">{{ data.quote_date }}</small>
              </template>
            </Column>
            <Column field="institution" :header="pt('Institution')">
              <template #body="{ data }">
                <div>{{ data.institution || data.customer || '—' }}</div>
                <small v-if="data.kit" class="muted">{{ data.kit }} · {{ data.academic_year }}</small>
              </template>
            </Column>
            <Column field="valid_until" :header="pt('Valid until')" />
            <Column field="total" :header="pt('Value')" sortable />
            <Column field="status" :header="pt('Status')" sortable>
              <template #body="{ data }">
                <Tag
                  :value="pt(data.status)"
                  :severity="data.status === 'accepted' ? 'success' : data.status === 'sent' ? 'info' : 'secondary'"
                />
              </template>
            </Column>
            <Column :header="pt('Actions')" style="width: 7rem">
              <template #body="{ data }">
                <router-link :to="`/sales-quotations?id=${data.id}`">
                  <Button icon="pi pi-external-link" text rounded :title="pt('Open the quotation')" />
                </router-link>
              </template>
            </Column>
            <template #empty>
              <div class="empty-state">
                {{ pt('No live tenders. A school order usually starts here, weeks before anyone orders anything.') }}
              </div>
            </template>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <!-- Their PO -->
    <Dialog v-model:visible="poVisible" :header="pt('Record their purchase order')" modal :style="{ width: '480px' }">
      <p class="muted small">
        {{ pt('The school’s accounts office issues this days after the order. Until it is here, the bill cannot be settled.') }}
      </p>
      <div class="fld">
        <label>{{ pt('PO number') }} *</label>
        <InputText v-model="poForm.po_number" class="w-full mono" />
      </div>
      <div class="fld">
        <label>{{ pt('PO date') }}</label>
        <DatePicker v-model="poForm.po_date" date-format="dd/mm/yy" class="w-full" />
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="poVisible = false" />
        <Button :label="pt('Save')" :loading="poSaving" :disabled="!poForm.po_number" @click="savePo" />
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

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.7rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.muted { color: #6b7280; }
.small { font-size: 0.78rem; }
.block { display: block; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
