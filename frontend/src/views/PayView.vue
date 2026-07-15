<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import Button from 'primevue/button'

/**
 * Public, unauthenticated hosted pay page (SRS §16). Reached at /pay/:token —
 * the customer opens a payment link, picks an enabled gateway, and is sent to
 * that gateway's hosted checkout (redirect) or an auto-submitted signed form
 * (form_post). The gateway returns the browser to the API callback, which
 * reconciles and bounces back here with ?status=… so we can show the outcome.
 *
 * Deliberately uses a bare axios client (no bearer token / 401 redirect): this
 * page has no logged-in user.
 */
const route = useRoute()
const token = route.params.token as string

const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

const loading = ref(true)
const starting = ref('')
const error = ref('')
const txn = ref<any>(null)
const returnedStatus = ref<string>((route.query.status as string) || '')

const status = computed<string>(() => txn.value?.status ?? '')
const isPaid = computed(() => status.value === 'paid')
const isTerminal = computed(() => ['paid', 'failed', 'expired', 'cancelled'].includes(status.value))
const gateways = computed<any[]>(() => txn.value?.gateways ?? [])

const gatewayIcon: Record<string, string> = {
  razorpay: 'pi pi-bolt',
  cashfree: 'pi pi-indian-rupee',
  phonepe: 'pi pi-mobile',
  payu: 'pi pi-credit-card',
  ccavenue: 'pi pi-shield',
  stripe: 'pi pi-globe',
}

function money(amount: any, currency: string): string {
  const n = Number(amount ?? 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'INR' }).format(n)
  } catch {
    return `${currency} ${n.toFixed(2)}`
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/pay/${token}`)
    txn.value = data.data
  } catch (e: any) {
    error.value = e.response?.status === 404
      ? 'This payment link is invalid or no longer exists.'
      : (e.response?.data?.message || 'Could not load this payment link.')
  } finally {
    loading.value = false
  }
}

async function pay(gateway: string) {
  starting.value = gateway
  error.value = ''
  try {
    const { data } = await api.post(`/pay/${token}/start`, { gateway })
    const res = data.data
    if (res.flow === 'redirect' && res.url) {
      window.location.href = res.url
    } else if (res.flow === 'form_post' && res.action) {
      submitForm(res.action, res.fields || {})
    } else {
      error.value = 'This payment method could not be started. Please try another.'
      starting.value = ''
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Could not start the payment. Please try again.'
    starting.value = ''
  }
}

/** Build a hidden form and POST it to the gateway (PayU / CCAvenue flow). */
function submitForm(action: string, fields: Record<string, string>) {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = action
  for (const [key, value] of Object.entries(fields)) {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = key
    input.value = String(value)
    form.appendChild(input)
  }
  document.body.appendChild(form)
  form.submit()
}

onMounted(load)
</script>

<template>
  <div class="pay-wrap">
    <div class="pay-card">
      <div class="brand">
        <i class="pi pi-lock" />
        <span>Secure Payment</span>
      </div>

      <div v-if="loading" class="state">Loading…</div>

      <div v-else-if="error && !txn" class="state error">
        <i class="pi pi-times-circle big" />
        <p>{{ error }}</p>
      </div>

      <template v-else-if="txn">
        <p class="to">Pay to</p>
        <h1 class="company">{{ txn.company_name || 'Merchant' }}</h1>
        <div class="amount">{{ money(txn.amount, txn.currency) }}</div>
        <p v-if="txn.invoice_number" class="inv">Invoice {{ txn.invoice_number }}</p>

        <!-- Result states -->
        <div v-if="isPaid" class="result success">
          <i class="pi pi-check-circle big" />
          <p>Payment received. Thank you!</p>
        </div>
        <div v-else-if="status === 'expired'" class="result error">
          <i class="pi pi-clock big" />
          <p>This payment link has expired. Please ask for a new one.</p>
        </div>
        <div v-else-if="isTerminal" class="result error">
          <i class="pi pi-times-circle big" />
          <p>The payment did not go through. You can try again below.</p>
        </div>

        <!-- Gateway choices (only when still payable) -->
        <template v-if="!isPaid && status !== 'expired'">
          <div v-if="returnedStatus === 'failed'" class="notice">Your last attempt failed — please try again.</div>

          <div v-if="gateways.length" class="methods">
            <p class="choose">Choose a payment method</p>
            <button
              v-for="g in gateways" :key="g.gateway"
              class="method" :disabled="!!starting"
              @click="pay(g.gateway)"
            >
              <i :class="gatewayIcon[g.gateway] || 'pi pi-wallet'" />
              <span>{{ g.label }}</span>
              <i v-if="starting === g.gateway" class="pi pi-spin pi-spinner spin" />
              <i v-else class="pi pi-angle-right arrow" />
            </button>
          </div>
          <div v-else class="state">
            No online payment methods are available right now. Please contact the merchant.
          </div>

          <p v-if="error" class="err-line">{{ error }}</p>
        </template>

        <div v-if="isPaid || status === 'expired'" class="footer-actions">
          <Button label="Refresh" icon="pi pi-refresh" text @click="load" />
        </div>
      </template>
    </div>

    <p class="disclaimer">Payments are processed securely by the selected provider.</p>
  </div>
</template>

<style scoped>
.pay-wrap {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  background: #f1f5f9;
  padding: 1.5rem;
}
.pay-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 2rem 1.75rem;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
  text-align: center;
}
.brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  color: #64748b;
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  margin-bottom: 1.25rem;
}
.to { color: #6b7280; font-size: 0.85rem; margin-bottom: 0.15rem; }
.company { font-size: 1.25rem; font-weight: 700; margin-bottom: 0.75rem; }
.amount { font-size: 2.1rem; font-weight: 800; color: #0f172a; }
.inv { color: #6b7280; font-size: 0.85rem; margin-top: 0.25rem; }
.state { color: #6b7280; font-size: 0.9rem; padding: 1.5rem 0; }
.state.error { color: #b91c1c; }
.big { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; }
.result { margin-top: 1.25rem; padding: 1rem; border-radius: 10px; font-weight: 600; }
.result.success { background: #ecfdf5; color: #047857; }
.result.error { background: #fef2f2; color: #b91c1c; }
.methods { margin-top: 1.5rem; text-align: left; }
.choose { font-size: 0.85rem; color: #374151; font-weight: 600; margin-bottom: 0.6rem; }
.method {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.85rem 1rem;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 0.95rem;
  font-weight: 600;
  color: #1f2937;
  margin-bottom: 0.6rem;
  transition: border-color 0.15s, background 0.15s;
}
.method:hover:not(:disabled) { border-color: #2563eb; background: #eff6ff; }
.method:disabled { opacity: 0.6; cursor: default; }
.method .arrow, .method .spin { margin-left: auto; color: #9ca3af; }
.notice { background: #fffbeb; color: #92400e; font-size: 0.82rem; padding: 0.6rem 0.75rem; border-radius: 8px; margin-top: 1rem; }
.err-line { color: #b91c1c; font-size: 0.82rem; margin-top: 0.75rem; }
.footer-actions { margin-top: 1rem; }
.disclaimer { color: #94a3b8; font-size: 0.75rem; }
</style>
