<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import UiLiquidBar from '@/components/ui/UiLiquidBar.vue'
import FeeCalculator, { type FeePayload } from '@/components/payment/FeeCalculator.vue'
import PaymentMethods from '@/components/payment/PaymentMethods.vue'

import trackCheck from '@/assets/figma/application/track-check.svg'
import trackDotActive from '@/assets/figma/application/track-dot-active.svg'
import trackDot from '@/assets/figma/application/track-dot.svg'
import stepDone from '@/assets/figma/application/step-done.svg'
import stepActive from '@/assets/figma/application/step-active.svg'
import stepPending from '@/assets/figma/application/step-pending.svg'
import feeDollar from '@/assets/figma/application/fee-dollar.svg'
import chevronDown from '@/assets/figma/application/chevron-down.svg'
import rateCheck from '@/assets/figma/application/rate-check.svg'
import nodeCheck from '@/assets/figma/application/node-check.svg'
import nodeDotRed from '@/assets/figma/application/node-dot-red.svg'
import nodeDot from '@/assets/figma/application/node-dot.svg'
import lockIcon from '@/assets/figma/application/lock.svg'
import buildingIcon from '@/assets/figma/application/building.svg'
import briefcaseIcon from '@/assets/figma/application/briefcase.svg'
import shieldAlertIcon from '@/assets/figma/application/shield-alert.svg'
import globeIcon from '@/assets/figma/application/globe.svg'
import usersIcon from '@/assets/figma/application/users.svg'
import benefitListings from '@/assets/figma/application/benefit-listings.jpg'
import benefitProcurement from '@/assets/figma/application/benefit-procurement.jpg'
import benefitNetwork from '@/assets/figma/application/benefit-network.jpg'
import promoPlate from '@/assets/figma/raw/sbo-promo-plate.jpg'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t } = useI18n()
const { entityTerm } = useEntityTerm()

/*
 * The screens of the registration flow that live on this route:
 *
 *   submitted    279:15798 "payment  required wall"  (designed, off-flow)
 *   fees         33:4      "Step 2: ... Fees"        Espees payment, 25%
 *   documents    253:7678  "Step 3: Required Documents"  50%
 *   verification 279:14175 "Step 4: Verified Status"     100%
 *
 * Payment page 1 (384:17211) and the manual-payment state (387:18447) are the
 * same design as /payment-required and render from PaymentRequiredView; see
 * `journey` below for the full six-screen order.
 *
 * fees/documents/verification share one shell: hero-section 33:30 (px-80 py-56, VIDEO fill over
 * a #0b192c -> #1b2b4b gradient, breadcrumb, title, and the four-node
 * hero-progress-tracker) above dashboard-workspace 33:60 (px-80 py-48, a
 * 500px left column with the application + timeline cards beside a fluid
 * right column that changes per step).
 *
 * Step 1 is a different screen: a centred locked hero, a status card beside a
 * "What happens next?" rail, and a three-card benefits band.
 */

defineOptions({ layout: AppLayout })

type RequiredDocument = {
  typeId: number
  name: string
  description: string | null
  required: boolean
  /** null until something has been uploaded for this type. */
  status: 'pending' | 'approved' | 'rejected' | null
  rejectionReason: string | null
  fileName: string | null
  downloadUrl: string | null
}

const props = defineProps<{
  step: string
  sbo: { id: number; name: string; slug: string; applicationId: string | null; progressUrl: string }
  progress: {
    percent: number
    /** Where the fill is drawn; see SboApplicationProgressBar::barPercent. */
    barPercent: number
    feesPaid: boolean
    /** Fee settled OR a payment submitted awaiting admin confirmation. */
    feeSettled: boolean
    documentsCompliant: boolean
    /** All required documents uploaded (any status), i.e. the owner's part done. */
    documentsSubmitted: boolean
    verified: boolean
  }
  /** Country-specific; see SboComplianceRequirements. */
  documents: RequiredDocument[]
  /** The typed Tax Identification Number, if the owner has entered it. */
  taxNumber: string | null
  /** The fee schedule, current selection and gateway state; see SboFeePayload. */
  fees: FeePayload
  /**
   * The company-details card. Was a fixed "Kemefy LLC / MRN-2026-0004 /
   * July 25, 2025", so every applicant read somebody else's registration.
   */
  application: { key: string; label: string; value: string }[]
}>()

// From the server, not re-derived from the URL — see SettingsView for the
// same change and INTEGRATION.md for why the shim is going away.
const step = computed(() => props.step || 'submitted')

/*
 * The journey is six screens and spans two views, so the order is held as
 * paths rather than step keys:
 *
 *   1  Application payment page 1              384:17211  /payment-required
 *   2  Step 2 fees — Espees payment            33:4       /application/fees
 *   3  Step 2 fees — Manual payment            387:18447  /payment-required?method=manual
 *   4  Step 3 Required Documents               253:7678   /application/documents
 *   5  Step 4 Verified Status                  279:14175  /application/verification
 *   6  sbo-progress                            223:3324   /sbo/progress
 *
 * The primary action on a step routes to the next; the tracker nodes and
 * timeline rows route to the screen they name, so you can walk back over what
 * is already done.
 *
 * Nothing is gated — the backend has no application state yet, so every step
 * is reachable. When it does, this is where the guard belongs.
 *
 * 279:15798 ("payment required wall") is a designed screen that is not in the
 * flow; it stays reachable at /application/submitted.
 */
const journey = [
  { key: 'payment', path: '/sbo/complete-payment-required' },
  { key: 'fees', path: '/application/fees' },
  { key: 'manual', path: '/sbo/complete-payment-required?method=manual' },
  { key: 'documents', path: '/application/documents' },
  { key: 'verification', path: '/application/verification' },
  { key: 'progress', path: '/sbo/progress' },
] as const

const index = computed(() => journey.findIndex((j) => j.key === step.value))
const nextPath = computed(() => journey[index.value + 1]?.path ?? null)

/* ------------------------------------------------- Step 3 document upload --
 * The step drew "Upload Documents →" and a Continue button with no upload
 * behind either. Each required type posts on its own to POST /sbo/documents,
 * which re-opens the review if the document had been rejected.
 */
const upload = useForm<{
  sbo_id: number
  compliance_document_type_id: number | null
  document: File | null
}>({
  sbo_id: props.sbo.id,
  compliance_document_type_id: null,
  document: null,
})

/** Which type is mid-upload, so only that row shows a spinner. */
const uploading = ref<number | null>(null)

function chooseFile(doc: RequiredDocument, event: Event) {
  const files = (event.target as HTMLInputElement).files
  const file = files && files.length ? files[0] : null
  const input = event.target as HTMLInputElement
  if (!file) return

  upload.sbo_id = props.sbo.id
  upload.compliance_document_type_id = doc.typeId
  upload.document = file
  uploading.value = doc.typeId

  upload.post('/sbo/documents', {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => {
      uploading.value = null
      upload.reset('document')
      // Clear the native input so re-picking the same file fires `change`.
      input.value = ''
    },
  })
}

const documentsOutstanding = computed(() =>
  props.documents.filter((d) => d.required && d.status !== 'approved').length,
)

/* --------------------------------------------------- Step 3 TIN number ------
 * The TIN itself is typed here; the certificate is uploaded above like any
 * other compliance document. Posts to /application/tin, saved onto the SBO.
 */
const tinForm = useForm<{ tax_number: string }>({ tax_number: props.taxNumber ?? '' })

function saveTin() {
  tinForm.post('/application/tin', { preserveScroll: true })
}

/** The pill copy per document status, using the step's own colour language. */
const DOCUMENT_STATES: Record<string, { label: string; classes: string }> = {
  approved: { label: t('application.approved'), classes: 'bg-[#d1fae5] text-[#007035]' },
  pending: { label: t('application.in_review'), classes: 'bg-[#fef3c7] text-[#b45309]' },
  rejected: { label: t('application.rejected'), classes: 'bg-[#fee2e2] text-[#991b1b]' },
}

/*
 * The four tracker/timeline nodes are the four numbered steps, which is a
 * coarser grouping than the six screens: step 2 covers both payment screens
 * and lands on the Espees one.
 */
const stepPaths = [
  '/sbo/complete-payment-required',
  '/application/fees',
  '/application/documents',
  '/application/verification',
]
const pathFor = (i: number) => stepPaths[i]

// Which of the four tracker nodes the current screen is, for aria-current.
const currentStepIndex = computed(() =>
  ['submitted', 'fees', 'documents', 'verification'].indexOf(step.value),
)

/* -------------------------------------------------- hero-progress-tracker --
 * node states read per frame: done -> check + white, active -> amber dot +
 * bold amber, pending -> grey dot + grey. Step 3's frame greys out node 2
 * rather than ticking it; that is what the design does.
 */
type NodeState = 'done' | 'active' | 'pending'
const trackerLabels = [
  t('dashboard.step_1_submitted'),
  t('dashboard.step_2_fees_payment'),
  t('dashboard.step_3_documents'),
  t('dashboard.step_4_verification'),
]

/*
 * Node states were fixed per screen, so an application that had already paid
 * still showed Step 2 as the active one. They are derived now: whichever stage
 * the URL names is `active`, anything the SBO has genuinely cleared is `done`.
 */
const trackerStates = computed<Record<string, NodeState[]>>(() => {
  // The owner's steps clear on THEIR action — payment made (settled or awaiting
  // confirmation) and documents uploaded. Verification clears only when the
  // admin has approved, which is the review at the end.
  const cleared = [
    true,
    props.progress.feeSettled,
    props.progress.documentsSubmitted,
    props.progress.verified,
  ]
  const current = ['submitted', 'fees', 'documents', 'verification'].indexOf(step.value)

  const states = cleared.map((isDone, i): NodeState => {
    if (isDone) return 'done'
    return i === current ? 'active' : 'pending'
  })

  return { submitted: states, fees: states, documents: states, verification: states }
})

/*
 * "Verified" means what isFullyVerified() means on the model: approved AND
 * documents accepted AND the fee settled. Registry approval alone is not
 * verification — that distinction is why the seal has a provisional state.
 */
const fullyVerified = computed(
  () => props.progress.verified && props.progress.documentsCompliant && props.progress.feesPaid,
)

const heroes = computed<Record<string, { crumb: string; title: string; lead: string; strong: string; tail: string; percent: number; cta?: string }>>(() => ({
  fees: {
    crumb: props.sbo.name,
    title: t('application.welcome_back', { name: props.sbo.name }),
    lead: t('application.your_loveworld_sbo_business_registration_i', { entity: entityTerm.value }),
    strong: `${props.progress.percent}% complete`,
    tail: '. Complete Step 2 to move forward.',
    percent: props.progress.percent,
  },
  documents: {
    crumb: props.sbo.name,
    title: t('application.your_applications'),
    lead: t('application.your_loveworld_sbo_business_registration_i', { entity: entityTerm.value }),
    strong: `${props.progress.percent}% complete`,
    tail: '. Complete Step 3 to move forward.',
    percent: props.progress.percent,
  },
  /*
   * This step is reachable by URL at any point in the flow, so its copy used
   * to congratulate applicants who were still awaiting verification.
   */
  verification: fullyVerified.value
    ? {
        crumb: t('application.business_dashboard'),
        title: t('application.welcome_back', { name: props.sbo.name }),
        lead: t('application.congratulations'),
        strong: props.sbo.name,
        tail: t('application.has_completed_the_verification_journey_you'),
        percent: props.progress.percent,
        cta: t('application.post_procurement_request'),
      }
    : {
        crumb: props.sbo.name,
        title: t('application.welcome_back', { name: props.sbo.name }),
        lead: t('application.your_loveworld_sbo_business_registration_i', { entity: entityTerm.value }),
        strong: `${props.progress.percent}% complete`,
        tail: props.progress.feesPaid
          ? '. Your documents are with the registry — verification follows once they are accepted.'
          : '. Verification begins once your registration fee is settled.',
        percent: props.progress.percent,
      },
  submitted: {
    crumb: props.sbo.name,
    title: t('application.welcome_back', { name: props.sbo.name }),
    lead: t('application.your_loveworld_sbo_business_registration_i', { entity: entityTerm.value }),
    strong: `${props.progress.percent}% complete`,
    tail: '. Complete Step 2 to move forward.',
    percent: props.progress.percent,
  },
}))

const hero = computed(() => heroes.value[step.value] ?? heroes.value.fees)
const nodes = computed(() => trackerStates.value[step.value] ?? trackerStates.value.fees)
const done = computed(() => props.progress.percent === 100)

const trackerIcon = (s: NodeState) =>
  s === 'done' ? trackCheck : s === 'active' ? trackDotActive : trackDot

/* ------------------------------------------------------ timeline-card 33:81 */

/*
 * The fee sentence read "Pay the calculated 50 Espees" on every application,
 * while the calculator beside it showed the real country-scoped amount — so an
 * applicant owing 250 was told to pay 50 in the same column.
 */
const feeAmount = computed(() => {
  if (props.fees.espees?.testAccount) return '1 Espee'
  const fee = props.fees.calculatedFee ?? props.fees.flatFee
  return fee === null || fee === undefined
    ? t('application.your_calculated_fee')
    : `${Number(fee).toLocaleString()} ${props.fees.currency ?? 'Espees'}`
})

/*
 * Three steps: (1) add business + payment, (2) documents, (3) verification.
 * Step 1 is only done once the fee is APPROVED — adding the business alone
 * never reads as complete. Every row reports its own real state, so the same
 * list serves whichever step the screen is showing.
 */
const threeStepTimeline = computed<{ title: string; body: string; state: NodeState }[]>(() => [
  {
    title: t('application.step_1_business_payment'),
    body: props.progress.feesPaid
      ? t('application.amount_cleared', { amount: feeAmount.value })
      : t('application.pay_to_proceed', { amount: feeAmount.value }),
    state: props.progress.feesPaid ? 'done' : 'active',
  },
  {
    title: t('application.step_2_documents'),
    body: props.progress.documentsCompliant
      ? t('application.verified_corporate_compliance_documents')
      : t('application.awaiting_document_review'),
    state: props.progress.documentsCompliant ? 'done' : props.progress.feesPaid ? 'active' : 'pending',
  },
  {
    title: t('application.step_3_verification'),
    body: fullyVerified.value
      ? t('application.final_approval_issued_from_loveworld_comme')
      : t('sbo_progress.issued_once_your_fee_and_documents_are_cle'),
    state: fullyVerified.value ? 'done' : 'pending',
  },
])

const timelines = computed<Record<string, { title: string; body: string; state: NodeState }[]>>(() => ({
  submitted: threeStepTimeline.value,
  fees: threeStepTimeline.value,
  documents: threeStepTimeline.value,
  verification: threeStepTimeline.value,
}))

const timeline = computed(() => timelines.value[step.value] ?? timelines.value.fees)

const stepIcon = (s: NodeState) =>
  s === 'done' ? stepDone : s === 'active' ? stepActive : stepPending

const details = computed(() => props.application)

/* ------------------------------------------------ step 4 workspace sidebar */
const summary = [
  { label: t('application.total_requests'), value: '0' },
  { label: t('application.open_requests'), value: '0' },
  { label: t('application.awarded_requests'), value: '0' },
  { label: t('application.bids_received'), value: '0' },
]

/* --------------------------------------------------- step 1 (279:15798) --- */
const nextSteps = [
  { n: '1', title: t('application.complete_payment'), body: t('application.pay_standard_regional_business_registratio'), active: true },
  { n: '2', title: t('application.document_review_begins'), body: t('application.our_administrative_desk_verifies_your_cons', { entity: entityTerm.value }), active: false },
  { n: '3', title: t('application.workspace_unlocks'), body: t('application.gain_full_control_of_corporate_profiles_ve'), active: false },
]

const benefits = [
  { title: t('application.business_listings'), body: t('application.launch_authorized_local_and_international'), img: benefitListings, icon: briefcaseIcon, tint: '#fef3c7' },
  { title: t('application.procurement_access'), body: t('application.pitch_on_active_loveworld_network_procurem'), img: benefitProcurement, icon: shieldAlertIcon, tint: '#e0f2fe' },
  { title: t('application.network_matching'), body: t('application.connect_seamlessly_with_thousands_of_certi'), img: benefitNetwork, icon: globeIcon, tint: '#e8f1f8' },
]

const wallStepper = [
  { label: t('application.submitted_100'), tone: 'text-[#007035] font-semibold' },
  { label: t('application.fees_pending_25'), tone: 'text-[#f59e0b] font-semibold' },
  { label: t('application.documents'), tone: 'text-[#94a3b8]' },
  { label: t('application.verify_seal'), tone: 'text-[#94a3b8]' },
]
</script>

<template>
  <!-- ===================================================== Step 1: Submitted
       payment required wall 279:15798 — hero-banner 279:15829 is a VIDEO fill
       over a #0f2b3c -> #0b192c gradient with the content centred. -->
  <template v-if="step === 'submitted'">
    <section
      class="relative overflow-hidden bg-linear-to-b from-[#0f2b3c] to-[#0b192c] section-x py-10 sm:py-16"
    >
      <HeroVideo overlay="bg-[#0b192c]/72" />

      <div class="relative mx-auto flex max-w-[800px] flex-col items-center gap-5 text-center">
        <span
          class="inline-flex items-center gap-1.5 rounded-full bg-[#fef3c7] px-3.5 py-1.5 text-2xs font-semibold text-[#92400e] uppercase"
        >
          <img :src="lockIcon" alt="" class="size-3.5" aria-hidden="true" />
          {{ t('application.workspace_locked') }}
        </span>

        <h1 class="font-serif text-5xl font-bold text-white">
          {{ t('application.complete_your_registration_payment') }}
        </h1>
        <p class="text-base leading-normal text-white/80">
          {{ t('application.your_business_profile_is_approved_and_wait') }}
        </p>

        <RouterLink
          v-if="nextPath"
          :href="nextPath"
          class="btn-primary rounded-lg px-5 sm:px-8 py-3.5 text-base font-semibold"
        >
          {{ t('application.go_to_secure_payment_step') }}
        </RouterLink>
      </div>
    </section>

    <!-- status-card-section 279:15840 -->
    <div class="flex flex-col items-start gap-8 bg-surface-secondary section-x py-8 sm:py-12 lg:flex-row">
      <!-- active-sbo-card 279:15841 -->
      <section
        class="flex min-w-0 flex-1 flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 drop-shadow-[0px_8px_12px_rgba(0,0,0,0.02)]"
      >
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-4">
            <span
              class="grid shrink-0 place-items-center rounded-lg bg-[#f8fafc] p-3"
              aria-hidden="true"
            >
              <img :src="buildingIcon" alt="" class="size-6" />
            </span>
            <div>
              <p class="text-2xs font-semibold text-[#475569] uppercase">
                {{ t('application.current_sbo_application', { entity: entityTerm }) }}
              </p>
              <p class="font-serif text-2xl font-bold text-[#0b192c]">{{ t('application.pending_sbo_demo_llc', { entity: entityTerm }) }}</p>
              <p class="text-xs text-[#94a3b8]">{{ t('application.application_id_mrn_2026_8941') }}</p>
            </div>
          </div>
          <span
            class="shrink-0 rounded-xl bg-[#fef3c7] px-4 py-1.5 text-xs font-semibold text-[#92400e]"
          >
            {{ t('application.payment_required') }}
          </span>
        </div>

        <hr class="border-[#e2e8f0]" />

        <!-- stepper-container 279:15855 — each label jumps to its step -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-wrap items-start justify-between gap-2 text-xs">
            <RouterLink
              v-for="(s, i) in wallStepper"
              :key="s.label"
              :href="pathFor(i)"
              :class="[s.tone, 'transition-opacity hover:opacity-80']"
            >
              {{ s.label }}
            </RouterLink>
          </div>
          <div class="h-2.5 overflow-hidden rounded-md bg-[#e2e8f0]">
            <div class="h-full w-[21%] bg-[#f59e0b]" />
          </div>
        </div>

        <p class="text-xs leading-normal text-[#475569]">
          {{ t('application.your_sbo_certification_requires_a_statutor', { entity: entityTerm }) }}
        </p>
      </section>

      <!-- next-steps-sidebar 279:15864 -->
      <aside
        class="flex w-full shrink-0 flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 drop-shadow-[0px_8px_12px_rgba(0,0,0,0.02)] lg:w-[380px]"
      >
        <h2 class="font-display text-xl font-semibold text-[#0b192c]">{{ t('application.what_happens_next') }}</h2>
        <ol class="flex flex-col gap-4">
          <li v-for="s in nextSteps" :key="s.n" class="flex items-start gap-3">
            <span
              class="grid size-6 shrink-0 place-items-center rounded-xl text-xs font-semibold"
              :class="s.active ? 'bg-[#fef3c7] text-[#92400e]' : 'bg-[#f8fafc] text-[#475569]'"
            >
              {{ s.n }}
            </span>
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-[#0f172a]">{{ s.title }}</span>
              <span class="block text-xs text-[#475569]">{{ s.body }}</span>
            </span>
          </li>
        </ol>
      </aside>
    </div>

    <!-- benefits-section 279:15885 -->
    <section class="flex flex-col gap-8 border-y border-[#e2e8f0] bg-[#f1f5f9] section-x py-10 sm:py-16">
      <header class="flex flex-col gap-3 text-center">
        <h2 class="font-serif text-3xl font-bold text-[#0b192c]">
          {{ t('application.unlock_your_full_business_potential') }}
        </h2>
        <p class="text-base text-[#475569]">
          {{ t('application.settle_your_statutory_filing_fee_to_releas') }}
        </p>
      </header>

      <ul class="grid gap-6 md:grid-cols-3 [&>*]:min-w-0">
        <li
          v-for="b in benefits"
          :key="b.title"
          class="flex flex-col gap-4 overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white p-6"
        >
          <img :src="b.img" alt="" class="h-[140px] w-full object-cover" aria-hidden="true" />
          <span
            class="grid w-fit place-items-center rounded-xl p-3"
            :style="{ backgroundColor: b.tint }"
            aria-hidden="true"
          >
            <img :src="b.icon" alt="" class="size-6" />
          </span>
          <div class="flex flex-col gap-2">
            <h3 class="font-display text-xl font-semibold text-[#0b192c]">{{ b.title }}</h3>
            <p class="text-sm leading-normal text-[#475569]">{{ b.body }}</p>
          </div>
        </li>
      </ul>
    </section>
  </template>

  <!-- ============================================ Steps 2-4 shared shell === -->
  <template v-else>
    <!-- hero-section 33:30 -->
    <section
      class="relative overflow-hidden bg-linear-to-b from-[#0b192c] to-[#1b2b4b] section-x py-9 sm:py-14"
    >
      <HeroVideo overlay="bg-[#0b192c]/72" />

      <div class="relative flex flex-col gap-7">
        <nav class="flex items-center gap-2 text-xs" :aria-label="t('application.breadcrumb')">
          <RouterLink href="/dashboard" class="text-[#94a3b8] hover:text-white">
            {{ t('application.applications') }}
          </RouterLink>
          <span class="text-[#94a3b8]" aria-hidden="true">{{ t('application.gt') }}</span>
          <span class="text-white">{{ hero.crumb }}</span>
        </nav>

        <div class="flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div class="flex max-w-[700px] flex-col gap-2">
            <h1 class="font-serif text-4xl font-bold text-white">
              {{ hero.title }}
            </h1>
            <p
              class="text-base text-[#94a3b8]"
            >{{ hero.lead }}<strong
                class="font-display"
                :class="done ? 'font-semibold text-white' : 'font-semibold text-[#f59e0b]'"
              >{{ hero.strong }}</strong>{{ hero.tail }}</p>
          </div>

          <RouterLink
            v-if="hero.cta"
            href="/procurement/new"
            class="btn-primary shrink-0 rounded-lg px-6 py-3 text-sm font-semibold whitespace-nowrap"
          >
            {{ hero.cta }}
          </RouterLink>
        </div>

        <!-- hero-progress-tracker 33:41 — nodes navigate between steps -->
        <div class="flex flex-col gap-3 rounded-2xl border border-white/10 bg-white/6 p-5">
          <div class="flex items-center gap-2">
            <UiLiquidBar
              :value="props.progress.barPercent"
              :color="done ? '#007035' : '#f59e0b'"
              label="Application progress"
            />
            <p
              class="text-sm font-semibold whitespace-nowrap"
              :class="done ? 'text-[#007035]' : 'text-[#f59e0b]'"
            >
              {{ t('application.percent_complete', { percent: hero.percent }) }}
            </p>
          </div>

          <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <RouterLink
              v-for="(label, i) in trackerLabels"
              :key="label"
              :href="pathFor(i)"
              class="flex items-center gap-1.5 rounded transition-opacity hover:opacity-80"
              :aria-current="i === currentStepIndex ? 'step' : undefined"
            >
              <img
                :src="trackerIcon(nodes[i])"
                alt=""
                :class="nodes[i] === 'done' ? 'size-4' : 'size-2'"
                aria-hidden="true"
              />
              <span
                class="text-xs whitespace-nowrap"
                :class="{
                  'font-semibold text-white': nodes[i] === 'done',
                  'font-semibold text-[#f59e0b]': nodes[i] === 'active',
                  'text-[#94a3b8]': nodes[i] === 'pending',
                }"
              >
                {{ label }}
              </span>
            </RouterLink>
          </div>
        </div>
      </div>
    </section>

    <!-- dashboard-workspace 33:60 -->
    <div class="flex flex-col items-start gap-8 bg-surface-secondary section-x py-8 sm:py-12 lg:flex-row">
      <!-- workspace-left 33:61 / 279:14266.
           The sizing inverts on the last step: fees and documents pin the left
           column at 500 and let the right fill, while verification fills the
           left (708) against a fixed 380 sidebar.

           Keyed on the STEP, not on `done`. It used to read `progress.percent
           === 100`, but the right-hand column has always switched on the step —
           so an owner sitting on /application/verification before they are
           fully verified got the 500px left column against the 380px
           verification sidebar, and the row came up ~208px short of the
           content width. Completion and "which step am I looking at" are
           different questions. -->
      <div
        class="flex w-full flex-col gap-6"
        :class="step === 'verification' ? 'min-w-0 lg:flex-1' : 'shrink-0 lg:w-[500px]'"
      >
        <!-- company-details-card 33:62 -->
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <div class="flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('application.your_application') }}</h2>
            <!-- Was a hardcoded green "Approved Status" for every applicant;
                 reflects the real state until an admin approves. -->
            <span
              class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold"
              :class="props.progress.verified
                ? 'border-[#a7f3d0] bg-[#d1fae5] text-[#007035]'
                : 'border-[#fde68a] bg-[#fef3c7] text-[#b45309]'"
            >
              {{ props.progress.verified ? t('application.approved_status') : t('data.status.awaiting_verification') }}
            </span>
          </div>

          <hr class="border-[#e2e8f0]" />

          <dl class="flex flex-col gap-3.5 text-sm">
            <div
              v-for="d in details"
              :key="d.label"
              class="flex flex-col gap-0.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
            >
              <dt class="text-[#64748b] sm:shrink-0">{{ d.label }}</dt>
              <dd class="min-w-0 font-semibold text-[#1e293b] sm:text-right">{{ d.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- timeline-card 33:81 — each row is the step it describes -->
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('application.timeline_tracking') }}</h2>

          <ol class="flex flex-col gap-3">
            <li v-for="(node, i) in timeline" :key="node.title">
              <RouterLink
                :href="pathFor(i)"
                class="flex items-center gap-4 rounded-xl border p-4 transition-colors"
                :class="
                  node.state === 'active'
                    ? 'border-[#f59e0b] bg-[#fef3c7]'
                    : 'border-[#e2e8f0] bg-white hover:bg-surface-secondary'
                "
                :aria-current="i === currentStepIndex ? 'step' : undefined"
              >
                <img :src="stepIcon(node.state)" alt="" class="size-6 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                  <p
                    class="text-sm"
                    :class="
                      node.state === 'active'
                        ? 'font-semibold text-[#f59e0b]'
                        : node.state === 'done'
                          ? 'font-semibold text-[#64748b]'
                          : 'font-semibold text-[#1e293b]'
                    "
                  >
                    {{ node.title }}
                  </p>
                  <p
                    class="text-xs"
                    :class="node.state === 'done' ? 'text-[#94a3b8]' : 'text-[#64748b]'"
                  >
                    {{ node.body }}
                  </p>
                </div>
                <p
                  v-if="node.state !== 'pending'"
                  class="shrink-0 text-2xs uppercase"
                  :class="
                    node.state === 'active'
                      ? 'font-semibold text-[#f59e0b]'
                      : 'font-semibold text-[#007035]'
                  "
                >
                  {{ node.state === 'active' ? t('security.active') : t('application.done') }}
                </p>
              </RouterLink>
            </li>
          </ol>
        </section>
      </div>

      <!-- workspace-right — one card per step -------------------------------->

      <!-- Step 2: registration-fee-payment-card 33:111 ------------------
           The calculator, the confirmed state and both payment methods. Was a
           single select of three invented structures, a hardcoded "50 Espees"
           and a "Pay Fee Now" link that only navigated to the next step. -->
      <div v-if="step === 'fees'" class="flex min-w-0 flex-1 flex-col gap-6">
        <FeeCalculator :fees="props.fees" />
        <PaymentMethods :sbo-id="props.fees.sboId" :fees="props.fees" />
      </div>

      <!-- Step 3: workspace-right 253:7813 -->
      <div v-else-if="step === 'documents'" class="flex min-w-0 flex-1 flex-col gap-6">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-serif text-2xl font-bold text-[#1e293b]">{{ t('application.your_applications') }}</h2>
          <RouterLink href="/dashboard" class="text-sm font-semibold text-[#0e8345] underline">
            {{ t('application.view_all') }}
          </RouterLink>
        </div>

        <!-- SBO-Card 253:8170 -->
        <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-6">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-col gap-1.5">
              <div class="flex items-center gap-3">
                <p class="font-display text-lg font-semibold text-[#1e293b]">{{ props.sbo.name }}</p>
                <span
                  class="rounded-md px-2.5 py-1 text-2xs font-semibold"
                  :class="
                    documentsOutstanding
                      ? 'bg-[#fee2e2] text-[#991b1b]'
                      : 'bg-[#d1fae5] text-[#007035]'
                  "
                >
                  {{ documentsOutstanding ? t('dashboard.action_required') : t('application.all_submitted') }}
                </span>
              </div>
              <p v-if="props.sbo.applicationId" class="text-xs text-[#64748b]">
                {{ t('application.application_id_is', { id: props.sbo.applicationId }) }}
              </p>
            </div>
          </div>

          <hr class="border-[#e2e8f0]" />

          <!-- Progress-Tracker 253:8181 — real percent, not a fixed 50%. -->
          <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
              <div class="h-2 flex-1 overflow-hidden rounded bg-[#e2e8f0]">
                <!-- Animated: the bar fills to the new value rather than
                     snapping, so clearing a step is visible. -->
                <div
                  class="h-full transition-[width] duration-700 ease-out motion-reduce:transition-none"
                  :class="documentsOutstanding ? 'bg-[#ef4444]' : 'bg-[#007035]'"
                  :style="{ width: `${props.progress.percent}%` }"
                />
              </div>
              <p
                class="text-xs font-semibold"
                :class="documentsOutstanding ? 'text-[#991b1b]' : 'text-[#007035]'"
              >
                {{ props.progress.percent }}%
              </p>
            </div>

            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
              <p class="flex items-center gap-2 text-xs font-medium text-[#1e293b]">
                <img :src="nodeCheck" alt="" class="size-4" aria-hidden="true" /> {{ t('application.submitted') }}
              </p>
              <p
                class="flex items-center gap-2 text-xs font-medium"
                :class="props.progress.feesPaid ? 'text-[#1e293b]' : 'text-[#64748b]'"
              >
                <img
                  :src="props.progress.feesPaid ? nodeCheck : nodeDot"
                  alt=""
                  :class="props.progress.feesPaid ? 'size-4' : 'size-2'"
                  aria-hidden="true"
                />
                {{ t('application.fees') }}
              </p>
              <p
                class="flex items-center gap-2 text-xs font-medium"
                :class="props.progress.documentsCompliant ? 'text-[#1e293b]' : 'text-[#991b1b]'"
              >
                <img
                  :src="props.progress.documentsCompliant ? nodeCheck : nodeDotRed"
                  alt=""
                  :class="props.progress.documentsCompliant ? 'size-4' : 'size-2'"
                  aria-hidden="true"
                />
                {{ t('application.documents') }}
              </p>
              <p
                class="flex items-center gap-2 text-xs font-medium"
                :class="props.progress.verified ? 'text-[#1e293b]' : 'text-[#64748b]'"
              >
                <img
                  :src="props.progress.verified ? nodeCheck : nodeDot"
                  alt=""
                  :class="props.progress.verified ? 'size-4' : 'size-2'"
                  aria-hidden="true"
                />
                {{ t('application.verification') }}
              </p>
            </div>
          </div>

          <p class="text-sm font-medium text-[#475569]">
            {{ t('application.status') }}
            <template v-if="documentsOutstanding">
              {{ t('application.documents_outstanding', { count: documentsOutstanding }) }}
            </template>
            <template v-else>
              {{ t('application.everything_required_has_been_submitted_our') }}
            </template>
          </p>

          <!-- TIN number — typed, not uploaded. (The TIN certificate is an
               upload in the list below, like any other document.) -->
          <div class="flex flex-col gap-2 rounded-lg border border-[#e2e8f0] p-4">
            <label for="tin-number" class="text-sm font-semibold text-[#1e293b]">
              {{ t('application.tax_identification_number_tin') }}
            </label>
            <form class="flex flex-wrap items-center gap-3" @submit.prevent="saveTin">
              <input
                id="tin-number"
                v-model="tinForm.tax_number"
                inputmode="numeric"
                :placeholder="t('application.enter_your_tin_number')"
                class="min-w-0 flex-1 rounded-md border bg-white px-4 py-2.5 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
                :class="tinForm.errors.tax_number ? 'border-danger focus:border-danger' : 'border-[#e2e8f0] focus:border-focus'"
              />
              <button
                type="submit"
                :disabled="tinForm.processing"
                class="rounded-md btn-primary px-5 py-2.5 text-sm font-semibold disabled:opacity-60"
              >
                {{ t('application.save') }}
              </button>
            </form>
            <span v-if="tinForm.errors.tax_number" class="text-xs text-danger">{{ tinForm.errors.tax_number }}</span>
            <span v-else-if="tinForm.recentlySuccessful" class="text-xs text-[#007035]">{{ t('application.tin_saved') }}</span>
          </div>

          <!--
            Required-documents list. The step drew an "Upload Documents →" link
            and a Continue button with nothing behind either; this is the upload
            itself, in the card's own idiom. The list is country-specific and
            comes from the server (SboComplianceRequirements).
          -->
          <ul v-if="props.documents.length" class="flex flex-col gap-3">
            <li
              v-for="d in props.documents"
              :key="d.typeId"
              class="flex flex-col gap-2 rounded-lg border border-[#e2e8f0] p-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-[#1e293b]">
                    {{ d.name }}
                    <span v-if="!d.required" class="font-normal text-[#94a3b8]">{{ t('application.optional') }}</span>
                  </p>
                  <p v-if="d.description" class="mt-0.5 text-xs text-[#64748b]">
                    {{ d.description }}
                  </p>
                </div>
                <span
                  v-if="d.status"
                  class="shrink-0 rounded-md px-2.5 py-1 text-2xs font-semibold"
                  :class="DOCUMENT_STATES[d.status].classes"
                >
                  {{ DOCUMENT_STATES[d.status].label }}
                </span>
              </div>

              <p v-if="d.status === 'rejected' && d.rejectionReason" class="text-xs text-danger">
                {{ d.rejectionReason }}
              </p>

              <div class="flex flex-wrap items-center gap-3">
                <label
                  class="cursor-pointer rounded-md border border-[#e2e8f0] bg-white px-3.5 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
                >
                  {{
                    uploading === d.typeId
                      ? t('documents.uploading')
                      : d.status
                        ? t('application.replace_file')
                        : t('payment_methods.choose_file')
                  }}
                  <input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    class="sr-only"
                    :disabled="uploading !== null"
                    @change="chooseFile(d, $event)"
                  />
                </label>
                <a
                  v-if="d.downloadUrl"
                  :href="d.downloadUrl"
                  class="min-w-0 truncate text-xs font-medium text-[#0e8345] underline"
                >
                  {{ d.fileName }}
                </a>
                <span v-else class="text-xs text-[#94a3b8]">{{ t('application.pdf_jpg_or_png_max_10_mb') }}</span>
              </div>
            </li>
          </ul>

          <p v-else class="text-xs text-[#64748b]">
            {{ t('application.no_document_types', { name: props.sbo.name }) }}
          </p>

          <p v-if="upload.errors.document" class="text-xs text-danger">
            {{ upload.errors.document }}
          </p>

          <div class="flex flex-col gap-3">
            <RouterLink
              v-if="nextPath"
              :href="nextPath"
              class="btn-primary rounded-lg py-3.5 text-center text-base font-semibold"
            >
              {{ t('application.continue') }}
            </RouterLink>
          </div>
        </section>
      </div>

      <!-- Step 4: workspace-sidebar 279:14324 -->
      <div v-else class="flex w-full min-w-0 shrink-0 flex-col gap-8 lg:w-[380px]">
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-6">
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('application.workspace_summary') }}</h2>
          <p class="text-xs text-[#475569]">
            {{ t('application.a_quick_view_of_your_activity_across_the_r') }}
          </p>
          <dl class="flex flex-col text-sm">
            <div
              v-for="s in summary"
              :key="s.label"
              class="flex items-start justify-between border-b border-[#f1f5f9] py-2"
            >
              <dt class="text-[#475569]">{{ s.label }}</dt>
              <dd class="font-semibold text-[#1e293b]">{{ s.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- need-help-card 279:14341 -->
        <section class="flex flex-col gap-4 rounded-2xl border border-[#f59e0b] bg-[#fef3c7] p-6">
          <h2 class="font-display text-lg font-semibold text-[#b45309]">{{ t('application.need_help') }}</h2>
          <p class="text-xs text-[#1e293b]">
            {{ t('application.the_registry_team_can_help_with_applicatio') }}
          </p>
          <RouterLink href="/support" class="text-sm font-semibold text-[#b45309]">
            {{ t('application.contact_support') }}
          </RouterLink>
        </section>

        <!-- next-milestone-card 279:14346 -->
        <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6">
          <span
            class="w-fit rounded-full bg-[#f1f5f9] px-2.5 py-1 text-2xs font-semibold text-[#475569]"
          >
            {{ t('application.next_milestone') }}
          </span>
          <h2 class="font-display text-xl font-semibold text-[#1e293b]">
            {{ t('application.procurement_amp_network_access') }}
          </h2>
          <p class="text-xs text-[#475569]">
            {{ t('application.once_approved_unlock_global_procurements_r') }}
          </p>
          <div class="flex flex-col gap-3">
            <RouterLink
              href="/procurement/new"
              class="btn-primary rounded-lg px-4 py-2.5 text-center text-xs font-semibold"
            >
              {{ t('application.post_procurement_request') }}
            </RouterLink>
            <RouterLink
              href="/sbo/network"
              class="flex items-center justify-center gap-1.5 rounded-lg border border-[#e2e8f0] px-4 py-2.5 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
            >
              <img :src="usersIcon" alt="" class="size-3.5" aria-hidden="true" />
              {{ t('application.open_sbo_network', { entity: entityTerm }) }}
            </RouterLink>
          </div>
        </section>
      </div>
    </div>

    <!-- promo-cta-banner 279:14359 — Step 4 only -->
    <section v-if="done" class="bg-surface-secondary section-x pb-12">
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
            {{ t('application.reach_verified_loveworld_businesses') }}
          </h2>
          <p class="text-base leading-6 text-[#94a3b8]">
            {{ t('application.connect_with_over_975_validated_spirit_led') }}
          </p>
        </div>
        <RouterLink
          href="/advertise"
          class="btn-primary relative shrink-0 rounded-xl px-5 sm:px-7 py-3.5 text-sm font-semibold whitespace-nowrap"
        >
          {{ t('application.advertise_with_us') }}
        </RouterLink>
      </div>
    </section>
  </template>
</template>
