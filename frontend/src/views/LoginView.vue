<script setup lang="ts">
/**
 * Tenant sign-in.
 *
 * Shares AuthShell with the signup journey (register → verify → provisioning)
 * so the two halves of the same funnel look like one product. No step rail
 * here — login is a single screen — and the perks strip swaps the trial pitch
 * for reassurance that matters once you already have an account.
 */
import { ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import { useAuthStore } from '../stores/auth'
import { SUPPORTED_LOCALES, setLocale } from '../i18n'
import AuthShell from '../components/auth/AuthShell.vue'

const router = useRouter()
const auth = useAuthStore()
const { t, locale } = useI18n()

const email = ref('admin@sims.test')
const password = ref('password')
const error = ref('')
const loading = ref(false)

const PERKS = [
  { icon: 'pi pi-shield', label: 'Bank-grade encryption' },
  { icon: 'pi pi-clock', label: '99.9% uptime' },
  { icon: 'pi pi-headphones', label: 'Support in EN · हिन्दी · ગુજરાતી' },
]

// Fills the rail space the signup step spine occupies. Computed so the copy
// follows the language picker in the card below.
const highlights = computed(() => [
  { icon: 'pi pi-book', label: t('login.hlLedger') },
  { icon: 'pi pi-th-large', label: t('login.hlTrade') },
  { icon: 'pi pi-indian-rupee', label: t('login.hlPayments') },
])

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e: any) {
    error.value = e.response?.data?.message || t('login.failed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthShell
    :eyebrow="t('login.welcomeBack')"
    :headline="t('login.headline')"
    :blurb="t('login.blurb')"
    :perks="PERKS"
    :highlights="highlights"
  >
    <div class="head">
      <h1>{{ t('login.cardTitle') }}</h1>
      <p>{{ t('login.subtitle') }}</p>
    </div>

    <div class="lang-row" role="group" :aria-label="t('login.language')">
      <button
        v-for="l in SUPPORTED_LOCALES"
        :key="l.code"
        type="button"
        class="lang-btn"
        :class="{ on: locale === l.code }"
        :aria-pressed="locale === l.code"
        @click="setLocale(l.code)"
      >
        {{ l.label }}
      </button>
    </div>

    <p v-if="error" class="err" role="alert">
      <i class="pi pi-exclamation-circle" />
      <span>{{ error }}</span>
    </p>

    <form @submit.prevent="submit">
      <div class="fld">
        <label for="login-email">{{ t('login.email') }}</label>
        <span class="input-wrap">
          <i class="pi pi-envelope" aria-hidden="true" />
          <InputText
            id="login-email"
            v-model="email"
            type="email"
            placeholder="you@example.com"
            autocomplete="username"
            required
          />
        </span>
      </div>

      <div class="fld">
        <label for="login-password">{{ t('login.password') }}</label>
        <span class="input-wrap has-toggle">
          <i class="pi pi-lock" aria-hidden="true" />
          <Password
            input-id="login-password"
            v-model="password"
            :feedback="false"
            toggle-mask
            placeholder="••••••••"
            autocomplete="current-password"
            required
          />
        </span>
      </div>

      <Button type="submit" :label="t('login.signIn')" :loading="loading" class="submit" />
    </form>

    <p class="signup">
      {{ t('login.noAccount') }}
      <RouterLink to="/register">{{ t('login.createOne') }}</RouterLink>
    </p>

    <p class="hint">
      {{ t('login.defaultAdmin') }} <code>admin@sims.test</code> / <code>password</code>
    </p>

    <template #footer>
      By continuing you agree to our Terms of Service and Privacy Policy.
    </template>
  </AuthShell>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}
.head h1 {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: #1e2328;
  margin: 0;
}
.head p {
  margin: 0.35rem 0 0;
  font-size: 0.9rem;
  color: #6b7280;
}

.lang-row {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 1.35rem;
}
.lang-btn {
  border: 1px solid #e5e7eb;
  background: #fff;
  color: #6b7280;
  font-size: 0.8rem;
  padding: 0.3rem 0.8rem;
  border-radius: 999px;
  cursor: pointer;
  font-family: inherit;
  transition:
    border-color 0.15s,
    background 0.15s,
    color 0.15s;
}
.lang-btn:hover {
  border-color: #d6cfc2;
  background: #faf8f4;
}
.lang-btn.on {
  border-color: #e8a33d;
  background: #fdf7ec;
  color: #a06a27;
  font-weight: 600;
}

.err {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0 0 1rem;
  padding: 0.7rem 0.85rem;
  border-radius: 10px;
  border: 1px solid #f3c7c7;
  background: #fdf2f2;
  color: #b3261e;
  font-size: 0.85rem;
  line-height: 1.45;
}
.err i {
  margin-top: 0.1rem;
  flex: none;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1.05rem;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.fld label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #374151;
}

/* Leading icon sits inside the field; the input gets matching left padding.
   Hand-rolled rather than PrimeVue IconField so the Password toggle (which
   renders its own trailing icon) keeps working unchanged. */
.input-wrap {
  position: relative;
  display: block;
}
.input-wrap > i {
  position: absolute;
  left: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  font-size: 0.9rem;
  color: #9ca3af;
  pointer-events: none;
  z-index: 1;
}
.input-wrap :deep(.p-inputtext),
.input-wrap :deep(.p-password) {
  width: 100%;
}
.input-wrap :deep(.p-inputtext) {
  padding-left: 2.5rem;
  padding-block: 0.7rem;
  border-radius: 10px;
}
.input-wrap:focus-within > i {
  color: #c98730;
}

.submit {
  margin-top: 0.35rem;
  padding-block: 0.72rem;
  font-weight: 600;
  border-radius: 10px;
}

.signup {
  margin: 1.5rem 0 0;
  padding-top: 1.25rem;
  border-top: 1px solid #ede9e0;
  text-align: center;
  font-size: 0.9rem;
  color: #6b7280;
}
.signup a {
  color: #c98730;
  font-weight: 600;
  text-decoration: none;
}
.signup a:hover {
  text-decoration: underline;
}

.hint {
  margin: 0.9rem 0 0;
  text-align: center;
  font-size: 0.76rem;
  color: #9ca3af;
}
.hint code {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 0.95em;
}

@media (prefers-reduced-motion: reduce) {
  .lang-btn {
    transition: none;
  }
}
</style>
