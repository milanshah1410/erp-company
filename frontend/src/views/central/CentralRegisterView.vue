<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../api/http'

interface Plan {
  slug: string
  name: string
  price: number
  currency: string
  is_custom: boolean
  feature_limits: Record<string, unknown>
}

const router = useRouter()

const plans = ref<Plan[]>([])
const form = ref({
  plan_slug: 'starter',
  company_name: '',
  admin_name: '',
  email: '',
  phone: '',
  password: '',
})

const emailState = ref<'idle' | 'checking' | 'available' | 'taken'>('idle')
const emailReason = ref('')
const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const serverError = ref('')

const canSubmit = computed(
  () =>
    form.value.company_name &&
    form.value.admin_name &&
    form.value.email &&
    form.value.password.length >= 8 &&
    emailState.value === 'available',
)

onMounted(async () => {
  try {
    const { data } = await http.get('/plans')
    plans.value = data.data
  } catch {
    /* pricing is best-effort */
  }
})

// Live email-availability check (email is the tenant owner's unique login).
let checkTimer: ReturnType<typeof setTimeout>
watch(
  () => form.value.email,
  (email) => {
    emailState.value = 'idle'
    clearTimeout(checkTimer)
    if (!email) return
    emailState.value = 'checking'
    checkTimer = setTimeout(async () => {
      try {
        const { data } = await http.get('/check-email', { params: { email } })
        emailState.value = data.available ? 'available' : 'taken'
        emailReason.value = data.reason || ''
      } catch {
        emailState.value = 'idle'
      }
    }, 400)
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  serverError.value = ''
  try {
    const { data } = await http.post('/register', form.value)
    // Carry the registration id (and dev code locally) to the verify screen.
    router.push({
      name: 'central-verify',
      query: { registration: data.registration_id, dev_code: data.dev_code ?? undefined },
    })
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
      serverError.value = e.response.data.message || ''
    } else {
      serverError.value = 'Something went wrong. Please try again.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="signup">
    <div class="panel">
      <div class="brand">
        <div class="brand-icon"><i class="pi pi-box" /></div>
        <h1>Start your free trial</h1>
        <p>14 days free · no card required</p>
      </div>
      <div class="barcode-divider" />

      <div class="plans">
        <button
          v-for="p in plans"
          :key="p.slug"
          type="button"
          class="plan"
          :class="{ active: form.plan_slug === p.slug }"
          @click="form.plan_slug = p.slug"
        >
          <span class="plan-name">{{ p.name }}</span>
          <span class="plan-price">
            <template v-if="p.is_custom">Custom</template>
            <template v-else>₹{{ p.price.toLocaleString('en-IN') }}<small>/mo</small></template>
          </span>
        </button>
      </div>

      <form @submit.prevent="submit">
        <label>Company name</label>
        <input v-model="form.company_name" type="text" placeholder="Acme Traders" />

        <label>Your name</label>
        <input v-model="form.admin_name" type="text" placeholder="Jane Doe" />

        <label>Work email</label>
        <input v-model="form.email" type="email" placeholder="you@company.com" />
        <p class="hint" :class="emailState">
          <template v-if="emailState === 'checking'">Checking availability…</template>
          <template v-else-if="emailState === 'available'">✓ Available</template>
          <template v-else-if="emailState === 'taken'">{{ emailReason }}</template>
        </p>
        <p v-if="errors.email" class="err">{{ errors.email[0] }}</p>

        <label>Phone <span class="opt">(optional)</span></label>
        <input v-model="form.phone" type="tel" placeholder="+91…" />

        <label>Password</label>
        <input v-model="form.password" type="password" placeholder="At least 8 characters" />
        <p v-if="errors.password" class="err">{{ errors.password[0] }}</p>

        <p v-if="serverError" class="err server">{{ serverError }}</p>

        <button class="submit" type="submit" :disabled="!canSubmit || submitting">
          {{ submitting ? 'Creating…' : 'Create workspace' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.signup {
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
  padding: 2.25rem;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
}
.brand {
  text-align: center;
}
.brand-icon {
  width: 52px;
  height: 52px;
  margin: 0 auto 0.6rem;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eaad48, #c98730);
  box-shadow: 0 8px 20px rgba(232, 163, 61, 0.35);
}
.brand-icon i {
  font-size: 1.5rem;
  color: #1e2328;
}
.brand h1 {
  font-size: 1.5rem;
  margin-top: 0.3rem;
}
.brand p {
  color: #6b7280;
  font-size: 0.9rem;
}
.barcode-divider {
  margin: 1rem 0 1.25rem;
}
.plans {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}
.plan {
  flex: 1;
  border: 1px solid #e8e4da;
  background: #fbfaf7;
  border-radius: 10px;
  padding: 0.7rem 0.5rem;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  transition: all 0.15s;
}
.plan.active {
  border-color: #e8a33d;
  background: rgba(232, 163, 61, 0.1);
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.plan-name {
  font-weight: 600;
  font-size: 0.85rem;
}
.plan-price {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  color: #1e2328;
}
.plan-price small {
  font-weight: 400;
  color: #9ca3af;
  font-size: 0.7rem;
}
form {
  display: flex;
  flex-direction: column;
}
label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #374151;
  margin: 0.7rem 0 0.25rem;
}
.opt {
  font-weight: 400;
  color: #9ca3af;
}
input {
  border: 1px solid #d8d3c8;
  border-radius: 8px;
  padding: 0.55rem 0.7rem;
  font-size: 0.9rem;
  font-family: inherit;
  outline: none;
}
input:focus {
  border-color: #e8a33d;
  box-shadow: 0 0 0 3px rgba(232, 163, 61, 0.15);
}
.subdomain-input {
  display: flex;
  align-items: center;
  border: 1px solid #d8d3c8;
  border-radius: 8px;
  overflow: hidden;
}
.subdomain-input input {
  border: none;
  flex: 1;
}
.subdomain-input input:focus {
  box-shadow: none;
}
.subdomain-input .suffix {
  padding: 0 0.7rem;
  color: #9ca3af;
  font-family: 'IBM Plex Mono', monospace;
  font-size: 0.85rem;
  background: #f3f1ec;
  align-self: stretch;
  display: flex;
  align-items: center;
}
.subdomain-input.available {
  border-color: #4c8b6c;
}
.subdomain-input.taken {
  border-color: #c1502e;
}
.hint {
  font-size: 0.78rem;
  margin: 0.25rem 0 0;
  min-height: 1rem;
}
.hint.available {
  color: #4c8b6c;
}
.hint.taken {
  color: #c1502e;
}
.hint.checking {
  color: #9ca3af;
}
.err {
  color: #c1502e;
  font-size: 0.78rem;
  margin: 0.25rem 0 0;
}
.err.server {
  margin-top: 0.75rem;
}
.submit {
  margin-top: 1.4rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 8px;
  padding: 0.75rem;
  font-weight: 700;
  font-size: 0.95rem;
  cursor: pointer;
}
.submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
