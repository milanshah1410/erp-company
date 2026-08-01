<script setup lang="ts">
/**
 * The public QR menu a guest sees after scanning the sticker on their table.
 *
 * Unauthenticated (router meta.public) and read-only. It is built for a phone
 * held one-handed at a table: big type, a sticky veg/non-veg filter, and no
 * chrome from the ERP around it — the guest is a customer of the restaurant, not
 * a user of this software, and should never see that an ERP is involved.
 *
 * There is no ordering. That is a deliberate product decision, not an omission,
 * so the page says so rather than leaving someone hunting for a button.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import http from '../api/http'

const route = useRoute()

const loading = ref(true)
const failed = ref(false)
const menu = ref<any>(null)
const filter = ref<'all' | 'veg' | 'non_veg'>('all')

const symbol = computed(() => menu.value?.currency_symbol ?? '₹')

function price(v: number) {
  return `${symbol.value}${Number(v).toFixed(2)}`
}

function dotColour(type: string | null) {
  if (type === 'veg' || type === 'vegan') return '#0a8f3c'
  if (type === 'non_veg') return '#c62828'
  if (type === 'egg') return '#e0a800'
  return null
}

/** Veg / non-veg filtering, with egg counted as non-veg the way menus do. */
const groups = computed(() => {
  if (!menu.value) return []

  return menu.value.groups
    .map((g: any) => ({
      category: g.category,
      items: g.items.filter((i: any) => {
        if (filter.value === 'all') return true
        if (filter.value === 'veg') return i.food_type === 'veg' || i.food_type === 'vegan'
        return i.food_type === 'non_veg' || i.food_type === 'egg'
      }),
    }))
    .filter((g: any) => g.items.length)
})

onMounted(async () => {
  try {
    const { data } = await http.get(`/menu/${route.params.token}`)
    menu.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="qr-menu">
    <div v-if="loading" class="state">Loading the menu…</div>

    <div v-else-if="failed" class="state">
      <h2>Menu not available</h2>
      <p>This code is not valid any more. Please ask a member of staff.</p>
    </div>

    <template v-else>
      <header class="head">
        <h1>{{ menu.company_name }}</h1>
        <p v-if="menu.table" class="table-name">Table {{ menu.table }}</p>
        <p class="view-only">Menu only — please order with a member of staff.</p>
      </header>

      <div class="filters">
        <button :class="{ on: filter === 'all' }" @click="filter = 'all'">All</button>
        <button :class="{ on: filter === 'veg' }" @click="filter = 'veg'">
          <span class="dot" style="border-color: #0a8f3c"><i style="background: #0a8f3c" /></span>
          Veg
        </button>
        <button :class="{ on: filter === 'non_veg' }" @click="filter = 'non_veg'">
          <span class="dot" style="border-color: #c62828"><i style="background: #c62828" /></span>
          Non-veg
        </button>
      </div>

      <section v-for="g in groups" :key="g.category" class="group">
        <h2>{{ g.category }}</h2>
        <ul>
          <li v-for="(item, i) in g.items" :key="i" :class="{ off: !item.is_available }">
            <div class="item-main">
              <div class="item-title">
                <span
                  v-if="dotColour(item.food_type)"
                  class="dot"
                  :style="{ borderColor: dotColour(item.food_type)! }"
                >
                  <i :style="{ background: dotColour(item.food_type)! }" />
                </span>
                <span class="name">{{ item.name }}</span>
              </div>
              <p v-if="item.description" class="desc">{{ item.description }}</p>
              <p v-if="item.prep_minutes" class="prep">About {{ item.prep_minutes }} min</p>
            </div>
            <div class="item-side">
              <span class="price">{{ price(item.price) }}</span>
              <span v-if="!item.is_available" class="sold-out">Sold out</span>
            </div>
          </li>
        </ul>
      </section>

      <p v-if="!groups.length" class="state">Nothing on the menu matches that filter.</p>

      <footer class="foot">Prices include applicable taxes unless stated otherwise.</footer>
    </template>
  </div>
</template>

<style scoped>
.qr-menu {
  max-width: 640px;
  margin: 0 auto;
  padding: 1.2rem 1rem 3rem;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2430;
  background: #fff;
  min-height: 100vh;
}

.state {
  text-align: center;
  padding: 3rem 1rem;
  color: #6b7280;
}

.head {
  text-align: center;
  border-bottom: 2px solid #1f2430;
  padding-bottom: 0.9rem;
  margin-bottom: 0.9rem;
}
.head h1 {
  margin: 0;
  font-size: 1.6rem;
  letter-spacing: 0.02em;
}
.table-name {
  margin: 0.3rem 0 0;
  font-weight: 600;
  color: #374151;
}
.view-only {
  margin: 0.4rem 0 0;
  font-size: 0.78rem;
  color: #6b7280;
}

.filters {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  gap: 0.5rem;
  padding: 0.6rem 0;
  background: #fff;
}
.filters button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.45rem 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 999px;
  background: #fff;
  font-size: 0.85rem;
  cursor: pointer;
}
.filters button.on {
  background: #1f2430;
  color: #fff;
  border-color: #1f2430;
}

.dot {
  width: 12px;
  height: 12px;
  border: 2px solid;
  border-radius: 2px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
}
.dot i {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  display: block;
}

.group {
  margin-top: 1.4rem;
}
.group h2 {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6b7280;
  margin: 0 0 0.5rem;
}
.group ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.group li {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 0;
  border-bottom: 1px dashed #e5e7eb;
}
.group li.off {
  opacity: 0.45;
}

.item-title {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}
.name {
  font-weight: 600;
}
.desc {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
  color: #6b7280;
  line-height: 1.35;
}
.prep {
  margin: 0.2rem 0 0;
  font-size: 0.72rem;
  color: #9ca3af;
}

.item-side {
  text-align: right;
  white-space: nowrap;
}
.price {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.sold-out {
  display: block;
  font-size: 0.7rem;
  color: #c62828;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.foot {
  margin-top: 2rem;
  text-align: center;
  font-size: 0.72rem;
  color: #9ca3af;
}

/* The guest's phone may well be in dark mode; the card should still read. */
@media (prefers-color-scheme: dark) {
  .qr-menu { background: #14161c; color: #e8eaf0; }
  .head { border-bottom-color: #e8eaf0; }
  .table-name { color: #c3c8d4; }
  .filters { background: #14161c; }
  .filters button { background: #14161c; color: #e8eaf0; border-color: #333846; }
  .filters button.on { background: #e8eaf0; color: #14161c; border-color: #e8eaf0; }
  .group li { border-bottom-color: #2a2f3a; }
}
</style>
