<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../api/http'
import AuthShell from '../../components/auth/AuthShell.vue'

const route = useRoute()
const registrationId = String(route.query.registration ?? '')

const status = ref<'provisioning' | 'active' | 'failed' | 'pending_verification'>('provisioning')
const loginUrl = ref<string | null>(null)
const workspaceUrl = ref<string | null>(null)
const workspaceAddress = ref<string | null>(null)
const companyName = ref<string>('')
const errorMsg = ref('')
const copied = ref(false)

// The eight onboarding phases (Volume 11 §"Onboarding Automation"), reported by
// the provisioning job itself — App\Support\ProvisionSteps writes each one back
// to the registration row as it finishes, so this is real progress rather than a
// timed animation, and a failure names the phase it died in.
type ProvisionStep = { key: string; label: string; status: string; note: string | null }

const steps = ref<ProvisionStep[]>([])

const STEP_ICONS: Record<string, string> = {
  tenant: 'pi pi-building',
  industry: 'pi pi-briefcase',
  plan: 'pi pi-box',
  schema: 'pi pi-database',
  seed: 'pi pi-sparkles',
  modules: 'pi pi-sliders-h',
  admin: 'pi pi-user',
  demo: 'pi pi-table',
}

/** Settled = the phase is behind us, whether it ran or had nothing to do. */
const settled = computed(() => steps.value.filter((s) => s.status === 'done' || s.status === 'skipped').length)

const progressPct = computed(() => {
  if (status.value === 'active') return 100
  if (!steps.value.length) return 0
  return Math.round((settled.value / steps.value.length) * 100)
})

const running = computed(() => steps.value.find((s) => s.status === 'running'))
const currentLabel = computed(() => {
  if (status.value === 'active') return 'Done'
  return running.value?.label ?? steps.value.find((s) => s.status === 'pending')?.label ?? 'your workspace'
})

function stepClass(s: ProvisionStep): string {
  if (status.value === 'active') return 'done'
  return s.status === 'running' ? 'active' : s.status
}

function stepIcon(s: ProvisionStep): string {
  if (status.value === 'active' || s.status === 'done') return 'pi pi-check'
  if (s.status === 'running') return 'pi pi-spin pi-spinner'
  if (s.status === 'failed') return 'pi pi-times'
  if (s.status === 'skipped') return 'pi pi-minus'
  return STEP_ICONS[s.key] ?? 'pi pi-circle'
}

let poll: ReturnType<typeof setInterval>

async function check() {
  try {
    const { data } = await http.get(`/provisioning-status/${registrationId}`)
    status.value = data.status
    loginUrl.value = data.login_url
    workspaceUrl.value = data.workspace_url ?? null
    workspaceAddress.value = data.subdomain && data.workspace_url
      ? data.workspace_url.replace(/^https?:\/\//, '')
      : null
    companyName.value = data.company_name ?? ''
    errorMsg.value = data.error || ''
    steps.value = data.steps ?? []
    if (data.status === 'active' && data.login_url) {
      clearInterval(poll)
      setTimeout(() => (window.location.href = data.login_url), 2500)
    }
    if (data.status === 'failed') {
      clearInterval(poll)
    }
  } catch {
    /* keep polling */
  }
}

async function copyAddress() {
  if (!workspaceAddress.value) return
  try {
    await navigator.clipboard.writeText(workspaceAddress.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1800)
  } catch {
    /* clipboard is best-effort (needs a secure context) */
  }
}

onMounted(() => {
  check()
  poll = setInterval(check, 2000)
})
onBeforeUnmount(() => {
  clearInterval(poll)
})
</script>

<template>
  <AuthShell
    eyebrow="Provisioning"
    :headline="status === 'active' ? 'Your workspace is live.' : 'Building your workspace…'"
    blurb="Company, roles, permissions, menus, users and a dashboard — generated for you, not configured by you."
  >
    <template v-if="status !== 'failed'">
      <div class="center">
        <div class="orb" :class="{ done: status === 'active' }">
          <span v-if="status !== 'active'" class="pulse" aria-hidden="true" />
          <i :class="status === 'active' ? 'pi pi-check' : 'pi pi-cog pi-spin'" />
        </div>
        <h1>{{ status === 'active' ? 'You’re all set!' : 'Generating your workspace…' }}</h1>
        <p class="sub">
          {{
            status === 'active'
              ? 'Taking you to the sign-in page…'
              : 'This usually takes under a minute. Keep this tab open.'
          }}
        </p>
      </div>

      <div class="bar-row">
        <div class="progress"><div class="bar" :style="{ width: progressPct + '%' }" /></div>
        <span class="pct">{{ progressPct }}%</span>
      </div>
      <p class="doing">{{ status === 'active' ? 'Everything generated' : `Working on ${currentLabel.toLowerCase()}…` }}</p>

      <ul class="steps">
        <li v-for="s in steps" :key="s.key" :class="stepClass(s)">
          <span class="tick"><i :class="stepIcon(s)" /></span>
          {{ s.label }}
        </li>
      </ul>

      <!-- The address claimed at signup, ready to be bookmarked. -->
      <div v-if="workspaceAddress" class="workspace">
        <span class="ws-label"><i class="pi pi-globe" /> Your workspace address</span>
        <div class="ws-row">
          <code>{{ workspaceAddress }}</code>
          <button type="button" class="copy" :title="copied ? 'Copied' : 'Copy'" @click="copyAddress">
            <i :class="copied ? 'pi pi-check' : 'pi pi-copy'" />
          </button>
        </div>
      </div>

      <a v-if="status === 'active' && loginUrl" :href="loginUrl" class="go">
        Open {{ companyName || 'my dashboard' }} <i class="pi pi-arrow-right" />
      </a>
    </template>

    <template v-else>
      <div class="center">
        <div class="orb failed"><i class="pi pi-times" /></div>
        <h1>Provisioning failed</h1>
        <p class="sub">{{ errorMsg || 'Something went wrong while creating your workspace.' }}</p>
      </div>
      <RouterLink :to="{ name: 'register' }" class="go">Start over</RouterLink>
      <p class="tip">Nothing was charged. If this keeps happening, contact support.</p>
    </template>
  </AuthShell>
</template>

<style scoped>
.center {
  text-align: center;
}
.orb {
  position: relative;
  width: 66px;
  height: 66px;
  margin: 0 auto 1rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #f2c274, #e8a33d 55%, #c98730);
  box-shadow: 0 10px 26px rgba(232, 163, 61, 0.32);
  font-size: 1.7rem;
  color: #1e2328;
}
.orb.done {
  background: linear-gradient(135deg, #6fb492, #4c8b6c);
  box-shadow: 0 10px 26px rgba(76, 139, 108, 0.32);
  color: #fff;
}
.orb.failed {
  background: linear-gradient(135deg, #d66a45, #c1502e);
  box-shadow: 0 10px 26px rgba(193, 80, 46, 0.28);
  color: #fff;
}
/* Slow halo so the screen feels alive while the queue works. */
.pulse {
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  border: 2px solid rgba(232, 163, 61, 0.45);
  animation: halo 2s ease-out infinite;
}
@keyframes halo {
  0% { transform: scale(0.9); opacity: 0.8; }
  100% { transform: scale(1.35); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .pulse {
    animation: none;
    opacity: 0.35;
  }
}

h1 {
  font-size: 1.35rem;
  letter-spacing: -0.01em;
}
.sub {
  margin: 0.4rem 0 0;
  color: #6b7280;
  font-size: 0.9rem;
}

.bar-row {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin: 1.5rem 0 0.5rem;
}
.progress {
  flex: 1;
  height: 8px;
  background: #efece4;
  border-radius: 999px;
  overflow: hidden;
}
.progress .bar {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #eaad48, #c98730);
  transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}
.pct {
  flex: none;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: #8a5a12;
  min-width: 2.6rem;
  text-align: right;
}
.doing {
  margin: 0 0 1.1rem;
  font-size: 0.8rem;
  color: #9ca3af;
}

.steps {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.3rem 0.9rem;
}
.steps li {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.3rem 0;
  color: #a8a295;
  font-size: 0.85rem;
  transition: color 0.3s;
}
.tick {
  flex: none;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 0.62rem;
  background: #f2efe8;
  color: #b8b2a5;
  transition: all 0.3s;
}
.steps li.active {
  color: #1e2328;
  font-weight: 600;
}
.steps li.active .tick {
  background: rgba(232, 163, 61, 0.18);
  color: #c98730;
}
.steps li.done {
  color: #4a5560;
}
.steps li.done .tick {
  background: #4c8b6c;
  color: #fff;
}

.workspace {
  margin-top: 1.4rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid #ece5d6;
  border-radius: 12px;
  background: linear-gradient(180deg, #fffdf8, #faf7f0);
}
.ws-label {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #8a5a12;
}
.ws-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.35rem;
}
.ws-row code {
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  white-space: nowrap;
  font-size: 0.9rem;
  font-weight: 600;
  color: #1e2328;
}
.copy {
  flex: none;
  background: #fff;
  border: 1px solid #e3ded3;
  border-radius: 8px;
  width: 30px;
  height: 30px;
  display: grid;
  place-items: center;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s;
}
.copy:hover {
  border-color: #e8a33d;
  color: #c98730;
}

.go {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  margin-top: 1.4rem;
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  text-decoration: none;
  border-radius: 10px;
  padding: 0.8rem 1.4rem;
  font-weight: 700;
  font-size: 0.92rem;
  box-shadow: 0 6px 16px rgba(201, 135, 48, 0.28);
  transition: transform 0.15s, box-shadow 0.15s;
}
.go:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(201, 135, 48, 0.34);
}
.tip {
  margin: 0.7rem 0 0;
  text-align: center;
  font-size: 0.76rem;
  color: #a8a295;
}

@media (max-width: 480px) {
  .steps {
    grid-template-columns: 1fr;
  }
}
</style>
