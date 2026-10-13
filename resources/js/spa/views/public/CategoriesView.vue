<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import PublicLayout from '@/layouts/PublicLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import shieldCheck from '@/assets/figma/icons/shield-check.svg'
import arrowRight16 from '@/assets/figma/icons/arrow-right-16.svg'
import searchIcon from '@/assets/figma/icons/search.svg'
import chevronDown from '@/assets/figma/icons/chevron-down.svg'
import sboCloudtrac from '@/assets/figma/cat/sbo-cloudtrac.jpg'
import sboDma from '@/assets/figma/cat/sbo-dma.jpg'
import sboWorkforce from '@/assets/figma/cat/sbo-workforce.jpg'
import sboPlayArena from '@/assets/figma/cat/sbo-playarena.jpg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

/*
 * SBO categories — Figma 10:116 "sbo-categories-redesign" (1280x2766).
 *
 * Sections: header 406:23665 · hero-banner 10:142 · workspace-container 10:159
 * · benefits 52:1822 · footer.
 *
 * The hero carries the shared site video fill (hash 564ad6f3…) — the same one
 * on 24 screens — so it renders through HeroVideo rather than a still.
 *
 * Gutters are 80px here as on the directory, so `container-page` is not used.
 */

const tabs = [t('categories.all_sbos'), t('business_profile.popular_categories'), t('categories.recent_additions'), t('categories.top_rated'), t('categories.verified_partners')]
const activeTab = ref(0)

defineOptions({ layout: PublicLayout })

type Listing = {
  name: string
  slug: string
  body: string | null
  address: string
  size: string | null
  tag: string | null
  /** The owner's card artwork; null until uploaded, stock image otherwise. */
  coverUrl: string | null
  logoUrl: string | null
}

const props = defineProps<{
  listings: {
    data: Listing[]
    links: { url: string | null; label: string; active: boolean }[]
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null }
  }
  quickFilters: { value: string; label: string; count: number }[]
  filters: { category?: string; search?: string }
  /** The selected category's own hero, or null for the shared video. */
  categoryCover: string | null
}>()

const quickFilters = computed(() => props.quickFilters)

// Search submits back to this route; the controller re-reads the query.
const search = ref(props.filters.search ?? '')

function submitSearch() {
  const query: Record<string, string> = {}
  if (search.value) query.search = search.value
  if (props.filters.category) query.category = props.filters.category
  router.get('/categories', query, { preserveState: true, preserveScroll: true })
}

/*
 * The quick filters were drawn but never wired: eight buttons with a count and
 * no handler, so the sidebar looked like a filter and behaved like a caption.
 *
 * Clicking one narrows the list to that category; clicking the one already
 * applied clears it, so each button is its own off switch and there is no
 * separate "All" to hunt for. `value` is the stored category name — filtering
 * on `label` would break the moment the reader is not reading English.
 */
function applyQuickFilter(value: string) {
  const query: Record<string, string> = {}
  if (search.value) query.search = search.value
  if (props.filters.category !== value) query.category = value
  router.get('/categories', query, { preserveState: true, preserveScroll: true })
}

/*
 * Card photography and the star rating are design placeholders: sbos carries
 * no images and no reviews. Photos cycle by position so the list still reads
 * as designed, and the rating is fixed rather than randomised so it cannot be
 * mistaken for a real score. See INTEGRATION.md.
 */
const cardPhotos = [sboCloudtrac, sboDma, sboWorkforce]

const sbos = computed(() =>
  props.listings.data.map((l, i) => ({
    ...l,
    // The owner's own cover when set; the stock rotation only as a fallback.
    img: l.coverUrl ?? cardPhotos[i % cardPhotos.length],
    rating: 5,
    reviews: 0,
  })),
)
</script>

<template>
  <!-- ========================================== hero-banner 10:142 ======== -->
  <section class="relative overflow-hidden bg-[#001529]">
    <!--
      A category with its own cover shows it; everything else keeps the shared
      hero video. The overlay is the same either way, so the white title holds
      whatever the photograph underneath is doing.
    -->
    <template v-if="props.categoryCover">
      <img
        :src="props.categoryCover"
        alt=""
        aria-hidden="true"
        class="pointer-events-none absolute inset-0 size-full object-cover"
      />
      <span class="pointer-events-none absolute inset-0 bg-[#001529]/70" aria-hidden="true" />
    </template>
    <HeroVideo v-else overlay="bg-[#001529]/70" />

    <div class="relative flex flex-col items-start gap-6 section-x py-9 sm:py-14 xl:py-20">
      <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/12 px-3 py-1.5">
        <img :src="shieldCheck" alt="" class="size-3.5" aria-hidden="true" />
        <span class="text-xs font-semibold tracking-wider text-secondary uppercase">
          {{ t('categories.loveworld_network_platform') }}
        </span>
      </span>

      <div class="flex max-w-[800px] flex-col gap-4">
        <h1 class="font-serif text-4xl font-bold text-white xl:text-5xl">
          {{ t('categories.grow_your_business_with_the_loveworld_netw') }}
        </h1>
        <p class="text-base leading-relaxed text-[#94a3b8]">
          {{ t('categories.connect_with_thousands_of_verified_spirit') }}
        </p>
      </div>

      <div class="flex flex-wrap gap-4">
        <RouterLink
          :href="registerUrl"
          class="inline-flex items-center gap-2 rounded-md btn-primary px-7 py-3.5 text-sm font-semibold text-white "
        >
          {{ t('categories.register_your_business') }}
          <img :src="arrowRight16" alt="" class="size-4" aria-hidden="true" />
        </RouterLink>
        <RouterLink
          href="/about"
          class="inline-flex items-center rounded-md border-[1.5px] border-white px-7 py-3.5 text-sm font-semibold text-white hover:bg-white/10"
        >
          {{ t('categories.how_it_works') }}
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ================================== workspace-container 10:159 ======== -->
  <div class="bg-surface-secondary section-x py-6">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_288px] [&>*]:min-w-0">
      <div class="min-w-0">
        <div class="flex gap-6 overflow-x-auto border-b border-border">
          <button
            v-for="(t, i) in tabs"
            :key="t"
            type="button"
            class="shrink-0 border-b-2 pb-3 text-xs font-medium transition-colors"
            :class="i === activeTab ? 'border-ink text-ink' : 'border-transparent text-ink-secondary hover:text-ink'"
            @click="activeTab = i"
          >
            {{ t }}
          </button>
        </div>

        <div class="mt-5 rounded-lg bg-white p-4">
          <form class="flex gap-3" role="search" @submit.prevent="submitSearch">
            <div class="relative flex-1">
              <img :src="searchIcon" alt="" class="absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden="true" />
              <input
                v-model="search"
                type="search"
                :placeholder="t('categories.search_sbo_name_category_services_keywords')"
                class="w-full rounded-md border border-border-input bg-white py-2.5 pr-3 pl-9 text-xs text-ink placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
              />
            </div>
            <button
              type="submit"
              class="shrink-0 rounded-md bg-[#001529] px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90"
            >
              {{ t('categories.search') }}
            </button>
          </form>

          <p class="mt-4 text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
            {{ t('categories.quick_filters') }}
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              v-for="f in quickFilters"
              :key="f.value"
              type="button"
              :aria-pressed="props.filters.category === f.value"
              class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-2xs transition-colors"
              :class="
                props.filters.category === f.value
                  ? 'border-primary bg-primary text-white'
                  : 'border-border bg-white text-ink-secondary hover:border-primary hover:text-primary'
              "
              @click="applyQuickFilter(f.value)"
            >
              {{ f.label }}
              <span
                class="rounded-full px-1.5 text-2xs font-semibold"
                :class="props.filters.category === f.value ? 'bg-white/20' : 'bg-surface-tertiary'"
              >
                {{ f.count }}
              </span>
            </button>
          </div>
          <RouterLink href="/categories" class="mt-3 inline-block text-2xs font-semibold text-primary hover:underline">
            {{ t('categories.view_all_14_categories') }}
          </RouterLink>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
          <h2 class="font-sans text-sm font-semibold">{{ t('categories.verified_loveworld_directory_sbos') }}</h2>
          <button type="button" class="inline-flex items-center gap-1.5 text-2xs text-ink-secondary hover:text-ink">
            {{ t('categories.sort_by') }} <span class="font-semibold text-ink">{{ t('categories.highest_rating') }}</span>
            <img :src="chevronDown" alt="" class="size-3" aria-hidden="true" />
          </button>
        </div>

        <ul class="mt-4 space-y-4">
          <li v-for="s in sbos" :key="s.slug">
            <article class="flex flex-col gap-4 rounded-lg border border-border bg-white p-4 sm:flex-row">
              <img :src="s.img" :alt="s.name" class="h-28 w-full shrink-0 rounded-md object-cover sm:w-36" />

              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="font-sans text-xs font-semibold text-ink">{{ s.name }}</h3>
                  <span class="inline-flex items-center gap-1 rounded-full bg-[#dcfce7] px-2 py-0.5 text-2xs font-semibold text-[#007035]">
                    {{ t('categories.verified_sbo') }}
                  </span>
                  <span class="ml-auto flex items-center gap-1 text-2xs text-ink-tertiary">
                    <span class="text-[#facc15]" aria-hidden="true">
                      {{ '★'.repeat(s.rating) }}<span class="text-border">{{ '★'.repeat(5 - s.rating) }}</span>
                    </span>
                    ({{ s.reviews }})
                  </span>
                </div>

                <p class="mt-2 text-2xs leading-5 text-ink-secondary">{{ s.body }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-2xs text-ink-tertiary">
                  <span class="flex items-center gap-1">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-3 shrink-0" aria-hidden="true">
                      <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z" stroke-linejoin="round" />
                      <circle cx="12" cy="10" r="2.5" />
                    </svg>
                    {{ s.address }}
                  </span>
                  <span class="flex items-center gap-1">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-3 shrink-0" aria-hidden="true">
                      <circle cx="9" cy="8" r="3" />
                      <path d="M3 20a6 6 0 0 1 12 0M16 11a3 3 0 1 0 0-6M18 20a5 5 0 0 0-3-4.6" stroke-linecap="round" />
                    </svg>
                    {{ s.size }}
                  </span>
                  <span class="flex items-center gap-1 text-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-3 shrink-0" aria-hidden="true">
                      <path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z" stroke-linejoin="round" />
                      <circle cx="7.5" cy="7.5" r="1.2" />
                    </svg>
                    {{ s.tag }}
                  </span>
                </div>
              </div>

              <div class="flex shrink-0 flex-col gap-2 sm:w-[110px]">
                <button
                  type="button"
                  class="inline-flex items-center justify-center gap-1.5 rounded-md bg-[#001529] px-3 py-2 text-2xs font-semibold text-white hover:opacity-90"
                >
                  {{ t('categories.connect') }}
                </button>
                <RouterLink
                  :href="`/business/${s.slug}`"
                  class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-2xs font-semibold text-ink hover:bg-surface-secondary"
                >
                  {{ t('categories.view_details') }}
                </RouterLink>
              </div>
            </article>
          </li>
        </ul>

        <!--
          Real paginator. The count line and the 1/2/3/…/10 buttons were static,
          so the screen claimed "1 to 4 of 113" no matter the data or the page.
        -->
        <nav class="mt-6 flex flex-wrap items-center justify-between gap-4" :aria-label="t('categories.pagination')">
          <p class="text-2xs text-ink-secondary">
            {{ t('categories.showing') }} <strong>{{ props.listings.meta.from ?? 0 }}</strong> {{ t('categories.to') }}
            <strong>{{ props.listings.meta.to ?? 0 }}</strong> {{ t('categories.of') }}
            <strong>{{ props.listings.meta.total }}</strong> {{ t('categories.sbos') }}
          </p>
          <ul v-if="props.listings.meta.last_page > 1" class="flex flex-wrap items-center gap-1">
            <li v-for="(link, i) in props.listings.links" :key="i">
              <RouterLink
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="grid h-7 min-w-7 place-items-center rounded-md border px-2 text-2xs font-semibold"
                :class="
                  link.active
                    ? 'border-[#001529] bg-[#001529] text-white'
                    : 'border-border text-ink-secondary hover:bg-surface-secondary'
                "
                :aria-current="link.active ? 'page' : undefined"
                v-html="link.label"
              />
              <span
                v-else
                class="grid h-7 min-w-7 place-items-center rounded-md border border-border px-2 text-2xs font-semibold text-ink-tertiary"
                v-html="link.label"
              />
            </li>
          </ul>
        </nav>
      </div>

      <aside class="min-w-0 space-y-5">
        <!-- "Are you an active SBO?" is unique to this screen. -->
        <section class="rounded-lg border border-border bg-white p-4">
          <h2 class="flex items-center gap-2 font-sans text-xs font-semibold">
            <span aria-hidden="true">✨</span> {{ t('categories.are_you_an_active_sbo') }}
          </h2>
          <p class="mt-2 text-2xs leading-5 text-ink-secondary">
            {{ t('categories.establish_credibility_publish_active_comme') }}
          </p>
          <RouterLink
            :href="registerUrl"
            class="mt-3 block rounded-md bg-[#001529] px-3 py-2.5 text-center text-2xs font-semibold text-white hover:opacity-90"
          >
            {{ t('categories.register_sbo_now') }}
          </RouterLink>
        </section>

        <MarketingRail />
      </aside>
    </div>
  </div>

  <!-- ==================================== benefits strip 52:1822 ========== -->
  <BenefitsStrip />
</template>
