<script setup lang="ts">
import { ref, computed } from 'vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useCentralAuthStore } from '../../stores/centralAuth'
import RevenuePanel from './billing/RevenuePanel.vue'
import SubscriptionsPanel from './billing/SubscriptionsPanel.vue'
import InvoicesPanel from './billing/InvoicesPanel.vue'
import RenewalsPanel from './billing/RenewalsPanel.vue'
import CouponsPanel from './billing/CouponsPanel.vue'
import TaxPanel from './billing/TaxPanel.vue'
import GatewaysPanel from './billing/GatewaysPanel.vue'

const auth = useCentralAuthStore()

// Each tab is gated by its own central permission, so a finance/auditor
// operator sees exactly the surfaces their role grants. The plan catalogue
// lives on its own /platform/plans screen, not here.
//
// Order follows the billing cycle: what came in (Revenue), what is running
// (Subscriptions), what is owed (Renewals), what was raised (Invoices), then the
// levers — promotions, our tax identity, and where the money is collected.
const tabs = computed(() =>
  [
    { value: 'revenue', label: 'Revenue', icon: 'pi pi-chart-line', permission: 'revenue.view' },
    { value: 'subscriptions', label: 'Subscriptions', icon: 'pi pi-sync', permission: 'subscriptions.view' },
    { value: 'renewals', label: 'Renewals', icon: 'pi pi-clock', permission: 'renewals.view' },
    { value: 'invoices', label: 'Invoices', icon: 'pi pi-file', permission: 'invoices.view' },
    { value: 'coupons', label: 'Coupons', icon: 'pi pi-ticket', permission: 'coupons.view' },
    { value: 'tax', label: 'GST & Invoicing', icon: 'pi pi-verified', permission: 'settings.view' },
    { value: 'gateways', label: 'Payment Gateways', icon: 'pi pi-credit-card', permission: 'gateways.view' },
  ].filter((t) => auth.can(t.permission)),
)

const active = ref(tabs.value[0]?.value ?? 'subscriptions')
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Billing &amp; Subscriptions</h1>
        <p class="sub">Subscriptions, renewals, GST invoices, coupons &amp; collection.</p>
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
        <TabPanel v-if="auth.can('subscriptions.view')" value="subscriptions"><SubscriptionsPanel /></TabPanel>
        <TabPanel v-if="auth.can('renewals.view')" value="renewals"><RenewalsPanel /></TabPanel>
        <TabPanel v-if="auth.can('invoices.view')" value="invoices"><InvoicesPanel /></TabPanel>
        <TabPanel v-if="auth.can('coupons.view')" value="coupons"><CouponsPanel /></TabPanel>
        <TabPanel v-if="auth.can('settings.view')" value="tax"><TaxPanel /></TabPanel>
        <TabPanel v-if="auth.can('gateways.view')" value="gateways"><GatewaysPanel /></TabPanel>
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
