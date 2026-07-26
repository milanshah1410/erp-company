<script setup lang="ts">
// Package (plan) picker used by the onboarding wizard — step 1 picks the package
// and step 3 confirms it next to the menu listing, so the cards live here in one
// place. Each card sells the package: price, what it caps, and the module names
// it unlocks (names come from the module catalogue, keyed by the plan's `modules`).
import { computed } from 'vue'

interface Plan {
  slug: string
  name: string
  price: number
  currency?: string
  is_custom: boolean
  feature_limits?: Record<string, unknown>
  modules?: string[]
  modules_all?: boolean
}

interface ModuleOption {
  key: string
  label: string
}

const props = defineProps<{
  plans: Plan[]
  modules: ModuleOption[]
  /** Show the module-name list on each card (step 1 + step 3 both do). */
  showModules?: boolean
}>()

const slug = defineModel<string>({ required: true })

const labelByKey = computed(() => new Map(props.modules.map((m) => [m.key, m.label])))

/** The module labels a package unlocks; a package with no list = the lot. */
function moduleLabels(p: Plan): string[] {
  const keys = p.modules_all || !p.modules?.length ? props.modules.map((m) => m.key) : p.modules
  return keys.map((k) => labelByKey.value.get(k) ?? k)
}

function moduleCount(p: Plan): number {
  return moduleLabels(p).length
}

/** "3 users · 1 warehouse · 500 products", with null read as unlimited. */
function limits(p: Plan): string[] {
  const f = p.feature_limits ?? {}
  const n = (v: unknown) => (v === null || v === undefined ? '∞' : Number(v).toLocaleString('en-IN'))
  const plural = (v: unknown, one: string, many: string) => (Number(v) === 1 ? one : many)
  return [
    `${n(f.max_users)} ${plural(f.max_users, 'user', 'users')}`,
    `${n(f.max_warehouses)} ${plural(f.max_warehouses, 'warehouse', 'warehouses')}`,
    `${n(f.max_products)} products`,
  ]
}

// The middle package is the one most tenants land on — worth pointing at.
function isPopular(index: number): boolean {
  return props.plans.length >= 3 && index === 1
}
</script>

<template>
  <div class="plans">
    <button
      v-for="(p, i) in plans"
      :key="p.slug"
      type="button"
      class="plan"
      :class="{ active: slug === p.slug, popular: isPopular(i), premium: p.is_custom }"
      @click="slug = p.slug"
    >
      <span v-if="isPopular(i)" class="ribbon">Popular</span>
      <span v-else-if="p.is_custom" class="ribbon premium-ribbon">Full access</span>

      <span class="plan-head">
        <span class="plan-name">{{ p.name }}</span>
        <i class="pi" :class="slug === p.slug ? 'pi-check-circle on' : 'pi-circle'" />
      </span>

      <span class="plan-price">
        <template v-if="p.is_custom">
          <em>Talk to us</em>
        </template>
        <template v-else>
          <span class="cur">₹</span>{{ p.price.toLocaleString('en-IN') }}<small>/mo</small>
        </template>
      </span>

      <span class="plan-limits">
        <span v-for="l in limits(p)" :key="l" class="limit">{{ l }}</span>
      </span>

      <template v-if="showModules !== false">
        <span class="plan-modules-head">
          <i class="pi pi-th-large" />
          {{ moduleCount(p) }} modules
        </span>
        <span class="plan-modules" :title="moduleLabels(p).join(', ')">
          <span v-for="label in moduleLabels(p)" :key="label" class="mod-chip">{{ label }}</span>
        </span>
      </template>
    </button>
  </div>
</template>

<style scoped>
.plans {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.6rem;
  margin-bottom: 0.5rem;
}
.plan {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  text-align: left;
  font-family: inherit;
  border: 1px solid #e8e4da;
  background: linear-gradient(180deg, #fff, #fbfaf7);
  border-radius: 14px;
  padding: 0.85rem 0.8rem 0.7rem;
  cursor: pointer;
  overflow: hidden;
  transition: border-color 0.15s, box-shadow 0.18s, transform 0.18s;
}
.plan::before {
  /* Accent rail along the top; brightens when the package is chosen. */
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  background: #e3ded3;
  transition: background 0.15s;
}
.plan:hover {
  border-color: #ddd5c4;
  box-shadow: 0 10px 24px rgba(30, 35, 40, 0.08);
  transform: translateY(-2px);
}
.plan.active {
  border-color: #e8a33d;
  background: linear-gradient(180deg, #fffdf8, rgba(232, 163, 61, 0.1));
  box-shadow: 0 0 0 1px #e8a33d inset, 0 10px 26px rgba(232, 163, 61, 0.18);
}
.plan.active::before {
  background: linear-gradient(90deg, #eaad48, #c98730);
}
.plan.premium.active::before {
  background: linear-gradient(90deg, #2c4a5e, #3d637c);
}

.ribbon {
  position: absolute;
  top: 0.55rem;
  right: -1.6rem;
  transform: rotate(35deg);
  width: 6.5rem;
  text-align: center;
  font-size: 0.58rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #23292f;
  background: linear-gradient(135deg, #eaad48, #c98730);
  padding: 0.12rem 0;
}
.ribbon.premium-ribbon {
  color: #fff;
  background: linear-gradient(135deg, #2c4a5e, #3d637c);
}

.plan-head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.plan-name {
  font-size: 0.88rem;
  font-weight: 700;
  color: #1e2328;
  letter-spacing: -0.01em;
}
.plan-head .pi {
  margin-left: auto;
  font-size: 0.85rem;
  color: #d3ccbc;
}
.plan-head .pi.on {
  color: #4c8b6c;
}

.plan-price {
  font-size: 1.15rem;
  font-weight: 800;
  color: #1e2328;
  line-height: 1.1;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
}
.plan-price .cur {
  font-size: 0.82rem;
  font-weight: 600;
  margin-right: 0.05rem;
}
.plan-price small {
  font-size: 0.62rem;
  font-weight: 500;
  color: #9ca3af;
  font-family: inherit;
}
.plan-price em {
  font-style: normal;
  font-size: 0.92rem;
  font-weight: 700;
  color: #2c4a5e;
  font-family: 'Inter', system-ui, sans-serif;
}

.plan-limits {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 0.35rem 0;
  border-top: 1px dashed #e8e4da;
  border-bottom: 1px dashed #e8e4da;
}
.limit {
  font-size: 0.68rem;
  color: #6b7280;
}

.plan-modules-head {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #8a5a12;
}
.plan-modules {
  display: flex;
  flex-wrap: wrap;
  gap: 0.2rem;
  /* Keep the three cards level however long a package's module list is. */
  max-height: 6.4rem;
  overflow: hidden;
}
.mod-chip {
  font-size: 0.62rem;
  font-weight: 600;
  color: #4a5560;
  background: #f1eee6;
  border: 1px solid #e6e1d5;
  border-radius: 999px;
  padding: 0.06rem 0.36rem;
  white-space: nowrap;
}
.plan.active .mod-chip {
  color: #6b4a12;
  background: rgba(232, 163, 61, 0.16);
  border-color: rgba(232, 163, 61, 0.35);
}

@media (max-width: 560px) {
  .plans {
    grid-template-columns: 1fr;
  }
  .plan-modules {
    max-height: none;
  }
}
</style>
