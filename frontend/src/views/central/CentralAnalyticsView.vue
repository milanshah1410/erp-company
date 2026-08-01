<script setup lang="ts">
import { ref } from 'vue'
import RevenueAnalyticsPanel from './analytics/RevenueAnalyticsPanel.vue'
import IndustryPanel from './analytics/IndustryPanel.vue'
import PlanMixPanel from './analytics/PlanMixPanel.vue'
import ServerUsagePanel from './analytics/ServerUsagePanel.vue'

// One permission (`analytics.view`) gates the whole screen — the four cuts are
// the same read of the same book, so splitting them per-tab would be a
// distinction without a difference. Tabs are lazy: server usage walks the
// storage disk and information_schema, and nobody opening Revenue should pay
// for that.
const tabs = [
  { key: 'revenue', label: 'Revenue', icon: 'pi pi-chart-line' },
  { key: 'industry', label: 'Industry', icon: 'pi pi-sitemap' },
  { key: 'plans', label: 'Plans', icon: 'pi pi-box' },
  { key: 'server', label: 'Server Usage', icon: 'pi pi-server' },
]

const activeTab = ref('revenue')
const visited = ref<Set<string>>(new Set(['revenue']))

function select(key: string) {
  activeTab.value = key
  visited.value.add(key)
}
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Analytics</h1>
        <p class="sub">Revenue, verticals, plan economics and infrastructure headroom.</p>
      </div>
    </div>

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

    <!-- v-show once visited, so switching back does not re-fetch. -->
    <RevenueAnalyticsPanel v-if="visited.has('revenue')" v-show="activeTab === 'revenue'" />
    <IndustryPanel v-if="visited.has('industry')" v-show="activeTab === 'industry'" />
    <PlanMixPanel v-if="visited.has('plans')" v-show="activeTab === 'plans'" />
    <ServerUsagePanel v-if="visited.has('server')" v-show="activeTab === 'server'" />
  </div>
</template>

<style>
/* Shared panel layout — global on purpose, every rule nested under
   `.pf-analytics` (see the file header). */
@import './analytics/panel.css';
</style>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.88rem; margin-top: 0.15rem; }

.tab-bar { display: flex; gap: 0.25rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1.25rem; flex-wrap: wrap; }
.tab {
  display: flex; align-items: center; gap: 0.45rem; padding: 0.55rem 0.9rem; border: none; background: none;
  font-size: 0.9rem; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: #1f2937; }
.tab.active { color: #b45309; border-bottom-color: #e8a33d; }
</style>
