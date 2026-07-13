<script setup lang="ts">
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'

const dateFrom = defineModel<Date | null>('dateFrom', { default: null })
const dateTo = defineModel<Date | null>('dateTo', { default: null })

defineProps<{ exporting?: boolean; showDateRange?: boolean }>()
const emit = defineEmits<{ refresh: []; export: [format: 'csv' | 'xlsx' | 'pdf'] }>()
</script>

<template>
  <div class="report-toolbar">
    <template v-if="showDateRange !== false">
      <DatePicker v-model="dateFrom" placeholder="From date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
      <DatePicker v-model="dateTo" placeholder="To date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
    </template>
    <slot />
    <Button label="Refresh" icon="pi pi-refresh" outlined @click="emit('refresh')" />
    <span class="spacer" />
    <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="emit('export', 'csv')" />
    <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="emit('export', 'xlsx')" />
    <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="emit('export', 'pdf')" />
  </div>
</template>

<style scoped>
.report-toolbar {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.spacer {
  flex: 1;
}
</style>
