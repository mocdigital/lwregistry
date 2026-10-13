<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'

const page = usePage()
import AppHeader from '@/components/shell/AppHeader.vue'
import AppFooter from '@/components/shell/AppFooter.vue'
import SalesAssistant from '@/components/shell/SalesAssistant.vue'
</script>

<template>
  <!-- The designs use the same global header/footer for signed-in screens as
       for public ones; the only difference is the profile pill and the
       notification bell, handled inside AppHeader. -->
  <div class="flex min-h-dvh flex-col bg-surface-secondary">
    <AppHeader :authenticated="Boolean(page.props.auth?.user)" />
    <main class="flex-1">
      <slot />
    </main>
    <AppFooter />

    <!--
      Support, on the signed-in screens as well as the public ones. It was on
      PublicLayout only, which is eleven of the twenty-eight views — so a
      member with a question about fees or verification, on the pages where
      those questions actually arise, had nothing to ask.
    -->
    <SalesAssistant />
  </div>
</template>
