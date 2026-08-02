<script setup lang="ts">
/**
 * The season's buying list — every published booklist rolled up by product and
 * netted against stock.
 *
 * This is the number the trade's whole year turns on. A stationery shop earns
 * most of its revenue in about ten weeks, and it has to buy for those weeks in
 * March: short means turning away the customers who pay for the year, long means
 * a godown of Class 7 books nobody will ever ask for again.
 *
 * Sorted by the gap rather than by name, because the list is a work queue and not
 * a report — the row at the top is what to order first. Optional items are
 * counted at full strength, which over-states them on purpose: nothing here knows
 * what fraction of parents want the geometry box, and in June the cheaper mistake
 * is having it.
 */
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const summary = ref({ lines: 0, to_buy: 0 })
const academicYear = ref('')
const loading = ref(false)
const institutions = ref<any[]>([])
const institutionFilter = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stationery/season-demand', {
      params: {
        academic_year: academicYear.value || undefined,
        institution_id: institutionFilter.value || undefined,
      },
    })
    rows.value = data.data
    summary.value = data.summary
    // The API resolves the session when the box is empty; echo it back so the
    // buyer can see which year they are looking at.
    if (data.academic_year) academicYear.value = data.academic_year
  } finally {
    loading.value = false
  }
}

async function loadPickers() {
  const { data } = await http.get('/stationery/institutions', { params: { per_page: 500, is_active: 1 } })
  institutions.value = (data.data ?? data).map((x: any) => ({ label: x.name, value: x.id }))
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Season Demand')"
      :subtitle="pt('What every published booklist adds up to, netted against what is on the shelf')"
    />

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Session') }}</span>
        <span class="t-value">{{ academicYear || '—' }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Distinct items') }}</span>
        <span class="t-value">{{ summary.lines }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Short of the season') }}</span>
        <span class="t-value warn">{{ summary.to_buy }}</span>
      </div>
    </div>

    <Message severity="info" :closable="false">
      {{ pt('Counts every published list at its expected sets, optional items included. Draft lists are not in here — publish one to see it.') }}
    </Message>

    <div class="toolbar">
      <InputText v-model="academicYear" placeholder="2026-27" class="year" @keyup.enter="load" />
      <Select
        v-model="institutionFilter"
        :options="[{ label: pt('All institutions'), value: null }, ...institutions]"
        option-label="label"
        option-value="value"
        filter
        @change="load"
      />
      <Button icon="pi pi-refresh" :label="pt('Recalculate')" @click="load" />
      <TableExportButtons
        title="Season Demand"
        filenameBase="stationery-season-demand"
        :rows="rows"
        :columns="[
          { key: 'sku', label: 'SKU' },
          { key: 'name', label: 'Product' },
          { key: 'required', label: 'Required' },
          { key: 'available', label: 'On hand' },
          { key: 'to_buy', label: 'To buy' },
        ]"
      />
    </div>

    <DataTable :value="rows" :loading="loading" data-key="product_id" paginator :rows="50" striped-rows responsive-layout="scroll">
      <Column field="name" :header="pt('Product')" sortable>
        <template #body="{ data }">
          <div class="strong">{{ data.name }}</div>
          <small class="muted mono">{{ data.sku }}</small>
        </template>
      </Column>

      <Column field="kits" :header="pt('On these lists')">
        <template #body="{ data }">
          <small class="muted">{{ data.kits.join(' · ') }}</small>
        </template>
      </Column>

      <Column field="required" :header="pt('Required')" sortable />
      <Column field="available" :header="pt('On hand')" sortable />

      <Column field="to_buy" :header="pt('To buy')" sortable>
        <template #body="{ data }">
          <Tag
            :value="String(data.to_buy)"
            :severity="data.to_buy > 0 ? 'warn' : 'success'"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('Nothing to buy for this session — either no booklists are published, or the shelf already covers them.') }}
        </div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value.warn { color: #b45309; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
.year { width: 130px; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
