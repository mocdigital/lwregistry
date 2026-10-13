<script setup lang="ts">
/*
 * Step indicator — Figma StepIndicator 265:177 (675x32).
 * Used by the 3-step registration wizard and the 5-step bid wizard.
 *
 * Uses the active/inactive primary pair: completed and current steps are green
 * (#007035, active), upcoming steps are teal (#0d9488, inactive).
 * The connector line and mobile stacking are invented — the designs show only
 * the 1280px horizontal row.
 */

const props = defineProps<{ steps: string[]; current: number }>()
</script>

<template>
  <ol class="flex items-center gap-2 overflow-x-auto sm:gap-3" role="list">
    <li
      v-for="(step, i) in props.steps"
      :key="step"
      class="flex shrink-0 items-center gap-2"
      :aria-current="i === props.current ? 'step' : undefined"
    >
      <span
        class="grid size-7 shrink-0 place-items-center rounded-full text-2xs font-semibold"
        :class="
          i <= props.current
            ? 'bg-primary text-ink-inverse'
            : 'bg-primary-inactive/15 text-primary-inactive'
        "
      >
        {{ i + 1 }}
      </span>
      <span
        class="hidden text-xs font-medium whitespace-nowrap sm:inline"
        :class="i <= props.current ? 'text-ink' : 'text-ink-tertiary'"
      >
        {{ step }}
      </span>
      <span
        v-if="i < props.steps.length - 1"
        class="h-px w-6 shrink-0 sm:w-10"
        :class="i < props.current ? 'bg-primary' : 'bg-border'"
        aria-hidden="true"
      />
    </li>
  </ol>
</template>
