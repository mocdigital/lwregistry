<script setup lang="ts">
import SboCertificatePreview from '@/components/sbo/SboCertificatePreview.vue'
import EntityLogoRing from '@/components/ui/EntityLogoRing.vue'
import { computed, ref } from 'vue'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import { Link as RouterLink } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import LiveOpportunities from '@/components/shell/LiveOpportunities.vue'
import { DEFAULT_AVATAR } from '@/assets/defaultAvatar'
import icLayout from '@/assets/figma/icons/hero-layout.svg'
import icVerified from '@/assets/figma/icons/hero-verified-check.svg'
import icEdit from '@/assets/figma/icons/hero-edit.svg'
import icShield from '@/assets/figma/icons/hero-shield.svg'
import qaPlus from '@/assets/figma/icons/qa-plus-circle.svg'
import qaChart from '@/assets/figma/icons/qa-bar-chart.svg'
import qaFile from '@/assets/figma/icons/qa-file-text.svg'
import qaSettings from '@/assets/figma/icons/qa-settings.svg'
import qaArrow from '@/assets/figma/icons/qa-arrow-right.svg'

import wsBriefcase from '@/assets/figma/profile/briefcase.svg'
import wsArrowUpRight from '@/assets/figma/profile/arrow-up-right.svg'
import wsFolderPlus from '@/assets/figma/profile/folder-plus.svg'
import wsAward from '@/assets/figma/profile/award.svg'
import wsShieldAlert from '@/assets/figma/profile/shield-alert.svg'
import wsStepDone from '@/assets/figma/profile/step-done.svg'
import wsStepActive from '@/assets/figma/profile/step-active.svg'
import wsStepTodo from '@/assets/figma/profile/step-todo.svg'
import wsAdArrow from '@/assets/figma/profile/ad-arrow.svg'
import wsAdSpace from '@/assets/figma/profile/ad-space.jpg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t, td } = useI18n()
const { entityTerm } = useEntityTerm()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

defineOptions({ layout: AppLayout })

/*
 * Profile dashboard — Figma 34:4 (1280x3058).
 *   Interactive_Hero_Section 34:28 (273)  — identity, stats, actions
 *   Quick_Actions_Section    34:66 (176)  — 4 portal action cards
 *   Workspace_Split_Layout   34:105 (1158)
 *   Grow_Your_Business_CTA   34:206 (314)
 *   benefits 52:1934
 *
 * Prototype wiring from this screen: Action_Edit -> settings profile tab,
 * Go_To_Listings_Btn -> business profile, SBO_Seal/Action_Support -> sbo
 * dashboard. Copy read from the design's text nodes.
 */

const props = defineProps<{
  user: {
    name: string
    email: string
    phone: string | null
    emailVerified: boolean
    /** Uploaded photo; null falls back to the Figma stock avatar. */
    photoUrl: string | null
    /** KingsChat handle, shown under the name when the member has one. */
    handle: string | null
  }
  stats: { listings: number; verified: number; pending: number; views: number }
  listings: {
    name: string
    slug: string
    category: string | null
    /** Fully verified: approved, documents compliant, fee settled. */
    verified: boolean
    /** The raw state, which the tabs filter on. Never displayed as-is. */
    status: string
    address: string
    logoUrl: string | null
    /** 'SBO' | 'COB' — colours the animated logo ring. */
    entityType: string | null
    href: string
    manageUrl: string
  }[]
  /** SBO_Seal_Container 34:136 — see DashboardController::sealPanel. */
  seal: {
    issued: boolean
    status: 'provisional' | 'verified' | null
    sboName: string | null
    checklist: { label: string; done: boolean }[]
    imageUrl: string | null
    certificateUrl: string | null
    certificatePreviewUrl: string | null
    number: string | null
    issuedOn: string | null
  }
}>()

/*
 * The hero badge told EVERY member "Verified" regardless of state, so a member
 * still awaiting review read as approved. It follows the real state now: verified
 * only when a business is fully verified, otherwise "Awaiting Verification", and
 * nothing at all before they have added a business.
 */
const heroVerified = computed(() => props.seal.status === 'verified' || props.stats.verified > 0)

// Were fixed zeros, and the header named a fictional Kenneth James.
const stats = computed(() => [
  { label: t('profile_dashboard.active_listings'), value: String(props.stats.listings) },
  { label: t('profile_dashboard.views_30d'), value: String(props.stats.views) },
])

// Quick_Actions_Grid 34:68 — each card has its own icon and tile colour
const quickActions = [
  { title: t('profile_dashboard.create_listing'), body: t('profile_dashboard.list_a_new_loveworld_business'), to: '/sbo/listings/new', icon: qaPlus, tile: 'bg-[#ccfbf1]' },
  // Both pointed at /dashboard — the profile this card sits on — so neither
  // card did anything.
  { title: t('profile_dashboard.view_analytics'), body: t('profile_dashboard.track_views_clicks_and_leads'), to: '/account/analytics', icon: qaChart, tile: 'bg-[#fef3c7]' },
  { title: t('profile_dashboard.manage_documents'), body: t('profile_dashboard.upload_validation_certificates'), to: '/account/documents', icon: qaFile, tile: 'bg-[#e0f2fe]' },
  { title: t('profile_dashboard.account_settings'), body: t('profile_dashboard.preferences_and_notifications'), to: '/settings/profile', icon: qaSettings, tile: 'bg-[#f1f5f9]' },
]

/*
 * Tab_Filters 34:119.
 *
 * The tabs counted correctly and filtered nothing: `listingTab` was set by the
 * click and read by no one, and the empty state below rendered unconditionally
 * — so an owner with two listings saw "All (2)" above "No listings found".
 *
 * Keyed rather than indexed. The old version compared against a position in an
 * array of translated strings, which is two ways to break: reorder the tabs and
 * the filter follows the wrong one; translate them and the label no longer
 * matches what the filter expects.
 *
 * The "Drafts (0)" tab is gone. There is no draft state on a listing — the
 * states are pending, approved, declined and info_requested — so it was a tab
 * that could only ever read zero.
 */
type ListingTab = 'all' | 'verified' | 'pending'

const listingTab = ref<ListingTab>('all')

const listingTabs = computed(() => [
  { key: 'all' as const, label: t('profile_dashboard.tab_all'), count: props.stats.listings },
  { key: 'verified' as const, label: t('data.status.verified'), count: props.stats.verified },
  { key: 'pending' as const, label: t('profile_dashboard.tab_pending'), count: props.stats.pending },
])

const visibleListings = computed(() =>
  props.listings.filter((listing) => {
    if (listingTab.value === 'verified') return listing.verified
    if (listingTab.value === 'pending') return !listing.verified

    return true
  }),
)

/** The pill on a card. Translated here so the filter above stays on the key. */
const STATUS_LABELS: Record<string, string> = {
  approved: t('data.status.approved'),
  pending: t('data.status.pending_review'),
  declined: t('data.status.declined'),
  info_requested: t('data.status.info_requested'),
}

const STATUS_TONES: Record<string, string> = {
  approved: 'bg-success/12 text-success',
  declined: 'bg-danger/12 text-danger',
  info_requested: 'bg-info/12 text-info',
}

/*
 * What the empty state says depends on WHY it is empty. "Register your
 * business" is wrong advice for someone who has two listings and is looking at
 * the Verified tab.
 */
const emptyCopy = computed(() => {
  if (props.listings.length === 0) {
    return {
      title: t('profile_dashboard.no_listings_found'),
      body: t('profile_dashboard.you_haven_t_listed_any_businesses_yet_regi'),
      showRegister: true,
    }
  }

  return {
    title:
      listingTab.value === 'verified'
        ? t('profile_dashboard.none_verified_yet')
        : t('profile_dashboard.none_pending'),
    body:
      listingTab.value === 'verified'
        ? t('profile_dashboard.none_verified_yet_body')
        : t('profile_dashboard.none_pending_body'),
    showRegister: false,
  }
})

// Progress_Steps 34:154 — done / active / todo each carry their own glyph
/*
 * The checklist was three fixed rows with fixed icons. It is the real state of
 * the application behind the seal now; the glyphs still come from the design.
 */
const sealChecklist = computed(() =>
  props.seal.checklist.map((c) => ({
    ...c,
    icon: c.done ? wsStepDone : wsStepTodo,
    cls: c.done ? 'text-[#0f172a]' : 'text-[#94a3b8]',
  })),
)

</script>

<template>
  <!-- ============================= Interactive_Hero_Section 34:28 =========
       96px avatar ringed #0d9488, 32px serif name, #ecfdf5 verified pill, and
       two 160px metric cards on white/6 with a white/8 border. -->
  <section class="relative overflow-hidden bg-[#0b192c]">
    <HeroVideo overlay="bg-[#0b192c]/72" />

    <div class="relative flex flex-col gap-8 section-x pt-12 pb-16">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('profile_dashboard.breadcrumb')">
        <RouterLink href="/account" class="text-[#94a3b8] hover:text-white">{{ t('profile_dashboard.account') }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">/</span>
        <span class="text-white">{{ t('profile_dashboard.profile_dashboard') }}</span>
      </nav>

      <div class="flex flex-col gap-10 lg:flex-row lg:items-center">
        <div class="flex flex-1 flex-wrap items-center gap-6">
          <!-- The default face rather than initials: this hero is drawn around
               a photograph, and two letters in a 96px disc read as an empty
               state rather than a portrait. -->
          <img
            :src="user.photoUrl ?? DEFAULT_AVATAR"
            :alt="user.name"
            class="size-24 shrink-0 rounded-full border-[3px] border-[#0d9488] object-cover"
          />

          <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-3">
              <h1 class="font-serif text-3xl font-bold text-white">{{ user.name }}</h1>
              <span v-if="user.handle" class="text-sm text-[#94a3b8]">{{ user.handle }}</span>
              <span
                v-if="heroVerified"
                class="inline-flex items-center gap-1 rounded-full bg-[#ecfdf5] px-2.5 py-1"
              >
                <img :src="icVerified" alt="" class="size-3" aria-hidden="true" />
                <span class="text-2xs font-semibold text-[#007035]">{{ t('profile_dashboard.verified_sbo', { entity: entityTerm }) }}</span>
              </span>
              <span
                v-else-if="props.stats.listings > 0"
                class="inline-flex items-center gap-1 rounded-full bg-[#fef3c7] px-2.5 py-1"
              >
                <span class="text-2xs font-semibold text-[#b45309]">{{ t('data.status.awaiting_verification') }}</span>
              </span>
            </div>
            <p class="text-base text-[#94a3b8]">{{ user.email }}</p>

            <div class="mt-1 flex flex-wrap gap-3">
              <!-- Action_Support 34:51 -> 262:9642 sbo-dashboard-redesign -->
              <RouterLink
                href="/sbo/dashboard"
                class="inline-flex items-center gap-2 rounded-md border border-white/10 bg-white/[0.08] px-4 py-2 text-xs font-semibold text-white hover:bg-white/15"
              >
                <img :src="icLayout" alt="" class="size-[18px]" aria-hidden="true" />
                {{ t('profile_dashboard.view_dashboard') }}
              </RouterLink>
              <RouterLink
                href="/settings/profile"
                class="btn-primary inline-flex items-center gap-2 rounded-md px-4 py-2 text-xs font-semibold"
              >
                <img :src="icEdit" alt="" class="size-3.5" aria-hidden="true" />
                {{ t('profile_dashboard.edit_profile') }}
              </RouterLink>
              <RouterLink
                href="/settings/security"
                class="inline-flex items-center gap-2 rounded-md border border-white/10 bg-white/[0.08] px-4 py-2 text-xs font-semibold text-white hover:bg-white/15"
              >
                <img :src="icShield" alt="" class="size-3.5" aria-hidden="true" />
                {{ t('profile_dashboard.security') }}
              </RouterLink>
            </div>
          </div>
        </div>

        <dl class="flex shrink-0 gap-6">
          <div
            v-for="s in stats"
            :key="s.label"
            class="flex w-40 flex-col gap-1 rounded-2xl border border-white/[0.08] bg-white/[0.06] p-6"
          >
            <dd class="font-serif text-4xl font-bold text-white">{{ s.value }}</dd>
            <dt class="text-xs text-[#94a3b8]">{{ s.label }}</dt>
          </div>
        </dl>
      </div>
    </div>
  </section>

  <!-- ============================== Quick_Actions_Section 34:66 ===========
       Horizontal cards: 48px coloured icon tile, title + subtitle, trailing
       arrow — not the stacked cards used before. -->
  <section class="bg-white section-x pt-8 pb-4">
    <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('profile_dashboard.quick_portal_actions') }}</h2>
    <ul class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4 [&>*]:min-w-0">
      <li v-for="a in quickActions" :key="a.title">
        <RouterLink
          :href="a.to"
          class="flex h-full items-center gap-3 rounded-2xl border border-[#e2e8f0] bg-white py-5 pr-5 pl-3 shadow-[0px_4px_6px_rgba(0,0,0,0.02)] hover:shadow-sm"
        >
          <span class="grid size-12 shrink-0 place-items-center rounded-xl" :class="a.tile">
            <img :src="a.icon" alt="" class="size-6" aria-hidden="true" />
          </span>
          <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="font-display text-base font-semibold text-[#0f172a]">{{ a.title }}</span>
            <span class="text-xs text-[#475569]">{{ a.body }}</span>
          </span>
          <img :src="qaArrow" alt="" class="size-4 shrink-0" aria-hidden="true" />
        </RouterLink>
      </li>
    </ul>
  </section>

  <!-- ============================ Workspace_Split_Layout 34:105 ===========
       Fluid left column beside a fixed 380px sidebar, gap 32, pad 24/80/48. -->
  <div class="flex flex-col gap-8 bg-surface-secondary section-x pt-6 pb-12 lg:flex-row">
    <div class="flex min-w-0 flex-1 flex-col gap-8">
      <!-- Business_Listings_Box 34:107 -->
      <section
        class="flex flex-col gap-6 rounded-3xl border border-[#e2e8f0] bg-white p-5 sm:p-8 drop-shadow-[0px_8px_12px_rgba(0,0,0,0.02)]"
      >
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="grid h-8 w-9 shrink-0 place-items-center rounded-lg bg-[#ccfbf1]">
              <img :src="wsBriefcase" alt="" class="size-4" aria-hidden="true" />
            </span>
            <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('profile_dashboard.business_listings') }}</h2>
          </div>
          <RouterLink
            href="/account"
            class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-[#ccfbf1] px-4 py-2 text-xs font-semibold text-[#0d9488]"
          >
            {{ t('profile_dashboard.go_to_listings') }}
            <img :src="wsArrowUpRight" alt="" class="size-3.5" aria-hidden="true" />
          </RouterLink>
        </div>

        <p class="text-sm leading-6 text-[#475569]">
          {{ t('profile_dashboard.your_listings_now_live_in_their_own_worksp') }}
        </p>

        <!-- Tab_Filters 34:119 -->
        <div class="flex flex-wrap gap-2 rounded-[10px] bg-[#f4f7f9] p-1">
          <button
            v-for="tab in listingTabs"
            :key="tab.key"
            type="button"
            class="rounded-lg px-4 py-2 text-xs whitespace-nowrap"
            :class="
              tab.key === listingTab
                ? 'bg-white font-semibold text-[#0f172a] drop-shadow-[0px_2px_2px_rgba(0,0,0,0.02)]'
                : 'font-medium text-[#475569] hover:text-[#0f172a]'
            "
            :aria-pressed="tab.key === listingTab"
            @click="listingTab = tab.key"
          >
            {{ tab.label }} ({{ tab.count }})
          </button>
        </div>

        <!-- The listings themselves. This is what the tabs above count. -->
        <ul v-if="visibleListings.length" class="flex flex-col gap-3">
          <!--
            The Manage button drops to its own row when the name has nowhere to
            go. Driven by the middle column's basis rather than a breakpoint:
            this list sits in a column that is already narrow at desktop widths,
            so a viewport query answers the wrong question — 800px of window can
            still mean 300px of row. Below `basis-48` there is no room for three
            columns and the button wraps, whatever the window is doing.
          -->
          <li
            v-for="listing in visibleListings"
            :key="listing.slug"
            class="flex flex-wrap items-center gap-3 rounded-2xl border border-[#e2e8f0] bg-white p-4 sm:gap-4"
          >
            <EntityLogoRing :entity="listing.entityType" radius="0.85rem" class="shrink-0">
              <img
                v-if="listing.logoUrl"
                :src="listing.logoUrl"
                alt=""
                class="size-12 rounded-xl border border-[#e2e8f0] object-cover"
              />
              <span
                v-else
                class="grid size-12 place-items-center rounded-xl bg-[#001529] font-display text-base font-semibold text-white"
                aria-hidden="true"
              >{{ listing.name.charAt(0) }}</span>
            </EntityLogoRing>

            <span class="min-w-0 flex-1 basis-48">
              <span class="flex flex-wrap items-center gap-2">
                <RouterLink
                  :href="listing.href"
                  class="font-display text-base font-semibold break-words text-[#0f172a] hover:underline"
                >
                  {{ listing.name }}
                </RouterLink>
                <span
                  class="rounded-full px-2 py-0.5 text-2xs font-semibold"
                  :class="STATUS_TONES[listing.status] ?? 'bg-warning/12 text-warning'"
                >
                  {{ listing.verified ? t('data.status.verified') : (STATUS_LABELS[listing.status] ?? listing.status) }}
                </span>
              </span>
              <span v-if="listing.category" class="mt-0.5 block text-xs text-[#475569]">
                {{ td(listing.category, 'category') }}
              </span>
              <span v-if="listing.address" class="block text-2xs break-words text-[#94a3b8]">{{ listing.address }}</span>
            </span>

            <!-- Full width and 44px tall on a phone, where it is a thumb
                 target rather than a cursor one. -->
            <RouterLink
              :href="listing.manageUrl"
              class="w-full shrink-0 rounded-lg border border-[#e2e8f0] px-4 py-3 text-center text-xs font-semibold text-[#0f172a] hover:bg-surface-secondary sm:w-auto sm:py-2"
            >
              {{ t('profile_dashboard.manage') }}
            </RouterLink>
          </li>
        </ul>

        <!--
          Empty_Listings_State 34:128. Shown only when the CURRENT tab has
          nothing, and it says why — "Register your business" is wrong advice
          for someone with two listings looking at the Verified tab.
        -->
        <div
          v-else
          class="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-[#e2e8f0] bg-[#f4f7f9] px-6 py-10"
        >
          <img :src="wsFolderPlus" alt="" class="size-9" aria-hidden="true" />
          <div class="flex flex-col items-center gap-1 text-center">
            <p class="font-display text-base font-semibold text-[#0f172a]">{{ emptyCopy.title }}</p>
            <p class="text-xs text-[#475569]">{{ emptyCopy.body }}</p>
          </div>
          <RouterLink
            v-if="emptyCopy.showRegister"
            :href="registerUrl"
            class="rounded-lg bg-[#0d9488] px-5 py-2.5 text-xs font-semibold text-white transition-opacity hover:opacity-90"
          >
            {{ t('profile_dashboard.register_your_business') }}
          </RouterLink>
        </div>
      </section>

      <!-- SBO_Seal_Container 34:136 -->
      <section
        class="flex flex-col gap-6 rounded-3xl border border-border bg-white p-5 sm:p-8 drop-shadow-[0px_8px_12px_rgba(0,0,0,0.02)]"
      >
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="grid h-8 w-9 shrink-0 place-items-center rounded-lg bg-warning/12">
              <img :src="wsAward" alt="" class="size-4" aria-hidden="true" />
            </span>
            <div>
              <p class="font-display text-lg font-semibold text-ink">{{ t('profile_dashboard.digital_sbo_seal', { entity: entityTerm }) }}</p>
              <p class="text-xs text-ink-secondary">{{ t('profile_dashboard.official_validation_personalized_seal') }}</p>
            </div>
          </div>
          <span
            class="shrink-0 rounded-full px-3 py-1.5 text-2xs font-semibold"
            :class="
              props.seal.status === 'verified'
                ? 'bg-success/12 text-success'
                : props.seal.status === 'provisional'
                  ? 'bg-warning/12 text-warning'
                  : 'bg-surface-tertiary text-ink-secondary'
            "
          >
            {{
              props.seal.status === 'verified'
                ? 'Seal issued'
                : props.seal.status === 'provisional'
                  ? 'Provisional seal'
                  : 'No seal issued yet'
            }}
          </span>
        </div>

        <!-- Issued: the badge itself, and the certificate to download. -->
        <template v-if="props.seal.issued">
          <div
            class="flex flex-col items-center gap-4 rounded-2xl border border-border bg-surface-secondary p-6 sm:flex-row sm:items-center"
          >
            <img
              v-if="props.seal.imageUrl"
              :src="props.seal.imageUrl"
              :alt="t('seal.alt_for', { name: props.seal.sboName })"
              class="size-[120px] shrink-0 object-contain"
            />
            <div class="min-w-0 flex-1 text-center sm:text-left">
              <p class="font-display text-base font-semibold text-ink">
                {{ props.seal.sboName }}
              </p>
              <p class="text-xs text-ink-secondary">
                {{ props.seal.number }}
                <template v-if="props.seal.issuedOn"> {{ t('seal.issued_on', { date: props.seal.issuedOn }) }}</template>
              </p>
              <p
                v-if="props.seal.status === 'provisional'"
                class="mt-2 text-xs text-warning"
              >
                {{ t('profile_dashboard.your_fee_is_settled_so_the_seal_is_yours_i') }}
              </p>
            </div>
          </div>

          <!--
            The preview belongs here as much as on the application-progress
            panel. It was added to that one only, so the page most members
            actually visit offered nothing but a download.
          -->
          <div class="flex flex-wrap gap-3">
            <SboCertificatePreview
              :preview-url="props.seal.certificatePreviewUrl"
              :download-url="props.seal.certificateUrl"
              :title="props.seal.sboName"
            />
            <a
              v-if="props.seal.certificateUrl"
              :href="props.seal.certificateUrl"
              class="btn-primary rounded-lg px-5 py-2.5 text-xs font-semibold"
            >
              {{ t('profile_dashboard.download_certificate_pdf') }}
            </a>
          </div>
        </template>

        <!-- Not issued yet: what is outstanding, and where to go. -->
        <template v-else>
          <!-- Validation_Notice_Box 34:147 -->
          <div class="flex items-center gap-4 rounded-2xl border border-border bg-surface-secondary p-5">
            <span
              class="grid size-10 shrink-0 place-items-center rounded-[20px] border border-border bg-white"
            >
              <img :src="wsShieldAlert" alt="" class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-ink">{{ t('profile_dashboard.pending_validation_completion') }}</p>
              <p class="text-xs text-ink-secondary">
                {{ t('profile_dashboard.your_seal_is_issued_as_soon_as_your_regist') }}
              </p>
            </div>
          </div>
        </template>

        <!-- Progress_Steps 34:154 — the real checklist for this application. -->
        <div v-if="sealChecklist.length" class="flex flex-col gap-3">
          <p class="text-xs font-semibold text-ink-secondary uppercase">{{ t('profile_dashboard.validation_checklist') }}</p>
          <div class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4 [&>*]:min-w-0">
            <span v-for="c in sealChecklist" :key="c.label" class="flex min-w-0 items-center gap-2">
              <img :src="c.icon" alt="" class="size-4 shrink-0" aria-hidden="true" />
              <span class="text-xs" :class="c.cls">{{ c.label }}</span>
            </span>
          </div>
        </div>

        <!-- Complete_Validation_CTA 34:167 -->
        <div
          v-if="props.seal.status !== 'verified'"
          class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-warning/12 p-4"
        >
          <p class="text-xs font-semibold text-warning">
            {{ t('profile_dashboard.fast_track_your_validation_amp_receive_you') }}
          </p>
          <RouterLink
            href="/application/fees"
            class="btn-primary shrink-0 rounded-lg px-4 py-2 text-xs font-semibold"
          >
            {{ t('profile_dashboard.complete_validation') }}
          </RouterLink>
        </div>
      </section>
    </div>

    <!-- Right_Sidebar_Column 34:171 -->
    <aside class="flex w-full shrink-0 flex-col gap-8 lg:w-[380px]">
      <!-- Reach_Verified_Promo 34:172 -->
      <section
        class="flex flex-col gap-4 rounded-[20px] border border-[#e2e8f0] bg-white p-6 drop-shadow-[0px_8px_10px_rgba(0,0,0,0.02)]"
      >
        <div class="flex flex-col gap-1">
          <p class="text-2xs font-semibold text-[#0d9488] uppercase">{{ t('profile_dashboard.advertisement_space') }}</p>
          <p class="font-display text-base font-semibold text-[#0f172a]">{{ t('profile_dashboard.reach_verified_businesses') }}</p>
        </div>
        <p class="text-xs text-[#475569]">
          {{ t('profile_dashboard.connect_with_over_5_000_verified_loveworld') }}
        </p>
        <RouterLink
          href="/advertise"
          class="flex h-10 items-center justify-center rounded-lg bg-[#0b192c] text-xs font-semibold text-white"
        >
          {{ t('profile_dashboard.advertise_with_us') }}
        </RouterLink>
      </section>

      <!-- Live Opportunities 387:21704 — shared with /notifications -->
      <LiveOpportunities />

      <!-- MFI_Affiliate_Promo 34:198 -->
      <section
        class="relative flex flex-col gap-4 overflow-hidden rounded-[20px] bg-[#111e36] p-6"
      >
        <span
          class="pointer-events-none absolute -right-[30px] -bottom-[30px] size-[120px] rounded-full bg-[#0d9488] opacity-20 blur-2xl"
          aria-hidden="true"
        />
        <div class="relative flex flex-col gap-1">
          <p class="font-display text-base font-semibold text-white">{{ t('profile_dashboard.become_an_affiliate_mfi') }}</p>
          <p class="text-xs text-[#94a3b8]">{{ t('profile_dashboard.support_local_trade_and_scale_up') }}</p>
        </div>
        <p class="relative text-xs text-[#d1d5db]">
          {{ t('profile_dashboard.access_structural_credit_and_microfinance') }}
        </p>
        <RouterLink
          href="/support"
          class="relative w-fit rounded-lg bg-[#0d9488] px-4 py-2 text-xs font-semibold text-white"
        >
          {{ t('profile_dashboard.get_started') }}
        </RouterLink>
      </section>

      <!-- Ad slot 299:16333 -->
      <RouterLink href="/advertise" class="relative block h-[271px] overflow-hidden rounded-xl">
        <img
          :src="wsAdSpace"
          alt=""
          class="absolute inset-0 size-full object-cover"
          aria-hidden="true"
        />
        <span class="absolute inset-0 bg-black/20" aria-hidden="true" />
        <span
          class="absolute inset-0 bg-linear-to-b from-black/50 to-[#000f2a]/50"
          aria-hidden="true"
        />
        <span class="relative flex h-full min-h-[240px] flex-col items-center gap-2 px-5 py-6 text-center">
          <span class="text-2xs text-[#d1d5db]">{{ t('profile_dashboard.advertisement_space') }}</span>
          <span class="max-w-[232px] font-display text-xl leading-6 font-semibold text-white">
            {{ t('profile_dashboard.reach_verified_loveworld_businesses') }}
          </span>
          <span
            class="mt-auto inline-flex h-8 w-full max-w-[200px] items-center justify-center gap-1.5 rounded bg-white px-3 text-2xs font-semibold text-[#1e3a8a]"
          >
            {{ t('profile_dashboard.advertise_your_business') }}
            <img :src="wsAdArrow" alt="" class="h-2.5 w-2 shrink-0" aria-hidden="true" />
          </span>
        </span>
      </RouterLink>
    </aside>
  </div>

  <!-- ===================== Grow_Your_Business_CTA_Section 34:206 ========== -->
  <section class="bg-[#001529] px-5 py-8 sm:py-12 text-center md:px-10 xl:px-20">
    <h2 class="font-serif text-3xl font-bold text-white">
      {{ t('profile_dashboard.grow_your_business_with_the_loveworld_netw') }}
    </h2>
    <p class="mx-auto mt-3 max-w-2xl text-xs text-white/70">
      {{ t('profile_dashboard.expose_your_products_and_services_to_thous') }}
    </p>
    <RouterLink
      :href="registerUrl"
      class="mt-6 inline-block rounded-md btn-primary px-6 py-3 text-xs font-semibold text-white "
    >
      {{ t('profile_dashboard.register_your_business') }}
    </RouterLink>
  </section>

  <BenefitsStrip />
</template>
