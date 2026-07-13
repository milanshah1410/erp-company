<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('admin@sims.test')
const password = ref('password')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Login failed. Check your credentials.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="brand">
        <div class="brand-icon"><i class="pi pi-box" /></div>
        <h1>SIMS</h1>
        <p>Stock &amp; Inventory Management</p>
      </div>
      <div class="barcode-divider" />

      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

      <form @submit.prevent="submit">
        <label>Email</label>
        <InputText v-model="email" type="email" placeholder="you@example.com" autocomplete="username" />

        <label>Password</label>
        <Password v-model="password" :feedback="false" toggleMask inputClass="w-full" placeholder="••••••••" />

        <Button type="submit" label="Sign In" :loading="loading" class="submit" />
      </form>

      <p class="hint">Default admin: <code>admin@sims.test</code> / <code>password</code></p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2c4a5e 0%, #1e2328 65%, #171a1e 100%);
  padding: 1rem;
}
.login-card {
  background: #fff;
  border-radius: 16px;
  padding: 2.5rem;
  width: 100%;
  max-width: 400px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
}
.brand {
  text-align: center;
  margin-bottom: 1.25rem;
}
.brand-icon {
  width: 56px;
  height: 56px;
  margin: 0 auto 0.75rem;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eaad48 0%, #e8a33d 55%, #c98730 100%);
  box-shadow: 0 8px 20px rgba(232, 163, 61, 0.35);
}
.brand-icon i {
  font-size: 1.6rem;
  color: #1e2328;
}
.brand h1 {
  font-size: 1.75rem;
  font-weight: 700;
  color: #1e2328;
  margin: 0.25rem 0 0;
}
.brand p {
  color: #6b7280;
  font-size: 0.9rem;
}
.barcode-divider {
  margin-bottom: 1.25rem;
}
form {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 1rem;
}
form label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
  margin-top: 0.5rem;
}
form :deep(.p-inputtext),
form :deep(.p-password),
form :deep(.p-password input) {
  width: 100%;
}
.submit {
  margin-top: 1.25rem;
}
.hint {
  margin-top: 1.25rem;
  text-align: center;
  font-size: 0.78rem;
  color: #9ca3af;
}
.hint code {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
}
</style>
