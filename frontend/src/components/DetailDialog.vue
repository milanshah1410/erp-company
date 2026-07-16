<script setup lang="ts">
// Reusable read-only "View" dialog. Give it the selected record and a list of
// field definitions ({label, key, format?, full?}) and it renders a clean
// definition-list. Dotted keys (e.g. 'customer.name') and format callbacks are
// supported; booleans default to Yes/No and empty values show as an em dash.
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

export interface DetailField {
  label: string
  key: string
  format?: (value: any, row: Record<string, any>) => string
  full?: boolean // span both columns (long text)
}

const props = defineProps<{
  visible: boolean
  title: string
  record: Record<string, any> | null
  fields: DetailField[]
  width?: string
}>()

const emit = defineEmits<{ (e: 'update:visible', value: boolean): void }>()

function resolve(row: Record<string, any>, key: string): any {
  return key.split('.').reduce<any>((o, k) => (o == null ? o : o[k]), row)
}

function display(field: DetailField, row: Record<string, any>): string {
  const raw = resolve(row, field.key)
  if (field.format) return field.format(raw, row)
  if (raw === null || raw === undefined || raw === '') return '—'
  if (typeof raw === 'boolean') return raw ? 'Yes' : 'No'
  return String(raw)
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="emit('update:visible', $event)"
    modal
    :header="title"
    :style="{ width: width || '540px' }"
  >
    <div v-if="record" class="detail-grid">
      <div v-for="f in fields" :key="f.key" class="detail-row" :class="{ full: f.full }">
        <span class="detail-label">{{ f.label }}</span>
        <span class="detail-value">{{ display(f, record) }}</span>
      </div>
    </div>
    <template #footer>
      <Button label="Close" text @click="emit('update:visible', false)" />
    </template>
  </Dialog>
</template>

<style scoped>
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem 1.5rem;
  padding-top: 0.5rem;
}
.detail-row {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.detail-row.full {
  grid-column: 1 / -1;
}
.detail-label {
  font-size: 0.75rem;
  font-weight: 500;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.detail-value {
  font-size: 0.95rem;
  color: var(--graphite);
  word-break: break-word;
}
</style>
