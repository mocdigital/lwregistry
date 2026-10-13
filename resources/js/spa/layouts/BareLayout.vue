<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
</script>

<template>
  <!-- Auth + registration wizard screens. In the designs these carry only the
       logo, which links home (prototype: `logo` -> homepage 1:4). -->
  <div class="flex min-h-dvh flex-col bg-surface-secondary">
    <header class="container-page flex h-(--header-height-mobile) items-center md:h-(--header-height-desktop)">
      <RouterLink href="/" class="text-lg font-semibold text-ink">
        Loveworld Registry
      </RouterLink>
    </header>
    <main class="flex flex-1 items-start justify-center px-4 py-8 md:items-center md:py-12">
      <slot />
    </main>
  </div>
</template>
