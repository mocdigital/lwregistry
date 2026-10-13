<script setup lang="ts">
import { computed, reactive } from 'vue'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import procSearch from '@/assets/figma/icons/proc-search.svg'
import procCheck from '@/assets/figma/icons/proc-check.svg'
import procSpark from '@/assets/figma/icons/proc-spark.svg'
// The stock cover for a request the buyer did not give a photo, so every card
// carries an image rather than some starting blank.
import defaultCover from '@/assets/figma/profile/procurement-default.jpg'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t, tc } = useI18n()
const { entityTerm } = useEntityTerm()

/*
 * Opportunities / Procurement Requests — Figma 290:4 (1280x2998).
 *   hero-section 290:29 (226)        — VIDEO fill, breadcrumbs, CTA
 *   workspace-split 536:55558 (1774) — filters-sidebar 300 + listings 788
 *   bottom-sticky-bar 290:269 (68)
 *
 * Card, sidebar and hero specs read from 536:55638, 536:55560 and 290:29.
 * Gutters are 80px here, so `section-x` applies.
 */

defineOptions({ layout: AppLayout })

type ProcurementRequest = {
  id: string
  title: string
  featuredImageUrl: string | null
  body: string | null
  category: string | null
  buyer: string | null
  budget: string | null
  status: string
  bids: string
  posted: string | null
  deadline: string | null
}

const props = defineProps<{
  requests: {
    data: ProcurementRequest[]
    links: { url: string | null; label: string; active: boolean }[]
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null }
  }
  facets: { label: string; count: number }[]
  /** Counted over the whole open set, not the current page. */
  statusFacets: { value: string; label: string; count: number }[]
  filters: { category?: string; search?: string; status?: string }
  sort?: string
  sorts?: { value: string; label: string }[]
  /** 'mine' on /sbo/rfqs, which reuses this screen without the filters. */
  scope?: string
}>()

const requests = computed(() => props.requests.data)

/*
 * The filters sidebar was drawn and inert: the keyword box had no model, the
 * category and status rows were display-only spans, "Clear All" did nothing and
 * the sort select drove a local ref. Every control is a query parameter now, so
 * the URL is shareable and pagination keeps the filters.
 */
const form = reactive({
  search: props.filters.search ?? '',
  category: props.filters.category ?? '',
  status: props.filters.status ?? '',
  sort: props.sort ?? 'recent',
})

function submit() {
  const query = Object.fromEntries(
    Object.entries(form).filter(([k, v]) => v !== '' && !(k === 'sort' && v === 'recent')),
  )

  router.get('/procurement', query, { preserveState: true, preserveScroll: true, replace: true })
}

function toggleCategory(label: string) {
  // A checked box unchecks — that is what a checkbox does, and there is no
  // other way back to "everything" from a single-select facet.
  form.category = form.category === label ? '' : label
  submit()
}

function selectStatus(value: string) {
  form.status = value === 'all' ? '' : value
  submit()
}

function clearAll() {
  form.search = ''
  form.category = ''
  form.status = ''
  form.sort = 'recent'
  submit()
}

const hasFilters = computed(
  () => form.search !== '' || form.category !== '' || form.status !== '' || form.sort !== 'recent',
)

const categories = computed(() =>
  props.facets.map((f) => ({ ...f, checked: f.label === form.category })),
)

const statuses = computed(() =>
  props.statusFacets.map((f) => ({
    ...f,
    checked: f.value === 'all' ? form.status === '' : form.status === f.value,
  })),
)

const meta = computed(() => props.requests.meta)

/*
 * Laravel labels the two ends of linkCollection() "&laquo; Previous" and
 * "Next &raquo;" in English, HTML entity and all. The screen already has both
 * words translated, so the ends are named from the catalogue and only the
 * numbered links keep the paginator's own label — which means no v-html, and
 * the row reads in the reader's language.
 */
function pageLabel(link: { label: string }, index: number): string {
  if (index === 0) return t('procurement_list.previous')
  if (index === props.requests.links.length - 1) return t('procurement_list.next')

  return link.label
}
</script>

<template>
  <!-- ========================================== hero-section 290:29 ======= -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo overlay="bg-[#001529]/72" />

    <div class="relative flex flex-col gap-7 section-x py-8 sm:py-12">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('procurement_list.breadcrumb')">
        <RouterLink href="/sbo/dashboard" class="text-[#94a3b8] hover:text-white">{{ t('procurement_list.sbo_workspace', { entity: entityTerm }) }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('procurement_list.gt') }}</span>
        <span class="text-white">{{ t('procurement_list.procurement_requests') }}</span>
      </nav>

      <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex max-w-[675px] flex-col gap-2">
          <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">
            {{ t('procurement_list.procurement_requests') }}
          </h1>
          <p class="text-base text-[#94a3b8]">
            {{ t('procurement_list.post_and_manage_product_or_service_request') }}
          </p>
        </div>

        <div class="flex w-full shrink-0 flex-col gap-3 sm:flex-row lg:w-auto">
          <!-- Won & awarded bids: the platform's success stories. -->
          <RouterLink
            href="/procurement/awarded"
            class="flex w-full items-center justify-center gap-2 rounded-xl border border-white/25 px-5 py-3.5 text-base font-semibold whitespace-nowrap text-white transition-colors hover:bg-white/10 sm:w-auto"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5" aria-hidden="true">
              <path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 4H5a2 2 0 0 0 0 4h1M17 4h2a2 2 0 0 1 0 4h-1" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ t('procurement_list.awarded_bids') }}
          </RouterLink>
          <RouterLink
            href="/procurement/new"
            class="btn-primary flex w-full items-center justify-center rounded-xl px-5 sm:px-7 py-3.5 text-base font-semibold whitespace-nowrap lg:w-auto lg:min-w-[225px]"
          >
            {{ t('procurement_list.post_procurement_request') }}
          </RouterLink>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================================== workspace-split 536:55558 ======= -->
  <div class="bg-surface-secondary section-x py-8 pb-[92px]">
    <div class="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)] [&>*]:min-w-0">
      <!-- filters-sidebar 536:55560 -->
      <aside
        class="flex h-fit min-w-0 flex-col gap-6 rounded-xl border border-[#e2e8f0] bg-white p-6 shadow-[0px_4px_4px_rgba(15,23,42,0.02)]"
      >
        <div class="flex items-center justify-between">
          <h2 class="font-display text-base font-semibold text-[#0f172a]">{{ t('procurement_list.filters') }}</h2>
          <button
            type="button"
            :disabled="!hasFilters"
            class="text-xs font-medium text-[#0f766e] underline disabled:opacity-40 disabled:no-underline"
            @click="clearAll"
          >
            {{ t('procurement_list.clear_all') }}
          </button>
        </div>
        <hr class="border-[#e2e8f0]" />

        <div class="flex flex-col gap-2">
          <p class="text-xs font-semibold tracking-wide text-[#0f172a] uppercase">{{ t('procurement_list.keywords') }}</p>
          <div class="flex items-center gap-2 rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-3 py-2.5">
            <img :src="procSearch" alt="" class="size-3.5 shrink-0" aria-hidden="true" />
            <input
              v-model="form.search"
              type="search"
              :placeholder="t('procurement_list.search_requests')"
              class="min-w-0 flex-1 bg-transparent text-xs text-ink placeholder:text-[#94a3b8] focus:outline-none"
              @keyup.enter="submit"
              @search="submit"
            />
          </div>
        </div>

        <div class="flex flex-col gap-3">
          <p class="text-xs font-semibold tracking-wide text-[#0f172a] uppercase">{{ t('procurement_list.category') }}</p>
          <ul class="flex flex-col gap-2.5">
            <li v-for="c in categories" :key="c.label" class="flex items-center justify-between">
              <button
                type="button"
                class="flex cursor-pointer items-center gap-2.5 text-left"
                :aria-pressed="c.checked"
                @click="toggleCategory(c.label)"
              >
                <span
                  class="grid size-[18px] shrink-0 place-items-center rounded-sm border"
                  :class="c.checked ? 'border-[#0f766e] bg-[#0f766e]' : 'border-[#e2e8f0] bg-white'"
                >
                  <img v-if="c.checked" :src="procCheck" alt="" class="size-2.5" aria-hidden="true" />
                </span>
                <span class="text-sm" :class="c.checked ? 'font-medium text-[#0f172a]' : 'text-[#475569]'">
                  {{ c.label }}
                </span>
              </button>
              <span class="text-xs text-[#94a3b8]">{{ c.count }}</span>
            </li>
          </ul>
        </div>
        <hr class="border-[#e2e8f0]" />

        <div class="flex flex-col gap-3">
          <p class="text-xs font-semibold tracking-wide text-[#0f172a] uppercase">{{ t('procurement_list.status') }}</p>
          <ul class="flex flex-col gap-2.5">
            <li v-for="st in statuses" :key="st.value" class="flex items-center justify-between">
              <button
                type="button"
                class="flex cursor-pointer items-center gap-2.5 text-left"
                :aria-pressed="st.checked"
                @click="selectStatus(st.value)"
              >
                <span
                  class="grid size-[18px] shrink-0 place-items-center rounded-sm border"
                  :class="st.checked ? 'border-[#0f766e] bg-[#0f766e]' : 'border-[#e2e8f0] bg-white'"
                >
                  <img v-if="st.checked" :src="procCheck" alt="" class="size-2.5" aria-hidden="true" />
                </span>
                <span class="text-sm" :class="st.checked ? 'font-medium text-[#0f172a]' : 'text-[#475569]'">
                  {{ st.label }}
                </span>
              </button>
              <span class="text-xs text-[#94a3b8]">{{ st.count }}</span>
            </li>
          </ul>
        </div>
        <hr class="border-[#e2e8f0]" />

        <!-- pro-callout 536:55622 -->
        <div class="flex flex-col gap-3 rounded-md bg-[#ccfbf1] p-4">
          <div class="flex items-center gap-1.5">
            <img :src="procSpark" alt="" class="size-3.5" aria-hidden="true" />
            <p class="text-xs font-semibold text-[#0f766e]">{{ t('procurement_list.registered_bidder_benefits') }}</p>
          </div>
          <p class="text-xs leading-snug text-[#0f766e]">
            {{ t('procurement_list.complete_your_verification_today_to_receiv') }}
          </p>
        </div>
      </aside>

      <!-- listings-section 536:55627 -->
      <div class="min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <!-- Was the fixed "Showing 1-5 of 28". -->
          <p class="text-xs text-[#475569]">
            {{ t('procurement_list.showing') }} <strong class="text-[#0f172a]">{{ meta.from ?? 0 }}-{{ meta.to ?? 0 }}</strong> {{ t('procurement_list.of') }}
            <strong class="text-[#0f172a]">{{ meta.total }}</strong> {{ tc('procurement_list.open_opportunities', meta.total) }}
          </p>
          <label v-if="sorts?.length" class="flex items-center gap-2 text-xs text-[#475569]">
            {{ t('procurement_list.sort_by') }}
            <select
              v-model="form.sort"
              class="max-w-full rounded-md border border-[#e2e8f0] bg-white px-3 py-1.5 text-xs text-[#0f172a] focus:border-focus focus:outline-none"
              @change="submit"
            >
              <option v-for="o in sorts" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
        </div>

        <!-- cards-stack 536:55637 -->
        <ul class="mt-5 flex flex-col gap-5">
          <li v-for="r in requests" :key="r.id">
            <article
              class="flex flex-col overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-[0px_4px_4px_rgba(15,23,42,0.02)] sm:flex-row"
            >
              <!-- The buyer's cover photo, a column down the left. A request
                   without its own falls back to the stock cover. -->
              <img
                :src="r.featuredImageUrl || defaultCover"
                :alt="r.title"
                class="h-48 w-full shrink-0 object-cover sm:h-auto sm:w-52 md:w-60"
              />

              <div class="flex min-w-0 flex-1 flex-col gap-5 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="rounded-full bg-[#eff6ff] px-3 py-1 text-xs font-semibold text-[#007035]">
                    {{ r.category }}
                  </span>
                  <span class="text-xs font-semibold text-[#475569]">{{ r.budget }}</span>
                </div>
                <div class="flex items-center gap-3">
                  <span
                    class="rounded-full px-3 py-1 text-xs font-semibold"
                    :class="r.status === 'Open' ? 'bg-[#d1fae5] text-[#10b981]' : 'bg-amber-100 text-amber-700'"
                  >
                    {{ r.status }}
                  </span>
                  <span class="rounded-md bg-[#f1f5f9] px-2.5 py-1 text-xs font-medium text-[#0f172a]">
                    {{ r.bids }}
                  </span>
                </div>
              </div>

              <div class="flex flex-col gap-2.5">
                <h3 class="font-display text-xl font-semibold text-[#0f172a]">{{ r.title }}</h3>
                <!-- An excerpt only; the full specification is on the request's
                     own page, behind "View Details". -->
                <p class="line-clamp-3 text-sm leading-normal text-[#475569]">{{ r.body }}</p>
              </div>

              <hr class="border-[#e2e8f0]" />

              <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex gap-6">
                  <div class="flex flex-col gap-0.5">
                    <span class="text-2xs tracking-wide text-[#94a3b8] uppercase">{{ t('procurement_list.posted_on') }}</span>
                    <span class="text-xs font-medium text-[#0f172a]">{{ r.posted }}</span>
                  </div>
                  <div class="flex flex-col gap-0.5">
                    <span class="text-2xs tracking-wide text-[#94a3b8] uppercase">
                      {{ t('procurement_list.submission_deadline') }}
                    </span>
                    <span class="text-xs font-semibold text-[#0f172a]">{{ r.deadline }}</span>
                  </div>
                </div>

                <div class="flex items-center gap-3">
                  <RouterLink
                    :href="`/procurement/${r.id}`"
                    class="rounded-md border border-[#e2e8f0] bg-white px-6 py-3 text-sm font-semibold text-[#0f172a] hover:bg-surface-secondary"
                  >
                    {{ t('procurement_list.view_details') }}
                  </RouterLink>
                  <RouterLink
                    :href="`/procurement/${r.id}/bid`"
                    class="btn-primary rounded-md px-6 py-3 text-sm font-semibold shadow-[0px_4px_6px_rgba(15,118,110,0.13)]"
                  >
                    {{ t('procurement_list.place_bid') }}
                  </RouterLink>
                </div>
              </div>
              </div>
            </article>
          </li>
        </ul>

        <!-- pagination-row 536:55778
             The design's three numbered buttons were literally `[1, 2, 3]`
             with no handler and page one hardcoded as current: it paged
             nothing, and said "1 2 3" whether there were two pages or eleven.
             The paginator's own links are rendered instead, so the numbers
             match the result set and each one carries the active filters
             (the query is appended by withQueryString() on the server). -->
        <nav
          v-if="meta.last_page > 1"
          class="mt-6 flex flex-wrap items-center justify-center gap-1"
          :aria-label="t('procurement_list.pagination')"
        >
          <template v-for="(link, i) in props.requests.links" :key="i">
            <RouterLink
              v-if="link.url"
              :href="link.url"
              preserve-scroll
              class="grid h-8 min-w-8 place-items-center rounded-md border px-3 text-xs font-semibold"
              :class="
                link.active
                  ? 'border-[#0f766e] bg-[#0f766e] text-white'
                  : 'border-[#e2e8f0] bg-white text-[#475569] hover:bg-surface-secondary'
              "
              :aria-current="link.active ? 'page' : undefined"
            >
              {{ pageLabel(link, i) }}
            </RouterLink>
            <!-- Previous on page one, Next on the last: drawn, not clickable,
                 so the row keeps its shape as you move through it. -->
            <span
              v-else
              class="grid h-8 min-w-8 place-items-center rounded-md border border-[#e2e8f0] px-3 text-xs font-semibold text-[#94a3b8]"
            >
              {{ pageLabel(link, i) }}
            </span>
          </template>
        </nav>
      </div>
    </div>
  </div>

  <!-- bottom-sticky-bar 290:269 — the same dark navy bar the detail and
       bid screens carry, not a light inline band. -->
</template>
