<script setup lang="ts">
/**
 * Customer-facing display for a POS terminal — meant to be dragged onto a
 * second monitor / pole display and shown full-screen. It holds no state of
 * its own: it mirrors whatever the cashier's PosView broadcasts over the
 * shared BroadcastChannel, so there are no API calls here.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { createDisplayChannel, type CustomerDisplayState } from '../utils/posDevice'

const state = ref<CustomerDisplayState>({
  shopName: 'Point of Sale',
  currencySymbol: '₹',
  lines: [],
  subtotal: 0,
  discount: 0,
  tax: 0,
  total: 0,
  status: 'idle',
})

let channel: BroadcastChannel | null = null

function money(v: number | undefined) {
  return `${state.value.currencySymbol}${(Number(v) || 0).toFixed(2)}`
}

onMounted(() => {
  channel = createDisplayChannel()
  if (channel) {
    channel.onmessage = (e: MessageEvent) => {
      if (e.data?.type === 'update' && e.data.payload) {
        state.value = e.data.payload as CustomerDisplayState
      }
    }
    // Ask the cashier window to push its current state on connect.
    channel.postMessage({ type: 'display-ready' })
  }
})

onBeforeUnmount(() => channel?.close())
</script>

<template>
  <div class="cd" :class="state.status">
    <header class="cd-head">
      <div class="shop">{{ state.shopName }}</div>
      <div class="tag" v-if="state.status === 'done'">Thank you!</div>
      <div class="tag" v-else-if="state.status === 'selling'">Welcome</div>
    </header>

    <div class="cd-body">
      <div class="items" v-if="state.lines.length">
        <div class="row head">
          <span>Item</span><span class="q">Qty</span><span class="a">Amount</span>
        </div>
        <transition-group name="pop" tag="div">
          <div class="row" v-for="(l, i) in state.lines" :key="l.name + i">
            <span class="nm">{{ l.name }}</span>
            <span class="q">{{ l.qty }} × {{ money(l.unit_price) }}</span>
            <span class="a">{{ money(l.line_total) }}</span>
          </div>
        </transition-group>
      </div>
      <div class="empty" v-else>
        <i class="pi pi-shopping-cart"></i>
        <p>{{ state.message || 'Welcome' }}</p>
      </div>
    </div>

    <footer class="cd-foot">
      <div class="sub-lines" v-if="state.lines.length">
        <div><span>Subtotal</span><span>{{ money(state.subtotal) }}</span></div>
        <div v-if="state.discount"><span>Discount</span><span>−{{ money(state.discount) }}</span></div>
        <div><span>GST</span><span>{{ money(state.tax) }}</span></div>
      </div>
      <div class="grand">
        <span>{{ state.status === 'done' ? 'PAID' : 'TOTAL' }}</span>
        <span>{{ money(state.total) }}</span>
      </div>
      <div class="pay" v-if="state.change && state.change > 0">Change: {{ money(state.change) }}</div>
      <div class="pay due" v-else-if="state.balance && state.balance > 0">Balance: {{ money(state.balance) }}</div>
    </footer>
  </div>
</template>

<style scoped>
.cd {
  position: fixed;
  inset: 0;
  display: flex;
  flex-direction: column;
  background: linear-gradient(160deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-family: system-ui, sans-serif;
}
.cd.done { background: linear-gradient(160deg, #064e3b, #065f46); }
.cd-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.5rem 2.5rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.shop { font-size: 2rem; font-weight: 800; letter-spacing: 0.5px; }
.tag { font-size: 1.4rem; font-weight: 600; color: #a7f3d0; }
.cd-body { flex: 1; overflow-y: auto; padding: 1rem 2.5rem; }
.items { font-size: 1.4rem; }
.row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 1.5rem;
  padding: 0.7rem 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.07);
  align-items: baseline;
}
.row.head { color: #94a3b8; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; }
.nm { font-weight: 600; }
.q { color: #cbd5e1; font-size: 1.1rem; white-space: nowrap; }
.a { font-weight: 700; text-align: right; min-width: 8rem; }
.empty { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #64748b; gap: 1rem; }
.empty i { font-size: 5rem; }
.empty p { font-size: 2rem; font-weight: 600; }
.cd-foot { padding: 1.5rem 2.5rem 2.5rem; border-top: 1px solid rgba(255, 255, 255, 0.12); }
.sub-lines { font-size: 1.3rem; color: #cbd5e1; margin-bottom: 0.75rem; }
.sub-lines div { display: flex; justify-content: space-between; padding: 0.2rem 0; }
.grand {
  display: flex;
  justify-content: space-between;
  font-size: 3rem;
  font-weight: 800;
  color: #fbbf24;
}
.cd.done .grand { color: #6ee7b7; }
.pay { margin-top: 0.75rem; font-size: 1.6rem; font-weight: 600; text-align: right; color: #6ee7b7; }
.pay.due { color: #fca5a5; }
.pop-enter-active { transition: all 0.2s ease; }
.pop-enter-from { opacity: 0; transform: translateY(-6px); }
</style>
