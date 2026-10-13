<script setup lang="ts">
import { ref } from 'vue'

/*
 * FAQ accordion — Figma FAQ Items 265:140.
 *   FAQ/Expanded  265:141 (800x139)
 *   FAQ/Collapsed 265:146 / 265:150 (800x72)
 * Rendered as a native <details> so it works without JS and is accessible by
 * default; `open` mirrors the two designed states.
 */

const props = withDefaults(defineProps<{ question: string; open?: boolean }>(), {
  open: false,
})

const isOpen = ref(props.open)
</script>

<template>
  <details
    :open="isOpen"
    class="group border-b border-border last:border-b-0"
    @toggle="isOpen = ($event.target as HTMLDetailsElement).open"
  >
    <summary
      class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-sm font-semibold text-ink marker:content-none hover:text-primary"
    >
      {{ props.question }}
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        class="size-4 shrink-0 text-ink-tertiary transition-transform group-open:rotate-180"
        aria-hidden="true"
      >
        <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </summary>
    <div class="pb-5 text-sm text-ink-secondary">
      <slot />
    </div>
  </details>
</template>
