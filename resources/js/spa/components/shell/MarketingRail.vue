<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink, usePage } from '@inertiajs/vue3'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import adSpace from '@/assets/figma/raw/ad-space.jpg'
import ctaConnections from '@/assets/figma/raw/cta-connections.jpg'
import oppCircuit from '@/assets/figma/raw/opp-circuit.jpg'
import oppMeeting from '@/assets/figma/raw/opp-meeting.jpg'
import sboPartners from '@/assets/figma/raw/sbo-partners.jpg'
import arrowNavy from '@/assets/figma/icons/arrow-navy.svg'
import chevronRight from '@/assets/figma/icons/chevron-right.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

/*
 * Shared marketing right rail — the same Figma nodes appear on the homepage
 * (1:209) and the directory (12:48), so they live here rather than twice.
 *   advertise card       1:327
 *   live opportunities   1:32
 *   build connections    1:315
 *
 * `sponsoredLabel` shows the SPONSORED / Ads header the directory has above
 * the advertise card; the homepage does not show it.
 */

// `cards` selects which of the three appear — screens carry different subsets
// (the business profile has no build-connections card, for example).
withDefaults(defineProps<{ sponsoredLabel?: boolean; cards?: string[] }>(), {
  sponsoredLabel: false,
  cards: () => ['advertise', 'opportunities', 'connections'],
})

/*
 * The three rows here were invented — a government IT tender with a deadline in
 * May 2025 — and drawn on every screen carrying this rail while real open
 * requests sat unseen on /procurement. They come from the shared
 * `liveOpportunities` prop now; see App\Support\LiveOpportunities.
 */
type Opportunity = {
  id: string
  kind: string
  title: string
  place: string
  when: string
  urgent: boolean
  url: string
}

const page = usePage()

const opportunities = computed(() => (page.props.liveOpportunities ?? []) as Opportunity[])

/*
 * `purchase_orders` carries no image, so the design's three stock shots cycle
 * by position rather than being invented per request.
 */
const photos = [oppCircuit, oppMeeting, sboPartners]

</script>

<template>
  <div class="space-y-5">
    <div v-if="sponsoredLabel" class="flex items-center justify-between text-2xs font-semibold tracking-wide uppercase">
      <span class="text-[#6b7280]">{{ t('marketing_rail.sponsored') }}</span>
      <span class="text-[#9ca3af]">{{ t('marketing_rail.ads') }}</span>
    </div>

    <!-- advertise card 1:327 -->
    <RouterLink v-if="cards.includes('advertise')" href="/advertise" class="relative block min-h-[271px] overflow-clip rounded-xl">
      <img :src="adSpace" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
      <div class="absolute inset-0 bg-black/20" aria-hidden="true" />
      <div
        class="absolute inset-0 bg-[linear-gradient(to_bottom,rgba(0,0,0,0.5)_6.993%,rgba(0,15,42,0.5)_57.692%)]"
        aria-hidden="true"
      />
      <div class="relative flex min-h-[271px] flex-col items-center gap-3 px-5 pt-5 pb-6 text-center">
        <p class="text-2xs leading-4 text-[#d1d5db]">
          {{ t('marketing_rail.advertisement_space') }}
        </p>
        <h2 class="max-w-[232px] font-display text-xl leading-6 font-semibold text-white">
          {{ t('marketing_rail.reach_verified_loveworld_businesses') }}
        </h2>
        <span
          class="mt-auto inline-flex h-8 w-full max-w-[200px] items-center justify-center gap-1.5 rounded-sm bg-white px-3 text-xs leading-4 font-semibold text-[#1e3a8a]"
        >
          {{ t('marketing_rail.advertise_your_business') }}
          <img :src="arrowNavy" alt="" class="h-2.5 w-2 shrink-0" aria-hidden="true" />
        </span>
      </div>
    </RouterLink>

    <!-- live opportunities 1:32 -->
    <!--
      Live opportunities 1:32.

      Rebuilt to the design rather than the shrunken version that shipped: a
      40px thumbnail, 10px type throughout and rows separated by nothing but
      whitespace, which read as one grey block. The row is the unit here — a
      photograph, what kind of opportunity it is, what it is called, where, and
      by when — so each part is sized to be read in that order.
    -->
    <section
      v-if="cards.includes('opportunities')"
      class="rounded-2xl border border-border bg-surface p-5"
    >
      <div class="flex items-center justify-between gap-2">
        <h2 class="font-display text-lg leading-tight font-bold tracking-tight text-ink">
          {{ t('marketing_rail.live_opportunities') }}
        </h2>
        <RouterLink
          href="/procurement"
          class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-ink hover:underline"
        >
          <!-- The arrow is the icon, not part of the label. The string used to
               end in a literal "→" as well, so the row drew two of them; a
               glyph inside translatable text is also one a translator can drop
               or mangle. -->
          {{ t('marketing_rail.view_all') }}
          <img :src="arrowNavy" alt="" class="size-3" aria-hidden="true" />
        </RouterLink>
      </div>

      <ul v-if="opportunities.length" class="mt-4">
        <li v-for="(o, i) in opportunities" :key="o.id">
          <RouterLink :href="o.url" class="group flex items-center gap-3">
            <img
              :src="photos[i % photos.length]"
              alt=""
              class="size-14 shrink-0 rounded-lg object-cover"
              aria-hidden="true"
            />

            <!--
              The rule sits on the text column, not on the row: inset to the
              left of the photograph and stopping short of the chevron, it
              separates the entries without boxing each one in.
            -->
            <span
              class="min-w-0 flex-1 py-4"
              :class="i < opportunities.length - 1 ? 'border-b border-border' : ''"
            >
              <span class="block text-xs leading-snug font-bold text-ink group-hover:underline">
                {{ o.title }}
              </span>
              <span class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <!--
                  A deadline is a warning and an open-ended request is not, so
                  the dot carries the same colour as the words rather than
                  being decoration next to them.
                -->
                <span
                  class="inline-flex items-center gap-1.5 text-2xs font-medium"
                  :class="o.urgent ? 'text-danger' : 'text-success'"
                >
                  <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
                  {{ o.when }}
                </span>
              </span>
            </span>

            <img
              :src="chevronRight"
              alt=""
              class="size-3.5 shrink-0 opacity-70 transition-opacity group-hover:opacity-100"
              aria-hidden="true"
            />
          </RouterLink>
        </li>
      </ul>

      <p v-else class="mt-4 text-2xs text-ink-tertiary">{{ t('marketing_rail.no_open_opportunities_right_now') }}</p>
    </section>

    <!-- build connections 1:315 -->
    <section
      v-if="cards.includes('connections')"
      class="relative min-h-[271px] overflow-clip rounded-xl bg-[linear-gradient(to_bottom,#1e3a8a,#581c87)]"
    >
      <img :src="ctaConnections" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
      <div
        class="absolute inset-0 bg-[linear-gradient(to_bottom,rgba(0,0,0,0.5)_6.993%,rgba(0,15,42,0.5)_57.692%)]"
        aria-hidden="true"
      />
      <div class="absolute top-[42px] left-1/2 w-[232px] -translate-x-1/2">
        <h2 class="text-center font-display text-xl leading-6 font-semibold text-white">
          {{ t('marketing_rail.build_connections_that_shape_the_future') }}
        </h2>
        <p class="mt-[17px] text-center text-2xs leading-4 text-[#d1d5db]">
          {{ t('marketing_rail.join_a_global_network_of_purpose_driven_bu') }}
        </p>
        <RouterLink
          :href="registerUrl"
          class="mx-auto mt-[22px] flex h-8 w-[200px] items-center justify-center gap-1.5 rounded-sm bg-white text-xs leading-4 font-semibold text-[#1e3a8a] hover:bg-white/90"
        >
          {{ t('marketing_rail.register_your_business') }}
          <img :src="arrowNavy" alt="" class="h-2.5 w-2" aria-hidden="true" />
        </RouterLink>
        <p class="mt-4 text-center text-2xs leading-4 text-[#9ca3af]">
          {{ t('marketing_rail.already_registered') }}
          <RouterLink href="/login" class="text-2xs text-white hover:underline">{{ t('marketing_rail.sign_in') }}</RouterLink>
        </p>
      </div>
    </section>
  </div>
</template>
