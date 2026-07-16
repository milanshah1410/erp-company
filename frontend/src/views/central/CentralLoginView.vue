<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import { useCentralAuthStore } from '../../stores/centralAuth'

const router = useRouter()
const auth = useCentralAuthStore()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

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
    <div class="card">
      <div class="head">
        <i class="pi pi-sitemap" />
        <h1>Platform Console</h1>
        <p>SaaS operations sign-in. Authorized operators only.</p>
      </div>

      <form @submit.prevent="submit" class="form">
        <div class="fld">
          <label>Email</label>
          <InputText v-model="email" type="email" placeholder="operator@saas.com" autocomplete="username" />
        </div>
        <div class="fld">
          <label>Password</label>
          <Password v-model="password" :feedback="false" toggleMask inputClass="pw" autocomplete="current-password" />
        </div>
        <p v-if="error" class="err">{{ error }}</p>
        <Button type="submit" label="Sign in" :loading="loading" class="submit" />
      </form>
    </div>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(135deg, #1f2937, #2c4a5e); padding: 1.5rem; }
.card { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); padding: 2rem; }
.head { text-align: center; margin-bottom: 1.5rem; }
.head i { font-size: 2.2rem; color: #e8a33d; }
.head h1 { font-size: 1.4rem; font-weight: 700; margin: 0.5rem 0 0.25rem; }
.head p { color: #6b7280; font-size: 0.85rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext), .fld :deep(.p-password) { width: 100%; }
.fld :deep(.pw) { width: 100%; }
.err { color: #dc2626; font-size: 0.85rem; margin: 0; }
.submit { margin-top: 0.5rem; }
</style>
