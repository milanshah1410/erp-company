<script setup lang="ts">
import { ref } from 'vue'
import ChurnPanel from './insights/ChurnPanel.vue'
import ForecastPanel from './insights/ForecastPanel.vue'
import UpsellPanel from './insights/UpsellPanel.vue'

// Churn and upsell each sweep every tenant, so tabs load on first visit and
// then stay mounted — switching back must not re-run the sweep.
const tabs = [
  { key: 'churn', label: 'Churn Prediction', icon: 'pi pi-exclamation-triangle' },
  { key: 'forecast', label: 'Revenue Forecast', icon: 'pi pi-chart-line' },
  { key: 'upsell', label: 'Upsell', icon: 'pi pi-arrow-up-right' },
]

const activeTab = ref('churn')
const visited = ref<Set<string>>(new Set(['churn']))

function select(key: string) {
  activeTab.value = key
  visited.value.add(key)
}
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>AI Insights</h1>
        <p class="sub">
          Who is about to leave, what the book will collect, and who has outgrown their plan.
        </p>
      </div>
    </div>

    <p class="disclosure">
      <i class="pi pi-info-circle" />
      Every figure here is computed on this server from data the platform already holds — no
      model call, no key, nothing leaves the box. Each one shows the signals it was derived
      from, so a decision made on it can be audited.
    </p>

    <div class="tab-bar">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="tab"
        :class="{ active: activeTab === tab.key }"
        type="button"
        @click="select(tab.key)"
      >
        <i :class="tab.icon" />
        {{ tab.label }}
      </button>
    </div>

    <ChurnPanel v-if="visited.has('churn')" v-show="activeTab === 'churn'" />
    <ForecastPanel v-if="visited.has('forecast')" v-show="activeTab === 'forecast'" />
    <UpsellPanel v-if="visited.has('upsell')" v-show="activeTab === 'upsell'" />
  </div>
</template>

<style>
/* Shared panel layout, nested under `.pf-analytics` (see the file header). */
@import './analytics/panel.css';
</style>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.88rem; margin-top: 0.15rem; }

.disclosure {
  display: flex; align-items: flex-start; gap: 0.5rem;
  background: #f9fafb; border: 1px solid #e5e7eb; border-left: 3px solid #2a78d6;
  border-radius: 8px; padding: 0.6rem 0.85rem; margin: 0 0 1.1rem;
  font-size: 0.83rem; color: #4b5563;
}
.disclosure i { color: #2a78d6; margin-top: 0.1rem; }

.tab-bar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1.25rem; flex-wrap: wrap; }
.tab {
  display: flex; align-items: center; gap: 0.45rem; padding: 0.55rem 0.9rem; border: none; background: none;
  font-size: 0.9rem; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: #1f2937; }
.tab.active { color: #b45309; border-bottom-color: #e8a33d; }
</style>
