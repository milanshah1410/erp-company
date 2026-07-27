<script setup lang="ts">
// Shipment Timeline. Pick a shipment and read its journey end to end: the
// milestones the workflow recorded on its own (status moves, customs states,
// container moves, documents received, payments) interleaved with whatever the
// desk typed in from a carrier update or a phone call.
//
// Deep-linkable as /import-export/timeline?shipment=123, so a link out of any
// other board lands on the right thread.
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'
import TableExportButtons from '../components/TableExportButtons.vue'
import ShipmentDetailDialog from '../components/exim/ShipmentDetailDialog.vue'
import {
  useTradeCatalogue, humanize, shipmentStatusSeverity, customsStatusSeverity, paymentStatusSeverity,
} from '../composables/useTradeCatalogue'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const { catalogue, load: loadCatalogue } = useTradeCatalogue()

const canEdit = computed(() => auth.can('shipments.edit'))

const shipments = ref<any[]>([])
const selectedId = ref<number | null>(null)
const shipment = ref<any>(null)
const events = ref<any[]>([])
const loading = ref(false)

async function loadShipments() {
  const { data } = await http.get('/shipments', { params: { per_page: 100, state: 'all' } })
  shipments.value = (data.data ?? data).map((s: any) => ({
    label: `${s.shipment_number} — ${s.party ?? humanize(s.direction)}`,
    value: s.id,
  }))
}

async function loadTimeline() {
  if (!selectedId.value) {
    shipment.value = null
    events.value = []
    return
  }
  loading.value = true
  try {
    const { data } = await http.get(`/shipments/${selectedId.value}/timeline`)
    shipment.value = data.shipment.data ?? data.shipment
    events.value = data.data
  } finally {
    loading.value = false
  }
}

// Keep the URL in step so the thread can be linked to and reloaded.
watch(selectedId, id => {
  const current = route.query.shipment ? Number(route.query.shipment) : null
  if (id !== current) router.replace({ query: id ? { shipment: String(id) } : {} })
  loadTimeline()
})

// --- add a milestone ---
const form = ref<any>(null)
const saving = ref(false)
function openForm() {
  form.value = {
    event_type: 'in_transit',
    event_at: new Date().toISOString().slice(0, 16),
    location: '',
    description: '',
  }
}
async function save() {
  saving.value = true
  try {
    await http.post(`/shipments/${selectedId.value}/events`, form.value)
    toast.add({ severity: 'success', summary: 'Milestone added', life: 2200 })
    form.value = null
    await loadTimeline()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}
async function remove(row: any) {
  const res = await Swal.fire({ title: 'Remove this milestone?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/shipments/${selectedId.value}/events/${row.id}`)
    await loadTimeline()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'System milestones cannot be deleted.', life: 4000 })
  }
}

const detailVisible = ref(false)
function openDetail() {
  detailVisible.value = true
}

// Grouped by calendar day, so a busy port day reads as one block.
const grouped = computed(() => {
  const out: { day: string; items: any[] }[] = []
  for (const e of events.value) {
    const day = new Date(e.event_at).toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
    const last = out[out.length - 1]
    if (last && last.day === day) last.items.push(e)
    else out.push({ day, items: [e] })
  }
  return out
})

onMounted(async () => {
  await Promise.all([loadShipments(), loadCatalogue()])
  const fromUrl = route.query.shipment ? Number(route.query.shipment) : null
  selectedId.value = fromUrl ?? shipments.value[0]?.value ?? null
  if (selectedId.value === fromUrl) await loadTimeline()
})
</script>

<template>
  <div>
    <PageHeader title="Shipment Timeline"
      subtitle="Every milestone on a consignment — recorded automatically as it moves, or added by hand.">
      <template #actions>
        <div class="header-actions">
          <Select v-model="selectedId" :options="shipments" optionLabel="label" optionValue="value" filter
            placeholder="Pick a shipment" class="picker" />
          <TableExportButtons v-if="events.length" title="Shipment Timeline" :rows="events" :columns="[
            { key: 'event_at', label: 'When' },
            { key: 'event_label', label: 'Milestone' },
            { key: 'location', label: 'Location' },
            { key: 'description', label: 'Detail' },
            { key: 'source', label: 'Source' },
            { key: 'creator', label: 'By' },
          ]" />
          <Button v-if="canEdit && selectedId" label="Add milestone" icon="pi pi-plus" @click="openForm" />
        </div>
      </template>
    </PageHeader>

    <div v-if="!selectedId" class="empty">Pick a shipment to see its journey.</div>

    <template v-else>
      <!-- Where the shipment stands right now -->
      <section v-if="shipment" class="head-card">
        <div class="head-main">
          <h2>
            <a class="link" @click="openDetail">{{ shipment.shipment_number }}</a>
            <Tag :severity="shipment.direction === 'import' ? 'info' : 'success'" :value="humanize(shipment.direction)" />
          </h2>
          <p class="lane">
            {{ shipment.party || '—' }} ·
            {{ shipment.port_of_loading || '—' }} → {{ shipment.port_of_discharge || '—' }} ·
            {{ shipment.carrier || 'no carrier' }}
          </p>
        </div>
        <div class="head-tags">
          <Tag :severity="shipmentStatusSeverity[shipment.status]" :value="humanize(shipment.status)" />
          <Tag :severity="customsStatusSeverity[shipment.customs_status]" :value="`Customs: ${humanize(shipment.customs_status)}`" />
          <Tag :severity="paymentStatusSeverity[shipment.payment_status]" :value="humanize(shipment.payment_status)" />
        </div>
        <dl class="head-dl">
          <div><dt>ETD → ATD</dt><dd>{{ shipment.etd || '—' }} → {{ shipment.atd || '—' }}</dd></div>
          <div><dt>ETA → ATA</dt><dd>{{ shipment.eta || '—' }} → {{ shipment.ata || '—' }}</dd></div>
          <div><dt>Containers</dt><dd>{{ shipment.containers_count ?? 0 }}</dd></div>
          <div><dt>Documents pending</dt><dd :class="{ bad: shipment.pending_documents_count > 0 }">{{ shipment.pending_documents_count ?? 0 }}</dd></div>
        </dl>
      </section>

      <div v-if="loading && !events.length" class="muted">Loading…</div>

      <div v-else-if="!events.length" class="empty">No milestones recorded yet.</div>

      <div v-else class="thread">
        <section v-for="group in grouped" :key="group.day" class="day">
          <h3 class="day-head">{{ group.day }}</h3>
          <ol class="timeline">
            <li v-for="e in group.items" :key="e.id" :class="{ system: e.source === 'system' }">
              <span class="dot" />
              <div class="body">
                <div class="top">
                  <strong>{{ e.event_label }}</strong>
                  <span class="time">{{ new Date(e.event_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
                  <Tag v-if="e.source === 'manual'" value="added by hand" severity="secondary" class="mini" />
                  <Button v-if="canEdit && e.source === 'manual'" icon="pi pi-times" text rounded size="small"
                    severity="danger" @click="remove(e)" />
                </div>
                <div class="desc">{{ e.description }}</div>
                <div class="meta">
                  <span v-if="e.location"><i class="pi pi-map-marker" /> {{ e.location }}</span>
                  <span v-if="e.creator"><i class="pi pi-user" /> {{ e.creator }}</span>
                </div>
              </div>
            </li>
          </ol>
        </section>
      </div>
    </template>

    <Dialog :visible="!!form" modal header="Add milestone" :style="{ width: '540px' }"
      @update:visible="v => { if (!v) form = null }">
      <div v-if="form" class="form-grid">
        <div class="fld">
          <label>Milestone *</label>
          <Select v-model="form.event_type" :options="catalogue.milestones" optionLabel="label" optionValue="value" filter />
        </div>
        <div class="fld"><label>When *</label><input type="datetime-local" v-model="form.event_at" class="date-input" /></div>
        <div class="fld full"><label>Location</label><InputText v-model="form.location" placeholder="Port, terminal, city…" /></div>
        <div class="fld full"><label>Detail</label><Textarea v-model="form.description" rows="3" placeholder="What happened, per the carrier or the agent" /></div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="form = null" />
        <Button label="Add" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <ShipmentDetailDialog v-model:visible="detailVisible" :shipment-id="selectedId" @changed="loadTimeline" />
  </div>
</template>

<style scoped>
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.picker { width: 320px; }

.head-card { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.head-main h2 { margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem; }
.lane { margin: 0.3rem 0 0.7rem; color: #6b7280; font-size: 0.86rem; }
.head-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.head-dl { margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.6rem 1.25rem; }
.head-dl > div { display: flex; flex-direction: column; }
.head-dl dt { font-size: 0.72rem; color: #6b7280; }
.head-dl dd { margin: 0; font-size: 0.92rem; font-weight: 600; }

.thread { display: flex; flex-direction: column; gap: 1.25rem; }
.day-head { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin: 0 0 0.5rem; }
.timeline { list-style: none; margin: 0; padding: 0 0 0 1rem; border-left: 2px solid var(--border, #e8e4da); }
.timeline li { position: relative; padding: 0 0 1rem 1rem; }
.timeline .dot { position: absolute; left: -1.45rem; top: 0.35rem; width: 0.7rem; height: 0.7rem; border-radius: 50%; background: #6366f1; }
.timeline li.system .dot { background: #9ca3af; }
.top { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.time { font-size: 0.78rem; color: #9ca3af; }
.desc { font-size: 0.88rem; margin-top: 0.1rem; }
.meta { display: flex; gap: 1rem; font-size: 0.76rem; color: #9ca3af; margin-top: 0.15rem; }

.empty { border: 1px dashed var(--border, #e8e4da); border-radius: 12px; padding: 2rem; text-align: center; color: #9ca3af; }
.link { color: #6366f1; cursor: pointer; }
.link:hover { text-decoration: underline; }
.muted { color: #9ca3af; font-size: 0.85rem; }
.bad { color: #dc2626; }
.mini { font-size: 0.62rem; }
.mini :deep(.p-tag-label) { font-size: 0.62rem; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.82rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea), .fld :deep(.p-inputtext) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
</style>
