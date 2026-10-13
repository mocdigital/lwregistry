<script setup lang="ts">
import { computed } from 'vue'

/*
 * Progress bar — Figma Progress Variants 265:218.
 * Label row (16px) + 8px track, 24px gap between them. Variants shown at
 * 25% (265:219), 60% (265:225) and 100% (265:231).
 */

const props = withDefaults(
  defineProps<{ value: number; label?: string; showValue?: boolean }>(),
  { showValue: true },
)

const pct = computed(() => Math.min(100, Math.max(0, Math.round(props.value))))
</script>

<template>
  <div class="w-full">
    <div v-if="props.label || props.showValue" class="flex items-center justify-between">
      <span v-if="props.label" class="text-xs font-medium text-ink">{{ props.label }}</span>
      <span v-if="props.showValue" class="text-xs font-semibold text-ink">{{ pct }}%</span>
    </div>
    <div
      class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-surface-tertiary"
      role="progressbar"
      :aria-valuenow="pct"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="props.label"
    >
      <div
        class="h-full rounded-full bg-primary transition-[width] duration-300"
        :style="{ width: `${pct}%` }"
      />
    </div>
  </div>
</template>
