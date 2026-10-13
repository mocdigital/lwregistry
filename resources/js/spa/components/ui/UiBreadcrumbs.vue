<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* Breadcrumbs — Figma Breadcrumbs 265:207. */

defineProps<{ items: { label: string; to?: string }[] }>()
</script>

<template>
  <nav :aria-label="t('ui_breadcrumbs.breadcrumb')">
    <ol class="flex flex-wrap items-center gap-1.5 text-xs">
      <li v-for="(item, i) in items" :key="item.label" class="flex items-center gap-1.5">
        <RouterLink
          v-if="item.to && i < items.length - 1"
          :href="item.to"
          class="text-ink-secondary hover:text-ink hover:underline"
        >
          {{ item.label }}
        </RouterLink>
        <span v-else class="font-medium text-ink" :aria-current="i === items.length - 1 ? 'page' : undefined">
          {{ item.label }}
        </span>
        <span v-if="i < items.length - 1" class="text-ink-tertiary" aria-hidden="true">/</span>
      </li>
    </ol>
  </nav>
</template>
