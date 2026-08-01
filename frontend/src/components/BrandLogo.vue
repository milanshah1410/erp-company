<script setup lang="ts">
/**
 * The brand mark for whichever workspace we are in (Volume 11 §"White Label").
 *
 * A white-labelled tenant gets its own logo and display name; everyone else
 * gets the platform lockup exactly as before. Every screen that shows a logo
 * goes through here, so switching a tenant's brand is one setting rather than a
 * hunt through the layouts.
 *
 * Falls back to the component form of the logo (BrandLockup / BrandMark), never
 * the standalone SVG file — see BrandLockup for why that matters on dark rails.
 */
import { computed } from 'vue'
import BrandLockup from './BrandLockup.vue'
import BrandMark from './BrandMark.vue'
import { useBranding } from '../composables/useBranding'

const props = withDefaults(
  defineProps<{
    /** Rendered height in px. */
    height?: number
    /**
     * `lockup` = mark + wordmark in one graphic (auth screens); `mark` = the
     * mark alone, with the name set beside it by the caller (app topbar).
     */
    variant?: 'lockup' | 'mark'
    /** Set the workspace name next to a custom logo (which may be mark-only). */
    withName?: boolean
  }>(),
  { height: 32, variant: 'lockup', withName: false },
)

const { branding, logoUrl, isWhiteLabelled } = useBranding()

// A custom logo of unknown aspect ratio: cap the height and let the width
// follow, so a wide wordmark and a square mark both sit on the same baseline.
const imgStyle = computed(() => ({ height: `${props.height}px`, maxHeight: `${props.height}px` }))

// The name is set beside the graphic only for a branded workspace: on the
// platform brand the wordmark is already part of the lockup, and the topbar
// sets its own. This is also why a name-only white label (custom name, no logo
// uploaded) still reads correctly — the platform mark keeps the tenant's name.
const showName = computed(() => props.withName && isWhiteLabelled.value)
</script>

<template>
  <span class="brand-logo">
    <img v-if="logoUrl" :src="logoUrl" :alt="branding.app_name" :style="imgStyle" />
    <BrandLockup v-else-if="variant === 'lockup'" :height="height" />
    <BrandMark v-else :size="height" />
    <span v-if="showName" class="brand-logo-name">{{ branding.app_name }}</span>
  </span>
</template>

<style scoped>
.brand-logo {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}
.brand-logo img {
  width: auto;
  object-fit: contain;
}
.brand-logo-name {
  font-weight: 700;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
</style>
