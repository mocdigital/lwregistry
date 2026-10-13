<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import { Link as RouterLink, useForm, usePage } from '@inertiajs/vue3'
import { normalizeUrl } from '@/composables/useExternalUrl'
import PublicLayout from '@/layouts/PublicLayout.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import EntityLogoRing from '@/components/ui/EntityLogoRing.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import AdSlot from '@/components/ads/AdSlot.vue'
import heroHouse from '@/assets/figma/profile/hero-house.jpg'
import sboCloudtrac from '@/assets/figma/cat/sbo-cloudtrac.jpg'
import sboDma from '@/assets/figma/cat/sbo-dma.jpg'
import sboWorkforce from '@/assets/figma/cat/sbo-workforce.jpg'
import hlUsers from '@/assets/figma/icons/hl-users.svg'
import hlBriefcase from '@/assets/figma/icons/hl-briefcase.svg'
import hlCalendar from '@/assets/figma/icons/hl-calendar.svg'
import hlStar from '@/assets/figma/icons/hl-star.svg'
import UiCountryFlags from '@/components/ui/UiCountryFlags.vue'
import { useI18n } from '@/composables/useI18n'

const { t, tc, td } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

/*
 * Business profile — Figma 39:4 "ditabol-homes-profile-redesign" (1280x4975),
 * the tallest public screen.
 *
 * Sections: header 406:23773 · interactive-hero 39:20 · quick-stats 39:49 ·
 * workspace-container 39:62 · cta-banner 39:283 · popular-categories 39:292 ·
 * benefits 52:1990 · footer.
 *
 * FIDELITY:
 *  - hero 39:20 and quick-stats 39:49 are exact (type, colours, layout pulled
 *    from get_design_context / the rendered node).
 *  - workspace 39:62 follows the frame's real children in order: media gallery
 *    39:64, highlights 39:85, services 39:118, location+hours 77:4, and the
 *    reviews section 39:165 which includes the submit-review form. Similar
 *    Businesses (39:256) is a SIDEBAR widget here, not a main-column section.
 *    Per-block type and fills are not yet inspected node-by-node.
 *
 * Prototype: the `verified-badge` here links to /business/:slug/verification
 * (sbo-verify 223:4647).
 */

defineOptions({ layout: PublicLayout })

const props = defineProps<{
  business: {
    id: number
    name: string
    slug: string
    category: string | null
    description: string | null
    verified: boolean
    address: string
    website: string | null
    /** The owner's hero photo and logo; null until they upload one. */
    coverUrl: string | null
    logoUrl: string | null
    /** 'SBO' | 'COB' — colours the animated logo ring. */
    entityType: string | null
    details: { label: string; value: string }[]
  }
  /*
   * The four sections below were `const` arrays in this file until now — the
   * same three services and the same "4.5 Score" on every listing. They are
   * per-SBO records served by BusinessController::show, and each arrives empty
   * when the business has not filled it in, which hides the section rather
   * than showing an invented default.
   */
  /** Derived from the same columns the details list reads; see Sbo::highlights(). */
  highlights: { key: string; value: string; label: string }[]
  services: { title: string; body: string | null }[]
  /** One entry per gallery tab that actually has photos. */
  gallery: { label: string; images: { thumb: string; full: string; alt: string }[] }[]
  /** Monday-first; days the owner never set are absent. */
  hours: { day: string; time: string }[]
  /** Real listings for the sidebar widget; see BusinessController::similarTo. */
  similar: {
    name: string
    slug: string
    tagline: string | null
    logoUrl: string | null
    coverUrl: string | null
    entityType: string | null
  }[]
  /**
   * The location map. `embedUrl` is null when the address could not be
   * resolved to a point, and the block then shows the address and a search
   * link rather than an empty frame.
   */
  map: {
    address: string
    latitude: number | null
    longitude: number | null
    embedUrl: string | null
    linkUrl: string
  }
  /** Where the viewer stands with this business; see SboConnectionStates. */
  connection: 'self' | 'none' | 'pending' | 'accepted'
  /** False when the owner has closed connection requests in Settings › Privacy. */
  acceptsConnections: boolean
  /** Real reviews, and whether the viewer may add one. */
  reviews: {
    items: {
      id: number
      author: string
      initials: string
      rating: number
      comment: string | null
      at: string | null
      mine: boolean
    }[]
    count: number
    /** Null when there are none — a listing with no reviews is not rated 0. */
    average: number | null
    canReview: boolean
    isOwnListing: boolean
    mine: { rating: number; comment: string | null } | null
    postUrl: string
  }
  /** Every place the business trades from, primary first. */
  addresses: {
    id: number
    label: string | null
    line: string
    isPrimary: boolean
    countryCode: string | null
    latitude: number | null
    longitude: number | null
    mapUrl: string | null
    directionsUrl: string | null
  }[]
  /** The row Disconnect acts on; null unless actually connected. */
  connectionId: number | null
  /** Which of the viewer's SBOs Connect acts as; null for a visitor. */
  mySboId: number | null
  /*
   * Reporting. `canReport` is false for a visitor, for the owner of this
   * listing, and for a member who is not verified — a report puts a listing in
   * front of a reviewer and can end in suspension, so raising one is not
   * something an unverified account can do.
   */
  report: {
    canReport: boolean
    isOwn: boolean
    url: string
    reasons: { value: string; label: string }[]
  }
}>()

/* ------------------------------------------------------------- report --- */

const reportOpen = ref(false)

const reportForm = useForm({ reason: '', details: '' })

function submitReport() {
  reportForm.post(props.report.url, {
    preserveScroll: true,
    onSuccess: () => {
      reportForm.reset()
      reportOpen.value = false
    },
  })
}

/*
 * The hero's three buttons. All were inert: Connect and Disconnect were bare
 * <button>s with no handler, and Visit Website was href="#".
 */
const websiteUrl = computed(() => normalizeUrl(props.business.website))

const connectForm = useForm<{ requester_sbo_id: number | null; recipient_sbo_id: number }>({
  requester_sbo_id: props.mySboId,
  recipient_sbo_id: props.business.id,
})

function connect() {
  if (!props.mySboId) return
  connectForm.requester_sbo_id = props.mySboId
  connectForm.post('/sbo/connections', { preserveScroll: true })
}

const disconnectForm = useForm({})

function disconnect() {
  if (!props.connectionId) return
  if (!window.confirm(t('business_profile.disconnect_confirm', { name: props.business.name }))) {
    return
  }
  disconnectForm.delete(`/sbo/connections/${props.connectionId}`, { preserveScroll: true })
}

/* ------------------------------------------------------------------ reviews ---
 *
 * The section rendered three invented reviews reading "Awaiting backend data",
 * a fixed "4.5/5.0 (12 reviews)", and a form with `@submit.prevent` and no
 * handler — under a route that bound a legacy model whose table is empty. Real
 * now, and pre-filled when the viewer has already left one, because posting
 * again updates rather than duplicates.
 */
const reviewForm = useForm({
  rating: props.reviews.mine?.rating ?? 0,
  comment: props.reviews.mine?.comment ?? '',
})

const reviewSaved = computed(() => usePage().props.flash.status)

function submitReview() {
  reviewForm.post(props.reviews.postUrl, { preserveScroll: true })
}

/** Connect is only offered when there is an SBO to act as and no row already. */
const canConnect = computed(
  () => props.connection === 'none' && props.mySboId !== null && props.acceptsConnections,
)

/*
 * Highlights drive both the quick-stats bar and the highlights cards — the
 * design shows the same figures twice, and reading them from one source is
 * what stops the two from drifting apart.
 *
 * The icon belongs to the presentation, so it is matched here by the stable
 * `key` the server sends rather than shipped from PHP.
 */
const highlightIcons: Record<string, string> = {
  staff: hlUsers,
  services: hlBriefcase,
  years: hlCalendar,
  rating: hlStar,
}

/*
 * Similar businesses come from the server now.
 *
 * They were three invented companies with invented slugs — cloudtrac,
 * dma-shelvings, strategic-workforce — so every link in the widget led to a
 * 404. The stock images stay only as a fallback tile for a listing that has
 * uploaded no artwork.
 */
const similarTiles = [sboCloudtrac, sboDma, sboWorkforce]

const similar = computed(() =>
  props.similar.map((b, i) => ({
    ...b,
    img: b.coverUrl ?? b.logoUrl ?? similarTiles[i % similarTiles.length],
  })),
)

const popularCategories = [
  t('business_profile.branding_agency'), t('business_profile.product_design'), t('business_profile.support_services'), t('data.category.legal_consultants'),
  t('business_profile.property_management'), t('business_profile.fmcg_supply'), t('business_profile.staffing_solutions'),
]

// media-gallery-card 39:64 — tabs are whichever collections have photos.
const activeGallery = ref(0)
const activeImages = computed(() => props.gallery[activeGallery.value]?.images ?? [])

// Company details come from the server; only populated fields are sent.
const companyDetails = computed(() => props.business.details)
</script>

<template>
  <!-- ===================================== interactive-hero 39:20 ========= -->
  <section class="relative overflow-hidden">
    <!-- The business's own cover when it has one; the stock house otherwise.
         Every profile in the registry used the same photo before. -->
    <img
      :src="props.business.coverUrl ?? heroHouse"
      alt=""
      class="absolute inset-0 size-full object-cover"
      aria-hidden="true"
    />
    <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(11,25,44,0.92),rgba(11,25,44,0.55))]" aria-hidden="true" />

    <div class="relative flex flex-col gap-5 section-x py-10">
      <div class="flex items-start gap-6">
        <EntityLogoRing :entity="props.business.entityType" class="hidden shrink-0 sm:inline-flex">
          <span
            class="grid size-[90px] place-items-center overflow-hidden rounded-full border-2 border-white bg-[#0b192c]"
            aria-hidden="true"
          >
            <img v-if="props.business.logoUrl" :src="props.business.logoUrl" alt="" class="size-full object-cover" />
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="1.6" class="size-9">
              <path d="M4 16h16M6 16a6 6 0 0 1 12 0M12 4v3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </span>
        </EntityLogoRing>

        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <span
              v-if="props.business.category"
              class="rounded-md bg-white/20 px-2.5 py-1 text-2xs font-semibold text-white"
            >
              {{ td(props.business.category, 'category') }}
            </span>
            <RouterLink
              v-if="props.business.verified"
              :href="`/business/${props.business.slug}/verification`"
              class="inline-flex items-center gap-1 rounded-md bg-[#dbeafe] px-2.5 py-1 text-2xs font-semibold text-[#2563eb]"
            >
              {{ t('business_profile.verified') }}
            </RouterLink>
          </div>

          <h1 class="mt-3 font-serif text-4xl font-bold text-white">
            {{ props.business.name }}
          </h1>
          <p class="mt-1.5 text-base text-white/90">{{ t('business_profile.building_your_dream_homes_across_nigeria') }}</p>

          <p v-if="props.reviews.average !== null" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-[#facc15]" :aria-label="tc('business_profile.star_count', Math.round(props.reviews.average))">
              {{ '★'.repeat(Math.round(props.reviews.average))
              }}<span class="text-white/30">{{ '★'.repeat(5 - Math.round(props.reviews.average)) }}</span>
            </span>
            <span class="font-semibold text-white">{{ props.reviews.average }} / 5.0</span>
            <span class="text-white/70">{{ tc('business_profile.review_count', props.reviews.count) }}</span>
          </p>

          <div class="mt-5 flex flex-wrap gap-3">
            <!--
              Connect states: your own listing shows nothing to do, a request
              already sent reads Pending and is disabled, and a live connection
              is replaced by Disconnect below.
            -->
            <button
              v-if="connection !== 'accepted' && connection !== 'self'"
              type="button"
              :disabled="!canConnect || connectForm.processing"
              :title="
                !acceptsConnections
                  ? t('business_profile.connections_closed')
                  : mySboId
                    ? undefined
                    : t('business_profile.register_a_business_to_connect')
              "
              class="inline-flex items-center gap-2 rounded-md btn-primary px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
              @click="connect"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
                <circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0 1 12 0M17 11h4M19 9v4" stroke-linecap="round" />
              </svg>
              {{ connection === 'pending' ? t('common.pending') : connectForm.processing ? t('forgot_password.sending') : t('action.connect') }}
            </button>

            <!--
              Not every listing has a website, and the column is free text, so a
              value that is not plausibly a host is not linked at all.
            -->
            <a
              v-if="websiteUrl"
              :href="websiteUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-2 rounded-md border border-white/40 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/10"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
                <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
              </svg>
              {{ t('business_profile.visit_website') }}
            </a>

            <button
              v-if="connection === 'accepted'"
              type="button"
              :disabled="disconnectForm.processing"
              class="inline-flex items-center gap-2 rounded-md border border-white/40 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/10 disabled:opacity-60"
              @click="disconnect"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
              </svg>
              {{ disconnectForm.processing ? t('business_profile.disconnecting') : t('business_profile.disconnect') }}
            </button>

            <!--
              Report. Hidden on your own listing; shown but disabled for a
              member who is not verified, so the rule is visible rather than
              the control simply missing.
            -->
            <button
              v-if="!report.isOwn"
              type="button"
              :disabled="!report.canReport"
              :title="report.canReport ? undefined : t('business_profile.only_a_verified_business_can_report_a_list')"
              class="inline-flex items-center gap-2 rounded-md border border-white/25 px-5 py-2.5 text-sm font-semibold text-white/80 hover:bg-white/10 hover:text-white disabled:cursor-not-allowed disabled:opacity-45"
              @click="reportOpen = true"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
                <path d="M4 21V4h11l-1 3h6v9h-7l-1-3H4" stroke-linejoin="round" />
              </svg>
              {{ t('business_profile.report') }}
            </button>
          </div>

          <p v-if="$page.props.flash?.status === 'report-submitted'" class="mt-3 text-sm text-[#6ee7b7]">
            {{ t('business_profile.thank_you_your_report_has_been_sent_to_the') }}
          </p>
          <p v-else-if="$page.props.flash?.status === 'report-already-open'" class="mt-3 text-sm text-[#fcd34d]">
            {{ t('business_profile.you_already_have_an_open_report_against_th') }}
          </p>
        </div>
      </div>

    </div>
  </section>

  <!-- ========================================== quick-stats 39:49 =========
       Same figures as the highlights cards below, which is what the design
       asks for — so they read from the same prop and cannot disagree. -->
  <section v-if="props.highlights.length" class="border border-[#e2e8f0] bg-white section-x py-6">
    <dl class="flex flex-wrap items-center justify-between gap-y-4">
      <div v-for="s in props.highlights" :key="s.key" class="flex min-w-[140px] flex-1 flex-col items-center gap-1">
        <dt class="text-2xs font-semibold tracking-wide text-[#64748b] uppercase">{{ s.label }}</dt>
        <dd class="font-display text-xl text-[#0b192c]">{{ s.value }}</dd>
      </div>
    </dl>
  </section>

  <!-- ================================ workspace-container 39:62 ===========
       Left column 840px, right sidebar 304px. Section order and content taken
       from the frame's own children:
         media-gallery 39:64 · highlights 39:85 · services 39:118 ·
         location+hours 77:4 · reviews+form 39:165
       Sidebar: promo 39:226 · live opportunities 387:21781 ·
         similar businesses 39:256  (no build-connections card on this screen) -->
  <div class="bg-surface-secondary section-x py-6">
    <!-- Design is 840 + 304 in a 1120 content width; the left column flexes so
         the section spans the same width as its neighbours below xl. -->
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_304px] [&>*]:min-w-0">
      <div class="min-w-0 space-y-6">
        <!-- About the company — added on request, not a Figma node. -->
        <section class="rounded-lg border border-border bg-white p-5">
          <h2 class="font-serif text-2xl font-bold text-[#0b192c]">{{ t('business_profile.about_the_company') }}</h2>
          <p class="mt-2 text-xs leading-5 text-ink-secondary">
            {{ props.business.description || t('profile.about.empty') }}
          </p>
          <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
            <div
              v-for="d in companyDetails"
              :key="d.label"
              class="flex flex-col gap-0.5 border-b border-border-subtle pb-3"
            >
              <dt class="text-2xs font-semibold tracking-wide text-[#64748b] uppercase">{{ d.label }}</dt>
              <dd class="text-xs text-ink">{{ d.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- Company Detail – Below About. Empty until an ad is assigned. -->
        <AdSlot name="company_below_about" />

        <!-- media-gallery-card 39:64 — only tabs with photos are sent. -->
        <section v-if="props.gallery.length" class="rounded-lg border border-border bg-white p-5">
          <h2 class="font-sans text-sm font-semibold">{{ t('business_profile.media_gallery') }}</h2>
          <div v-if="props.gallery.length > 1" class="mt-3 flex gap-2 overflow-x-auto border-b border-border">
            <button
              v-for="(t, i) in props.gallery"
              :key="t.label"
              type="button"
              class="shrink-0 border-b-2 px-2 pb-2 text-2xs font-medium transition-colors"
              :class="i === activeGallery ? 'border-ink text-ink' : 'border-transparent text-ink-secondary hover:text-ink'"
              @click="activeGallery = i"
            >
              {{ t.label }}
            </button>
          </div>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
            <a
              v-for="(g, i) in activeImages"
              :key="i"
              :href="g.full"
              target="_blank"
              rel="noopener"
              class="block overflow-hidden rounded-md"
            >
              <img
                :src="g.thumb"
                :alt="g.alt"
                loading="lazy"
                class="h-40 w-full object-cover transition-transform hover:scale-[1.03]"
              />
            </a>
          </div>
        </section>

        <!-- highlights-container 39:85 — derived, see Sbo::highlights() -->
        <section v-if="props.highlights.length">
          <h2 class="font-serif text-2xl font-bold text-[#0b192c]">{{ t('business_profile.sbo_highlights') }}</h2>
          <p class="mt-1.5 text-sm text-[#64748b]">
            {{ t('business_profile.stats_for', { name: props.business.name }) }}
          </p>
          <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
            <li
              v-for="h in props.highlights"
              :key="h.key"
              class="flex flex-col items-center gap-3 rounded-[10px] border border-[#e2e8f0] bg-white p-5"
            >
              <span class="rounded-full bg-[rgba(0,128,128,0.2)] p-2.5">
                <img :src="highlightIcons[h.key]" alt="" class="size-6" aria-hidden="true" />
              </span>
              <span class="flex flex-col items-center gap-0.5 text-center">
                <span class="font-display text-xl text-[#0b192c]">{{ h.value }}</span>
                <span class="text-2xs text-[#64748b]">{{ h.label }}</span>
              </span>
            </li>
          </ul>
        </section>

        <!-- services-section 39:118 -->
        <section v-if="props.services.length" class="rounded-lg border border-border bg-white p-5">
          <h2 class="font-sans text-sm font-semibold">{{ t('business_profile.services') }}</h2>
          <ul class="mt-4 grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
            <li v-for="s in props.services" :key="s.title">
              <h3 class="font-sans text-xs font-semibold text-ink">{{ s.title }}</h3>
              <p v-if="s.body" class="mt-1 text-2xs leading-5 text-ink-secondary">{{ s.body }}</p>
            </li>
          </ul>
        </section>

        <!-- location-map-section 77:4 — the address always renders; the hours
             column only appears once the owner has set some. -->
        <section class="rounded-lg border border-border bg-white p-5">
          <h2 class="font-sans text-sm font-semibold">{{ t('business_profile.location_amp_opening_hours') }}</h2>
          <div class="mt-4 grid gap-5 [&>*]:min-w-0" :class="props.hours.length ? 'lg:grid-cols-[minmax(0,1fr)_260px]' : ''">
            <!-- OpenStreetMap: no API key, no account, and no handing the
                 visitor's IP to an ad network to see a street. `loading=lazy`
                 because this sits well below the fold. -->
            <div class="flex flex-col gap-2">
              <iframe
                v-if="props.map.embedUrl"
                :src="props.map.embedUrl"
                class="h-56 w-full rounded-md border border-border"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                :title="t('business_profile.map_alt', { name: props.business.name })"
              />

              <!-- Unresolvable address: the address itself is still the useful
                   thing, so it is shown rather than an empty grey box. -->
              <div
                v-else
                class="grid h-56 place-items-center rounded-md bg-surface-tertiary px-4 text-center"
              >
                <div>
                  <p class="text-xs font-semibold text-ink">{{ props.map.address || t('my_account.no_address_on_file') }}</p>
                  <p v-if="props.map.address" class="mt-1 text-2xs text-ink-tertiary">
                    {{ t('business_profile.we_could_not_place_this_address_on_a_map') }}
                  </p>
                </div>
              </div>

              <a
                v-if="props.map.address"
                :href="props.map.linkUrl"
                target="_blank"
                rel="noopener noreferrer external"
                class="text-2xs font-semibold text-primary hover:underline"
              >
                {{ props.map.embedUrl ? t('business_profile.open_in_openstreetmap') : t('business_profile.search_this_address') }}
              </a>

              <!--
                Every place this business trades from. The map above pins the
                main address; a business with branches in three countries used
                to show three flags on its card and one address on its page.
                Hidden when there is only the one the map already shows.
              -->
              <div v-if="props.addresses.length > 1" class="mt-2">
                <p class="text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
                  {{ t('business_profile.all_locations') }}
                </p>
                <ul class="mt-2 space-y-2">
                  <li
                    v-for="a in props.addresses"
                    :key="a.id"
                    class="flex min-w-0 flex-wrap items-start gap-2 border-b border-border-subtle pb-2 last:border-b-0"
                  >
                    <UiCountryFlags v-if="a.countryCode" :codes="[a.countryCode]" :max="1" class="mt-0.5 shrink-0" />

                    <span class="min-w-0 flex-1">
                      <span class="flex flex-wrap items-center gap-2">
                        <span v-if="a.label" class="text-2xs font-semibold text-ink">{{ a.label }}</span>
                        <span
                          v-if="a.isPrimary"
                          class="rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary"
                        >
                          {{ t('business_profile.main_address') }}
                        </span>
                      </span>
                      <span class="mt-0.5 block text-2xs text-ink-secondary">{{ a.line }}</span>
                    </span>

                    <a
                      v-if="a.directionsUrl"
                      :href="a.directionsUrl"
                      target="_blank"
                      rel="noopener noreferrer external"
                      class="shrink-0 text-2xs font-semibold text-primary hover:underline"
                    >
                      {{ t('action.directions') }}
                    </a>
                  </li>
                </ul>
              </div>
            </div>
            <dl v-if="props.hours.length" class="space-y-2">
              <div v-for="h in props.hours" :key="h.day" class="flex items-center justify-between gap-4 border-b border-border-subtle pb-2 last:border-b-0">
                <dt class="text-2xs font-semibold text-ink">{{ h.day }}</dt>
                <dd class="text-2xs" :class="h.time === 'Closed' ? 'text-danger' : 'text-ink-secondary'">{{ h.time }}</dd>
              </div>
            </dl>
          </div>
        </section>

        <!-- reviews-section 39:165 — list plus submit form -->
        <section class="rounded-lg border border-border bg-white p-5">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-sans text-sm font-semibold">{{ t('business_profile.client_reviews') }}</h2>
            <!-- The average and the count, or nothing. A listing with no
                 reviews is not a listing rated 0. -->
            <span v-if="props.reviews.average !== null" class="text-2xs text-ink-tertiary">
              {{ props.reviews.average }}/5.0 ·
              {{ tc('business_profile.review_count', props.reviews.count) }}
            </span>
          </div>

          <p v-if="reviewSaved" class="mt-3 rounded-md bg-success/10 px-3 py-2 text-2xs text-success" role="status">
            {{ reviewSaved }}
          </p>

          <ul v-if="props.reviews.items.length" class="mt-4 space-y-4">
            <li
              v-for="r in props.reviews.items"
              :key="r.id"
              class="border-b border-border-subtle pb-4 last:border-b-0 last:pb-0"
            >
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="grid size-7 shrink-0 place-items-center rounded-full bg-surface-tertiary text-2xs font-semibold"
                  aria-hidden="true"
                >{{ r.initials }}</span>
                <span class="text-xs font-semibold">{{ r.author }}</span>
                <span v-if="r.mine" class="rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary">
                  {{ t('business_profile.your_review') }}
                </span>
                <span class="ml-auto flex items-center gap-2">
                  <span class="text-[#facc15]" :aria-label="tc('business_profile.star_count', r.rating)">
                    {{ '★'.repeat(r.rating) }}<span class="text-border">{{ '★'.repeat(5 - r.rating) }}</span>
                  </span>
                  <span class="text-2xs text-ink-tertiary">{{ r.at }}</span>
                </span>
              </div>
              <p v-if="r.comment" class="mt-2 text-2xs leading-5 text-ink-secondary">{{ r.comment }}</p>
            </li>
          </ul>

          <p v-else class="mt-4 rounded-md bg-surface-secondary px-4 py-6 text-center text-2xs text-ink-secondary">
            {{ t('business_profile.no_reviews_yet') }}
          </p>

          <!-- Offered only to somebody who may actually post one; the reasons
               they may not are stated rather than the form being silently
               absent. -->
          <form
            v-if="props.reviews.canReview"
            class="mt-6 border-t border-border pt-5"
            @submit.prevent="submitReview"
          >
            <h3 class="font-sans text-xs font-semibold">
              {{ props.reviews.mine ? t('business_profile.update_your_review') : t('business_profile.submit_review') }}
            </h3>
            <p class="mt-1 text-2xs text-ink-secondary">
              {{ t('business_profile.share_experience_with', { name: props.business.name }) }}
            </p>

            <div class="mt-4 space-y-4">
              <div>
                <span class="text-2xs font-semibold text-slate-800">{{ t('business_profile.rating') }}</span>
                <div class="mt-1.5 flex gap-1 text-lg">
                  <button
                    v-for="n in 5"
                    :key="n"
                    type="button"
                    class="transition-colors"
                    :class="n <= reviewForm.rating ? 'text-[#facc15]' : 'text-border hover:text-[#facc15]/60'"
                    :aria-label="tc('business_profile.star_count', n)"
                    :aria-pressed="n === reviewForm.rating"
                    @click="reviewForm.rating = n"
                  >★</button>
                </div>
                <span v-if="reviewForm.errors.rating" class="mt-1 block text-2xs text-danger">
                  {{ reviewForm.errors.rating }}
                </span>
              </div>

              <label class="block">
                <span class="text-2xs font-semibold text-slate-800">{{ t('business_profile.review') }}</span>
                <textarea
                  v-model="reviewForm.comment"
                  rows="4"
                  maxlength="5000"
                  class="mt-1.5 w-full rounded-md border border-border-input px-4 py-2.5 text-xs focus:border-focus focus:outline-none"
                />
                <span v-if="reviewForm.errors.comment" class="mt-1 block text-2xs text-danger">
                  {{ reviewForm.errors.comment }}
                </span>
              </label>

              <button
                type="submit"
                :disabled="reviewForm.processing || reviewForm.rating < 1"
                class="rounded-md btn-primary px-6 py-2.5 text-xs font-semibold text-white disabled:opacity-60"
              >
                {{ reviewForm.processing ? t('common.saving') : t('business_profile.submit_review') }}
              </button>
            </div>
          </form>

          <p v-else class="mt-6 border-t border-border pt-5 text-2xs text-ink-secondary">
            {{
              props.reviews.isOwnListing
                ? t('business_profile.cannot_review_own')
                : t('business_profile.sign_in_to_review')
            }}
          </p>
        </section>
      </div>

      <!-- right-sidebar-column 39:225 -->
      <aside class="min-w-0 space-y-5">
        <!-- Company Detail – Sidebar. Empty until an ad is assigned. -->
        <AdSlot name="company_sidebar" />
        <MarketingRail :cards="['advertise', 'opportunities']" />

        <!-- similar-businesses-widget 39:256 — sidebar, not the main column -->
        <section v-if="similar.length" class="rounded-lg border border-border bg-white p-4">
          <h2 class="font-sans text-xs font-semibold">{{ t('business_profile.similar_businesses') }}</h2>
          <ul class="mt-3 space-y-3">
            <li v-for="b in similar" :key="b.slug">
              <RouterLink
                :href="`/business/${b.slug}`"
                class="flex gap-2.5 hover:opacity-80"
              >
                <EntityLogoRing :entity="b.entityType" radius="8px" class="shrink-0">
                  <img :src="b.img" :alt="b.name" class="size-10 rounded-md object-cover" />
                </EntityLogoRing>
                <span class="min-w-0">
                  <span class="block text-2xs font-semibold text-ink">{{ b.name }}</span>
                  <span class="block text-2xs text-ink-tertiary">{{ b.tagline }}</span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </div>

  <!-- =========================================== cta-banner 39:283 ======== -->
  <section class="bg-[#001529] px-5 py-8 sm:py-12 text-center md:px-10 xl:px-20">
    <h2 class="font-serif text-3xl font-bold text-white">{{ t('business_profile.connect_with_today', { name: props.business.name }) }}</h2>
    <p class="mx-auto mt-3 max-w-xl text-xs text-white/70">
      {{ t('business_profile.join_over_10_000_corporate_partners_and_ve') }}
    </p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">
      <RouterLink
        :href="registerUrl"
        class="rounded-md btn-primary px-6 py-3 text-xs font-semibold text-white "
      >
        {{ t('business_profile.register_your_sbo') }}
      </RouterLink>
      <RouterLink
        href="/businesses"
        class="rounded-md border border-white/40 px-6 py-3 text-xs font-semibold text-white hover:bg-white/10"
      >
        {{ t('business_profile.browse_all_businesses') }}
      </RouterLink>
    </div>
  </section>

  <!-- ==================================== popular-categories 39:292 ======= -->
  <section class="bg-white section-x py-10">
    <h2 class="font-sans text-sm font-semibold">{{ t('business_profile.popular_categories') }}</h2>
    <ul class="mt-4 flex flex-wrap gap-3">
      <li v-for="c in popularCategories" :key="c">
        <RouterLink
          href="/categories"
          class="inline-block rounded-full border border-border px-4 py-2 text-2xs text-ink-secondary hover:border-primary hover:text-primary"
        >
          {{ c }}
        </RouterLink>
      </li>
    </ul>
  </section>

  <!-- ==================================== benefits strip 52:1990 ========== -->
  <BenefitsStrip />

  <!--
    Report dialog. A fixed reason list rather than free text — the admin queue
    groups by reason, and a reason typed differently by every reporter cannot be
    grouped — with the specifics in the detail box.
  -->
  <div
    v-if="reportOpen"
    class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="report-title"
    @click.self="reportOpen = false"
    @keydown.esc="reportOpen = false"
  >
    <div class="w-full max-w-lg rounded-2xl bg-surface p-6 shadow-[0px_24px_48px_rgba(15,23,42,0.24)]">
      <h2 id="report-title" class="font-display text-lg font-semibold text-ink">
        {{ t('business_profile.report_named', { name: props.business.name }) }}
      </h2>
      <p class="mt-1 text-xs text-ink-secondary">
        {{ t('business_profile.report_privacy', { name: props.business.name }) }}
      </p>

      <form class="mt-5 flex flex-col gap-4" @submit.prevent="submitReport">
        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-ink">{{ t('business_profile.reason') }}</span>
          <select
            v-model="reportForm.reason"
            required
            class="max-w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink focus:border-focus focus:outline-none"
          >
            <option value="" disabled>{{ t('business_profile.select_a_reason') }}</option>
            <option v-for="r in report.reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <span v-if="reportForm.errors.reason" class="text-xs text-danger">
            {{ reportForm.errors.reason }}
          </span>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-ink">{{ t('business_profile.what_happened') }}</span>
          <textarea
            v-model="reportForm.details"
            rows="4"
            required
            minlength="20"
            :placeholder="t('business_profile.give_the_reviewer_enough_detail_to_act_on')"
            class="resize-y rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
          />
          <span v-if="reportForm.errors.details" class="text-xs text-danger">
            {{ reportForm.errors.details }}
          </span>
          <span v-else class="text-xs text-ink-tertiary">{{ t('business_profile.at_least_20_characters') }}</span>
        </label>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-border bg-surface px-4 py-2.5 text-xs font-semibold text-ink-secondary hover:bg-surface-secondary"
            @click="reportOpen = false"
          >
            {{ t('business_profile.cancel') }}
          </button>
          <button
            type="submit"
            :disabled="reportForm.processing"
            class="btn-primary rounded-lg px-5 py-2.5 text-xs font-semibold disabled:opacity-60"
          >
            {{ reportForm.processing ? t('forgot_password.sending') : t('business_profile.submit_report') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
