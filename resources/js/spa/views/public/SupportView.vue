<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link as RouterLink, useForm, usePage } from '@inertiajs/vue3'
import supportHero from '@/assets/figma/heroes/support-hero.jpg'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import tipCheck from '@/assets/figma/icons/tip-check.svg'
import faqPlus from '@/assets/figma/icons/faq-plus.svg'
import faqMinus from '@/assets/figma/icons/faq-minus.svg'
import lrImage from '@/assets/figma/heroes/lr.png'
import appStoreBadge from '@/assets/figma/heroes/app-store-badge.png'
import googlePlayBadge from '@/assets/figma/heroes/google-play-badge.png'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */

defineOptions({ layout: PublicLayout })

/*
 * Support — Figma 36:4 "support-page-redesign" (1280x4298).
 *   hero-section 36:28 (387)          — greeting, search, category chips
 *   quick-help 36:51 (494)            — three self-service cards
 *   request-form-section 36:94 (1003) — "Before Submitting" + the form
 *   ad-cta-banner 36:153 (165)
 *   faq-section 36:161 (731)
 *   register-cta-section 36:192 (381)
 *   benefits 52:1962
 *
 * Copy read from the design's text nodes.
 */

/*
 * `user` is nullable: Support is one of the four pages a signed-out visitor
 * can reach, so the greeting and the prefilled contact fields have to degrade.
 */
const props = defineProps<{ user: { firstname: string; lastname: string; email: string } | null }>()

// tips-sidebar 36:140 — verbatim
const beforeSubmitting = [
  t('support.have_your_application_id_or_moc_verificati'),
  t('support.describe_the_specific_workflow_page_where'),
  t('support.check_our_status_page_if_you_are_experienc'),
]

const faqs = [
  {
    q: t('support.how_do_i_verify_my_sbo'),
    a: "To verify your SBO, log in to your dashboard and navigate to 'SBO Verification'. Upload your registered corporate documents (MOC / CAC status) and enter your structural group reference. Our compliance team usually processes and responds to requests in 48-72 business hours.",
  },
  { q: t('support.what_is_my_application_id'), a: t('support.your_application_id_is_issued_when_you_sub') },
  { q: t('support.how_long_does_verification_take'), a: t('support.verification_typically_completes_within_a') },
  { q: t('support.can_i_update_my_business_details'), a: t('support.yes_update_them_from_settings_profile_at_a') },
  { q: t('support.how_do_i_contact_support_directly'), a: t('support.submit_the_request_form_above_or_start_a_l') },
]

const openFaq = ref(0)

// The mobile app is not published yet; the store badges open this notice.
const comingSoon = ref(false)

/*
 * POST /support/enquiries (SboNetworkWriteController@supportEnquiry) writes a
 * SupportEnquiry; the form collected six fields and posted none of them.
 *
 * The endpoint takes first_name / last_name / sbo_number / subject / message.
 * `category` is drawn but has no column on support_enquiries, so it is folded
 * into the subject line rather than dropped — see INTEGRATION.md.
 */
const form = useForm({
  first_name: props.user?.firstname ?? '',
  last_name: props.user?.lastname ?? '',
  category: t('support.sbo_verification_assistance'),
  sbo_number: '',
  subject: '',
  message: '',
})

// Signing in mid-visit repopulates the contact rows.
watch(
  () => props.user,
  (user) => {
    form.first_name = user?.firstname ?? ''
    form.last_name = user?.lastname ?? ''
  },
)

const page = usePage()
const sent = computed(() => page.props.flash.status === 'enquiry-sent')

function submit() {
  form
    .transform((data) => ({
      ...data,
      subject: `[${data.category}] ${data.subject}`,
    }))
    .post('/support/enquiries', {
      preserveScroll: true,
      onSuccess: () => form.reset('subject', 'message', 'sbo_number'),
    })
}
</script>

<template>
  <!-- ========================================== hero-section 36:28 ========
       Centred stack: 800px text block, then a 680px search container holding a
       WHITE search bar (12px radius, 8px padding) with the button inside it,
       and the category pills centred beneath. Fill is IMAGE + a
       rgba(5,12,26,0.56) scrim. -->
  <section class="relative overflow-hidden bg-[#050c1a]">
    <img :src="supportHero" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
    <div class="absolute inset-0 bg-[rgba(5,12,26,0.56)]" aria-hidden="true" />

    <div class="relative flex flex-col items-center justify-center gap-8 section-x py-9 sm:py-14 xl:py-20">
      <div class="flex w-full max-w-[800px] flex-col items-center gap-4 text-center">
        <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">
          {{ props.user ? t('support.how_can_we_help_named', { name: props.user.firstname }) : t('support.how_can_we_help') }}
        </h1>
        <p class="text-base leading-normal text-[#94a3b8]">
          {{ t('support.search_our_knowledge_base_or_submit_a_requ') }}
        </p>
      </div>
    </div>
  </section>

  <!-- =================================== request-form-section 36:94 ======= -->
  <section class="bg-surface-secondary section-x py-12 sm:py-20">
    <!-- The design draws 708 + 380 inside a 1120 content width. Fixing the
         form column at 708px leaves the section narrower than its neighbours
         at any width below xl, so the form takes the remaining space and only
         the sidebar keeps its 380px. At xl this resolves to the same 708/380. -->
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px] [&>*]:min-w-0">
      <div class="min-w-0">
        <!-- form-card 36:95 — the heading sits INSIDE the card. -->
        <form class="rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8" @submit.prevent="submit">
          <header class="mb-7 flex flex-col gap-2">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('support.submit_a_support_request') }}</h2>
            <p class="text-sm text-[#475569]">
              {{ t('support.fill_out_the_form_below_and_our_team_will') }}
            </p>
          </header>

          <p
            v-if="sent"
            class="mb-6 rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
            role="status"
          >
            {{ t('support.thanks_your_request_is_with_the_support_te') }}
          </p>

          <div class="grid gap-5 sm:grid-cols-2 [&>*]:min-w-0">
            <label class="flex flex-col gap-2">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.first_name') }}</span>
              <input
                v-model="form.first_name"
                class="rounded-md border border-border-input px-4 py-3 text-sm focus:border-focus focus:outline-none"
              />
              <span v-if="form.errors.first_name" class="text-xs text-danger">
                {{ form.errors.first_name }}
              </span>
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.last_name') }}</span>
              <input
                v-model="form.last_name"
                class="rounded-md border border-border-input px-4 py-3 text-sm focus:border-focus focus:outline-none"
              />
              <span v-if="form.errors.last_name" class="text-xs text-danger">
                {{ form.errors.last_name }}
              </span>
            </label>
          </div>

          <!--
            The design draws an Email Address row, prefilled with one specific
            person's address. `support_enquiries` has no email column — replies
            go to the linked user — and the field is meaningless signed out, so
            it shows the account's address read-only rather than collecting an
            address nothing would read. See INTEGRATION.md.
          -->
          <label v-if="props.user" class="mt-5 flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.email_address') }}</span>
            <input
              type="email"
              :value="props.user.email"
              readonly
              class="rounded-md border border-border-input bg-surface-secondary px-4 py-3 text-sm text-ink-secondary focus:outline-none"
            />
          </label>

          <label class="mt-5 flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.select_category') }}</span>
            <select
              v-model="form.category"
              class="max-w-full rounded-md border border-border-input bg-white px-4 py-3 text-sm focus:border-focus focus:outline-none"
            >
              <option>{{ t('support.sbo_verification_assistance') }}</option>
              <option>{{ t('support.account_issues') }}</option>
              <option>{{ t('support.billing') }}</option>
              <option>{{ t('support.technical_support') }}</option>
              <option>{{ t('support.getting_started') }}</option>
            </select>
          </label>

          <label class="mt-5 flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">
              {{ t('support.sbo_number_application_id_or_moc_number') }}
            </span>
            <input
              v-model="form.sbo_number"
              :placeholder="t('support.e_g_mbn_2026_0001_or_moc_2026_0001')"
              class="rounded-md border border-border-input px-4 py-3 text-sm placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
            />
            <span v-if="form.errors.sbo_number" class="text-xs text-danger">
              {{ form.errors.sbo_number }}
            </span>
          </label>

          <label class="mt-5 flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.subject') }}</span>
            <input
              v-model="form.subject"
              :placeholder="t('support.brief_summary_of_your_inquiry')"
              class="rounded-md border border-border-input px-4 py-3 text-sm placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
            />
            <span v-if="form.errors.subject" class="text-xs text-danger">
              {{ form.errors.subject }}
            </span>
          </label>

          <label class="mt-5 flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('support.detailed_message') }}</span>
            <textarea
              v-model="form.message"
              rows="6"
              :placeholder="t('support.please_describe_the_issue_clearly_so_our_s')"
              class="rounded-md border border-border-input px-4 py-3 text-sm placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
            />
            <span v-if="form.errors.message" class="text-xs text-danger">
              {{ form.errors.message }}
            </span>
          </label>

          <div class="mt-6 flex flex-wrap items-center justify-end gap-4">
            <button
              type="submit"
              :disabled="form.processing"
              class="btn-primary rounded-md px-5 sm:px-7 py-3 text-sm font-semibold disabled:opacity-60"
            >
              {{ form.processing ? t('register_wizard.submitting') : t('support.submit_request') }}
            </button>
          </div>
        </form>
      </div>

      <aside>
        <!-- tips-sidebar 36:138 — 32px pad, 16px radius, check icons -->
        <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <h3 class="font-display text-base font-semibold text-[#0f172a]">{{ t('support.before_submitting') }}</h3>
          <ul class="flex flex-col gap-4">
            <li v-for="b in beforeSubmitting" :key="b" class="flex gap-3">
              <img :src="tipCheck" alt="" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              <span class="flex-1 text-xs leading-snug text-[#475569]">{{ b }}</span>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </section>

  <!-- ======================================== ad-cta-banner 36:153 ======== -->
  <section class="bg-[#001529] section-x py-8">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <p class="text-2xs font-semibold tracking-wide text-secondary uppercase">
          {{ t('support.advertisement_opportunities') }}
        </p>
        <h2 class="mt-1.5 font-serif text-2xl font-bold text-white">
          {{ t('support.reach_verified_loveworld_businesses') }}
        </h2>
        <p class="mt-1.5 max-w-2xl text-xs text-white/70">
          {{ t('support.promote_your_services_directly_to_decision') }}
        </p>
      </div>
      <RouterLink href="/advertise" class="btn-primary shrink-0 self-start rounded-md px-6 py-3 text-xs font-semibold lg:self-auto">
        {{ t('support.advertise_with_us') }}
      </RouterLink>
    </div>
  </section>

  <!-- =========================================== faq-section 36:161 =======
       Each FAQ is its own card (white, #e2e8f0 border, 12px radius, 24px pad)
       with a minus icon when open and a plus when closed — not a divided list. -->
  <section class="bg-white section-x py-12 sm:py-20">
    <header class="flex flex-col gap-2">
      <h2 class="font-serif text-3xl font-bold text-[#0f172a]">{{ t('support.frequently_asked_questions') }}</h2>
      <p class="text-base text-[#475569]">
        {{ t('support.quick_solutions_and_explanations_for_commo') }}
      </p>
    </header>

    <ul class="mt-10 flex flex-col gap-4">
      <li
        v-for="(f, i) in faqs"
        :key="f.q"
        class="rounded-xl border border-[#e2e8f0] bg-white p-6"
      >
        <button
          type="button"
          class="flex w-full items-center justify-between gap-4 text-left"
          :aria-expanded="openFaq === i"
          @click="openFaq = openFaq === i ? -1 : i"
        >
          <span class="flex-1 font-display text-base font-semibold text-[#0f172a]">{{ f.q }}</span>
          <img
            :src="openFaq === i ? faqMinus : faqPlus"
            alt=""
            class="size-5 shrink-0"
            aria-hidden="true"
          />
        </button>
        <p v-show="openFaq === i" class="mt-4 text-sm leading-relaxed text-[#475569]">{{ f.a }}</p>
      </li>
    </ul>
  </section>

  <!-- ============================ grow-your-business + app CTA (pre-footer) ===
       A brand-coloured card: heading, copy, the app-store badges, and the LR
       device art sitting flush to the bottom edge. -->
  <section class="bg-white section-x pb-20">
    <div class="relative overflow-hidden rounded-[24px] bg-gradient-to-br from-primary to-primary-hover">
      <!-- Soft brand circles, echoing the reference, kept faint. -->
      <div class="pointer-events-none absolute -top-16 -right-14 size-56 rounded-full bg-white/[0.07]" aria-hidden="true" />
      <div class="pointer-events-none absolute bottom-4 left-1/4 size-28 rounded-full bg-white/[0.06]" aria-hidden="true" />

      <!-- items-end so the art aligns to the bottom; padding lives on the copy
           column only, so the art meets the card's bottom (and right) edge with
           no gap. -->
      <div class="relative grid gap-2 lg:grid-cols-2 lg:items-end">
        <div class="flex flex-col gap-4 px-6 pt-8 pb-2 sm:px-10 sm:pt-10 lg:py-12 lg:pr-0 lg:pl-12 xl:pl-14">
          <h2 class="font-serif text-2xl leading-tight font-bold text-white sm:text-3xl">
            {{ t('support.grow_your_business_with_the_loveworld_netwo') }}
          </h2>
          <p class="max-w-[480px] text-sm leading-relaxed text-white/85 sm:text-base">
            {{ t('support.register_your_business_today_and_connect_wit') }}
          </p>

          <div class="mt-1 flex flex-col gap-2.5">
            <span class="text-sm font-semibold tracking-wide text-white/90">
              {{ t('support.download_our_mobile_app') }}
            </span>
            <div class="flex flex-wrap items-center gap-3">
              <button
                type="button"
                aria-label="Download on the App Store"
                class="h-11 w-40 transition-opacity hover:opacity-90"
                @click="comingSoon = true"
              >
                <img :src="appStoreBadge" alt="Download on the App Store" class="h-full w-full object-contain" />
              </button>
              <button
                type="button"
                aria-label="Get it on Google Play"
                class="h-11 w-40 transition-opacity hover:opacity-90"
                @click="comingSoon = true"
              >
                <img :src="googlePlayBadge" alt="Get it on Google Play" class="h-full w-full object-contain" />
              </button>
            </div>
          </div>
        </div>

        <!-- The device art — visible on every size, flush to the bottom. Sized
             below the copy column's height so it bottom-aligns with clear space
             above it (not crowding the top edge). -->
        <div class="flex justify-center self-end px-6 sm:px-10 lg:justify-end lg:px-0">
          <img
            :src="lrImage"
            alt=""
            aria-hidden="true"
            class="block w-full max-w-[190px] object-contain object-bottom sm:max-w-[230px] lg:max-w-[290px]"
          />
        </div>
      </div>
    </div>
  </section>

  <BenefitsStrip />

  <!-- "Coming soon" notice for the mobile-app store badges. -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150"
      leave-to-class="opacity-0"
    >
      <div
        v-if="comingSoon"
        class="fixed inset-0 z-[100] grid place-items-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="coming-soon-title"
        @click.self="comingSoon = false"
        @keydown.esc="comingSoon = false"
      >
        <div class="w-full max-w-sm rounded-2xl bg-surface p-6 text-center shadow-[0px_24px_48px_rgba(15,23,42,0.24)]">
          <span class="mx-auto grid size-12 place-items-center rounded-full bg-primary/10 text-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6" aria-hidden="true">
              <rect x="7" y="2.5" width="10" height="19" rx="2.5" stroke-linejoin="round" />
              <path d="M11 18.5h2" stroke-linecap="round" />
            </svg>
          </span>
          <h2 id="coming-soon-title" class="mt-4 font-display text-lg font-semibold text-ink">
            {{ t('support.mobile_app_coming_soon') }}
          </h2>
          <p class="mt-2 text-sm leading-relaxed text-ink-secondary">
            {{ t('support.our_mobile_app_is_on_the_way') }}
          </p>
          <button
            type="button"
            class="btn-primary mt-5 w-full rounded-md py-3 text-sm font-semibold"
            @click="comingSoon = false"
          >
            {{ t('support.got_it') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
