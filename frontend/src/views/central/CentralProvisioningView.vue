<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../api/http'

const route = useRoute()
const registrationId = String(route.query.registration ?? '')

const status = ref<'provisioning' | 'active' | 'failed' | 'pending_verification'>('provisioning')
const loginUrl = ref<string | null>(null)
const errorMsg = ref('')

// The Step-7 "Generate" sequence from the onboarding spec. Provisioning is a
// single atomic job server-side, so we reveal these client-side for perceived
// progress and mark them all complete once the tenant is active.
const steps = [
  { label: 'Company', icon: 'pi pi-building' },
  { label: 'Menus', icon: 'pi pi-bars' },
  { label: 'Permissions', icon: 'pi pi-key' },
  { label: 'Roles', icon: 'pi pi-shield' },
  { label: 'Users', icon: 'pi pi-users' },
  { label: 'Settings', icon: 'pi pi-cog' },
  { label: 'Dashboard', icon: 'pi pi-th-large' },
  { label: 'Sample data', icon: 'pi pi-database' },
]

// How many steps have "completed" so far (all of them once active).
const revealed = ref(1)
const stepState = (i: number): 'done' | 'active' | 'pending' => {
  if (status.value === 'active' || i < revealed.value) return 'done'
  if (i === revealed.value) return 'active'
  return 'pending'
}
const progressPct = computed(() =>
  status.value === 'active' ? 100 : Math.round((revealed.value / steps.length) * 100),
)

let poll: ReturnType<typeof setInterval>
let reveal: ReturnType<typeof setInterval>

async function check() {
  try {
    const { data } = await http.get(`/provisioning-status/${registrationId}`)
    status.value = data.status
    loginUrl.value = data.login_url
    errorMsg.value = data.error || ''
    if (data.status === 'active' && data.login_url) {
      clearInterval(poll)
      clearInterval(reveal)
      setTimeout(() => (window.location.href = data.login_url), 1500)
    }
    if (data.status === 'failed') {
      clearInterval(poll)
      clearInterval(reveal)
    }
  } catch {
    /* keep polling */
  }
}

onMounted(() => {
  check()
  poll = setInterval(check, 2000)
  // Walk the generate steps while we wait, pausing on the last one until the
  // server confirms completion.
  reveal = setInterval(() => {
    if (status.value === 'provisioning' && revealed.value < steps.length) revealed.value++
  }, 700)
})
onBeforeUnmount(() => {
  clearInterval(poll)
  clearInterval(reveal)
})
</script>

<template>
  <div class="prov">
    <div class="panel">
      <template v-if="status !== 'failed'">
        <div class="spinner" :class="{ done: status === 'active' }">
          <i :class="status === 'active' ? 'pi pi-check' : 'pi pi-spin pi-spinner'" />
        </div>
        <h1>{{ status === 'active' ? 'Your workspace is ready!' : 'Generating your workspace…' }}</h1>
        <p class="sub">
          {{ status === 'active' ? 'Redirecting you to your dashboard…' : 'Setting everything up — just a few seconds.' }}
        </p>

        <div class="progress"><div class="bar" :style="{ width: progressPct + '%' }" /></div>

        <ul class="steps">
          <li v-for="(s, i) in steps" :key="s.label" :class="stepState(i)">
            <i
              :class="stepState(i) === 'done' ? 'pi pi-check-circle'
                : stepState(i) === 'active' ? 'pi pi-spin pi-spinner' : 'pi pi-circle'"
            />
            {{ s.label }}
          </li>
        </ul>

        <a v-if="status === 'active' && loginUrl" :href="loginUrl" class="go">Open dashboard →</a>
      </template>

      <template v-else>
        <div class="spinner failed"><i class="pi pi-times" /></div>
        <h1>Provisioning failed</h1>
        <p class="sub">{{ errorMsg || 'Something went wrong while creating your workspace.' }}</p>
        <RouterLink :to="{ name: 'register' }" class="go">Try again</RouterLink>
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
.progress {
  height: 6px;
  background: #eee;
  border-radius: 999px;
  overflow: hidden;
  margin: 1.25rem 0;
}
.progress .bar {
  height: 100%;
  background: linear-gradient(135deg, #eaad48, #c98730);
  border-radius: 999px;
  transition: width 0.5s ease;
}
.steps {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.15rem 1rem;
  text-align: left;
}
.steps li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0;
  color: #9ca3af;
  font-size: 0.88rem;
  transition: color 0.3s;
}
.steps li.active {
  color: #1e2328;
  font-weight: 600;
}
.steps li.done {
  color: #4c8b6c;
}
.steps li i {
  color: #c98730;
  font-size: 0.85rem;
}
.steps li.done i {
  color: #4c8b6c;
}
.steps li.pending i {
  color: #d8d3c8;
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
