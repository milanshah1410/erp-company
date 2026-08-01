<script setup lang="ts">
/**
 * Kosha lockup — mark + wordmark, the full logo.
 *
 * This is the component form of public/logo-kosha.svg. The difference matters:
 * that file has to guess its own colours from `prefers-color-scheme`, which
 * reads the OS theme rather than the background it was actually dropped onto —
 * so a dark-OS user viewing a light page gets an invisible wordmark. Here the
 * sheath and wordmark inherit `currentColor`, so the parent decides and it is
 * right by construction on any surface.
 *
 * Use the standalone file only where a component cannot go (README, e-mail,
 * invoice letterhead); use this everywhere inside the app.
 */
withDefaults(
  defineProps<{
    /** Rendered height in px; width follows the 272:72 aspect. */
    height?: number
    /** `duo` keeps the amber core; `mono` draws everything in currentColor. */
    tone?: 'duo' | 'mono'
  }>(),
  { height: 32, tone: 'duo' },
)
</script>

<template>
  <svg
    class="brand-lockup"
    :height="height"
    :width="height * (272 / 72)"
    viewBox="0 0 272 72"
    fill="none"
    role="img"
    aria-label="Kosha"
  >
    <!--
      Stroke weights are heavier than the original logo file's (6.5/5.5 vs
      5/4.5) and the outer sheath is less faded (0.72 vs 0.55). At the ~36-44px
      the auth screens render this at, the lighter original went wispy and lost
      against the headline next to it. The rings still clear each other: outer
      inner-edge 23.75, inner outer-edge 20.25, so a 3.5-unit gap survives.
    -->
    <g transform="translate(6 4)">
      <!-- Outer sheath, opened at the top-right edge -->
      <path
        d="M55.38 18.5L55.38 45.5L32 59L8.62 45.5L8.62 18.5L32 5"
        stroke="currentColor"
        stroke-width="6.5"
        stroke-linejoin="round"
        stroke-linecap="round"
        opacity="0.72"
      />
      <!-- Inner sheath -->
      <path
        d="M32 14.5L47.16 23.25L47.16 40.75L32 49.5L16.84 40.75L16.84 23.25Z"
        stroke="currentColor"
        stroke-width="5.5"
        stroke-linejoin="round"
      />
      <!-- Core, fattened to keep pace with the heavier rings -->
      <path
        d="M32 21.5L41.53 27L41.53 38L32 43.5L22.47 38L22.47 27Z"
        :fill="tone === 'mono' ? 'currentColor' : 'var(--safety-amber, #e8a33d)'"
      />
    </g>

    <text
      x="82"
      y="49"
      fill="currentColor"
      font-family="'Space Grotesk','Inter','Segoe UI',system-ui,sans-serif"
      font-size="40"
      font-weight="700"
      letter-spacing="-0.5"
    >kosha</text>
  </svg>
</template>

<style scoped>
.brand-lockup {
  flex: none;
  display: block;
}
</style>
