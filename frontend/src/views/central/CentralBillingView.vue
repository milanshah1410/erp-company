<script setup lang="ts">
import { ref, computed } from 'vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useCentralAuthStore } from '../../stores/centralAuth'
import RevenuePanel from './billing/RevenuePanel.vue'
import PlansPanel from './billing/PlansPanel.vue'
import SubscriptionsPanel from './billing/SubscriptionsPanel.vue'
import InvoicesPanel from './billing/InvoicesPanel.vue'

const auth = useCentralAuthStore()

// Each tab is gated by its own central permission, so a finance/auditor
// operator sees exactly the surfaces their role grants.
const tabs = computed(() =>
  [
    { value: 'revenue', label: 'Revenue', icon: 'pi pi-chart-line', permission: 'revenue.view' },
    { value: 'plans', label: 'Plans', icon: 'pi pi-box', permission: 'plans.view' },
    { value: 'subscriptions', label: 'Subscriptions', icon: 'pi pi-sync', permission: 'subscriptions.view' },
    { value: 'invoices', label: 'Invoices', icon: 'pi pi-file', permission: 'invoices.view' },
  ].filter((t) => auth.can(t.permission)),
)

const active = ref(tabs.value[0]?.value ?? 'plans')
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Billing &amp; Subscriptions</h1>
        <p class="sub">Plans, subscriptions, invoices, refunds &amp; revenue.</p>
      </div>
    </div>

    <Tabs v-model:value="active" :lazy="true">
      <TabList>
        <Tab v-for="t in tabs" :key="t.value" :value="t.value">
          <i :class="t.icon" class="tab-icon" /> {{ t.label }}
        </Tab>
      </TabList>
      <TabPanels>
        <TabPanel v-if="auth.can('revenue.view')" value="revenue"><RevenuePanel /></TabPanel>
        <TabPanel v-if="auth.can('plans.view')" value="plans"><PlansPanel /></TabPanel>
        <TabPanel v-if="auth.can('subscriptions.view')" value="subscriptions"><SubscriptionsPanel /></TabPanel>
        <TabPanel v-if="auth.can('invoices.view')" value="invoices"><InvoicesPanel /></TabPanel>
      </TabPanels>
    </Tabs>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.88rem; margin-top: 0.15rem; }
.tab-icon { margin-right: 0.4rem; }
:deep(.p-tabpanels) { padding-left: 0; padding-right: 0; background: transparent; }
:deep(.p-tablist-tab-list) { background: transparent; }
</style>
