<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { Link as RouterLink, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'

import iconPlus from '@/assets/figma/account/plus.svg'
import iconStar from '@/assets/figma/account/star.svg'
import iconDot from '@/assets/figma/account/dot.svg'
import iconEdit from '@/assets/figma/account/edit.svg'
import iconShare from '@/assets/figma/account/share.svg'
import iconTrash from '@/assets/figma/account/trash.svg'
import iconActivityCheck from '@/assets/figma/account/activity-check.svg'
import iconActivityFile from '@/assets/figma/account/activity-file.svg'
import iconActivityStar from '@/assets/figma/account/activity-star.svg'
import iconBarChart from '@/assets/figma/account/bar-chart.svg'
import iconFileText from '@/assets/figma/account/file-text.svg'
import iconSettings from '@/assets/figma/account/settings.svg'
import iconArrowRight from '@/assets/figma/account/arrow-right.svg'
import listingThumb from '@/assets/figma/account/listing-thumb.jpg'
import promoPlate from '@/assets/figma/account/promo-plate.jpg'
import { useI18n } from '@/composables/useI18n'

const { t, tc } = useI18n()

/*
 * My Account — Figma 275:4 "my-account-dashboard-redesign" (1280x2407).
 *   Hero-Section 275:30 (239)             px-80 py-48, VIDEO fill, teal disc
 *   Content-Section 275:73 (331)          pill Tab-Bar + Listing-Card
 *   Recent-Activity-Section 275:207 (341) Activity-Feed, 36px icon boxes
 *   Quick-Actions-Section 275:169 (232)   3 cards, 48px Icon-Box + arrow
 *   Grow-Your-Business-Promo 275:199 (266)
 *
 * Every icon here is the exported Figma vector; the frame carries 23 of them
 * and none were wired up before.
 *
 * NOTE: this screen is orphaned in the prototype — nothing links in or out of
 * 275:4, and its relationship to /profile (34:4) is unresolved.
 */

defineOptions({ layout: AppLayout })

type Listing = {
  id: number
  kind: 'sbo' | 'business'
  name: string
  slug: string
  address: string
  verified: boolean
  views: number | null
  /** Null for a listing that is not the viewer's own. */
  manageUrl: string | null
  /** The owner's card artwork; null until uploaded. */
  coverUrl: string | null
  logoUrl: string | null
  publicUrl: string
  canDelete: boolean
}

type ActivityRow = { title: string; date: string; icon: 'check' | 'file' | 'star' }

const props = defineProps<{
  user: { name: string; email: string }
  listings: { verified: Listing[]; unverified: Listing[]; favorites: Listing[]; recent: Listing[] }
  /** Derived from real records — see AccountController::activity. */
  activity: ActivityRow[]
}>()

/*
 * Tab-Bar 275:75 — four pills inside a rounded rgba(226,232,240,0.25) track.
 * The counts were baked into the labels ("Unverified Listings (1)"); they now
 * come from the data, so they cannot drift from what the tab shows.
 */
const activeTab = ref(0)

const tabSets = computed(() => [
  { label: t('my_account.verified_listings'), rows: props.listings.verified },
  { label: t('my_account.unverified_listings'), rows: props.listings.unverified },
  { label: t('my_account.favorites'), rows: props.listings.favorites },
  { label: t('my_account.recently_viewed'), rows: props.listings.recent },
])

const tabs = computed(() => tabSets.value.map((t) => `${t.label} (${t.rows.length})`))

/* ------------------------------------------ Actions 275:103 --------------
 * All three icons were inert buttons. Edit and Delete only apply to the
 * owner's own listings, so they are hidden on Favourites and Recently Viewed.
 */

/** Share: copy the public profile link, with a short confirmation. */
const copiedId = ref<number | null>(null)
let copyTimer: ReturnType<typeof setTimeout> | undefined

async function share(listing: Listing) {
  const url = new URL(listing.publicUrl, window.location.origin).toString()

  try {
    // The Web Share sheet where the browser has one (mobile); clipboard
    // everywhere else. Both are "share" — neither needs a backend.
    if (navigator.share) {
      await navigator.share({ title: listing.name, url })
      return
    }
    await navigator.clipboard.writeText(url)
  } catch {
    return
  }

  copiedId.value = listing.id
  clearTimeout(copyTimer)
  copyTimer = setTimeout(() => (copiedId.value = null), 2000)
}

onBeforeUnmount(() => clearTimeout(copyTimer))

/*
 * Delete: two-step rather than a native confirm(), so the consequence is
 * readable and a mis-click cannot destroy a listing.
 */
const confirmingId = ref<number | null>(null)
const remove = useForm({})

function askDelete(listing: Listing) {
  confirmingId.value = confirmingId.value === listing.id ? null : listing.id
}

function confirmDelete(listing: Listing) {
  remove.delete(`/sbo/listings/${listing.id}`, {
    preserveScroll: true,
    onFinish: () => (confirmingId.value = null),
  })
}
const visibleListings = computed(() => tabSets.value[activeTab.value].rows)

/*
 * Activity-Feed 275:209 — each row has its own tint and glyph. Was three
 * invented rows ("New review received: 5 stars from Faith Community"); the
 * server sends real events now and names which glyph each one wants.
 */
const ACTIVITY_ICONS: Record<string, { icon: string; tint: string }> = {
  check: { icon: iconActivityCheck, tint: 'rgba(16,185,129,0.08)' },
  file: { icon: iconActivityFile, tint: 'rgba(59,130,246,0.08)' },
  star: { icon: iconActivityStar, tint: 'rgba(245,158,11,0.08)' },
}

const activity = computed(() =>
  props.activity.map((a) => ({ ...a, ...(ACTIVITY_ICONS[a.icon] ?? ACTIVITY_ICONS.file) })),
)

// Quick-Actions-Grid 275:171
const quickActions = [
  {
    title: t('my_account.view_seo_analytics'),
    body: t('my_account.track_your_business_views_search_visibilit'),
    to: '/dashboard',
    icon: iconBarChart,
    tint: '#ccfbf1',
  },
  {
    title: t('my_account.manage_documents'),
    body: t('my_account.upload_organize_and_submit_your_regulatory'),
    to: '/dashboard',
    icon: iconFileText,
    tint: '#e0f2fe',
  },
  {
    title: t('my_account.account_settings'),
    body: t('my_account.update_your_personal_profile_email_setting'),
    to: '/settings/profile',
    icon: iconSettings,
    tint: '#f1f5f9',
  },
]
</script>

<template>
  <!-- ========================================= Hero-Section 275:30 ======== -->
  <section class="relative overflow-hidden bg-[#001529] section-x py-8 sm:py-12">
    <HeroVideo overlay="bg-[#001529]/72" />

    <!-- Ellipse 275:31 — 400px teal disc bleeding off the top-right corner -->
    <div
      class="pointer-events-none absolute -top-[100px] -right-[100px] size-[400px] rounded-full bg-[#2dd4a8] opacity-10 blur-[80px]"
      aria-hidden="true"
    />

    <div class="relative flex flex-col gap-6">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('my_account.breadcrumb')">
        <RouterLink href="/account" class="text-[#94a3b8] hover:text-white">{{ t('my_account.account') }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">/</span>
        <span class="font-medium text-white">{{ t('my_account.my_account') }}</span>
      </nav>

      <div class="flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex max-w-[720px] flex-col gap-2">
          <h1 class="font-serif text-4xl font-bold text-white">{{ t('my_account.my_account') }}</h1>
          <p class="text-base leading-normal text-[#94a3b8]">
            {{ t('my_account.manage_your_listings_favorites_and_grow_yo') }}
          </p>
        </div>

        <!-- New-Listing-CTA 275:40 -->
        <RouterLink
          href="/sbo/listings/new"
          class="btn-primary inline-flex shrink-0 items-center gap-2 rounded-lg px-6 py-3 text-sm font-semibold whitespace-nowrap"
        >
          <img :src="iconPlus" alt="" class="size-3.5" aria-hidden="true" />
          {{ t('my_account.new_listing') }}
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ====================================== Content-Section 275:73 ======== -->
  <section class="flex flex-col gap-6 bg-white section-x pt-10 pb-5">
    <h2 class="font-serif text-3xl font-bold text-[#0f172a]">{{ t('my_account.your_listings') }}</h2>

    <!-- Tab-Bar 275:75 -->
    <div class="flex flex-wrap gap-2 rounded-[10px] bg-[#e2e8f0]/25 p-1">
      <button
        v-for="(t, i) in tabs"
        :key="t"
        type="button"
        class="rounded-lg px-4 py-2 text-xs whitespace-nowrap transition-colors"
        :class="
          i === activeTab
            ? 'bg-white font-semibold text-[#0d9488] drop-shadow-[0px_2px_2px_rgba(0,0,0,0.03)]'
            : 'font-medium text-[#475569] hover:text-[#0f172a]'
        "
        :aria-pressed="i === activeTab"
        @click="activeTab = i"
      >
        {{ t }}
      </button>
    </div>

    <!-- Listing-Card-1 275:85, repeated per listing. -->
    <article
      v-for="l in visibleListings"
      :key="l.slug"
      class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-6 sm:flex-row sm:items-center"
    >
      <!-- Every listing shared one stock thumbnail before. -->
      <img
        :src="l.coverUrl ?? l.logoUrl ?? listingThumb"
        alt=""
        class="size-[100px] shrink-0 rounded-xl object-cover"
        aria-hidden="true"
      />

      <div class="flex min-w-0 flex-1 flex-col gap-2">
        <div class="flex flex-wrap items-center gap-3">
          <h3 class="font-display text-xl font-semibold text-[#0f172a]">{{ l.name }}</h3>
          <span
            class="rounded-full px-2.5 py-1 text-2xs font-semibold"
            :class="l.verified ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef3c7] text-[#92400e]'"
          >
            {{ l.verified ? t('common.verified') : t('common.pending') }}
          </span>
        </div>

        <p class="text-xs text-[#475569]">{{ l.address || t('my_account.no_address_on_file') }}</p>

        <!-- Rating-Views-Row 275:93 -->
        <div class="flex flex-wrap items-center gap-4">
          <span class="flex items-center gap-0.5" :aria-label="t('my_account.5_out_of_5_stars')">
            <img v-for="n in 5" :key="n" :src="iconStar" alt="" class="size-3.5" aria-hidden="true" />
          </span>
          <!-- Reviews are not modelled; views exist only on legacy Business
               rows, so the counter is omitted rather than shown as zero. -->
          <template v-if="l.views !== null">
            <img :src="iconDot" alt="" class="size-1" aria-hidden="true" />
            <span class="text-xs text-[#0d9488]">{{ tc('my_account.view_count', l.views) }}</span>
          </template>
        </div>
      </div>

      <!-- Actions 275:103 -->
      <div class="flex shrink-0 flex-col items-end gap-2">
        <div class="flex items-start gap-2">
          <!-- The pencil used to open the application screen, because no SBO
               edit form existed. It edits the listing now; the application
               screen keeps its own link below, since tracking verification
               and editing a profile are different jobs.

               Still gated on `manageUrl`, which is null unless the listing is
               the viewer's own: these same cards render in Favorites and
               Recently Viewed, and an edit pencil on someone else's business
               would only ever lead to a 404. -->
          <RouterLink
            v-if="l.manageUrl"
            :href="`/sbo/listings/${l.id}/edit`"
            class="rounded-lg bg-[#f1f5f9] p-2.5 transition-colors hover:bg-[#e2e8f0]"
            :aria-label="t('action.edit_named', { name: l.name })"
            :title="t('my_account.edit_listing')"
          >
            <img :src="iconEdit" alt="" class="size-[13px]" aria-hidden="true" />
          </RouterLink>

          <button
            type="button"
            class="rounded-lg bg-[#f1f5f9] p-2.5 transition-colors hover:bg-[#e2e8f0]"
            :aria-label="t('action.share_named', { name: l.name })"
            :title="t('my_account.copy_the_public_profile_link')"
            @click="share(l)"
          >
            <img :src="iconShare" alt="" class="size-[13px]" aria-hidden="true" />
          </button>

          <button
            v-if="l.canDelete"
            type="button"
            class="rounded-lg bg-[#fff1f2] p-2.5 transition-colors hover:bg-[#ffe4e6]"
            :aria-label="t('action.delete_named', { name: l.name })"
            :title="t('my_account.delete_listing')"
            @click="askDelete(l)"
          >
            <img :src="iconTrash" alt="" class="size-[13px]" aria-hidden="true" />
          </button>
        </div>

        <RouterLink
          v-if="l.manageUrl"
          :href="l.manageUrl"
          class="text-2xs font-semibold text-ink-secondary hover:text-ink hover:underline"
        >
          {{ t('my_account.view_application') }}
        </RouterLink>

        <span v-if="copiedId === l.id" class="text-2xs font-semibold text-[#0d9488]">
          {{ t('my_account.link_copied') }}
        </span>

        <!-- Two-step delete: destructive and irreversible, so it asks. -->
        <div
          v-if="confirmingId === l.id"
          class="flex items-center gap-2 rounded-lg border border-[#fecaca] bg-[#fef2f2] px-3 py-2"
        >
          <span class="text-2xs text-[#991b1b]">{{ t('my_account.delete_permanently') }}</span>
          <button
            type="button"
            :disabled="remove.processing"
            class="rounded bg-[#991b1b] px-2 py-1 text-2xs font-semibold text-white disabled:opacity-60"
            @click="confirmDelete(l)"
          >
            {{ remove.processing ? t('my_account.deleting') : t('my_account.delete') }}
          </button>
          <button
            type="button"
            class="text-2xs font-semibold text-[#475569]"
            @click="confirmingId = null"
          >
            {{ t('my_account.cancel') }}
          </button>
        </div>
      </div>
    </article>

    <p
      v-if="!visibleListings.length"
      class="rounded-2xl border border-dashed border-[#e2e8f0] bg-white px-6 py-8 sm:py-12 text-center text-xs text-[#94a3b8]"
    >
      {{ t('my_account.nothing_in_tab', { tab: tabSets[activeTab].label }) }}
    </p>
  </section>

  <!-- ============================== Recent-Activity-Section 275:207 ======= -->
  <section class="flex flex-col gap-6 bg-white section-x pt-5 pb-16">
    <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('my_account.recent_activity') }}</h2>

    <!-- Activity-Feed 275:209 -->
    <ul class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
      <li v-for="(a, i) in activity" :key="i" class="flex items-center gap-4">
        <span
          class="grid size-9 shrink-0 place-items-center rounded-full"
          :style="{ backgroundColor: a.tint }"
          aria-hidden="true"
        >
          <img :src="a.icon" alt="" class="size-3.5" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="text-sm font-medium text-[#0f172a]">{{ a.title }}</span>
          <span class="text-xs text-[#475569]">{{ a.date }}</span>
        </span>
      </li>

      <li v-if="!activity.length" class="text-xs text-[#94a3b8]">
        {{ t('my_account.nothing_yet_registering_a_listing_paying_y') }}
      </li>
    </ul>
  </section>

  <!-- ================================ Quick-Actions-Section 275:169 ======= -->
  <section class="flex flex-col gap-5 bg-white section-x pt-5 pb-10">
    <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('my_account.quick_portal_actions') }}</h2>

    <ul class="grid gap-5 md:grid-cols-3 [&>*]:min-w-0">
      <li v-for="q in quickActions" :key="q.title">
        <RouterLink
          :href="q.to"
          class="flex h-full items-center gap-4 rounded-xl border border-[#e2e8f0] bg-white p-6 transition-shadow hover:shadow-sm"
        >
          <span
            class="grid size-12 shrink-0 place-items-center rounded-xl"
            :style="{ backgroundColor: q.tint }"
            aria-hidden="true"
          >
            <img :src="q.icon" alt="" class="size-[19px]" />
          </span>
          <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-base font-semibold text-[#0f172a]">{{ q.title }}</span>
            <span class="text-xs text-[#475569]">{{ q.body }}</span>
          </span>
          <img :src="iconArrowRight" alt="" class="size-[13px] shrink-0" aria-hidden="true" />
        </RouterLink>
      </li>
    </ul>
  </section>

  <!-- ============================ Grow-Your-Business-Promo 275:199 ======== -->
  <section class="bg-white section-x pb-16">
    <div
      class="relative flex flex-col items-start justify-between gap-6 overflow-hidden rounded-2xl p-5 sm:p-8 lg:p-10 lg:flex-row lg:items-center"
    >
      <img
        :src="promoPlate"
        alt=""
        class="absolute inset-0 size-full object-cover"
        aria-hidden="true"
      />
      <div class="absolute inset-0 bg-[#001529]/80" aria-hidden="true" />

      <div class="relative max-w-xl">
        <h2 class="font-serif text-2xl font-bold text-white">
          {{ t('my_account.reach_verified_loveworld_businesses') }}
        </h2>
        <p class="mt-2 text-sm text-white/70">
          {{ t('my_account.advertise_your_brand_to_thousands_of_faith') }}
        </p>
      </div>
      <RouterLink
        href="/advertise"
        class="btn-primary relative shrink-0 rounded-md px-6 py-3 text-sm font-semibold whitespace-nowrap"
      >
        {{ t('my_account.advertise_with_us') }}
      </RouterLink>
    </div>
  </section>
</template>
