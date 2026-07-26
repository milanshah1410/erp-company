<script setup lang="ts">
import { ref } from 'vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import http from '../api/http'
import PageHeader from '../components/PageHeader.vue'

// --- GSTIN validation ---
const gstin = ref('')
const checking = ref(false)
const result = ref<any>(null)

async function validate() {
  if (!gstin.value.trim()) return
  checking.value = true
  result.value = null
  try {
    const { data } = await http.post('/gst/validate-gstin', { gstin: gstin.value.trim() })
    result.value = data
  } finally {
    checking.value = false
  }
}

// --- HSN lookup ---
const hsnQuery = ref('')
const hsnLoading = ref(false)
const hsnRows = ref<any[]>([])

async function lookupHsn() {
  hsnLoading.value = true
  try {
    const { data } = await http.get('/gst/hsn-lookup', { params: { q: hsnQuery.value.trim() } })
    hsnRows.value = data.data
  } finally {
    hsnLoading.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="GST Tools" subtitle="Validate a GSTIN and search the HSN / SAC master." />

    <div class="grid">
      <!-- GSTIN validator -->
      <section class="card">
        <h2>GSTIN Validation</h2>
        <p class="hint">Checks the 15-character format and the check digit, and decodes the state and PAN.</p>
        <div class="row">
          <InputText v-model="gstin" placeholder="e.g. 24ABCDE1234F1Z5" class="grow" maxlength="15"
            style="text-transform: uppercase" @keyup.enter="validate" />
          <Button label="Validate" :loading="checking" @click="validate" />
        </div>

        <div v-if="result" class="result">
          <Tag :severity="result.valid ? 'success' : 'danger'"
            :value="result.valid ? 'Valid GSTIN' : 'Invalid GSTIN'" />
          <ul v-if="result.errors?.length" class="errors">
            <li v-for="(e, i) in result.errors" :key="i">{{ e }}</li>
          </ul>
          <dl class="decoded">
            <div><dt>State</dt><dd>{{ result.state || '—' }} <small v-if="result.state_code">({{ result.state_code }})</small></dd></div>
            <div><dt>PAN</dt><dd>{{ result.pan || '—' }}</dd></div>
            <div><dt>Entity</dt><dd>{{ result.entity_type || '—' }}</dd></div>
          </dl>
        </div>
      </section>

      <!-- HSN lookup -->
      <section class="card">
        <h2>HSN / SAC Lookup</h2>
        <p class="hint">Search codes or descriptions to find the applicable GST rate.</p>
        <div class="row">
          <InputText v-model="hsnQuery" placeholder="Code or description, e.g. 5208 or shirt" class="grow"
            @keyup.enter="lookupHsn" />
          <Button label="Search" :loading="hsnLoading" @click="lookupHsn" />
        </div>

        <DataTable :value="hsnRows" :loading="hsnLoading" class="p-datatable-sm" style="margin-top: 0.75rem">
          <Column field="code" header="Code" />
          <Column field="description" header="Description" />
          <Column header="GST %"><template #body="{ data }">{{ data.gst_rate }}%</template></Column>
          <Column field="uqc" header="UQC" />
          <template #empty>Search the HSN master above.</template>
        </DataTable>
      </section>
    </div>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1.25rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.25rem; }
.card { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 12px; padding: 1.25rem; }
.card h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: 0.25rem; }
.hint { color: #6b7280; font-size: 0.85rem; margin-bottom: 0.75rem; }
.row { display: flex; gap: 0.5rem; align-items: center; }
.grow { flex: 1; }
.result { margin-top: 1rem; }
.errors { color: #dc2626; font-size: 0.85rem; margin: 0.5rem 0; padding-left: 1.1rem; }
.decoded { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-top: 0.75rem; }
.decoded dt { font-size: 0.75rem; color: #6b7280; }
.decoded dd { font-weight: 600; font-size: 0.95rem; margin: 0.15rem 0 0; }
</style>
