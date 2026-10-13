<script setup lang="ts">
import { reactive } from 'vue'
import { Link as RouterLink, router, usePage } from '@inertiajs/vue3'
import { useAppUrl } from '@/composables/useAppUrl'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import { useI18n } from '@/composables/useI18n'
import LanguageSelector from '@/components/shell/LanguageSelector.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'

/*
 * Home — a single hero screen. One column of copy and calls to action beside a
 * "Global Directory" search card, on a dark navy field. It replaced the long
 * scrolling home (featured listings, testimonials, benefits): the whole page is
 * this one screen now, so it carries its own header rather than the shared
 * shell, and there is nothing below it.
 *
 * Accent green, per brand: a lighter green (#16a34a) on the dark field where a
 * deep green would not carry enough contrast, and the deep brand green (#007035)
 * on the white card where it does.
 */

const appUrl = useAppUrl()
const registerUrl = useRegisterUrl()
const page = usePage()
const { t } = useI18n()

const authUser = () => (page.props.auth as any)?.user ?? null

const props = defineProps<{
  categories: { name: string; slug: string }[]
  countries: string[]
}>()

/*
 * The search hands off to the directory, which re-reads the query string and
 * filters on it. Only the fields the visitor actually set are sent — an empty
 * `category` would otherwise match nothing. `from_home` is deliberately NOT set:
 * with it the directory ignores the category and country facets.
 */
const search = reactive({ q: '', category: '', country: '' })

function submitSearch() {
  const query: Record<string, string> = {}
  const q = search.q.trim()
  if (q) query.search = q
  if (search.category) query.category = search.category
  if (search.country) query.country = search.country

  router.get(appUrl('/businesses'), query)
}
</script>

<template>
  <div class="relative flex min-h-dvh flex-col overflow-hidden bg-[#0a1628] text-white">
    <!-- Ambient light + faint concentric arcs on the right, purely decorative. -->
    <div
      class="pointer-events-none absolute inset-0"
      aria-hidden="true"
      style="background: radial-gradient(120% 80% at 20% 0%, rgba(22,163,74,0.10), transparent 55%), radial-gradient(90% 90% at 100% 100%, rgba(120,80,40,0.12), transparent 60%);"
    />
    <svg
      class="pointer-events-none absolute -right-40 top-1/2 hidden -translate-y-1/2 text-white/[0.04] lg:block"
      width="900"
      height="900"
      viewBox="0 0 900 900"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="450" cy="450" r="200" stroke="currentColor" stroke-width="1.5" />
      <circle cx="450" cy="450" r="320" stroke="currentColor" stroke-width="1.5" />
      <circle cx="450" cy="450" r="440" stroke="currentColor" stroke-width="1.5" />
    </svg>

    <!-- ============================ header ============================ -->
    <!-- Above <main>, which is also stacked (z-10) and painted later: the
         language list drops out of this header over the hero, and at the same
         level the hero sat on top of it and took its clicks. -->
    <header class="relative z-20 border-b border-white/10">
      <div class="mx-auto flex w-full max-w-[1200px] items-center justify-between px-6 py-5 lg:px-10">
        <RouterLink :href="appUrl('/')" class="inline-flex items-center rounded-lg bg-white px-3 py-2">
          <img :src="logo" alt="LW Registry" class="h-6 w-auto object-contain" />
        </RouterLink>

        <nav class="flex items-center gap-6 text-sm font-medium text-slate-200">
          <RouterLink :href="appUrl('/about')" class="hidden transition-colors hover:text-white sm:inline">
            {{ t('nav.about') }}
          </RouterLink>
          <RouterLink :href="appUrl('/procurement')" class="hidden transition-colors hover:text-white sm:inline">
            {{ t('nav.opportunities') }}
          </RouterLink>

          <!-- The shared shell's language control. This page carries its own
               header instead of the shell, and the control went missing with
               it; a visitor choosing a language is exactly who lands here. -->
          <LanguageSelector tone="light" />

          <template v-if="authUser()">
            <RouterLink
              :href="appUrl('/sbo/dashboard')"
              class="rounded-lg border border-white/25 px-4 py-2 font-semibold text-white transition-colors hover:bg-white hover:text-[#0a1628]"
            >
              {{ t('home_hero.dashboard') }}
            </RouterLink>
          </template>
          <template v-else>
            <RouterLink :href="appUrl('/login')" class="whitespace-nowrap transition-colors hover:text-white">
              {{ t('home_hero.sign_in') }}
            </RouterLink>
            <!-- Hidden on mobile: the header has no room, and the hero's own
                 "Register your business" button already carries the CTA. -->
            <RouterLink
              :href="appUrl('/register')"
              class="hidden whitespace-nowrap rounded-lg border border-white/25 px-4 py-2 font-semibold text-white transition-colors hover:bg-white hover:text-[#0a1628] sm:inline-flex"
            >
              {{ t('home_hero.join') }}
            </RouterLink>
          </template>
        </nav>
      </div>
    </header>

    <!-- ============================= hero ============================= -->
    <main class="relative z-10 flex flex-1 items-center">
      <div class="mx-auto grid w-full max-w-[1200px] items-center gap-12 px-6 py-14 lg:grid-cols-2 lg:gap-16 lg:px-10 lg:py-20">
        <!-- left: copy + actions -->
        <div>
          <p class="flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.18em] text-[#16a34a]">
            <span class="h-px w-8 bg-[#16a34a]" aria-hidden="true" />
            {{ t('home_hero.eyebrow') }}
          </p>

          <h1 class="mt-6 font-sans text-5xl font-extrabold leading-[1.02] tracking-tight sm:text-6xl lg:text-7xl">
            <span class="block text-white">{{ t('home_hero.welcome_to') }}</span>
            <span class="block font-serif font-semibold text-[#16a34a]">{{ t('home_hero.brand') }}</span>
          </h1>

          <p class="mt-6 max-w-md text-base leading-relaxed text-slate-300 sm:text-lg">
            {{ t('home_hero.tagline') }}
          </p>

          <div class="mt-9 flex flex-wrap items-center gap-6">
            <RouterLink
              :href="registerUrl"
              class="inline-flex items-center gap-2 rounded-xl bg-[#16a34a] px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-[#16a34a]/20 transition-colors hover:bg-[#15803d]"
            >
              {{ t('home_hero.create_account') }}
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </RouterLink>

            <RouterLink
              :href="appUrl('/procurement')"
              class="group inline-flex items-center gap-1.5 border-b border-white/40 pb-0.5 text-base font-semibold text-white transition-colors hover:border-white"
            >
              {{ t('home_hero.view_live_opportunities') }}
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-[#16a34a]" aria-hidden="true">
                <path d="M7 17L17 7M9 7h8v8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </RouterLink>
          </div>
        </div>

        <!-- right: global directory search card -->
        <div class="rounded-3xl bg-[#f6f7f9] p-7 text-ink shadow-2xl shadow-black/30 sm:p-8">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[#007035]">{{ t('home_hero.global_directory') }}</p>
              <h2 class="mt-2 font-sans text-3xl font-bold leading-tight text-[#0f172a]">{{ t('home_hero.what_are_you_looking_for') }}</h2>
            </div>
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-[#0a1628]" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <circle cx="11" cy="11" r="7" stroke="#fff" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" stroke="#fff" stroke-width="2" stroke-linecap="round" />
              </svg>
            </span>
          </div>

          <form class="mt-6" @submit.prevent="submitSearch">
            <label class="block">
              <span class="text-sm font-semibold text-[#334155]">{{ t('home_hero.search_label') }}</span>
              <input
                v-model="search.q"
                type="text"
                :placeholder="t('home_hero.search_placeholder')"
                class="mt-2 w-full rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink placeholder:text-[#94a3b8] focus:border-[#007035] focus:outline-none"
              />
            </label>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-sm font-semibold text-[#334155]">{{ t('home_hero.category') }}</span>
                <select
                  v-model="search.category"
                  class="mt-2 w-full rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-[#007035] focus:outline-none"
                >
                  <option value="">{{ t('home_hero.all_categories') }}</option>
                  <option v-for="c in props.categories" :key="c.slug" :value="c.name">{{ c.name }}</option>
                </select>
              </label>

              <label class="block">
                <span class="text-sm font-semibold text-[#334155]">{{ t('home_hero.country') }}</span>
                <select
                  v-model="search.country"
                  class="mt-2 w-full rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-[#007035] focus:outline-none"
                >
                  <option value="">{{ t('home_hero.all_countries') }}</option>
                  <option v-for="c in props.countries" :key="c" :value="c">{{ c }}</option>
                </select>
              </label>
            </div>

            <button
              type="submit"
              class="mt-5 flex w-full items-center justify-between rounded-xl bg-[#0a1628] px-5 py-4 text-base font-semibold text-white transition-colors hover:bg-[#0f2135]"
            >
              {{ t('home_hero.search_the_directory') }}
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="text-[#16a34a]" aria-hidden="true">
                <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </form>

          <p class="mt-4 text-xs text-[#64748b]">
            {{ t('home_hero.popular') }}
            <RouterLink :href="appUrl('/businesses?search=Oil%20%26%20Gas')" class="font-semibold text-[#007035] hover:underline">Oil &amp; Gas</RouterLink>
            ·
            <RouterLink :href="appUrl('/businesses?search=Faith-Based%20Schools')" class="font-semibold text-[#007035] hover:underline">Faith-Based Schools</RouterLink>
            ·
            <RouterLink :href="appUrl('/businesses?search=Hospitality')" class="font-semibold text-[#007035] hover:underline">Hospitality</RouterLink>
          </p>
        </div>
      </div>
    </main>
  </div>
</template>
