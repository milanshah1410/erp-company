<script setup lang="ts">
import { ref, computed, nextTick, onBeforeUnmount } from 'vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { openPdfBlob } from '../utils/blob'
import { formatCurrency } from '../utils/currency'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const canAssignRfid = computed(() => auth.can('products.edit'))
const scanValue = ref('')
const scanInput = ref<any>(null)
const loading = ref(false)
const result = ref<any>(null)
const notFound = ref(false)

async function lookup(code: string) {
  const value = code.trim()
  if (!value) return
  loading.value = true
  notFound.value = false
  result.value = null
  try {
    const { data } = await http.get('/barcode/lookup', { params: { code: value } })
    result.value = data
    rfidValue.value = ''
  } catch (e: any) {
    if (e.response?.status === 404) {
      notFound.value = true
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Lookup failed'), life: 4000 })
    }
  } finally {
    loading.value = false
    scanValue.value = ''
    await nextTick()
    scanInput.value?.$el?.focus()
  }
}

function onEnter() {
  lookup(scanValue.value)
}

// --- Camera scanning (webcam / phone browser) ---
const cameraVisible = ref(false)
let scanner: any = null

async function openCamera() {
  cameraVisible.value = true
  await nextTick()
  const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode')
  scanner = new Html5Qrcode('camera-reader')
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
        ],
      },
      (decodedText: string) => {
        closeCamera()
        lookup(decodedText)
      },
      () => {
        // ignore per-frame decode misses
      },
    )
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Camera error'), detail: e?.message || pt('Could not start camera'), life: 4000 })
    cameraVisible.value = false
  }
}

async function closeCamera() {
  if (scanner) {
    try {
      await scanner.stop()
      scanner.clear()
    } catch {
      // scanner may already be stopped
    }
    scanner = null
  }
  cameraVisible.value = false
}

onBeforeUnmount(() => {
  if (scanner) closeCamera()
})

// --- Quick print for the matched item ---
const printQty = ref(1)
const printLabelType = ref('code128')
const printing = ref(false)

async function printLabel() {
  if (!result.value) return
  const item = result.value.type === 'variant'
    ? { type: 'variant', id: result.value.variant.id, quantity: printQty.value }
    : { type: 'product', id: result.value.product.id, quantity: printQty.value }

  printing.value = true
  try {
    const { data } = await http.post(
      '/barcode/print-labels',
      { label_type: printLabelType.value, items: [item] },
      { responseType: 'blob' },
    )
    openPdfBlob(data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not generate labels'), life: 4000 })
  } finally {
    printing.value = false
  }
}

// --- Assign an RFID tag to the matched item ---
const rfidValue = ref('')
const rfidSaving = ref(false)

const matchedRfid = computed(() =>
  result.value?.type === 'variant' ? result.value.variant.rfid_tag : result.value?.product?.rfid_tag,
)

async function assignRfid() {
  const tag = rfidValue.value.trim()
  if (!tag || !result.value) return
  const payload = result.value.type === 'variant'
    ? { type: 'variant', id: result.value.variant.id, rfid_tag: tag }
    : { type: 'product', id: result.value.product.id, rfid_tag: tag }

  rfidSaving.value = true
  try {
    const { data } = await http.post('/rfid/assign', payload)
    if (result.value.type === 'variant') result.value.variant.rfid_tag = data.rfid_tag
    else result.value.product.rfid_tag = data.rfid_tag
    rfidValue.value = ''
    toast.add({ severity: 'success', summary: pt('RFID assigned'), detail: data.rfid_tag, life: 2500 })
  } catch (e: any) {
    const msg = e.response?.data?.errors?.rfid_tag?.[0] || e.response?.data?.message || pt('Could not assign RFID tag')
    toast.add({ severity: 'error', summary: pt('Error'), detail: msg, life: 4000 })
  } finally {
    rfidSaving.value = false
  }
}

function money(v: any) {
  return v === null || v === undefined ? '—' : formatCurrency(v)
}
</script>

<template>
  <div>
    <PageHeader :title="pt('Barcode / RFID Scan')" :subtitle="pt('Scan with a handheld/USB barcode or RFID reader (both type into the field below) or use your device camera.')" />

    <div class="scan-box">
      <span class="scan-input">
        <i class="pi pi-search" />
        <InputText ref="scanInput" v-model="scanValue" :placeholder="pt('Scan or type a barcode / SKU / RFID tag, then press Enter')"
          autofocus @keyup.enter="onEnter" />
      </span>
      <Button :label="pt('Lookup')" icon="pi pi-search" :loading="loading" @click="onEnter" />
      <Button :label="pt('Use Camera')" icon="pi pi-camera" outlined @click="openCamera" />
    </div>

    <div v-if="notFound" class="not-found">
      <i class="pi pi-exclamation-triangle" /> {{ pt('No product or variant matches that code.') }}
    </div>

    <div v-if="result" class="result-card">
      <div class="result-header">
        <h2 v-if="result.type === 'variant'">{{ result.product.name }} — {{ result.variant.name || result.variant.sku }}</h2>
        <h2 v-else>{{ result.product.name }}</h2>
      </div>
      <div class="result-grid">
        <div><label>{{ pt('SKU') }}</label><span>{{ result.type === 'variant' ? result.variant.sku : result.product.sku }}</span></div>
        <div><label>{{ pt('Barcode') }}</label><span>{{ result.type === 'variant' ? result.variant.barcode : result.product.barcode }}</span></div>
        <div><label>{{ pt('RFID Tag') }}</label><span>{{ matchedRfid || '—' }}</span></div>
        <div><label>{{ pt('Category') }}</label><span>{{ result.product.category || '—' }}</span></div>
        <div><label>{{ pt('Brand') }}</label><span>{{ result.product.brand || '—' }}</span></div>
        <div>
          <label>{{ pt('Selling Price') }}</label>
          <span>{{ money(result.type === 'variant' ? result.variant.selling_price : result.product.selling_price) }}</span>
        </div>
        <div v-if="result.type === 'product'"><label>{{ pt('Total Stock') }}</label><span>{{ result.product.total_stock ?? '—' }}</span></div>
      </div>

      <div class="print-row">
        <Select v-model="printLabelType" :options="[{ label: pt('CODE128 Barcode'), value: 'code128' }, { label: pt('QR Code'), value: 'qr' }]"
          optionLabel="label" optionValue="value" />
        <InputNumber v-model="printQty" :min="1" :max="500" showButtons />
        <Button :label="pt('Print Label')" icon="pi pi-print" :loading="printing" @click="printLabel" />
      </div>

      <div v-if="canAssignRfid" class="rfid-row">
        <label class="rfid-label">{{ matchedRfid ? pt('Reassign RFID Tag') : pt('Assign RFID Tag') }}</label>
        <div class="rfid-controls">
          <InputText v-model="rfidValue" :placeholder="pt('Scan or type RFID tag, then press Enter')" @keyup.enter="assignRfid" />
          <Button :label="pt('Assign')" icon="pi pi-wifi" :loading="rfidSaving" :disabled="!rfidValue.trim()" @click="assignRfid" />
        </div>
      </div>
    </div>

    <!-- Camera scan modal -->
    <Dialog v-model:visible="cameraVisible" modal :header="pt('Scan with Camera')" :style="{ width: '420px' }" @hide="closeCamera">
      <div id="camera-reader"></div>
      <template #footer>
        <Button :label="pt('Cancel')" text @click="closeCamera" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.scan-box {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 1.25rem;
}
.scan-input {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1;
  max-width: 460px;
}
.scan-input i {
  position: absolute;
  left: 0.75rem;
  color: #9ca3af;
}
.scan-input :deep(input) {
  width: 100%;
  padding-left: 2.25rem;
}
.not-found {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: #fef3c7;
  color: #92400e;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  margin-bottom: 1rem;
}
.result-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1.25rem;
  max-width: 640px;
}
.result-header h2 {
  font-size: 1.15rem;
  font-weight: 600;
  margin-bottom: 0.75rem;
}
.result-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}
.result-grid label {
  display: block;
  font-size: 0.75rem;
  color: #6b7280;
}
.result-grid span {
  font-size: 0.95rem;
  color: #111827;
}
.print-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
}
.rfid-row {
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
  margin-top: 1rem;
}
.rfid-label {
  display: block;
  font-size: 0.75rem;
  color: #6b7280;
  margin-bottom: 0.4rem;
}
.rfid-controls {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.rfid-controls :deep(input) {
  flex: 1;
}
#camera-reader {
  width: 100%;
}
</style>
