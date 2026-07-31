<script setup lang="ts">
/**
 * Printer Testing — sample prints of the three shipping print templates so a
 * shop can verify its hardware before billing on it:
 *
 *   - A4 Invoice     → server-rendered PDF (documents.invoice) on sample data
 *   - Thermal Invoice→ the live POS receipt renderer (utils/posReceipt.ts), so
 *                      the browser and raw ESC/POS agent paths, the roll width
 *                      and the cash-drawer kick are all exercised for real
 *   - Barcode Label  → server-rendered label sheet (labels.print)
 *
 * Nothing is persisted: the A4/label endpoints build throw-away models, and the
 * thermal receipt is assembled in the browser.
 */
import { ref, computed, onBeforeUnmount, watch } from 'vue'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { openPdfBlob, downloadBlob } from '../utils/blob'
import {
  loadDeviceSettings,
  saveDeviceSettings,
  DEFAULT_DEVICE_SETTINGS,
  type PosDeviceSettings,
} from '../utils/posDevice'
import { buildReceiptHtml, printReceipt, openCashDrawer, type ReceiptData } from '../utils/posReceipt'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const pt = usePt()
const auth = useAuthStore()

const TEMPLATES = computed(() => [
  {
    value: 'a4',
    label: pt('A4 Invoice'),
    icon: 'pi pi-file-pdf',
    desc: pt('Full-page tax invoice on a laser/inkjet printer.'),
  },
  {
    value: 'thermal',
    label: pt('Thermal Invoice'),
    icon: 'pi pi-receipt',
    desc: pt('58/80 mm POS receipt via browser or ESC/POS agent.'),
  },
  {
    value: 'label',
    label: pt('Barcode Label'),
    icon: 'pi pi-barcode',
    desc: pt('Label sheet for the barcode/label printer.'),
  },
])

const active = ref<'a4' | 'thermal' | 'label'>('a4')

const shopName = computed(() => auth.user?.current_company?.name || pt('Your Company'))
const symbol = computed(() => auth.currencySymbol)

// ---- Inline PDF preview (shared by the A4 and label tests) ------------------
const previewUrl = ref<string | null>(null)
const busy = ref(false)

function setPreview(blob: Blob | null) {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = blob ? URL.createObjectURL(blob) : null
}

onBeforeUnmount(() => setPreview(null))
// The two PDF tests share one preview frame — drop a stale one when switching.
watch(active, () => setPreview(null))

/** POST to a printer-test endpoint and hand the PDF blob to `then`. */
async function fetchPdf(path: string, body: Record<string, unknown>, then: (b: Blob) => void) {
  busy.value = true
  try {
    const { data } = await http.post(path, body, { responseType: 'blob' })
    then(data)
  } catch {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not generate the test print.'), life: 4000 })
  } finally {
    busy.value = false
  }
}

// ---- A4 invoice --------------------------------------------------------------
const a4 = ref({ paper: 'a4', lines: 5 })

const PAPER_SIZES = computed(() => [
  { label: pt('A4 (210 × 297 mm)'), value: 'a4' },
  { label: pt('Letter (216 × 279 mm)'), value: 'letter' },
  { label: pt('A5 (148 × 210 mm)'), value: 'a5' },
])

function a4Body(extra: Record<string, unknown> = {}) {
  return { paper: a4.value.paper, lines: a4.value.lines, ...extra }
}

const previewA4 = () => fetchPdf('/printer-test/a4-invoice', a4Body(), setPreview)
const printA4 = () => fetchPdf('/printer-test/a4-invoice', a4Body(), openPdfBlob)
const downloadA4 = () =>
  fetchPdf('/printer-test/a4-invoice', a4Body({ download: 1 }), (b) => downloadBlob(b, 'printer-test-a4-invoice.pdf'))

// ---- Thermal invoice ---------------------------------------------------------
// Terminal hardware settings live in localStorage (per counter, not per tenant),
// so the test edits the very same record POS billing prints with.
const device = ref<PosDeviceSettings>(loadDeviceSettings())

function saveDevice() {
  saveDeviceSettings(device.value)
  toast.add({ severity: 'success', summary: pt('Printer settings saved'), life: 2500 })
}

function resetDevice() {
  device.value = { ...DEFAULT_DEVICE_SETTINGS }
}

/** A representative sale: mixed quantities, discounts, split tender, change due. */
function sampleReceipt(): ReceiptData {
  const lines = [
    { name: 'Cotton Shirting Fabric', qty: 2.5, unit_price: 240, line_total: 600 },
    { name: 'Brass Buttons — Antique', qty: 12, unit_price: 3.2, line_total: 38.4 },
    { name: 'Embroidery Thread Box', qty: 1, unit_price: 1250, line_total: 1250 },
  ]
  const subtotal = 1888.4
  return {
    title: pt('PRINTER TEST'),
    docNumber: 'TEST-0001',
    date: new Date().toLocaleString(),
    cashier: auth.user?.name ?? 'Test User',
    customer: 'Printer Test Customer',
    lines,
    subtotal,
    itemDiscount: 38.4,
    invoiceDiscount: 50,
    tax: 90,
    roundOff: 0.6,
    total: 1890,
    payments: [
      { mode: 'cash', amount: 1500 },
      { mode: 'upi', amount: 500 },
    ],
    paid: 2000,
    change: 110,
  }
}

const receiptHtml = computed(() => buildReceiptHtml(sampleReceipt(), device.value, shopName.value, symbol.value))

async function printThermal() {
  busy.value = true
  try {
    await printReceipt(sampleReceipt(), device.value, {
      shopName: shopName.value,
      symbol: symbol.value,
      hasCash: true,
    })
    if (device.value.printMode === 'agent') {
      toast.add({ severity: 'success', summary: pt('Sent to print agent'), life: 2500 })
    }
  } catch {
    toast.add({
      severity: 'warn',
      summary: pt('Print failed'),
      detail: pt('Could not reach the printer/agent.'),
      life: 4000,
    })
  } finally {
    busy.value = false
  }
}

async function testDrawer() {
  try {
    const ok = await openCashDrawer(device.value)
    if (!ok) {
      toast.add({
        severity: 'info',
        summary: pt('Cash drawer'),
        detail: pt('Drawer kick needs the local print agent.'),
        life: 4000,
      })
    }
  } catch {
    toast.add({ severity: 'warn', summary: pt('Could not reach the printer/agent.'), life: 4000 })
  }
}

// ---- Barcode label -----------------------------------------------------------
const label = ref({
  template: 'barcode',
  label_type: 'code128',
  count: 12,
  value: '8901234567890',
  show_company: true,
  show_price: true,
})

const LABEL_TEMPLATES = computed(() => [
  { label: pt('Barcode Printing'), value: 'barcode', symbology: 'code128' as string | null },
  { label: pt('QR Label'), value: 'qr', symbology: 'qr' as string | null },
  { label: pt('Product Sticker'), value: 'sticker', symbology: null as string | null },
  { label: pt('Price Tag'), value: 'price_tag', symbology: null as string | null },
])

// barcode/qr imply their symbology; sticker/price_tag let the user choose.
const allowsSymbologyChoice = computed(
  () => LABEL_TEMPLATES.value.find((t) => t.value === label.value.template)?.symbology === null,
)
const showsPriceOption = computed(() => ['sticker', 'price_tag'].includes(label.value.template))

const previewLabels = () => fetchPdf('/printer-test/labels', { ...label.value }, setPreview)
const printLabels = () => fetchPdf('/printer-test/labels', { ...label.value }, openPdfBlob)
const downloadLabels = () =>
  fetchPdf('/printer-test/labels', { ...label.value }, (b) => downloadBlob(b, 'printer-test-labels.pdf'))
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Printer Testing')"
      :subtitle="pt('Send a sample print of each template to check paper size, margins and alignment. Test prints are never saved as documents.')"
    />

    <!-- Template picker -->
    <div class="templates">
      <button
        v-for="t in TEMPLATES"
        :key="t.value"
        class="template-card"
        :class="{ active: active === t.value }"
        type="button"
        @click="active = t.value as any"
      >
        <i :class="t.icon" />
        <span class="tpl-label">{{ t.label }}</span>
        <span class="tpl-desc">{{ t.desc }}</span>
      </button>
    </div>

    <div class="layout">
      <!-- ---------- Options ---------- -->
      <div class="panel">
        <!-- A4 invoice -->
        <template v-if="active === 'a4'">
          <h2>{{ pt('A4 Invoice') }}</h2>
          <p class="hint">{{ pt('Prints the same tax-invoice template real invoices use, filled with sample data.') }}</p>
          <div class="fld">
            <label>{{ pt('Paper Size') }}</label>
            <Select v-model="a4.paper" :options="PAPER_SIZES" optionLabel="label" optionValue="value" class="w-full" />
          </div>
          <div class="fld">
            <label>{{ pt('Sample Line Items') }}</label>
            <InputNumber v-model="a4.lines" :min="1" :max="60" showButtons buttonLayout="horizontal" class="w-full" />
            <small class="muted">{{ pt('Raise this past a page to test page breaks and the repeated footer.') }}</small>
          </div>
          <div class="actions">
            <Button :label="pt('Preview')" icon="pi pi-eye" outlined :loading="busy" @click="previewA4" />
            <Button :label="pt('Print')" icon="pi pi-print" :loading="busy" @click="printA4" />
            <Button :label="pt('Download')" icon="pi pi-download" text :loading="busy" @click="downloadA4" />
          </div>
        </template>

        <!-- Thermal invoice -->
        <template v-else-if="active === 'thermal'">
          <h2>{{ pt('Thermal Invoice') }}</h2>
          <p class="hint">{{ pt('These are this terminal\'s printer settings — POS billing prints with exactly the same ones.') }}</p>
          <div class="fld">
            <label>{{ pt('Paper Width') }}</label>
            <Select
              v-model="device.paperWidth"
              :options="[{ label: '58 mm', value: '58' }, { label: '80 mm', value: '80' }]"
              optionLabel="label"
              optionValue="value"
              class="w-full"
            />
          </div>
          <div class="fld">
            <label>{{ pt('Print Method') }}</label>
            <Select
              v-model="device.printMode"
              :options="[
                { label: pt('Browser print dialog'), value: 'browser' },
                { label: pt('Local print agent (raw ESC/POS)'), value: 'agent' },
              ]"
              optionLabel="label"
              optionValue="value"
              class="w-full"
            />
            <small class="muted">{{ pt('The cash drawer can only be kicked via a local print agent.') }}</small>
          </div>
          <div class="fld" v-if="device.printMode === 'agent'">
            <label>{{ pt('Print Agent URL') }}</label>
            <InputText v-model="device.agentUrl" class="w-full" placeholder="http://localhost:8080/print" />
          </div>
          <div class="fld">
            <label>{{ pt('Receipt Header Lines (address, GSTIN, phone)') }}</label>
            <Textarea v-model="device.headerLines" rows="3" class="w-full" autoResize />
          </div>
          <div class="fld">
            <label>{{ pt('Footer Note') }}</label>
            <InputText v-model="device.footerNote" class="w-full" />
          </div>
          <div class="actions">
            <Button :label="pt('Test Print')" icon="pi pi-print" :loading="busy" @click="printThermal" />
            <Button :label="pt('Test Cash Drawer')" icon="pi pi-inbox" outlined @click="testDrawer" />
          </div>
          <div class="actions">
            <Button :label="pt('Save Settings')" icon="pi pi-check" text @click="saveDevice" />
            <Button :label="pt('Reset to defaults')" icon="pi pi-refresh" text severity="secondary" @click="resetDevice" />
          </div>
        </template>

        <!-- Barcode label -->
        <template v-else>
          <h2>{{ pt('Barcode Label') }}</h2>
          <p class="hint">{{ pt('Prints a sheet of identical sample labels so label pitch and cut lines can be checked.') }}</p>
          <div class="fld">
            <label>{{ pt('Label Format') }}</label>
            <Select v-model="label.template" :options="LABEL_TEMPLATES" optionLabel="label" optionValue="value" class="w-full" />
          </div>
          <div class="fld" v-if="allowsSymbologyChoice">
            <label>{{ pt('Code Type') }}</label>
            <Select
              v-model="label.label_type"
              :options="[{ label: pt('CODE128 Barcode'), value: 'code128' }, { label: pt('QR Code'), value: 'qr' }]"
              optionLabel="label"
              optionValue="value"
              class="w-full"
            />
          </div>
          <div class="fld">
            <label>{{ pt('Sample Code Value') }}</label>
            <InputText v-model="label.value" class="w-full" placeholder="8901234567890" />
          </div>
          <div class="fld">
            <label>{{ pt('Number of Labels') }}</label>
            <InputNumber v-model="label.count" :min="1" :max="200" showButtons buttonLayout="horizontal" class="w-full" />
          </div>
          <label class="chk"><input type="checkbox" v-model="label.show_company" /> {{ pt('Show company name') }}</label>
          <label class="chk" v-if="showsPriceOption"><input type="checkbox" v-model="label.show_price" /> {{ pt('Show price') }}</label>
          <div class="actions">
            <Button :label="pt('Preview')" icon="pi pi-eye" outlined :loading="busy" @click="previewLabels" />
            <Button :label="pt('Print')" icon="pi pi-print" :loading="busy" @click="printLabels" />
            <Button :label="pt('Download')" icon="pi pi-download" text :loading="busy" @click="downloadLabels" />
          </div>
        </template>
      </div>

      <!-- ---------- Preview ---------- -->
      <div class="panel preview-panel">
        <h2>{{ pt('Preview') }}</h2>

        <!-- The thermal receipt renders live at true roll width. -->
        <div v-if="active === 'thermal'" class="roll" :class="`roll-${device.paperWidth}`">
          <iframe :srcdoc="receiptHtml" title="receipt preview" />
        </div>

        <template v-else>
          <iframe v-if="previewUrl" class="pdf" :src="previewUrl" title="pdf preview" />
          <p v-else class="hint empty">{{ pt('Hit Preview to render the template here, or Print to open it in a new tab.') }}</p>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.templates {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}
.template-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 0.85rem 1rem;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.template-card:hover { border-color: var(--safety-amber-light, #f2c274); }
.template-card.active { border-color: var(--safety-amber, #e8a33d); box-shadow: 0 0 0 1px var(--safety-amber, #e8a33d) inset; }
.template-card i { font-size: 1.15rem; color: var(--safety-amber, #e8a33d); }
.tpl-label { font-weight: 600; font-size: 0.92rem; }
.tpl-desc { font-size: 0.75rem; color: #6b7280; }

.layout {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 1.25rem;
  align-items: start;
}
@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
}
.panel {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem;
}
.panel h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: 0.35rem; }
.hint { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.9rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.85rem; }
.fld label { font-size: 0.8rem; color: #374151; }
.muted { color: #6b7280; font-size: 0.75rem; }
.chk { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #374151; margin-bottom: 0.5rem; }
.actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
.w-full { width: 100%; }
.w-full :deep(input) { width: 100%; }

.preview-panel { min-height: 22rem; }
.pdf { width: 100%; height: 34rem; border: 1px solid var(--border, #e8e4da); border-radius: 8px; background: #f8f8f8; }
.empty { margin: 3rem 0; text-align: center; }

/* The receipt frame is held at the physical roll width so the preview shows
   the same line wrapping the printer will produce. */
.roll {
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  padding: 0.5rem;
  background: #fafafa;
  display: flex;
  justify-content: center;
}
.roll iframe { border: 0; background: #fff; height: 32rem; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12); }
.roll-58 iframe { width: 58mm; }
.roll-80 iframe { width: 80mm; }
</style>
