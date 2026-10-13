<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import UiRichText from '@/components/ui/UiRichText.vue'

import iconChevron from '@/assets/figma/procurement/form-chevron.svg'
import iconChipDot from '@/assets/figma/procurement/chip-dot.svg'
import iconMapPin from '@/assets/figma/procurement/map-pin.svg'
import iconCalendar from '@/assets/figma/procurement/calendar.svg'
import iconArrowRight from '@/assets/figma/procurement/arrow-right.svg'
import iconAdArrow from '@/assets/figma/procurement/ad-arrow.svg'
import adSpace from '@/assets/figma/procurement/ad-space.jpg'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t, tc } = useI18n()
const { entityTerm } = useEntityTerm()

/*
 * Post a procurement request — Figma 401:22131 "Post procurement-requests".
 *   hero-section 401:22181 (226)  px-80 py-48, VIDEO fill, two CTAs
 *   workspace-split 401:22192     form column + 380px sidebar, gap 32
 *   bottom-sticky-bar 401:22356
 *
 * The form is five numbered Section-Cards (401:22504 / 22545 / 22557 / 22590 /
 * 22604), each a 16px-radius #e2e8f0 card at p-32 with a 24px gap, closed by a
 * Form-Footer carrying Cancel and Post Request.
 */

defineOptions({ layout: AppLayout })

const props = defineProps<{
  categories: string[]
  /** The owner's own SBOs — an RFQ is posted as one of them. */
  sbos: { id: number; name: string }[]
  summary: { total: number; open: number; awarded: number; bids: number }
  /** Currencies a budget may be quoted in; see PurchaseOrder::CURRENCIES. */
  currencies: string[]
  /**
   * Present only when amending an existing request: the form fills itself from
   * `values` and posts to `updateUrl`. Absent (null) for a fresh request.
   */
  /** The owner's saved drafts, shown on a fresh form to pick up again. */
  drafts?: { title: string; savedAt: string | null; editUrl: string }[]
  editing?: {
    id: number
    updateUrl: string
    /** True while the request is still a draft; the form then offers to publish. */
    isDraft: boolean
    featuredImageUrl: string | null
    values: {
      sbo_id: number | null
      title: string
      description: string
      category: string
      request_type: string
      budget: string
      budget_currency: string
      quantity: string
      delivery_location: string
      order_deadline: string
      advertised_at: string
      briefing_at: string
      briefing_link: string
      briefing_location: string
      briefing_mandatory: boolean
      inquiry_opens_at: string
      inquiry_closes_at: string
      inquiry_responses_at: string
      final_selection_at: string
      visibility: string
      terms: string
    }
    /** Who the request is already shared with, to prefill the invite lists. */
    invites: {
      emails: string[]
      sbos: { id: number; name: string }[]
    }
  } | null
}>()

const isEditing = computed(() => Boolean(props.editing))

/* ------------------------------------------- invite-only sharing --------- */

const isInviteOnly = computed(() => form.visibility === 'invite_only')

const invitedSbos = ref<{ id: number; name: string }[]>(props.editing?.invites.sbos ?? [])

const sboQuery = ref('')
const sboResults = ref<{ id: number; name: string }[]>([])
let searchTimer: ReturnType<typeof setTimeout> | null = null

function searchSbos() {
  if (searchTimer) clearTimeout(searchTimer)
  const q = sboQuery.value.trim()
  if (q.length < 2) {
    sboResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    try {
      const res = await fetch(`/procurement-invitees?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      sboResults.value = res.ok ? await res.json() : []
    } catch {
      sboResults.value = []
    }
  }, 250)
}

function addSbo(sbo: { id: number; name: string }) {
  if (!invitedSbos.value.some((s) => s.id === sbo.id)) invitedSbos.value.push(sbo)
  sboQuery.value = ''
  sboResults.value = []
}

function removeSbo(index: number) {
  invitedSbos.value.splice(index, 1)
}

const shareUrl = computed(() =>
  props.editing ? `${window.location.origin}/procurement/${props.editing.id}` : '',
)
const copied = ref(false)

async function copyLink() {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Clipboard blocked (insecure context); the field is selectable as a fallback.
  }
}

/*
 * The chips are labels; `request_type` and `visibility` are the column values
 * ProcurementWriteController@store validates against. Kept as a pair rather
 * than slugifying the label, so a copy change cannot silently break the post.
 */
const requestTypes = [
  { label: t('procurement_create.product'), value: 'product' },
  { label: t('procurement_create.service'), value: 'service' },
  { label: t('procurement_create.product_service'), value: 'both' },
] as const

const visibilities = [
  { label: t('procurement_create.public_to_verified_sbos', { entity: entityTerm.value }), value: 'public' },
  { label: t('procurement_create.invite_only'), value: 'invite_only' },
] as const

/*
 * The featured image the opportunity cards draw.
 *
 * `purchase_orders` carried no image, so LiveOpportunities.vue rotated three
 * stock Figma photos by list position — a road-construction shot could
 * illustrate a request for accountancy services.
 */
// The existing card photo when editing; replaced only if a new one is picked.
const featuredPreview = ref<string | null>(props.editing?.featuredImageUrl ?? null)

function onFeaturedImage(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null

  form.featured_image = file
  featuredPreview.value = file ? URL.createObjectURL(file) : null
}

const form = useForm<{
  sbo_id: number | null
  title: string
  description: string
  category: string
  request_type: string
  budget: string
  budget_currency: string
  quantity: string
  delivery_location: string
  order_deadline: string
  advertised_at: string
  briefing_at: string
  briefing_link: string
  briefing_location: string
  briefing_mandatory: boolean
  inquiry_opens_at: string
  inquiry_closes_at: string
  inquiry_responses_at: string
  final_selection_at: string
  visibility: string
  terms: string
  featured_image: File | null
  document: File | null
  documents: File[]
}>({
  // Blank for a new request; the existing values when editing. The files are
  // never prefilled — an edit adds new attachments rather than re-uploading.
  // One listing is the common case, so it is preselected rather than making
  // the owner pick from a list of one.
  sbo_id: props.editing?.values.sbo_id ?? (props.sbos.length === 1 ? props.sbos[0].id : null),
  title: props.editing?.values.title ?? '',
  description: props.editing?.values.description ?? '',
  category: props.editing?.values.category ?? '',
  request_type: props.editing?.values.request_type ?? 'product',
  budget: props.editing?.values.budget ?? '',
  // Espees first: it is what the registry charges its own fees in.
  budget_currency: props.editing?.values.budget_currency ?? 'Espees',
  quantity: props.editing?.values.quantity ?? '',
  delivery_location: props.editing?.values.delivery_location ?? '',
  order_deadline: props.editing?.values.order_deadline ?? '',
  advertised_at: props.editing?.values.advertised_at ?? '',
  briefing_at: props.editing?.values.briefing_at ?? '',
  briefing_link: props.editing?.values.briefing_link ?? '',
  briefing_location: props.editing?.values.briefing_location ?? '',
  // The design labels the briefing "(Non-Mandatory)", which is the default
  // rather than the only possibility.
  briefing_mandatory: props.editing?.values.briefing_mandatory ?? false,
  inquiry_opens_at: props.editing?.values.inquiry_opens_at ?? '',
  inquiry_closes_at: props.editing?.values.inquiry_closes_at ?? '',
  inquiry_responses_at: props.editing?.values.inquiry_responses_at ?? '',
  final_selection_at: props.editing?.values.final_selection_at ?? '',
  visibility: props.editing?.values.visibility ?? 'public',
  terms: props.editing?.values.terms ?? '',
  featured_image: null,
  document: null,
  // The detail screen draws two whole sections of documents; the form offered
  // one upload, so what a buyer could attach never matched what a bidder saw.
  documents: [],
})

/*
 * The RFP schedule — Section 6.
 *
 * A request carried one date, `order_deadline`, so everything between
 * advertising it and announcing the award was unstated: when the briefing is,
 * how long questions stay open, when answers come back, when a decision is
 * expected. Bidders plan around exactly these.
 *
 * "Proposals due" is `order_deadline` itself rather than a second column: two
 * fields both meaning "the last moment to bid" is how they end up disagreeing,
 * and it is what Closing Soon and the bid endpoint already read.
 */
// The earliest a schedule date may be: a delivery or selection cannot be set
// for a day already past.
const today = new Date().toISOString().slice(0, 10)

const scheduleDates = [
  {
    key: 'advertised_at',
    label: t('procurement_create.rfp_advertised'),
    hint: t('procurement_create.the_day_this_request_goes_out_to_vendors'),
  },
  {
    key: 'inquiry_opens_at',
    label: t('procurement_create.inquiry_period_opens'),
    hint: t('procurement_create.when_bidders_may_start_submitting_written'),
  },
  {
    key: 'inquiry_closes_at',
    label: t('procurement_create.inquiry_period_closes'),
    hint: t('procurement_create.the_last_day_to_submit_a_written_question'),
  },
  {
    key: 'inquiry_responses_at',
    label: t('procurement_create.responses_to_written_inquiries_sent'),
    hint: t('procurement_create.when_answers_go_back_to_everyone_who_asked'),
  },
  {
    key: 'final_selection_at',
    label: t('procurement_create.final_selection_anticipated'),
    hint: t('procurement_create.when_you_expect_to_award_not_binding'),
  },
] as const

/*
 * Every file the buyer has attached so far. A file input replaces its list on
 * each pick, so selecting one file at a time would keep only the last; these
 * accumulate instead, and the first still maps to `document_path` with the rest
 * becoming document rows.
 */
const selectedFiles = ref<File[]>([])

const fileName = computed(() =>
  selectedFiles.value.length === 0
    ? ''
    : selectedFiles.value.length === 1
      ? selectedFiles.value[0].name
      : tc('procurement_create.files_selected', selectedFiles.value.length),
)

function syncFiles() {
  form.document = selectedFiles.value[0] ?? null
  form.documents = selectedFiles.value.slice(1)
}

function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const picked = input.files ? Array.from(input.files) : []

  // Add to what is already there, skipping a file that is plainly the same one
  // picked twice, so choosing files across several clicks builds one list.
  for (const file of picked) {
    if (!selectedFiles.value.some((f) => f.name === file.name && f.size === file.size)) {
      selectedFiles.value.push(file)
    }
  }

  // Clear the control, or re-picking a file that was removed would not fire.
  input.value = ''
  syncFiles()
}

function removeFile(index: number) {
  selectedFiles.value.splice(index, 1)
  syncFiles()
}

/*
 * `quantity` is an integer column but the field is drawn as free text
 * ("e.g. 200 units"), so the digits are lifted out and the rest dropped rather
 * than failing validation on a placeholder the design invited.
 */
/*
 * Every error, not only the ones with a slot beside a field.
 *
 * Twelve fields were validated and ten of them had nowhere to show a failure —
 * visibility, request type, budget, the whole RFP schedule and the document
 * list. A request rejected on any of those looked like a button that simply
 * did nothing, which is exactly what was reported.
 */
const errorList = computed(() => Object.values(form.errors).filter(Boolean) as string[])

function submit() {
  form
    .transform((data) => ({
      ...data,
      quantity: data.quantity.replace(/\D/g, '') || null,
      // Invites are members-only now, managed outside the form; a public
      // request shares with no one, so switching away from invite-only clears
      // the list.
      invites_emails: [],
      invites_sbo_ids: isInviteOnly.value ? invitedSbos.value.map((s) => s.id) : [],
      // A multipart PUT is not a thing browsers send, so an edit posts with the
      // method spoofed — the same way Inertia handles file uploads on update.
      ...(isEditing.value ? { _method: 'put' } : {}),
    }))
    .post(props.editing?.updateUrl ?? '/procurement', {
      forceFormData: true,
      // Scroll it into view: on a form this long the summary can be several
      // screens above wherever the submit button was pressed.
      onError: () =>
        nextTick(() => {
          document
            .getElementById('procurement-errors')
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        }),
    })
}

/*
 * Save as a draft rather than publishing. Only a title is required, so the
 * relaxed endpoint takes whatever is filled in; an existing draft is continued
 * by sending its id. Always a plain POST (the draft route is not method-spoofed).
 */
function saveDraft() {
  form
    .transform((data) => ({
      ...data,
      quantity: data.quantity.replace(/\D/g, '') || null,
      invites_emails: [],
      invites_sbo_ids: isInviteOnly.value ? invitedSbos.value.map((s) => s.id) : [],
      ...(props.editing ? { id: props.editing.id } : {}),
    }))
    .post('/procurement/draft', {
      forceFormData: true,
      onError: () =>
        nextTick(() => {
          document.getElementById('procurement-errors')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        }),
    })
}

// The draft button shows on a new form and while editing a draft — not once a
// request is live. Publishing a draft says "Post"; editing a live one "saves".
const canSaveDraft = computed(() => !props.editing || props.editing.isDraft === true)
const isLiveEdit = computed(() => Boolean(props.editing) && props.editing?.isDraft === false)

const summary = computed(() => [
  { label: t('procurement_create.total_requests'), value: String(props.summary.total) },
  { label: t('procurement_create.open_requests'), value: String(props.summary.open) },
  { label: t('procurement_create.awarded_requests'), value: String(props.summary.awarded) },
  { label: t('procurement_create.bids_received'), value: String(props.summary.bids) },
])
</script>

<template>
  <!-- ======================================= hero-section 401:22181 ======= -->
  <section class="relative overflow-hidden bg-[#0b192c] section-x py-8 sm:py-12">
    <HeroVideo overlay="bg-[#0b192c]/80" />

    <div class="relative flex flex-col gap-7">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('procurement_create.breadcrumb')">
        <RouterLink href="/sbo/dashboard" class="text-[#94a3b8] hover:text-white">
          {{ t('procurement_create.sbo_workspace', { entity: entityTerm }) }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('procurement_create.gt') }}</span>
        <span class="text-white">{{ t('procurement_create.procurement_requests') }}</span>
      </nav>

      <div class="flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex max-w-[675px] flex-col gap-2">
          <h1 class="font-serif text-4xl font-bold text-white">
            {{ t('procurement_create.procurement_requests') }}
          </h1>
          <p class="text-base text-[#94a3b8]">
            {{ t('procurement_create.post_and_manage_product_or_service_request') }}
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-6">
          <span
            class="inline-flex items-center justify-center rounded-xl bg-[#007035] px-7 py-3.5 text-base font-semibold whitespace-nowrap text-white"
          >
            {{ t('procurement_create.post_procurement_request') }}
          </span>
          <RouterLink
            href="/procurement"
            class="inline-flex items-center rounded-lg border-[1.5px] border-white px-7 py-3.5 text-sm font-semibold whitespace-nowrap text-white transition-colors hover:bg-white/10"
          >
            {{ t('procurement_create.see_all_procurement') }}
          </RouterLink>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================================== workspace-split 401:22192 ======= -->
  <div class="flex flex-col gap-8 bg-surface-secondary section-x py-8 sm:py-12 pb-[92px] lg:flex-row">
    <!-- Form-Container 401:22487 -->
    <form class="flex min-w-0 flex-1 flex-col gap-6" @submit.prevent="submit">
      <!-- Saved drafts to continue, on a fresh form only. -->
      <section
        v-if="!isEditing && props.drafts && props.drafts.length"
        class="flex flex-col gap-3 rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-5"
      >
        <h2 class="font-display text-base font-semibold text-[#0f172a]">{{ t('procurement_create.continue_a_draft') }}</h2>
        <ul class="flex flex-col gap-2">
          <li
            v-for="(d, i) in props.drafts"
            :key="i"
            class="flex items-center justify-between gap-3 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3"
          >
            <span class="min-w-0">
              <span class="block truncate text-sm font-semibold text-[#0f172a]">{{ d.title }}</span>
              <span v-if="d.savedAt" class="block text-xs text-[#94a3b8]">{{ t('procurement_create.saved') }} {{ d.savedAt }}</span>
            </span>
            <RouterLink :href="d.editUrl" class="shrink-0 text-sm font-semibold text-primary hover:underline">
              {{ t('procurement_create.continue') }}
            </RouterLink>
          </li>
        </ul>
      </section>

      <!--
        Everything that stopped the request being posted, in one place.
        Ten of the validated fields had no slot beside them, so a rejection on
        any of those looked like a button that did nothing.
      -->
      <div
        v-if="errorList.length"
        id="procurement-errors"
        class="rounded-2xl border border-[#fecaca] bg-[#fef2f2] p-5"
        role="alert"
        aria-live="polite"
      >
        <p class="text-sm font-semibold text-danger">
          {{ tc('procurement_create.could_not_post', errorList.length) }}
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <li v-for="(message, i) in errorList" :key="i" class="text-xs text-danger">
            {{ message }}
          </li>
        </ul>
      </div>

      <!-- Section-Card-1 401:22504 -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.1_basic_details') }}</h2>

        <div class="flex flex-col gap-6 sm:flex-row">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ entityTerm }}</span>
            <span class="relative">
              <select
                v-model="form.sbo_id"
                class="w-full appearance-none rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 pr-10 text-base text-[#0f172a] focus:border-focus focus:outline-none"
              >
                <option :value="null">{{ t('procurement_create.select_your_sbo', { entity: entityTerm }) }}</option>
                <option v-for="s in props.sbos" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <img
                :src="iconChevron"
                alt=""
                class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2"
                aria-hidden="true"
              />
            </span>
            <span v-if="form.errors.sbo_id" class="text-xs text-danger">
              {{ form.errors.sbo_id }}
            </span>
          </label>

          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.category') }}</span>
            <span class="relative">
              <select
                v-model="form.category"
                class="w-full appearance-none rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 pr-10 text-base text-[#0f172a] focus:border-focus focus:outline-none"
              >
                <option value="">{{ t('procurement_create.choose_category') }}</option>
                <option v-for="c in props.categories" :key="c" :value="c">{{ c }}</option>
              </select>
              <img
                :src="iconChevron"
                alt=""
                class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2"
                aria-hidden="true"
              />
            </span>
            <span v-if="form.errors.category" class="text-xs text-danger">
              {{ form.errors.category }}
            </span>
          </label>
        </div>

        <!-- Chips-Group 401:22527 -->
        <div class="flex flex-col gap-2.5">
          <p class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.request_type') }}</p>
          <div class="flex flex-wrap gap-3">
            <button
              v-for="t in requestTypes"
              :key="t.value"
              type="button"
              class="inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-sm"
              :class="
                form.request_type === t.value
                  ? 'border-[1.5px] border-[#10b981] bg-[#e6f4ea] font-semibold text-[#059669]'
                  : 'border border-[#e2e8f0] bg-white font-medium text-[#475569]'
              "
              :aria-pressed="form.request_type === t.value"
              @click="form.request_type = t.value"
            >
              <img
                v-if="form.request_type === t.value"
                :src="iconChipDot"
                alt=""
                class="size-2"
                aria-hidden="true"
              />
              {{ t.label }}
            </button>
          </div>
        </div>

        <!-- Visibility-Block 401:22535 -->
        <div class="flex flex-col gap-2.5">
          <p class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.bid_visibility') }}</p>
          <div class="flex flex-wrap gap-3">
            <button
              v-for="v in visibilities"
              :key="v.value"
              type="button"
              class="inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-sm"
              :class="
                form.visibility === v.value
                  ? 'border-[1.5px] border-[#10b981] bg-[#e6f4ea] font-semibold text-[#059669]'
                  : 'border border-[#e2e8f0] bg-white font-medium text-[#475569]'
              "
              :aria-pressed="form.visibility === v.value"
              @click="form.visibility = v.value"
            >
              <img
                v-if="form.visibility === v.value"
                :src="iconChipDot"
                alt=""
                class="size-2"
                aria-hidden="true"
              />
              {{ v.label }}
            </button>
          </div>
          <p class="text-xs text-[#475569]">
            {{ t('procurement_create.public_requests_are_visible_to_all_eligibl', { entity: entityTerm }) }}
          </p>

          <!-- Invite-only sharing: who may see the request. A public request
               keeps no such list. -->
          <div
            v-if="isInviteOnly"
            class="mt-1 flex flex-col gap-4 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-4"
          >
            <div class="flex flex-col gap-1">
              <p class="text-sm font-semibold text-[#0f172a]">Who can see this request</p>
              <p class="text-xs text-[#94a3b8]">
                Only verified members can be invited — they are the ones who can bid.
              </p>
            </div>

            <!-- Businesses on the platform -->
            <div class="flex flex-col gap-2">
              <span class="text-xs font-semibold text-[#475569]">Invite a business on the platform</span>
              <div class="relative">
                <input
                  v-model="sboQuery"
                  type="text"
                  placeholder="Search businesses by name"
                  class="w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-sm focus:border-focus focus:outline-none"
                  @input="searchSbos"
                />
                <ul v-if="sboResults.length" class="absolute z-10 mt-1 max-h-52 w-full overflow-auto rounded-lg border border-[#e2e8f0] bg-white shadow-lg">
                  <li v-for="s in sboResults" :key="s.id">
                    <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-surface-secondary" @click="addSbo(s)">
                      {{ s.name }}
                    </button>
                  </li>
                </ul>
              </div>
              <ul v-if="invitedSbos.length" class="flex flex-wrap gap-2">
                <li v-for="(s, i) in invitedSbos" :key="s.id" class="inline-flex items-center gap-1.5 rounded-full border border-[#e2e8f0] bg-white px-3 py-1 text-xs text-[#0f172a]">
                  {{ s.name }}
                  <button type="button" class="text-danger" aria-label="Remove" @click="removeSbo(i)">×</button>
                </li>
              </ul>
            </div>

            <!-- Copy link — only when editing; a new request has no URL yet. -->
            <div v-if="props.editing" class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold text-[#475569]">Share link</span>
              <div class="flex gap-2">
                <input :value="shareUrl" readonly class="min-w-0 flex-1 rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-xs text-[#475569]" />
                <button type="button" class="shrink-0 rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-xs font-semibold" @click="copyLink">
                  {{ copied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <p class="text-2xs text-[#94a3b8]">
                The link only opens for people you invite; anyone else is asked to request access.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- Section-Card-2 401:22545 -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.2_request_information') }}</h2>

        <label class="flex flex-col gap-2">
          <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.title') }}</span>
          <input
            v-model="form.title"
            type="text"
            :placeholder="t('procurement_create.e_g_supply_and_install_media_equipment')"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
          <span v-if="form.errors.title" class="text-xs text-danger">
            {{ form.errors.title }}
          </span>
        </label>

        <!-- Not a <label>: it wraps a contenteditable editor, and a label
             re-targets clicks to it — which closes the toolbar's format
             dropdown the instant it opens. The field is named via aria-label. -->
        <div class="flex flex-col gap-2">
          <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.description_specification') }}</span>
          <!-- A specification is a list of requirements, so it needs lists.
               The markup is sanitised server-side; see App\Support\RichText. -->
          <UiRichText
            v-model="form.description"
            :aria-label="t('procurement_create.description_specification')"
            :placeholder="t('procurement_create.describe_what_you_need_vendors_to_quote_fo')"
            :max-length="5000"
          />
          <span v-if="form.errors.description" class="text-xs text-danger">
            {{ form.errors.description }}
          </span>
        </div>
      </section>

      <!-- Section-Card-3 401:22557 -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.3_budget_amp_logistics') }}</h2>

        <div class="flex flex-col gap-6 sm:flex-row">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="flex items-center gap-1">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.budget') }}</span>
              <span class="text-xs text-[#94a3b8]">{{ t('procurement_create.optional') }}</span>
            </span>
            <span
              class="flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus"
            >
              <input
                v-model="form.budget"
                type="text"
                placeholder="e.g. 500000"
                class="min-w-0 flex-1 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none"
              />
              <!--
                The currency is chosen, not assumed. This was a fixed "NGN"
                label, so a Kenyan buyer entering 500000 was published as
                ₦500,000 and a bidder reading it had no way to know.
              -->
              <select
                v-model="form.budget_currency"
                class="shrink-0 bg-transparent text-xs font-semibold text-[#475569] focus:outline-none"
                :aria-label="t('procurement_create.currency')"
              >
                <option v-for="code in props.currencies" :key="code" :value="code">{{ code }}</option>
              </select>
            </span>
          </label>

          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.quantity') }}</span>
            <input
              v-model="form.quantity"
              type="text"
              :placeholder="t('procurement_create.e_g_200_units')"
              class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
            <span v-if="form.errors.quantity" class="text-xs text-danger">
              {{ form.errors.quantity }}
            </span>
          </label>
        </div>

        <div class="flex flex-col gap-6 sm:flex-row">
          <!--
            "Unit / Specification" has no column on purchase_orders and the
            spec it describes belongs in the description, so it is not posted.
            Left drawn rather than removed; see INTEGRATION.md.
          -->
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="flex items-center gap-1">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.unit_specification') }}</span>
              <span class="text-xs text-[#94a3b8]">{{ t('procurement_create.optional') }}</span>
            </span>
            <input
              type="text"
              :placeholder="t('procurement_create.e_g_4k_display_55_inch')"
              class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
          </label>

          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.delivery_location') }}</span>
            <span
              class="flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus"
            >
              <img :src="iconMapPin" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
              <input
                v-model="form.delivery_location"
                type="text"
                :placeholder="t('procurement_create.e_g_lagos_zone_1')"
                class="min-w-0 flex-1 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none"
              />
            </span>
            <span v-if="form.errors.delivery_location" class="text-xs text-danger">
              {{ form.errors.delivery_location }}
            </span>
          </label>
        </div>
      </section>

      <!-- Section-Card-4 401:22590 -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.4_requirements_amp_terms') }}</h2>

        <label class="flex flex-col gap-2">
          <span class="flex items-center gap-1">
            <span class="text-sm font-semibold text-[#0f172a]">
              {{ t('procurement_create.required_documents_from_bidders') }}
            </span>
            <span class="text-xs text-[#94a3b8]">{{ t('procurement_create.optional') }}</span>
          </span>
          <!-- No column for a bidder-document checklist yet; the requirement
               belongs in Terms until there is one. See INTEGRATION.md. -->
          <input
            type="text"
            :placeholder="t('procurement_create.e_g_cac_certificate_tax_clearance_company')"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
        </label>

        <!-- A <div>, not a <label>, for the same reason as the description
             field above: a label would swallow the editor's toolbar clicks. -->
        <div class="flex flex-col gap-2">
          <span class="flex items-center gap-1">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.terms_amp_conditions') }}</span>
            <span class="text-xs text-[#94a3b8]">{{ t('procurement_create.optional') }}</span>
          </span>
          <UiRichText
            v-model="form.terms"
            :aria-label="t('procurement_create.terms_amp_conditions')"
            :placeholder="t('procurement_create.delivery_terms_payment_terms_compliance_re')"
          />
          <span v-if="form.errors.terms" class="text-xs text-danger">
            {{ form.errors.terms }}
          </span>
        </div>
      </section>

      <!-- Section-Card-5 401:22604 -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.5_deadline_amp_documents') }}</h2>

        <!-- Featured image. Without one the opportunity cards fall back to
             stock artwork picked by list position, which has nothing to do
             with the request. -->
        <div class="flex flex-col gap-2">
          <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.featured_image') }}</span>
          <span class="text-2xs text-[#94a3b8]">
            {{ t('procurement_create.shown_on_the_opportunity_cards_across_the') }}
          </span>

          <div class="flex flex-wrap items-center gap-4">
            <img
              v-if="featuredPreview"
              :src="featuredPreview"
              :alt="t('procurement_create.featured_image_preview')"
              class="h-[72px] w-[128px] shrink-0 rounded-lg object-cover"
            />
            <div
              v-else
              class="grid h-[72px] w-[128px] shrink-0 place-items-center rounded-lg bg-[#f1f5f9] text-2xs text-[#94a3b8]"
            >
              {{ t('procurement_create.no_image') }}
            </div>

            <label
              class="flex cursor-pointer items-center gap-3 rounded-lg border-[1.5px] border-dashed border-[#e2e8f0] bg-[#f8fafc] p-3"
            >
              <span
                class="shrink-0 rounded-md border border-[#e2e8f0] bg-white px-4 py-2 text-xs font-semibold text-[#0f172a]"
              >
                {{ form.featured_image ? t('procurement_create.change_image') : t('procurement_create.choose_image') }}
              </span>
              <span class="min-w-0 truncate text-xs text-[#475569]">
                {{ form.featured_image?.name ?? t('common.optional') }}
              </span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="sr-only"
                @change="onFeaturedImage"
              />
            </label>
          </div>

          <span v-if="form.errors.featured_image" class="text-xs text-danger">
            {{ form.errors.featured_image }}
          </span>
        </div>

        <div class="flex flex-col gap-6 sm:flex-row sm:items-end">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.order_deadline') }}</span>
            <span
              class="flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus"
            >
              <img :src="iconCalendar" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
              <input
                v-model="form.order_deadline"
                type="date"
                :min="today"
                class="min-w-0 flex-1 text-base text-[#0f172a] focus:outline-none"
              />
            </span>
            <span v-if="form.errors.order_deadline" class="text-xs text-danger">
              {{ form.errors.order_deadline }}
            </span>
          </label>

          <div class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="flex items-center gap-1">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.documents') }}</span>
              <span class="text-xs text-[#94a3b8]">{{ t('procurement_create.optional') }}</span>
            </span>
            <!-- Upload-Zone 401:22619 -->
            <label
              class="flex cursor-pointer items-center gap-3 rounded-lg border-[1.5px] border-dashed border-[#e2e8f0] bg-[#f8fafc] p-3"
            >
              <span
                class="shrink-0 rounded-md border border-[#e2e8f0] bg-white px-4 py-2 text-xs font-semibold text-[#0f172a]"
              >
                {{ t('procurement_create.browse') }}
              </span>
              <span class="flex min-w-0 flex-col gap-0.5">
                <span class="min-w-0 truncate text-xs text-[#475569]">
                  {{ fileName || t('procurement_create.no_file_selected') }}
                </span>
                <span class="text-2xs text-[#94a3b8]">{{ t('procurement_create.pdf_office_or_image_files_max_10mb_each') }}</span>
              </span>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                multiple
                class="sr-only"
                @change="onFile"
              />
            </label>

            <!-- Each attached file, addable across several picks and removable
                 one at a time. -->
            <ul v-if="selectedFiles.length" class="flex flex-col gap-1.5">
              <li
                v-for="(f, i) in selectedFiles"
                :key="`${f.name}-${f.size}-${i}`"
                class="flex items-center justify-between gap-3 rounded-md border border-[#e2e8f0] bg-white px-3 py-2"
              >
                <span class="min-w-0 truncate text-xs text-[#0f172a]">{{ f.name }}</span>
                <button
                  type="button"
                  class="shrink-0 text-xs font-semibold text-danger hover:underline"
                  @click="removeFile(i)"
                >
                  {{ t('procurement_create.remove') }}
                </button>
              </li>
            </ul>

            <span v-if="form.errors.document || form.errors['documents.0']" class="text-xs text-danger">
              {{ form.errors.document || form.errors['documents.0'] }}
            </span>
          </div>
        </div>
      </section>

      <!--
        Section 6 — the RFP schedule. An ADDITION: there is no frame for it,
        because a request only ever carried a single deadline. Built from the
        same section card, label and input treatment as the five above.
      -->
      <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
        <div class="flex flex-col gap-1.5">
          <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.6_procurement_schedule') }}</h2>
          <p class="text-xs text-[#475569]">
            {{ t('procurement_create.optional_but_bidders_plan_around_these_pro') }}
          </p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 [&>*]:min-w-0">
          <label v-for="d in scheduleDates" :key="d.key" class="flex min-w-0 flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ d.label }}</span>
            <span
              class="flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus"
            >
              <img :src="iconCalendar" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
              <input
                v-model="(form as any)[d.key]"
                type="date"
                :min="today"
                class="min-w-0 flex-1 text-base text-[#0f172a] focus:outline-none"
              />
            </span>
            <span class="text-2xs text-[#94a3b8]">{{ d.hint }}</span>
            <span v-if="(form.errors as any)[d.key]" class="text-xs text-danger">
              {{ (form.errors as any)[d.key] }}
            </span>
          </label>
        </div>

        <hr class="border-[#e2e8f0]" />

        <!-- Pre-proposal briefing: a meeting, so it carries a time and a link. -->
        <div class="flex flex-col gap-6">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-display text-base font-semibold text-[#0f172a]">
              {{ t('procurement_create.pre_proposal_briefing') }}
            </h3>
            <label class="flex items-center gap-2 text-xs text-[#475569]">
              <input v-model="form.briefing_mandatory" type="checkbox" class="size-4" />
              {{ t('procurement_create.attendance_is_mandatory') }}
            </label>
          </div>

          <div class="grid gap-6 sm:grid-cols-2 [&>*]:min-w-0">
            <label class="flex min-w-0 flex-col gap-2">
              <span class="text-sm font-semibold text-[#0f172a]">
                {{ t('procurement_create.date_amp_time') }}
                <span v-if="!form.briefing_mandatory" class="font-normal text-[#94a3b8]">
                  {{ t('procurement_create.non_mandatory') }}
                </span>
              </span>
              <span
                class="flex items-center gap-2 rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus"
              >
                <img :src="iconCalendar" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
                <input
                  v-model="form.briefing_at"
                  type="datetime-local"
                  class="min-w-0 flex-1 text-base text-[#0f172a] focus:outline-none"
                />
              </span>
              <span v-if="form.errors.briefing_at" class="text-xs text-danger">
                {{ form.errors.briefing_at }}
              </span>
            </label>

            <label class="flex min-w-0 flex-col gap-2">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('procurement_create.meeting_link') }}</span>
              <input
                v-model="form.briefing_link"
                type="url"
                :placeholder="t('procurement_create.https_meet_example_com_briefing')"
                class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
              />
              <span v-if="form.errors.briefing_link" class="text-xs text-danger">
                {{ form.errors.briefing_link }}
              </span>
            </label>

            <label class="flex min-w-0 flex-col gap-2 sm:col-span-2">
              <span class="text-sm font-semibold text-[#0f172a]">
                {{ t('procurement_create.venue') }}
                <span class="font-normal text-[#94a3b8]">{{ t('procurement_create.if_held_in_person') }}</span>
              </span>
              <input
                v-model="form.briefing_location"
                type="text"
                :placeholder="t('procurement_create.e_g_registry_house_14_admiralty_way_lekki')"
                class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-base text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
              />
            </label>
          </div>
        </div>
      </section>

      <!-- Form-Footer 401:22625 -->
      <div class="flex flex-wrap items-center justify-between gap-4 py-4">
        <RouterLink
          href="/procurement"
          class="rounded-lg border border-[#e2e8f0] bg-white px-6 py-3.5 text-base font-semibold text-[#475569] hover:bg-surface-secondary"
        >
          {{ t('procurement_create.cancel') }}
        </RouterLink>
        <div class="flex flex-wrap items-center gap-3">
          <button
            v-if="canSaveDraft"
            type="button"
            :disabled="form.processing"
            class="rounded-lg border border-[#e2e8f0] bg-white px-6 py-3.5 text-base font-semibold text-[#0f172a] hover:bg-surface-secondary disabled:opacity-60"
            @click="saveDraft"
          >
            {{ t('procurement_create.save_as_draft') }}
          </button>
          <button
            type="submit"
            :disabled="form.processing"
            class="btn-primary inline-flex items-center gap-2 rounded-lg px-8 py-3.5 text-base font-semibold disabled:opacity-60"
          >
            {{
              form.processing
                ? isLiveEdit
                  ? t('procurement_create.saving')
                  : t('procurement_create.posting')
                : isLiveEdit
                  ? t('procurement_create.save_changes')
                  : t('procurement_create.post_request')
            }}
            <img :src="iconArrowRight" alt="" class="size-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </form>

    <!-- workspace-sidebar 401:22326 -->
    <aside class="flex w-full min-w-0 shrink-0 flex-col gap-6 lg:w-[380px]">
      <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-6">
        <h2 class="font-display text-lg font-semibold text-[#0f172a]">{{ t('procurement_create.workspace_summary') }}</h2>
        <p class="text-xs text-[#475569]">
          {{ t('procurement_create.a_quick_view_of_your_activity_across_the_r') }}
        </p>
        <dl class="flex flex-col text-sm">
          <div
            v-for="s in summary"
            :key="s.label"
            class="flex items-start justify-between border-b border-[#f1f5f9] py-2"
          >
            <dt class="text-[#475569]">{{ s.label }}</dt>
            <dd class="font-semibold text-[#0f172a]">{{ s.value }}</dd>
          </div>
        </dl>
      </section>

      <!-- need-help-card 401:22343 — teal here, not the amber the app flow uses -->
      <section class="flex flex-col gap-4 rounded-2xl border border-[#0d9488] bg-[#ccfbf1] p-6">
        <h2 class="font-display text-lg font-semibold text-[#0d9488]">{{ t('procurement_create.need_help') }}</h2>
        <p class="text-xs text-[#0f172a]">
          {{ t('procurement_create.the_registry_support_team_is_standing_by_t') }}
        </p>
        <RouterLink href="/support" class="text-sm font-semibold text-[#0d9488]">
          {{ t('procurement_create.contact_support') }}
        </RouterLink>
      </section>

      <!-- ad slot 401:22347 -->
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
          <span class="text-2xs text-[#d1d5db]">{{ t('procurement_create.advertisement_space') }}</span>
          <span class="max-w-[232px] font-display text-xl leading-6 font-semibold text-white">
            {{ t('procurement_create.reach_verified_loveworld_businesses') }}
          </span>
          <span
            class="mt-auto inline-flex h-8 w-full max-w-[200px] items-center justify-center gap-1.5 rounded bg-white px-3 text-2xs font-semibold text-[#1e3a8a]"
          >
            {{ t('procurement_create.advertise_your_business') }}
            <img :src="iconAdArrow" alt="" class="h-2.5 w-2 shrink-0" aria-hidden="true" />
          </span>
        </span>
      </RouterLink>
    </aside>
  </div>

</template>
