<script setup lang="ts">
// Import & Export Logistics dashboard. One panel per section the module spec
// calls for, in that order: Active Import Shipments, Active Export Shipments,
// ETA / ETD, Container Status, Shipment Profit, Pending Documents, Pending
// Customs Clearance, Logistics Expenses.
//
// Every panel is a summary of the board that works that queue and links straight
// to it, so the dashboard answers "what needs me today" and hands off from there.
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import http from '../api/http'
import PageHeader from '../components/PageHeader.vue'
import { humanize, shipmentStatusSeverity, containerStatusSeverity, customsStatusSeverity } from '../composables/useTradeCatalogue'

const router = useRouter()
const loading = ref(false)
const data = ref<any>(null)

async function load() {
  loading.value = true
  try {
    const res = await http.get('/import-export/dashboard')
    data.value = res.data
  } finally {
    loading.value = false
  }
}

function money(value: any): string {
  return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
}

// Widest bar sets the scale, so the cost mix reads as a proportion.
const expenseMax = computed(() => Math.max(1, ...(data.value?.expenses?.by_type ?? []).map((e: any) => Number(e.total))))
const containersWithBoxes = computed(() => (data.value?.containers?.by_status ?? []).filter((s: any) => s.total > 0))

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Import &amp; Export Logistics"
      subtitle="Shipments in transit, the queues that need clearing today, and what the freight is costing.">
      <template #actions>
        <Button label="Refresh" icon="pi pi-refresh" outlined :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div v-if="!data && loading" class="muted">Loading…</div>

    <template v-else-if="data">
      <!-- 1 & 2 · Active Import / Export Shipments -->
      <div class="two-col">
        <section v-for="leg in [
          { key: 'import', label: 'Active Import Shipments', icon: 'pi pi-arrow-down-left', to: '/import-export/import-shipments', moneyLabel: 'Payable to suppliers' },
          { key: 'export', label: 'Active Export Shipments', icon: 'pi pi-arrow-up-right', to: '/import-export/export-shipments', moneyLabel: 'Receivable from buyers' },
        ]" :key="leg.key" class="card leg" :class="leg.key">
          <h3><i :class="leg.icon" /> {{ leg.label }}</h3>
          <div class="leg-body">
            <span class="big">{{ data[leg.key].active }}</span>
            <dl class="mini-dl">
              <div><dt>Value in flight</dt><dd>{{ money(data[leg.key].value) }}</dd></div>
              <div><dt>{{ leg.moneyLabel }}</dt><dd :class="{ bad: data[leg.key].outstanding > 0 }">{{ money(data[leg.key].outstanding) }}</dd></div>
              <div><dt>Running late</dt><dd :class="{ bad: data[leg.key].delayed > 0 }">{{ data[leg.key].delayed }}</dd></div>
              <div><dt>Unsettled</dt><dd>{{ data[leg.key].unpaid }}</dd></div>
            </dl>
          </div>
          <div class="chips">
            <Tag v-for="s in data[leg.key].by_status.filter((x: any) => x.total > 0)" :key="s.status"
              :severity="shipmentStatusSeverity[s.status]" :value="`${humanize(s.status)} · ${s.total}`" class="mini" />
          </div>
          <div class="card-foot">
            <Button :label="`Open ${leg.key} shipments`" icon="pi pi-arrow-right" iconPos="right" size="small" text
              @click="router.push(leg.to)" />
          </div>
        </section>
      </div>

      <!-- 3 · ETA / ETD -->
      <section class="card">
        <h3><i class="pi pi-calendar-clock" /> ETA / ETD</h3>
        <div class="kpi-row">
          <div class="kpi"><span class="kpi-value" :class="{ bad: data.eta_etd.delayed > 0 }">{{ data.eta_etd.delayed }}</span><span class="kpi-label">Past ETA</span></div>
          <div class="kpi"><span class="kpi-value">{{ data.eta_etd.arriving_count }}</span><span class="kpi-label">Arriving in {{ data.eta_etd.horizon_days }}d</span></div>
          <div class="kpi"><span class="kpi-value">{{ data.eta_etd.departing_soon }}</span><span class="kpi-label">Yet to sail</span></div>
        </div>
        <DataTable :value="data.eta_etd.arriving" class="p-datatable-sm tight" dataKey="id">
          <Column field="shipment_number" header="Shipment #" />
          <Column header="Leg">
            <template #body="{ data: row }">
              <Tag :severity="row.direction === 'import' ? 'info' : 'success'" :value="humanize(row.direction)" class="mini" />
            </template>
          </Column>
          <Column header="Party"><template #body="{ data: row }">{{ row.party || '—' }}</template></Column>
          <Column header="Lane">
            <template #body="{ data: row }">{{ row.port_of_loading || '—' }} → {{ row.port_of_discharge || '—' }}</template>
          </Column>
          <Column field="eta" header="ETA" />
          <Column header="Delay">
            <template #body="{ data: row }">
              <span v-if="row.eta_variance_days > 0" class="bad">{{ row.eta_variance_days }}d late</span>
              <span v-else class="good">on time</span>
            </template>
          </Column>
          <Column header="Status">
            <template #body="{ data: row }"><Tag :severity="shipmentStatusSeverity[row.status]" :value="humanize(row.status)" /></template>
          </Column>
          <template #empty>Nothing due to arrive in this window.</template>
        </DataTable>
        <div class="card-foot">
          <Button label="Full ETA / ETD board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/eta-etd')" />
        </div>
      </section>

      <div class="two-col">
        <!-- 4 · Container Status -->
        <section class="card">
          <h3><i class="pi pi-box" /> Container Status</h3>
          <div class="kpi-row">
            <div class="kpi"><span class="kpi-value">{{ data.containers.total }}</span><span class="kpi-label">Tracked</span></div>
            <div class="kpi"><span class="kpi-value">{{ data.containers.at_port }}</span><span class="kpi-label">At port</span></div>
            <div class="kpi"><span class="kpi-value">{{ data.containers.in_transit }}</span><span class="kpi-label">Sailing</span></div>
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.containers.in_detention > 0 }">{{ data.containers.in_detention }}</span><span class="kpi-label">In detention</span></div>
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.containers.damaged > 0 }">{{ data.containers.damaged }}</span><span class="kpi-label">Damaged</span></div>
          </div>
          <div class="chips">
            <Tag v-for="s in containersWithBoxes" :key="s.status" :severity="containerStatusSeverity[s.status]"
              :value="`${humanize(s.status)} · ${s.total}`" class="mini" />
            <span v-if="!containersWithBoxes.length" class="muted">No containers booked yet.</span>
          </div>
          <div class="card-foot">
            <Button label="Container board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/containers')" />
          </div>
        </section>

        <!-- 5 · Shipment Profit -->
        <section class="card">
          <h3><i class="pi pi-chart-line" /> Shipment Profit</h3>
          <p class="panel-sub">Last {{ data.profit.days }} days · {{ data.profit.export_shipments }} exports, {{ data.profit.import_shipments }} imports</p>
          <dl class="money">
            <div><dt>Export revenue</dt><dd>{{ money(data.profit.revenue) }}</dd></div>
            <div><dt>Export cost</dt><dd>{{ money(data.profit.export_cost) }}</dd></div>
            <div>
              <dt>Profit</dt>
              <dd :class="data.profit.profit >= 0 ? 'good' : 'bad'">
                {{ money(data.profit.profit) }}
                <small v-if="data.profit.margin_percent !== null">({{ data.profit.margin_percent }}%)</small>
              </dd>
            </div>
            <div><dt>Import landed cost</dt><dd>{{ money(data.profit.import_landed_cost) }}</dd></div>
          </dl>
          <div class="card-foot">
            <Button label="Profit board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/profit')" />
          </div>
        </section>
      </div>

      <div class="two-col">
        <!-- 6 · Pending Documents -->
        <section class="card">
          <h3><i class="pi pi-file-import" /> Pending Documents</h3>
          <div class="kpi-row">
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.documents.outstanding > 0 }">{{ data.documents.outstanding }}</span><span class="kpi-label">Outstanding</span></div>
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.documents.overdue > 0 }">{{ data.documents.overdue }}</span><span class="kpi-label">Overdue</span></div>
            <div class="kpi"><span class="kpi-value">{{ data.documents.due_this_week }}</span><span class="kpi-label">Due in 7 days</span></div>
          </div>
          <ul v-if="data.documents.by_type.length" class="plain-list">
            <li v-for="row in data.documents.by_type" :key="row.doc_type">
              <span>{{ row.label }}</span><strong>{{ row.total }}</strong>
            </li>
          </ul>
          <p v-else class="muted">Every document is in hand.</p>
          <div class="card-foot">
            <Button label="Document board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/documents')" />
          </div>
        </section>

        <!-- 7 · Pending Customs Clearance -->
        <section class="card">
          <h3><i class="pi pi-verified" /> Pending Customs Clearance</h3>
          <div class="kpi-row">
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.customs.pending > 0 }">{{ data.customs.pending }}</span><span class="kpi-label">Not cleared</span></div>
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.customs.at_port_uncleared > 0 }">{{ data.customs.at_port_uncleared }}</span><span class="kpi-label">Landed, uncleared</span></div>
            <div class="kpi"><span class="kpi-value" :class="{ bad: data.customs.on_hold > 0 }">{{ data.customs.on_hold }}</span><span class="kpi-label">On hold</span></div>
            <div class="kpi"><span class="kpi-value">{{ money(data.customs.duty_outstanding) }}</span><span class="kpi-label">Duty assessed</span></div>
          </div>
          <div class="chips">
            <Tag v-for="s in data.customs.by_status" :key="s.status" :severity="customsStatusSeverity[s.status]"
              :value="`${humanize(s.status)} · ${s.total}`" class="mini" />
            <span v-if="!data.customs.by_status.length" class="muted">Nothing waiting at customs.</span>
          </div>
          <div class="card-foot">
            <Button label="Customs board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/customs')" />
          </div>
        </section>
      </div>

      <!-- 8 · Logistics Expenses -->
      <section class="card">
        <h3><i class="pi pi-indian-rupee" /> Logistics Expenses</h3>
        <div class="kpi-row">
          <div class="kpi"><span class="kpi-value">{{ money(data.expenses.total) }}</span><span class="kpi-label">Booked</span></div>
          <div class="kpi"><span class="kpi-value">{{ money(data.expenses.paid) }}</span><span class="kpi-label">Paid</span></div>
          <div class="kpi"><span class="kpi-value" :class="{ bad: data.expenses.unpaid > 0 }">{{ money(data.expenses.unpaid) }}</span><span class="kpi-label">Owed to vendors</span></div>
        </div>
        <ul v-if="data.expenses.by_type.length" class="bars">
          <li v-for="row in data.expenses.by_type" :key="row.expense_type">
            <span class="bar-label">{{ row.label }}</span>
            <span class="bar-track"><span class="bar-fill" :style="{ width: `${(row.total / expenseMax) * 100}%` }" /></span>
            <span class="bar-value">{{ money(row.total) }}</span>
          </li>
        </ul>
        <p v-else class="muted">No logistics costs booked yet.</p>
        <div class="card-foot">
          <Button label="Expense board" icon="pi pi-arrow-right" iconPos="right" size="small" text @click="router.push('/import-export/expenses')" />
          <Button label="Pending payments" icon="pi pi-wallet" size="small" text @click="router.push('/import-export/payments')" />
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.card { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 12px; padding: 1.1rem 1.25rem; margin-bottom: 1.25rem; }
.card h3 { margin: 0 0 0.9rem; font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
.card h3 i { color: #6366f1; }
.card-foot { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
.panel-sub { margin: -0.5rem 0 0.9rem; font-size: 0.78rem; color: #9ca3af; }
.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.25rem; }
.two-col > .card { margin-bottom: 0; }
.two-col { margin-bottom: 1.25rem; }

.leg.export h3 i { color: #16a34a; }
.leg-body { display: flex; align-items: center; gap: 1.5rem; }
.big { font-size: 2.6rem; font-weight: 700; line-height: 1; }
.mini-dl { margin: 0; display: grid; grid-template-columns: repeat(2, minmax(120px, 1fr)); gap: 0.4rem 1rem; flex: 1; }
.mini-dl > div { display: flex; flex-direction: column; }
.mini-dl dt { font-size: 0.72rem; color: #6b7280; }
.mini-dl dd { margin: 0; font-size: 0.95rem; font-weight: 600; }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.75rem; margin-bottom: 0.9rem; }
.kpi { display: flex; flex-direction: column; border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.6rem 0.8rem; }
.kpi-value { font-size: 1.4rem; font-weight: 700; line-height: 1.2; }
.kpi-label { font-size: 0.72rem; color: #6b7280; }

.chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.money { margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem 1.25rem; }
.money > div { display: flex; flex-direction: column; }
.money dt { font-size: 0.74rem; color: #6b7280; }
.money dd { margin: 0; font-size: 1.15rem; font-weight: 600; }

.plain-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.plain-list li { display: flex; justify-content: space-between; font-size: 0.83rem; border-bottom: 1px dashed var(--border, #e8e4da); padding-bottom: 0.25rem; }

.bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.bars li { display: grid; grid-template-columns: 30% 1fr auto; gap: 0.6rem; align-items: center; }
.bar-label { font-size: 0.8rem; }
.bar-track { height: 0.5rem; background: rgba(99, 102, 241, 0.12); border-radius: 999px; overflow: hidden; }
.bar-fill { display: block; height: 100%; background: #6366f1; border-radius: 999px; }
.bar-value { font-size: 0.8rem; font-variant-numeric: tabular-nums; }

.tight :deep(.p-datatable-tbody > tr > td) { padding-top: 0.35rem; padding-bottom: 0.35rem; }
.muted { color: #9ca3af; font-size: 0.85rem; }
.good { color: #16a34a; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }
</style>
