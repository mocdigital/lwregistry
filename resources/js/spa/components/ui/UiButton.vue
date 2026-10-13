<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink } from '@inertiajs/vue3'

/*
 * Button — Figma Buttons Section 265:9.
 *
 * Variants map 1:1 to the Figma components:
 *   primary   -> Button/Primary/{Large,Medium,Small}  265:15 / 265:17 / 265:19
 *   dark      -> Button/Secondary/Default             265:35  (#001529)
 *   outline   -> Button/Secondary/Outline             265:33
 *   ghost     -> Button/Ghost                         265:37
 *   cta       -> Button/CTA/Amber                     265:39
 *   disabled  -> Button/Primary/Disabled              265:21  (#94a3b8)
 *
 * PRIMARY RESTS INACTIVE. Per the design system a primary button sits in the
 * inactive state (teal #0d9488) and only turns active (green #007035) once
 * actioned — pressed, or driven by the `active` prop. Green is never the
 * resting appearance. The `btn-primary` utility in main.css encodes this.
 * `disabled` is separate again: the greyed-out #94a3b8 variant.
 */

type Variant = 'primary' | 'dark' | 'outline' | 'ghost' | 'cta'
type Size = 'sm' | 'md' | 'lg'

const props = withDefaults(
  defineProps<{
    variant?: Variant
    size?: Size
    active?: boolean
    disabled?: boolean
    to?: string
    href?: string
    type?: 'button' | 'submit' | 'reset'
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button' },
)

/*
 * `to` renders an in-app Inertia visit, `href` a plain anchor. Both are kept:
 * callers already use `to` everywhere, and Inertia's Link takes `href`, so the
 * template collapses them onto a single `:href` binding. Passing `to` straight
 * through would render a dead attribute and Inertia would quietly default the
 * href to the current URL, making every button a no-op.
 */
const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))

// Padding/type sizes are taken verbatim from the Figma variants.
const sizeClasses: Record<Size, string> = {
  lg: 'px-8 py-3.5 text-base', // 32 / 14 / 16px
  md: 'px-6 py-2.5 text-sm',   // 24 / 10 / 14px
  sm: 'px-4 py-2 text-xs',     // 16 /  8 / 13px
}

const variantClasses = computed<string>(() => {
  if (props.disabled) return 'bg-disabled text-ink-inverse'

  switch (props.variant) {
    case 'dark':
      return 'bg-surface-dark text-ink-inverse hover:bg-navy-800'
    case 'outline':
      return 'bg-surface text-ink border border-border-input hover:bg-surface-secondary'
    case 'ghost':
      return 'text-primary-inactive hover:text-primary-inactive-hover hover:bg-teal-50/60'
    case 'cta':
      return 'bg-secondary text-surface-dark hover:bg-secondary-hover'
    case 'primary':
    default:
      // Rests inactive (teal); `active` promotes it to green.
      return props.active ? 'btn-primary is-active' : 'btn-primary'
  }
})
</script>

<template>
  <component
    :is="tag"
    :href="props.to ?? props.href"
    :type="tag === 'button' ? props.type : undefined"
    :disabled="tag === 'button' ? props.disabled : undefined"
    :aria-disabled="tag !== 'button' && props.disabled ? 'true' : undefined"
    class="inline-flex items-center justify-center gap-2 rounded-md font-semibold whitespace-nowrap transition-colors"
    :class="[
      sizeClasses[props.size],
      variantClasses,
      props.block && 'w-full',
      props.disabled && 'pointer-events-none',
    ]"
  >
    <slot />
  </component>
</template>
