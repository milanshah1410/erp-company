<script setup lang="ts">
import { ref, onMounted } from 'vue'
import http from '../api/http'

interface Plan {
  slug: string
  name: string
  price: number
  currency: string
  is_custom: boolean
  feature_limits: Record<string, unknown>
}
interface Sub {
  status: string
  plan: { slug: string; name: string; price: number; feature_limits: Record<string, unknown> }
  on_trial: boolean
  trial_ends_at: string | null
  trial_days_left: number
  current_period_end: string | null
}

const sub = ref<Sub | null>(null)
const plans = ref<Plan[]>([])
const loading = ref(true)
const changing = ref('')
const message = ref('')

async function load() {
  loading.value = true
  try {
    const [{ data: s }, { data: p }] = await Promise.all([
      http.get('/subscription'),
      http.get('/subscription/plans'),
    ])
    sub.value = s.data
    plans.value = p.data
  } finally {
    loading.value = false
  }
}

async function changePlan(slug: string) {
  changing.value = slug
  message.value = ''
  try {
    const { data } = await http.post('/subscription/change-plan', { plan_slug: slug })
    message.value = data.message
    if (data.short_url) window.open(data.short_url, '_blank')
    await load()
  } catch (e: any) {
    message.value = e.response?.data?.message || 'Could not change plan.'
  } finally {
    changing.value = ''
  }
}

function limitLabel(v: unknown): string {
  return v === null || v === undefined ? 'Unlimited' : String(v)
}

onMounted(load)
</script>

<template>
  <div class="billing">
    <h1>Billing &amp; Plan</h1>
    <p class="subtitle">Manage your subscription and usage limits</p>

    <div v-if="loading" class="empty">Loading…</div>

    <template v-else>
      <div v-if="sub" class="current" :class="sub.status">
        <div>
          <div class="label">Current plan</div>
          <div class="plan-name">{{ sub.plan.name }}</div>
        </div>
        <div class="state">
          <span class="badge" :class="sub.status">{{ sub.status }}</span>
          <span v-if="sub.on_trial" class="trial">{{ sub.trial_days_left }} days left in trial</span>
        </div>
      </div>

      <div v-if="message" class="notice">{{ message }}</div>

      <div class="barcode-divider" />

      <div class="plan-grid">
        <div v-for="p in plans" :key="p.slug" class="plan-card" :class="{ current: sub?.plan.slug === p.slug }">
          <div class="pc-head">
            <h3>{{ p.name }}</h3>
            <div class="price">
              <template v-if="p.is_custom">Custom</template>
              <template v-else>₹{{ p.price.toLocaleString('en-IN') }}<small>/mo</small></template>
            </div>
          </div>
          <ul class="limits">
            <li><i class="pi pi-box" /> {{ limitLabel(p.feature_limits.max_products) }} products</li>
            <li><i class="pi pi-building" /> {{ limitLabel(p.feature_limits.max_warehouses) }} warehouses</li>
            <li><i class="pi pi-users" /> {{ limitLabel(p.feature_limits.max_users) }} users</li>
            <li :class="{ off: !p.feature_limits.barcode }"><i class="pi pi-qrcode" /> Barcode</li>
            <li :class="{ off: !p.feature_limits.reports }"><i class="pi pi-chart-bar" /> Reports</li>
            <li :class="{ off: !p.feature_limits.api_access }"><i class="pi pi-code" /> API access</li>
          </ul>
          <button
            class="choose"
            :disabled="sub?.plan.slug === p.slug || changing === p.slug"
            @click="changePlan(p.slug)"
          >
            <template v-if="sub?.plan.slug === p.slug">Current plan</template>
            <template v-else-if="changing === p.slug">Switching…</template>
            <template v-else-if="p.is_custom">Contact sales</template>
            <template v-else>Choose {{ p.name }}</template>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.billing h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.25rem;
}
.current {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fff;
  border: 1px solid #e8e4da;
  border-left: 4px solid #e8a33d;
  border-radius: 12px;
  padding: 1.25rem 1.5rem;
}
.current .label {
  color: #6b7280;
  font-size: 0.8rem;
}
.current .plan-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 1.4rem;
  font-weight: 700;
}
.state {
  text-align: right;
}
.badge {
  display: inline-block;
  padding: 0.15rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: capitalize;
  background: #eef2f4;
  color: #2c4a5e;
}
.badge.trialing {
  background: rgba(232, 163, 61, 0.18);
  color: #a06a27;
}
.badge.active {
  background: rgba(76, 139, 108, 0.18);
  color: #3a6b53;
}
.badge.suspended,
.badge.past_due,
.badge.cancelled {
  background: rgba(193, 80, 46, 0.15);
  color: #c1502e;
}
.trial {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.8rem;
  color: #a06a27;
}
.notice {
  margin-top: 1rem;
  background: rgba(44, 74, 94, 0.06);
  border: 1px solid #dfe6ea;
  border-radius: 8px;
  padding: 0.7rem 1rem;
  font-size: 0.88rem;
  color: #2c4a5e;
}
.barcode-divider {
  margin: 1.5rem 0;
}
.plan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}
.plan-card {
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
}
.plan-card.current {
  border-color: #e8a33d;
  box-shadow: 0 0 0 1px #e8a33d inset;
}
.pc-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}
.pc-head h3 {
  font-size: 1.05rem;
}
.price {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  color: #1e2328;
}
.price small {
  font-weight: 400;
  color: #9ca3af;
  font-size: 0.7rem;
}
.limits {
  list-style: none;
  padding: 0;
  margin: 1rem 0;
  flex: 1;
}
.limits li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0;
  font-size: 0.85rem;
  color: #374151;
}
.limits li i {
  color: #4c8b6c;
  width: 1rem;
}
.limits li.off {
  color: #b8bdc4;
}
.limits li.off i {
  color: #d1d5db;
}
.choose {
  background: linear-gradient(135deg, #eaad48, #c98730);
  color: #1e2328;
  border: none;
  border-radius: 8px;
  padding: 0.6rem;
  font-weight: 700;
  cursor: pointer;
}
.choose:disabled {
  background: #eef0f2;
  color: #9ca3af;
  cursor: default;
}
.empty {
  color: #6b7280;
}
</style>
