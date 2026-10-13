<script setup lang="ts">
import { ref, useId } from 'vue'
import lockIcon from '@/assets/figma/icons/lock.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Password field with a reveal toggle.
 *
 * Styling matches the sign-in card (64:46): #e2e8f0 border, 8px radius,
 * 16/12 padding, 18px leading lock icon, 15px text, #94a3b8 placeholder.
 *
 * The reveal toggle swaps between an open eye and a struck-through one. The
 * Figma file ships only `eye-off` (64:90), and using it for both states with a
 * change in opacity said nothing: a faded icon reads as disabled, not as "the
 * password is hidden". The open eye is the same path with the slash and the
 * lid-closing detail removed, drawn inline so the two share a stroke weight and
 * follow `currentColor`.
 */

withDefaults(
  defineProps<{
    modelValue?: string
    label?: string
    placeholder?: string
    autocomplete?: string
    hint?: string
    hintTone?: 'muted' | 'good'
  }>(),
  { placeholder: '••••••••••••', autocomplete: 'current-password', hintTone: 'muted' },
)

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const revealed = ref(false)
</script>

<template>
  <div class="flex flex-col gap-2">
    <label v-if="label" :for="id" class="text-sm font-semibold text-[#0f172a]">{{ label }}</label>

    <div class="flex items-center gap-2 rounded-md border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus">
      <img :src="lockIcon" alt="" class="size-[18px] shrink-0" aria-hidden="true" />

      <input
        :id="id"
        :value="modelValue"
        :type="revealed ? 'text' : 'password'"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        class="min-w-0 flex-1 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
        @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />

      <button
        type="button"
        class="shrink-0 rounded-sm text-[#94a3b8] transition-colors hover:text-ink-secondary"
        :aria-label="revealed ? t('ui_password_field.hide_password') : t('ui_password_field.show_password')"
        :aria-pressed="revealed"
        :aria-controls="id"
        @click="revealed = !revealed"
      >
        <!-- Revealed: an open eye. Hidden: the same eye, struck through. -->
        <svg
          viewBox="0 0 18 18"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          class="size-[18px]"
          aria-hidden="true"
        >
          <path
            d="M1.55 8.74a.5.5 0 0 1 0-.52C2.8 5.2 5.68 3.25 9 3.25s6.2 1.95 7.45 4.97a.5.5 0 0 1 0 .52C15.2 12.8 12.32 14.75 9 14.75S2.8 12.8 1.55 8.74Z"
          />
          <circle cx="9" cy="9" r="2.25" />
          <path v-if="!revealed" d="M1.5 1.5 16.5 16.5" />
        </svg>
      </button>
    </div>

    <p v-if="hint" class="text-2xs" :class="hintTone === 'good' ? 'text-primary' : 'text-ink-secondary'">
      {{ hint }}
    </p>
  </div>
</template>
