<script setup lang="ts">
/**
 * Kosha brand mark — three nested hexagons.
 *
 * कोश means both "storehouse/treasury" and "sheath, layer", which is also how
 * the product is built: core ERP, then the shop profile, then the industry
 * plugin wrapped around it. The outer layer is left open at the top-right so
 * the mark reads as a vault mid-opening rather than a generic hex badge.
 *
 * The rings inherit `currentColor`, so the same component drops into the dark
 * topbar and the light auth card without a variant. Use `tone="mono"` when the
 * mark sits on an amber tile — the gold core would otherwise vanish into it.
 */
withDefaults(
  defineProps<{
    /** Rendered edge length in px. */
    size?: number | string
    /** `duo` keeps the amber core; `mono` draws everything in currentColor. */
    tone?: 'duo' | 'mono'
  }>(),
  { size: 24, tone: 'duo' },
)
</script>

<template>
  <svg
    class="brand-mark"
    :width="size"
    :height="size"
    viewBox="0 0 64 64"
    fill="none"
    role="img"
    aria-label="Kosha"
  >
    <!-- Outer sheath, opened at the top-right edge -->
    <path
      d="M55.38 18.5L55.38 45.5L32 59L8.62 45.5L8.62 18.5L32 5"
      stroke="currentColor"
      stroke-width="5"
      stroke-linejoin="round"
      stroke-linecap="round"
      opacity="0.55"
    />
    <!-- Inner sheath -->
    <path
      d="M32 14.5L47.16 23.25L47.16 40.75L32 49.5L16.84 40.75L16.84 23.25Z"
      stroke="currentColor"
      stroke-width="4.5"
      stroke-linejoin="round"
    />
    <!-- Core -->
    <path
      d="M32 22.5L40.23 27.25L40.23 36.75L32 41.5L23.77 36.75L23.77 27.25Z"
      :fill="tone === 'mono' ? 'currentColor' : 'var(--safety-amber, #e8a33d)'"
    />
  </svg>
</template>

<style scoped>
.brand-mark {
  flex: none;
  display: block;
}
</style>
