<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import UiLiquidBar from '@/components/ui/UiLiquidBar.vue'
import { type FeePayload } from '@/components/payment/FeeCalculator.vue'
import iconTrackerCheck from '@/assets/figma/payment/tracker-done-check.svg'
import iconHeadset from '@/assets/figma/icons/pay-headset.svg'
import iconShieldAlert from '@/assets/figma/icons/pay-shield-alert.svg'
import iconBenefitCheck from '@/assets/figma/icons/pay-benefit-check.svg'
import iconCheckCircle from '@/assets/figma/icons/sbo-check-circle.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Payment required — Figma 279:15242 (1280x2501).
 *   hero-section 279:15277 (364)      — VIDEO fill, breadcrumb, 25% tracker
 *   main-content-area 279:15309       — workspace-left 708 + workspace-right 380
 *
 * workspace-left  279:15310 — Your Application, Why Payment Matters, Timeline
 * workspace-right 279:15403 — Fee Calculator, Need Help
 *
 * States: 279:15242 initial · 384:17211 "Application payment page 1", which
 * adds the Espees/manual methods · 387:18447 manual expanded, which draws the
 * manual form as a dialog. Both live in PaymentMethods, keyed on ?method.
 * Every icon, fill and radius below is read from those nodes.
 */

defineOptions({ layout: AppLayout })

const props = defineProps<{
  sboId: number | null
  /** `key` is stable; `label` is translated, so never match on it. */
  application: { key: string; label: string; value: string }[]
  progressUrl: string
  applicationsUrl: string
  /** The fee schedule, current selection and gateway state; see SboFeePayload. */
  fees: FeePayload
  /** Gated, but owns no listing yet — there is no fee to pay, only one to create. */
  needsRegistration: boolean
  /** Where the outstanding fee is paid — the add-business page's payment step. */
  payUrl: string
  registerUrl: string
  /**
   * Which gate sent them here. The SBO network gate redirects here too, and a
   * member who has paid but is not yet verified must not be told to pay again.
   */
  reason: 'payment' | 'verification'
  /*
   * The tracker was a fixed 25% with "Step 2: Fees Payment" always active, so
   * the wall said the same thing to every owner — including one blocked on
   * verification with the fee long settled.
   */
  /*
   * `percent` is the completion count shown as text; `barPercent` is where the
   * fill is drawn. They differ on purpose — the four steps complete
   * independently, so an application with documents cleared and the fee still
   * due scores 50% while sitting on Step 2. Filling to 50% put the bar past
   * the Step 3 marker while the Step 2 dot was the one lit. See
   * SboApplicationProgressBar::barPercent.
   */
  progress: { percent: number; barPercent: number; steps: { key: string; label: string; state: string }[] }
}>()

const steps = computed(() => props.progress.steps)

const application = computed(() => props.application)

const benefits = [
  {
    title: t('payment_required.unlock_your_business_listings_procurement'),
    body: t('payment_required.get_instant_visibility_in_our_central_regi'),
  },
  {
    title: t('payment_required.get_your_official_sbo_digital_seal_certifi'),
    body: t('payment_required.high_resolution_visual_markers_to_download'),
  },
  {
    title: t('payment_required.appear_in_the_verified_loveworld_business'),
    body: t('payment_required.a_permanent_digital_catalog_listing_access'),
  },
]

/*
 * timeline-card — the same tracker as the application progress page, so the two
 * screens stay consistent. It is driven by the real application state
 * (props.progress.steps) rather than a fixed "Step 2 active" list, so a member
 * who has paid and is awaiting approval sees fees marked done, not active.
 */
const TIMELINE_COPY: Record<string, { done: string; active: string; todo: string }> = {
  // Step 1: add business + payment — done only when the fee is approved.
  payment: {
    done: t('sbo_progress.registration_fee_cleared'),
    active: t('sbo_progress.awaiting_your_registration_fee'),
    todo: t('sbo_progress.awaiting_your_registration_fee'),
  },
  documents: {
    done: t('sbo_progress.compliance_documents_accepted'),
    active: t('sbo_progress.upload_your_compliance_documents_for_revie'),
    todo: t('sbo_progress.opens_once_your_fee_is_settled'),
  },
  verification: {
    done: t('sbo_progress.final_approval_issued_from_loveworld_comme'),
    active: t('sbo_progress.with_the_registry_for_final_approval'),
    todo: t('sbo_progress.issued_once_your_fee_and_documents_are_cle'),
  },
}

const STATE_LABEL: Record<string, string> = {
  done: t('application.done'),
  active: t('sbo_progress.in_progress'),
  todo: t('data.status.pending'),
}

const timeline = computed(() =>
  props.progress.steps.map((step) => ({
    title: step.label,
    body: TIMELINE_COPY[step.key]?.[step.state as 'done' | 'active' | 'todo'] ?? '',
    state: step.state,
    label: STATE_LABEL[step.state] ?? '',
  })),
)

/** The listing being paid for, for the breadcrumb. */
const companyName = computed(
  () => props.application.find((r) => r.key === 'company')?.value ?? t('payment_required.your_application_2'),
)
</script>

<template>
  <!-- ========================================= hero-section 279:15277 ===== -->
  <section class="relative overflow-hidden bg-[#191818]">
    <HeroVideo overlay="bg-[#191818]/72" />

    <div class="relative section-x py-8 sm:py-12">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('payment_required.breadcrumb')">
        <RouterLink href="/dashboard" class="text-[#94a3b8] hover:text-white">{{ t('payment_required.applications') }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('payment_required.gt') }}</span>
        <!-- Was the literal "Pending SBO Demo" for every applicant. -->
        <span class="text-white">{{ companyName }}</span>
      </nav>

      <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="max-w-2xl">
          <!-- Three gates land on this screen, so the headline says which one:
               telling a member who has already paid to "Complete Your Payment"
               is worse than the 403 it replaced. -->
          <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">
            {{
              props.needsRegistration
                ? t('profile_dashboard.register_your_business')
                : props.reason === 'verification'
                  ? t('payment_required.verification_in_progress')
                  : t('payment_required.complete_your_payment')
            }}
          </h1>
          <p class="mt-2 text-base text-[#94a3b8]">
            <template v-if="props.needsRegistration">
              {{ t('payment_required.the_loveworld_registry_is_for_paid_members') }}
            </template>
            <template v-else-if="props.reason === 'verification'">
              {{ t('payment_required.your_fee_is_settled_access_opens_once_your') }}
            </template>
            <template v-else>
              {{ t('payment_required.registration_percent', { percent: props.fees.calculatedFee !== null ? '50%' : '25%' }) }}
            </template>
          </p>
        </div>
        <!-- continue-cta 387:18495 -> Step 3: Required Documents -->
        <RouterLink
          href="/application/documents"
          class="btn-primary flex shrink-0 items-center justify-center rounded-xl px-5 sm:px-7 py-3.5 text-base font-semibold whitespace-nowrap"
        >
          {{ t('payment_required.continue_registration') }}
        </RouterLink>
      </div>

      <!-- hero-progress-tracker 384:17261 — the bar and the percentage share
           one row, and the four nodes spread across the full width beneath it.
           Indicators are three different sizes: a 16px filled #007035 disc with
           a white check when done, a 12px #e8a317 dot when active, a 10px
           #94a3b8 dot when pending. -->
      <div class="mt-7 flex flex-col gap-4 rounded-2xl bg-white/6 p-5">
        <div class="flex items-center gap-3">
          <UiLiquidBar :value="progress.barPercent" label="Application progress" />
          <span class="shrink-0 text-sm font-semibold text-[#e8a317]">
            {{ t('application.percent_complete', { percent: progress.percent }) }}
          </span>
        </div>

        <ol class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
          <li v-for="s in steps" :key="s.label" class="flex items-center gap-2">
            <span
              v-if="s.state === 'done'"
              class="grid size-4 shrink-0 place-items-center rounded-full bg-[#007035]"
              aria-hidden="true"
            >
              <img :src="iconTrackerCheck" alt="" class="size-2.5" />
            </span>
            <span
              v-else
              class="shrink-0 rounded-full"
              :class="s.state === 'active' ? 'size-3 bg-[#e8a317]' : 'size-2.5 bg-[#94a3b8]'"
              aria-hidden="true"
            />
            <span
              class="text-xs whitespace-nowrap"
              :class="{
                'font-medium text-white': s.state === 'done',
                'font-semibold text-[#e8a317]': s.state === 'active',
                'font-medium text-[#94a3b8]': s.state === 'todo',
              }"
            >
              {{ s.label }}
            </span>
          </li>
        </ol>
      </div>
    </div>
  </section>

  <!-- ==================================== main-content-area 279:15309 ===== -->
  <div class="bg-surface-secondary section-x py-8">
    <div class="grid gap-6 lg:grid-cols-[minmax(0,708px)_380px] [&>*]:min-w-0">
      <!-- ================================ workspace-left 279:15310 ======== -->
      <div class="flex min-w-0 flex-col gap-6">
        <!-- company-details-card 279:15311 -->
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7 shadow-[0px_4px_6px_rgba(0,0,0,0.02)]">
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-[#0f172a]">{{ t('payment_required.your_application') }}</h2>
            <!-- The payment wall means the fee is outstanding, so the applicant
                 is never approved here — it read a hardcoded "Approved Status". -->
            <span class="rounded-full bg-[#fef3c7] px-2.5 py-1 text-2xs font-semibold text-[#b45309]">
              {{ t('data.status.awaiting_verification') }}
            </span>
          </div>
          <hr class="border-[#e2e8f0]" />
          <dl class="flex flex-col gap-2.5">
            <div v-for="a in application" :key="a.label" class="flex items-center justify-between gap-4 py-1">
              <dt class="text-sm text-[#475569]">{{ a.label }}</dt>
              <dd class="text-sm font-semibold text-[#0f172a]">{{ a.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- why-payment-matters-card 279:15330 -->
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7 shadow-[0px_4px_6px_rgba(0,0,0,0.02)]">
          <div class="flex items-center gap-3">
            <span class="grid size-10 place-items-center rounded-md bg-[#fef3c7]">
              <img :src="iconShieldAlert" alt="" class="size-5" aria-hidden="true" />
            </span>
            <h2 class="text-lg font-semibold text-[#0f172a]">{{ t('payment_required.why_completing_payment_matters') }}</h2>
          </div>
          <p class="text-sm leading-6 text-[#475569]">
            {{ t('payment_required.loveworld_registry_validation_establishes') }}
          </p>
          <hr class="border-[#e2e8f0]" />
          <ul class="flex flex-col gap-4">
            <li v-for="b in benefits" :key="b.title" class="flex gap-3">
              <span class="grid size-5 shrink-0 place-items-center rounded-[10px] bg-[#d1fae5]">
                <img :src="iconBenefitCheck" alt="" class="size-3" aria-hidden="true" />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm font-semibold text-[#0f172a]">{{ b.title }}</span>
                <span class="text-xs text-[#475569]">{{ b.body }}</span>
              </span>
            </li>
          </ul>
        </section>

        <!-- timeline-card 279:15364 — matches the application progress tracker -->
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <h2 class="text-lg font-semibold text-[#0f172a]">{{ t('payment_required.timeline_tracking') }}</h2>
          <ol class="flex flex-col gap-3">
            <li
              v-for="row in timeline"
              :key="row.title"
              class="flex items-center gap-4 rounded-xl border bg-white p-4"
              :class="row.state === 'active' ? 'border-[#e8a317]' : 'border-[#e2e8f0]'"
            >
              <img
                v-if="row.state === 'done'"
                :src="iconCheckCircle"
                alt=""
                class="size-6 shrink-0"
                aria-hidden="true"
              />
              <span
                v-else
                class="grid size-6 shrink-0 place-items-center rounded-full border-2"
                :class="row.state === 'active' ? 'border-[#e8a317]' : 'border-[#cbd5e1]'"
                aria-hidden="true"
              >
                <span
                  class="size-2 rounded-full"
                  :class="row.state === 'active' ? 'bg-[#e8a317]' : 'bg-[#cbd5e1]'"
                />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm font-semibold text-[#64748b]">{{ row.title }}</span>
                <span class="text-xs text-[#94a3b8]">{{ row.body }}</span>
              </span>
              <span
                class="shrink-0 text-2xs font-semibold tracking-wide uppercase"
                :class="{
                  'text-[#007035]': row.state === 'done',
                  'text-[#e8a317]': row.state === 'active',
                  'text-[#94a3b8]': row.state === 'todo',
                }"
              >
                {{ row.label }}
              </span>
            </li>
          </ol>
        </section>
      </div>

      <!-- =============================== workspace-right 279:15403 ======== -->
      <aside class="flex min-w-0 flex-col gap-6">
        <!-- registration-fee-payment-card 279:15404 ----------------------
             This card WAS the calculator: a Share Structure select with three
             invented options and a hardcoded "50 Espees". FeeCalculator draws
             the same card from the real, country-scoped schedule, so it
             replaces the section rather than nesting inside it — which is what
             made this column overflow: a p-9 bordered card inside a p-8
             bordered card, in a fixed 380px track. -->
        <!-- Nothing registered yet: there is no fee to calculate and nothing
             to pay, so the wall asks for the step that actually comes first
             rather than drawing two controls that cannot run. -->
        <section
          v-if="props.needsRegistration"
          class="flex min-w-0 flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-[0px_4px_8px_rgba(0,0,0,0.02)]"
        >
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('payment_required.register_your_business') }}</h2>
          <p class="text-xs text-[#475569]">
            {{ t('payment_required.the_loveworld_registry_is_for_paid_members_2') }}
          </p>
          <RouterLink
            :href="props.registerUrl"
            class="btn-primary rounded-lg px-6 py-3.5 text-center text-base font-semibold"
          >
            {{ t('payment_required.register_your_business') }}
          </RouterLink>
        </section>

        <!--
          Payment is taken only on the add-business page's final step, so the
          wall never shows a payment form. If the fee is still outstanding it
          points the owner to that step; once paid it is a status card.
        -->
        <section
          v-else-if="props.reason === 'payment'"
          class="flex min-w-0 flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-[0px_4px_8px_rgba(0,0,0,0.02)]"
        >
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('payment_required.complete_your_payment') }}</h2>
          <p class="text-xs text-[#475569]">{{ t('payment_required.finish_paying_to_continue') }}</p>
          <RouterLink
            :href="props.payUrl"
            class="btn-primary rounded-lg px-6 py-3.5 text-center text-base font-semibold"
          >
            {{ t('payment_required.go_to_payment') }}
          </RouterLink>
        </section>

        <section
          v-else
          class="flex min-w-0 flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-[0px_4px_8px_rgba(0,0,0,0.02)]"
        >
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('payment_required.payment_received_title') }}</h2>
          <p class="text-xs text-[#475569]">{{ t('payment_required.payment_received_body') }}</p>
        </section>

        <!-- need-help-card 279:15459 -->
        <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6">
          <div class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-md bg-[#f1f5f9]">
              <img :src="iconHeadset" alt="" class="size-[18px]" aria-hidden="true" />
            </span>
            <h2 class="text-base font-semibold text-[#0f172a]">{{ t('payment_required.need_help') }}</h2>
          </div>
          <p class="text-xs text-[#475569]">
            {{ t('payment_required.our_dedicated_support_team_is_available_24') }}
          </p>
          <RouterLink href="/support" class="text-xs font-semibold text-[#007035] hover:underline">
            {{ t('payment_required.contact_support') }}
          </RouterLink>
        </section>
      </aside>
    </div>
  </div>
</template>
