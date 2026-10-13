<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from '@/composables/useI18n'

/*
 * The header language selector.
 *
 * A round trip rather than client-side switching: the dictionary is a shared
 * Inertia prop, and server-rendered strings (validation messages, controller
 * labels, notification copy) are translated by Laravel's own locale — so the
 * server has to know. `preserveScroll` keeps the reader's place, which is the
 * point of the control: they want to re-read THIS page in their language.
 */

/*
 * Which way the list opens.
 *
 * Down under the header bar, where there is a page below it. Up in the mobile
 * drawer, where the control is the last row and a downward list would open
 * past the bottom of the drawer and be clipped by it.
 */
/*
 * `tone` — the trigger's colour. `light` is for a dark bar (the home hero's own
 * header), where the shell's ink-on-white trigger would vanish into the navy.
 * The list itself stays white in both: it floats over the page, not the bar.
 */
const props = withDefaults(defineProps<{ drop?: 'down' | 'up'; tone?: 'default' | 'light' }>(), {
  drop: 'down',
  tone: 'default',
})

const { locale, locales, t } = useI18n()

const open = ref(false)
const root = ref<HTMLElement | null>(null)

function choose(code: string) {
  open.value = false

  if (code === locale.value) return

  router.post('/locale', { locale: code }, { preserveScroll: true, preserveState: false })
}

/* A menu that cannot be dismissed by clicking away is a trap on touch. */
function onPointerDown(event: PointerEvent) {
  if (root.value && !root.value.contains(event.target as Node)) {
    open.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  if ('Escape' === event.key) open.value = false
}

onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="relative">
    <!--
      The globe, which is what the header design draws (401:22653). It shipped
      as a dead placeholder beside this control, so the bar carried two language
      buttons — one styled and inert, one working and unstyled. This is the
      styled one, wired up.
    -->
    <button
      type="button"
      class="flex items-center gap-1 text-xs transition-colors"
      :class="props.tone === 'light' ? 'text-slate-200 hover:text-white' : 'text-ink-secondary hover:text-ink'"
      :aria-label="t('language.choose')"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="open = !open"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
      </svg>
      <!-- The code, not the full name: the header is tight and "EN" is
           unambiguous next to a globe. -->
      <span class="uppercase">{{ locale }}</span>
      <span aria-hidden="true" class="text-2xs">▾</span>
    </button>

    <ul
      v-if="open"
      class="absolute right-0 z-50 w-44 overflow-hidden rounded-lg border border-border bg-white py-1 shadow-lg"
      :class="props.drop === 'up' ? 'bottom-full mb-1' : 'mt-1'"
      role="listbox"
      :aria-label="t('language.label')"
    >
      <li v-for="l in locales" :key="l.code">
        <button
          type="button"
          class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs transition-colors hover:bg-surface-secondary"
          :class="l.code === locale ? 'font-semibold text-ink' : 'text-ink-secondary'"
          role="option"
          :aria-selected="l.code === locale"
          @click="choose(l.code)"
        >
          <span aria-hidden="true">{{ l.flag }}</span>
          <!-- Native name: a French speaker scans for "Français", not "French". -->
          <span class="flex-1">{{ l.native }}</span>
          <span v-if="l.code === locale" class="text-primary" aria-hidden="true">✓</span>
        </button>
      </li>
    </ul>
  </div>
</template>
