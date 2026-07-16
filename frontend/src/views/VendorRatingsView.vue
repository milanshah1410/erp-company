<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Rating from 'primevue/rating'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/vendor-ratings')

const suppliers = ref<any[]>([])
const grns = ref<any[]>([])
const summary = ref<any[]>([])
const search = ref('')

const canCreate = computed(() => auth.can('vendor-ratings.create'))
const canDelete = computed(() => auth.can('vendor-ratings.delete'))

function today() {
  return new Date().toISOString().slice(0, 10)
}

const grnsForSupplier = computed(() =>
  grns.value
    .filter((g) => !form.value.supplier_id || g.supplier_id === form.value.supplier_id)
    .map((g) => ({ label: g.grn_number, value: g.id })),
)

// --- Create dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const form = ref<any>(blankForm())
const errors = ref<Record<string, string[]>>({})

function blankForm() {
  return {
    supplier_id: null, goods_receipt_id: null, rated_on: today(),
    quality_score: 3, delivery_score: 3, pricing_score: 3, service_score: 3, remarks: '',
  }
}
const overall = computed(() => {
  const f = form.value
  return ((f.quality_score + f.delivery_score + f.pricing_score + f.service_score) / 4).toFixed(2)
})

function openCreate() {
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}
async function save() {
  saving.value = true
  errors.value = {}
  try {
    await http.post('/vendor-ratings', form.value)
    toast.add({ severity: 'success', summary: 'Rating saved', life: 2500 })
    dialogVisible.value = false
    await Promise.all([reload(), loadSummary()])
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}
async function remove(row: any) {
  const res = await Swal.fire({ title: 'Delete this rating?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
  if (!res.isConfirmed) return
  await http.delete(`/vendor-ratings/${row.id}`)
  toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
  await Promise.all([reload(), loadSummary()])
}

function scoreColor(v: number): string {
  if (v >= 4) return 'success'
  if (v >= 3) return 'info'
  if (v >= 2) return 'warn'
  return 'danger'
}

function reload() {
  return crud.fetch({ search: search.value || undefined })
}
async function loadSummary() {
  const { data } = await http.get('/vendor-ratings/summary')
  summary.value = data.data ?? []
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(async () => {
  const [s, g] = await Promise.all([
    http.get('/suppliers', { params: { per_page: 100 } }),
    http.get('/goods-receipts', { params: { per_page: 100 } }),
  ])
  suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  grns.value = (g.data.data ?? g.data)
  await Promise.all([reload(), loadSummary()])
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Vendor Rating</h1>
      <Button v-if="canCreate" label="Rate a Vendor" icon="pi pi-star" @click="openCreate" />
    </div>

    <!-- Leaderboard -->
    <div class="card" v-if="summary.length">
      <h3 class="card-title">Vendor Scorecard</h3>
      <DataTable :value="summary" class="p-datatable-sm" dataKey="supplier_id">
        <Column field="supplier" header="Vendor" />
        <Column header="Quality"><template #body="{ data }">{{ data.avg_quality }}</template></Column>
        <Column header="Delivery"><template #body="{ data }">{{ data.avg_delivery }}</template></Column>
        <Column header="Pricing"><template #body="{ data }">{{ data.avg_pricing }}</template></Column>
        <Column header="Service"><template #body="{ data }">{{ data.avg_service }}</template></Column>
        <Column header="Overall">
          <template #body="{ data }"><Tag :severity="scoreColor(data.avg_overall)" :value="`${data.avg_overall} ★`" /></template>
        </Column>
        <Column header="Ratings"><template #body="{ data }">{{ data.ratings_count }}</template></Column>
      </DataTable>
    </div>

    <!-- Individual ratings -->
    <div class="ratings-head">
      <h3 class="card-title" style="margin-top: 1.5rem">All Ratings</h3>
      <div class="ratings-tools">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search vendor / remarks..." @keyup.enter="crud.currentPage.value = 1; reload()" />
        </span>
        <Button label="Search" outlined @click="crud.currentPage.value = 1; reload()" />
        <TableExportButtons title="Vendor Ratings" endpoint="/vendor-ratings"
          :params="() => ({ search: search || undefined })"
          :columns="[
          { key: 'supplier.name', label: 'Vendor' },
          { key: 'rated_on', label: 'Date' },
          { key: 'goods_receipt.grn_number', label: 'GRN' },
          { key: 'quality_score', label: 'Quality' },
          { key: 'delivery_score', label: 'Delivery' },
          { key: 'pricing_score', label: 'Pricing' },
          { key: 'service_score', label: 'Service' },
          { key: 'overall_score', label: 'Overall' },
          { key: 'remarks', label: 'Remarks' },
        ]" />
      </div>
    </div>
    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Vendor"><template #body="{ data }">{{ data.supplier?.name }}</template></Column>
      <Column field="rated_on" header="Date" />
      <Column header="GRN"><template #body="{ data }">{{ data.goods_receipt?.grn_number || '—' }}</template></Column>
      <Column header="Quality"><template #body="{ data }">{{ data.quality_score }}</template></Column>
      <Column header="Delivery"><template #body="{ data }">{{ data.delivery_score }}</template></Column>
      <Column header="Pricing"><template #body="{ data }">{{ data.pricing_score }}</template></Column>
      <Column header="Service"><template #body="{ data }">{{ data.service_score }}</template></Column>
      <Column header="Overall">
        <template #body="{ data }"><Tag :severity="scoreColor(data.overall_score)" :value="`${data.overall_score} ★`" /></template>
      </Column>
      <Column header="Remarks"><template #body="{ data }"><span class="remarks">{{ data.remarks || '—' }}</span></template></Column>
      <Column header="" style="width: 4rem">
        <template #body="{ data }">
          <Button v-if="canDelete" icon="pi pi-trash" size="small" text severity="danger" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>No ratings recorded yet.</template>
    </DataTable>

    <!-- Create dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="Rate a Vendor" :style="{ width: '560px' }">
      <div class="fld">
        <label>Vendor *</label>
        <Select v-model="form.supplier_id" :options="suppliers" optionLabel="label" optionValue="value" filter placeholder="Select vendor" />
        <small v-if="errors.supplier_id" class="err">{{ errors.supplier_id[0] }}</small>
      </div>
      <div class="two-col">
        <div class="fld">
          <label>Against GRN (optional)</label>
          <Select v-model="form.goods_receipt_id" :options="grnsForSupplier" optionLabel="label" optionValue="value" showClear placeholder="Link a receipt" />
        </div>
        <div class="fld">
          <label>Rated On *</label>
          <input type="date" v-model="form.rated_on" class="date-input" />
        </div>
      </div>

      <div class="scores">
        <div class="score-row"><span>Quality</span><Rating v-model="form.quality_score" /></div>
        <div class="score-row"><span>Delivery</span><Rating v-model="form.delivery_score" /></div>
        <div class="score-row"><span>Pricing</span><Rating v-model="form.pricing_score" /></div>
        <div class="score-row"><span>Service</span><Rating v-model="form.service_score" /></div>
      </div>
      <div class="overall">Overall Score: <strong>{{ overall }} ★</strong></div>

      <div class="fld full">
        <label>Remarks</label>
        <Textarea v-model="form.remarks" rows="2" />
      </div>

      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button label="Save Rating" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.card { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 12px; padding: 1rem; }
.card-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.6rem; }
.ratings-head { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.ratings-tools { display: inline-flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.75rem; }
.fld.full { margin-top: 0.25rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(textarea) { width: 100%; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.scores { margin: 0.5rem 0 0.75rem; }
.score-row { display: flex; align-items: center; justify-content: space-between; padding: 0.4rem 0; border-bottom: 1px solid #f3f4f6; }
.score-row span { font-weight: 500; color: #374151; }
.overall { text-align: right; font-size: 1.05rem; margin-bottom: 0.75rem; }
.remarks { display: inline-block; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
