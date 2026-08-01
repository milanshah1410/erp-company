<script setup lang="ts">
/**
 * Marketplace (/platform/marketplace) — Volume 11 §"Module Marketplace",
 * §"Integration Marketplace" and §"Device Marketplace".
 *
 * Three shelves, one screen, because the operator question is the same for all:
 * what do we offer, to whom, and who took it up. They stay separately
 * permissioned — `packs.*` is product + commercial, `integrations.*` commercial,
 * `devices.*` engineering — so an operator sees only the tabs their role grants.
 *
 * Module packs differ from the other two in one way worth knowing: integrations and
 * devices ship in code and this panel only decides whether they are on sale, while
 * a pack is a priced row with its own entitlement and its own per-tenant grants.
 * Hence four tabs, not three: the catalogue and who holds it.
 */
import { ref, computed } from 'vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useCentralAuthStore } from '../../stores/centralAuth'
import IntegrationsPanel from './marketplace/IntegrationsPanel.vue'
import DevicesPanel from './marketplace/DevicesPanel.vue'
import PacksPanel from './marketplace/PacksPanel.vue'
import PackGrantsPanel from './marketplace/PackGrantsPanel.vue'

const auth = useCentralAuthStore()

const tabs = computed(() =>
  [
    { value: 'packs', label: 'Module Packs', icon: 'pi pi-box', permission: 'packs.view' },
    { value: 'grants', label: 'Pack Grants', icon: 'pi pi-users', permission: 'packs.view' },
    { value: 'integrations', label: 'Integrations', icon: 'pi pi-link', permission: 'integrations.view' },
    { value: 'devices', label: 'Devices', icon: 'pi pi-desktop', permission: 'devices.view' },
  ].filter((t) => auth.can(t.permission)),
)

const active = ref(tabs.value[0]?.value ?? 'integrations')
</script>

<template>
  <div>
    <div class="header">
      <div>
        <h1>Marketplace</h1>
        <p class="sub">Module packs, integrations and devices — what the platform offers, and to whom.</p>
      </div>
    </div>

    <Tabs v-model:value="active" :lazy="true">
      <TabList>
        <Tab v-for="t in tabs" :key="t.value" :value="t.value">
          <i :class="t.icon" class="tab-icon" /> {{ t.label }}
        </Tab>
      </TabList>
      <TabPanels>
        <TabPanel v-if="auth.can('packs.view')" value="packs"><PacksPanel /></TabPanel>
        <TabPanel v-if="auth.can('packs.view')" value="grants"><PackGrantsPanel /></TabPanel>
        <TabPanel v-if="auth.can('integrations.view')" value="integrations"><IntegrationsPanel /></TabPanel>
        <TabPanel v-if="auth.can('devices.view')" value="devices"><DevicesPanel /></TabPanel>
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
