<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../../api/http'

const route = useRoute()
const router = useRouter()

const registrationId = ref<string>(String(route.query.registration ?? ''))
const code = ref<string>(String(route.query.dev_code ?? ''))
const token = ref<string>(String(route.query.token ?? ''))
const error = ref('')
const submitting = ref(false)
const resent = ref(false)

onMounted(() => {
  // A magic-link visit (?token=…) verifies immediately.
  if (token.value && registrationId.value) submit()
})

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
  } finally {
    submitting.value = false
  }
}

async function resend() {
  try {
    const { data } = await http.post('/resend-verification', { registration_id: Number(registrationId.value) })
    resent.value = true
    if (data.dev_code) code.value = data.dev_code
  } catch {
    error.value = 'Could not resend the code.'
  }
}
</script>

<template>
  <div class="verify">
    <div class="panel">
      <div class="brand-icon"><i class="pi pi-envelope" /></div>
      <h1>Verify your email</h1>
      <p class="sub">Enter the 6-digit code we emailed you to finish setting up your workspace.</p>
      <div class="barcode-divider" />

      <form @submit.prevent="submit">
        <input v-model="code" class="code" inputmode="numeric" maxlength="6" placeholder="••••••" />
        <p v-if="error" class="err">{{ error }}</p>
        <button class="submit" type="submit" :disabled="code.length < 6 || submitting">
          {{ submitting ? 'Verifying…' : 'Verify & create workspace' }}
        </button>
      </form>

      <p class="resend">
        Didn’t get it?
        <button type="button" @click="resend">Resend code</button>
        <span v-if="resent" class="ok">Sent!</span>
      </p>
    </div>
  </div>
</template>

<style scoped>
.verify {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2c4a5e 0%, #1e2328 65%, #171a1e 100%);
  padding: 1.5rem;
}
.panel {
  background: #fff;
  border-radius: 16px;
  padding: 2.5rem;
  width: 100%;
  max-width: 400px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
  text-align: center;
}
.brand-icon {
  width: 52px;
  height: 52px;
  margin: 0 auto 0.75rem;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eaad48, #c98730);
}
.brand-icon i {
  font-size: 1.5rem;
  color: #1e2328;
}
h1 {
  font-size: 1.4rem;
}
.sub {
  color: #6b7280;
  font-size: 0.9rem;
  margin-top: 0.4rem;
}
.barcode-divider {
  margin: 1.1rem 0 1.4rem;
}
.code {
  width: 100%;
  text-align: center;
  letter-spacing: 0.5em;
  font-family: 'IBM Plex Mono', monospace;
  font-size: 1.5rem;
  padding: 0.6rem;
  border: 1px solid #d8d3c8;
  border-radius: 8px;
  outline: none;
}
.code:focus {
  border-color: #e8a33d;
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.15);
}
.submit {
  width: 100%;
  margin-top: 1.1rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 8px;
  padding: 0.75rem;
  font-weight: 700;
  cursor: pointer;
}
.submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.err {
  color: #c1502e;
  font-size: 0.82rem;
  margin-top: 0.6rem;
}
.resend {
  margin-top: 1.25rem;
  font-size: 0.82rem;
  color: #6b7280;
}
.resend button {
  background: none;
  border: none;
  color: #c98730;
  font-weight: 600;
  cursor: pointer;
}
.ok {
  color: #4c8b6c;
  margin-left: 0.25rem;
}
</style>
