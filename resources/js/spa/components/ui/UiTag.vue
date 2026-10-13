<script setup lang="ts">
/*
 * Tag / badge — Figma Tag Variants 265:89 (pills, 23px tall).
 *   Tag/Success 265:90 · Warning 265:92 · Info 265:94
 *   Neutral 265:96 · Premium 265:98 · Ad 265:100
 *
 * NOTE: colours here are derived from the semantic tokens rather than pulled
 * per-variant from Figma. The pill geometry (height, radius) is from the
 * metadata; the exact per-variant fills were not individually inspected.
 */

type Tone = 'success' | 'warning' | 'info' | 'neutral' | 'premium' | 'ad'

const props = withDefaults(defineProps<{ tone?: Tone }>(), { tone: 'neutral' })

const tones: Record<Tone, string> = {
  success: 'bg-green-100 text-green-700',
  warning: 'bg-amber-100 text-amber-700',
  info: 'bg-blue-100 text-blue-700',
  neutral: 'bg-slate-100 text-slate-600',
  premium: 'bg-amber-500 text-surface-dark',
  ad: 'bg-slate-200 text-slate-600',
}
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-2xs font-semibold whitespace-nowrap"
    :class="tones[props.tone]"
  >
    <slot />
  </span>
</template>
