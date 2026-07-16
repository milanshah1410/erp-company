import { ref } from 'vue'

/**
 * Shared navigation-progress state (§17 Performance — Lazy Loading UX).
 *
 * With route-level code splitting, navigating to a not-yet-loaded view triggers
 * a network fetch for its chunk. This tiny store lets the router hooks toggle a
 * global top progress bar so that fetch never looks like a frozen screen.
 *
 * `active` drives visibility; `progress` (0–100) is nudged forward on a timer
 * while loading and snapped to 100 on completion for a smooth finish.
 */
const active = ref(false)
const progress = ref(0)
let timer: ReturnType<typeof setInterval> | null = null
let doneTimer: ReturnType<typeof setTimeout> | null = null

function start() {
  if (doneTimer) {
    clearTimeout(doneTimer)
    doneTimer = null
  }
  active.value = true
  progress.value = 8
  if (timer) clearInterval(timer)
  // Ease toward 90% while the chunk loads; the final 10% is filled on finish so
  // fast navigations still show a complete-then-fade animation.
  timer = setInterval(() => {
    if (progress.value < 90) {
      progress.value += Math.max(0.5, (90 - progress.value) * 0.15)
    }
  }, 120)
}

function finish() {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
  progress.value = 100
  doneTimer = setTimeout(() => {
    active.value = false
    progress.value = 0
  }, 240)
}

export function useRouteProgress() {
  return { active, progress, start, finish }
}
