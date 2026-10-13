<script setup lang="ts">
import { computed, useId } from 'vue'

/*
 * Text input — Figma Input Variants 265:46.
 *   Input/Default  265:47 — border #e5e7eb
 *   Input/Focused  265:51 — border #0d9488 (teal)
 *   Input/Error    265:55 — border + text + message #ef4444, message 12px
 *   Input/Disabled 265:60 — border #f3f4f6
 * Label 14px medium #1e293b, 6px gap, field px16/py12, radius 8.
 * The designs fix the field at 384px; here it fills its container so it can be
 * used responsively, which the designs never show.
 */

const props = defineProps<{
  modelValue?: string
  label?: string
  placeholder?: string
  type?: string
  error?: string
  disabled?: boolean
  required?: boolean
  autocomplete?: string
}>()

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const errorId = computed(() => (props.error ? `${id}-error` : undefined))
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label v-if="props.label" :for="id" class="text-sm font-medium text-slate-800">
      {{ props.label }}
      <span v-if="props.required" class="text-danger" aria-hidden="true">*</span>
    </label>

    <input
      :id="id"
      :value="props.modelValue"
      :type="props.type ?? 'text'"
      :placeholder="props.placeholder"
      :disabled="props.disabled"
      :required="props.required"
      :autocomplete="props.autocomplete"
      :aria-invalid="props.error ? 'true' : undefined"
      :aria-describedby="errorId"
      class="w-full rounded-md border bg-surface px-4 py-3 text-sm text-ink transition-colors placeholder:text-ink-tertiary focus:outline-none disabled:cursor-not-allowed disabled:bg-surface-secondary"
      :class="
        props.error
          ? 'border-danger focus:border-danger'
          : props.disabled
            ? 'border-border-input-disabled'
            : 'border-border-input focus:border-focus'
      "
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="props.error" :id="errorId" class="text-2xs text-danger">
      {{ props.error }}
    </p>
  </div>
</template>
