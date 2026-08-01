<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../../api/http'
import AuthShell from '../../components/auth/AuthShell.vue'
import type { AuthStep } from '../../components/auth/types'

const route = useRoute()
const router = useRouter()

const STEPS: AuthStep[] = [
  { key: 'package', label: 'Package', hint: 'Plan, trade and modules' },
  { key: 'company', label: 'Company', hint: 'Name and workspace address' },
  { key: 'account', label: 'Account', hint: 'Owner login and defaults' },
  { key: 'verify', label: 'Verify email', hint: 'Six-digit code' },
]

const registrationId = ref<string>(String(route.query.registration ?? ''))
const token = ref<string>(String(route.query.token ?? ''))
const error = ref('')
const submitting = ref(false)
const resent = ref(false)

// One box per digit: easier to read back off a phone screen than a single field,
// and it makes a pasted code land correctly without the user thinking about it.
const digits = ref<string[]>(['', '', '', '', '', ''])
const boxes = ref<HTMLInputElement[]>([])
const code = computed(() => digits.value.join(''))

// Resend is rate-limited client-side too, so the button can't be hammered while
// the first mail is still in flight.
const COOLDOWN = 45
const cooldown = ref(0)
let ticker: ReturnType<typeof setInterval> | undefined

function fill(value: string) {
  const clean = value.replace(/\D/g, '').slice(0, 6).split('')
  digits.value = Array.from({ length: 6 }, (_, i) => clean[i] ?? '')
}

function onInput(i: number, e: Event) {
  const el = e.target as HTMLInputElement
  const typed = el.value.replace(/\D/g, '')

  // A paste (or an autofilled OTP) arrives as several characters at once.
  if (typed.length > 1) {
    const rest = typed.slice(0, 6 - i).split('')
    rest.forEach((d, k) => (digits.value[i + k] = d))
    el.value = digits.value[i]
    focusBox(Math.min(i + rest.length, 5))
    maybeAutoSubmit()
    return
  }

  digits.value[i] = typed
  el.value = typed
  if (typed && i < 5) focusBox(i + 1)
  maybeAutoSubmit()
}

function onKeydown(i: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !digits.value[i] && i > 0) {
    focusBox(i - 1)
    digits.value[i - 1] = ''
  }
  if (e.key === 'ArrowLeft' && i > 0) focusBox(i - 1)
  if (e.key === 'ArrowRight' && i < 5) focusBox(i + 1)
}

function focusBox(i: number) {
  nextTick(() => boxes.value[i]?.focus())
}

/** Six digits in and nothing pending? Just verify — no extra click needed. */
function maybeAutoSubmit() {
  if (code.value.length === 6 && !submitting.value) submit()
}

function startCooldown() {
  cooldown.value = COOLDOWN
  clearInterval(ticker)
  ticker = setInterval(() => {
    if (--cooldown.value <= 0) clearInterval(ticker)
  }, 1000)
}

onMounted(() => {
  // A magic-link visit (?token=…) verifies immediately.
  if (token.value && registrationId.value) {
    submit()
    return
  }
  // Locally the API hands back the code so the flow is testable without SMTP.
  const dev = String(route.query.dev_code ?? '')
  if (dev) fill(dev)
  focusBox(0)
})

onBeforeUnmount(() => clearInterval(ticker))

async function submit() {
  submitting.value = true
  error.value = ''
  try {
    const payload: Record<string, unknown> = { registration_id: Number(registrationId.value) }
    if (token.value) payload.token = token.value
    else payload.code = code.value
    await http.post('/verify-email', payload)
    router.push({ name: 'central-provisioning', query: { registration: registrationId.value } })
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Verification failed.'
    digits.value = ['', '', '', '', '', '']
    focusBox(0)
  } finally {
    submitting.value = false
  }
}

async function resend() {
  if (cooldown.value > 0) return
  error.value = ''
  try {
    const { data } = await http.post('/resend-verification', { registration_id: Number(registrationId.value) })
    resent.value = true
    startCooldown()
    if (data.dev_code) fill(data.dev_code)
  } catch {
    error.value = 'Could not resend the code.'
  }
}
</script>

<template>
  <AuthShell
    :steps="STEPS"
    :current="4"
    eyebrow="Almost there"
    headline="One code stands between you and your workspace."
    blurb="Verifying your email is what starts provisioning — we build the company, roles, menus and dashboard the moment it clears."
  >
    <div class="center">
      <div class="glyph"><i class="pi pi-envelope" /></div>
      <h1>Check your inbox</h1>
      <p class="sub">Enter the 6-digit code we just emailed you.</p>
    </div>

    <form @submit.prevent="submit">
      <div class="otp" :class="{ shake: error }">
        <input
          v-for="(d, i) in digits"
          :key="i"
          :ref="(el) => { if (el) boxes[i] = el as HTMLInputElement }"
          :value="d"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          :aria-label="`Digit ${i + 1}`"
          :class="{ filled: !!d }"
          @input="onInput(i, $event)"
          @keydown="onKeydown(i, $event)"
          @focus="($event.target as HTMLInputElement).select()"
        />
      </div>

      <p v-if="error" class="err"><i class="pi pi-exclamation-circle" /> {{ error }}</p>

      <button class="submit" type="submit" :disabled="code.length < 6 || submitting">
        <i v-if="submitting" class="pi pi-spin pi-spinner" />
        {{ submitting ? 'Verifying…' : 'Verify & build my workspace' }}
      </button>
    </form>

    <p class="resend">
      Didn’t get it?
      <button type="button" :disabled="cooldown > 0" @click="resend">
        {{ cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend code' }}
      </button>
      <span v-if="resent && cooldown > 0" class="ok"><i class="pi pi-check" /> Sent</span>
    </p>
    <p class="tip">Check spam if it hasn’t arrived within a minute.</p>
  </AuthShell>
</template>

<style scoped>
.center {
  text-align: center;
}
.glyph {
  width: 56px;
  height: 56px;
  margin: 0 auto 0.9rem;
  border-radius: 16px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #f2c274, #e8a33d 55%, #c98730);
  box-shadow: 0 10px 24px rgba(232, 163, 61, 0.32);
}
.glyph i {
  font-size: 1.5rem;
  color: #1e2328;
}
h1 {
  font-size: 1.4rem;
  letter-spacing: -0.01em;
}
.sub {
  margin: 0.4rem 0 0;
  color: #6b7280;
  font-size: 0.9rem;
}

.otp {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.5rem;
  margin: 1.6rem 0 0;
}
.otp input {
  width: 100%;
  height: 56px;
  text-align: center;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 1.4rem;
  font-weight: 700;
  color: #1e2328;
  border: 1px solid #ddd7ca;
  border-radius: 12px;
  background: #fbfaf7;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s, transform 0.1s;
}
.otp input.filled {
  border-color: #dcc79a;
  background: #fff;
}
.otp input:focus {
  border-color: #e8a33d;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.18);
  transform: translateY(-1px);
}
.otp.shake {
  animation: shake 0.35s ease;
}
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  75% { transform: translateX(5px); }
}
@media (prefers-reduced-motion: reduce) {
  .otp.shake {
    animation: none;
  }
}

.err {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  color: #c1502e;
  font-size: 0.82rem;
  margin: 0.85rem 0 0;
}
.submit {
  width: 100%;
  margin-top: 1.3rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 10px;
  padding: 0.8rem;
  font-family: inherit;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(201, 135, 48, 0.28);
  transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
}
.submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(201, 135, 48, 0.34);
}
.submit:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  box-shadow: none;
}
.resend {
  margin: 1.4rem 0 0;
  text-align: center;
  font-size: 0.84rem;
  color: #6b7280;
}
.resend button {
  background: none;
  border: none;
  font-family: inherit;
  font-size: 0.84rem;
  color: #c98730;
  font-weight: 700;
  cursor: pointer;
  padding: 0 0.15rem;
}
.resend button:disabled {
  color: #a8a295;
  cursor: default;
  font-weight: 600;
}
.ok {
  color: #4c8b6c;
  margin-left: 0.3rem;
  font-weight: 600;
}
.tip {
  margin: 0.45rem 0 0;
  text-align: center;
  font-size: 0.75rem;
  color: #a8a295;
}

@media (max-width: 420px) {
  .otp {
    gap: 0.35rem;
  }
  .otp input {
    height: 48px;
    font-size: 1.15rem;
  }
}
</style>
