<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import AppHeader from '@/components/shell/AppHeader.vue'
import AppFooter from '@/components/shell/AppFooter.vue'
import SalesAssistant from '@/components/shell/SalesAssistant.vue'
import { useScrollEffects } from '@/composables/useScrollEffects'

const page = usePage()

// Sections rise in as they arrive, and drift out of focus as they leave —
// across the whole public site. Reduced-motion and phones opt out inside.
useScrollEffects()
</script>

<template>
  <div class="flex min-h-dvh flex-col bg-surface">
    <AppHeader :authenticated="Boolean(page.props.auth?.user)" />
    <main class="flex-1">
      <slot />
    </main>
    <AppFooter />

    <!-- The sales assistant, on the public pages only: it answers the
         questions somebody asks BEFORE joining. -->
    <SalesAssistant />
  </div>
</template>
