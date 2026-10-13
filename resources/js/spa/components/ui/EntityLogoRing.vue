<script setup lang="ts">
import { computed } from 'vue'

/*
 * An animated ring around a business logo on a listing card, coloured by what
 * kind of business it is:
 *
 *   SBO (Strategic Business Organization, ministry-owned) -> blue
 *   COB (Citizen Owned Business)                          -> gold
 *
 * The ring is a conic gradient that slowly rotates, so the logo reads as a
 * "badge" at a glance without a legend. Anything that is neither gets no ring —
 * the logo is passed straight through, so this is safe to wrap every card with.
 *
 * The logo itself goes in the default slot; the wrapper only draws the band
 * around it, so it works at any logo size. `radius` matches the logo's own
 * corner — 9999px for the round tiles, a smaller value for the rounded-square
 * ones — so the band hugs the shape instead of forcing a circle onto a square.
 */

const props = withDefaults(
  defineProps<{
    /** The Sbo's entity_type — 'SBO' or 'COB' (case-insensitive); null/other = no ring. */
    entity?: string | null
    /** CSS border-radius for the band, to match the logo it wraps. */
    radius?: string
  }>(),
  { radius: '9999px' },
)

const kind = computed<'sbo' | 'cob' | null>(() => {
  const value = (props.entity ?? '').toUpperCase()

  if (value === 'SBO') return 'sbo'
  if (value === 'COB') return 'cob'

  return null
})
</script>

<template>
  <!-- No ring for an unknown type: pass the logo through untouched. -->
  <slot v-if="!kind" />
  <span
    v-else
    class="elr"
    :class="`elr--${kind}`"
    :style="{ '--elr-radius': props.radius }"
  >
    <span class="elr__logo">
      <slot />
    </span>
  </span>
</template>

<style scoped>
/* The animated angle. Registered so it can be tweened — animating the gradient's
   own angle (rather than rotating the element) keeps the band gap-free at any
   corner radius. Where @property is unsupported the ring simply sits still. */
@property --elr-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

.elr {
  position: relative;
  display: inline-flex;
  border-radius: var(--elr-radius, 9999px);
  padding: 3px; /* ring thickness */
  isolation: isolate;
  /* The band wraps a fixed-size logo and must stay square. In a flex row the
     default align-items: stretch would pull it into an oval, so anchor it to the
     top (where every card places its logo) and let it size to its content. */
  align-self: flex-start;
}

/* The rotating colour band. Sits behind the logo; only the padding shows. */
.elr::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  z-index: 0;
  background: conic-gradient(
    from var(--elr-angle),
    var(--elr-c1),
    var(--elr-c2),
    var(--elr-c1),
    var(--elr-c2),
    var(--elr-c1)
  );
  animation: elr-spin 3s linear infinite;
}

/* A thin surface-coloured gap between the band and the logo, so the ring reads
   as a ring rather than a thick border touching the logo. */
.elr__logo {
  position: relative;
  z-index: 1;
  display: inline-flex;
  border-radius: var(--elr-radius, 9999px);
  padding: 2px;
  background: var(--color-surface, #fff);
}

.elr--sbo {
  --elr-c1: #1d4ed8;
  --elr-c2: #60a5fa;
  box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.15), 0 0 10px rgba(37, 99, 235, 0.35);
}

.elr--cob {
  --elr-c1: #b8860b;
  --elr-c2: #ffe08a;
  box-shadow: 0 0 0 1px rgba(184, 134, 11, 0.18), 0 0 10px rgba(212, 160, 23, 0.4);
}

@keyframes elr-spin {
  to {
    --elr-angle: 360deg;
  }
}

@media (prefers-reduced-motion: reduce) {
  .elr::before {
    animation: none;
  }
}
</style>
