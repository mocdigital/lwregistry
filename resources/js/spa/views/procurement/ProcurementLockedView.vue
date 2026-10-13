<script setup lang="ts">
import { ref } from 'vue'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

/*
 * The screen a member lands on when they open a private request they were not
 * invited to. It reveals nothing about the request — not its title, not who
 * posted it — only that it exists and can be asked for.
 */
defineOptions({ layout: AppLayout })

const props = defineProps<{
  id: string
  /** Whether this viewer has already asked and is waiting on the owner. */
  requested: boolean
  requestUrl: string
}>()

const sending = ref(false)

function requestAccess() {
  if (props.requested || sending.value) return
  sending.value = true
  // On success the page re-renders with `requested` true, which swaps the
  // button for the "request sent" note.
  router.post(props.requestUrl, {}, { preserveScroll: true, onFinish: () => (sending.value = false) })
}
</script>

<template>
  <div class="section-x flex flex-col items-center gap-5 py-16 text-center sm:py-24">
    <div class="grid size-16 place-items-center rounded-full bg-surface-secondary text-ink-tertiary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-7" aria-hidden="true">
        <rect x="4" y="11" width="16" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </div>

    <h1 class="font-serif text-2xl font-bold text-[#0f172a] sm:text-3xl">This request is private</h1>
    <p class="max-w-[460px] text-sm leading-relaxed text-[#475569]">
      This procurement request is invite-only. If you should have access, ask the owner and they
      can let you in.
    </p>

    <button
      v-if="!props.requested"
      type="button"
      :disabled="sending"
      class="btn-primary rounded-lg px-6 py-3 text-sm font-semibold disabled:opacity-60"
      @click="requestAccess"
    >
      {{ sending ? 'Sending…' : 'Request authorization' }}
    </button>
    <p
      v-else
      class="rounded-lg border border-[#e2e8f0] bg-surface-secondary px-4 py-3 text-sm text-[#475569]"
      role="status"
    >
      Your request has been sent. You'll be notified when the owner responds.
    </p>

    <RouterLink href="/procurement" class="text-xs font-semibold text-primary hover:underline">
      Back to procurement requests
    </RouterLink>
  </div>
</template>
