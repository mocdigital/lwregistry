<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import PublicLayout from '@/layouts/PublicLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import shieldCheck from '@/assets/figma/icons/shield-check.svg'
import arrowRight16 from '@/assets/figma/icons/arrow-right-16.svg'
import chevronRight from '@/assets/figma/icons/chevron-right.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Browse by facet — the home page's "Explore the Global Directory" rows.
 *
 * ADDITION: there is no Figma frame for this screen. The five rows are drawn
 * (1:211) but every one of them linked to /categories, so the pages they name
 * were never designed. Rather than invent a look, this is assembled from the
 * categories screen it used to land on: the same hero band and video fill, the
 * same `section-x` gutters, workspace container, sidebar rail and benefits
 * strip. The only new element is the option list, which reuses the home page's
 * own directory-row treatment — icon well, label, sub-label, chevron.
 *
 * Replace with the real frame if one is drawn; nothing here should outrank it.
 */

defineOptions({ layout: PublicLayout })

const registerUrl = useRegisterUrl()

defineProps<{
  facet: string
  title: string
  blurb: string
  /** Values with at least one listing; see App\Support\SboBrowseFacets. */
  options: {
    value: string
    label: string
    description: string | null
    count: number
    url: string
  }[]
  facets: { key: string; label: string; sub: string; url: string }[]
}>()

function listings(count: number): string {
  return `${count} ${count === 1 ? 'listing' : 'listings'}`
}
</script>

<template>
  <!-- Hero, mirroring the categories screen's band (10:142). -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo overlay="bg-[#001529]/70" />

    <div class="relative flex flex-col items-start gap-6 section-x py-9 sm:py-14 xl:py-20">
      <span
        class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/12 px-3 py-1.5"
      >
        <img :src="shieldCheck" alt="" class="size-3.5" aria-hidden="true" />
        <span class="text-xs font-semibold tracking-wider text-secondary uppercase">
          {{ t('browse.explore_the_global_directory') }}
        </span>
      </span>

      <div class="flex max-w-[800px] flex-col gap-4">
        <h1 class="font-serif text-4xl font-bold text-white xl:text-5xl">
          {{ title }}
        </h1>
        <p class="text-base leading-relaxed text-[#94a3b8]">{{ blurb }}</p>
      </div>

      <RouterLink
        href="/businesses"
        class="inline-flex items-center gap-2 rounded-md btn-primary px-7 py-3.5 text-sm font-semibold text-white"
      >
        {{ t('browse.browse_every_listing') }}
        <img :src="arrowRight16" alt="" class="size-4" aria-hidden="true" />
      </RouterLink>
    </div>
  </section>

  <div class="bg-surface-secondary section-x py-6">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_288px] [&>*]:min-w-0">
      <div class="min-w-0">
        <!-- Cross-links, so moving between facets does not mean going back to
             the home page for the menu. -->
        <nav class="flex gap-6 overflow-x-auto border-b border-border" :aria-label="t('browse.browse_by')">
          <RouterLink
            v-for="f in facets"
            :key="f.key"
            :href="f.url"
            class="shrink-0 border-b-2 pb-3 text-xs font-medium transition-colors"
            :class="
              f.key === facet
                ? 'border-ink text-ink'
                : 'border-transparent text-ink-secondary hover:text-ink'
            "
            :aria-current="f.key === facet ? 'page' : undefined"
          >
            {{ f.label }}
          </RouterLink>
        </nav>

        <ul v-if="options.length" class="mt-5 divide-y divide-border-subtle rounded-lg bg-white">
          <li v-for="o in options" :key="o.value">
            <!--
              A plain <a>, not a RouterLink: these carry a query string the
              directory reads server-side, and the count beside each one is the
              size of the result set it opens.
            -->
            <a :href="o.url" class="flex items-center gap-3 px-4 py-3.5 hover:bg-surface-tertiary">
              <span class="min-w-0 flex-1">
                <span class="block text-xs leading-4 font-semibold text-ink">{{ o.label }}</span>
                <span v-if="o.description" class="mt-0.5 block text-2xs text-ink-tertiary">
                  {{ o.description }}
                </span>
              </span>
              <span class="shrink-0 text-2xs font-semibold text-ink-secondary">
                {{ listings(o.count) }}
              </span>
              <img :src="chevronRight" alt="" class="h-2.5 w-1.5 shrink-0" aria-hidden="true" />
            </a>
          </li>
        </ul>

        <!--
          Only values with a listing behind them are offered, so an empty page
          means an empty registry rather than a dead facet.
        -->
        <p v-else class="mt-5 rounded-lg bg-white p-6 text-xs text-ink-secondary">
          {{ t('browse.nothing_is_listed_under_this_yet_once_busi') }}
        </p>
      </div>

      <aside class="flex flex-col gap-5">
        <section class="rounded-lg border border-border bg-white p-4">
          <h2 class="flex items-center gap-2 font-sans text-xs font-semibold">
            <span aria-hidden="true">✨</span> {{ t('browse.are_you_an_active_sbo') }}
          </h2>
          <p class="mt-2 text-2xs leading-5 text-ink-secondary">
            {{ t('browse.establish_credibility_publish_active_comme') }}
          </p>
          <RouterLink
            :href="registerUrl"
            class="mt-3 block rounded-md bg-[#001529] px-3 py-2.5 text-center text-2xs font-semibold text-white hover:opacity-90"
          >
            {{ t('browse.register_sbo_now') }}
          </RouterLink>
        </section>

        <MarketingRail />
      </aside>
    </div>
  </div>

  <BenefitsStrip />
</template>
