<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: PublicLayout })
</script>

<template>
  <!-- SCAFFOLD ONLY — not yet implemented from the design.
       Figma node(s): 223:4647 -->
  <div class="container-page py-10 sm:py-16">
    <p class="text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
      {{ t('sbo_verify.not_yet_built') }}
    </p>
    <h1 class="mt-2 font-serif text-3xl font-bold">{{ t('sbo_verify.sbo_verification') }}</h1>
    <p class="mt-3 max-w-prose text-sm text-ink-secondary">
      {{ t('sbo_verify.figma_reference') }} <code class="rounded-sm bg-surface-tertiary px-1.5 py-0.5 text-xs">223:4647</code>
    </p>
  </div>
</template>
