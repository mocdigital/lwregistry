<script setup lang="ts">
import UiButton from './UiButton.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Search bar — Figma SearchBar 265:78 (680x53): a rounded field with an
 * integrated primary button on the right.
 * Below sm the button drops beneath the field; the designs show only the
 * 680px inline form.
 */

defineProps<{ modelValue?: string; placeholder?: string; buttonLabel?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string]; submit: [] }>()
</script>

<template>
  <form
    class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:gap-0 sm:rounded-md sm:border sm:border-border-input sm:bg-surface sm:p-1.5"
    role="search"
    @submit.prevent="emit('submit')"
  >
    <input
      :value="modelValue"
      type="search"
      :placeholder="placeholder ?? t('ui_search_bar.search_businesses_categories_locations')"
      class="w-full rounded-md border border-border-input bg-surface px-4 py-3 text-sm text-ink placeholder:text-ink-tertiary focus:border-focus focus:outline-none sm:flex-1 sm:border-0 sm:py-2 sm:focus:border-transparent"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <UiButton type="submit" size="md" class="shrink-0">
      {{ buttonLabel ?? t('action.search') }}
    </UiButton>
  </form>
</template>
