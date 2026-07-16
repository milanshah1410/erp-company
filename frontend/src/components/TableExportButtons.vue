<script setup lang="ts">
// Drop-in Export (CSV) + Print buttons for any list screen. Pages through the
// given endpoint (honouring current search/filter params) and builds the file
// client-side, so no server-side export endpoint is required. If `rows` is
// provided instead of an endpoint, it exports those rows directly.
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { useTableExport, type ExportColumn } from '../composables/useTableExport'

const props = defineProps<{
  columns: ExportColumn[]
  title: string
  endpoint?: string
  // Reactive query params (search/filters). Pass a getter for live values.
  params?: Record<string, any> | (() => Record<string, any>)
  // Alternatively, export an already-loaded row set (no fetching).
  rows?: Record<string, any>[]
  filenameBase?: string
  size?: 'small' | 'large'
  // Restrict to a single button when the screen already has its own print
  // (e.g. an order/invoice PDF) or export action. Defaults to showing both.
  only?: 'export' | 'print'
}>()

const toast = useToast()
const { exporting, exportCsv, printRows, fetchAll } = useTableExport()

function resolvedParams(): Record<string, any> {
  return typeof props.params === 'function' ? props.params() : props.params ?? {}
}

async function collect(): Promise<Record<string, any>[]> {
  if (props.endpoint) return fetchAll(props.endpoint, resolvedParams())
  return props.rows ?? []
}

function fileBase(): string {
  return props.filenameBase ?? props.title.replace(/\s+/g, '-').toLowerCase()
}

async function onExport() {
  try {
    exportCsv(props.columns, await collect(), fileBase())
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Export failed', detail: e.response?.data?.message || 'Could not export', life: 4000 })
  }
}

async function onPrint() {
  try {
    printRows(props.title, props.columns, await collect())
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Print failed', detail: e.response?.data?.message || 'Could not print', life: 4000 })
  }
}
</script>

<template>
  <span class="table-export-buttons no-print">
    <Button v-if="only !== 'print'" label="Export" icon="pi pi-download" outlined :size="size" :loading="exporting" @click="onExport" />
    <Button v-if="only !== 'export'" label="Print" icon="pi pi-print" outlined :size="size" :loading="exporting" @click="onPrint" />
  </span>
</template>

<style scoped>
.table-export-buttons {
  display: inline-flex;
  gap: 0.5rem;
}
</style>
