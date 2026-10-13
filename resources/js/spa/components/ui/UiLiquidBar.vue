<script setup lang="ts">
import { computed } from 'vue'

/*
 * The hero progress tracker's bar, with liquid motion.
 *
 * Three screens drew their own `<div class="h-full rounded bg-…">` inside a
 * dark track — the payment wall's pinned at a hardcoded 25% — so the bar looked
 * identical whether an application was moving or stuck. This is that bar, once,
 * and its fill now reads as something in motion.
 *
 * Separate from UiProgressBar, which is the Figma design-system component
 * (Progress Variants 265:218) with its own label row, light track and fixed
 * height. These hero bars sit on dark heroes in three different colours.
 *
 * The motion is two wave crests riding the fill at unrelated speeds, plus a
 * sheen travelling along it. Two waves rather than one because a single sine on
 * a loop reads as a rotating barber pole; two whose periods never line up read
 * as liquid. The crests are SVG with `preserveAspectRatio="none"`, so the same
 * shape works at any bar height without being redrawn.
 */

const props = withDefaults(
  defineProps<{
    /** 0-100. Clamped, because over 100 would draw past the track. */
    value: number
    /**
     * Fill colour. These bars are amber while in progress, green when done.
     * The fill animates BETWEEN this and `colorAlt`, so the bar reads as live
     * rather than as a flat block of colour.
     */
    color?: string
    /** The far end of the colour cycle. Defaults to a lighter cast of `color`. */
    colorAlt?: string
    trackColor?: string
    /** Bar height in pixels. */
    height?: number
    label?: string
  }>(),
  {
    color: '#e8a317',
    colorAlt: '#f6c453',
    trackColor: 'rgba(255,255,255,0.13)',
    height: 8,
    label: 'Progress',
  },
)

const percent = computed(() => Math.min(100, Math.max(0, Math.round(props.value))))
</script>

<template>
  <div
    class="liquid"
    role="progressbar"
    :aria-valuenow="percent"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-label="label"
    :style="{ height: `${height}px`, background: trackColor }"
  >
    <!--
      The fill is a three-stop gradient twice the bar's width, slid sideways
      on a loop: the colour itself travels, so a stalled application still
      looks like a live indicator rather than a painted block.
    -->
    <div
      class="liquid__fill"
      :style="{
        width: `${percent}%`,
        backgroundImage: `linear-gradient(90deg, ${color}, ${colorAlt}, ${color})`,
      }"
    >
      <!--
        `viewBox` is twice the tile width so the path repeats seamlessly while
        the layer translates by exactly half its own width.
      -->
      <svg class="liquid__wave liquid__wave--back" viewBox="0 0 240 24" preserveAspectRatio="none">
        <path
          d="M0 12 C 20 4, 40 20, 60 12 S 100 4, 120 12 S 160 20, 180 12 S 220 4, 240 12 L240 24 L0 24 Z"
        />
      </svg>
      <svg class="liquid__wave liquid__wave--front" viewBox="0 0 240 24" preserveAspectRatio="none">
        <path d="M0 14 C 30 22, 50 6, 80 14 S 120 22, 160 14 S 200 6, 240 14 L240 24 L0 24 Z" />
      </svg>

      <span class="liquid__sheen" aria-hidden="true" />
    </div>
  </div>
</template>

<style scoped>
.liquid {
  position: relative;
  flex: 1 1 0%;
  overflow: hidden;
  border-radius: 9999px;
}

.liquid__fill {
  position: relative;
  height: 100%;
  overflow: hidden;
  border-radius: 9999px;
  /* The width travels too: a bar that jumps from 25% to 50% on a page load
     tells you less than one that moves there. */
  transition: width 900ms cubic-bezier(0.22, 1, 0.36, 1);
  /* Twice the fill width so the gradient has somewhere to travel to. */
  background-size: 200% 100%;
  animation: liquid-hue 3.8s ease-in-out infinite;
}

@keyframes liquid-hue {
  0%,
  100% {
    background-position: 0% 50%;
  }
  50% {
    background-position: 100% 50%;
  }
}

.liquid__wave {
  position: absolute;
  top: 0;
  left: 0;
  /* 200% so the tile repeats across the fill; translating 50% loops it. */
  width: 200%;
  height: 100%;
  fill: rgb(255 255 255 / 0.28);
}

.liquid__wave--back {
  animation: liquid-drift 2.6s linear infinite;
}

.liquid__wave--front {
  fill: rgb(255 255 255 / 0.16);
  /* An unrelated period, so the two never settle into a pattern. */
  animation: liquid-drift 4.1s linear infinite reverse;
}

.liquid__sheen {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    100deg,
    transparent 20%,
    rgb(255 255 255 / 0.42) 50%,
    transparent 80%
  );
  transform: translateX(-100%);
  animation: liquid-sheen 3.4s ease-in-out infinite;
}

@keyframes liquid-drift {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}

@keyframes liquid-sheen {
  0% {
    transform: translateX(-100%);
  }
  /* Still for most of the cycle, so it reads as an occasional glint rather
     than a strobe. */
  60%,
  100% {
    transform: translateX(200%);
  }
}

/*
 * The motion is decoration — the percentage beside the bar carries the meaning
 * — so it stops entirely rather than merely slowing.
 */
@media (prefers-reduced-motion: reduce) {
  .liquid__fill {
    transition: none;
    animation: none;
  }

  .liquid__wave {
    animation: none;
  }

  .liquid__sheen {
    display: none;
  }
}
</style>
