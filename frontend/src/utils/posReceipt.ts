/**
 * Thermal receipt rendering + delivery to the printer / cash drawer.
 *
 * Two delivery paths, chosen per terminal (see posDevice.ts):
 *  - browser: build an HTML receipt sized to the roll width and drive the OS
 *    print dialog through a hidden iframe. Universally available.
 *  - agent:   build raw ESC/POS text and POST it to a local print-agent, which
 *    talks to the printer directly and can pulse the cash-drawer kick line.
 */
import type { PosDeviceSettings } from './posDevice'

export interface ReceiptLine {
  name: string
  qty: number
  unit_price: number
  line_total: number
}

export interface ReceiptData {
  title: string
  docNumber: string
  /** The invoice behind this receipt, so the counter can still collect on it. */
  invoiceId?: number
  soNumber?: string
  date: string
  cashier?: string
  customer?: string
  lines: ReceiptLine[]
  subtotal: number
  itemDiscount?: number
  invoiceDiscount?: number
  tax: number
  roundOff?: number
  total: number
  payments?: { mode: string; amount: number }[]
  paid?: number
  balance?: number
  change?: number
  /** Extra key/value rows (e.g. exchange net payable/refund). */
  extra?: { label: string; value: string }[]
}

function money(v: number, symbol: string): string {
  return `${symbol}${(Number(v) || 0).toFixed(2)}`
}

/** Column count for the monospace ESC/POS layout by paper width. */
function cols(settings: PosDeviceSettings): number {
  return settings.paperWidth === '58' ? 32 : 48
}

// --- HTML receipt (browser print) --------------------------------------------

export function buildReceiptHtml(
  data: ReceiptData,
  settings: PosDeviceSettings,
  shopName: string,
  symbol: string,
): string {
  const width = settings.paperWidth === '58' ? '58mm' : '80mm'
  const esc = (s: string) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c] as string))
  const header = (settings.headerLines || '')
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => `<div class="c small">${esc(l)}</div>`)
    .join('')

  const itemRows = data.lines
    .map(
      (l) => `
      <tr class="item">
        <td class="nm" colspan="3">${esc(l.name)}</td>
      </tr>
      <tr class="item">
        <td class="qty">${l.qty} × ${money(l.unit_price, symbol)}</td>
        <td></td>
        <td class="amt">${money(l.line_total, symbol)}</td>
      </tr>`,
    )
    .join('')

  const totalRow = (label: string, value: string, cls = '') =>
    `<tr class="${cls}"><td colspan="2">${esc(label)}</td><td class="amt">${esc(value)}</td></tr>`

  const totals: string[] = [totalRow('Subtotal', money(data.subtotal, symbol))]
  if (data.itemDiscount) totals.push(totalRow('Item Discount', '−' + money(data.itemDiscount, symbol)))
  if (data.invoiceDiscount) totals.push(totalRow('Invoice Discount', '−' + money(data.invoiceDiscount, symbol)))
  totals.push(totalRow('GST', money(data.tax, symbol)))
  if (data.roundOff) totals.push(totalRow('Round Off', money(data.roundOff, symbol)))
  totals.push(totalRow('TOTAL', money(data.total, symbol), 'grand'))

  const pays = (data.payments || []).map((p) => totalRow(p.mode.toUpperCase(), money(p.amount, symbol))).join('')
  const extra = (data.extra || []).map((e) => totalRow(e.label, e.value)).join('')

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(data.docNumber)}</title>
<style>
  @page { size: ${width} auto; margin: 0; }
  * { box-sizing: border-box; }
  body { width: ${width}; margin: 0; padding: 3mm; font-family: 'Courier New', monospace; font-size: 11px; color: #000; }
  .c { text-align: center; }
  .small { font-size: 10px; }
  .shop { font-size: 15px; font-weight: 700; }
  .title { font-weight: 700; margin: 4px 0; letter-spacing: 1px; }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 1px 0; }
  .amt { text-align: right; white-space: nowrap; }
  .nm { font-weight: 600; }
  .qty { color: #000; }
  .grand td { font-size: 13px; font-weight: 700; border-top: 1px solid #000; padding-top: 3px; }
  .meta { font-size: 10px; }
  .meta div { display: flex; justify-content: space-between; }
  .foot { margin-top: 6px; text-align: center; font-size: 10px; }
</style></head><body>
  <div class="c shop">${esc(shopName)}</div>
  ${header}
  <div class="c title">${esc(data.title)}</div>
  <hr>
  <div class="meta">
    <div><span>No:</span><span>${esc(data.docNumber)}</span></div>
    ${data.soNumber ? `<div><span>Ref:</span><span>${esc(data.soNumber)}</span></div>` : ''}
    <div><span>Date:</span><span>${esc(data.date)}</span></div>
    ${data.customer ? `<div><span>Customer:</span><span>${esc(data.customer)}</span></div>` : ''}
    ${data.cashier ? `<div><span>Cashier:</span><span>${esc(data.cashier)}</span></div>` : ''}
  </div>
  <hr>
  <table>${itemRows}</table>
  <hr>
  <table>${totals.join('')}</table>
  ${pays ? '<hr><table>' + pays + '</table>' : ''}
  ${
    data.balance || data.change
      ? '<table>' +
        (data.change ? totalRow('Change', money(data.change, symbol)) : '') +
        (data.balance ? totalRow('Balance Due', money(data.balance, symbol)) : '') +
        '</table>'
      : ''
  }
  ${extra ? '<hr><table>' + extra + '</table>' : ''}
  <hr>
  <div class="foot">${esc(settings.footerNote || '')}</div>
</body></html>`
}

// --- Raw ESC/POS (agent print) -----------------------------------------------

const ESC = '\x1B'
const GS = '\x1D'
const INIT = ESC + '@'
const ALIGN_CENTER = ESC + 'a\x01'
const ALIGN_LEFT = ESC + 'a\x00'
const BOLD_ON = ESC + 'E\x01'
const BOLD_OFF = ESC + 'E\x00'
const DOUBLE_ON = GS + '!\x11'
const DOUBLE_OFF = GS + '!\x00'
const CUT = GS + 'V\x42\x00'
/** Standard drawer-kick: ESC p m t1 t2 (pin 0, 25ms on, 250ms off). */
export const DRAWER_KICK = ESC + 'p\x00\x19\xFA'

function padRow(left: string, right: string, width: number): string {
  const space = Math.max(1, width - left.length - right.length)
  if (left.length + right.length >= width) {
    return (left + ' ' + right).slice(0, width) + '\n'
  }
  return left + ' '.repeat(space) + right + '\n'
}

export function buildReceiptEscPos(
  data: ReceiptData,
  settings: PosDeviceSettings,
  shopName: string,
  symbol: string,
): string {
  const w = cols(settings)
  const dash = '-'.repeat(w) + '\n'
  let out = INIT + ALIGN_CENTER + BOLD_ON + DOUBLE_ON + shopName + '\n' + DOUBLE_OFF + BOLD_OFF

  ;(settings.headerLines || '')
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .forEach((l) => (out += l + '\n'))

  out += BOLD_ON + data.title + '\n' + BOLD_OFF + ALIGN_LEFT + dash
  out += padRow('No: ' + data.docNumber, data.date, w)
  if (data.customer) out += 'Customer: ' + data.customer + '\n'
  if (data.cashier) out += 'Cashier: ' + data.cashier + '\n'
  out += dash

  data.lines.forEach((l) => {
    out += l.name + '\n'
    out += padRow(`  ${l.qty} x ${money(l.unit_price, symbol)}`, money(l.line_total, symbol), w)
  })
  out += dash
  out += padRow('Subtotal', money(data.subtotal, symbol), w)
  if (data.itemDiscount) out += padRow('Item Discount', '-' + money(data.itemDiscount, symbol), w)
  if (data.invoiceDiscount) out += padRow('Invoice Discount', '-' + money(data.invoiceDiscount, symbol), w)
  out += padRow('GST', money(data.tax, symbol), w)
  if (data.roundOff) out += padRow('Round Off', money(data.roundOff, symbol), w)
  out += BOLD_ON + padRow('TOTAL', money(data.total, symbol), w) + BOLD_OFF
  ;(data.payments || []).forEach((p) => (out += padRow(p.mode.toUpperCase(), money(p.amount, symbol), w)))
  if (data.change) out += padRow('Change', money(data.change, symbol), w)
  if (data.balance) out += padRow('Balance Due', money(data.balance, symbol), w)
  ;(data.extra || []).forEach((e) => (out += padRow(e.label, e.value, w)))
  out += dash + ALIGN_CENTER + (settings.footerNote || '') + '\n\n'
  return out
}

// --- Delivery ----------------------------------------------------------------

let printFrame: HTMLIFrameElement | null = null

function browserPrint(html: string): void {
  if (!printFrame) {
    printFrame = document.createElement('iframe')
    printFrame.style.position = 'fixed'
    printFrame.style.right = '0'
    printFrame.style.bottom = '0'
    printFrame.style.width = '0'
    printFrame.style.height = '0'
    printFrame.style.border = '0'
    document.body.appendChild(printFrame)
  }
  const doc = printFrame.contentWindow?.document
  if (!doc) return
  doc.open()
  doc.write(html)
  doc.close()
  const win = printFrame.contentWindow!
  // Give the layout a tick before invoking the print dialog.
  setTimeout(() => {
    win.focus()
    win.print()
  }, 250)
}

async function agentSend(url: string, payload: string): Promise<void> {
  await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain; charset=x-user-defined' },
    body: payload,
  })
}

export interface PrintContext {
  shopName: string
  symbol: string
  /** Whether the tender was fully/partly cash — drives the drawer kick. */
  hasCash?: boolean
}

/**
 * Print a receipt through whichever path the terminal is configured for, and
 * kick the drawer when appropriate. Resolves on dispatch; throws only for a
 * hard agent failure so the caller can surface it.
 */
export async function printReceipt(
  data: ReceiptData,
  settings: PosDeviceSettings,
  ctx: PrintContext,
): Promise<void> {
  if (settings.printMode === 'agent') {
    let payload = buildReceiptEscPos(data, settings, ctx.shopName, ctx.symbol)
    if (settings.openDrawerOnCash && ctx.hasCash) payload += DRAWER_KICK
    payload += CUT
    await agentSend(settings.agentUrl, payload)
    return
  }
  browserPrint(buildReceiptHtml(data, settings, ctx.shopName, ctx.symbol))
}

/** Fire the cash-drawer kick on its own (agent mode only). */
export async function openCashDrawer(settings: PosDeviceSettings): Promise<boolean> {
  if (settings.printMode !== 'agent') return false
  await agentSend(settings.agentUrl, DRAWER_KICK)
  return true
}
