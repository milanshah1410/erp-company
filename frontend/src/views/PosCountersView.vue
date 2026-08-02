<script setup lang="ts">
/**
 * Tills, and the shift cash-up behind each.
 *
 * Two screens off one component, chosen by the route's `mode` meta — the lanes
 * and the shift history are the same objects seen from two ends, and splitting
 * them would have duplicated the open/close dialogs.
 *
 * A POS feature, not a supermarket one: a jeweller with a separate silver counter
 * and a two-till kirana reconcile per desk exactly as a hypermarket does. What is
 * worth understanding about the numbers on this screen is where they come from:
 *
 *  - **Expected cash** is the opening float plus every *cash* payment stamped
 *    with the shift. Card takings never enter the drawer, so counting them would
 *    make every shift look short by its card total.
 *  - **A refund handed over today** comes out of today's drawer even when the
 *    bill is months old, because the stamp is on the payment rather than on the
 *    sale.
 *  - **Variance is recorded, never refused.** A till that will not close until it
 *    balances is a till that gets closed by making the count agree.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'

const pt = usePt()
const toast = useToast()
const route = useRoute()
const auth = useAuthStore()

const mode = computed<'counters' | 'sessions'>(() => (route.meta.mode as any) || 'counters')

const counters = ref<any[]>([])
const sessions = ref<any[]>([])
const warehouses = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)

const statusFilter = ref<string | null>(null)

const canCreate = computed(() => auth.can('pos-counters.create'))
const canEdit = computed(() => auth.can('pos-counters.edit'))
const canDelete = computed(() => auth.can('pos-counters.delete'))

const MODES = computed(() => [
  { label: pt('Attended'), value: 'attended' },
  { label: pt('Self-checkout'), value: 'self_checkout' },
])

const STATUSES = computed(() => [
  { label: pt('Open'), value: 'open' },
  { label: pt('Closed'), value: 'closed' },
])

async function loadCounters() {
  const { data } = await http.get('/pos-counters')
  counters.value = data.data
}

async function loadSessions() {
  const { data } = await http.get('/pos-counters/sessions', {
    params: { status: statusFilter.value || undefined, per_page: 50 },
  })
  sessions.value = data.data.data ?? data.data
}

async function load() {
  loading.value = true
  try {
    await (mode.value === 'counters' ? loadCounters() : Promise.all([loadCounters(), loadSessions()]))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  const { data } = await http.get('/warehouses')
  warehouses.value = data.data ?? data
  await load()
})

watch(mode, load)

// --- The counter master ------------------------------------------------------

const counterVisible = ref(false)
const counterForm = ref<any>({})

function openCounter(row: any = null) {
  counterForm.value = row
    ? { id: row.id, warehouse_id: row.warehouse_id, code: row.code, name: row.name, mode: row.mode, location: row.location, default_float: row.default_float, is_active: row.is_active }
    : { id: null, warehouse_id: warehouses.value[0]?.id ?? null, code: '', name: '', mode: 'attended', location: '', default_float: 0, is_active: true }
  counterVisible.value = true
}

async function saveCounter() {
  saving.value = true
  try {
    const body = { ...counterForm.value }
    delete body.id
    if (counterForm.value.id) await http.put(`/pos-counters/${counterForm.value.id}`, body)
    else await http.post('/pos-counters', body)
    counterVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Counter saved'), life: 2500 })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not save the counter'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

async function deleteCounter(row: any) {
  try {
    await http.delete(`/pos-counters/${row.id}`)
    await load()
    toast.add({ severity: 'success', summary: pt('Counter deleted'), life: 2000 })
  } catch (e: any) {
    toast.add({ severity: 'warn', summary: pt('Cannot delete'), detail: e.response?.data?.message, life: 5000 })
  }
}

// --- Opening the drawer ------------------------------------------------------

const openVisible = ref(false)
const openRow = ref<any>(null)
const openFloat = ref<number | null>(null)
const openNotes = ref('')

function startOpen(row: any) {
  openRow.value = row
  openFloat.value = row.default_float
  openNotes.value = ''
  openVisible.value = true
}

async function confirmOpen() {
  saving.value = true
  try {
    await http.post(`/pos-counters/${openRow.value.id}/open`, {
      opening_float: openFloat.value,
      notes: openNotes.value || undefined,
    })
    openVisible.value = false
    await load()
    toast.add({ severity: 'success', summary: pt('Till opened'), life: 2500 })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not open the till'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

// --- Cashing up --------------------------------------------------------------

const closeVisible = ref(false)
const closeReport = ref<any>(null)
const declaredCash = ref<number | null>(null)
const closeNotes = ref('')

/**
 * The X report is fetched *before* the count is keyed, so the cashier sees what
 * the system expects and then counts. Deliberately in that order — showing the
 * expected figure after the declaration is how a drawer gets "counted" to match.
 */
async function startClose(sessionId: number) {
  const { data } = await http.get(`/pos-counters/sessions/${sessionId}/report`)
  closeReport.value = data.data
  declaredCash.value = null
  closeNotes.value = ''
  closeVisible.value = true
}

async function confirmClose() {
  saving.value = true
  try {
    const { data } = await http.post(`/pos-counters/sessions/${closeReport.value.session_id}/close`, {
      declared_cash: declaredCash.value,
      notes: closeNotes.value || undefined,
    })
    closeReport.value = data.data
    await load()
    toast.add({
      severity: data.data.variance === 0 ? 'success' : 'warn',
      summary: pt('Till closed'),
      detail:
        data.data.variance === 0
          ? pt('The drawer balanced.')
          : `${pt('Variance')}: ${data.data.variance}`,
      life: 5000,
    })
  } catch (e: any) {
    const errors = e.response?.data?.errors
    toast.add({
      severity: 'error',
      summary: pt('Could not close the till'),
      detail: errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message,
      life: 5000,
    })
  } finally {
    saving.value = false
  }
}

const reportVisible = ref(false)
const report = ref<any>(null)

async function viewReport(sessionId: number) {
  const { data } = await http.get(`/pos-counters/sessions/${sessionId}/report`)
  report.value = data.data
  reportVisible.value = true
}

function tenderRows(tenders: Record<string, number> | undefined) {
  return Object.entries(tenders || {}).map(([mode, total]) => ({ mode, total }))
}

function varianceSeverity(v: number | null) {
  if (v === null) return 'secondary'
  if (v === 0) return 'success'
  return v < 0 ? 'danger' : 'warn'
}

const openCount = computed(() => counters.value.filter((c) => c.is_open).length)
const laneCount = computed(() => counters.value.filter((c) => c.mode === 'self_checkout').length)
</script>

<template>
  <div>
    <PageHeader
      :title="mode === 'counters' ? pt('Tills') : pt('Shift Cash-Up')"
      :subtitle="
        mode === 'counters'
          ? pt('The counters the shop bills from, and which drawers are open right now')
          : pt('Every shift, what the drawer should have held and what was counted')
      "
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Counters') }}</span>
        <span class="t-value">{{ counters.length }}</span>
      </div>
      <div class="tile" :class="{ accent: openCount > 0 }">
        <span class="t-label">{{ pt('Open now') }}</span>
        <span class="t-value">{{ openCount }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Self-checkout lanes') }}</span>
        <span class="t-value">{{ laneCount }}</span>
      </div>
    </div>

    <div class="toolbar">
      <Button icon="pi pi-refresh" text :label="pt('Refresh')" :loading="loading" @click="load" />
      <Button v-if="mode === 'counters' && canCreate" icon="pi pi-plus" :label="pt('New Counter')" @click="openCounter()" />
      <Select
        v-if="mode === 'sessions'"
        v-model="statusFilter"
        :options="STATUSES"
        option-label="label"
        option-value="value"
        :placeholder="pt('Any status')"
        show-clear
        @change="loadSessions"
      />
      <TableExportButtons
        v-if="mode === 'sessions'"
        title="Shift Cash-Up"
        filenameBase="shift-cash-up"
        :rows="sessions"
        :columns="[
          { key: 'opened_at', label: 'Opened' },
          { key: 'closed_at', label: 'Closed' },
          { key: 'opening_float', label: 'Float' },
          { key: 'expected_cash', label: 'Expected' },
          { key: 'declared_cash', label: 'Counted' },
          { key: 'variance', label: 'Variance' },
        ]"
      />
    </div>

    <!-- ================= Counters ================= -->
    <DataTable v-if="mode === 'counters'" :value="counters" :loading="loading" striped-rows responsive-layout="scroll">
      <Column field="code" :header="pt('Code')" sortable />
      <Column field="name" :header="pt('Counter')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.name }}</strong>
            <small v-if="data.location" class="muted">{{ data.location }}</small>
          </div>
        </template>
      </Column>
      <Column field="mode" :header="pt('Type')" sortable>
        <template #body="{ data }">
          <Tag
            :severity="data.mode === 'self_checkout' ? 'info' : 'secondary'"
            :value="data.mode === 'self_checkout' ? pt('Self-checkout') : pt('Attended')"
          />
        </template>
      </Column>
      <Column field="warehouse_name" :header="pt('Store')" sortable />
      <Column field="is_open" :header="pt('Drawer')" sortable>
        <template #body="{ data }">
          <div v-if="data.is_open" class="stack">
            <Tag severity="success" :value="pt('Open')" />
            <small class="muted">{{ data.opened_by }} · {{ data.opened_at }}</small>
          </div>
          <Tag v-else severity="secondary" :value="pt('Closed')" />
        </template>
      </Column>
      <Column field="default_float" :header="pt('Float')" sortable />
      <Column :header="pt('Actions')" style="min-width: 15rem">
        <template #body="{ data }">
          <Button
            v-if="!data.is_open && canCreate && data.is_active"
            icon="pi pi-lock-open"
            text
            :label="pt('Open Till')"
            @click="startOpen(data)"
          />
          <Button
            v-if="data.is_open && canEdit"
            icon="pi pi-wallet"
            text
            severity="warn"
            :label="pt('Cash Up')"
            @click="startClose(data.session_id)"
          />
          <Button v-if="data.is_open" icon="pi pi-file" text @click="viewReport(data.session_id)" />
          <Button v-if="canEdit" icon="pi pi-pencil" text @click="openCounter(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text severity="danger" @click="deleteCounter(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- ================= Shifts ================= -->
    <DataTable v-else :value="sessions" :loading="loading" striped-rows responsive-layout="scroll">
      <Column :header="pt('Counter')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <strong>{{ data.counter?.name }}</strong>
            <small class="muted">{{ data.counter?.code }}</small>
          </div>
        </template>
      </Column>
      <Column field="status" :header="pt('Status')" sortable>
        <template #body="{ data }">
          <Tag :severity="data.status === 'open' ? 'success' : 'secondary'" :value="data.status === 'open' ? pt('Open') : pt('Closed')" />
        </template>
      </Column>
      <Column field="opened_at" :header="pt('Opened')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.opened_at }}</span>
            <small class="muted">{{ data.opened_by?.name }}</small>
          </div>
        </template>
      </Column>
      <Column field="closed_at" :header="pt('Closed')" sortable>
        <template #body="{ data }">
          <div class="stack">
            <span>{{ data.closed_at || '—' }}</span>
            <small class="muted">{{ data.closed_by?.name }}</small>
          </div>
        </template>
      </Column>
      <Column field="opening_float" :header="pt('Float')" sortable />
      <Column field="expected_cash" :header="pt('Expected')" sortable />
      <Column field="declared_cash" :header="pt('Counted')" sortable />
      <Column field="variance" :header="pt('Variance')" sortable>
        <template #body="{ data }">
          <Tag
            v-if="data.variance !== null"
            :severity="varianceSeverity(Number(data.variance))"
            :value="String(data.variance)"
          />
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column :header="pt('Report')" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-file" text :label="data.status === 'open' ? pt('X Report') : pt('Z Report')" @click="viewReport(data.id)" />
          <Button v-if="data.status === 'open' && canEdit" icon="pi pi-wallet" text severity="warn" @click="startClose(data.id)" />
        </template>
      </Column>
    </DataTable>

    <!-- ================= Dialogs ================= -->
    <Dialog v-model:visible="counterVisible" modal :header="pt('Counter')" :style="{ width: '30rem' }">
      <div class="form">
        <div class="field-row">
          <div class="field">
            <label>{{ pt('Code') }}</label>
            <InputText v-model="counterForm.code" />
          </div>
          <div class="field">
            <label>{{ pt('Name') }}</label>
            <InputText v-model="counterForm.name" />
          </div>
        </div>
        <div class="field">
          <label>{{ pt('Store') }}</label>
          <Select v-model="counterForm.warehouse_id" :options="warehouses" option-label="name" option-value="id" />
        </div>
        <div class="field">
          <label>{{ pt('Type') }}</label>
          <Select v-model="counterForm.mode" :options="MODES" option-label="label" option-value="value" />
          <small class="hint">
            {{ pt('A self-checkout lane is worked by the customer and needs a supervisor to clear restricted items.') }}
          </small>
        </div>
        <div class="field">
          <label>{{ pt('Where it is') }}</label>
          <InputText v-model="counterForm.location" :placeholder="pt('Front row, near exit')" />
        </div>
        <div class="field">
          <label>{{ pt('Default opening float') }}</label>
          <InputNumber v-model="counterForm.default_float" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt('A suggestion only — the cashier keys what actually went in the drawer.') }}</small>
        </div>
        <div class="field-inline">
          <Checkbox v-model="counterForm.is_active" binary input-id="c-active" />
          <label for="c-active">{{ pt('In service') }}</label>
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="counterVisible = false" />
        <Button :label="pt('Save')" :loading="saving" :disabled="!counterForm.code || !counterForm.name" @click="saveCounter" />
      </template>
    </Dialog>

    <Dialog v-model:visible="openVisible" modal :header="pt('Open the Till')" :style="{ width: '26rem' }">
      <div v-if="openRow" class="form">
        <p class="hint">{{ openRow.name }} — {{ openRow.warehouse_name }}</p>
        <div class="field">
          <label>{{ pt('Cash in the drawer') }}</label>
          <InputNumber v-model="openFloat" :min="0" :max-fraction-digits="2" />
          <small class="hint">{{ pt('Whatever is actually in it. This is what the shift reconciles against.') }}</small>
        </div>
        <div class="field">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="openNotes" rows="2" auto-resize />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="openVisible = false" />
        <Button :label="pt('Open')" :loading="saving" @click="confirmOpen" />
      </template>
    </Dialog>

    <Dialog v-model:visible="closeVisible" modal :header="pt('Cash Up')" :style="{ width: '30rem' }">
      <div v-if="closeReport" class="form">
        <div class="summary-grid">
          <div><span class="muted">{{ pt('Counter') }}</span><strong>{{ closeReport.counter?.name }}</strong></div>
          <div><span class="muted">{{ pt('Bills') }}</span><strong>{{ closeReport.bills }}</strong></div>
          <div><span class="muted">{{ pt('Sales') }}</span><strong>{{ closeReport.sales_total }}</strong></div>
          <div><span class="muted">{{ pt('Average bill') }}</span><strong>{{ closeReport.average_bill }}</strong></div>
          <div><span class="muted">{{ pt('Opening float') }}</span><strong>{{ closeReport.opening_float }}</strong></div>
          <div><span class="muted">{{ pt('Net cash taken') }}</span><strong>{{ closeReport.cash_taken }}</strong></div>
          <div class="wide">
            <span class="muted">{{ pt('Expected in the drawer') }}</span>
            <strong class="big">{{ closeReport.expected_cash }}</strong>
          </div>
        </div>

        <template v-if="closeReport.variance === null">
          <div class="field">
            <label>{{ pt('Counted cash') }}</label>
            <InputNumber v-model="declaredCash" :min="0" :max-fraction-digits="2" />
            <small class="hint">
              {{ pt('Count the drawer and key the figure. A difference is recorded, not refused — that is the whole point of the report.') }}
            </small>
          </div>
          <div class="field">
            <label>{{ pt('Notes') }}</label>
            <Textarea v-model="closeNotes" rows="2" auto-resize :placeholder="pt('Anything that explains a difference')" />
          </div>
        </template>

        <div v-else class="closed-result">
          <span class="muted">{{ pt('Counted') }}: {{ closeReport.declared_cash }}</span>
          <Tag :severity="varianceSeverity(Number(closeReport.variance))" :value="`${pt('Variance')}: ${closeReport.variance}`" />
        </div>
      </div>
      <template #footer>
        <Button :label="closeReport?.variance === null ? pt('Cancel') : pt('Close')" text @click="closeVisible = false" />
        <Button
          v-if="closeReport?.variance === null"
          :label="pt('Close the Till')"
          :loading="saving"
          :disabled="declaredCash === null"
          @click="confirmClose"
        />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="reportVisible"
      modal
      :header="report?.type === 'z' ? pt('Z Report') : pt('X Report')"
      :style="{ width: '28rem' }"
    >
      <div v-if="report" class="form">
        <p class="hint">
          {{ report.counter?.name }} · {{ report.opened_at }}
          <template v-if="report.closed_at"> → {{ report.closed_at }}</template>
          <br />
          <template v-if="report.type === 'x'">
            {{ pt('Taken mid-shift. Nothing is settled and the drawer stays open.') }}
          </template>
          <template v-else>{{ pt('Signed off at close. These figures no longer move.') }}</template>
        </p>

        <div class="summary-grid">
          <div><span class="muted">{{ pt('Bills') }}</span><strong>{{ report.bills }}</strong></div>
          <div><span class="muted">{{ pt('Sales') }}</span><strong>{{ report.sales_total }}</strong></div>
          <div><span class="muted">{{ pt('Average bill') }}</span><strong>{{ report.average_bill }}</strong></div>
          <div><span class="muted">{{ pt('Opening float') }}</span><strong>{{ report.opening_float }}</strong></div>
        </div>

        <h4 class="section">{{ pt('By tender') }}</h4>
        <DataTable :value="tenderRows(report.tenders)" size="small">
          <Column field="mode" :header="pt('Tender')" />
          <Column field="total" :header="pt('Total')" />
        </DataTable>
        <small class="hint">{{ pt('Only cash is counted by hand. The rest is here so the card machine can be checked against it.') }}</small>

        <div class="summary-grid">
          <div><span class="muted">{{ pt('Expected cash') }}</span><strong>{{ report.expected_cash }}</strong></div>
          <div v-if="report.declared_cash !== null"><span class="muted">{{ pt('Counted') }}</span><strong>{{ report.declared_cash }}</strong></div>
          <div v-if="report.variance !== null" class="wide">
            <span class="muted">{{ pt('Variance') }}</span>
            <Tag :severity="varianceSeverity(Number(report.variance))" :value="String(report.variance)" />
          </div>
        </div>
        <p v-if="report.notes" class="hint">{{ report.notes }}</p>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="reportVisible = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.tile.accent { border-color: #16a34a; background: rgba(22, 163, 74, 0.07); }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.stack { display: flex; flex-direction: column; }
.muted { color: #6b7280; font-size: 0.75rem; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; flex: 1; }
.field label { font-size: 0.8rem; color: #6b7280; }
.field-row { display: flex; gap: 0.6rem; }
.field-inline { display: flex; align-items: center; gap: 0.5rem; }
.hint { font-size: 0.72rem; color: #9ca3af; line-height: 1.5; }
.section { margin: 0.4rem 0 0.2rem; font-size: 0.85rem; }
.summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 0.9rem; }
.summary-grid > div { display: flex; flex-direction: column; }
.summary-grid .wide { grid-column: 1 / -1; }
.summary-grid .big { font-size: 1.4rem; }
.closed-result { display: flex; align-items: center; gap: 0.75rem; }
</style>
