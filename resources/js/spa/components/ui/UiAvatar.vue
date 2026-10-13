<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { DEFAULT_AVATAR } from '@/assets/defaultAvatar'

/*
 * A member's avatar: their photo where they have one, the default face where
 * they do not.
 *
 * Every avatar in the app used to be one of three things — a hardcoded "KJ", a
 * stock Figma face, or initials — so uploading a photo changed nothing outside
 * the settings page it was uploaded on. One component, so a photo added once
 * appears everywhere a face is drawn.
 *
 * Initials are still drawn, but only when an image cannot be: a broken photo
 * URL, or a missing default. Something legible is better than the browser's
 * torn-page glyph, and this component is used at 24px where an alt string is
 * not readable anyway.
 *
 * Sizing is passed in as classes rather than a `size` prop: these are drawn at
 * 24px in a notification row and 96px on the profile hero, and the frames set
 * ring, border and radius differently at each.
 */

const props = withDefaults(
  defineProps<{
    src?: string | null
    name?: string | null
    initials?: string | null
    /** Tailwind classes for the box: size, ring, radius. */
    class?: string
    /** Fallback tile colours, so a dark hero can invert them. */
    fallbackClass?: string
  }>(),
  {
    fallbackClass: 'bg-[#ccfbf1] text-[#0d9488]',
  },
)

/*
 * An image that failed is not retried by swapping in another URL — the default
 * may be what failed. Reset when the photo changes, so a member who uploads a
 * working photo after a broken one is not stuck on initials for the session.
 */
const failed = ref(false)

watch(
  () => props.src,
  () => {
    failed.value = false
  },
)

const source = computed(() => (failed.value ? null : (props.src || DEFAULT_AVATAR)))

/* Derived only when no explicit initials are given — a name is what most
   callers have to hand. */
const letters = computed(() => {
  if (props.initials) return props.initials

  return (props.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase() ?? '')
    .join('')
})
</script>

<template>
  <img
    v-if="source"
    :src="source"
    :alt="name ?? ''"
    class="shrink-0 rounded-full object-cover"
    :class="props.class"
    @error="failed = true"
  />
  <span
    v-else
    class="grid shrink-0 place-items-center rounded-full font-semibold"
    :class="[props.class, fallbackClass]"
    :aria-label="name ?? undefined"
  >
    {{ letters }}
  </span>
</template>
