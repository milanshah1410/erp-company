import { defineStore } from 'pinia'

export interface CartItem {
  product_id: number
  variant_id: number | null
  name: string
  variant_name: string | null
  unit: string | null
  price: number
  quantity: number
  available: number
}

function keyOf(productId: number, variantId: number | null): string {
  return `${productId}:${variantId ?? ''}`
}

/**
 * Client-side B2B order basket. Prices shown are the customer's resolved
 * prices; the server re-prices authoritatively when the order is placed.
 */
export const usePortalCartStore = defineStore('portalCart', {
  state: () => ({
    items: [] as CartItem[],
  }),
  getters: {
    count: (state) => state.items.reduce((n, i) => n + i.quantity, 0),
    lineCount: (state) => state.items.length,
    total: (state) => state.items.reduce((t, i) => t + i.price * i.quantity, 0),
    has: (state) => (productId: number, variantId: number | null) =>
      state.items.some((i) => i.product_id === productId && i.variant_id === variantId),
  },
  actions: {
    add(item: Omit<CartItem, 'quantity'>, quantity = 1) {
      const existing = this.items.find(
        (i) => i.product_id === item.product_id && i.variant_id === item.variant_id,
      )
      if (existing) {
        existing.quantity += quantity
      } else {
        this.items.push({ ...item, quantity })
      }
    },
    setQty(productId: number, variantId: number | null, quantity: number) {
      const item = this.items.find((i) => i.product_id === productId && i.variant_id === variantId)
      if (!item) return
      if (quantity <= 0) {
        this.remove(productId, variantId)
      } else {
        item.quantity = quantity
      }
    },
    remove(productId: number, variantId: number | null) {
      this.items = this.items.filter(
        (i) => !(i.product_id === productId && i.variant_id === variantId),
      )
    },
    clear() {
      this.items = []
    },
    /** Lines shaped for POST /portal/orders. */
    payload() {
      return this.items.map((i) => ({
        product_id: i.product_id,
        variant_id: i.variant_id,
        quantity: i.quantity,
      }))
    },
    keyOf,
  },
})
