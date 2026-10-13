<script setup lang="ts">
/*
 * The flag stack on an SBO card.
 *
 * The design draws two or three country flags on every card and nothing on the
 * model produced them, so they cycled through a fixed list of assets by card
 * position — a Lagos logistics firm could be shown flying the flag of the UAE.
 * They are now the countries the business actually holds an address in, one per
 * COUNTRY: three branches in Nigeria is one flag, not three.
 *
 * Drawn as circles, one per country, at a single diameter so the stack reads
 * as a row of dots rather than a ragged strip of rectangles.
 *
 * Fourteen flags are vendored as SVGs. The registry reaches far past those, so
 * anything else falls back to the regional-indicator emoji, which every locale
 * has and which degrades to the country's two letters where a platform has no
 * glyph — legible either way, and better than a gap.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** ISO 3166-1 alpha-2, in the order the addresses are listed. */
    codes: string[]
    /** Beyond this the stack becomes "+N"; a card has room for three. */
    max?: number
    size?: 'sm' | 'md'
  }>(),
  { max: 3, size: 'sm' },
)

/*
 * Eager glob: these are 3x2 SVGs of a few hundred bytes each and the set is
 * fixed at build time, so bundling them beats fourteen extra requests. Keyed by
 * the lowercase code the filename already uses.
 */
const vendored = import.meta.glob('@/assets/flags/*.svg', {
  eager: true,
  import: 'default',
}) as Record<string, string>

const byCode: Record<string, string> = Object.fromEntries(
  Object.entries(vendored).map(([path, url]) => [
    path.split('/').pop()!.replace('.svg', '').toLowerCase(),
    url,
  ]),
)

/** `NG` -> 🇳🇬. Two regional indicator symbols, offset from 'A'. */
function emojiFlag(code: string): string {
  return [...code.toUpperCase()]
    .map((letter) => String.fromCodePoint(0x1f1e6 + letter.charCodeAt(0) - 65))
    .join('')
}

const shown = computed(() =>
  props.codes.slice(0, props.max).map((code) => ({
    code: code.toUpperCase(),
    src: byCode[code.toLowerCase()] ?? null,
    emoji: emojiFlag(code),
  })),
)

const overflow = computed(() => Math.max(0, props.codes.length - props.max))
</script>

<template>
  <span v-if="codes.length" class="inline-flex items-center gap-1">
    <template v-for="flag in shown" :key="flag.code">
      <!-- Round, not rectangular. The vendored SVGs are 3:2, so the square box
           plus object-cover crops the hoist and fly ends rather than squashing
           the design; the hairline ring keeps a flag with white at its edge
           (Nigeria, Japan) from dissolving into a white card. -->
      <img
        v-if="flag.src"
        :src="flag.src"
        :alt="flag.code"
        :title="flag.code"
        class="shrink-0 rounded-full object-cover ring-1 ring-black/10"
        :class="size === 'md' ? 'size-5' : 'size-4'"
      />
      <!-- The emoji fallback is a glyph, so it cannot be cropped: it is centred
           in a circle of the same diameter and scaled to fill it. -->
      <span
        v-else
        :title="flag.code"
        class="grid shrink-0 place-items-center overflow-hidden rounded-full leading-none ring-1 ring-black/10"
        :class="size === 'md' ? 'size-5 text-[17px]' : 'size-4 text-[14px]'"
        >{{ flag.emoji }}</span
      >
    </template>

    <span v-if="overflow" class="text-2xs font-semibold text-ink-tertiary">+{{ overflow }}</span>
  </span>
</template>
