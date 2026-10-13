<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'

import iconUploadCloud from '@/assets/figma/bid/upload-cloud.svg'
import iconAlertTriangle from '@/assets/figma/bid/alert-triangle.svg'
import iconCheck from '@/assets/figma/bid/check.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t } = useI18n()
const { entityTerm } = useEntityTerm()

defineOptions({ layout: AppLayout })

/*
 * Submit a bid — five Figma frames behind one route:
 *
 *   1 Contact Information        509:53900 -> contact-information 509:55036
 *   2 Address Confirmation       509:54184 -> address-confirmation 509:55149
 *   3 Proposal Contents Checklist 509:52740 -> kemafy-response-form 509:53295
 *   4 Company Profile            509:54468 -> company-profile 509:55298
 *   5 Submit Form                509:54752 -> submit-form 509:55437
 *
 * Every step shares one shell: hero-section 509:53902 (px-80 py-48, VIDEO
 * fill), then a 300px white sidebar carrying the 5-item stepper beside a
 * #f8fafc main panel whose top-bar shows the response name and Export to CSV.
 * Content is a stack of 12px-radius form-section-cards, closed by
 * bottom-actions. bottom-sticky-bar 509:53918 is pinned below.
 *
 * Step 3 breaks the card pattern: its cards are named card-signer /
 * card-official / card-certs / card-proposal and use a 16px radius.
 */

const props = defineProps<{
  id: string
  step: string
  request: {
    id: string
    title: string
    body: string | null
    category: string | null
    buyer: string | null
    budget: string | null
    status: string
    bids: string
    posted: string | null
    deadline: string | null
  }
  /** The SBOs the owner can bid as. */
  sbos: { id: number; name: string }[]
  ownRequest: boolean
  /** No verified listing, so the endpoint will refuse the bid. */
  unverified: boolean
  closed: boolean
  existingBid: { submittedAt: string | null; status: string } | null
  contact: { name: string; email: string }
}>()

// Server-supplied; the header previously named a fixed RFQ regardless of id.
const step = computed(() => Number(props.step ?? 1))
const id = computed(() => props.id)

// Only a taste of the brief in the header — the full specification lives on the
// request's own page; a bidder here is filling in their response, not reading.
const bodyExcerpt = computed(() => {
  const body = (props.request.body ?? '').trim()
  if (body.length <= 240) return body
  return body.slice(0, 240).replace(/\s+\S*$/, '') + '…'
})

const steps = [
  { n: 1, label: t('bid_wizard.contact_information') },
  { n: 2, label: t('bid_wizard.address_confirmation') },
  { n: 3, label: t('bid_wizard.proposal_contents_checklist') },
  { n: 4, label: t('bid_wizard.company_profile') },
  { n: 5, label: t('bid_wizard.submit_form') },
]

const pathFor = (n: number) => `/procurement/${id.value}/bid/${n}`
const nextPath = computed(() => (step.value < 5 ? pathFor(step.value + 1) : null))

// step 1 — Preferred Communication 509:55133
const channels = ['Email', 'Phone', 'Either']

const sameAsRegistered = ref(false)

// step 3 — Mandatory Certifications 1.3 & 1.4
const certs = [
  'I certify that I have read, understood, and agree to be bound to the terms in the General Instructions, Solicitation Instructions, Award Process, and Legal Notice in this solicitation, and that I am authorized to submit this response.',
  'I certify that I have read, understood, and agree, on behalf of the firm submitting this response, to the terms and conditions including, but not limited to, the insurance requirements set forth herein.',
]

// step 5 — Submission Summary / Final Declarations 509:55437
const summaryRows = steps.slice(0, 4).map((s) => ({ n: s.n, label: s.label }))
const declarations = [
  'I certify that all information provided in this response is completely accurate, truthful, and representative of my business.',
  'I authorize Loveworld Registry and affiliated auditing partners to verify the business registrations and insurance documents submitted.',
]

/* --------------------------------------------------------- the actual bid --
 *
 * POST /procurement/{id}/bid takes three things: `bidder_sbo_id`, a `proposal`
 * and an optional `attachment`. The wizard collects far more than that, and
 * `purchase_order_bids` has no columns for the rest — so every answer is
 * gathered here and composed into the proposal document that IS the bid. That
 * is lossless in the sense that matters: the buyer reads all of it.
 *
 * Step navigation carries `preserve-state`, so moving between steps keeps this
 * form alive. Without it each step is a fresh mount and the previous answers
 * are gone — which is why the wizard could only ever have posted one step.
 */
const form = useForm<{
  bidder_sbo_id: number | null
  attachments: File[]
  primaryName: string
  primaryTitle: string
  primaryEmail: string
  primaryPhone: string
  altName: string
  altTitle: string
  altEmail: string
  altPhone: string
  channel: string
  street: string
  suite: string
  city: string
  state: string
  zip: string
  signers: string
  signerTitles: string
  signerEmails: string
  authorizedOfficial: string
  certified: boolean[]
  legalName: string
  dba: string
  yearEstablished: string
  employees: string
  duns: string
  taxId: string
  generalLiability: string
  professionalLiability: string
  declared: boolean[]
  signature: string
}>({
  bidder_sbo_id: props.sbos.length === 1 ? props.sbos[0].id : null,
  attachments: [],
  primaryName: props.contact.name,
  primaryTitle: '',
  primaryEmail: props.contact.email,
  primaryPhone: '',
  altName: '',
  altTitle: '',
  altEmail: '',
  altPhone: '',
  channel: channels[0],
  street: '',
  suite: '',
  city: '',
  state: '',
  zip: '',
  signers: '',
  signerTitles: '',
  signerEmails: '',
  authorizedOfficial: '',
  certified: [false, false],
  legalName: props.sbos.length === 1 ? props.sbos[0].name : '',
  dba: '',
  yearEstablished: '',
  employees: '',
  duns: '',
  taxId: '',
  generalLiability: '',
  professionalLiability: '',
  declared: [false, false],
  signature: '',
})

/*
 * step 1 draws two identical contact blocks. They were a `v-for` over two
 * labels with the values chosen by comparing the label string; naming the four
 * form keys per block is what lets them actually bind.
 */
const contactGroups = [
  {
    title: t('bid_wizard.primary_contact'),
    name: 'primaryName',
    jobTitle: 'primaryTitle',
    email: 'primaryEmail',
    phone: 'primaryPhone',
    // The alternate contact is optional, so only the primary is marked required.
    required: true,
  },
  {
    title: t('bid_wizard.alternate_contact'),
    name: 'altName',
    jobTitle: 'altTitle',
    email: 'altEmail',
    phone: 'altPhone',
    required: false,
  },
] as const

/** Checkbox groups post as arrays; this is the toggle for one member. */
function toggle(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value]
}

// Uploads accumulate across both file inputs and across several picks, deduped
// by name+size, so a bidder builds one list of documents.
function onAttachment(event: Event) {
  const input = event.target as HTMLInputElement
  const picked = input.files ? Array.from(input.files) : []

  for (const file of picked) {
    if (!form.attachments.some((f) => f.name === file.name && f.size === file.size)) {
      form.attachments.push(file)
    }
  }

  input.value = ''
}

function removeAttachment(index: number) {
  form.attachments.splice(index, 1)
}

/** Everything the wizard collected, as the proposal the buyer reads. */
function composeProposal(): string {
  const line = (label: string, value: string) => (value.trim() ? `${label}: ${value.trim()}` : null)

  return [
    '## Contact',
    line('Primary', `${form.primaryName} (${form.primaryTitle})`),
    line('Primary email', form.primaryEmail),
    line('Primary phone', form.primaryPhone),
    line('Alternate', `${form.altName} (${form.altTitle})`),
    line('Alternate email', form.altEmail),
    line('Alternate phone', form.altPhone),
    line('Preferred channel', form.channel),
    '',
    '## Address',
    line('Street', [form.street, form.suite].filter(Boolean).join(', ')),
    line('City', form.city),
    line('State', form.state),
    line('ZIP', form.zip),
    '',
    '## Proposal contents',
    line('Authorized signatories', form.signers),
    line('Signatory titles', form.signerTitles),
    line('Signatory emails', form.signerEmails),
    line('Authorized official', form.authorizedOfficial),
    line('Mandatory certifications', form.certified.every(Boolean) ? 'Both accepted' : 'Incomplete'),
    '',
    '## Company profile',
    line('Legal name', form.legalName),
    line('Doing business as', form.dba),
    line('Year established', form.yearEstablished),
    line('Employees', form.employees),
    line('DUNS', form.duns),
    line('Tax ID / EIN', form.taxId),
    line('General liability cover', form.generalLiability),
    line('Professional liability cover', form.professionalLiability),
    '',
    '## Declaration',
    line('Signed by', form.signature),
    line('Signed on', new Date().toISOString().slice(0, 10)),
  ]
    .filter((row) => row !== null)
    .join('\n')
}

const canSubmit = computed(
  () =>
    !props.ownRequest &&
    // The endpoint refuses an unverified bidder; the button says so first.
    !props.unverified &&
    !props.closed &&
    form.bidder_sbo_id !== null &&
    form.certified.every(Boolean) &&
    form.declared.every(Boolean) &&
    form.signature.trim().length > 0,
)

function submit() {
  if (!canSubmit.value) return

  form
    .transform((data) => ({
      bidder_sbo_id: data.bidder_sbo_id,
      // The readable document the buyer reads end to end...
      proposal: composeProposal(),
      // ...and the same answers as fields, so the RFQ's Interest tab can show
      // a submitted bid instead of one long string. Nested under the wizard's
      // own step groupings, which is how the detail panel reads it back.
      details: {
        contact: {
          primaryName: data.primaryName,
          primaryTitle: data.primaryTitle,
          primaryEmail: data.primaryEmail,
          primaryPhone: data.primaryPhone,
          altName: data.altName,
          altTitle: data.altTitle,
          altEmail: data.altEmail,
          altPhone: data.altPhone,
          channel: data.channel,
        },
        address: {
          street: data.street,
          suite: data.suite,
          city: data.city,
          state: data.state,
          zip: data.zip,
        },
        company: {
          legalName: data.legalName,
          dba: data.dba,
          yearEstablished: data.yearEstablished,
          employees: data.employees,
          duns: data.duns,
          taxId: data.taxId,
          generalLiability: data.generalLiability,
          professionalLiability: data.professionalLiability,
        },
        declaration: {
          signers: data.signers,
          signerTitles: data.signerTitles,
          signerEmails: data.signerEmails,
          authorizedOfficial: data.authorizedOfficial,
          certified: data.certified.every(Boolean),
          declared: data.declared.every(Boolean),
          signature: data.signature,
          signedOn: new Date().toISOString().slice(0, 10),
        },
      },
      attachments: data.attachments,
    }))
    .post(`/procurement/${id.value}/bid`, { forceFormData: true })
}
</script>

<template>
  <!-- ======================================= hero-section 509:53902 ======= -->
  <section class="relative overflow-hidden bg-[#0b192c] section-x py-8 sm:py-12">
    <HeroVideo overlay="bg-[#0b192c]/80" />

    <div class="relative flex flex-col gap-7">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('bid_wizard.breadcrumb')">
        <RouterLink href="/sbo/dashboard" class="text-[#94a3b8] hover:text-white">
          {{ t('bid_wizard.sbo_workspace', { entity: entityTerm }) }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('bid_wizard.gt') }}</span>
        <span class="text-white">{{ t('bid_wizard.bids') }}</span>
      </nav>

      <div class="flex max-w-[675px] flex-col gap-2">
        <h1 class="font-serif text-4xl font-bold text-white">
          {{ request.title }}
        </h1>
        <p class="text-base whitespace-pre-line text-[#94a3b8]">
          {{ bodyExcerpt || t('bid_wizard.no_description_was_provided_for_this_reque') }}
        </p>
      </div>
    </div>
  </section>

  <!-- ============================ sidebar + main-panel 509:55036 ========== -->
  <div class="flex flex-col bg-[#f8fafc] lg:flex-row">
    <!-- sidebar 509:55037 -->
    <aside
      class="flex w-full min-w-0 shrink-0 flex-col gap-10 border-b border-[#e2e8f0] bg-white px-6 py-10 lg:w-[300px] lg:border-r lg:border-b-0"
    >
      <div class="flex flex-col gap-1.5">
        <p class="font-serif text-3xl font-bold text-[#0f172a]">{{ t('bid_wizard.loveworld_registry') }}</p>
        <p class="text-2xs leading-snug font-semibold text-[#94a3b8] uppercase">
          {{ t('bid_wizard.rfp_submission_hub') }}
        </p>
      </div>

      <!-- stepper-container 509:55041 -->
      <nav class="flex flex-col gap-2" :aria-label="t('bid_wizard.bid_steps')">
        <!-- preserve-state: the five steps are the same page component, so
             Inertia keeps the form alive across them. Without it every step
             remounted and the previous answers were discarded. -->
        <RouterLink
          v-for="s in steps"
          :key="s.n"
          :href="pathFor(s.n)"
          preserve-state
          preserve-scroll
          class="flex items-center gap-3 rounded-lg px-4 py-3"
          :class="step === s.n ? 'bg-[#ccfbf1]' : 'bg-white hover:bg-surface-secondary'"
          :aria-current="step === s.n ? 'step' : undefined"
        >
          <span
            class="grid size-6 shrink-0 place-items-center rounded-xl text-2xs font-semibold"
            :class="
              step === s.n
                ? 'bg-[#0f766e] text-white'
                : 'border border-[#e2e8f0] bg-white text-[#94a3b8]'
            "
          >
            {{ s.n }}
          </span>
          <span
            class="min-w-0 flex-1 truncate text-xs"
            :class="step === s.n ? 'font-semibold text-[#0f766e]' : 'font-medium text-[#475569]'"
          >
            {{ s.label }}
          </span>
        </RouterLink>
      </nav>
    </aside>

    <!-- main-panel 509:55062 -->
    <div class="flex min-w-0 flex-1 flex-col gap-8 p-5 sm:p-8 lg:p-10">
      <!-- top-bar 509:55063 -->
      <div
        class="flex flex-wrap items-center justify-between gap-4 border-b border-[#e2e8f0] pb-5"
      >
        <div class="flex flex-col gap-1">
          <!-- Was a fixed "Kemafy LLC Response / RFP-2026-089A" for everyone. -->
          <label class="flex w-full flex-col items-start gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <span class="text-lg font-semibold text-[#0f172a]">{{ t('bid_wizard.bidding_as') }}</span>
            <select
              v-model="form.bidder_sbo_id"
              class="w-full max-w-full rounded-md border border-[#e2e8f0] bg-white px-3 py-1.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none sm:w-auto"
            >
              <option :value="null">{{ t('bid_wizard.select_your_sbo', { entity: entityTerm }) }}</option>
              <option v-for="s in props.sbos" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </label>
          <p class="text-xs text-[#475569]">{{ t('bid_wizard.proposal_reference', { id }) }}</p>
        </div>
        <!-- Export to CSV is drawn but there is nothing to export until the bid
             exists; left out rather than drawn dead. See INTEGRATION.md. -->
      </div>

      <!-- The endpoint refuses both of these; the wizard says so up front
           rather than at the end of five steps. -->
      <p
        v-if="props.ownRequest"
        class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-danger"
        role="alert"
      >
        {{ t('bid_wizard.this_is_your_own_request_you_cannot_bid_on') }}
      </p>
      <p
        v-else-if="props.unverified"
        class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-sm text-[#b45309]"
        role="alert"
      >
        {{ t('bid_wizard.only_a_verified_business_can_bid_finish_ve') }}
      </p>
      <p
        v-else-if="props.closed"
        class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-sm text-[#b45309]"
        role="alert"
      >
        {{ t('bid_wizard.this_request_is_no_longer_open_for_bids') }}
      </p>
      <p
        v-else-if="props.existingBid"
        class="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] px-4 py-3 text-sm text-[#475569]"
        role="status"
      >
        {{ t('bid_wizard.existing_bid', { date: props.existingBid.submittedAt, status: props.existingBid.status }) }}
      </p>

      <form class="flex flex-col gap-6" @submit.prevent="submit">
        <!-- ========================= 1. contact-information 509:55071 ===== -->
        <template v-if="step === 1">
          <section
            v-for="grp in contactGroups"
            :key="grp.title"
            class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7"
          >
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ grp.title }}</h2>
            <div class="flex flex-col gap-4">
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">
                    {{ t('bid_wizard.full_name') }} <span v-if="grp.required" class="text-[#ef4444]">*</span>
                  </span>
                  <input
                    v-model="form[grp.name]"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">
                    {{ t('bid_wizard.job_title') }} <span v-if="grp.required" class="text-[#ef4444]">*</span>
                  </span>
                  <input
                    v-model="form[grp.jobTitle]"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">
                    {{ t('bid_wizard.email_address') }} <span v-if="grp.required" class="text-[#ef4444]">*</span>
                  </span>
                  <input
                    v-model="form[grp.email]"
                    type="email"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">
                    {{ t('bid_wizard.phone_number') }} <span v-if="grp.required" class="text-[#ef4444]">*</span>
                  </span>
                  <input
                    v-model="form[grp.phone]"
                    type="tel"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
            </div>
          </section>

          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.preferred_communication') }}</h2>
            <div class="flex flex-wrap gap-6">
              <label v-for="c in channels" :key="c" class="flex items-center gap-2.5">
                <input
                  v-model="form.channel"
                  type="radio"
                  :value="c"
                  name="channel"
                  class="size-[18px] appearance-none rounded-full border-2 border-[#e2e8f0] bg-white checked:border-[6px] checked:border-[#0d9488]"
                />
                <span class="text-sm font-medium text-[#475569]">{{ c }}</span>
              </label>
            </div>
          </section>
        </template>

        <!-- ========================= 2. address-confirmation 509:55149 ==== -->
        <template v-else-if="step === 2">
          <!--
            The frame draws two address blocks. `purchase_order_bids` carries a
            single proposal document, so only the registered address is
            collected and the mailing block is the "same as registered" tick —
            which is what it already defaulted to. See INTEGRATION.md.
          -->
          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">
              {{ t('bid_wizard.registered_business_address') }}
            </h2>

            <div class="flex flex-col gap-4">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.street_address') }}</span>
                <input
                  v-model="form.street"
                  type="text"
                  :placeholder="t('bid_wizard.e_g_100_adeola_odeku_street')"
                  class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                />
              </label>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.suite_unit_optional') }}</span>
                  <input
                    v-model="form.suite"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.city') }}</span>
                  <input
                    v-model="form.city"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.state') }}</span>
                  <input
                    v-model="form.state"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.zip_code') }}</span>
                  <input
                    v-model="form.zip"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>

              <label class="flex items-center gap-2.5">
                <input
                  v-model="sameAsRegistered"
                  type="checkbox"
                  class="size-5 shrink-0 appearance-none rounded border border-[#e2e8f0] bg-white checked:border-[#0d9488] checked:bg-[#0d9488]"
                />
                <span class="text-sm font-medium text-[#475569]">
                  {{ t('bid_wizard.mailing_address_is_the_same_as_the_registe') }}
                </span>
              </label>
            </div>
          </section>

        </template>

        <!-- ==================== 3. kemafy-response-form 509:53295 ========= -->
        <template v-else-if="step === 3">
          <!-- card-signer -->
          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <div class="flex flex-col gap-2">
              <h2 class="text-base font-semibold text-[#0f172a]">
                {{ t('bid_wizard.1_1_individual_s_authorized_to_sign_the_co') }}
              </h2>
              <p class="text-xs text-[#475569]">
                {{ t('bid_wizard.please_enter_the_full_name_s_title_s_and_e') }}
              </p>
            </div>
            <hr class="border-[#e2e8f0]" />
            <div class="flex flex-col gap-4">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.full_name_s') }}</span>
                <input
                  v-model="form.signers"
                  type="text"
                  :placeholder="t('bid_wizard.e_g_jane_doe_robert_smith')"
                  class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                />
                <span class="text-xs text-[#94a3b8]">{{ t('bid_wizard.separate_multiple_names_with_commas') }}</span>
              </label>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.title_s') }}</span>
                  <input
                    v-model="form.signerTitles"
                    type="text"
                    :placeholder="t('bid_wizard.e_g_ceo_managing_partner')"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.email_address_es') }}</span>
                  <input
                    v-model="form.signerEmails"
                    type="text"
                    :placeholder="t('bid_wizard.e_g_representative_example_com')"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
            </div>
          </section>

          <!-- card-official -->
          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="text-base font-semibold text-[#0f172a]">
              {{ t('bid_wizard.1_2_respondent_s_authorized_official') }}
            </h2>
            <hr class="border-[#e2e8f0]" />
            <p class="text-xs text-[#475569]">
              {{ t('bid_wizard.all_required_certifications_documentation') }}
            </p>
            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold text-[#475569]">
                {{ t('bid_wizard.authorized_official_name_amp_title') }}
              </span>
              <input
                v-model="form.authorizedOfficial"
                type="text"
                :placeholder="t('bid_wizard.enter_full_name_and_formal_job_title')"
                class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
              />
            </label>
          </section>

          <!-- card-certs -->
          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <div class="flex flex-col gap-2">
              <h2 class="text-base font-semibold text-[#0f172a]">
                {{ t('bid_wizard.1_3_amp_1_4_mandatory_certifications') }}
              </h2>
              <p class="text-xs text-[#475569]">
                {{ t('bid_wizard.review_and_certify_the_compliance_statemen') }}
              </p>
            </div>
            <hr class="border-[#e2e8f0]" />
            <div class="flex flex-col gap-4">
              <label
                v-for="(c, i) in certs"
                :key="i"
                class="flex cursor-pointer items-start gap-3 rounded-[10px] p-4"
                :class="form.certified[i] ? 'bg-[#ccfbf1]' : 'bg-[#f8fafc]'"
              >
                <span
                  class="mt-0.5 grid size-5 shrink-0 place-items-center rounded"
                  :class="form.certified[i] ? 'bg-[#0d9488]' : 'border border-[#e2e8f0] bg-white'"
                >
                  <img
                    v-if="form.certified[i]"
                    :src="iconCheck"
                    alt=""
                    class="size-3"
                    aria-hidden="true"
                  />
                </span>
                <input v-model="form.certified[i]" type="checkbox" class="sr-only" />
                <span class="text-xs text-[#0f172a]">{{ c }}</span>
              </label>
            </div>
          </section>

          <!-- card-proposal -->
          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <div class="flex flex-col gap-2">
              <h2 class="text-base font-semibold text-[#0f172a]">{{ t('bid_wizard.1_5_technical_proposal_upload') }}</h2>
              <p class="text-xs text-[#475569]">
                {{ t('bid_wizard.submit_your_technical_proposal_per_the_ins') }}
              </p>
            </div>

            <div class="flex items-start gap-3 rounded-lg bg-[#fef3c7] p-4">
              <img
                :src="iconAlertTriangle"
                alt=""
                class="mt-px size-[18px] shrink-0"
                aria-hidden="true"
              />
              <p class="text-xs font-semibold text-[#d97706]">
                {{ t('bid_wizard.do_not_include_any_cost_proposal_informati') }}
              </p>
            </div>

            <!-- drop-zone 509:53384 -->
            <label
              class="flex cursor-pointer flex-col items-center gap-4 rounded-xl bg-[#f8fafc] p-5 sm:p-8 lg:p-10 text-center"
            >
              <span class="grid size-12 place-items-center rounded-3xl bg-[#ccfbf1]">
                <img :src="iconUploadCloud" alt="" class="size-6" aria-hidden="true" />
              </span>
              <span class="flex flex-col gap-1">
                <span class="text-sm font-semibold text-[#0f172a]">
                  {{ t('bid_wizard.drag_and_drop_files_here_to_upload') }}
                </span>
                <span class="text-xs text-[#475569]">PDF, DOC or DOCX · up to 10MB each</span>
              </span>
              <span
                class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2 text-xs font-semibold text-[#0f172a]"
              >
                {{ t('bid_wizard.choose_file') }}
              </span>
              <!-- Several documents may be attached; only office files. -->
              <input
                type="file"
                accept=".pdf,.doc,.docx"
                multiple
                class="sr-only"
                @change="onAttachment"
              />
            </label>

            <!-- Every attached document, addable across picks and removable. -->
            <ul v-if="form.attachments.length" class="flex flex-col gap-1.5">
              <li
                v-for="(f, i) in form.attachments"
                :key="`${f.name}-${f.size}-${i}`"
                class="flex items-center justify-between gap-3 rounded-md border border-[#e2e8f0] bg-white px-3 py-2"
              >
                <span class="min-w-0 truncate text-xs text-[#0f172a]">{{ f.name }}</span>
                <button type="button" class="shrink-0 text-xs font-semibold text-danger hover:underline" @click="removeAttachment(i)">
                  {{ t('procurement_create.remove') }}
                </button>
              </li>
            </ul>
            <span v-if="(form.errors as any)['attachments.0']" class="text-xs text-danger">
              {{ (form.errors as any)['attachments.0'] }}
            </span>
          </section>
        </template>

        <!-- ========================= 4. company-profile 509:55298 ========= -->
        <template v-else-if="step === 4">
          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.company_overview') }}</h2>
            <div class="flex flex-col gap-4">
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.legal_company_name') }}</span>
                  <input
                    v-model="form.legalName"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.dba_doing_business_as') }}</span>
                  <input
                    v-model="form.dba"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.year_established') }}</span>
                  <input
                    v-model="form.yearEstablished"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.number_of_employees') }}</span>
                  <input
                    v-model="form.employees"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
              <div class="flex flex-col gap-4 sm:flex-row">
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.duns_number') }}</span>
                  <input
                    v-model="form.duns"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
                <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.tax_id_ein') }}</span>
                  <input
                    v-model="form.taxId"
                    type="text"
                    class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                  />
                </label>
              </div>
            </div>
          </section>

          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.insurance_amp_bonding') }}</h2>
            <div class="flex flex-col gap-4 sm:flex-row">
              <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">
                  {{ t('bid_wizard.general_liability_coverage_amount') }}
                </span>
                <input
                  v-model="form.generalLiability"
                  type="text"
                  class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                />
              </label>
              <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">
                  {{ t('bid_wizard.professional_liability_coverage_amount') }}
                </span>
                <input
                  v-model="form.professionalLiability"
                  type="text"
                  class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
                />
              </label>
            </div>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold text-[#475569]">
                {{ t('bid_wizard.upload_certificate_of_insurance_pdf_max_10') }}
              </span>
              <span
                class="flex cursor-pointer flex-col items-center gap-2 rounded-xl bg-[#f8fafc] p-5 sm:p-8 text-center"
              >
                <img :src="iconUploadCloud" alt="" class="size-6" aria-hidden="true" />
                <span class="text-xs text-[#475569]">
                  {{ t('bid_wizard.click_to_upload_or_drag_amp_drop_coi_pdf') }}
                </span>
                <input type="file" accept=".pdf,.doc,.docx" multiple class="sr-only" @change="onAttachment" />
              </span>
            </label>

            <!-- Shared with the technical-proposal upload: every attached file
                 goes to the bid, and shows here so an upload is confirmed. -->
            <ul v-if="form.attachments.length" class="flex flex-col gap-1.5">
              <li
                v-for="(f, i) in form.attachments"
                :key="`coi-${f.name}-${f.size}-${i}`"
                class="flex items-center justify-between gap-3 rounded-md border border-[#e2e8f0] bg-white px-3 py-2"
              >
                <span class="min-w-0 truncate text-xs text-[#0f172a]">{{ f.name }}</span>
                <button type="button" class="shrink-0 text-xs font-semibold text-danger hover:underline" @click="removeAttachment(i)">
                  {{ t('procurement_create.remove') }}
                </button>
              </li>
            </ul>
          </section>
        </template>

        <!-- ========================= 5. submit-form 509:55437 ============= -->
        <template v-else>
          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.submission_summary') }}</h2>
            <ul class="flex flex-col">
              <li
                v-for="r in summaryRows"
                :key="r.n"
                class="flex items-center justify-between gap-4 border-b border-[#f1f5f9] py-3"
              >
                <span class="text-sm font-medium text-[#0f172a]">{{ r.label }}</span>
                <RouterLink
                  :href="pathFor(r.n)"
                  preserve-state
                  preserve-scroll
                  class="text-xs font-semibold text-[#0d9488]"
                >
                  {{ t('bid_wizard.edit') }}
                </RouterLink>
              </li>
            </ul>
          </section>

          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.final_declarations') }}</h2>
            <label
              v-for="(d, i) in declarations"
              :key="i"
              class="flex cursor-pointer items-start gap-3"
            >
              <input
                v-model="form.declared[i]"
                type="checkbox"
                class="mt-0.5 size-5 shrink-0 appearance-none rounded border border-[#e2e8f0] bg-white checked:border-[#0d9488] checked:bg-[#0d9488]"
              />
              <span class="text-xs text-[#475569]">{{ d }}</span>
            </label>
          </section>

          <section class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
            <h2 class="font-serif text-2xl font-bold text-[#0f172a]">{{ t('bid_wizard.digital_signature') }}</h2>
            <div class="flex flex-col gap-4 sm:flex-row">
              <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">
                  {{ t('bid_wizard.type_your_full_legal_name_as_your_digital') }}
                </span>
                <input
                  v-model="form.signature"
                  type="text"
                  :placeholder="t('bid_wizard.your_full_legal_name')"
                  class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                />
              </label>
              <label class="flex min-w-0 flex-1 flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('bid_wizard.date_of_signature') }}</span>
                <!-- Stamped at submit, not typed: a signature date the signer
                     can edit is not a signature date. -->
                <input
                  :value="new Date().toLocaleDateString(undefined, { dateStyle: 'long' })"
                  type="text"
                  readonly
                  class="rounded-lg border border-[#e2e8f0] bg-surface-secondary px-4 py-3 text-sm text-[#475569] focus:outline-none"
                />
              </label>
            </div>
            <p class="text-xs text-[#94a3b8]">
              {{ t('bid_wizard.by_typing_your_name_above_you_agree_this_c') }}
            </p>
          </section>
        </template>

        <p
          v-if="Object.keys(form.errors).length"
          class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-danger"
          role="alert"
        >
          {{ Object.values(form.errors)[0] }}
        </p>

        <!-- bottom-actions 509:55144 -->
        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-[#e2e8f0] pt-4">
          <RouterLink
            v-if="step > 1"
            :href="pathFor(step - 1)"
            preserve-state
            preserve-scroll
            class="rounded-lg border border-[#e2e8f0] bg-white px-6 py-3 text-base font-semibold text-[#475569] hover:bg-surface-secondary"
          >
            {{ t('bid_wizard.back') }}
          </RouterLink>
          <span v-else />

          <RouterLink
            v-if="nextPath"
            :href="nextPath"
            preserve-state
            preserve-scroll
            class="rounded-lg bg-[#0d9488] px-5 sm:px-7 py-3 text-base font-semibold text-white transition-opacity hover:opacity-90"
          >
            {{ t('bid_wizard.save_amp_continue') }}
          </RouterLink>
          <button
            v-else
            type="submit"
            :disabled="!canSubmit || form.processing"
            class="rounded-lg bg-[#007035] px-5 sm:px-7 py-3 text-base font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
            :title="
              canSubmit
                ? undefined
                : t('bid_wizard.pick_the_sbo_you_are_bidding_as_accept_bot')
            "
          >
            {{ form.processing ? t('register_wizard.submitting') : t('bid_wizard.submit_response') }}
          </button>
        </div>
      </form>
    </div>
  </div>

</template>
