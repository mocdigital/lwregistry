<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link as RouterLink, router } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import iconBuilding from '@/assets/figma/icons/sbo-building.svg'
import iconShieldCheck from '@/assets/figma/icons/sbo-shield-check.svg'
import iconCheckCircle from '@/assets/figma/icons/sbo-check-circle.svg'
import iconShieldBtn from '@/assets/figma/icons/sbo-shield-btn.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import UiLiquidBar from '@/components/ui/UiLiquidBar.vue'
import SboSealPanel from '@/components/sbo/SboSealPanel.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t } = useI18n()
const { entityTerm } = useEntityTerm()

defineOptions({ layout: AppLayout })

/*
 * SBO Progress — Figma 223:3324 "sbo-progress-redesign" (1280x2452).
 * The COMPLETED state of the application flow: 100%, all four steps Done.
 *   hero-section 223:3490 (379) — VIDEO fill, verified badge, Continue CTA
 *   CoreContent 223:3363        — LeftCol (details, notice, timeline)
 *                                 + RightCol 420 (seal, license, ad)
 */

const props = defineProps<{
  sbo: { name: string; slug: string; applicationId: string | null; submittedOn: string | null; progressUrl: string; entityType: string }
  progress: {
    percent: number
    /** Where the fill is drawn; see SboApplicationProgressBar::barPercent. */
    barPercent: number
    feesPaid: boolean
    /** Fee settled OR a payment submitted awaiting admin confirmation. */
    feeSettled: boolean
    documentsCompliant: boolean
    verified: boolean
    /** done / active / todo per step, so the list stops ticking everything. */
    steps: { key: string; label: string; state: 'done' | 'active' | 'todo' }[]
  }
  /** The real seal, badge image and certificate; see SboSealPanel. */
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
  /** Why the application is where it is — see ApplicationController. */
  status: {
    verification: string
    infoRequest: string | null
    declineReason: string | null
  }
  /** The applicant's own "Resubmit for review" action; see ApplicationController. */
  canResubmit: boolean
  resubmitUrl: string
  documents: {
    typeId: number
    name: string
    required: boolean
    status: 'pending' | 'approved' | 'rejected' | null
    rejectionReason: string | null
  }[]
}>()

/*
 * Resubmit after an info request. Confirmed first, because it puts the
 * application back in the review queue — the Blade button used wire:confirm
 * for the same reason.
 */
const resubmitting = ref(false)

function resubmit() {
  if (!window.confirm(t('sbo_progress.confirm_you_have_updated_the_requested_inf'))) {
    return
  }

  resubmitting.value = true
  router.post(props.resubmitUrl, {}, {
    preserveScroll: true,
    onFinish: () => (resubmitting.value = false),
  })
}

/* Documents still standing between this application and compliance. */
/*
 * The hero state.
 *
 * The headline read "Your SBO is Officially Verified!" and the pill read
 * "✓ Verified Status" unconditionally — this screen is DRAWN as the completed
 * state, but it is reachable at any point in the flow, so an application still
 * awaiting verification was congratulated on finishing it.
 *
 * "Verified" here means what isFullyVerified() means on the model: approved by
 * the registry AND documents accepted AND the fee settled. Approval alone is
 * not verification; that distinction is the whole reason the seal has a
 * provisional state.
 */
const fullyVerified = computed(
  () => props.progress.verified && props.progress.documentsCompliant && props.progress.feesPaid,
)

const declined = computed(() => 'declined' === props.status.verification)

const hero = computed(() => {
  if (declined.value) {
    return {
      pill: t('sbo_progress.registration_declined'),
      pillClass: 'bg-[#fee2e2] text-[#991b1b]',
      title: t('sbo_progress.your_registration_was_declined'),
      body: t('sbo_progress.declined_body', { name: props.sbo.name }),
      ctaLabel: null,
      ctaHref: null,
    }
  }

  if (props.status.infoRequest) {
    return {
      pill: t('sbo_progress.information_requested'),
      pillClass: 'bg-[#fef3c7] text-[#92400e]',
      title: t('sbo_progress.we_need_a_little_more_from_you'),
      body: t('sbo_progress.info_requested_body', { name: props.sbo.name }),
      ctaLabel: null,
      ctaHref: null,
    }
  }

  if (fullyVerified.value) {
    return {
      pill: t('sbo_progress.verified_status'),
      pillClass: 'bg-[#d1fae5] text-[#007035]',
      title: t('sbo_progress.your_sbo_is_officially_verified', { entity: entityTerm.value }),
      body: t('sbo_progress.verified_body', { name: props.sbo.name }),
      ctaLabel: t('sbo_progress.continue_to_dashboard'),
      ctaHref: '/sbo/dashboard',
    }
  }

  // Still moving: name the step that is actually outstanding.
  const next = props.progress.steps.find((s) => 'active' === s.state)

  return {
    pill: t('sbo_progress.awaiting_verification'),
    pillClass: 'bg-[#e0f2fe] text-[#0369a1]',
    title: t('sbo_progress.your_registration_is_in_progress'),
    body: next
      ? t('sbo_progress.in_progress_body', { name: props.sbo.name, step: next.label })
      : t('sbo_progress.reviewing_body', { name: props.sbo.name }),
    ctaLabel: props.progress.feeSettled ? null : t('sbo_progress.complete_payment'),
    ctaHref: props.progress.feeSettled ? null : '/application/fees',
  }
})

const outstanding = computed(() =>
  props.documents.filter((d) => d.required && d.status !== 'approved'),
)

// Was a fixed "Paid SBO Demo" application; these are the owner's own now.
const details = computed(() => [
  { label: t('sbo_progress.company_name'), value: props.sbo.name },
  { label: t('sbo_progress.application_id'), value: props.sbo.applicationId ?? '—' },
  { label: t('sbo_progress.entity_type'), value: props.sbo.entityType },
  { label: t('sbo_progress.submitted_date'), value: props.sbo.submittedOn ?? '—' },
])

/*
 * Timeline Tracking.
 *
 * Every row was a fixed check icon, a past-tense sentence and the word "Done",
 * so an application sitting on Step 2 reported all four stages complete —
 * including a final approval that had not been issued. The titles come from the
 * server's own step list, and each row's copy is chosen by its real state, so a
 * stage that has not happened is not described as though it had.
 */
/*
 * Keyed on the step's stable key, not its label. Keying on the label worked
 * only while the label was English — the server translates it now, so the
 * lookup would miss in French and every row would render blank.
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
    body: TIMELINE_COPY[step.key]?.[step.state] ?? '',
    state: step.state,
    label: STATE_LABEL[step.state] ?? '',
  })),
)
</script>

<template>
  <!-- ========================================= hero-section 223:3490 ====== -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo overlay="bg-[#001529]/72" />

    <div class="relative flex flex-col gap-6 section-x py-9 sm:py-14">
      <!-- The pill, headline and CTA were fixed at the "verified" state, so an
           application still awaiting verification was congratulated on having
           finished. All three follow the real state now; see `hero`. -->
      <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full px-3 py-1 text-2xs font-semibold" :class="hero.pillClass">
          {{ hero.pill }}
        </span>
        <span class="text-2xs text-[#94a3b8]">{{ t('application.application_id_is', { id: sbo.applicationId ?? '—' }) }}</span>
      </div>

      <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="max-w-2xl">
          <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">{{ hero.title }}</h1>
          <p class="mt-2 text-base text-[#94a3b8]">{{ hero.body }}</p>
        </div>
        <RouterLink
          v-if="hero.ctaHref"
          :href="hero.ctaHref"
          class="btn-primary flex shrink-0 items-center justify-center rounded-xl px-5 sm:px-7 py-3.5 text-base font-semibold whitespace-nowrap"
        >
          {{ hero.ctaLabel }}
        </RouterLink>
      </div>

      <!--
        The two states that stop an application dead. Neither reached this
        screen before, so an applicant blocked on a question was shown a
        progress bar and nothing else.
      -->
      <div
        v-if="props.status.infoRequest"
        class="flex flex-col gap-1 rounded-2xl border border-[#f59e0b]/40 bg-[#f59e0b]/10 p-5"
      >
        <p class="text-sm font-semibold text-[#fbbf24]">{{ t('sbo_progress.more_information_requested') }}</p>
        <p class="text-xs text-white/80">{{ props.status.infoRequest }}</p>

        <button
          v-if="props.canResubmit"
          type="button"
          :disabled="resubmitting"
          class="mt-3 self-start rounded-full bg-[#f59e0b] px-5 py-2 text-xs font-semibold text-[#1c1917] hover:bg-[#d97706] disabled:opacity-60"
          @click="resubmit"
        >
          {{ resubmitting ? t('sbo_progress.resubmitting') : t('sbo_progress.resubmit_for_review') }}
        </button>
      </div>

      <div
        v-if="props.status.declineReason"
        class="flex flex-col gap-1 rounded-2xl border border-[#ef4444]/40 bg-[#ef4444]/10 p-5"
      >
        <p class="text-sm font-semibold text-[#fca5a5]">{{ t('sbo_progress.application_declined') }}</p>
        <p class="text-xs text-white/80">{{ props.status.declineReason }}</p>
      </div>

      <div
        v-if="outstanding.length"
        class="flex flex-col gap-2 rounded-2xl border border-white/10 bg-white/[0.06] p-5"
      >
        <p class="text-sm font-semibold text-white">{{ t('sbo_progress.documents_still_needed') }}</p>
        <ul class="flex flex-col gap-1">
          <li v-for="d in outstanding" :key="d.typeId" class="text-xs text-white/80">
            {{ d.name }}
            <span v-if="d.status === 'rejected' && d.rejectionReason" class="text-[#fca5a5]">
              — {{ d.rejectionReason }}
            </span>
          </li>
        </ul>
      </div>

      <div class="flex flex-col gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-5">
        <div class="flex items-center gap-2">
          <!-- Was `w-full` and a literal "100% Complete". The screen is drawn
               as the completed state, but it is reachable before the last step
               is cleared, so it claimed 100% for applications that were not.
               It reads the real figure now, and fills to it. -->
          <UiLiquidBar
            :value="props.progress.barPercent"
            :color="fullyVerified ? 'var(--color-primary)' : '#e8a317'"
            label="Application progress"
          />
          <span
            class="shrink-0 text-sm font-semibold"
            :class="fullyVerified ? 'text-primary' : 'text-[#e8a317]'"
          >
            {{ t('application.percent_complete', { percent: props.progress.percent }) }}
          </span>
        </div>
        <!-- Every step drew a green tick regardless of state, so the list
             said "done" for stages that had not started. -->
        <ol class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
          <li v-for="s in props.progress.steps" :key="s.label" class="flex items-center gap-1.5">
            <img
              v-if="s.state === 'done'"
              :src="iconCheckCircle"
              alt=""
              class="size-4 shrink-0"
              aria-hidden="true"
            />
            <span
              v-else
              class="shrink-0 rounded-full"
              :class="s.state === 'active' ? 'size-3 bg-[#e8a317]' : 'size-2.5 bg-[#94a3b8]'"
              aria-hidden="true"
            />
            <span
              class="text-xs font-semibold"
              :class="{
                'text-white': s.state === 'done',
                'text-[#e8a317]': s.state === 'active',
                'text-[#94a3b8]': s.state === 'todo',
              }"
            >
              {{ s.label }}
            </span>
          </li>
        </ol>
      </div>
    </div>
  </section>

  <!-- =========================================== CoreContent 223:3363 ===== -->
  <div class="bg-surface-secondary section-x pt-8 pb-10">
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_420px] [&>*]:min-w-0">
      <!-- LeftCol 223:3364 -->
      <div class="flex min-w-0 flex-col gap-8">
        <section class="flex flex-col gap-6 rounded-[20px] border border-[#e2e8f0] bg-white p-5 sm:p-8 shadow-[0px_10px_12px_rgba(0,0,0,0.03)]">
          <div class="flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('sbo_progress.sbo_profile_details', { entity: entityTerm }) }}</h2>
            <img :src="iconBuilding" alt="" class="size-5" aria-hidden="true" />
          </div>
          <dl class="flex flex-col gap-4">
            <div v-for="d in details" :key="d.label" class="flex flex-col gap-0.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
              <dt class="text-sm text-[#475569] sm:shrink-0">{{ d.label }}</dt>
              <dd class="min-w-0 text-sm font-semibold text-[#1e293b] sm:text-right">{{ d.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- validation-notice-box 223:4163 -->
        <section class="flex items-center gap-4 rounded-2xl border border-[#e2e8f0] bg-[#ccfbf1] p-5">
          <span class="grid size-10 shrink-0 place-items-center rounded-[20px] border border-[#0d9488] bg-white">
            <img :src="iconShieldCheck" alt="" class="size-[18px]" aria-hidden="true" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-[#115e59]">{{ t('sbo_progress.your_registry_credentials_are_live') }}</p>
            <p class="mt-1 text-xs leading-snug text-[#475569]">
              {{ t('sbo_progress.your_credential_file_has_been_digitally_si') }}
            </p>
          </div>
        </section>

        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('sbo_progress.timeline_tracking') }}</h2>
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

      <!-- RightCol 223:3422 -->
      <aside class="flex flex-col gap-8">
        <!-- seal-card 223:4224 — the real badge and certificate, shared with
             the profile dashboard. This was a static ribbon glyph, a "VETTED"
             pill and the words "LBN Certified Member 2026" over a Download
             button with no handler, so an issued seal was never shown and the
             certificate that existed could not be reached. -->
        <SboSealPanel :seal="props.seal" />

        <!-- VerificationActionsCard 223:3423 -->
        <section class="flex flex-col gap-6 rounded-[20px] border border-[#e2e8f0] bg-white p-5 sm:p-8 shadow-[0px_10px_12px_rgba(0,0,0,0.03)]">
          <div class="flex items-center justify-between gap-3">
            <h2 class="font-sans text-sm font-semibold tracking-widest text-[#475569] uppercase">
              {{ t('sbo_progress.registration_license') }}
            </h2>
            <span class="rounded-md bg-[#d1fae5] px-2.5 py-1 text-2xs font-semibold text-[#047857]">
              {{ t('sbo_progress.validated') }}
            </span>
          </div>

          <div class="flex flex-col gap-3 rounded-xl bg-[#f8fafc] p-5">
            <p class="text-xs text-[#475569]">{{ t('sbo_progress.ministry_of_commerce_registration_number') }}</p>
            <p class="font-display text-xl font-semibold text-[#1e293b]">
              {{ t('sbo_progress.moc_2026_9001') }}
            </p>
          </div>

          <div class="flex flex-col gap-3">
            <p class="text-xs leading-snug text-[#475569]">
              {{ t('sbo_progress.your_sbo_is_fully_authorized_to_operate_wi', { entity: entityTerm }) }}
            </p>
            <p class="text-xs text-[#94a3b8]">
              {{ t('sbo_progress.valid_until_january_2027_authority_ministr') }}
            </p>
          </div>

          <button
            type="button"
            class="btn-primary flex w-full items-center justify-center gap-2 rounded-md px-5 py-3 text-xs font-semibold"
          >
            <img :src="iconShieldBtn" alt="" class="size-4" aria-hidden="true" />
            {{ t('sbo_progress.validate_registration') }}
          </button>
        </section>

        <MarketingRail :cards="['advertise']" />
      </aside>
    </div>
  </div>
</template>
