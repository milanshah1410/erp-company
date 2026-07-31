<script setup lang="ts">
// Drop-in "scan with the device camera" button for any screen that otherwise
// expects a handheld/USB barcode reader. Opens a dialog, decodes with the
// webcam (or the phone's rear camera) and emits the decoded string — the host
// screen handles it exactly as if the reader had typed it.
//
// `continuous` keeps the dialog open so several labels can be scanned in a row
// (POS counter flow); otherwise the dialog closes on the first hit.
//
// Note: browsers only grant camera access on https:// or localhost.
import { ref, computed, nextTick, onBeforeUnmount, useId } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { useToast } from 'primevue/usetoast'
import { usePt } from '../i18n/pt'

const props = withDefaults(
  defineProps<{
    // Defaults to "Camera"; pass an empty string for an icon-only button.
    label?: string
    icon?: string
    outlined?: boolean
    text?: boolean
    rounded?: boolean
    severity?: string
    size?: 'small' | 'large'
    // Stay open and keep decoding instead of closing after the first code.
    continuous?: boolean
  }>(),
  { icon: 'pi pi-camera', outlined: true, continuous: false },
)

const emit = defineEmits<{ (e: 'scan', code: string): void }>()

const toast = useToast()
const pt = usePt()

const buttonLabel = computed(() => (props.label === '' ? undefined : (props.label ?? pt('Camera'))))

const visible = ref(false)
const readerId = `camera-reader-${useId()}`
let scanner: any = null
// A label held in front of the lens decodes many times a second; ignore repeats.
let lastDecoded = { code: '', at: 0 }

async function open() {
  visible.value = true
  await nextTick()
  const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode')
  scanner = new Html5Qrcode(readerId)
  try {
    await scanner.start(
      { facingMode: 'environment' },
      {
        fps: 10,
        qrbox: { width: 250, height: 150 },
        formatsToSupport: [
          Html5QrcodeSupportedFormats.QR_CODE,
          Html5QrcodeSupportedFormats.CODE_128,
          Html5QrcodeSupportedFormats.EAN_13,
          Html5QrcodeSupportedFormats.EAN_8,
          Html5QrcodeSupportedFormats.UPC_A,
          Html5QrcodeSupportedFormats.CODE_39,
          Html5QrcodeSupportedFormats.ITF,
        ],
      },
      onDecoded,
      () => {
        // ignore per-frame decode misses
      },
    )
  } catch (e: any) {
    toast.add({
      severity: 'error',
      summary: pt('Camera error'),
      detail: e?.message || pt('Could not start camera'),
      life: 4000,
    })
    visible.value = false
  }
}

function onDecoded(code: string) {
  const now = Date.now()
  if (code === lastDecoded.code && now - lastDecoded.at < 1500) return
  lastDecoded = { code, at: now }
  if (props.continuous) {
    emit('scan', code)
  } else {
    close()
    emit('scan', code)
  }
}

async function close() {
  if (scanner) {
    try {
      await scanner.stop()
      scanner.clear()
    } catch {
      // scanner may already be stopped
    }
    scanner = null
  }
  lastDecoded = { code: '', at: 0 }
  visible.value = false
}

onBeforeUnmount(() => {
  if (scanner) close()
})

defineExpose({ open, close })
</script>

<template>
  <Button
    :icon="props.icon"
    :label="buttonLabel"
    :outlined="props.outlined"
    :text="props.text"
    :rounded="props.rounded"
    :severity="props.severity"
    :size="props.size"
    :title="pt('Scan with device camera')"
    @click="open"
  />

  <Dialog v-model:visible="visible" modal :header="pt('Scan with Camera')" :style="{ width: '420px' }" @hide="close">
    <div :id="readerId" class="camera-reader"></div>
    <p class="camera-hint">
      {{
        props.continuous
          ? pt('Hold a barcode or QR label in front of the camera. Codes keep adding until you close this.')
          : pt('Hold a barcode or QR label in front of the camera.')
      }}
    </p>
    <template #footer>
      <Button :label="props.continuous ? pt('Done') : pt('Cancel')" text @click="close" />
    </template>
  </Dialog>
</template>

<style scoped>
.camera-reader {
  width: 100%;
}
.camera-hint {
  margin-top: 0.6rem;
  font-size: 0.8rem;
  color: #6b7280;
}
</style>
