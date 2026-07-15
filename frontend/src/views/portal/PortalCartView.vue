<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import portalHttp from '../../api/portalHttp'
import { usePortalAuthStore } from '../../stores/portalAuth'
import { usePortalCartStore } from '../../stores/portalCart'

const router = useRouter()
const toast = useToast()
const auth = usePortalAuthStore()
const cart = usePortalCartStore()

const notes = ref('')
const placing = ref(false)

function money(v: number) {
  return auth.money(v)
}

async function placeOrder() {
  if (!cart.items.length) return
  placing.value = true
  try {
    const { data } = await portalHttp.post('/orders', { items: cart.payload(), notes: notes.value || null })
    cart.clear()
    notes.value = ''
    toast.add({ severity: 'success', summary: 'Order placed', detail: `${data.data.so_number} is awaiting approval.`, life: 4000 })
    router.push({ name: 'portal-orders' })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Could not place order', detail: e.response?.data?.message || 'Please try again.', life: 5000 })
  } finally {
    placing.value = false
  }
}
</script>

<template>
  <div class="cart-wrap">
    <h1>Your Cart</h1>

    <div v-if="!cart.items.length" class="empty">
      <i class="pi pi-shopping-cart" />
      <p>Your cart is empty.</p>
      <Button label="Browse catalogue" @click="router.push({ name: 'portal-catalogue' })" />
    </div>

    <div v-else class="layout">
      <div class="lines">
        <div v-for="item in cart.items" :key="cart.keyOf(item.product_id, item.variant_id)" class="line">
          <div class="line-info">
            <div class="line-name">{{ item.name }}<span v-if="item.variant_name" class="variant"> · {{ item.variant_name }}</span></div>
            <div class="line-price">{{ money(item.price) }}<span v-if="item.unit"> / {{ item.unit }}</span></div>
          </div>
          <InputNumber :modelValue="item.quantity" :min="1" :minFractionDigits="0" :maxFractionDigits="3" showButtons buttonLayout="horizontal"
            class="qty" @update:modelValue="(v: number) => cart.setQty(item.product_id, item.variant_id, v)" />
          <div class="line-total">{{ money(item.price * item.quantity) }}</div>
          <Button icon="pi pi-times" text rounded severity="danger" size="small" @click="cart.remove(item.product_id, item.variant_id)" />
        </div>
      </div>

      <div class="summary">
        <h3>Order Summary</h3>
        <div class="row"><span>Items</span><span>{{ cart.count }}</span></div>
        <div class="row total"><span>Estimated total</span><span>{{ money(cart.total) }}</span></div>
        <p class="note-hint">Final pricing &amp; taxes are confirmed on approval.</p>

        <label class="lbl">Notes (optional)</label>
        <Textarea v-model="notes" rows="3" placeholder="Delivery instructions, PO reference…" class="notes" />

        <Button label="Place Order" icon="pi pi-check" class="place" :loading="placing" @click="placeOrder" />
        <Button label="Clear cart" text severity="secondary" class="clear" @click="cart.clear()" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.cart-wrap h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.25rem; }
.empty { text-align: center; color: #6b7280; padding: 3rem; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; }
.empty i { font-size: 2.5rem; color: #d1d5db; }
.layout { display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem; align-items: start; }
.lines { display: flex; flex-direction: column; gap: 0.75rem; }
.line { display: grid; grid-template-columns: 1fr auto auto auto; gap: 1rem; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0.85rem 1rem; }
.line-name { font-weight: 600; font-size: 0.95rem; }
.variant { color: #6b7280; font-weight: 400; }
.line-price { color: #6b7280; font-size: 0.8rem; }
.qty :deep(input) { width: 70px; }
.line-total { font-weight: 600; white-space: nowrap; min-width: 80px; text-align: right; }
.summary { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; position: sticky; top: 5rem; }
.summary h3 { font-size: 1.05rem; margin-bottom: 0.75rem; }
.row { display: flex; justify-content: space-between; padding: 0.25rem 0; font-size: 0.9rem; }
.row.total { font-size: 1.1rem; font-weight: 700; border-top: 1px solid #e5e7eb; margin-top: 0.35rem; padding-top: 0.5rem; }
.note-hint { font-size: 0.75rem; color: #6b7280; margin: 0.35rem 0 1rem; }
.lbl { font-size: 0.85rem; font-weight: 500; color: #374151; }
.notes { width: 100%; margin-top: 0.35rem; }
.place { width: 100%; margin-top: 1rem; }
.clear { width: 100%; margin-top: 0.5rem; }
@media (max-width: 820px) { .layout { grid-template-columns: 1fr; } }
</style>
