<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import bgVideo from '@/assets/video/background.mp4'
import bgVideoMobile from '@/assets/video/background-mobile.mp4'
import heroPoster from '@/assets/figma/raw/cta-banner-bg.jpg'

/*
 * Shared hero/title background video.
 *
 * It used to be desktop only. `background.mp4` is ~10MB, which is not something
 * to put on a phone's data plan for decoration, so below lg the poster showed
 * and the band sat still — which is what "the title background is not playing
 * on mobile" was.
 *
 * There are two encodes now. The desktop file is unchanged; the mobile one is
 * 284KB, made with `avconvert -p PresetAppleM4VCellular --duration 10` — Apple's
 * own preset for cellular delivery. Fifteen seconds of an abstract particle
 * field survives that compression because it is soft-edged and sits under an
 * overlay; a photographic clip would not.
 *
 *   avconvert -s background.mp4 -p PresetAppleM4VCellular \
 *     -o background-mobile.mp4 --duration 10 --replace
 *
 * ffmpeg would do better still (~100KB at this length), and the desktop file is
 * worth re-encoding with it before launch — avconvert cannot compress it at
 * all, every preset came out within 10% of the source. It is not installed on
 * this machine and Homebrew could not reach its CDN.
 *
 * ONE <video>, with the source chosen at mount. Two elements with `lg:hidden`
 * would put both files in the DOM, and a browser is entitled to prefetch the
 * hidden one — which is how a phone ends up downloading the 10MB copy it was
 * never meant to see.
 *
 * Motion is suppressed for `prefers-reduced-motion`, where the poster shows.
 */

withDefaults(defineProps<{ overlay?: string; poster?: string }>(), {
  overlay: 'bg-[#001529]/55',
  poster: heroPoster,
})

const video = ref<HTMLVideoElement | null>(null)

/** Null until mounted, so the server-rendered markup requests no video at all. */
const source = ref<string | null>(null)

let desktopQuery: MediaQueryList | null = null

function pick() {
  source.value = desktopQuery?.matches ? bgVideo : bgVideoMobile
}

onMounted(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

  desktopQuery = window.matchMedia('(min-width: 64rem)')
  pick()

  /*
   * Following the breakpoint matters on a tablet being rotated, but only to
   * pick the right file next time — it does not swap a clip mid-playback,
   * which would restart it under the reader.
   */
  desktopQuery.addEventListener('change', pick)

  // Autoplay can still be refused (low-power mode, a data saver); the poster
  // remains, which is the same still frame.
  void video.value?.play().catch(() => {})
})

onBeforeUnmount(() => desktopQuery?.removeEventListener('change', pick))
</script>

<template>
  <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
    <!-- Shown until the clip has painted, and whenever it is refused. -->
    <img :src="poster" alt="" class="absolute inset-0 size-full object-cover" />

    <video
      v-if="source"
      ref="video"
      :key="source"
      :src="source"
      class="relative size-full object-cover"
      :poster="poster"
      muted
      loop
      playsinline
      autoplay
      preload="auto"
    />

    <div class="absolute inset-0" :class="overlay" />
  </div>
</template>
