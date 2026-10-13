<script setup lang="ts">
/*
 * Card — Figma Card Variants 265:107 (357x264 each):
 *   Card/Help/KnowledgeBase 265:108 · ContactSupport 265:117 ·
 *   CommunityForum 265:126
 * A plain surface container; the help-card content is composed by the caller.
 */

withDefaults(defineProps<{ interactive?: boolean; padded?: boolean }>(), {
  interactive: false,
  padded: true,
})
</script>

<template>
  <div
    class="rounded-lg border border-border bg-surface"
    :class="[
      padded && 'p-6',
      interactive && 'transition-shadow hover:border-border-input hover:shadow-sm',
    ]"
  >
    <slot />
  </div>
</template>
