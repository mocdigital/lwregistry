<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Reading the certificate before keeping it.
 *
 * The seal panels offered downloading only, which answers "can I have a copy"
 * rather than "what does it look like" — it puts a file on the member's disk
 * before they know whether they want one, and on a phone that usually means it
 * lands in a downloads folder unopened.
 *
 * A component rather than markup repeated in both panels. The two seal cards
 * deliberately do NOT share their layout — one is a narrow sidebar, the other a
 * full-width card — but this dialog is the same in both, and the first version
 * of it lived only in the sidebar, so the profile page never got the button at
 * all.
 *
 * The frame is only rendered while the dialog is open, so a member who never
 * asks for a preview never downloads the PDF.
 */
const props = defineProps<{
  /** The same document served inline; null when there is no certificate yet. */
  previewUrl: string | null
  /** The download, offered again from inside the dialog. */
  downloadUrl: string | null
  title: string | null
}>()

const open = ref(false)

// Escape closes it, which is what a dialog is expected to do.
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <button
    v-if="props.previewUrl"
    type="button"
    class="rounded-lg border border-border px-5 py-2.5 text-xs font-semibold text-ink transition-colors hover:bg-surface-secondary"
    @click="open = true"
  >
    {{ t('sbo_seal_panel.preview_certificate') }}
  </button>

  <div
    v-if="open && props.previewUrl"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
    role="dialog"
    aria-modal="true"
    :aria-label="t('sbo_seal_panel.preview_certificate')"
    @click.self="open = false"
  >
    <div class="flex h-full max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white">
      <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
        <p class="min-w-0 truncate font-display text-sm font-semibold text-ink">{{ props.title }}</p>
        <button
          type="button"
          class="grid size-8 shrink-0 place-items-center rounded-lg text-ink-secondary transition-colors hover:bg-surface-secondary"
          :aria-label="t('common.close')"
          @click="open = false"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <iframe
        :src="props.previewUrl"
        :title="t('sbo_seal_panel.preview_certificate')"
        class="min-h-0 w-full flex-1 bg-surface-secondary"
      />

      <!--
        "Open in a new tab" is always visible rather than appearing once
        something has already failed: most mobile browsers refuse to render a
        PDF in a frame, and they do it silently.
      -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
        <a
          :href="props.previewUrl"
          target="_blank"
          rel="noopener"
          class="text-2xs font-semibold text-primary hover:underline"
        >
          {{ t('sbo_seal_panel.open_in_a_new_tab') }}
        </a>
        <a
          v-if="props.downloadUrl"
          :href="props.downloadUrl"
          class="btn-primary rounded-lg px-4 py-2 text-2xs font-semibold"
        >
          {{ t('sbo_seal_panel.download_certificate_pdf') }}
        </a>
      </div>
    </div>
  </div>
</template>
