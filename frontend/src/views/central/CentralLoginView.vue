<script setup lang="ts">
/**
 * Platform (SaaS-operator) console sign-in.
 *
 * Deliberately NOT the AuthShell used by tenant signup: that shell sells a
 * product — free-trial perks, a step rail, a light card. This is the operator
 * door into other people's data, so it reads as a console instead: dark glass,
 * restricted-access notice, no marketing. The shared motifs (gold mark, barcode
 * ruling, amber accent) keep it recognisably the same product.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCentralAuthStore } from '../../stores/centralAuth'
import BrandLockup from '../../components/BrandLockup.vue'

const router = useRouter()
const auth = useCentralAuthStore()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)
const showPassword = ref(false)
// Silent caps lock is the classic cause of a "wrong password" that isn't.
const capsLock = ref(false)

function trackCapsLock(e: KeyboardEvent) {
  capsLock.value = e.getModifierState?.('CapsLock') ?? false
}

async function submit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'central-dashboard' })
  } catch (e: any) {
    error.value =
      e.response?.data?.errors?.email?.[0] ||
      e.response?.data?.message ||
      'Login failed. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="wrap">
    <div class="glow" aria-hidden="true" />
    <div class="ruling" aria-hidden="true" />

    <div class="card">
      <span class="accent" aria-hidden="true" />

      <div class="head">
        <!-- Full lockup, not the bare mark: this door is signed-for. currentColor
             on .head is what tints the sheath and wordmark white. -->
        <BrandLockup :height="46" class="logo" />
        <h1>Platform Console</h1>
        <p>SaaS operations sign-in</p>
      </div>

      <div class="restricted">
        <i class="pi pi-shield" />
        <span>Restricted area — authorized operators only.</span>
      </div>

      <form class="form" @submit.prevent="submit">
        <div class="fld">
          <label for="op-email">Email</label>
          <div class="input-wrap">
            <i class="pi pi-envelope lead" />
            <input
              id="op-email"
              v-model="email"
              type="email"
              placeholder="operator@kosha.com"
              autocomplete="username"
              spellcheck="false"
            />
          </div>
        </div>

        <div class="fld">
          <label for="op-pass">Password</label>
          <div class="input-wrap">
            <i class="pi pi-lock lead" />
            <input
              id="op-pass"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="••••••••"
              autocomplete="current-password"
              @keyup="trackCapsLock"
              @keydown="trackCapsLock"
            />
            <button
              type="button"
              class="peek"
              :title="showPassword ? 'Hide password' : 'Show password'"
              @click="showPassword = !showPassword"
            >
              <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" />
            </button>
          </div>
          <p v-if="capsLock" class="caps"><i class="pi pi-exclamation-triangle" /> Caps Lock is on</p>
        </div>

        <p v-if="error" class="err"><i class="pi pi-times-circle" /> {{ error }}</p>

        <button type="submit" class="submit" :disabled="loading || !email || !password">
          <i v-if="loading" class="pi pi-spin pi-spinner" />
          {{ loading ? 'Signing in…' : 'Sign in to console' }}
          <i v-if="!loading" class="pi pi-arrow-right" />
        </button>
      </form>

      <p class="alt">
        Looking for your own workspace?
        <a href="/login">Sign in here</a>
      </p>
    </div>
  </div>
</template>

<style scoped>
.wrap {
  position: relative;
  min-height: 100vh;
  display: grid;
  /* minmax(0, 1fr), not a bare auto column. `justify-items: center` stops the
     item stretching, so an `auto` track is free to size to the card's
     max-content width and overflow a narrow viewport. A 1fr track is capped by
     the container instead, and the card's own max-width still centres it on
     desktop. Defensive — no overflow was observed, but the auto track is the
     one thing here that could produce one. */
  grid-template-columns: minmax(0, 1fr);
  place-items: center;
  overflow: hidden;
  padding: 1.5rem;
  background:
    radial-gradient(120% 80% at 50% 0%, #2b485c 0%, transparent 55%),
    linear-gradient(165deg, #1c262e 0%, #171a1e 50%, #101315 100%);
}
/* Amber wash from below — the console's only warm note. */
.glow {
  position: absolute;
  left: 50%;
  bottom: -35%;
  width: 120%;
  height: 70%;
  transform: translateX(-50%);
  background: radial-gradient(closest-side, rgba(232, 163, 61, 0.2), transparent 70%);
  filter: blur(8px);
  pointer-events: none;
}
/* Same barcode ruling the signup rail uses, so the two doors feel related. */
.ruling {
  position: absolute;
  inset: 0;
  opacity: 0.04;
  background-image: repeating-linear-gradient(
    90deg,
    #fff 0 1px,
    transparent 1px 4px,
    #fff 4px 6px,
    transparent 6px 11px
  );
  mask-image: radial-gradient(70% 60% at 50% 40%, #000, transparent);
  pointer-events: none;
}

.card {
  position: relative;
  width: 100%;
  max-width: 420px;
  padding: 2.25rem 2rem 1.75rem;
  border-radius: 18px;
  background: rgba(28, 33, 38, 0.78);
  border: 1px solid rgba(255, 255, 255, 0.09);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  box-shadow: 0 1px 0 rgba(255, 255, 255, 0.06) inset, 0 30px 70px rgba(0, 0, 0, 0.55);
  overflow: hidden;
}
/* Hairline of brand gold along the top edge. */
.accent {
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  background: linear-gradient(90deg, transparent, #eaad48 25%, #c98730 75%, transparent);
}

.head {
  text-align: center;
  /* The lockup inherits this — sheath + wordmark in white, core stays amber. */
  color: #fff;
}
.logo {
  margin: 0 auto 1rem;
}
.head h1 {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: #fff;
  margin: 0 0 0.3rem;
}
.head p {
  margin: 0;
  color: #8d979e;
  font-size: 0.85rem;
}

.restricted {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 1.25rem 0 1.35rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid rgba(232, 163, 61, 0.22);
  border-radius: 10px;
  background: rgba(232, 163, 61, 0.07);
  font-size: 0.76rem;
  color: #d8b98c;
}
.restricted i {
  color: #e8a33d;
  font-size: 0.85rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.fld label {
  font-size: 0.8rem;
  font-weight: 600;
  color: #b3bcc2;
}
.input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.input-wrap input {
  width: 100%;
  height: 46px;
  padding: 0 2.6rem 0 2.5rem;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
  color: #e6e8ea;
  font-family: inherit;
  font-size: 0.9rem;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.input-wrap input::placeholder {
  color: #6b7680;
}
.input-wrap input:hover {
  border-color: rgba(255, 255, 255, 0.2);
}
.input-wrap input:focus {
  border-color: #e8a33d;
  background: rgba(255, 255, 255, 0.07);
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.18);
}
/* Chrome paints autofill with an opaque near-white; keep the field dark. */
.input-wrap input:-webkit-autofill,
.input-wrap input:-webkit-autofill:focus {
  -webkit-text-fill-color: #e6e8ea;
  -webkit-box-shadow: 0 0 0 1000px #232a31 inset;
  caret-color: #e6e8ea;
}
.lead {
  position: absolute;
  left: 0.9rem;
  font-size: 0.85rem;
  color: #7c868e;
  pointer-events: none;
}
/* :focus-within, not `input:focus ~ .lead` — the icon precedes the input in the
   DOM, and the sibling combinator only reaches forwards. */
.input-wrap:focus-within .lead {
  color: #e8a33d;
}
.peek {
  position: absolute;
  right: 0.5rem;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 7px;
  background: none;
  color: #7c868e;
  cursor: pointer;
}
.peek:hover {
  color: #d8dde1;
  background: rgba(255, 255, 255, 0.06);
}

.caps,
.err {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.78rem;
}
.caps {
  color: #e8c489;
}
.err {
  padding: 0.6rem 0.75rem;
  border: 1px solid rgba(193, 80, 46, 0.42);
  border-radius: 10px;
  background: rgba(193, 80, 46, 0.14);
  color: #f0a58c;
}

.submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 0.35rem;
  height: 46px;
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  font-family: inherit;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 20px rgba(201, 135, 48, 0.3);
  transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
}
.submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 12px 26px rgba(201, 135, 48, 0.38);
}
.submit:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  box-shadow: none;
}

.alt {
  margin: 1.35rem 0 0;
  padding-top: 1.1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  text-align: center;
  font-size: 0.8rem;
  color: #8d979e;
}
.alt a {
  color: #eaad48;
  font-weight: 600;
  text-decoration: none;
}
.alt a:hover {
  text-decoration: underline;
}

@media (max-width: 480px) {
  .card {
    padding: 1.75rem 1.25rem 1.4rem;
  }
  .head h1 {
    font-size: 1.3rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .submit {
    transition: none;
  }
}
</style>
