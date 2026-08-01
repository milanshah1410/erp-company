<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { usePortalAuthStore } from '../../stores/portalAuth'
import BrandLogo from '../../components/BrandLogo.vue'

const router = useRouter()
const auth = usePortalAuthStore()

const method = ref<'password' | 'otp'>('password')
const email = ref('')
const password = ref('')
const otp = ref('')
const otpSent = ref(false)
const phoneHint = ref<string | null>(null)
const error = ref<string | null>(null)
const info = ref<string | null>(null)
const loading = ref(false)

function switchMethod(m: 'password' | 'otp') {
  method.value = m
  error.value = null
  info.value = null
  otpSent.value = false
  otp.value = ''
}

async function submitPassword() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'portal-catalogue' })
  } catch (e: any) {
    error.value = e.response?.data?.errors?.email?.[0] || e.response?.data?.message || 'Login failed. Please try again.'
  } finally {
    loading.value = false
  }
}

async function requestOtp() {
  error.value = null
  info.value = null
  loading.value = true
  try {
    phoneHint.value = await auth.requestOtp(email.value)
    otpSent.value = true
    info.value = phoneHint.value ? `OTP sent to ${phoneHint.value}.` : 'OTP sent to your registered phone.'
  } catch (e: any) {
    error.value = e.response?.data?.errors?.email?.[0] || e.response?.data?.message || 'Could not send OTP.'
  } finally {
    loading.value = false
  }
}

async function verifyOtp() {
  error.value = null
  loading.value = true
  try {
    await auth.loginWithOtp(email.value, otp.value)
    router.push({ name: 'portal-catalogue' })
  } catch (e: any) {
    error.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.errors?.email?.[0] || e.response?.data?.message || 'Invalid OTP.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="wrap">
    <div class="card">
      <div class="head">
        <!-- The seller's own brand when the workspace is white labelled: this
             is their customers' door, so it should read as their shop. -->
        <BrandLogo :height="42" variant="lockup" with-name class="logo" />
        <h1>B2B Order Portal</h1>
        <p>Sign in to browse the catalogue and place orders.</p>
      </div>

      <div class="tabs">
        <button type="button" :class="{ active: method === 'password' }" @click="switchMethod('password')">Password</button>
        <button type="button" :class="{ active: method === 'otp' }" @click="switchMethod('otp')">OTP</button>
      </div>

      <!-- Password login -->
      <form v-if="method === 'password'" @submit.prevent="submitPassword" class="form">
        <div class="fld">
          <label>Email</label>
          <InputText v-model="email" type="email" placeholder="you@company.com" autocomplete="username" />
        </div>
        <div class="fld">
          <label>Password</label>
          <InputText v-model="password" type="password" autocomplete="current-password" />
        </div>
        <p v-if="error" class="err">{{ error }}</p>
        <Button type="submit" label="Sign in" :loading="loading" class="submit" />
      </form>

      <!-- OTP login -->
      <form v-else @submit.prevent="otpSent ? verifyOtp() : requestOtp()" class="form">
        <div class="fld">
          <label>Email</label>
          <InputText v-model="email" type="email" placeholder="you@company.com" autocomplete="username" :disabled="otpSent" />
        </div>
        <div v-if="otpSent" class="fld">
          <label>Enter OTP</label>
          <InputText v-model="otp" inputmode="numeric" placeholder="6-digit code" autocomplete="one-time-code" />
        </div>

        <p v-if="info" class="info">{{ info }}</p>
        <p v-if="error" class="err">{{ error }}</p>

        <Button v-if="!otpSent" type="submit" label="Send OTP" :loading="loading" class="submit" />
        <template v-else>
          <Button type="submit" label="Verify & Sign in" :loading="loading" class="submit" />
          <button type="button" class="link" :disabled="loading" @click="requestOtp">Resend OTP</button>
        </template>
      </form>
    </div>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(135deg, #fef3c7, #f6f7f9); padding: 1.5rem; }
.card { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 2rem; }
/* Dark ink on this light card — the lockup takes its sheath/wordmark colour
   from here, so no separate light/dark asset is needed. */
.head { text-align: center; margin-bottom: 1.25rem; color: #1e2328; }
.head .logo { margin: 0 auto 0.85rem; }
.head h1 {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.4rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.3rem;
}
.head p { color: #6b7280; font-size: 0.88rem; margin: 0; }
.tabs { display: flex; gap: 0.25rem; background: #f3f4f6; border-radius: 10px; padding: 0.25rem; margin-bottom: 1.25rem; }
.tabs button { flex: 1; border: none; background: transparent; padding: 0.5rem; border-radius: 8px; font-size: 0.88rem; font-weight: 500; color: #6b7280; cursor: pointer; }
.tabs button.active { background: #fff; color: #b45309; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.form { display: flex; flex-direction: column; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext) { width: 100%; }
.err { color: #dc2626; font-size: 0.85rem; margin: 0; }
.info { color: #047857; font-size: 0.85rem; margin: 0; }
.submit { margin-top: 0.5rem; }
.link { background: none; border: none; color: #b45309; font-size: 0.82rem; cursor: pointer; padding: 0; }
.link:disabled { opacity: 0.5; cursor: default; }
</style>
