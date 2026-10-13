<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * View Analytics.
 *
 * ADDITION: there is no Figma frame for this screen — the profile's Quick
 * Actions card promised "Track views, clicks and leads" and linked to
 * /dashboard, the page it sits on, so the screen it named was never drawn.
 * Built from the profile dashboard's own idiom: the same dark hero with the
 * shared video fill, the same metric tiles, the same bordered white cards.
 *
 * Replace with the real frame if one is drawn; nothing here should outrank it.
 */

defineOptions({ layout: AppLayout })

const props = defineProps<{
  sbos: { id: number; name: string }[]
  selectedSboId: number | null
  days: number
  ranges: { value: number; label: string }[]
  totals: { key: string; label: string; value: number }[]
  series: { date: string; label: string; views: number }[]
  listings: {
    id: number
    name: string
    slug: string
    verified: boolean
    views: number
    requests: number
  }[]
  /** Things the registry does not record, stated rather than drawn as zero. */
  notTracked: string[]
}>()

function apply(patch: Record<string, string | number | null>) {
  const query: Record<string, string> = {}
  const days = patch.days ?? props.days
  const sbo = 'sbo' in patch ? patch.sbo : props.selectedSboId

  if (days !== 30) query.days = String(days)
  if (sbo) query.sbo = String(sbo)

  router.get('/account/analytics', query, { preserveScroll: true, replace: true })
}

/*
 * Bar heights as a percentage of the busiest day. A flat 1% floor on a day with
 * no views keeps the axis readable without drawing a bar that isn't there.
 */
const peak = computed(() => Math.max(1, ...props.series.map((d) => d.views)))

function barHeight(views: number): string {
  return views === 0 ? '2px' : `${Math.max(4, Math.round((views / peak.value) * 100))}%`
}

const totalViews = computed(() => props.series.reduce((sum, d) => sum + d.views, 0))

/* Only every nth label, or a 90-day range is an unreadable smear. */
const labelEvery = computed(() => Math.ceil(props.series.length / 10))
</script>

<template>
  <section class="relative overflow-hidden bg-[#0b192c]">
    <HeroVideo overlay="bg-[#0b192c]/72" />

    <div class="relative flex flex-col gap-6 section-x pt-12 pb-14">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('analytics.breadcrumb')">
        <RouterLink href="/profile" class="text-[#94a3b8] hover:text-white">
          {{ t('analytics.profile_dashboard') }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">/</span>
        <span class="text-white">{{ t('analytics.analytics') }}</span>
      </nav>

      <div class="flex flex-col gap-2">
        <h1 class="font-serif text-3xl font-bold text-white">{{ t('analytics.analytics') }}</h1>
        <p class="text-sm text-[#94a3b8]">
          {{ t('analytics.how_your_listings_are_being_found_and_who') }}
        </p>
      </div>

      <div class="flex flex-wrap gap-3">
        <label class="flex items-center gap-2 text-xs text-[#94a3b8]">
          <span class="sr-only">{{ t('analytics.date_range') }}</span>
          <select
            :value="days"
            class="max-w-full rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs text-white focus:outline-none"
            @change="apply({ days: Number(($event.target as HTMLSelectElement).value) })"
          >
            <option v-for="r in ranges" :key="r.value" :value="r.value" class="text-ink">
              {{ r.label }}
            </option>
          </select>
        </label>

        <!-- Only worth offering when there is more than one listing to split. -->
        <label v-if="sbos.length > 1" class="flex items-center gap-2 text-xs text-[#94a3b8]">
          <span class="sr-only">{{ t('analytics.listing') }}</span>
          <select
            :value="selectedSboId ?? ''"
            class="max-w-full rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs text-white focus:outline-none"
            @change="apply({ sbo: ($event.target as HTMLSelectElement).value || null })"
          >
            <option value="" class="text-ink">{{ t('analytics.all_listings') }}</option>
            <option v-for="s in sbos" :key="s.id" :value="s.id" class="text-ink">{{ s.name }}</option>
          </select>
        </label>
      </div>
    </div>
  </section>

  <div class="bg-surface-secondary section-x py-8">
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_288px] [&>*]:min-w-0">
      <div class="flex min-w-0 flex-col gap-6">
        <!-- Metric tiles, counted from rows that exist. -->
        <ul v-if="totals.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
          <li
            v-for="t in totals"
            :key="t.key"
            class="flex flex-col gap-1 rounded-2xl border border-border bg-white p-5"
          >
            <span class="text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
              {{ t.label }}
            </span>
            <span class="font-serif text-3xl font-bold text-ink">{{ t.value }}</span>
          </li>
        </ul>

        <p v-else class="rounded-2xl border border-border bg-white p-5 sm:p-8 text-sm text-ink-secondary">
          {{ t('analytics.register_a_business_to_start_collecting_an') }}
        </p>

        <!-- Views over time -->
        <section v-if="sbos.length" class="flex flex-col gap-5 rounded-2xl border border-border bg-white p-6">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-ink">{{ t('analytics.profile_views') }}</h2>
            <p class="text-xs text-ink-tertiary">
              {{ t('analytics.views_in_last_days', { total: totalViews, days }) }}
            </p>
          </div>

          <!--
            Every day in the range is plotted, including the empty ones: a chart
            that skips quiet days makes a quiet week look busy.
          -->
          <div
            v-if="totalViews > 0"
            class="flex h-40 items-end gap-1 overflow-x-auto"
            role="img"
            :aria-label="t('analytics.views_per_day', { days })"
          >
            <span
              v-for="(d, i) in series"
              :key="d.date"
              class="flex min-w-[6px] flex-1 flex-col items-center gap-1"
            >
              <span class="flex w-full flex-1 items-end">
                <span
                  class="w-full rounded-t bg-[#0d9488]"
                  :style="{ height: barHeight(d.views) }"
                  :title="`${d.label}: ${d.views}`"
                />
              </span>
              <span class="h-3 text-2xs whitespace-nowrap text-ink-tertiary">
                {{ i % labelEvery === 0 ? d.label : '' }}
              </span>
            </span>
          </div>

          <p v-else class="py-8 text-center text-sm text-ink-secondary">
            {{ t('analytics.no_profile_views_in_this_period_yet_views') }}
          </p>
        </section>

        <!-- Per listing, so an owner with several can see which is working. -->
        <section
          v-if="listings.length > 1"
          class="flex flex-col gap-4 rounded-2xl border border-border bg-white p-6"
        >
          <h2 class="font-display text-base font-semibold text-ink">{{ t('analytics.by_listing') }}</h2>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[420px] text-left text-sm">
              <thead>
                <tr class="text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
                  <th class="pb-2 font-semibold">{{ t('analytics.listing') }}</th>
                  <th class="pb-2 text-right font-semibold">{{ t('analytics.views') }}</th>
                  <th class="pb-2 text-right font-semibold">{{ t('analytics.requests') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in listings" :key="l.id" class="border-t border-border-subtle">
                  <td class="py-3">
                    <RouterLink :href="`/business/${l.slug}`" class="font-semibold text-ink hover:underline">
                      {{ l.name }}
                    </RouterLink>
                  </td>
                  <td class="py-3 text-right text-ink-secondary">{{ l.views }}</td>
                  <td class="py-3 text-right text-ink-secondary">{{ l.requests }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <aside class="flex flex-col gap-5">
        <!--
          Said plainly rather than shown as a tile reading zero: the registry
          records no outbound click on a listing's website link.
        -->
        <section class="rounded-2xl border border-border bg-white p-5">
          <h2 class="font-sans text-xs font-semibold text-ink">{{ t('analytics.not_tracked_yet') }}</h2>
          <ul class="mt-2 flex flex-col gap-1">
            <li v-for="n in notTracked" :key="n" class="text-2xs text-ink-secondary">{{ n }}</li>
          </ul>
          <p class="mt-3 text-2xs text-ink-tertiary">
            {{ t('analytics.these_are_not_shown_as_zero_because_nothin') }}
          </p>
        </section>

        <MarketingRail />
      </aside>
    </div>
  </div>
</template>
