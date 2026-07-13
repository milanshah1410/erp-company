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
  { key: 'welcome', label: 'Add your company logo', desc: 'Make it yours', icon: 'pi pi-image', to: '/settings' },
  { key: 'warehouse', label: 'Set up your first warehouse', desc: 'Where stock lives', icon: 'pi pi-building', to: '/warehouses' },
  { key: 'products', label: 'Add your first products', desc: 'Manually or via import', icon: 'pi pi-box', to: '/products' },
  { key: 'invites', label: 'Invite your team', desc: 'Optional', icon: 'pi pi-users', to: '/users' },
]

const steps = ref<Record<string, boolean>>({})
const completed = ref(false)
const dismissed = ref(localStorage.getItem('sims_gs_dismissed') === '1')

const doneCount = computed(() => STEPS.filter((s) => steps.value[s.key]).length)

async function load() {
  try {
    const { data } = await http.get('/onboarding')
    steps.value = data.steps || {}
    completed.value = data.completed
  } catch {
    /* non-blocking */
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
  <div v-if="!dismissed && !completed" class="gs">
    <div class="gs-head">
      <div>
        <h2>Getting started</h2>
        <p>{{ doneCount }}/{{ STEPS.length }} steps complete</p>
      </div>
      <button class="dismiss" @click="dismiss" title="Dismiss"><i class="pi pi-times" /></button>
    </div>
    <div class="progress"><div class="bar" :style="{ width: (doneCount / STEPS.length) * 100 + '%' }" /></div>
    <ul>
      <li v-for="s in STEPS" :key="s.key" :class="{ done: steps[s.key] }" @click="markAndGo(s)">
        <span class="check"><i :class="steps[s.key] ? 'pi pi-check-circle' : 'pi pi-circle'" /></span>
        <span class="icon"><i :class="s.icon" /></span>
        <span class="text">
          <span class="label">{{ s.label }}</span>
          <span class="desc">{{ s.desc }}</span>
        </span>
        <i class="pi pi-chevron-right go" />
      </li>
    </ul>
  </div>
</template>

<style scoped>
.gs {
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  padding: 1.25rem;
  margin-bottom: 1.25rem;
}
.gs-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.gs-head h2 {
  font-size: 1.05rem;
  font-weight: 600;
}
.gs-head p {
  color: #6b7280;
  font-size: 0.82rem;
  margin-top: 0.15rem;
}
.dismiss {
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  font-size: 0.9rem;
}
.progress {
  height: 6px;
  background: #f0ede6;
  border-radius: 999px;
  margin: 0.75rem 0 1rem;
  overflow: hidden;
}
.bar {
  height: 100%;
  background: linear-gradient(90deg, #eaad48, #c98730);
  transition: width 0.3s;
}
ul {
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
  border: 1px solid #eee9df;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.15s;
}
li:hover {
  background: #fbfaf7;
}
li.done .label {
  text-decoration: line-through;
  color: #9ca3af;
}
.check i {
  color: #d1cfc7;
}
li.done .check i {
  color: #4c8b6c;
}
.icon i {
  color: #2c4a5e;
}
.text {
  display: flex;
  flex-direction: column;
  flex: 1;
}
.label {
  font-size: 0.9rem;
  font-weight: 500;
}
.desc {
  font-size: 0.75rem;
  color: #9ca3af;
}
.go {
  color: #c8c3b8;
  font-size: 0.8rem;
}
</style>
