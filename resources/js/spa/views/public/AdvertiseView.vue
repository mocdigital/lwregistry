<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Link as RouterLink, useForm, usePage } from '@inertiajs/vue3'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import iconUserPlus from '@/assets/figma/icons/adv-user-plus.svg'
import iconUploadCloud from '@/assets/figma/icons/adv-upload-cloud.svg'
import iconCreditCard from '@/assets/figma/icons/adv-credit-card.svg'

// Placement previews — the design draws these as vector browser/phone
// mockups with the ad slot highlighted. Exported at 2x from 80:28 and cropped
// per card, so each slot shows where its banner actually lands.
import pvHeroBillboard from '@/assets/figma/adv/home-hero-billboard.png'
import pvMidLeaderboard from '@/assets/figma/adv/home-mid-leaderboard.png'
import pvDirectorySidebar from '@/assets/figma/adv/directory-sidebar.png'
import pvDirectoryInfeed from '@/assets/figma/adv/directory-infeed.png'
import pvCompanySidebar from '@/assets/figma/adv/company-sidebar.png'
import pvCompanyBelowAbout from '@/assets/figma/adv/company-below-about.png'
import pvDashboardTop from '@/assets/figma/adv/dashboard-top.png'
import pvGlobalAboveFooter from '@/assets/figma/adv/global-above-footer.png'
import pvMobileContainer from '@/assets/figma/adv/mobile-container.png'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: PublicLayout })

/*
 * Advertise with Us — Figma 80:5 "advertise-with-us" (1280x5260).
 *   hero-section 80:17 (503)               — VIDEO fill + #1e293b
 *   ad-placements-section 80:28 (1349)     — 9 placement slots
 *   campaign-steps-section 80:263 (500)    — 3 numbered steps
 *   ad-placement-overview 78:3194 (1005)   — rate table
 *   inquiry-section 80:295 (766)           — inquiry form
 *   benefits 78:3576
 *
 * Gutters here are 120px, not the 80px used elsewhere — the design pads this
 * page wider, so `section-x` is not used.
 */

// ad-placements-section 80:28 — slot, price and pixel size as drawn
type Slot = {
  value: string
  label: string
  group: string | null
  width: number
  height: number
  /** Espees per day, for the builder's live cost. */
  rate: number
  dimensions: string
  mobileWidth: number | null
  mobileHeight: number | null
  mobileDimensions: string | null
}

const props = defineProps<{
  /** Exactly the placements POST /advertise/inquiry accepts; config/ads.php. */
  slots: Slot[]
  /** Null for a guest — the endpoint refuses them, so the form is not shown. */
  advertiser: { name: string; email: string; kcHandle: string | null } | null
  /** True only for a signed-in member who owns a fully verified business. */
  verified: boolean
  turnstileSiteKey: string | null
}>()

/*
 * The nine placement cards were hardcoded, including their pixel sizes, so
 * they could drift from the slots the endpoint actually accepts. The preview
 * artwork stays local — it is design, not data — and is matched to a slot by
 * position, since `ads.slots` carries no imagery.
 */
const previews = [
  pvHeroBillboard,
  pvMidLeaderboard,
  pvDirectorySidebar,
  pvDirectoryInfeed,
  pvCompanySidebar,
  pvCompanyBelowAbout,
  pvDashboardTop,
  pvGlobalAboveFooter,
  pvMobileContainer,
]

const registerUrl = useRegisterUrl()

const page = usePage()
/*
 * The endpoint's own confirmation, not the generic `status` — nothing ever
 * flashed `status` here, so the banner could not appear however well the
 * inquiry went.
 */
const success = computed(() => page.props.flash.advertisingInquiry)
const authMessage = computed(() => page.props.flash.advertisingAuthMessage)

const form = useForm({
  name: props.advertiser?.name ?? '',
  kc_handle: props.advertiser?.kcHandle ?? '',
  email: props.advertiser?.email ?? '',
  ad_location: props.slots[0]?.value ?? '',
  message: '',
})

const selectedSlot = computed(
  () => props.slots.find((s) => s.value === form.ad_location) ?? null,
)

function submit() {
  form.post('/advertise/inquiry', {
    preserveScroll: true,
    onSuccess: () => form.reset('message'),
  })
}

const placements = computed(() =>
  props.slots.map((slot, i) => ({
    ...slot,
    preview: previews[i % previews.length],
    // A mobile-only slot is drawn in a phone mockup, which is taller.
    tall: slot.width <= 360,
  })),
)

/** "Select Position" scrolls to the form with that placement chosen. */
function choosePlacement(value: string) {
  form.ad_location = value
  document.getElementById('advertise-inquiry')?.scrollIntoView({ behavior: 'smooth' })
}

/*
 * The self-service builder. Pick a placement and a date range, upload the
 * artwork, and the cost is worked out live — the placement's daily rate times
 * the days chosen. Submitting hands straight off to Espees; the server prices
 * it again from the same rate, so the figure shown here is a preview, not a
 * promise the browser gets to make.
 */
const builder = useForm<{
  slot: string
  title: string
  cta_text: string
  cta_url: string
  starts_at: string
  ends_at: string
  desktop_creative: File | null
  mobile_creative: File | null
}>({
  slot: '',
  title: '',
  cta_text: 'Learn More',
  cta_url: '',
  starts_at: '',
  ends_at: '',
  desktop_creative: null,
  mobile_creative: null,
})

const builderSlot = computed(() => props.slots.find((s) => s.value === builder.slot) ?? null)

const builderDays = computed(() => {
  if (!builder.starts_at || !builder.ends_at) return 0
  const start = new Date(builder.starts_at).getTime()
  const end = new Date(builder.ends_at).getTime()
  const days = Math.round((end - start) / 86_400_000)
  return days > 0 ? days : 0
})

const builderCost = computed(() => (builderSlot.value?.rate ?? 0) * builderDays.value)

const today = new Date().toISOString().slice(0, 10)

function onCreative(event: Event, field: 'desktop_creative' | 'mobile_creative') {
  builder[field] = (event.target as HTMLInputElement).files?.[0] ?? null
}

function submitCampaign() {
  builder.post('/advertise/campaigns/submit', { forceFormData: true })
}

/*
 * The builder is revealed on request, not shown by default: a verified member
 * clicks "Submit advertisement" and the form appears. A guest or an unverified
 * member is scrolled to the same section, where the prompt to sign in or get
 * verified stands in place of the form.
 */
const showBuilder = ref(false)

function revealBuilder() {
  if (props.verified) showBuilder.value = true
  nextTick(() => {
    document.getElementById('builder')?.scrollIntoView({ behavior: 'smooth' })
  })
}

/*
 * Espees hand-off. The server flashes the checkout URL rather than answering
 * with Inertia::location, whose 409 header the proxy strips on this multipart
 * post, so the browser is navigated here with a plain assignment nothing in
 * the middle can drop.
 */
watch(
  () => page.props.flash?.espeesRedirect as string | null | undefined,
  (url) => {
    if (url) window.location.href = url
  },
  { immediate: true },
)

// campaign-steps-section 80:267
const steps = [
  {
    n: '01',
    icon: iconUserPlus,
    title: t('advertise.register_or_sign_in'),
    body: t('advertise.create_your_sponsor_account_in_seconds_sec'),
  },
  {
    n: '02',
    icon: iconUploadCloud,
    title: t('advertise.upload_your_banner_creative'),
    body: t('advertise.provide_raw_images_or_assets_specify_targe'),
  },
  {
    n: '03',
    icon: iconCreditCard,
    title: t('advertise.pay_and_activate'),
    body: t('advertise.complete_your_safe_b2b_payment_securely_ou'),
  },
]

// ad-placement-overview-section 78:3194 — rate table
const rates = [
  { unit: t('advertise.leaderboard_banner'), dims: '728×90 px', where: t('advertise.top_of_page_high_visibility'), rate: '1,200/mo' },
  { unit: t('advertise.large_leaderboard'), dims: '970×90 px', where: t('advertise.premium_top_placement'), rate: '1,800/mo' },
  { unit: t('advertise.medium_rectangle'), dims: '300×250 px', where: t('advertise.sidebar_in_content'), rate: '800/mo' },
  { unit: t('advertise.wide_skyscraper'), dims: '160×600 px', where: t('advertise.sidebar_persistent_scroll'), rate: '950/mo' },
  { unit: t('advertise.half_page'), dims: '300×600 px', where: t('advertise.sidebar_maximum_impact'), rate: '1,400/mo' },
  { unit: t('advertise.billboard'), dims: '970×250 px', where: t('advertise.hero_section_takeover'), rate: '2,500/mo' },
  { unit: t('advertise.mobile_banner_2'), dims: '320×50 px', where: t('advertise.mobile_header'), rate: '600/mo' },
  { unit: t('advertise.mobile_interstitial'), dims: '320×480 px', where: t('advertise.full_screen_mobile'), rate: '1,100/mo' },
  { unit: t('advertise.native_ad_unit'), dims: t('advertise.flexible_size'), where: t('advertise.in_feed_sponsored_content'), rate: '2,000/mo' },
  { unit: t('advertise.sticky_footer'), dims: '728×90 px', where: t('advertise.fixed_bottom_bar'), rate: '1,500/mo' },
]
</script>

<template>
  <!-- ========================================== hero-section 80:17 ======== -->
  <section class="relative overflow-hidden bg-[#1e293b]">
    <HeroVideo overlay="bg-[#1e293b]/78" />

    <div class="relative flex flex-col items-center gap-8 px-5 pt-16 pb-20 md:px-10 xl:px-[120px] xl:pt-24 xl:pb-[120px]">
      <div class="flex flex-col items-center gap-4">
        <span class="rounded-full bg-[rgba(0,112,53,0.1)] px-3 py-1.5">
          <span class="text-xs font-semibold tracking-wide text-[#007035] uppercase">
            {{ t('advertise.b2b_directory_sponsorship') }}
          </span>
        </span>
        <h1 class="text-center font-serif text-4xl font-bold text-white xl:text-5xl">
          {{ t('advertise.advertise_with_us') }}
        </h1>
        <p class="max-w-[680px] text-center text-lg leading-normal text-[#94a3b8]">
          {{ t('advertise.position_your_brand_directly_in_front_of_t') }}
        </p>
      </div>

      <div class="flex flex-wrap justify-center gap-4">
        <button
          type="button"
          class="btn-primary rounded-md px-5 sm:px-7 py-4 text-base font-semibold"
          @click="revealBuilder"
        >
          {{ t('advertise.submit_advertisement') }}
        </button>
        <RouterLink
          href="#inquiry"
          class="rounded-md border-[1.5px] border-white px-5 sm:px-7 py-4 text-base font-semibold text-white hover:bg-white/10"
        >
          {{ t('advertise.find_a_solution_for_your_audience') }}
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ================================= ad-placements-section 80:28 ======== -->
  <section id="placements" class="bg-white px-5 py-10 sm:py-16 md:px-10 xl:px-[120px] xl:py-24">
    <header class="flex flex-col items-center gap-3 text-center">
      <p class="text-xs font-semibold tracking-wide text-[#007035] uppercase">{{ t('advertise.ad_placements') }}</p>
      <h2 class="font-serif text-3xl font-bold text-[#0f172a] xl:text-4xl">
        {{ t('advertise.see_where_your_banner_appears') }}
      </h2>
      <p class="max-w-[720px] text-base leading-normal text-[#475569]">
        {{ t('advertise.choose_from_highly_optimized_layout_slots') }}
      </p>
    </header>

    <ul class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
      <li
        v-for="p in placements"
        :key="p.value"
        class="flex h-full flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6"
      >
        <!-- The mobile slot uses a 140x180 phone mockup; the rest use a
             250x140 browser mockup, so its preview box is taller. -->
        <div
          class="grid place-items-center rounded-lg bg-[#f8fafc]"
          :class="p.tall ? 'h-[180px]' : 'h-[140px]'"
        >
          <img
            :src="p.preview"
            :alt="t('advertise.placement_preview', { name: p.label })"
            class="max-h-full max-w-full object-contain"
          />
        </div>
        <div class="flex flex-col gap-1">
          <h3 class="font-display text-base font-semibold text-[#0f172a]">{{ p.label }}</h3>
          <p class="text-2xs text-[#94a3b8]">
            {{ p.dimensions
            }}<template v-if="p.mobileDimensions"> · {{ p.mobileDimensions }}</template>
          </p>
        </div>
        <div class="mt-auto flex items-center justify-between gap-3">
          <!--
            The cards carried a monthly price each ("$450/mo"). There is no
            price on `ads.slots` and no rate column anywhere, so quoting one
            per card would be inventing it; the rate table below this section
            is the published pricing. See INTEGRATION.md.
          -->
          <button
            type="button"
            class="btn-primary rounded-md px-3 py-2 text-2xs font-semibold"
            @click="choosePlacement(p.value)"
          >
            {{ t('advertise.select_position') }}
          </button>
        </div>
      </li>
    </ul>
  </section>

  <!-- =============================== campaign-steps-section 80:263 ======== -->
  <section class="bg-[#f8fafc] px-5 py-10 sm:py-16 md:px-10 xl:px-[120px] xl:py-24">
    <header class="flex flex-col items-center gap-3 text-center">
      <p class="text-xs font-semibold tracking-wide text-[#007035] uppercase">{{ t('advertise.seamless_workflow') }}</p>
      <h2 class="font-serif text-3xl font-bold text-[#0f172a] xl:text-4xl">
        {{ t('advertise.create_and_pay_for_your_ad_campaign') }}
      </h2>
    </header>

    <ol class="mt-16 grid gap-12 md:grid-cols-3 [&>*]:min-w-0">
      <li v-for="s in steps" :key="s.n" class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
          <span class="grid size-10 place-items-center rounded-full border border-[#e2e8f0] bg-white">
            <img :src="s.icon" alt="" class="size-[18px]" aria-hidden="true" />
          </span>
          <span class="font-display text-xl text-[#94a3b8]">{{ s.n }}</span>
        </div>
        <div class="flex flex-col gap-2">
          <h3 class="font-display text-lg font-semibold text-[#0f172a]">{{ s.title }}</h3>
          <p class="text-sm leading-normal text-[#475569]">{{ s.body }}</p>
        </div>
      </li>
    </ol>
  </section>

  <!-- ================================= campaign builder =================== -->
  <section id="builder" class="scroll-mt-24 bg-white px-5 py-10 sm:py-16 md:px-10 xl:px-[120px] xl:py-24">
    <header class="flex flex-col items-center gap-3 text-center">
      <p class="text-xs font-semibold tracking-wide text-[#007035] uppercase">{{ t('advertise.seamless_workflow') }}</p>
      <h2 class="font-serif text-3xl font-bold text-[#0f172a] xl:text-4xl">{{ t('advertise.submit_advertisement') }}</h2>
      <p class="max-w-[640px] text-base leading-normal text-[#475569]">
        Choose a placement and the dates you want it to run. The cost is worked out from the
        placement's daily rate, and you pay with Espees.
      </p>
    </header>

    <!-- Members only, like the inquiry form: the endpoint needs an owner. -->
    <div
      v-if="!props.advertiser"
      class="mx-auto mt-10 max-w-[560px] rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-8 text-center"
    >
      <p class="text-base font-semibold text-[#0f172a]">{{ t('advertise.sign_in_to_advertise') }}</p>
      <p class="mt-2 text-sm text-[#475569]">{{ t('advertise.you_need_a_registry_account_to_submit') }}</p>
      <RouterLink :href="registerUrl" class="btn-primary mt-5 inline-block rounded-md px-6 py-3 text-sm font-semibold">
        {{ t('advertise.create_an_account') }}
      </RouterLink>
    </div>

    <!-- Signed in, but not a verified business yet: the builder is open to
         verified members, so send them to finish verification first. -->
    <div
      v-else-if="!props.verified"
      class="mx-auto mt-10 max-w-[560px] rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-8 text-center"
    >
      <p class="text-base font-semibold text-[#0f172a]">Get verified to advertise</p>
      <p class="mt-2 text-sm text-[#475569]">
        The campaign builder is open to members with a verified business. Finish your
        registration and verification, then you can submit an advertisement here.
      </p>
      <RouterLink href="/dashboard" class="btn-primary mt-5 inline-block rounded-md px-6 py-3 text-sm font-semibold">
        Go to my dashboard
      </RouterLink>
    </div>

    <!-- Verified, but the form is revealed on request — the member clicks to
         start, here or from the hero button. -->
    <div
      v-else-if="!showBuilder"
      class="mx-auto mt-10 max-w-[560px] rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-8 text-center"
    >
      <p class="text-base font-semibold text-[#0f172a]">Ready to run a campaign?</p>
      <p class="mt-2 text-sm text-[#475569]">
        Choose a placement and the dates you want it to run, upload your artwork, and pay
        with Espees.
      </p>
      <button
        type="button"
        class="btn-primary mt-5 inline-block rounded-md px-6 py-3 text-sm font-semibold"
        @click="revealBuilder"
      >
        {{ t('advertise.submit_advertisement') }}
      </button>
    </div>

    <form
      v-else
      class="mx-auto mt-10 grid max-w-[960px] gap-8 lg:grid-cols-[minmax(0,1fr)_320px]"
      @submit.prevent="submitCampaign"
    >
      <!-- The fields -->
      <div class="space-y-5">
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Placement</span>
          <select
            v-model="builder.slot"
            class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          >
            <option value="" disabled>Select where your ad runs…</option>
            <option v-for="s in props.slots" :key="s.value" :value="s.value">
              {{ s.label }} — {{ s.dimensions }} · {{ s.rate }} Espees/day
            </option>
          </select>
          <span v-if="builder.errors.slot" class="mt-1 block text-xs text-danger">{{ builder.errors.slot }}</span>
        </label>

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Title</span>
          <input
            v-model="builder.title"
            type="text"
            maxlength="255"
            placeholder="A short name for your campaign"
            class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          />
          <span v-if="builder.errors.title" class="mt-1 block text-xs text-danger">{{ builder.errors.title }}</span>
        </label>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Button text</span>
            <input
              v-model="builder.cta_text"
              type="text"
              maxlength="60"
              placeholder="Learn More"
              class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
            <span v-if="builder.errors.cta_text" class="mt-1 block text-xs text-danger">{{ builder.errors.cta_text }}</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Button link</span>
            <input
              v-model="builder.cta_url"
              type="url"
              placeholder="https://…"
              class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
            <span v-if="builder.errors.cta_url" class="mt-1 block text-xs text-danger">{{ builder.errors.cta_url }}</span>
          </label>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Starts on</span>
            <input
              v-model="builder.starts_at"
              type="date"
              :min="today"
              class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
            <span v-if="builder.errors.starts_at" class="mt-1 block text-xs text-danger">{{ builder.errors.starts_at }}</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">Ends on</span>
            <input
              v-model="builder.ends_at"
              type="date"
              :min="builder.starts_at || today"
              class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
            <span v-if="builder.errors.ends_at" class="mt-1 block text-xs text-danger">{{ builder.errors.ends_at }}</span>
          </label>
        </div>

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">
            Creative image
            <span v-if="builderSlot" class="font-normal text-[#94a3b8]">— {{ builderSlot.dimensions }} or larger, same shape</span>
          </span>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="w-full rounded-lg border border-dashed border-[#cbd5e1] bg-[#f8fafc] px-3 py-2.5 text-sm text-[#475569] file:mr-3 file:rounded-md file:border-0 file:bg-surface-dark file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
            @change="onCreative($event, 'desktop_creative')"
          />
          <span v-if="builder.errors.desktop_creative" class="mt-1 block text-xs text-danger">{{ builder.errors.desktop_creative }}</span>
        </label>

        <label v-if="builderSlot?.mobileDimensions" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-[#0f172a]">
            Mobile creative <span class="font-normal text-[#94a3b8]">— {{ builderSlot.mobileWidth }}×{{ builderSlot.mobileHeight }} px or larger, same shape</span>
          </span>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="w-full rounded-lg border border-dashed border-[#cbd5e1] bg-[#f8fafc] px-3 py-2.5 text-sm text-[#475569] file:mr-3 file:rounded-md file:border-0 file:bg-surface-dark file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
            @change="onCreative($event, 'mobile_creative')"
          />
          <span v-if="builder.errors.mobile_creative" class="mt-1 block text-xs text-danger">{{ builder.errors.mobile_creative }}</span>
        </label>
      </div>

      <!-- Live cost + submit -->
      <aside class="lg:sticky lg:top-24 h-fit rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-6">
        <p class="text-xs font-semibold tracking-wide text-[#475569] uppercase">Estimated cost</p>
        <p class="mt-2 font-serif text-4xl font-bold text-[#0f172a]">
          {{ builderCost.toLocaleString() }} <span class="text-lg font-semibold text-[#475569]">Espees</span>
        </p>
        <ul class="mt-4 space-y-2 border-t border-[#e2e8f0] pt-4 text-sm text-[#475569]">
          <li class="flex justify-between"><span>Daily rate</span><span class="font-semibold text-[#0f172a]">{{ builderSlot ? builderSlot.rate + ' Espees' : '—' }}</span></li>
          <li class="flex justify-between"><span>Duration</span><span class="font-semibold text-[#0f172a]">{{ builderDays || '—' }} {{ builderDays === 1 ? 'day' : 'days' }}</span></li>
        </ul>
        <button
          type="submit"
          :disabled="builder.processing || builderCost <= 0"
          class="btn-primary mt-6 flex w-full items-center justify-center gap-2 rounded-md px-6 py-3.5 text-sm font-semibold disabled:opacity-50"
        >
          <img :src="iconCreditCard" alt="" class="size-4" aria-hidden="true" />
          {{ builder.processing ? 'Starting checkout…' : 'Submit & pay with Espees' }}
        </button>
        <p class="mt-3 text-2xs leading-relaxed text-[#94a3b8]">
          Your ad is reviewed by the registry team before it goes live. Payment is taken now; the
          placement starts on your chosen date once approved.
        </p>
      </aside>
    </form>
  </section>

  <!-- ========================= ad-placement-overview-section 78:3194 ====== -->
  <section class="bg-white px-5 py-10 sm:py-16 md:px-10 xl:px-[120px] xl:py-24">
    <header class="flex flex-col gap-3">
      <h2 class="font-serif text-3xl font-bold text-[#0f172a] xl:text-4xl">
        {{ t('advertise.available_ad_sizes_amp_placements') }}
      </h2>
      <p class="max-w-[720px] text-base leading-normal text-[#475569]">
        {{ t('advertise.transparent_flat_rate_monthly_pricing_sele') }}
      </p>
    </header>

    <div class="mt-10 overflow-x-auto rounded-2xl border border-[#e2e8f0]">
      <table class="w-full min-w-[720px] border-collapse text-left">
        <thead>
          <tr class="bg-[#f8fafc]">
            <th class="px-6 py-4 text-2xs font-semibold tracking-wide text-[#475569] uppercase">{{ t('advertise.ad_unit') }}</th>
            <th class="px-6 py-4 text-2xs font-semibold tracking-wide text-[#475569] uppercase">{{ t('advertise.dimensions') }}</th>
            <th class="px-6 py-4 text-2xs font-semibold tracking-wide text-[#475569] uppercase">
              {{ t('advertise.placement_amp_visibility') }}
            </th>
            <th class="px-6 py-4 text-right text-2xs font-semibold tracking-wide text-[#475569] uppercase">
              {{ t('advertise.rate_month') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rates" :key="r.unit" class="border-t border-[#e2e8f0]">
            <td class="px-6 py-4 text-base font-semibold text-[#0f172a]">{{ r.unit }}</td>
            <td class="px-6 py-4 text-sm text-[#475569]">{{ r.dims }}</td>
            <td class="px-6 py-4 text-sm text-[#475569]">{{ r.where }}</td>
            <td class="px-6 py-4 text-right text-base font-semibold text-[#007035]">
              {{ r.rate }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ===================================== inquiry-section 80:295 ========= -->
  <section id="inquiry" class="bg-white px-5 py-10 sm:py-16 md:px-10 xl:px-[120px] xl:py-24">
    <header class="flex flex-col items-center gap-3 text-center">
      <p class="text-xs font-semibold tracking-wide text-[#007035] uppercase">{{ t('advertise.custom_partnerships') }}</p>
      <h2 class="font-serif text-3xl font-bold text-[#0f172a] xl:text-4xl">
        {{ t('advertise.send_an_advertising_inquiry') }}
      </h2>
      <p class="max-w-[540px] text-base leading-normal text-[#475569]">
        {{ t('advertise.have_questions_about_tailored_packages_bul') }}
      </p>
    </header>

    <!--
      Guests are bounced by the endpoint with `advertising_auth_required`
      rather than having their inquiry accepted, so they get the prompt the
      Blade screen showed instead of a form that cannot submit.
    -->
    <div
      v-if="!props.advertiser"
      id="advertise-inquiry"
      class="mx-auto mt-12 flex w-full max-w-[640px] flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 text-center"
    >
      <p class="font-display text-lg font-semibold text-[#0f172a]">
        {{ t('advertise.register_your_business_to_send_an_inquiry') }}
      </p>
      <p class="text-sm text-[#475569]">
        {{ authMessage ?? t('advertise.payment_is_not_required_to_ask_about_adver') }}
      </p>
      <RouterLink
        :href="registerUrl"
        class="btn-primary mx-auto h-12 rounded-md px-5 sm:px-8 leading-[3rem] text-base font-semibold"
      >
        {{ t('advertise.register_your_business') }}
      </RouterLink>
    </div>

    <form
      v-else
      id="advertise-inquiry"
      class="mx-auto mt-12 flex w-full max-w-[640px] flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 shadow-[0px_4px_12px_rgba(15,23,42,0.05)]"
      @submit.prevent="submit"
    >
      <p
        v-if="success"
        class="rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
        role="status"
      >
        {{ success }}
      </p>

      <div class="grid gap-6 sm:grid-cols-2 [&>*]:min-w-0">
        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('advertise.your_name') }}</span>
          <input
            v-model="form.name"
            name="name"
            class="h-11 rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-4 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          />
          <span v-if="form.errors.name" class="text-xs text-danger">{{ form.errors.name }}</span>
        </label>

        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('advertise.kingschat_handle') }}</span>
          <input
            v-model="form.kc_handle"
            name="kc_handle"
            :placeholder="t('advertise.yourhandle')"
            class="h-11 rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-4 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
          <span v-if="form.errors.kc_handle" class="text-xs text-danger">
            {{ form.errors.kc_handle }}
          </span>
        </label>
      </div>

      <label class="flex flex-col gap-2">
        <span class="text-xs font-semibold text-[#0f172a]">{{ t('advertise.email_address') }}</span>
        <input
          v-model="form.email"
          name="email"
          type="email"
          class="h-11 rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-4 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
        />
        <span v-if="form.errors.email" class="text-xs text-danger">{{ form.errors.email }}</span>
      </label>

      <label class="flex flex-col gap-2">
        <span class="text-xs font-semibold text-[#0f172a]">{{ t('advertise.placement') }}</span>
        <select
          v-model="form.ad_location"
          name="ad_location"
          class="max-w-full h-11 rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-4 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
        >
          <option v-for="s in props.slots" :key="s.value" :value="s.value">
            {{ s.label }} — {{ s.dimensions }}
          </option>
        </select>
        <!-- The exact artwork the chosen slot needs; this was the Blade
             screen's live banner-dimension helper. -->
        <span class="text-xs text-[#64748b]">
          <template v-if="selectedSlot">
            Desktop banner: {{ selectedSlot.dimensions }}.
            {{
              selectedSlot.mobileDimensions
                ? t('advertise.mobile_banner', { size: selectedSlot.mobileDimensions })
                : t('advertise.no_mobile_banner_required')
            }}
          </template>
          <template v-else>{{ t('advertise.select_a_placement_to_see_the_required_ban') }}</template>
        </span>
        <span v-if="form.errors.ad_location" class="text-xs text-danger">
          {{ form.errors.ad_location }}
        </span>
      </label>

      <label class="flex flex-col gap-2">
        <span class="text-xs font-semibold text-[#0f172a]">{{ t('advertise.message_details') }}</span>
        <textarea
          v-model="form.message"
          name="message"
          rows="6"
          :placeholder="t('advertise.hello_we_represent_a_growing_cloud_logisti')"
          class="h-[140px] rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-4 text-sm leading-snug text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
        />
        <span v-if="form.errors.message" class="text-xs text-danger">
          {{ form.errors.message }}
        </span>
      </label>

      <!-- Rendered only when Turnstile is configured; the endpoint requires a
           token in exactly that case. -->
      <div
        v-if="props.turnstileSiteKey"
        data-turnstile-widget
        class="cf-turnstile"
        :data-sitekey="props.turnstileSiteKey"
      />

      <button
        type="submit"
        :disabled="form.processing"
        class="btn-primary h-12 rounded-md text-base font-semibold disabled:opacity-60"
      >
        {{ form.processing ? t('forgot_password.sending') : t('advertise.send_inquiry') }}
      </button>
    </form>
  </section>

  <BenefitsStrip />
</template>
