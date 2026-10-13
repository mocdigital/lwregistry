<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link as RouterLink, router, useForm } from '@inertiajs/vue3'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import LiveOpportunities from '@/components/shell/LiveOpportunities.vue'

import iconStatDotTeal from '@/assets/figma/notifications/stat-dot-teal.svg'
import iconStatDotAmber from '@/assets/figma/notifications/stat-dot-amber.svg'
import iconSearch from '@/assets/figma/notifications/search.svg'
import iconCalendar from '@/assets/figma/notifications/calendar.svg'
import iconChevronDown from '@/assets/figma/notifications/chevron-down.svg'
import iconUnreadDot from '@/assets/figma/notifications/unread-dot.svg'
import iconCheck from '@/assets/figma/notifications/card-check.svg'
import iconDollar from '@/assets/figma/notifications/card-dollar.svg'
import iconUsers from '@/assets/figma/notifications/card-users.svg'
import iconAlert from '@/assets/figma/notifications/card-alert.svg'
import iconBriefcase from '@/assets/figma/notifications/card-briefcase.svg'
import iconAward from '@/assets/figma/notifications/card-award.svg'
import iconChart from '@/assets/figma/notifications/card-chart.svg'
import iconClose from '@/assets/figma/icons/net-cross.svg'
import adSpace from '@/assets/figma/notifications/ad-space.jpg'
import adArrow from '@/assets/figma/notifications/ad-arrow.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: AppLayout })

/*
 * Notifications — Figma 253:8930 "notifications-page-redesign" (1280x2694).
 *   Hero_Header 253:8931 (227)  VIDEO fill + disc. The design also layers a
 *                               Cityscape_Overlay image (253:8933) here; it is
 *                               deliberately left out.
 *   Workspace_Layout 253:8947   VERTICAL, gap 32, pad 24/80/48/80
 *     Summary_Bar 253:8948 (88) avatar lockup + three quick stats
 *     Filters_Row 253:8969 (41) seven-pill tab bar + search + date filter
 *     Columns_Grid 253:8999     708px feed beside a 380px sidebar, gap 32
 *
 * The feed groups by date; each Notification_Card is a 12px-radius panel with
 * a 40px tinted Icon_Wrapper. Unread cards take a rgba(13,148,136,0.3) border
 * and a blue dot in the title row.
 *
 * Everything below now comes from the `notifications` table. The card's icon
 * and tint are picked server-side (App\Models\Notification) and named by key,
 * so the SVGs stay here — the design's own assets — while the classification
 * lives in one place on the backend.
 */

type Card = {
  id: string
  title: string
  body: string
  time: string | null
  icon: string
  tint: string
  unread: boolean
  badge: string | null
  action: { label: string | null; url: string } | null
  connectionId: number | null
}

const props = defineProps<{
  user: { name: string; email: string; initials: string; photoUrl?: string | null }
  filter: string
  search: string
  counts: {
    all: number
    unread: number
    action: number
    applications: number
    payments: number
    network: number
    system: number
  }
  groups: { label: string; cards: Card[] }[]
  pagination: {
    current_page: number
    last_page: number
    total: number
    from: number | null
    to: number | null
  }
}>()

/*
 * Icon keys, not paths, cross the wire. Keeping the map here means the assets
 * are still imported by the screen that draws them (so Vite fingerprints them)
 * while the server decides which one a given notification gets.
 */
const ICONS: Record<string, string> = {
  check: iconCheck,
  dollar: iconDollar,
  users: iconUsers,
  alert: iconAlert,
  briefcase: iconBriefcase,
  award: iconAward,
  chart: iconChart,
}

// Filters_Row 253:8970 — seven pills in an #f1f5f9 track. Counts were fixed at
// "All (23) / Unread (5) / Action Required (3)…" for everyone; they are real now.
const tabs = computed(() => [
  { key: 'all', label: `${t('notifications.tab.all')} (${props.counts.all})` },
  { key: 'unread', label: `${t('notifications.tab.unread')} (${props.counts.unread})` },
  { key: 'action', label: `${t('notifications.tab.action')} (${props.counts.action})` },
  { key: 'applications', label: `${t('notifications.tab.applications')} (${props.counts.applications})` },
  { key: 'payments', label: `${t('notifications.tab.payments')} (${props.counts.payments})` },
  { key: 'network', label: `${t('notifications.tab.network')} (${props.counts.network})` },
  { key: 'system', label: `${t('notifications.tab.system')} (${props.counts.system})` },
])

/*
 * Filter and search are query-string state so the view is linkable and the
 * Back button works. `preserveState` keeps the search box focused and its
 * value intact across the round trip.
 */
function visit(next: Record<string, string>) {
  const query = Object.fromEntries(
    Object.entries({ filter: props.filter, search: props.search, ...next }).filter(
      ([, v]) => v !== '' && v !== 'all',
    ),
  )
  const qs = new URLSearchParams(query).toString()

  router.get(qs ? `/notifications?${qs}` : '/notifications', undefined, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const search = ref(props.search)
watch(
  () => props.search,
  (v) => (search.value = v),
)

// Debounced so typing does not fire a visit per keystroke.
let searchTimer: ReturnType<typeof setTimeout> | undefined
function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => visit({ search: search.value, page: '1' }), 300)
}

/*
 * Every mutation is a POST/DELETE followed by a redirect back, so the feed and
 * all seven counts re-render from the server rather than being patched here
 * and drifting.
 */
const action = useForm({})

function markRead(card: Card) {
  if (!card.unread) return
  action.post(`/notifications/${card.id}/read`, { preserveScroll: true })
}

function markAllRead() {
  action.post('/notifications/read-all', { preserveScroll: true })
}

function remove(card: Card) {
  action.delete(`/notifications/${card.id}`, { preserveScroll: true })
}

/* Accept / Decline on a connection-request card — the same endpoint the
 * network screen's cards use. */
function respond(card: Card, status: 'accepted' | 'declined') {
  if (card.connectionId === null) return
  router.patch(
    `/sbo/connections/${card.connectionId}`,
    { status },
    {
      preserveScroll: true,
      // The request has been dealt with; the card has nothing left to say.
      onSuccess: () => action.post(`/notifications/${card.id}/read`, { preserveScroll: true }),
    },
  )
}

const empty = computed(() => props.groups.length === 0)

function pageUrl(page: number): string {
  const query = Object.fromEntries(
    Object.entries({ filter: props.filter, search: props.search, page: String(page) }).filter(
      ([, v]) => v !== '' && v !== 'all',
    ),
  )
  return `/notifications?${new URLSearchParams(query).toString()}`
}

/* The window of page numbers the pagination row draws (the design shows 3). */
const pages = computed(() => {
  const { current_page: current, last_page: last } = props.pagination
  const start = Math.max(1, Math.min(current - 1, last - 2))
  return Array.from({ length: Math.min(3, last) }, (_, i) => start + i).filter((p) => p <= last)
})
</script>

<template>
  <!-- ========================================= Hero_Header 253:8931 ======= -->
  <section class="relative overflow-hidden bg-[#001529] section-x py-8 sm:py-12">
    <HeroVideo overlay="bg-[#001529]/72" />

    <!-- Ellipse 253:8932 -->
    <div
      class="pointer-events-none absolute -top-[26px] -right-[100px] size-[400px] rounded-full bg-[#2dd4a8] opacity-10 blur-[90px]"
      aria-hidden="true"
    />

    <div class="relative flex flex-col gap-6">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('notifications.breadcrumb')">
        <RouterLink href="/account" class="text-[#94a3b8] hover:text-white">{{ t('notifications.account') }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('notifications.gt') }}</span>
        <span class="text-white">{{ t('notifications.notifications') }}</span>
      </nav>

      <!-- Hero_Main 253:8938 -->
      <div class="flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex max-w-[720px] flex-col gap-2">
          <h1 class="font-serif text-4xl font-bold text-white">{{ t('notifications.notifications') }}</h1>
          <p class="text-base text-[#94a3b8]">
            {{ t('notifications.stay_close_to_application_reviews_payment') }}
          </p>
        </div>

        <!-- Hero_Actions 253:8942 -->
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            :disabled="props.counts.unread === 0 || action.processing"
            class="rounded-lg border border-white/30 px-5 py-2.5 text-sm font-medium whitespace-nowrap text-white transition-colors hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
            @click="markAllRead"
          >
            {{ t('notifications.mark_all_as_read') }}
          </button>
          <RouterLink
            href="/settings/notifications"
            class="rounded-lg border border-white/30 px-5 py-2.5 text-sm font-medium whitespace-nowrap text-white transition-colors hover:bg-white/10"
          >
            {{ t('notifications.notification_settings') }}
          </RouterLink>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================================== Workspace_Layout 253:8947 ======= -->
  <div class="flex flex-col gap-8 bg-surface-secondary section-x pt-6 pb-12">
    <!-- Summary_Bar 253:8948 -->
    <div
      class="flex flex-wrap items-center justify-between gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 drop-shadow-[0px_4px_6px_rgba(0,0,0,0.02)]"
    >
      <div class="flex items-center gap-4">
        <UiAvatar
          :src="props.user.photoUrl"
          :name="props.user.name"
          :initials="props.user.initials"
          class="size-12 font-display text-lg"
        />
        <div class="flex flex-col gap-1">
          <p class="font-display text-base font-semibold text-[#0f172a]">{{ props.user.name }}</p>
          <p class="text-xs text-[#475569]">{{ t('notifications.personal_dashboard_account') }}</p>
        </div>
      </div>

      <!-- Quick_Stats 253:8955 -->
      <div class="flex flex-wrap items-center gap-12">
        <div class="flex flex-col items-center gap-1">
          <span class="flex items-center gap-2">
            <img :src="iconStatDotTeal" alt="" class="size-2" aria-hidden="true" />
            <span class="font-display text-xl font-semibold text-[#0f172a]">
              {{ props.counts.unread }}
            </span>
          </span>
          <span class="text-xs font-medium text-[#475569]">{{ t('notifications.unread') }}</span>
        </div>
        <div class="flex flex-col items-center gap-1">
          <span class="font-display text-xl font-semibold text-[#0f172a]">{{ props.counts.all }}</span>
          <span class="text-xs font-medium text-[#475569]">{{ t('notifications.total_notifications') }}</span>
        </div>
        <div class="flex flex-col items-center gap-1">
          <span class="flex items-center gap-2">
            <img :src="iconStatDotAmber" alt="" class="size-2" aria-hidden="true" />
            <span class="font-display text-xl font-semibold text-[#0f172a]">
              {{ props.counts.action }}
            </span>
          </span>
          <span class="text-xs font-medium text-[#475569]">{{ t('notifications.action_required') }}</span>
        </div>
      </div>
    </div>

    <!-- Filters_Row 253:8969 -->
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex flex-wrap gap-2 rounded-[10px] bg-[#f1f5f9] p-1">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="rounded-lg px-4 py-2 text-xs whitespace-nowrap transition-colors"
          :class="
            t.key === props.filter
              ? 'bg-white font-semibold text-[#0f172a] drop-shadow-[0px_2px_2px_rgba(0,0,0,0.02)]'
              : 'font-medium text-[#475569] hover:text-[#0f172a]'
          "
          :aria-pressed="t.key === props.filter"
          @click="visit({ filter: t.key, page: '1' })"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- Controls 253:8985 -->
      <div class="flex items-center gap-3">
        <label
          class="flex w-[161px] items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white py-2 pr-4 pl-3"
        >
          <span class="sr-only">{{ t('notifications.search_notifications') }}</span>
          <img :src="iconSearch" alt="" class="size-3.5 shrink-0" aria-hidden="true" />
          <input
            v-model="search"
            type="search"
            :placeholder="t('notifications.search_notifications_2')"
            class="min-w-0 flex-1 text-xs text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none"
            @input="onSearch"
          />
        </label>
        <!--
          The date filter is drawn but inert: the notifications table carries
          only `created_at`, and the design names no ranges for the dropdown to
          offer. Left as-is rather than invented — see INTEGRATION.md.
        -->
        <button
          type="button"
          disabled
          class="flex shrink-0 items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 disabled:cursor-not-allowed"
        >
          <img :src="iconCalendar" alt="" class="size-3.5" aria-hidden="true" />
          <span class="text-xs font-medium text-[#475569]">{{ t('notifications.all_time') }}</span>
          <img :src="iconChevronDown" alt="" class="size-3" aria-hidden="true" />
        </button>
      </div>
    </div>

    <!-- Columns_Grid 253:8999 — 708px feed + 380px sidebar -->
    <div class="flex flex-col gap-8 lg:flex-row">
      <div class="flex min-w-0 flex-1 flex-col gap-8">
        <section v-for="g in props.groups" :key="g.label" class="flex flex-col gap-4">
          <h2 class="font-sans text-sm font-semibold text-[#475569] uppercase">{{ g.label }}</h2>

          <!-- Notification_Card 253:9003 -->
          <article
            v-for="c in g.cards"
            :key="c.id"
            class="flex gap-4 rounded-xl border bg-white p-5 drop-shadow-[0px_2px_3px_rgba(0,0,0,0.01)]"
            :class="c.unread ? 'border-[#0d9488]/30' : 'border-[#e2e8f0]'"
          >
            <span
              class="grid size-10 shrink-0 place-items-center rounded-[10px]"
              :style="{ backgroundColor: c.tint }"
              aria-hidden="true"
            >
              <img :src="ICONS[c.icon] ?? iconChart" alt="" class="size-5" />
            </span>

            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
              <div class="flex items-center gap-3">
                <p class="min-w-0 flex-1 font-display text-base font-semibold text-[#0f172a]">
                  {{ c.title }}
                </p>
                <span
                  v-if="c.badge"
                  class="shrink-0 rounded-full bg-[#fef3c7] px-2.5 py-0.5 text-2xs font-semibold text-[#b45309]"
                >
                  {{ c.badge }}
                </span>
                <img v-if="c.unread" :src="iconUnreadDot" :alt="t('notifications.unread')" class="size-2 shrink-0" />

                <!--
                  Dismiss. The Figma card has no delete affordance, so this
                  control is an addition, kept to the card's own idiom (the
                  network screen's remove button uses the same glyph). Without
                  it DELETE /notifications/{id} would have no way in.

                  Drawn at full strength rather than as a faint hover-only
                  glyph: a destructive action nobody can find is not a safer
                  one. Bordered like the card's other controls, and it turns
                  red on hover/focus so the consequence is legible before the
                  click.

                  The mark is net-cross.svg's own geometry — two 10-unit
                  strokes at ±45°, 1.5 wide, round caps — inlined rather than
                  imported so the stroke can follow state. The asset hard-codes
                  #9ca3af, and that pale grey is what made it invisible.
                -->
                <button
                  type="button"
                  class="grid size-8 shrink-0 place-items-center rounded-md border border-[#e2e8f0] bg-white text-[#475569] transition-colors hover:border-danger hover:bg-[#fef2f2] hover:text-danger focus-visible:border-danger focus-visible:bg-[#fef2f2] focus-visible:text-danger focus-visible:outline-none"
                  :title="t('action.delete_named', { name: c.title })"
                  :aria-label="t('notifications.delete_named', { name: c.title })"
                  @click="remove(c)"
                >
                  <svg viewBox="0 0 24 24" fill="none" class="size-[18px]" aria-hidden="true">
                    <path
                      d="M12 7V17"
                      stroke="currentColor"
                      stroke-width="1.5"
                      stroke-linecap="round"
                      transform="rotate(45 12 12)"
                    />
                    <path
                      d="M12 7V17"
                      stroke="currentColor"
                      stroke-width="1.5"
                      stroke-linecap="round"
                      transform="rotate(-45 12 12)"
                    />
                  </svg>
                </button>
              </div>

              <p class="text-xs leading-5 text-[#475569]">{{ c.body }}</p>

              <div class="flex flex-wrap items-center gap-4">
                <span class="text-xs text-[#94a3b8]">{{ c.time }}</span>
                <a
                  v-if="c.action"
                  :href="c.action.url"
                  class="text-xs font-semibold text-[#0d9488]"
                  @click="markRead(c)"
                >
                  {{ c.action.label }} →
                </a>
                <button
                  v-if="c.unread"
                  type="button"
                  class="text-xs font-medium text-[#94a3b8] hover:text-[#475569]"
                  @click="markRead(c)"
                >
                  {{ t('notifications.mark_as_read') }}
                </button>
              </div>

              <!-- Extra_Action_Buttons 253:9039 -->
              <div v-if="c.connectionId !== null" class="flex gap-3 pt-2">
                <button
                  type="button"
                  class="rounded-md bg-[#0d9488] px-4 py-2 text-xs font-semibold text-white transition-opacity hover:opacity-90"
                  @click="respond(c, 'accepted')"
                >
                  {{ t('notifications.accept') }}
                </button>
                <button
                  type="button"
                  class="rounded-md border border-[#e2e8f0] px-4 py-2 text-xs font-medium text-[#475569] hover:bg-surface-secondary"
                  @click="respond(c, 'declined')"
                >
                  {{ t('notifications.decline') }}
                </button>
              </div>
            </div>
          </article>
        </section>

        <!-- Empty state: a new account, or a filter that matches nothing. -->
        <div
          v-if="empty"
          class="rounded-xl border border-dashed border-[#e2e8f0] bg-white px-6 py-10 sm:py-16 text-center"
        >
          <p class="font-display text-base font-semibold text-[#0f172a]">
            {{ props.counts.all === 0 ? t('notifications.no_notifications_yet') : t('notifications.nothing_matches_this_filter') }}
          </p>
          <p class="mt-1 text-xs text-[#475569]">
            {{
              props.counts.all === 0
                ? t('notifications.application_reviews_payment_confirmations')
                : t('notifications.try_another_tab_or_clear_the_search')
            }}
          </p>
        </div>

        <!-- Pagination 253:9098 -->
        <nav
          v-if="props.pagination.last_page > 1"
          class="flex flex-wrap items-center justify-between gap-4"
          :aria-label="t('notifications.pagination')"
        >
          <p class="text-xs text-[#475569]">
            {{ t('notifications.showing_range', { from: props.pagination.from, to: props.pagination.to, total: props.pagination.total }) }}
          </p>
          <div class="flex items-center gap-2">
            <RouterLink
              v-if="props.pagination.current_page > 1"
              :href="pageUrl(props.pagination.current_page - 1)"
              preserve-scroll
              class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
            >
              {{ t('notifications.previous') }}
            </RouterLink>
            <RouterLink
              v-for="p in pages"
              :key="p"
              :href="pageUrl(p)"
              preserve-scroll
              class="grid size-9 place-items-center rounded-lg border text-xs font-semibold"
              :class="
                p === props.pagination.current_page
                  ? 'border-[#0d9488] bg-[#0d9488] text-white'
                  : 'border-[#e2e8f0] bg-white text-[#475569]'
              "
              :aria-current="p === props.pagination.current_page ? 'page' : undefined"
            >
              {{ p }}
            </RouterLink>
            <RouterLink
              v-if="props.pagination.current_page < props.pagination.last_page"
              :href="pageUrl(props.pagination.current_page + 1)"
              preserve-scroll
              class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
            >
              {{ t('notifications.next') }}
            </RouterLink>
          </div>
        </nav>
      </div>

      <!-- Right_Column_Sidebar 253:9111 -->
      <aside class="flex w-full shrink-0 flex-col gap-6 lg:w-[380px]">
        <!-- Live Opportunities 422:32696 — shared with /profile -->
        <LiveOpportunities />

        <!-- Sponsored_Ad_Card 253:9132 — header row above a 271px image panel -->
        <section class="flex flex-col gap-4 rounded-[20px] border border-[#e2e8f0] bg-white p-6">
          <div class="flex items-center justify-between gap-3">
            <p class="text-2xs font-semibold text-[#0d9488] uppercase">{{ t('notifications.advertisement_space') }}</p>
            <span class="rounded border border-[#e2e8f0] px-1.5 py-0.5 text-2xs text-[#475569]">
              {{ t('notifications.ad') }}
            </span>
          </div>

          <!-- Frames 279:14858 -->
          <RouterLink href="/advertise" class="relative block h-[271px] overflow-hidden rounded-xl">
            <img
              :src="adSpace"
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

              <span class="text-2xs text-[#d1d5db]">{{ t('notifications.advertisement_space') }}</span>

              <span class="max-w-[232px] font-display text-xl leading-6 font-semibold text-white">

                {{ t('notifications.reach_verified_loveworld_businesses') }}

              </span>

              <span

                class="mt-auto inline-flex h-8 w-full max-w-[200px] items-center justify-center gap-1.5 rounded bg-white px-3 text-2xs font-semibold text-[#1e3a8a]"

              >

                {{ t('notifications.advertise_your_business') }}

                <img :src="adArrow" alt="" class="h-2.5 w-2 shrink-0" aria-hidden="true" />

              </span>

            </span>
          </RouterLink>
        </section>
      </aside>
    </div>
  </div>
</template>
