<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../api/http'

const route = useRoute()
const registrationId = String(route.query.registration ?? '')

const status = ref<'provisioning' | 'active' | 'failed' | 'pending_verification'>('provisioning')
const loginUrl = ref<string | null>(null)
const errorMsg = ref('')

const steps = [
  'Creating your database',
  'Running migrations',
  'Seeding defaults & roles',
  'Setting up your admin account',
  'Starting your free trial',
]

let poll: ReturnType<typeof setInterval>

async function check() {
  try {
    const { data } = await http.get(`/provisioning-status/${registrationId}`)
    status.value = data.status
    loginUrl.value = data.login_url
    errorMsg.value = data.error || ''
    if (data.status === 'active' && data.login_url) {
      clearInterval(poll)
      setTimeout(() => (window.location.href = data.login_url), 1200)
    }
    if (data.status === 'failed') clearInterval(poll)
  } catch {
    /* keep polling */
  }
}

onMounted(() => {
  check()
  poll = setInterval(check, 2000)
})
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <div class="prov">
    <div class="panel">
      <template v-if="status !== 'failed'">
        <div class="spinner" :class="{ done: status === 'active' }">
          <i :class="status === 'active' ? 'pi pi-check' : 'pi pi-spin pi-spinner'" />
        </div>
        <h1>{{ status === 'active' ? 'Your workspace is ready!' : 'Setting up your workspace…' }}</h1>
        <p class="sub">
          {{ status === 'active' ? 'Redirecting you to your dashboard…' : 'This takes just a few seconds.' }}
        </p>
        <div class="barcode-divider" />
        <ul class="steps">
          <li v-for="s in steps" :key="s" :class="{ ok: status === 'active' }">
            <i :class="status === 'active' ? 'pi pi-check-circle' : 'pi pi-circle'" />{{ s }}
          </li>
        </ul>
        <a v-if="status === 'active' && loginUrl" :href="loginUrl" class="go">Open dashboard →</a>
      </template>

      <template v-else>
        <div class="spinner failed"><i class="pi pi-times" /></div>
        <h1>Provisioning failed</h1>
        <p class="sub">{{ errorMsg || 'Something went wrong while creating your workspace.' }}</p>
        <RouterLink :to="{ name: 'central-register' }" class="go">Try again</RouterLink>
      </template>
    </div>
  </div>
</template>

<style scoped>
.prov {
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
  max-width: 420px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
  text-align: center;
}
.spinner {
  width: 60px;
  height: 60px;
  margin: 0 auto 1rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eaad48, #c98730);
  font-size: 1.6rem;
  color: #1e2328;
}
.spinner.done {
  background: linear-gradient(135deg, #5fa483, #4c8b6c);
  color: #fff;
}
.spinner.failed {
  background: linear-gradient(135deg, #d66a45, #c1502e);
  color: #fff;
}
h1 {
  font-size: 1.35rem;
}
.sub {
  color: #6b7280;
  font-size: 0.9rem;
  margin-top: 0.4rem;
}
.barcode-divider {
  margin: 1.1rem 0;
}
.steps {
  list-style: none;
  padding: 0;
  margin: 0;
  text-align: left;
}
.steps li {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0;
  color: #6b7280;
  font-size: 0.9rem;
}
.steps li.ok {
  color: #4c8b6c;
}
.steps li i {
  color: #c98730;
}
.steps li.ok i {
  color: #4c8b6c;
}
.go {
  display: inline-block;
  margin-top: 1.25rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  text-decoration: none;
  border-radius: 8px;
  padding: 0.7rem 1.4rem;
  font-weight: 700;
}
</style>
