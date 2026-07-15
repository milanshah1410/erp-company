<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { usePortalAuthStore } from '../../stores/portalAuth'

const router = useRouter()
const auth = usePortalAuthStore()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
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
</script>

<template>
  <div class="wrap">
    <div class="card">
      <div class="head">
        <i class="pi pi-shopping-bag" />
        <h1>B2B Order Portal</h1>
        <p>Sign in to browse the catalogue and place orders.</p>
      </div>

      <form @submit.prevent="submit" class="form">
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
    </div>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(135deg, #fef3c7, #f6f7f9); padding: 1.5rem; }
.card { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 2rem; }
.head { text-align: center; margin-bottom: 1.5rem; }
.head i { font-size: 2rem; color: #b45309; }
.head h1 { font-size: 1.4rem; font-weight: 700; margin: 0.5rem 0 0.25rem; }
.head p { color: #6b7280; font-size: 0.88rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.35rem; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-inputtext) { width: 100%; }
.err { color: #dc2626; font-size: 0.85rem; margin: 0; }
.submit { margin-top: 0.5rem; }
</style>
