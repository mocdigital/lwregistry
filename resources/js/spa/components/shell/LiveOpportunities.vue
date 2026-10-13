<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink, usePage } from '@inertiajs/vue3'

import oppChevron from '@/assets/figma/profile/opp-chevron.svg'
import viewAllArrow from '@/assets/figma/profile/viewall-arrow.svg'
import oppImg1 from '@/assets/figma/profile/opp-1.jpg'
import oppImg2 from '@/assets/figma/profile/opp-2.jpg'
import oppImg3 from '@/assets/figma/profile/opp-3.jpg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Live Opportunities rail — the same Figma component appears on the profile
 * dashboard (387:21704) and the notifications page (422:32696).
 *
 * In the file it is drawn as a scaled-down nested component, so its literal
 * type sizes there run 6-9px. Those are artefacts of the scale, not a type
 * scale anyone can read, and the two pages had drifted to different values
 * (6/8/9/12px vs 10/13/14/18px). This component is the single source of truth
 * and uses the larger, readable set for both.
 */

/*
 * The rows were three invented opportunities — a government IT tender in
 * Nairobi with a deadline in May 2025 — drawn identically on every screen
 * carrying this rail, while real open requests sat unseen on /procurement.
 * They come from the shared `liveOpportunities` prop now; see
 * App\Support\LiveOpportunities.
 */
type Opportunity = {
  id: string
  kind: string
  title: string
  place: string
  when: string
  urgent: boolean
  url: string
  /** The buyer's featured photo; null until they attach one. */
  imageUrl: string | null
}

const page = usePage()

const opportunities = computed(
  () => (page.props.liveOpportunities ?? []) as Opportunity[],
)

/*
 * Card photography. `purchase_orders` carries no image, so the design's three
 * stock shots cycle by position rather than being invented per request — the
 * same treatment the listing cards use. Marked aria-hidden: they illustrate the
 * row, they do not describe it.
 */
const photos = [oppImg1, oppImg2, oppImg3]
</script>

<template>
  <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6">
    <div class="flex items-center justify-between gap-3">
      <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('live_opportunities.live_opportunities') }}</h2>
      <RouterLink
        href="/procurement"
        class="flex shrink-0 items-center gap-1 text-xs font-semibold text-[#0d9488] hover:underline"
      >
        {{ t('live_opportunities.view_all') }}
        <img :src="viewAllArrow" alt="" class="h-2.5 w-2" aria-hidden="true" />
      </RouterLink>
    </div>

    <ul v-if="opportunities.length" class="flex flex-col">
      <li
        v-for="(o, i) in opportunities"
        :key="o.id"
        class="flex items-center gap-3"
        :class="i > 0 && 'border-t border-[#f1f5f9]'"
      >
        <RouterLink :href="o.url" class="flex min-w-0 flex-1 items-center gap-3 py-4">
          <!-- The buyer's own photo when they attached one; the stock
               artwork only as a fallback. -->
          <img
            :src="o.imageUrl ?? photos[i % photos.length]"
            alt=""
            class="h-[52px] w-[54px] shrink-0 rounded-md object-cover"
            aria-hidden="true"
          />
          <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-xs leading-tight font-semibold text-[#1e2337]">
              {{ o.title }}
            </span>
            <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
              <!-- Red when it closes within a fortnight, green otherwise. -->
              <span
                class="flex items-center gap-1.5"
                :class="o.urgent ? 'text-[#ef4444]' : 'text-[#34c759]'"
              >
                <span
                  class="size-1.5 rounded-full"
                  :class="o.urgent ? 'bg-[#ef4444]' : 'bg-[#22c55e]'"
                  aria-hidden="true"
                />
                {{ o.when }}
              </span>
            </span>
          </span>
          <img :src="oppChevron" alt="" class="h-2.5 w-1.5 shrink-0" aria-hidden="true" />
        </RouterLink>
      </li>
    </ul>

    <!-- Reachable on a quiet registry, and whenever every request is closed. -->
    <p v-else class="py-6 text-center text-xs text-[#94a3b8]">
      {{ t('live_opportunities.no_open_opportunities_right_now') }}
    </p>
  </section>
</template>
