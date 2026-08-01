<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import http from '../api/http'
import { openPdfBlob } from '../utils/blob'
import PageHeader from '../components/PageHeader.vue'

interface Plan {
  slug: string
  name: string
  price: number
  currency: string
  is_custom: boolean
  feature_limits: Record<string, unknown>
}
interface Sub {
  status: string
  plan: { slug: string; name: string; price: number; feature_limits: Record<string, unknown> }
  on_trial: boolean
  trial_ends_at: string | null
  trial_days_left: number
  current_period_end: string | null
}
interface SubInvoice {
  id: number
  number: string
  plan: string | null
  amount: number
  currency: string
  status: string
  paid_at: string | null
  refund_amount: number | null
  refunded_at: string | null
  created_at: string
}

const sub = ref<Sub | null>(null)
const plans = ref<Plan[]>([])
const invoices = ref<SubInvoice[]>([])
const loading = ref(true)
const changing = ref('')
const message = ref('')
const pdfBusy = ref(0)

async function load() {
  loading.value = true
  try {
    const [{ data: s }, { data: p }, { data: inv }] = await Promise.all([
      http.get('/subscription'),
      http.get('/subscription/plans'),
      http.get('/subscription/invoices'),
    ])
    sub.value = s.data
    plans.value = p.data
    invoices.value = inv.data
  } finally {
    loading.value = false
  }
}

function fmtDate(v: string | null): string {
  return v ? new Date(v).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

async function viewInvoice(inv: SubInvoice) {
  pdfBusy.value = inv.id
  try {
    const { data } = await http.get(`/subscription/invoices/${inv.id}/pdf`, { responseType: 'blob' })
    openPdfBlob(new Blob([data], { type: 'application/pdf' }))
  } finally {
    pdfBusy.value = 0
  }
}

async function downloadInvoice(inv: SubInvoice) {
  pdfBusy.value = inv.id
  try {
    const { data } = await http.get(`/subscription/invoices/${inv.id}/pdf`, {
      params: { download: 1 },
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `subscription-invoice-${inv.number}.pdf`
    a.click()
    setTimeout(() => window.URL.revokeObjectURL(url), 60_000)
  } finally {
    pdfBusy.value = 0
  }
}

declare global {
  interface Window {
    Razorpay?: new (options: Record<string, unknown>) => RazorpayInstance
  }
}
interface RazorpayInstance {
  open: () => void
  on: (event: string, cb: (resp: any) => void) => void
}
interface CheckoutResponse {
  razorpay_payment_id: string
  razorpay_subscription_id: string
  razorpay_signature: string
}

let checkoutScript: Promise<boolean> | null = null

/** Load Razorpay's checkout.js once; resolves false if the CDN is unreachable. */
function loadCheckoutScript(): Promise<boolean> {
  if (window.Razorpay) return Promise.resolve(true)
  if (!checkoutScript) {
    checkoutScript = new Promise((resolve) => {
      const s = document.createElement('script')
      s.src = 'https://checkout.razorpay.com/v1/checkout.js'
      s.onload = () => resolve(true)
      s.onerror = () => resolve(false)
      document.head.appendChild(s)
    })
  }
  return checkoutScript
}

async function confirmPayment(resp: CheckoutResponse) {
  try {
    const { data } = await http.post('/subscription/confirm-payment', {
      razorpay_payment_id: resp.razorpay_payment_id,
      razorpay_subscription_id: resp.razorpay_subscription_id,
      razorpay_signature: resp.razorpay_signature,
    })
    message.value = data.message
  } catch (e: any) {
    message.value =
      e.response?.data?.message ||
      'Could not confirm the payment. If you were charged, your plan will activate automatically within a few minutes.'
  }
  await load()
}

/** Open the in-app Razorpay Checkout modal; returns false if the script failed to load. */
async function openCheckout(data: {
  razorpay_key: string
  razorpay_subscription_id: string
  plan_name?: string
  prefill?: Record<string, string | null>
}): Promise<boolean> {
  const ok = await loadCheckoutScript()
  if (!ok || !window.Razorpay) return false

  const rzp = new window.Razorpay({
    key: data.razorpay_key,
    subscription_id: data.razorpay_subscription_id,
    name: 'Kosha',
    description: data.plan_name ? `${data.plan_name} plan subscription` : 'Plan subscription',
    prefill: data.prefill ?? {},
    theme: { color: '#e8a33d' },
    handler: (resp: CheckoutResponse) => {
      message.value = 'Verifying payment…'
      void confirmPayment(resp)
    },
    modal: {
      ondismiss: () => {
        message.value = 'Payment window closed — your plan has not changed. Pick a plan again whenever you are ready.'
      },
    },
  })
  rzp.on('payment.failed', (resp: any) => {
    message.value = resp?.error?.description
      ? `Payment failed: ${resp.error.description}`
      : 'Payment failed. Please try again or use a different method.'
  })
  rzp.open()
  return true
}

async function changePlan(slug: string) {
  changing.value = slug
  message.value = ''
  try {
    const { data } = await http.post('/subscription/change-plan', { plan_slug: slug })
    if (data.razorpay_subscription_id && data.razorpay_key) {
      const opened = await openCheckout(data)
      if (!opened) {
        // Checkout script unavailable — fall back to Razorpay's hosted page.
        message.value = data.message
        if (data.short_url) {
          window.open(data.short_url, '_blank')
          startActivationPolling()
        }
      }
    } else {
      message.value = data.message
    }
    await load()
  } catch (e: any) {
    message.value = e.response?.data?.message || 'Could not change plan.'
  } finally {
    changing.value = ''
  }
}

const paying = ref(false)
let pollTimer: number | undefined

function stopActivationPolling() {
  if (pollTimer) {
    window.clearInterval(pollTimer)
    pollTimer = undefined
  }
}

/**
 * Hosted Razorpay pages (dues invoice / short_url fallback) can't call us
 * back, so after opening one we watch the subscription until the webhook
 * flips it to active (checked every 5s for up to 2 minutes).
 */
function startActivationPolling() {
  stopActivationPolling()
  let attempts = 0
  pollTimer = window.setInterval(async () => {
    attempts++
    try {
      const { data } = await http.get('/subscription')
      if (data.data?.status === 'active') {
        stopActivationPolling()
        message.value = 'Payment received — your subscription is active again.'
        await load()
        return
      }
    } catch {
      /* transient — keep polling */
    }
    if (attempts >= 24) stopActivationPolling()
  }, 5000)
}

onUnmounted(stopActivationPolling)

/** Clear past_due/suspended dues without going through a plan change. */
async function payNow() {
  paying.value = true
  message.value = ''
  try {
    const { data } = await http.post('/subscription/pay-now')
    if (data.razorpay_subscription_id && data.razorpay_key) {
      // Nothing left to retry on the old subscription — fresh in-app checkout.
      const opened = await openCheckout(data)
      if (!opened && data.short_url) {
        message.value = data.message
        window.open(data.short_url, '_blank')
        startActivationPolling()
      }
    } else if (data.payment_url) {
      // Open dues invoice on Razorpay's hosted page; webhook reactivates us.
      message.value = data.message
      window.open(data.payment_url, '_blank')
      startActivationPolling()
    } else {
      message.value = data.message
      await load()
    }
  } catch (e: any) {
    message.value = e.response?.data?.message || 'Could not start the payment.'
  } finally {
    paying.value = false
  }
}

function limitLabel(v: unknown): string {
  return v === null || v === undefined ? 'Unlimited' : String(v)
}

onMounted(load)
</script>

<template>
  <div class="billing">
    <PageHeader title="Billing &amp; Plan" subtitle="Manage your subscription and usage limits" />

    <div v-if="loading" class="empty">Loading…</div>

    <template v-else>
      <div v-if="sub" class="current" :class="sub.status">
        <div>
          <div class="label">Current plan</div>
          <div class="plan-name">{{ sub.plan.name }}</div>
        </div>
        <div class="state">
          <span class="badge" :class="sub.status">{{ sub.status }}</span>
          <span v-if="sub.on_trial" class="trial">{{ sub.trial_days_left }} days left in trial</span>
        </div>
      </div>

      <div v-if="sub && (sub.status === 'past_due' || sub.status === 'suspended')" class="due-banner">
        <div>
          <div class="due-title">
            <i class="pi pi-exclamation-triangle" />
            {{ sub.status === 'past_due' ? 'Payment overdue' : 'Subscription suspended' }}
          </div>
          <div class="due-text">
            Clear the pending payment to reactivate your workspace — no plan change needed.
          </div>
        </div>
        <button class="pay-now" :disabled="paying" @click="payNow">
          {{ paying ? 'Starting…' : 'Pay Now' }}
        </button>
      </div>

      <div v-if="message" class="notice">{{ message }}</div>

      <div class="barcode-divider" />

      <div class="plan-grid">
        <div v-for="p in plans" :key="p.slug" class="plan-card" :class="{ current: sub?.plan.slug === p.slug }">
          <div class="pc-head">
            <h3>{{ p.name }}</h3>
            <div class="price">
              <template v-if="p.is_custom">Custom</template>
              <template v-else>₹{{ p.price.toLocaleString('en-IN') }}<small>/mo</small></template>
            </div>
          </div>
          <ul class="limits">
            <li><i class="pi pi-box" /> {{ limitLabel(p.feature_limits.max_products) }} products</li>
            <li><i class="pi pi-building" /> {{ limitLabel(p.feature_limits.max_warehouses) }} warehouses</li>
            <li><i class="pi pi-users" /> {{ limitLabel(p.feature_limits.max_users) }} users</li>
            <li :class="{ off: !p.feature_limits.barcode }"><i class="pi pi-qrcode" /> Barcode</li>
            <li :class="{ off: !p.feature_limits.reports }"><i class="pi pi-chart-bar" /> Reports</li>
            <li :class="{ off: !p.feature_limits.api_access }"><i class="pi pi-code" /> API access</li>
          </ul>
          <button
            class="choose"
            :disabled="sub?.plan.slug === p.slug || changing === p.slug"
            @click="changePlan(p.slug)"
          >
            <template v-if="sub?.plan.slug === p.slug">Current plan</template>
            <template v-else-if="changing === p.slug">Switching…</template>
            <template v-else-if="p.is_custom">Contact sales</template>
            <template v-else>Choose {{ p.name }}</template>
          </button>
        </div>
      </div>

      <div class="barcode-divider" />

      <h2 class="section-heading">Invoice History</h2>
      <div v-if="!invoices.length" class="empty">No subscription invoices yet.</div>
      <div v-else class="invoice-table-wrap">
        <table class="invoice-table">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Date</th>
              <th>Plan</th>
              <th class="num">Amount</th>
              <th>Status</th>
              <th>Paid On</th>
              <th class="actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in invoices" :key="inv.id">
              <td class="mono">{{ inv.number }}</td>
              <td>{{ fmtDate(inv.created_at) }}</td>
              <td>{{ inv.plan || '—' }}</td>
              <td class="num">₹{{ inv.amount.toLocaleString('en-IN', { minimumFractionDigits: 2 }) }}</td>
              <td>
                <span class="badge" :class="inv.status">{{ inv.status }}</span>
                <span v-if="inv.refund_amount" class="refund-note">
                  ₹{{ inv.refund_amount.toLocaleString('en-IN') }} refunded
                </span>
              </td>
              <td>{{ fmtDate(inv.paid_at) }}</td>
              <td class="actions">
                <button class="icon-btn" title="View PDF" :disabled="pdfBusy === inv.id" @click="viewInvoice(inv)">
                  <i class="pi pi-eye" />
                </button>
                <button class="icon-btn" title="Download PDF" :disabled="pdfBusy === inv.id" @click="downloadInvoice(inv)">
                  <i class="pi pi-download" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.billing h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.25rem;
}
.current {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fff;
  border: 1px solid #e8e4da;
  border-left: 4px solid #e8a33d;
  border-radius: 12px;
  padding: 1.25rem 1.5rem;
}
.current .label {
  color: #6b7280;
  font-size: 0.8rem;
}
.current .plan-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 1.4rem;
  font-weight: 700;
}
.state {
  text-align: right;
}
.badge {
  display: inline-block;
  padding: 0.15rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: capitalize;
  background: #eef2f4;
  color: #2c4a5e;
}
.badge.trialing {
  background: rgba(232, 163, 61, 0.18);
  color: #a06a27;
}
.badge.active {
  background: rgba(76, 139, 108, 0.18);
  color: #3a6b53;
}
.badge.suspended,
.badge.past_due,
.badge.cancelled {
  background: rgba(193, 80, 46, 0.15);
  color: #c1502e;
}
.trial {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.8rem;
  color: #a06a27;
}
.due-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  margin-top: 1rem;
  background: rgba(193, 80, 46, 0.08);
  border: 1px solid rgba(193, 80, 46, 0.35);
  border-left: 4px solid #c1502e;
  border-radius: 12px;
  padding: 1rem 1.25rem;
}
.due-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 700;
  color: #c1502e;
}
.due-text {
  margin-top: 0.25rem;
  font-size: 0.85rem;
  color: #6b7280;
}
.pay-now {
  background: #c1502e;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 0.6rem 1.4rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.pay-now:hover {
  background: #a84427;
}
.pay-now:disabled {
  opacity: 0.6;
  cursor: default;
}
.notice {
  margin-top: 1rem;
  background: rgba(44, 74, 94, 0.06);
  border: 1px solid #dfe6ea;
  border-radius: 8px;
  padding: 0.7rem 1rem;
  font-size: 0.88rem;
  color: #2c4a5e;
}
.barcode-divider {
  margin: 1.5rem 0;
}
.plan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}
.plan-card {
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
}
.plan-card.current {
  border-color: #e8a33d;
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.pc-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}
.pc-head h3 {
  font-size: 1.05rem;
}
.price {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  color: #1e2328;
}
.price small {
  font-weight: 400;
  color: #9ca3af;
  font-size: 0.7rem;
}
.limits {
  list-style: none;
  padding: 0;
  margin: 1rem 0;
  flex: 1;
}
.limits li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0;
  font-size: 0.85rem;
  color: #374151;
}
.limits li i {
  color: #4c8b6c;
  width: 1rem;
}
.limits li.off {
  color: #b8bdc4;
}
.limits li.off i {
  color: #d1d5db;
}
.choose {
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 8px;
  padding: 0.6rem;
  font-weight: 700;
  cursor: pointer;
}
.choose:disabled {
  background: #eef0f2;
  color: #9ca3af;
  cursor: default;
}
.empty {
  color: #6b7280;
}
.section-heading {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 0.75rem;
}
.invoice-table-wrap {
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  overflow-x: auto;
}
.invoice-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
.invoice-table th {
  text-align: left;
  padding: 0.7rem 1rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #9ca3af;
  border-bottom: 1px solid #e8e4da;
}
.invoice-table td {
  padding: 0.65rem 1rem;
  border-bottom: 1px solid #f1efe9;
  color: #374151;
  white-space: nowrap;
}
.invoice-table tr:last-child td {
  border-bottom: none;
}
.invoice-table .num {
  text-align: right;
}
.invoice-table .mono {
  font-family: 'Space Grotesk', monospace;
  font-weight: 600;
  color: #1e2328;
}
.badge.paid {
  background: rgba(76, 139, 108, 0.18);
  color: #3a6b53;
}
.badge.refunded,
.badge.failed {
  background: rgba(193, 80, 46, 0.15);
  color: #c1502e;
}
.refund-note {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.72rem;
  color: #c1502e;
}
.invoice-table .actions {
  text-align: right;
}
.icon-btn {
  background: none;
  border: 1px solid #e8e4da;
  border-radius: 6px;
  padding: 0.3rem 0.5rem;
  margin-left: 0.35rem;
  cursor: pointer;
  color: #2c4a5e;
}
.icon-btn:hover {
  background: #f6f4ee;
}
.icon-btn:disabled {
  opacity: 0.5;
  cursor: default;
}
</style>
