<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import iconCheck from '@/assets/figma/icons/sbo-check.svg'
import iconPlus from '@/assets/figma/icons/sbo-plus.svg'
import iconTabFile from '@/assets/figma/icons/sbo-tab-file.svg'
import promoPlate from '@/assets/figma/raw/sbo-promo-plate.jpg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t, tc } = useI18n()
const { entityTerm } = useEntityTerm()

defineOptions({ layout: AppLayout })

/*
 * SBO Dashboard — Figma 262:9642 / 538:56013 (1280x2469).
 *
 * The two frames are the SAME screen with the tab bar toggled:
 *   262:9642  -> "My Procurement" active, action column reads "Review & Award"
 *   538:56013 -> "My Bids" active,       action column reads "View Details"
 * so both live here behind `activeTab`.
 *
 *   hero-section 509:49269 (339)           px-80, VIDEO fill + two blurred discs
 *   active-user-profile-bar 538:55979 (88) px-80, two icon tabs
 *   workspace-left 536:55873 (566)         RFQ table card, ~20px gutter
 *   pagination 538:56458                   OUTSIDE the card
 *   workspace-split 536:55792 (354)        promo banner, ~20px gutter
 *   Frames 509:49084 (139)                 benefits, full-bleed band
 *
 * 279:14175 is a third variant with no inbound prototype link.
 *
 * Gutters differ by band and that is deliberate in the design: the hero and
 * tab bar sit at 80px (`section-x`), the table card, pagination and promo
 * banner at ~20px (`container-page`).
 */

type Row = {
  id: string
  title: string
  type?: string
  category: string | null
  deadline: string | null
  visibility: string
  /** A stable state key ('active' | 'under_review' | 'closed'), for the pill's colour. */
  statusKey: string
  status: string
  bids: string
}

const props = defineProps<{
  user: { name: string }
  sbo: { name: string; slug: string; verified: boolean } | null
  tab: 'procurement' | 'bids'
  rows: {
    data: Row[]
    links: { url: string | null; label: string; active: boolean }[]
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null }
  }
  counts: { procurement: number; bids: number }
}>()

// ---------------------------------------------------------- tab bar 538:55979
const tabs = [
  { key: 'procurement', title: t('sbo_dashboard.my_procurement'), sub: t('sbo_dashboard.rfq_posted') },
  { key: 'bids', title: t('sbo_dashboard.my_bids'), sub: t('sbo_dashboard.rfq_following') },
] as const

/*
 * The tab is server state, not a local ref: the table under it is paginated,
 * and a client-side tab cannot carry a page number.
 */
const activeTab = computed(() => props.tab)

function openTab(key: (typeof tabs)[number]['key']) {
  if (key === props.tab) return
  router.get('/sbo/dashboard', { tab: key }, { preserveScroll: true, preserveState: false })
}

// timeline-card 536:55892 header + action label swap with the tab.
const tableTitle = computed(() =>
  activeTab.value === 'bids' ? t('sbo_dashboard.my_bids') : t('sbo_dashboard.my_procurement_requests_rfqs'),
)
const actionLabel = computed(() =>
  activeTab.value === 'bids' ? t('procurement_list.view_details') : t('sbo_dashboard.review_award'),
)

/*
 * Status_Badge palette, one per state (536:55913 / 536:55926 / 536:55939).
 * Pill is radius 100, px-10 py-4, Geist Bold 11px.
 */
const statusTone: Record<string, string> = {
  active: 'bg-[#d1fae5] text-[#065f46]',
  under_review: 'bg-[#fef3c7] text-[#b45309]',
  closed: 'bg-[#fee2e2] text-[#991b1b]',
}

/*
 * Real rows. The seven here were verbatim design copy — the same "Office
 * Renovation Logistics" five times over — so the table looked populated for
 * every owner regardless of what they had posted.
 */
const rfqs = computed(() =>
  props.rows.data.map((r) => ({ ...r, type: r.type ?? t('rfq.type.procurement') })),
)

/* Real pagination. The numbers 1, 2 … 15 and "Showing 1-8 of 114 businesses"
 * were fixed strings — on a table of RFQs, counted as businesses. */
const meta = computed(() => props.rows.meta)

const pageLinks = computed(() =>
  props.rows.links.filter((l) => !/previous|next/i.test(l.label)),
)
const prevLink = computed(() => props.rows.links.find((l) => /previous/i.test(l.label))?.url ?? null)
const nextLink = computed(() => props.rows.links.find((l) => /next/i.test(l.label))?.url ?? null)

const tabCount = computed(() =>
  activeTab.value === 'bids' ? props.counts.bids : props.counts.procurement,
)
</script>

<template>
  <!-- ======================================= hero-section 509:49269 =======
       Base is a #0b192c -> #064e3b gradient; hero-bg-image 509:49271 is a
       VIDEO fill with a 40% #0b192c wash. The two discs are Figma's own
       Ellipse layers: teal #2dd4a8 450px @12% / blur 100 behind the video,
       orange #f7941d 300px @6% / blur 80 in front of it. -->
  <section
    class="relative overflow-hidden bg-linear-to-b from-[#0b192c] to-[#064e3b] section-x pt-14 pb-16"
  >
    <div
      class="pointer-events-none absolute -top-[50px] -right-[50px] size-[450px] rounded-full bg-[#2dd4a8] opacity-12 blur-[100px]"
      aria-hidden="true"
    />
    <HeroVideo overlay="bg-[#0b192c]/40" />
    <div
      class="pointer-events-none absolute top-[180px] left-[100px] size-[300px] rounded-full bg-[#f7941d] opacity-6 blur-[80px]"
      aria-hidden="true"
    />

    <div class="relative flex flex-col gap-7">
      <!-- breadcrumbs 509:49324 -->
      <nav class="flex items-center gap-2 text-xs text-white" :aria-label="t('sbo_dashboard.breadcrumb')">
        <RouterLink href="/dashboard" class="hover:underline">{{ t('sbo_dashboard.business_dashboard') }}</RouterLink>
        <span aria-hidden="true">/</span>
        <span class="font-medium">{{ t('sbo_dashboard.business_account') }}</span>
      </nav>

      <!-- hero-split-layout 509:49279 -->
      <div class="flex flex-col items-start gap-10 lg:flex-row lg:items-center">
        <div class="min-w-0 flex-1">
          <h1 class="font-serif text-2xl font-bold text-white">{{ t('sbo_dashboard.sbo_workspace', { entity: entityTerm }) }}</h1>
          <p class="mt-1 text-sm text-white">
            {{ t('sbo_dashboard.welcome_back', { name: user.name }) }}
          </p>
        </div>

        <!-- hero-right-card 509:49290 -->
        <div
          class="flex w-full shrink-0 flex-col items-center gap-4 rounded-3xl border border-white/12 bg-white/6 p-5 sm:p-8 shadow-[0px_12px_24px_rgba(0,0,0,0.15)] backdrop-blur-[30px] lg:w-[360px]"
        >
          <span
            class="grid size-16 place-items-center rounded-[32px] border shadow-[0px_0px_18px_rgba(45,212,168,0.4)]"
            :class="props.sbo?.verified ? 'border-[#2dd4a8] bg-[#2dd4a8]/12' : 'border-[#f0b429] bg-[#f0b429]/12 shadow-[0px_0px_18px_rgba(240,180,41,0.35)]'"
            aria-hidden="true"
          >
            <span
              class="grid size-11 place-items-center rounded-[22px]"
              :class="props.sbo?.verified ? 'bg-[#2dd4a8]' : 'bg-[#f0b429]'"
            >
              <img :src="props.sbo?.verified ? iconCheck : iconTabFile" alt="" class="size-6" />
            </span>
          </span>
          <div class="text-center">
            <p class="font-display text-xl font-semibold text-white">
              {{ props.sbo?.verified ? t('sbo_dashboard.verified_sbo', { entity: entityTerm }) : t('data.status.awaiting_verification') }}
            </p>
            <p class="mt-1 text-xs text-[#94a3b8]">
              {{ props.sbo?.verified ? t('sbo_dashboard.verified_trust_protocol') : t('sbo_dashboard.your_application_is_under_review') }}
            </p>
          </div>
        </div>
      </div>

      <!-- hero-actions 538:55972 -->
      <div class="flex items-center">
        <RouterLink
          href="/procurement/new"
          class="btn-primary inline-flex items-center gap-2 rounded-lg px-7 py-3.5"
        >
          <img :src="iconPlus" alt="" class="size-4 brightness-0 invert" aria-hidden="true" />
          <span class="font-display text-base font-semibold whitespace-nowrap">{{ t('sbo_dashboard.post_rfq') }}</span>
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ============================ active-user-profile-bar 538:55979 =======
       Two tabs, 34px apart. Active carries an 8px #1b8a4e bar flush with the
       bottom edge; inactive drops its text to 60% opacity. -->
  <div class="border border-[#e2e8f0] bg-white section-x py-5">
    <div class="flex flex-wrap items-center gap-x-[34px] gap-y-4">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        class="relative flex min-w-0 items-center gap-5 text-left"
        :aria-pressed="activeTab === t.key"
        @click="openTab(t.key)"
      >
        <span class="grid size-12 shrink-0 place-items-center rounded-3xl">
          <img :src="iconTabFile" alt="" class="size-6" aria-hidden="true" />
        </span>
        <span class="flex flex-col gap-1">
          <span
            class="font-display text-lg font-semibold lg:whitespace-nowrap"
            :class="activeTab === t.key ? 'text-[#0f172a]' : 'text-[#0f172a]/60'"
          >
            {{ t.title }}
          </span>
          <span
            class="text-xs lg:whitespace-pre"
            :class="activeTab === t.key ? 'text-[#475569]' : 'text-[#475569]/60'"
          >
            {{ t.sub }}
          </span>
        </span>
        <span
          v-if="activeTab === t.key"
          class="absolute -bottom-[21px] -left-px -right-[34px] hidden h-2 bg-[#1b8a4e] lg:block"
          aria-hidden="true"
        />
      </button>
    </div>
  </div>

  <!-- ======================================= workspace-left 536:55873 =====
       timeline-card 536:55892 — a bordered table inside a 16px-radius card.
       Pagination 538:56458 sits BELOW the card, on the page background. -->
  <div class="bg-surface-secondary pt-6 pb-6">
    <div class="container-page">
      <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
        <!-- table-header 536:55893 -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ tableTitle }}</h2>
          <!-- Was the fixed string "3 requests". -->
          <p class="text-xs text-[#475569]">
            {{ tc('sbo_dashboard.request_count', tabCount) }} · {{ t('sbo_dashboard.posting_service_available') }}
          </p>
        </div>

        <!-- table-container 536:55896 -->
        <div class="overflow-x-auto rounded-lg border border-[#e2e8f0]">
          <table class="w-full min-w-[1010px] border-collapse text-left">
            <thead>
              <tr class="bg-[#f8fafc] text-xs font-semibold text-[#475569]">
                <th class="w-[260px] px-4 py-3 font-semibold">{{ t('sbo_dashboard.title') }}</th>
                <th class="w-[120px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.type') }}</th>
                <th class="w-[120px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.category') }}</th>
                <th class="w-[120px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.deadline') }}</th>
                <th class="w-[120px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.visibility') }}</th>
                <th class="w-[128px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.status') }}</th>
                <th class="w-[80px] px-3 py-3 font-semibold">{{ t('sbo_dashboard.bids') }}</th>
                <th class="w-[140px] px-3 py-3 text-right font-semibold">{{ t('sbo_dashboard.action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rfqs" :key="r.id" class="border-b border-[#e2e8f0] bg-white">
                <td
                  class="min-w-0 max-w-[260px] truncate px-4 py-4 text-base font-semibold text-[#0f172a]"
                >
                  {{ r.title }}
                </td>
                <td class="px-3 py-4 text-xs text-[#475569]">{{ r.type }}</td>
                <td class="px-3 py-4 text-xs text-[#475569]">{{ r.category }}</td>
                <td class="px-3 py-4 text-xs text-[#475569]">{{ r.deadline }}</td>
                <td class="px-3 py-4 text-xs text-[#475569]">{{ r.visibility }}</td>
                <td class="px-3 py-4">
                  <span
                    class="inline-block rounded-full px-2.5 py-1 text-2xs font-semibold whitespace-nowrap"
                    :class="statusTone[r.statusKey]"
                  >
                    {{ r.status }}
                  </span>
                </td>
                <td class="px-3 py-4 text-right text-xs font-semibold text-[#0f172a]">
                  {{ r.bids }}
                </td>
                <td class="px-3 py-4 text-right">
                  <RouterLink
                    :href="`/procurement/${r.id}`"
                    class="btn-primary inline-flex h-8 items-center justify-center rounded-lg px-3 text-xs font-semibold whitespace-nowrap"
                  >
                    {{ actionLabel }}
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Reachable on a new account, and on My Bids before bidding. -->
          <p v-if="!rfqs.length" class="px-4 py-8 sm:py-12 text-center text-sm text-[#64748b]">
            {{
              activeTab === 'bids'
                ? 'You have not bid on any requests yet.'
                : 'You have not posted a procurement request yet.'
            }}
          </p>
        </div>
      </section>

      <!-- pagination 538:56458 -->
      <nav
        v-if="meta.total"
        class="flex flex-wrap items-center justify-between gap-4 pt-4"
        :aria-label="t('sbo_dashboard.pagination')"
      >
        <p class="text-sm text-[#64748b]">
          {{ t('sbo_dashboard.showing_range', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}
        </p>
        <div v-if="meta.last_page > 1" class="flex items-center gap-2">
          <component
            :is="prevLink ? 'a' : 'span'"
            :href="prevLink ?? undefined"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-semibold text-[#475569]"
            :class="prevLink ? 'hover:bg-surface-secondary' : 'opacity-40'"
          >
            {{ t('sbo_dashboard.previous') }}
          </component>

          <template v-for="l in pageLinks" :key="l.label">
            <span v-if="!l.url" class="text-xs text-[#64748b]" aria-hidden="true">
              {{ l.label }}
            </span>
            <a
              v-else
              :href="l.url"
              class="rounded-lg px-3.5 py-2 text-xs"
              :class="
                l.active
                  ? 'bg-[#0d5c75] font-semibold text-white'
                  : 'border border-[#e2e8f0] bg-white text-[#475569] hover:bg-surface-secondary'
              "
              :aria-current="l.active ? 'page' : undefined"
            >
              {{ l.label }}
            </a>
          </template>

          <component
            :is="nextLink ? 'a' : 'span'"
            :href="nextLink ?? undefined"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-semibold text-[#475569]"
            :class="nextLink ? 'hover:bg-surface-secondary' : 'opacity-40'"
          >
            {{ t('sbo_dashboard.next') }}
          </component>
        </div>
      </nav>
    </div>
  </div>

  <!-- ====================================== workspace-split 536:55792 =====
       promo-cta-banner 536:55865 / banner-plate 536:55866: photo fill under a
       #0b192c -> rgba(27,43,75,0.92) vertical gradient, 24px radius, p-48. -->
  <section class="container-page pt-6 pb-12">
    <div
      class="relative flex flex-col items-start justify-between gap-6 overflow-hidden rounded-3xl p-6 sm:p-10 lg:p-12 lg:flex-row lg:items-center"
    >
      <img
        :src="promoPlate"
        alt=""
        class="absolute inset-0 size-full object-cover"
        aria-hidden="true"
      />
      <div
        class="absolute inset-0 bg-linear-to-b from-[#0b192c] to-[#1b2b4b]/92"
        aria-hidden="true"
      />

      <div class="relative flex max-w-[720px] flex-col gap-3">
        <h2 class="font-serif text-3xl font-bold text-white">
          {{ t('sbo_dashboard.reach_verified_loveworld_businesses') }}
        </h2>
        <p class="text-base leading-6 text-[#94a3b8]">
          {{ t('sbo_dashboard.connect_with_over_975_validated_spirit_led') }}
        </p>
      </div>

      <RouterLink
        href="/advertise"
        class="btn-primary relative shrink-0 rounded-xl px-5 sm:px-7 py-3.5 text-sm font-semibold whitespace-nowrap"
      >
        {{ t('sbo_dashboard.advertise_with_us') }}
      </RouterLink>
    </div>
  </section>

  <!-- ============================================ Frames 509:49084 =======
       Benefits run full-bleed here, not as a card. -->
  <BenefitsStrip variant="band" />
</template>
