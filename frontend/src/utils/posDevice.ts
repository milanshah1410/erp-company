/**
 * Per-terminal POS hardware configuration and the customer-display channel.
 *
 * Hardware settings are intentionally kept in localStorage, not on the server:
 * the paper width, print path, drawer wiring and second screen belong to the
 * physical counter this browser runs on, not to the tenant. Two terminals in
 * the same shop can therefore be configured independently.
 */

export type PaperWidth = '58' | '80'
export type PrintMode = 'browser' | 'agent'

export interface PosDeviceSettings {
  /** Thermal roll width in millimetres — drives receipt column widths. */
  paperWidth: PaperWidth
  /**
   * How receipts reach the printer:
   *  - 'browser': render an HTML receipt and use the OS print dialog (works
   *    everywhere, but cannot kick a cash drawer).
   *  - 'agent':  POST raw ESC/POS bytes to a local print-agent HTTP endpoint,
   *    which can also fire the drawer-kick pulse.
   */
  printMode: PrintMode
  /** Local print-agent endpoint, e.g. http://localhost:8080/print (agent mode). */
  agentUrl: string
  /** Kick the cash drawer after a cash sale (agent mode only). */
  openDrawerOnCash: boolean
  /** Print the receipt automatically when a sale/return/exchange completes. */
  autoPrint: boolean
  /** Short beep when a barcode scan resolves to a product. */
  beepOnScan: boolean
  /** Extra header lines under the shop name (address, GSTIN, phone…). */
  headerLines: string
  /** Footer note printed at the bottom of every receipt. */
  footerNote: string
}

const STORAGE_KEY = 'pos_device_settings'

export const DEFAULT_DEVICE_SETTINGS: PosDeviceSettings = {
  paperWidth: '80',
  printMode: 'browser',
  agentUrl: 'http://localhost:8080/print',
  openDrawerOnCash: true,
  autoPrint: true,
  beepOnScan: true,
  headerLines: '',
  footerNote: 'Thank you! Visit again.',
}

export function loadDeviceSettings(): PosDeviceSettings {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return { ...DEFAULT_DEVICE_SETTINGS }
    return { ...DEFAULT_DEVICE_SETTINGS, ...JSON.parse(raw) }
  } catch {
    return { ...DEFAULT_DEVICE_SETTINGS }
  }
}

export function saveDeviceSettings(settings: PosDeviceSettings): void {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(settings))
}

// --- Customer display channel -------------------------------------------------

export const CUSTOMER_DISPLAY_CHANNEL = 'pos-customer-display'

export interface CustomerDisplayState {
  shopName: string
  currencySymbol: string
  lines: { name: string; qty: number; unit_price: number; line_total: number }[]
  subtotal: number
  discount: number
  tax: number
  total: number
  /** Populated only while tendering / after a sale completes. */
  paid?: number
  balance?: number
  change?: number
  status: 'idle' | 'selling' | 'done'
  message?: string
}

/** Create a BroadcastChannel, or null in the rare browser without support. */
export function createDisplayChannel(): BroadcastChannel | null {
  if (typeof BroadcastChannel === 'undefined') return null
  return new BroadcastChannel(CUSTOMER_DISPLAY_CHANNEL)
}
