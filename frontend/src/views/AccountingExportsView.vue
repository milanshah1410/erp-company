<script setup lang="ts">
/**
 * Tally / Busy / Zoho Books export (retail base §10).
 *
 * Built around one fact: the file is handed to somebody else. A chartered
 * accountant who imports a month of vouchers against the wrong ledger names
 * spends a day undoing it, so this screen leads with what a run *contains* and
 * makes the preview prominent — the download button is the last thing on it, not
 * the first.
 *
 * The three targets are genuinely different documents, and the form changes shape
 * rather than hiding that: Tally takes one XML file holding everything at once,
 * while Busy and Zoho take one CSV per document type and must be run several
 * times, contacts first.
 */
import { ref, computed, onMounted, watch } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()

const TARGETS = [
  { label: 'Tally (XML)', value: 'tally', note: 'One file with masters and every voucher type together.' },
  { label: 'Busy (CSV)', value: 'busy', note: 'One CSV per document type. Run it once for each.' },
  { label: 'Zoho Books (CSV)', value: 'zoho', note: 'One CSV per document type. Import contacts first.' },
]

/**
 * Order matters and is stated: a voucher citing a party the target has never
 * heard of is rejected, so contacts lead.
 */
const DOCUMENTS = [
  { key: 'contacts', label: 'Customers & Suppliers', hint: 'Ledger masters. Import these first.' },
  { key: 'invoices', label: 'Sales Invoices', hint: 'Party debited, sales and tax credited.' },
  { key: 'bills', label: 'Purchase Bills', hint: 'Purchases and tax debited, party credited.' },
  { key: 'receipts', label: 'Receipts', hint: 'Money in, against bills and on account.' },
  { key: 'payments', label: 'Payments', hint: 'Money out, against bills and on account.' },
  { key: 'journals', label: 'Journal Vouchers', hint: 'Only entries with both ledgers filled in.' },
  { key: 'expenses', label: 'Expenses', hint: 'Each expense category becomes its own ledger.' },
]

const target = ref('tally')
const document_ = ref('contacts')
const selectedDocs = ref<string[]>(['contacts', 'invoices', 'bills', 'receipts', 'payments', 'journals', 'expenses'])
const dateFrom = ref('')
const dateTo = ref('')

const loading = ref(false)
const downloading = ref(false)
const previewing = ref(false)
const summary = ref<any>({ counts: {}, skipped_journals: 0, period: {} })

const isTally = computed(() => target.value === 'tally')
const targetNote = computed(() => TARGETS.find((t) => t.value === target.value)?.note ?? '')

const counts = computed<Record<string, number>>(() => summary.value.counts ?? {})

/** Nothing to export in the chosen scope — the download would be a header row. */
const nothingToExport = computed(() => {
  const keys = isTally.value ? selectedDocs.value : [document_.value]
  return keys.every((k) => !Number(counts.value[k] ?? 0))
})

const totalRows = computed(() =>
  (isTally.value ? selectedDocs.value : [document_.value]).reduce(
    (sum, k) => sum + Number(counts.value[k] ?? 0),
    0,
  ),
)

function params() {
  return {
    date_from: dateFrom.value || undefined,
    date_to: dateTo.value || undefined,
  }
}

async function loadSummary() {
  loading.value = true
  try {
    const { data } = await http.get('/accounting-exports/summary', { params: params() })
    summary.value = data.data
    // Adopt the server's defaults the first time, so the date boxes show the
    // window that was actually counted rather than sitting empty.
    if (!dateFrom.value) dateFrom.value = data.data.period?.from ?? ''
    if (!dateTo.value) dateTo.value = data.data.period?.to ?? ''
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not read the books'), life: 4000 })
  } finally {
    loading.value = false
  }
}

function toggleDoc(key: string) {
  const i = selectedDocs.value.indexOf(key)
  if (i >= 0) selectedDocs.value.splice(i, 1)
  else selectedDocs.value.push(key)
}

// ---- Preview ---------------------------------------------------------------

const previewDialog = ref(false)
const preview = ref<any>(null)

async function runPreview() {
  previewing.value = true
  try {
    const { data } = await http.get('/accounting-exports/preview', {
      params: { ...params(), target: target.value, document: isTally.value ? selectedDocs.value[0] : document_.value },
    })
    preview.value = data
    previewDialog.value = true
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt('Could not build a preview'), life: 4000 })
  } finally {
    previewing.value = false
  }
}

// ---- Download --------------------------------------------------------------

async function download() {
  downloading.value = true
  try {
    const { data, headers } = await http.get('/accounting-exports/download', {
      params: {
        ...params(),
        target: target.value,
        document: isTally.value ? 'all' : document_.value,
        documents: isTally.value ? selectedDocs.value : undefined,
      },
      responseType: 'blob',
    })

    const type = String(headers['content-type'] ?? '')
    const ext = isTally.value ? 'xml' : 'csv'
    const stamp = new Date().toISOString().slice(0, 10)
    const name = isTally.value ? `tally-${stamp}.xml` : `${target.value}-${document_.value}-${stamp}.${ext}`

    const url = window.URL.createObjectURL(new Blob([data], { type: type || 'application/octet-stream' }))
    const a = window.document.createElement('a')
    a.href = url
    a.download = name
    a.click()
    window.URL.revokeObjectURL(url)
  } catch (e: any) {
    /**
     * A 422 arrives as a Blob because the request asked for one, so the message
     * has to be read back out of it — otherwise the user is told "download
     * failed" while the server was explaining exactly what to pick instead.
     */
    let message = pt('Could not build the file')
    try {
      const text = await e.response?.data?.text?.()
      if (text) message = JSON.parse(text).message ?? message
    } catch {
      message = e.response?.data?.message ?? message
    }
    toast.add({ severity: 'error', summary: message, life: 7000 })
  } finally {
    downloading.value = false
  }
}

watch([dateFrom, dateTo], loadSummary)
onMounted(loadSummary)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Accounting Export')"
      :subtitle="pt('Hand your books to Tally, Busy or Zoho Books')"
    />

    <p class="hint-block">
      {{ pt('This writes a file for your accountant to import. It is one-way — nothing is read back, so the balances here stay the ones you keep.') }}
    </p>

    <div class="panel">
      <h3>{{ pt('1. Where is it going?') }}</h3>
      <div class="target-row">
        <label v-for="t in TARGETS" :key="t.value" class="target" :class="{ on: target === t.value }">
          <input type="radio" :value="t.value" v-model="target" />
          <span>
            <strong>{{ t.label }}</strong>
            <small>{{ t.note }}</small>
          </span>
        </label>
      </div>
    </div>

    <div class="panel">
      <h3>{{ pt('2. Which period?') }}</h3>
      <div class="date-row">
        <div class="fld">
          <label>{{ pt('From') }}</label>
          <input type="date" v-model="dateFrom" class="date-input" />
        </div>
        <div class="fld">
          <label>{{ pt('To') }}</label>
          <input type="date" v-model="dateTo" class="date-input" />
        </div>
        <small class="hint">{{ pt('Masters (customers and suppliers) are always exported in full — a ledger has no date.') }}</small>
      </div>
    </div>

    <div class="panel">
      <h3>{{ pt('3. What goes in?') }}</h3>
      <p class="hint">{{ targetNote }}</p>

      <!-- Tally: tick several, one file. -->
      <div v-if="isTally" class="doc-grid">
        <label v-for="d in DOCUMENTS" :key="d.key" class="doc" :class="{ on: selectedDocs.includes(d.key) }">
          <Checkbox :modelValue="selectedDocs.includes(d.key)" binary @change="toggleDoc(d.key)" />
          <span class="doc-text">
            <strong>{{ d.label }}</strong>
            <small>{{ d.hint }}</small>
          </span>
          <span class="doc-count" :class="{ zero: !Number(counts[d.key] ?? 0) }">
            {{ Number(counts[d.key] ?? 0).toLocaleString('en-IN') }}
          </span>
        </label>
      </div>

      <!-- Busy / Zoho: one at a time, in order. -->
      <div v-else class="doc-single">
        <Select
          v-model="document_"
          :options="DOCUMENTS"
          optionLabel="label"
          optionValue="key"
          class="doc-sel"
        />
        <span class="doc-count" :class="{ zero: !Number(counts[document_] ?? 0) }">
          {{ Number(counts[document_] ?? 0).toLocaleString('en-IN') }} {{ pt('row(s)') }}
        </span>
        <small class="hint">
          {{ DOCUMENTS.find((d) => d.key === document_)?.hint }}
        </small>
      </div>

      <!-- Stated, never silent: journal rows this app allows but no accounting
           package will accept. -->
      <p v-if="Number(summary.skipped_journals) > 0" class="warn-block">
        {{ pt('{n} manual voucher(s) in this period have only one ledger filled in and cannot be exported as a journal entry. Add the second ledger on those vouchers to include them.', { n: summary.skipped_journals }) }}
      </p>
    </div>

    <div class="actions">
      <Button
        :label="pt('Preview')"
        icon="pi pi-eye"
        outlined
        :loading="previewing"
        :disabled="loading || nothingToExport"
        @click="runPreview"
      />
      <Button
        :label="pt('Download {n} row(s)', { n: totalRows.toLocaleString('en-IN') })"
        icon="pi pi-download"
        :loading="downloading"
        :disabled="loading || nothingToExport"
        @click="download"
      />
      <small v-if="nothingToExport" class="hint">
        {{ pt('Nothing to export in this period — widen the dates or pick another document.') }}
      </small>
    </div>

    <!-- ============ Preview ============ -->
    <Dialog v-model:visible="previewDialog" :header="pt('Export Preview')" modal :style="{ width: '62rem' }" maximizable>
      <p v-if="preview" class="hint">
        {{ pt('{n} row(s) in scope.', { n: preview.total }) }}
        {{ preview.format === 'xml' ? pt('Check the ledger names and which side each amount sits on before importing.') : pt('Showing the first 10 rows.') }}
      </p>

      <pre v-if="preview?.format === 'xml'" class="xml">{{ preview.sample }}</pre>

      <DataTable v-else-if="preview" :value="preview.rows" class="p-datatable-sm" scrollable scrollHeight="26rem">
        <Column v-for="(col, i) in preview.columns" :key="col" :header="col">
          <template #body="{ data }">{{ data[i] ?? '—' }}</template>
        </Column>
        <template #empty>{{ pt('No rows.') }}</template>
      </DataTable>

      <template #footer>
        <Button :label="pt('Close')" text @click="previewDialog = false" />
        <Button :label="pt('Download')" icon="pi pi-download" :loading="downloading" @click="download" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.panel {
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem 1.15rem;
  margin-bottom: 1rem;
}
.panel h3 {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #6b7280;
  margin: 0 0 0.75rem;
}

.target-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 0.6rem; }
.target {
  display: flex; align-items: flex-start; gap: 0.6rem; cursor: pointer;
  border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.7rem 0.85rem;
}
.target.on { border-color: var(--safety-amber, #e8a33d); background: rgba(232, 163, 61, 0.1); }
.target span { display: flex; flex-direction: column; gap: 0.15rem; }
.target small { font-size: 0.74rem; color: #6b7280; }

.date-row { display: flex; gap: 0.85rem; align-items: flex-end; flex-wrap: wrap; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }

.doc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.5rem; }
.doc {
  display: flex; align-items: center; gap: 0.6rem; cursor: pointer;
  border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.55rem 0.75rem;
}
.doc.on { border-color: var(--ink-blue, #2c4a5e); background: rgba(44, 74, 94, 0.07); }
.doc-text { display: flex; flex-direction: column; flex: 1; gap: 0.1rem; }
.doc-text small { font-size: 0.72rem; color: #6b7280; }
.doc-count { font-weight: 700; font-size: 0.85rem; }
.doc-count.zero { color: #9ca3af; font-weight: 400; }

.doc-single { display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap; }
.doc-sel { width: 260px; }

.actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }

.hint { font-size: 0.76rem; color: #6b7280; }
.hint-block {
  font-size: 0.82rem; color: #374151; margin: 0 0 1rem;
  border: 1px solid var(--border, #e8e4da); border-radius: 10px; padding: 0.65rem 0.85rem;
}
.warn-block {
  font-size: 0.78rem; color: #4a3410; margin: 0.85rem 0 0;
  border: 1px solid #e8c07d; background: rgba(232, 192, 125, 0.14);
  border-radius: 10px; padding: 0.6rem 0.8rem;
}

.xml {
  max-height: 26rem; overflow: auto; background: #1f2937; color: #e5e7eb;
  border-radius: 8px; padding: 0.85rem; font-size: 0.72rem; line-height: 1.5;
  white-space: pre; margin: 0;
}
</style>
