<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import { Link as RouterLink, router, useForm } from '@inertiajs/vue3'
import { normalizeUrl } from '@/composables/useExternalUrl'
import PublicLayout from '@/layouts/PublicLayout.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import EntityLogoRing from '@/components/ui/EntityLogoRing.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import AdSlot from '@/components/ads/AdSlot.vue'
import sparkles from '@/assets/figma/icons/sparkles.svg'
import arrowWhite from '@/assets/figma/icons/arrow-right-white.svg'
import searchIcon from '@/assets/figma/icons/search.svg'
import arrowSearch from '@/assets/figma/icons/arrow-search.svg'
import chevronDown from '@/assets/figma/icons/chevron-down.svg'
import UiCountryFlags from '@/components/ui/UiCountryFlags.vue'
import { useI18n } from '@/composables/useI18n'

const { t, td } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

/*
 * Business directory — Figma 12:4 "business-registry-redesign" (1280x3202).
 *
 * Sections in frame order: header 406:22826 · cta-banner 13:4 ·
 * hero-filter-rail 12:25 · workspace 12:48 · benefits 52:1794 · footer.
 *
 * GUTTERS: this screen pads its sections at 80px, not the ~20px the homepage
 * uses, so it deliberately does not use `container-page`. The 20/40/80 ramp
 * below matches the design at xl and degrades under it.
 */

defineOptions({ layout: PublicLayout })

type Listing = {
  id: number
  name: string
  slug: string
  category: string | null
  address: string
  mapQuery: string
  website: string | null
  verified: boolean
  /** The owner's card artwork; null until uploaded, stock image otherwise. */
  coverUrl: string | null
  logoUrl: string | null
  /** 'SBO' | 'COB' — colours the animated logo ring on the card. */
  entityType: string | null
  /** ISO codes, one per country the business trades in; see Sbo::countryCodes. */
  countryCodes: string[]
  /** From the viewer's point of view: their own listing, or the pair's status. */
  connection: 'self' | 'none' | 'pending' | 'accepted'
}

type PaginationLink = { url: string | null; label: string; page: number | null; active: boolean }

const props = defineProps<{
  listings: {
    data: Listing[]
    links: PaginationLink[]
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null }
  }
  categories: { name: string; slug: string }[]
  cities: string[]
  countries: string[]
  /** Sectors with at least one listing; see App\Support\SboIndustries. */
  industries: string[]
  filters: {
    search?: string
    location?: string
    status?: string
    category?: string
    industry?: string
    country?: string
  }
  sort: string
  sorts: { value: string; label: string }[]
  /** The SBO the viewer acts as. Null for a visitor with no listing. */
  mySboId: number | null
}>()

/*
 * Filter state. The server owns the URL, so submitting is a GET back to this
 * same route: the controller re-reads the query string and re-renders. Only
 * `listings` changes, so the layout and rail are preserved across the visit.
 */
const form = reactive({
  search: props.filters.search ?? '',
  category: props.filters.category ?? '',
  industry: props.filters.industry ?? '',
  country: props.filters.country ?? '',
  // Drawn as "Sort by Relevance" and inert; it is a real ordering now.
  sort: props.sort ?? 'relevance',
})

function submit() {
  // Drop empty values so a cleared filter leaves the URL instead of lingering
  // as `?category=`, which `filled()` would still reject but which shows up in
  // the address bar and in `withQueryString()` pagination links.
  const query = Object.fromEntries(
    Object.entries(form).filter(([k, v]) => v !== '' && !(k === 'sort' && v === 'relevance')),
  )

  router.get('/businesses', query, { preserveState: true, preserveScroll: true, replace: true })
}

/*
 * The design gives every card a flag stack and a review count. Neither exists
 * on the Sbo model yet, so they are presentational constants until the backend
 * carries them — see INTEGRATION.md.
 */
const listings = computed(() =>
  props.listings.data.map((l) => ({
    ...l,
    reviews: 0,
    website: normalizeUrl(l.website),
    directions: l.mapQuery
      ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(l.mapQuery)}`
      : null,
  })),
)

/*
 * Connect / Pending / Message. Each is a real post:
 *   Connect — POST /sbo/connections, which the owner then accepts
 *   Message — POST /sbo/conversations, which opens (or reuses) the thread
 * Pending is a state, not an action, so it renders disabled.
 */
const connectForm = useForm<{ requester_sbo_id: number | null; recipient_sbo_id: number | null }>({
  requester_sbo_id: null,
  recipient_sbo_id: null,
})

const composeForm = useForm<{ sbo_id: number | null; recipient_sbo_id: number | null }>({
  sbo_id: null,
  recipient_sbo_id: null,
})

function connect(listing: Listing) {
  if (props.mySboId === null) return
  connectForm.requester_sbo_id = props.mySboId
  connectForm.recipient_sbo_id = listing.id
  connectForm.post('/sbo/connections', { preserveScroll: true })
}

function message(listing: Listing) {
  if (props.mySboId === null) return
  composeForm.sbo_id = props.mySboId
  composeForm.recipient_sbo_id = listing.id
  composeForm.post('/sbo/conversations')
}

/** Label, and whether the button does anything, for a card's connect slot. */
function connectState(listing: Listing): { label: string; disabled: boolean; title?: string } {
  if (listing.connection === 'self') {
    return { label: t('directory.your_listing'), disabled: true }
  }
  if (props.mySboId === null) {
    return {
      label: t('directory.connect'),
      disabled: true,
      title: t('directory.register_a_business_to_connect_with_other'),
    }
  }
  if (listing.connection === 'pending') {
    return { label: t('directory.pending'), disabled: true, title: t('directory.connection_request_awaiting_a_response') }
  }
  if (listing.connection === 'accepted') {
    return { label: t('directory.message'), disabled: false }
  }
  return { label: t('directory.connect'), disabled: false }
}
</script>

<template>
  <!-- ============================================ cta-banner 13:4 ========= -->
  <!-- The banner background is a VIDEO fill in Figma, not a still. -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo />

    <div
      class="relative flex flex-col items-start justify-between gap-8 px-5 py-10 md:px-10 lg:flex-row lg:items-center xl:px-20"
    >
      <div class="flex max-w-[720px] flex-col items-start gap-6">
        <span class="inline-flex items-center gap-1.5 rounded-md border border-white/20 bg-white/10 px-2.5 py-1">
          <img :src="sparkles" alt="" class="size-2.5" aria-hidden="true" />
          <span class="text-2xs font-semibold tracking-wide text-white uppercase">
            {{ t('directory.loveworld_economic_network') }}
          </span>
        </span>

        <div class="flex flex-col gap-3">
          <h1 class="font-serif text-3xl font-bold text-white">
            {{ t('directory.grow_your_business_with_the_loveworld_netw') }}
          </h1>
          <p class="text-base leading-normal text-[#e2e8f0]">
            {{ t('directory.register_your_business_today_and_connect_w') }}
          </p>
        </div>
      </div>

      <div class="flex w-full justify-start lg:w-[400px] lg:justify-end">
        <RouterLink
          :href="registerUrl"
          class="inline-flex items-center justify-center gap-2 rounded-md btn-primary px-7 py-4 text-sm font-semibold text-white shadow-[0px_4px_8px_rgba(217,119,6,0.2)] "
        >
          {{ t('directory.register_your_business') }}
          <img :src="arrowWhite" alt="" class="size-3" aria-hidden="true" />
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ====================================== hero-filter-rail 12:25 ======== -->
  <section class="border-b border-[#e2e8f0] bg-white px-5 pt-10 pb-6 md:px-10 xl:px-20">
    <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-end">
      <div class="flex flex-col gap-2">
        <p class="text-sm font-semibold tracking-wide text-[#0d5c75] uppercase">
          {{ t('directory.global_business_index') }}
        </p>
        <h2 class="font-serif text-4xl font-bold text-[#1e293b]">
          {{ t('directory.browse_registered_sbos') }}
        </h2>
        <p class="text-base text-[#475569]">
          {{ t('directory.discover_vetted_spirit_led_businesses_and') }}
        </p>
      </div>

      <span class="shrink-0 rounded-[20px] bg-[#f4f7f9] px-4 py-2 text-xs font-semibold text-[#0d5c75]">
        {{ t('directory.active_verified', { total: props.listings.meta.total }) }}
      </span>
    </div>

    <form class="mt-6 flex flex-wrap items-center gap-3" role="search" @submit.prevent="submit">
      <div class="relative h-[38px] min-w-[240px] flex-1 sm:max-w-[357px] sm:flex-none">
        <img :src="searchIcon" alt="" class="absolute top-[11px] left-3 size-4" aria-hidden="true" />
        <input
          v-model="form.search"
          type="search"
          :placeholder="t('directory.search_businesses_products_or_services')"
          class="size-full rounded-md border border-[#e5e7eb] bg-[#f9fafb] pr-3 pl-9 text-2xs font-medium text-[#1f2937] placeholder:text-[#1f2937] focus:border-focus focus:outline-none"
        />
      </div>

      <select
        v-model="form.category"
        class="max-w-full h-[37px] w-[128px] rounded-md border border-[#e5e7eb] bg-[#f9fafb] px-3 text-2xs font-medium text-[#1f2937] focus:border-focus focus:outline-none"
        @change="submit"
      >
        <option value="">{{ t('directory.all_categories') }}</option>
        <!-- The option's VALUE is the stored name, because that is what the
             filter matches on; only the text a reader sees is translated. -->
        <option v-for="c in categories" :key="c.slug" :value="c.name">{{ td(c.name, 'category') }}</option>
      </select>

      <select
        v-model="form.country"
        class="max-w-full h-[37px] w-[128px] rounded-md border border-[#e5e7eb] bg-[#f9fafb] px-3 text-2xs font-medium text-[#1f2937] focus:border-focus focus:outline-none"
        @change="submit"
      >
        <option value="">{{ t('directory.all_countries') }}</option>
        <option v-for="c in countries" :key="c" :value="c">{{ td(c, 'country') }}</option>
      </select>

      <!--
        Industry is the sector above category: picking both narrows twice
        (Technology → Software Development) rather than conflicting. Only
        industries with a listing behind them are offered.
      -->
      <select
        v-model="form.industry"
        :aria-label="t('directory.filter_by_industry')"
        class="max-w-full h-[37px] w-[128px] rounded-md border border-[#e5e7eb] bg-[#f9fafb] px-3 text-2xs font-medium text-[#1f2937] focus:border-focus focus:outline-none"
        @change="submit"
      >
        <option value="">{{ t('directory.all_industries') }}</option>
        <option v-for="i in industries" :key="i" :value="i">{{ td(i, 'industry') }}</option>
      </select>

      <button
        type="submit"
        class="inline-flex h-9 w-[110px] items-center justify-center gap-2 rounded-md bg-[#001529] text-sm font-medium text-white hover:opacity-90"
      >
        {{ t('directory.search') }}
        <img :src="arrowSearch" alt="" class="h-3 w-2.5" aria-hidden="true" />
      </button>

      <!-- Drawn as a button in 12:25; it has to be a select to actually pick
           an order, so it keeps the button's chrome and gains the native menu. -->
      <label
        class="relative ml-auto inline-flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-3.5 py-2 text-xs text-[#475569] hover:bg-surface-secondary"
      >
        <span class="sr-only">{{ t('directory.sort_results') }}</span>
        <span aria-hidden="true">{{ t('directory.sort_by') }}</span>
        <select
          v-model="form.sort"
          class="max-w-full appearance-none bg-transparent pr-4 text-xs font-medium text-[#475569] focus:outline-none"
          @change="submit"
        >
          <option v-for="o in props.sorts" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <img
          :src="chevronDown"
          alt=""
          class="pointer-events-none absolute right-3 size-3.5"
          aria-hidden="true"
        />
      </label>
    </form>
  </section>

  <!-- ============================================ workspace 12:48 ========= -->
  <div class="bg-surface-secondary section-x py-6">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_288px] [&>*]:min-w-0">
      <div class="min-w-0">
      <!-- Directory – In-feed Leaderboard. Empty until an ad is assigned. -->
      <AdSlot name="directory_inline" wrapper-class="mb-5" />
      <ul class="space-y-5">
        <li v-for="l in listings" :key="l.slug">
          <!-- No cover strip here: the directory is a dense scanning list and
               a banner per row turns it into a gallery. The logo carries the
               identity; covers belong on the profile and the featured cards. -->
          <!-- The whole card opens the profile. "View profile" is the stretched
               link — its invisible ::after covers the card — so a click
               anywhere on the row goes to the profile, while the website,
               directions and connect controls sit above it (relative z-10) and
               keep their own actions. -->
          <article class="group relative rounded-lg border border-[#e5e7eb] bg-white p-5 transition-shadow hover:border-[#d1d5db] hover:shadow-md">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="flex min-w-0 gap-3">
                <!-- The owner's logo when set; the building glyph otherwise.
                     Every card drew the glyph before, so no two listings could
                     be told apart at a glance. -->
                <EntityLogoRing :entity="l.entityType" class="shrink-0">
                  <span
                    class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-full bg-[#001529]"
                  >
                    <img v-if="l.logoUrl" :src="l.logoUrl" alt="" class="size-full object-cover" />
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="1.8" class="size-4">
                      <path d="M4 20V9l8-5 8 5v11M9 20v-6h6v6" stroke-linejoin="round" />
                    </svg>
                  </span>
                </EntityLogoRing>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-sans text-xs font-semibold text-[#1f2937]">{{ l.name }}</h3>
                    <span class="rounded-full bg-[#eff6ff] px-2 py-0.5 text-2xs font-semibold text-[#2563eb]">
                      {{ t('directory.verified') }}
                    </span>
                  </div>
                  <p class="mt-1 text-2xs text-[#6b7280]">{{ td(l.category, 'category') }}</p>
                  <p class="mt-1 flex items-center gap-1 text-2xs text-[#6b7280]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-3 shrink-0" aria-hidden="true">
                      <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z" stroke-linejoin="round" />
                      <circle cx="12" cy="10" r="2.5" />
                    </svg>
                    {{ l.address }}
                  </p>
                </div>
              </div>

              <div class="flex w-full flex-row flex-wrap items-center gap-x-3 gap-y-1.5 sm:w-auto sm:shrink-0 sm:flex-col sm:items-end">
                <span class="flex items-center gap-1 text-2xs text-[#6b7280]">
                  <span class="tracking-tight text-[#facc15]" aria-hidden="true">★★★★★</span>
                  ({{ l.reviews }})
                </span>
                <span class="rounded-full bg-[#dcfce7] px-2 py-0.5 text-2xs font-semibold text-[#007035]">
                  {{ t('directory.open_now') }}
                </span>
                <!-- One per country the business holds an address in; see
                     Sbo::countryCodes. It was a fixed three-flag stack. -->
                <UiCountryFlags :codes="l.countryCodes" />
              </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-[#f3f4f6] pt-3">
              <RouterLink
                :href="`/business/${l.slug}`"
                class="inline-flex items-center gap-1.5 rounded-sm border border-[#e5e7eb] px-3 py-2 text-2xs font-semibold text-[#1f2937] after:absolute after:inset-0 after:rounded-lg after:content-[''] hover:bg-surface-secondary"
              >
                {{ t('directory.view_profile') }}
              </RouterLink>

              <!-- Leaves the app, so a real anchor rather than an Inertia Link.
                   Omitted entirely when the listing has no website: a dead
                   button is worse than an absent one. `relative z-10` keeps it
                   clickable above the card's stretched link. -->
              <a
                v-if="l.website"
                :href="l.website"
                target="_blank"
                rel="noopener noreferrer external"
                class="relative z-10 inline-flex items-center gap-1.5 rounded-sm border border-[#e5e7eb] px-3 py-2 text-2xs font-semibold text-[#1f2937] hover:bg-surface-secondary"
              >
                {{ t('directory.visit_website') }}
              </a>

              <a
                v-if="l.directions"
                :href="l.directions"
                target="_blank"
                rel="noopener noreferrer external"
                class="relative z-10 inline-flex items-center gap-1.5 rounded-sm border border-[#e5e7eb] px-3 py-2 text-2xs font-semibold text-[#1f2937] hover:bg-surface-secondary"
              >
                {{ t('directory.directions') }}
              </a>

              <!-- Connect is shown disabled on some rows in the design; which
                   rows is the connection's own state, not decoration. -->
              <button
                type="button"
                :disabled="connectState(l).disabled || connectForm.processing || composeForm.processing"
                :title="connectState(l).title"
                class="relative z-10 inline-flex items-center gap-1.5 rounded-sm border border-[#e5e7eb] px-3 py-2 text-2xs font-semibold text-[#1f2937] hover:bg-surface-secondary disabled:cursor-not-allowed disabled:text-[#9ca3af] disabled:hover:bg-transparent"
                @click="l.connection === 'accepted' ? message(l) : connect(l)"
              >
                {{ connectState(l).label }}
              </button>
            </div>
          </article>
        </li>

        <!-- Empty state: reachable on a filter miss and on a fresh database. -->
        <li v-if="!listings.length">
          <div class="rounded-lg border border-dashed border-[#e5e7eb] bg-white px-5 py-8 sm:py-12 text-center">
            <p class="text-base font-semibold text-[#1f2937]">{{ t('directory.no_businesses_found') }}</p>
            <p class="mt-1 text-2xs text-[#6b7280]">
              {{ t('directory.try_a_different_search_term_or_clear_the_f') }}
            </p>
          </div>
        </li>
      </ul>

      <!--
        Paginator links come from `linkCollection()`, so they already carry the
        active filters via `withQueryString()`. `preserveScroll` keeps the user
        on the results rather than bouncing to the hero on every page change.
      -->
      <nav
        v-if="props.listings.meta.last_page > 1"
        class="mt-6 flex flex-wrap items-center justify-center gap-2"
        :aria-label="t('directory.pagination')"
      >
        <template v-for="(link, i) in props.listings.links" :key="i">
          <RouterLink
            v-if="link.url"
            :href="link.url"
            preserve-scroll
            class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-2xs font-medium"
            :class="
              link.active
                ? 'border-[#001529] bg-[#001529] text-white'
                : 'border-[#e5e7eb] bg-white text-[#1f2937] hover:bg-surface-secondary'
            "
            :aria-current="link.active ? 'page' : undefined"
            v-html="link.label"
          />
          <span
            v-else
            class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-[#e5e7eb] bg-white px-3 text-2xs font-medium text-[#9ca3af]"
            v-html="link.label"
          />
        </template>
      </nav>
      </div>

      <aside class="min-w-0 space-y-5">
        <!-- Directory – Sidebar. Empty until an ad is assigned. -->
        <AdSlot name="directory_sidebar" />
        <MarketingRail sponsored-label />
      </aside>
    </div>
  </div>

  <!-- ==================================== benefits strip 52:1794 ========== -->
  <BenefitsStrip />
</template>
