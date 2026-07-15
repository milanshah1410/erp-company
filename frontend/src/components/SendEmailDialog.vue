<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'

const props = defineProps<{
  visible: boolean
  endpoint: string        // e.g. /email/quotations/5
  title?: string
  defaultTo?: string | null
  defaultSubject?: string
  defaultMessage?: string
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  sent: []
}>()

const toast = useToast()
const sending = ref(false)
const form = ref<{ to: string; cc: string; subject: string; message: string }>({
  to: '', cc: '', subject: '', message: '',
})
const errors = ref<Record<string, string[]>>({})

watch(
  () => props.visible,
  (open) => {
    if (open) {
      form.value = {
        to: props.defaultTo ?? '',
        cc: '',
        subject: props.defaultSubject ?? '',
        message: props.defaultMessage ?? '',
      }
      errors.value = {}
    }
  },
)

function close() {
  emit('update:visible', false)
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function send() {
  sending.value = true
  errors.value = {}
  try {
    const { data } = await http.post(props.endpoint, {
      to: form.value.to || null,
      cc: form.value.cc || null,
      subject: form.value.subject || null,
      message: form.value.message || null,
    })
    toast.add({ severity: 'success', summary: 'Email', detail: data.message ?? 'Email queued.', life: 3500 })
    emit('sent')
    close()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Send failed', detail: e.response?.data?.message ?? 'Unexpected error', life: 4500 })
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible" modal :header="title ?? 'Send Email'" :style="{ width: '560px' }"
    @update:visible="emit('update:visible', $event)"
  >
    <div class="form-grid">
      <div class="fld">
        <label>To <span class="req">*</span></label>
        <InputText v-model="form.to" placeholder="recipient@example.com" :class="{ invalid: fieldError('to') }" />
        <small v-if="fieldError('to')" class="error-text">{{ fieldError('to') }}</small>
      </div>
      <div class="fld">
        <label>CC <small class="muted">(comma-separated)</small></label>
        <InputText v-model="form.cc" placeholder="optional" :class="{ invalid: fieldError('cc') }" />
        <small v-if="fieldError('cc')" class="error-text">{{ fieldError('cc') }}</small>
      </div>
      <div class="fld">
        <label>Subject</label>
        <InputText v-model="form.subject" :class="{ invalid: fieldError('subject') }" />
        <small v-if="fieldError('subject')" class="error-text">{{ fieldError('subject') }}</small>
      </div>
      <div class="fld">
        <label>Message</label>
        <Textarea v-model="form.message" rows="5" autoResize :class="{ invalid: fieldError('message') }" />
        <small v-if="fieldError('message')" class="error-text">{{ fieldError('message') }}</small>
      </div>
      <p class="attach-note"><i class="pi pi-paperclip" /> The document PDF is attached automatically.</p>
    </div>
    <template #footer>
      <Button label="Cancel" text @click="close" />
      <Button label="Send" icon="pi pi-send" :loading="sending" @click="send" />
    </template>
  </Dialog>
</template>

<style scoped>
.form-grid { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(textarea) { width: 100%; }
.muted { color: #9ca3af; font-weight: 400; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext), :deep(.invalid) { border-color: #dc2626 !important; }
.attach-note { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: #6b7280; margin: 0; }
</style>
