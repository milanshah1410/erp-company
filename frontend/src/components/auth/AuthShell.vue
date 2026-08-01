<script setup lang="ts">
/**
 * Split-screen shell for the signup journey (register → verify → provisioning).
 *
 * Left: a dark brand rail that carries the narrative — where you are in the
 * flow and what you get at the end. Right: the actual form. Below 960px the
 * rail folds into a compact header so the form owns the whole viewport, which
 * is where most self-serve signups actually happen.
 */
import type { AuthStep } from './types'
import BrandLogo from '../BrandLogo.vue'

withDefaults(
  defineProps<{
    /** Vertical step rail. Omit for single-screen states (verify, provisioning). */
    steps?: AuthStep[]
    /** 1-based index of the step in progress. */
    current?: number
    /** Small caps line above the headline. */
    eyebrow?: string
    headline?: string
    blurb?: string
    /** Roomier card for the multi-column signup form. */
    wide?: boolean
    /** Reassurance strip pinned to the bottom of the rail. */
    perks?: { icon: string; label: string }[]
    /**
     * Selling points for the middle of the rail. Only rendered on screens with
     * no step list — otherwise the spine owns that space.
     */
    highlights?: { icon: string; label: string }[]
  }>(),
  {
    steps: () => [],
    current: 1,
    eyebrow: '',
    headline: '',
    blurb: '',
    wide: false,
    perks: () => [
      { icon: 'pi pi-clock', label: '14-day free trial' },
      { icon: 'pi pi-credit-card', label: 'No card required' },
      { icon: 'pi pi-lock', label: 'Your data stays yours' },
    ],
    highlights: () => [],
  },
)
</script>

<template>
  <div class="auth">
    <!-- Brand rail -->
    <aside class="rail">
      <div class="rail-glow" aria-hidden="true" />
      <div class="rail-grid" aria-hidden="true" />

      <div class="rail-inner">
        <!-- Full lockup rather than mark + hand-set text: one component owns the
             logo, so kerning and the wordmark can never drift between screens.
             It inherits .rail's colour, which is why the sheath reads light.
             On a white-labelled host it is the tenant's own logo instead — this
             screen is unauthenticated, so the brand comes from the host
             (composables/useBranding.ts). -->
        <BrandLogo :height="44" variant="lockup" with-name class="lockup" />

        <div class="pitch">
          <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
          <h2 v-if="headline">{{ headline }}</h2>
          <p v-if="blurb" class="blurb">{{ blurb }}</p>
        </div>

        <ol v-if="steps.length" class="rail-steps">
          <li
            v-for="(s, i) in steps"
            :key="s.key"
            :class="{ done: i + 1 < current, on: i + 1 === current }"
          >
            <span class="bullet">
              <i v-if="i + 1 < current" class="pi pi-check" />
              <span v-else>{{ i + 1 }}</span>
            </span>
            <span class="step-text">
              <span class="step-label">{{ s.label }}</span>
              <span v-if="s.hint" class="step-hint">{{ s.hint }}</span>
            </span>
          </li>
        </ol>

        <ul v-if="!steps.length && highlights.length" class="highlights">
          <li v-for="h in highlights" :key="h.label">
            <span class="hl-icon"><i :class="h.icon" /></span>
            <span>{{ h.label }}</span>
          </li>
        </ul>

        <ul v-if="perks.length" class="perks">
          <li v-for="p in perks" :key="p.label"><i :class="p.icon" />{{ p.label }}</li>
        </ul>
      </div>
    </aside>

    <!-- Compact header for the same rail on small screens -->
    <header class="mini">
      <!-- The headline is dropped here on purpose: the card below already leads
           with an h1, and stacking a long pitch line under the logo on a phone
           pushes the first field off-screen. Logo + where-am-I is enough. -->
      <BrandLogo :height="30" variant="lockup" class="mini-logo" />
      <small v-if="steps.length">Step {{ current }} of {{ steps.length }} · {{ steps[current - 1]?.label }}</small>
      <small v-else-if="blurb">{{ blurb }}</small>
      <span v-if="steps.length" class="mini-bar">
        <i :style="{ width: (current / steps.length) * 100 + '%' }" />
      </span>
    </header>

    <!-- Form column -->
    <main class="stage">
      <div class="card" :class="{ wide }">
        <slot />
      </div>
      <p class="legal">
        <slot name="footer">
          By continuing you agree to our Terms of Service and Privacy Policy.
        </slot>
      </p>
    </main>
  </div>
</template>

<style scoped>
.auth {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(320px, 30%) 1fr;
  background: #f4f1ea;
}

/* ---------------- Brand rail ---------------- */
.rail {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(120% 90% at 15% 0%, #35586e 0%, transparent 55%),
    linear-gradient(165deg, #2c4a5e 0%, #23303a 45%, #171a1e 100%);
  color: #e8eaec;
  padding: 2.5rem 2.25rem;
  display: flex;
}
.rail-glow {
  position: absolute;
  inset: auto -30% -35% -30%;
  height: 60%;
  background: radial-gradient(closest-side, rgba(232, 163, 61, 0.28), transparent 70%);
  filter: blur(6px);
  pointer-events: none;
}
/* Faint barcode ruling — the same motif the app uses for section breaks. */
.rail-grid {
  position: absolute;
  inset: 0;
  opacity: 0.05;
  background-image: repeating-linear-gradient(
    90deg,
    #fff 0 1px,
    transparent 1px 4px,
    #fff 4px 6px,
    transparent 6px 11px
  );
  mask-image: linear-gradient(180deg, transparent, #000 30%, #000 70%, transparent);
  pointer-events: none;
}
.rail-inner {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 2rem;
  width: 100%;
}
/* The lockup inherits .rail's colour for its sheath + wordmark; the amber core
   is baked into the component, so it stays gold on this dark ground. */
.lockup {
  color: #fff;
}
.pitch .eyebrow {
  margin: 0 0 0.5rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #e8a33d;
}
.pitch h2 {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.65rem;
  line-height: 1.2;
  color: #fff;
  margin: 0;
}
.blurb {
  margin: 0.6rem 0 0;
  font-size: 0.9rem;
  line-height: 1.55;
  color: #a9b3ba;
  max-width: 34ch;
}

/* Step rail: a connected spine so progress reads at a glance. */
.rail-steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.rail-steps li {
  position: relative;
  display: flex;
  gap: 0.85rem;
  padding: 0.55rem 0;
}
.rail-steps li:not(:last-child) .bullet::after {
  content: '';
  position: absolute;
  left: 50%;
  top: 100%;
  transform: translateX(-50%);
  width: 2px;
  height: calc(0.7rem + 8px);
  background: rgba(255, 255, 255, 0.12);
}
.rail-steps li.done .bullet::after {
  background: rgba(76, 139, 108, 0.75);
}
.bullet {
  position: relative;
  flex: none;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 0.75rem;
  font-weight: 700;
  color: #8d979e;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.14);
  transition: all 0.25s ease;
}
.rail-steps li.on .bullet {
  background: linear-gradient(135deg, #f2c274, #c98730);
  border-color: transparent;
  color: #1e2328;
  box-shadow: 0 0 0 5px rgba(232, 163, 61, 0.16);
}
.rail-steps li.done .bullet {
  background: #4c8b6c;
  border-color: transparent;
  color: #fff;
}
.step-text {
  display: flex;
  flex-direction: column;
  padding-top: 0.15rem;
}
.step-label {
  font-size: 0.9rem;
  font-weight: 600;
  color: #8d979e;
  transition: color 0.2s;
}
.rail-steps li.on .step-label {
  color: #fff;
}
.rail-steps li.done .step-label {
  color: #cfd5d9;
}
.step-hint {
  font-size: 0.76rem;
  color: #6f7b83;
  line-height: 1.4;
}
/* Fills the space the step spine would occupy on single-screen states. */
.highlights {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.highlights li {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  font-size: 0.88rem;
  line-height: 1.4;
  color: #cfd5d9;
}
.hl-icon {
  flex: none;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  background: rgba(232, 163, 61, 0.13);
  border: 1px solid rgba(232, 163, 61, 0.22);
  color: #e8a33d;
  font-size: 0.8rem;
}

.perks {
  list-style: none;
  margin: auto 0 0;
  padding: 1.1rem 0 0;
  border-top: 1px solid rgba(255, 255, 255, 0.09);
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.perks li {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.8rem;
  color: #97a2a9;
}
.perks i {
  color: #4c8b6c;
  font-size: 0.85rem;
}

/* ---------------- Form column ---------------- */
.mini {
  display: none;
}
.stage {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 1.75rem;
  overflow-y: auto;
}
.card {
  width: 100%;
  max-width: 440px;
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 18px;
  padding: 2.25rem;
  box-shadow: 0 1px 2px rgba(30, 35, 40, 0.04), 0 18px 45px rgba(30, 35, 40, 0.09);
}
.card.wide {
  max-width: 620px;
}
.legal {
  max-width: 620px;
  margin: 1.1rem 0 0;
  text-align: center;
  font-size: 0.75rem;
  line-height: 1.5;
  color: #98928a;
}

@media (max-width: 960px) {
  .auth {
    grid-template-columns: 1fr;
    grid-template-rows: auto 1fr;
  }
  .rail {
    display: none;
  }
  /* Stacked, not a row: the lockup is wide, so sitting text beside it would
     squeeze both. Centred reads as a masthead. */
  .mini {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.3rem;
    padding: 1rem 1.1rem 1.15rem;
    background: linear-gradient(135deg, #2c4a5e 0%, #23303a 60%, #171a1e 100%);
    color: #fff;
    text-align: center;
  }
  .mini small {
    font-size: 0.75rem;
    color: #a9b3ba;
  }
  .mini-bar {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: rgba(255, 255, 255, 0.12);
  }
  .mini-bar i {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #f2c274, #c98730);
    transition: width 0.35s ease;
  }
  .stage {
    padding: 1.25rem 1rem 2rem;
    justify-content: flex-start;
  }
  .card,
  .card.wide {
    max-width: 560px;
    padding: 1.5rem 1.25rem;
    border-radius: 14px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .bullet,
  .mini-bar i,
  .step-label {
    transition: none;
  }
}
</style>
