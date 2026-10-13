<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import { useI18n } from '@/composables/useI18n'
import AdSlot from '@/components/ads/AdSlot.vue'

const { t, td } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

/*
 * Global footer — Figma Footer Section 265:270 / Group 19 (262:11504),
 * 1280x924 on a dark navy field.
 *
 * Three bands:
 *   1. "Popular SBO Categories" — 40 category links in 5 columns (262:11507
 *      and 262:11544). Read verbatim from the design's text nodes.
 *   2. Logo + five link columns (262:11567).
 *   3. Closing CTA bar with the two buttons and the 15 min / 3x stats
 *      (262:11594).
 *
 * The logo export is dark ink on transparency (the header variant); no white
 * variant came back from Figma, so it is inverted with a CSS filter here.
 * Column counts collapse below lg — the designs have no mobile footer.
 */

const categories = [
  ['Agriculture & Agro Trade', 'Arts', 'Aviation', 'Beauty & Personal Care', 'Bookstores & Publishers', 'Childcare & Family Services', 'Churches & Ministries', 'Cleaning & Maintenance'],
  ['Construction & Real Estate', 'Consulting & Advisory', 'Education', 'Education & Training', 'Engineering & Manufacturing', 'Entertainment', 'Events & Conferences', 'Faith-Based NGOs'],
  ['Faith-Based Schools', 'Fashion & Apparel', 'Finance & Banking', 'FMCG', 'Graphics & Printing', 'Health & Medical Services', 'Hotels & Hospitality', 'Interior Design'],
  ['Legal Consultants', 'Manufacturing', 'Marketing & Advertising', 'Media', 'Media & Communications', 'Music & Entertainment', 'Non-Profits & NGOs', 'Oil & Gas'],
  ['Photography & Media', 'Restaurants & Cafes', 'Retail & Wholesale', 'Software Development', 'Sports & Recreation', 'Technology Services', 'Transportation & Logistics', 'Travel & Tourism'],
]

/** The category browse page, narrowed to one category. */
function categoryUrl(name: string): string {
  return `/categories?category=${encodeURIComponent(name)}`
}

const columns = [
  {
    title: t('app_footer.popular_local_searches'),
    links: [
      // These three are groupings rather than `categories` rows, so they run a
      // search instead of filtering on a category that does not exist.
      { label: t('app_footer.churches_ministries_schools'), to: '/categories?search=Church' },
      { label: t('app_footer.restaurants_cafes_events'), to: '/categories?search=Restaurant' },
      { label: t('app_footer.clinics_pharmacies_dentists'), to: '/categories?search=Health' },
    ],
  },
  { title: t('app_footer.advertisers'), links: [{ label: t('app_footer.advertise_with_us'), to: '/advertise' }] },
  {
    title: t('app_footer.about_us'),
    links: [
      { label: t('app_footer.about_loveworld_registry'), to: '/about' },
      { label: t('app_footer.our_mission'), to: '/about' },
    ],
  },
  {
    title: t('app_footer.quick_links'),
    links: [
      { label: t('app_footer.browse_by_category'), to: '/categories' },
      { label: t('app_footer.browse_by_location'), to: '/businesses' },
      { label: t('app_footer.give_feedback'), to: '/feedback' },
    ],
  },
  {
    title: t('app_footer.mobile_tools'),
    links: [
      { label: t('app_footer.follow_us_on_facebook'), to: '/' },
      { label: t('app_footer.follow_us_on_instagram'), to: '/' },
      { label: t('app_footer.follow_us_on_x'), to: '/' },
    ],
  },
]
</script>

<template>
  <!-- Global – Above Footer. Site-wide, so it lives here rather than in each
       page; renders nothing until an ad is assigned to the slot. -->
  <AdSlot name="global_footer" wrapper-class="container-page py-6" />

  <footer class="bg-surface-dark text-ink-inverse">
    <!-- ============================ 1. Popular SBO Categories ============= -->
    <div class="container-page py-10">
      <h2 class="font-sans text-xs font-semibold">{{ t('app_footer.popular_sbo_categories') }}</h2>
      <div class="mt-5 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-5 [&>*]:min-w-0">
        <ul v-for="(col, i) in categories" :key="i" class="space-y-2">
          <li v-for="c in col" :key="c">
            <!-- Every one of these 40 labels is a real `categories.name`, and
                 /categories already filters on ?category=. They all pointed at
                 the unfiltered index, so forty distinct links went to one page. -->
            <RouterLink
              :href="categoryUrl(c)"
              class="text-2xs text-white/60 transition-colors hover:text-white"
            >
              <!-- `c` stays English in the href, because it is the value
                   ?category= filters on; only the text is translated. -->
              {{ td(c, 'category') }}
            </RouterLink>
          </li>
        </ul>
      </div>
    </div>

    <!-- ============================ 2. Logo + link columns =============== -->
    <div class="container-page border-t border-white/10 py-10">
      <img :src="logo" alt="LW Registry" class="h-8 w-auto object-contain brightness-0 invert" />

      <div class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-5 [&>*]:min-w-0">
        <div v-for="col in columns" :key="col.title">
          <h3 class="font-sans text-2xs font-semibold text-white">{{ col.title }}</h3>
          <ul class="mt-3 space-y-2">
            <li v-for="link in col.links" :key="link.label">
              <RouterLink :href="link.to" class="text-2xs text-white/60 transition-colors hover:text-white">
                {{ link.label }}
              </RouterLink>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- ============================ 3. Closing CTA bar =================== -->
    <div class="container-page pb-10">
      <div class="flex flex-col gap-6 rounded-lg bg-white/5 p-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-start gap-3">
          <span class="grid size-9 shrink-0 place-items-center rounded-md bg-white/10" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4">
              <path d="M3 11l18-8-8 18-2-7-8-3z" stroke-linejoin="round" />
            </svg>
          </span>
          <div>
            <p class="text-xs font-semibold">
              {{ t('app_footer.make_your') }} <span class="text-secondary">{{ t('app_footer.business_visible') }}</span> {{ t('app_footer.to_the_world') }}
            </p>
            <p class="mt-1 max-w-md text-2xs text-white/60">
              {{ t('app_footer.join_thousands_of_verified_businesses_acce') }}
            </p>
          </div>
        </div>

        <!-- Each label stays on one line (whitespace-nowrap below), but the
             pair must be allowed to stack: as a shrink-0 row their combined
             min-content width set a ~500px floor on the whole document. -->
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-nowrap sm:shrink-0">
          <RouterLink
            :href="registerUrl"
            class="inline-flex w-full items-center justify-center gap-1.5 rounded-md bg-secondary px-4 py-2.5 text-2xs font-semibold whitespace-nowrap text-surface-dark hover:bg-secondary-hover sm:w-auto"
          >
            {{ t('app_footer.register_your_business') }}
          </RouterLink>
          <RouterLink
            href="/procurement/new"
            class="inline-flex w-full items-center justify-center gap-1.5 rounded-md bg-white/10 px-4 py-2.5 text-2xs font-semibold whitespace-nowrap text-white hover:bg-white/20 sm:w-auto"
          >
            {{ t('app_footer.publish_a_bid_or_rfp') }}
          </RouterLink>
        </div>

        <dl class="flex gap-8">
          <div>
            <dt class="text-lg font-semibold text-secondary">{{ t('app_footer.15_min') }}</dt>
            <dd class="text-2xs text-white/60">{{ t('app_footer.average_profile_setup') }}</dd>
          </div>
          <div>
            <dt class="text-lg font-semibold text-secondary">3x</dt>
            <dd class="text-2xs text-white/60">{{ t('app_footer.more_visibility_amp_engagement') }}</dd>
          </div>
        </dl>
      </div>

      <!-- DB-IP's country database picks a first-time visitor's language; its
           licence (CC BY 4.0) asks for this credit. -->
      <p class="mt-6 text-center text-2xs text-white/40">
        {{ t('app_footer.ip_geolocation_by') }}
        <a href="https://db-ip.com" rel="noopener" target="_blank" class="underline hover:text-white/70">DB-IP</a>
      </p>
    </div>
  </footer>
</template>
