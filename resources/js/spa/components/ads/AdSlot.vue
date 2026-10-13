<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

/*
 * One advertising slot.
 *
 * The live ad for every slot arrives as the shared `ads` prop; this reads its
 * own slot out of it. When there is no ad — the default — nothing renders at
 * all: no placeholder, no reserved space. When there is one, it links through
 * the click-tracking route and beacons a view once it has actually painted.
 */
const props = defineProps<{
  /** A key from config/ads.php, e.g. "home_hero". */
  name: string
  /** Extra classes for the wrapper, so a slot can sit in a sidebar or a row. */
  wrapperClass?: string
}>()

const page = usePage()

const ad = computed(() => page.props.ads?.[props.name] ?? null)

/** Count the view once, when a real ad is on screen. Failure is silent. */
function beacon(url: string) {
  fetch(url, { method: 'GET', credentials: 'same-origin', keepalive: true }).catch(() => {})
}

onMounted(() => {
  if (ad.value) beacon(ad.value.seenUrl)
})

// A client-side navigation reuses the component, so a fresh ad landing in the
// prop after mount is counted too.
watch(
  () => ad.value?.id,
  (id, previous) => {
    if (id && id !== previous && ad.value) beacon(ad.value.seenUrl)
  },
)
</script>

<template>
  <div v-if="ad" :class="['text-center', props.wrapperClass]" :data-ad-slot="name">
    <a
      :href="ad.clickUrl"
      target="_blank"
      rel="noopener sponsored"
      :aria-label="`Advertisement: ${ad.title}`"
    >
      <picture>
        <source
          v-if="ad.mobileImage && ad.mobileWidth"
          media="(max-width: 575.98px)"
          :srcset="ad.mobileImage"
        />
        <img
          :src="ad.image"
          :alt="ad.title"
          :width="ad.width || undefined"
          :height="ad.height || undefined"
          loading="lazy"
          class="mx-auto inline-block h-auto max-w-full rounded-lg"
        />
      </picture>
    </a>
    <div class="mt-1 text-[0.7rem] tracking-wide text-ink-tertiary uppercase">Advertisement</div>
  </div>
</template>
