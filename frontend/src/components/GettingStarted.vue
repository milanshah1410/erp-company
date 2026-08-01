<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import http from '../api/http'

interface StepDef {
  key: string
  label: string
  desc: string
  icon: string
  to: string
}

const router = useRouter()

const STEPS: StepDef[] = [
  { key: 'welcome', label: 'Add your company logo', desc: 'It prints on every invoice', icon: 'pi pi-image', to: '/settings' },
  { key: 'warehouse', label: 'Set up your first warehouse', desc: 'Where your stock lives', icon: 'pi pi-building', to: '/warehouses' },
  { key: 'products', label: 'Add your first products', desc: 'Manually, or import a sheet', icon: 'pi pi-box', to: '/products' },
  { key: 'invites', label: 'Invite your team', desc: 'Optional — you can do this later', icon: 'pi pi-users', to: '/users' },
]

const steps = ref<Record<string, boolean>>({})
const completed = ref(false)
const dismissed = ref(localStorage.getItem('sims_gs_dismissed') === '1')
// Wait for the first load before rendering, so the card doesn't flash in at
// 0/4 and then jump when the real progress arrives.
const loaded = ref(false)

const doneCount = computed(() => STEPS.filter((s) => steps.value[s.key]).length)
const pct = computed(() => (doneCount.value / STEPS.length) * 100)
const nextStep = computed(() => STEPS.find((s) => !steps.value[s.key]) ?? null)

async function load() {
  try {
    const { data } = await http.get('/onboarding')
    steps.value = data.steps || {}
    completed.value = data.completed
  } catch {
    /* non-blocking */
  } finally {
    loaded.value = true
  }
}

async function markAndGo(step: StepDef) {
  try {
    await http.post('/onboarding/complete-step', { step: step.key })
  } catch {
    /* still navigate */
  }
  router.push(step.to)
}

function dismiss() {
  dismissed.value = true
  localStorage.setItem('sims_gs_dismissed', '1')
}

onMounted(load)
</script>

<template>
  <div v-if="loaded && !dismissed && !completed" class="gs">
    <div class="gs-glow" aria-hidden="true" />

    <div class="gs-head">
      <div class="gs-title">
        <span class="gs-mark"><i class="pi pi-sparkles" /></span>
        <div>
          <h2>Getting started</h2>
          <p>
            <template v-if="nextStep">Next: {{ nextStep.label.toLowerCase() }}</template>
            <template v-else>Almost there</template>
          </p>
        </div>
      </div>

      <div class="gs-right">
        <div class="dial" :style="{ '--pct': pct + '%' }">
          <span>{{ doneCount }}<small>/{{ STEPS.length }}</small></span>
        </div>
        <button class="dismiss" title="Dismiss" @click="dismiss"><i class="pi pi-times" /></button>
      </div>
    </div>

    <div class="progress"><div class="bar" :style="{ width: pct + '%' }" /></div>

    <ul>
      <li
        v-for="s in STEPS"
        :key="s.key"
        :class="{ done: steps[s.key], next: nextStep?.key === s.key }"
        @click="markAndGo(s)"
      >
        <span class="check"><i :class="steps[s.key] ? 'pi pi-check' : s.icon" /></span>
        <span class="text">
          <span class="label">{{ s.label }}</span>
          <span class="desc">{{ s.desc }}</span>
        </span>
        <span v-if="nextStep?.key === s.key" class="cta">Start</span>
        <i v-else class="pi pi-chevron-right go" />
      </li>
    </ul>
  </div>
</template>

<style scoped>
.gs {
  position: relative;
  overflow: hidden;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 14px;
  padding: 1.25rem;
  margin-bottom: 1.25rem;
}
/* Warm corner wash so the card reads as an invitation, not another table. */
.gs-glow {
  position: absolute;
  inset: -60% 55% auto -10%;
  height: 190%;
  background: radial-gradient(closest-side, rgba(232, 163, 61, 0.16), transparent 70%);
  pointer-events: none;
}
.gs-head {
  position: relative;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}
.gs-title {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-width: 0;
}
.gs-mark {
  flex: none;
  width: 36px;
  height: 36px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #f2c274, #c98730);
  color: #1e2328;
  box-shadow: 0 6px 16px rgba(232, 163, 61, 0.28);
}
.gs-head h2 {
  font-size: 1.02rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.gs-head p {
  margin: 0.1rem 0 0;
  color: #9ca3af;
  font-size: 0.8rem;
}
.gs-right {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
/* Conic dial: progress at a glance without reading the bar. */
.dial {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: conic-gradient(#e8a33d var(--pct), var(--surface-2, #f0ede6) 0);
  transition: background 0.5s ease;
}
.dial span {
  width: 33px;
  height: 33px;
  border-radius: 50%;
  background: var(--surface, #fff);
  display: grid;
  place-items: center;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--graphite, #1e2328);
}
.dial small {
  font-size: 0.58rem;
  color: #9ca3af;
}
.dismiss {
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  font-size: 0.85rem;
  padding: 0.25rem;
  border-radius: 6px;
}
.dismiss:hover {
  color: #6b7280;
  background: var(--surface-2, #f2f0ea);
}
.progress {
  position: relative;
  height: 6px;
  background: var(--surface-2, #f0ede6);
  border-radius: 999px;
  margin: 0.9rem 0 1rem;
  overflow: hidden;
}
.bar {
  height: 100%;
  background: linear-gradient(90deg, #eaad48, #c98730);
  border-radius: 999px;
  transition: width 0.45s cubic-bezier(0.4, 0, 0.2, 1);
}
ul {
  position: relative;
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.7rem;
  border: 1px solid var(--border, #eee9df);
  border-radius: 10px;
  cursor: pointer;
  background: var(--surface, #fff);
  transition: border-color 0.15s, background 0.15s, transform 0.15s;
}
li:hover {
  border-color: #e8c489;
  background: var(--surface-2, #fbfaf7);
  transform: translateX(2px);
}
li.next {
  border-color: #e8a33d;
  box-shadow: 0 0 0 1px rgba(232, 163, 61, 0.35) inset;
}
li.done {
  opacity: 0.72;
}
li.done .label {
  text-decoration: line-through;
  color: #9ca3af;
}
.check {
  flex: none;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 0.72rem;
  background: var(--surface-2, #f2efe8);
  color: #2c4a5e;
  transition: all 0.2s;
}
li.done .check {
  background: #4c8b6c;
  color: #fff;
}
li.next .check {
  background: rgba(232, 163, 61, 0.18);
  color: #c98730;
}
.text {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}
.label {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--graphite, #1e2328);
}
.desc {
  font-size: 0.75rem;
  color: #9ca3af;
}
.cta {
  flex: none;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #1e2328;
  background: linear-gradient(135deg, #eaad48, #c98730);
  border-radius: 999px;
  padding: 0.22rem 0.7rem;
}
.go {
  flex: none;
  color: #c8c3b8;
  font-size: 0.78rem;
}

@media (prefers-reduced-motion: reduce) {
  li,
  .bar,
  .dial {
    transition: none;
  }
}
</style>
