<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link as RouterLink, router, useForm, usePage } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'

import iconFileText from '@/assets/figma/procurement/file-text.svg'
import iconBriefcase from '@/assets/figma/procurement/briefcase.svg'
import iconTag from '@/assets/figma/procurement/tag.svg'
import iconFlag from '@/assets/figma/procurement/flag.svg'
import iconEdit from '@/assets/figma/procurement/edit-2.svg'
import iconDownload from '@/assets/figma/procurement/download.svg'
import iconAlertTriangle from '@/assets/figma/procurement/alert-triangle.svg'
import iconMessageSquare from '@/assets/figma/procurement/message-square.svg'
import iconHelpCircle from '@/assets/figma/procurement/help-circle.svg'
import iconMail from '@/assets/figma/procurement/mail.svg'
import iconUser from '@/assets/figma/procurement/user.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t } = useI18n()
const { entityTerm } = useEntityTerm()

/*
 * Whether we have just arrived from posting the request. The controller
 * flashes `rfq-created`; nothing rendered it, so a buyer who had just filled
 * in a long form landed on a page that looked like any other.
 */
const justPosted = computed(() => usePage().props.flash.status === 'rfq-created')

/*
 * Time remaining.
 *
 * The hero read "Time Remaining: 13 days, 23 hours, 49 minutes" on every
 * request in the registry, frozen at whatever the designer typed. It counts the
 * real deadline down, once a minute — seconds would re-render the hero sixty
 * times as often for a number nobody reads that closely.
 */
const now = ref(Date.now())
let tick: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  tick = setInterval(() => (now.value = Date.now()), 60_000)
})

onBeforeUnmount(() => clearInterval(tick))

const timeRemaining = computed(() => {
  if (!props.request.deadlineAt) return null

  const left = new Date(props.request.deadlineAt).getTime() - now.value

  if (left <= 0) return t('procurement_detail.closed_for_bids')

  const days = Math.floor(left / 86_400_000)
  const hours = Math.floor((left % 86_400_000) / 3_600_000)
  const minutes = Math.floor((left % 3_600_000) / 60_000)

  return t('procurement_detail.time_remaining', { days, hours, minutes })
})

defineOptions({ layout: AppLayout })

/*
 * Procurement request detail — five Figma frames behind one route. The frame
 * NAMES and their contents disagree, so the mapping below is by content:
 *
 *   overview   509:50207 -> workspace-split 509:50260
 *   documents  509:51122 -> downloads-content 509:52339
 *   qa         509:52013 "q & a User view"   -> qa-content 509:52383   (answered)
 *              509:51392 "interest"          -> Content-Area 509:51662 (empty)
 *   interest   509:51680 "q & a vendor view" -> Table-Section 509:51920
 *
 * The last two are swapped in the file: the frame called "interest" draws the
 * Q&A empty state, and the one called "q & a vendor view" draws the table of
 * interested vendors — its tab node is even named Tab-Followers.
 *
 * Shared shell: hero-section 509:50209 (px-80 py-48, VIDEO fill) over a 1120px
 * bordered card whose tabs-container 509:50262 puts an 8px #1b8a4e underline on
 * the active tab, plus bottom-sticky-bar 509:50318.
 */

type Question = {
  id: string
  n: string
  title: string
  body: string
  at: string | null
  author: string
  mine: boolean
  answer: string | null
  answeredAt: string | null
  answered: boolean
}

type BidDetails = Record<string, Record<string, unknown>>

type Interest = {
  id: string
  vendor: string
  slug: string | null
  contact: string | null
  email: string | null
  address: string | null
  status: string
  submittedAt: string | null
  awarded: boolean
  canView: boolean
  details: BidDetails | null
  proposal: string | null
  attachments: { name: string; url: string }[]
}

type Bid = {
  id: string
  bidder: string
  slug: string | null
  proposal: string | null
  status: string
  submittedAt: string | null
  attachments: { name: string; url: string }[]
}

const props = defineProps<{
  id: string
  tab: string
  request: {
    id: string
    title: string
    body: string | null
    /** The same description as sanitised markup; see App\Support\RichText. */
    bodyHtml: string | null
    category: string | null
    buyer: string | null
    budget: string | null
    status: string
    bids: string
    posted: string | null
    deadline: string | null
    /** The full posting stamp, and the edit stamp when there has been one. */
    postedAt: string | null
    updatedAt: string | null
    /** ISO, for the live countdown. Null when the request has no deadline. */
    deadlineAt: string | null
    timezone: string
  }
  /** Whether the viewer posted this RFQ — only they see and award the bids. */
  isOwner: boolean
  /** An administrator overseeing procurement: sees the bids read-only, no award. */
  isAdmin: boolean
  /** Whether the viewer has already bid — the button then edits, not places. */
  hasBid: boolean
  awardedBidId: string | null
  bids: Bid[]
  /** The Q&A thread. `author` is already anonymised for non-buyers. */
  questions: Question[]
  canAsk: boolean
  /** Verified businesses only may bid or ask; the screen explains why not. */
  verifiedToParticipate: boolean
  /** Who has bid, and what they submitted (buyer or the bidder themselves). */
  interest: Interest[]
  /** The RFP schedule; only the dates actually set are sent. */
  timeline: { label: string; value: string; note: string | null; link: string | null }[]
  /** Project Documents / Attachments, as uploaded against this request. */
  documents: {
    key: string
    title: string
    items: { id: number | null; name: string; date: string | null; size: string | null; url: string }[]
  }[]
  documentsDownloadUrl: string
  documentsUploadUrl: string
  /**
   * The owner's controls for a private request: who is invited, and who is
   * waiting to be let in. Null for a public request or a non-owner.
   */
  access: {
    requests: { name: string; email: string | null; approveUrl: string; declineUrl: string }[]
    invitees: string[]
  } | null
  /** Whether the viewer's business won this request — they may leave a testimonial. */
  isWinner: boolean
  /** The winner's existing testimonial, so the form edits rather than duplicates. */
  testimonial: { rating: number; headline: string; body: string; displayPublicly: boolean } | null
}>()

function respondToAccess(url: string) {
  router.post(url, {}, { preserveScroll: true })
}

/* ------------------------------------------------ the winner's testimonial --- */
const testimonialOpen = ref(false)
const testimonialForm = useForm<{ rating: number; headline: string; body: string; display_publicly: boolean }>({
  rating: props.testimonial?.rating ?? 5,
  headline: props.testimonial?.headline ?? '',
  body: props.testimonial?.body ?? '',
  // Default to consenting: the point of asking the winner is the showcase, and
  // the checkbox lets them opt out rather than having to opt in.
  display_publicly: props.testimonial?.displayPublicly ?? true,
})

function submitTestimonial() {
  testimonialForm.post(`/procurement/${props.id}/testimonial`, {
    preserveScroll: true,
    onSuccess: () => {
      testimonialOpen.value = false
    },
  })
}

// The "Write a testimonial" notification links here with ?testimonial=1.
onMounted(() => {
  if (props.isWinner && new URLSearchParams(window.location.search).get('testimonial') === '1') {
    testimonialOpen.value = true
  }
})

/*
 * Review & Award. `purchase_orders.awarded_bid_id` has existed all along and
 * POST /procurement/{id}/award has been routed since the write endpoints
 * landed; the screen had neither a bid list nor a control. This is both, in
 * the page card's own idiom — the Figma frames for this route draw the vendor
 * table and the Q&A, never an award panel.
 */
/* ------------------------------------------------ Q&A 509:52383 ---------
 * The tab drew a guidelines panel, an "Ask Question" button and two invented
 * entries. All of it is real now; see PurchaseOrderQuestion.
 */
const asking = ref(false)
const ask = useForm({ title: '', body: '' })

function submitQuestion() {
  ask.post(`/procurement/${props.id}/questions`, {
    preserveScroll: true,
    onSuccess: () => {
      ask.reset()
      asking.value = false
    },
  })
}

/** The buyer's reply, keyed by question so two rows cannot share a draft. */
const answering = ref<string | null>(null)
const answer = useForm({ answer: '' })

function submitAnswer(question: Question) {
  answer.post(`/procurement/${props.id}/questions/${question.id}/answer`, {
    preserveScroll: true,
    onSuccess: () => {
      answer.reset()
      answering.value = null
    },
  })
}

/* --------------------------------------------- Interest 509:51920 -------
 * "View Details" was a dead button on a table of invented vendors. It opens
 * the bid that vendor actually submitted.
 */
const openBidId = ref<string | null>(null)

const openBid = computed(() => props.interest.find((i) => i.id === openBidId.value) ?? null)

/** The wizard's nested answers, flattened into readable label/value rows. */
const LABELS: Record<string, string> = {
  contact: t('procurement_detail.contact'),
  address: t('listing.locked.address'),
  company: t('procurement_detail.company_profile'),
  declaration: t('procurement_detail.declaration'),
}

function humanise(key: string): string {
  return key
    .replace(/([A-Z])/g, ' $1')
    .replace(/^./, (c) => c.toUpperCase())
    .trim()
}

function sections(details: BidDetails | null) {
  if (!details) return []

  return Object.entries(details).map(([group, fields]) => ({
    label: LABELS[group] ?? humanise(group),
    rows: Object.entries(fields ?? {})
      .map(([key, value]) => ({
        label: humanise(key),
        value: Array.isArray(value)
          ? value.join(', ')
          : typeof value === 'boolean'
            ? (value ? t('procurement_detail.yes') : 'No')
            : String(value ?? ''),
      }))
      // A blank answer is not worth a row on someone's bid.
      .filter((row) => row.value.trim() !== ''),
  })).filter((section) => section.rows.length > 0)
}

const award = useForm<{ bid_id: string | null; award_note: string }>({
  bid_id: null,
  award_note: '',
})

const awarded = computed(() => props.awardedBidId !== null)

function submitAward() {
  if (!award.bid_id) return
  award.post(`/procurement/${props.id}/award`, { preserveScroll: true })
}

// From the server rather than parsed out of the URL — the screen previously
// showed a fixed RFQ no matter which id was requested.
const tab = computed(() => props.tab || 'overview')
const id = computed(() => props.id)

const tabs = [
  { key: 'overview', label: t('procurement_detail.overview') },
  { key: 'documents', label: t('procurement_detail.project_documents') },
  { key: 'qa', label: t('procurement_detail.question_answer') },
  { key: 'interest', label: t('procurement_detail.interest') },
]

const meta = computed(() =>
  [
    { icon: iconFileText, text: t('procurement_detail.request_for_proposal'), bold: true },
    { icon: iconBriefcase, text: props.request.buyer, bold: false },
    { icon: iconTag, text: props.request.category, bold: false },
  ].filter((m): m is { icon: string; text: string; bold: boolean } => Boolean(m.text)),
)

// timeline-table 509:50296
/*
 * Timeline and documents both come from the server now.
 *
 * The Timeline was six fixed rows — "July 1, 2026" and a Teams link to a
 * meeting nobody scheduled — shown identically on every request. The Documents
 * tab listed the same two invented files, with checkboxes and a "Download
 * Selected Items" button that had no route behind it.
 */
const timeline = computed(() => props.timeline)
const docSections = computed(() => props.documents)

/* ------------------------------------------------- document selection --- */

/*
 * Only rows with an id are selectable. A request's legacy single upload
 * (`purchase_orders.document_path`) is listed alongside the rest so nothing
 * already uploaded disappears, but it is not a row in the documents table and
 * so cannot be part of a bulk download — it links to itself instead.
 */
const selectable = computed(() =>
  docSections.value.flatMap((s) => s.items).filter((d): d is typeof d & { id: number } => d.id !== null),
)

const selected = ref<number[]>([])

const allSelected = computed(
  () => selectable.value.length > 0 && selected.value.length === selectable.value.length,
)

function toggleAll() {
  selected.value = allSelected.value ? [] : selectable.value.map((d) => d.id)
}

/*
 * A plain link rather than a form post: the response is a file (or a zip of
 * several), and an Inertia visit cannot hand the browser a download.
 */
const downloadUrl = computed(() => {
  const params = selected.value.map((id) => `ids[]=${id}`).join('&')
  return `${props.documentsDownloadUrl}?${params}`
})

</script>

<template>
  <!--
    Confirmation that it worked.
    Posting a request redirected here with a flash nothing rendered, so a buyer
    who had just filled in a long form arrived at a page that looked the same
    as any other and had no way to tell whether it had saved.
  -->
  <p
    v-if="justPosted"
    class="border-b border-[#bbf7d0] bg-[#f0fdf4] section-x py-3 text-sm font-medium text-[#15803d]"
    role="status"
  >
    {{ t('procurement_detail.request_posted') }}
  </p>

  <!-- ======================================= hero-section 509:50209 ======= -->
  <section class="relative overflow-hidden bg-[#0b192c] section-x py-8 sm:py-12">
    <HeroVideo overlay="bg-[#0b192c]/80" />

    <div class="relative flex flex-col gap-7">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('procurement_detail.breadcrumb')">
        <RouterLink href="/sbo/dashboard" class="text-[#94a3b8] hover:text-white">
          {{ t('procurement_detail.sbo_workspace', { entity: entityTerm }) }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('procurement_detail.gt') }}</span>
        <span class="text-white">{{ t('procurement_detail.procurement_requests') }}</span>
      </nav>

      <h1 class="max-w-[1103px] font-serif text-4xl font-bold text-white">
        {{ request.title }}
      </h1>

      <!-- rfp-title-details 509:50220 -->
      <div class="flex flex-col gap-1.5">
        <!--
          Shown only when the buyer has genuinely edited the request. It used
          to read "Last updated by Addendum #1 on Jul 28, 2026 2:09 PM · See
          what changed" on every request, with both links going to "#" — an
          amendment history that does not exist.
        -->
        <p v-if="request.updatedAt" class="text-xs text-white">
          {{ t('procurement_detail.last_updated_on', { date: request.updatedAt }) }}
        </p>

        <div class="flex flex-wrap items-center gap-4">
          <span v-for="m in meta" :key="m.text" class="flex items-center gap-1">
            <img :src="m.icon" alt="" class="size-3.5" aria-hidden="true" />
            <span class="text-xs text-[#fff7f7]" :class="m.bold && 'font-semibold'">
              {{ m.text }}
            </span>
          </span>
        </div>

        <p class="text-xs text-white">
          <strong class="font-semibold">{{ t('procurement_detail.project_id') }}</strong> {{ t('procurement_detail.rfp_number', { id: request.id }) }}
        </p>
        <p class="text-xs text-[#fff7f7]">
          <strong class="font-semibold text-white">{{ t('procurement_detail.release_date') }}</strong> {{ request.posted ?? '—' }} ·
          <strong class="font-semibold text-white">{{ t('procurement_detail.due_date') }}</strong> {{ request.deadline ?? t('procurement_detail.no_deadline_set') }}
        </p>
        <p class="text-xs text-[#fff7f7]">
          <strong class="font-semibold text-white">{{ t('procurement_detail.status') }}</strong> {{ request.status }} ·
          <strong class="font-semibold text-white">{{ t('procurement_detail.bids') }}</strong> {{ request.bids }}
          <template v-if="request.budget"> · {{ request.budget }}</template>
        </p>

        <p class="flex items-center gap-1 text-xs text-[#fff7f7]">
          <img :src="iconFlag" alt="" class="size-[13px]" aria-hidden="true" />
          <span>
            <strong class="font-semibold text-white">{{ t('procurement_detail.posted') }}</strong> {{ request.postedAt ?? '—' }}
          </span>
        </p>
        <p class="text-2xs text-[#fff7f7] italic">
          {{ t('procurement_detail.all_dates_in_timezone', { zone: request.timezone }) }}
        </p>

        <!-- action-bar 509:50243 -->
        <div class="flex flex-wrap items-center gap-3 pt-2 pb-4">
          <!--
            Verified businesses only may bid. Shown disabled with the reason
            rather than hidden, so the rule is legible instead of the control
            just being absent.
          -->
          <!-- The owner edits rather than bids on their own request. -->
          <RouterLink
            v-if="props.isOwner"
            :href="`/procurement/${id}/edit`"
            class="inline-flex items-center gap-1.5 rounded bg-white/10 px-4 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-white/20"
          >
            <img :src="iconEdit" alt="" class="size-3.5" aria-hidden="true" />
            {{ t('procurement_detail.edit_request') }}
          </RouterLink>
          <RouterLink
            v-if="props.verifiedToParticipate && !props.isOwner"
            :href="`/procurement/${id}/bid`"
            class="inline-flex items-center gap-1.5 rounded bg-[#008751] px-4 py-2.5 text-xs font-semibold text-white transition-opacity hover:opacity-90"
          >
            <img :src="iconEdit" alt="" class="size-3.5" aria-hidden="true" />
            {{ props.hasBid ? t('procurement_detail.edit_bid') : t('procurement_detail.place_bid') }}
          </RouterLink>
          <span
            v-else-if="!props.isOwner"
            class="inline-flex cursor-not-allowed items-center gap-1.5 rounded bg-[#008751]/40 px-4 py-2.5 text-xs font-semibold text-white/70"
            :title="t('procurement_detail.only_a_verified_business_can_bid')"
          >
            <img :src="iconEdit" alt="" class="size-3.5" aria-hidden="true" />
            {{ t('procurement_detail.place_bid_verification_required') }}
          </span>
          <p v-if="timeRemaining" class="text-xs font-semibold text-[#daecbe]">
            {{ timeRemaining }}
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- ============================ page card + tabs-container 509:50262 ==== -->
  <div class="bg-surface-secondary section-x py-8 sm:py-12 pb-[92px]">
    <div class="overflow-hidden rounded-lg border border-[#e0e0e0] bg-white">
      <div class="flex flex-wrap border-b border-[#e0e0e0] bg-white pl-4">
        <RouterLink
          v-for="t in tabs"
          :key="t.key"
          :href="`/procurement/${id}/${t.key}`"
          class="relative px-6 py-3.5 text-sm font-semibold whitespace-nowrap"
          :class="tab === t.key ? 'text-[#0f172a]' : 'text-[#64748b] hover:text-[#0f172a]'"
          :aria-current="tab === t.key ? 'page' : undefined"
        >
          {{ t.label }}
          <span
            v-if="tab === t.key"
            class="absolute inset-x-0 -bottom-px h-2 bg-[#1b8a4e]"
            aria-hidden="true"
          />
        </RouterLink>
      </div>

      <!-- ============================== overview-content 509:50273 ======== -->
      <div v-if="tab === 'overview'" class="flex flex-col gap-8 p-4 sm:p-8">
        <!-- The winner is invited to share a testimonial about the engagement. -->
        <section
          v-if="props.isWinner"
          class="flex flex-col gap-3 rounded-xl border border-[#bbf7d0] bg-[#f0fdf4] p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
        >
          <div class="flex items-start gap-3">
            <svg viewBox="0 0 24 24" fill="none" stroke="#007035" stroke-width="1.8" class="mt-0.5 size-6 shrink-0" aria-hidden="true">
              <path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 4H5a2 2 0 0 0 0 4h1M17 4h2a2 2 0 0 1 0 4h-1" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div>
              <p class="text-sm font-semibold text-[#065f46]">{{ t('awarded.you_won_title') }}</p>
              <p class="text-xs text-[#047857]">
                {{ props.testimonial ? t('awarded.testimonial_saved') : t('awarded.you_won_sub') }}
              </p>
            </div>
          </div>
          <button
            type="button"
            class="shrink-0 rounded-lg bg-[#007035] px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
            @click="testimonialOpen = true"
          >
            {{ props.testimonial ? t('awarded.edit_testimonial') : t('awarded.write_testimonial') }}
          </button>
        </section>

        <!-- Private-request access: the owner sees who is waiting and who is in. -->
        <section v-if="props.access" class="flex flex-col gap-4 rounded-lg border border-[#e2e8f0] bg-[#f8fafc] p-4 sm:p-5">
          <h3 class="text-sm font-semibold text-[#0f172a]">Private request — access</h3>

          <div v-if="props.access.requests.length" class="flex flex-col gap-2">
            <p class="text-xs font-semibold text-[#475569]">Waiting for access</p>
            <div
              v-for="(r, i) in props.access.requests"
              :key="i"
              class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-[#e2e8f0] bg-white px-3 py-2"
            >
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-[#0f172a]">{{ r.name }}</span>
                <span v-if="r.email" class="block truncate text-xs text-[#94a3b8]">{{ r.email }}</span>
              </span>
              <span class="flex shrink-0 gap-2">
                <button type="button" class="rounded-md bg-[#007035] px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90" @click="respondToAccess(r.approveUrl)">
                  Approve
                </button>
                <button type="button" class="rounded-md border border-[#e2e8f0] px-3 py-1.5 text-xs font-medium text-[#475569] hover:bg-surface-secondary" @click="respondToAccess(r.declineUrl)">
                  Decline
                </button>
              </span>
            </div>
          </div>
          <p v-else class="text-xs text-[#94a3b8]">No one is waiting for access.</p>

          <div v-if="props.access.invitees.length" class="flex flex-col gap-2">
            <p class="text-xs font-semibold text-[#475569]">Shared with</p>
            <ul class="flex flex-wrap gap-2">
              <li v-for="(who, i) in props.access.invitees" :key="i" class="rounded-full border border-[#e2e8f0] bg-white px-3 py-1 text-xs text-[#0f172a]">
                {{ who }}
              </li>
            </ul>
          </div>
        </section>

        <div class="flex flex-col gap-2 text-[#1a1a1a]">
          <h2 class="font-serif text-2xl font-bold">{{ t('procurement_detail.overview') }}</h2>
          <h3 class="text-base font-semibold">{{ t('procurement_detail.summary') }}</h3>
        </div>

        <!-- Sanitised server-side by App\Support\RichText, which is why this
             is v-html: the allowlist runs before it is stored, not here. -->
        <div
          v-if="request.bodyHtml"
          class="rich-text text-sm leading-6 text-[#1a1a1a]"
          v-html="request.bodyHtml"
        />
        <p v-else class="text-sm leading-6 text-[#1a1a1a]">
          {{ t('bid_wizard.no_description_was_provided_for_this_reque') }}
        </p>

        <section v-if="timeline.length" class="flex flex-col gap-4">
          <h3 class="text-base font-semibold text-[#1a1a1a]">{{ t('procurement_detail.timeline') }}</h3>
          <dl class="flex flex-col gap-4">
            <div
              v-for="row in timeline"
              :key="row.label"
              class="flex flex-col gap-1 sm:flex-row sm:gap-6"
            >
              <dt class="shrink-0 text-sm font-semibold text-[#1a1a1a] sm:w-[300px] sm:text-right">
                {{ row.label }}
              </dt>
              <dd class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="text-sm text-[#1a1a1a]">{{ row.value }}</span>
                <span v-if="row.note" class="text-xs text-[#666]">{{ row.note }}</span>
                <a v-if="row.link" :href="row.link" class="text-xs break-all text-[#06c]">
                  {{ row.link }}
                </a>
              </dd>
            </div>
          </dl>
        </section>

        <!--
          Review & Award. Owner-only, and the only place awarded_bid_id can be
          set. Not a Figma frame — none was drawn for this route — so it stays
          inside the page card and reuses its type scale and rules.
        -->
        <section v-if="props.isOwner || props.isAdmin" class="flex flex-col gap-4 border-t border-[#e0e0e0] pt-8">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-[#1a1a1a]">{{ t('procurement_detail.review_amp_award') }}</h3>
            <span
              v-if="awarded"
              class="rounded-md bg-[#dcfce7] px-2.5 py-1 text-xs font-semibold text-[#007035]"
            >
              {{ t('procurement_detail.awarded') }}
            </span>
            <span
              v-else-if="!props.isOwner && props.isAdmin"
              class="rounded-md bg-[#f1f5f9] px-2.5 py-1 text-xs font-semibold text-[#475569]"
            >
              {{ t('procurement_detail.admin_view') }}
            </span>
          </div>

          <p v-if="!props.bids.length" class="text-sm text-[#666]">
            {{ t('procurement_detail.no_bids_have_been_submitted_yet') }}
          </p>

          <template v-else>
            <ul class="flex flex-col gap-3">
              <li v-for="b in props.bids" :key="b.id">
                <label
                  class="flex gap-3 rounded-lg border p-4 transition-colors"
                  :class="[
                    props.isOwner ? 'cursor-pointer' : '',
                    b.id === props.awardedBidId
                      ? 'border-[#007035] bg-[#f0fdf4]'
                      : award.bid_id === b.id
                        ? 'border-[#1b8a4e] bg-[#f8fafc]'
                        : 'border-[#e2e8f0] bg-white hover:bg-[#f8fafc]',
                  ]"
                >
                  <input
                    v-if="props.isOwner"
                    v-model="award.bid_id"
                    type="radio"
                    :value="b.id"
                    name="award"
                    :disabled="awarded"
                    class="mt-1 size-[18px] shrink-0 appearance-none rounded-full border-2 border-[#e2e8f0] bg-white checked:border-[6px] checked:border-[#007035] disabled:opacity-50"
                  />
                  <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <span class="flex flex-wrap items-center gap-2">
                      <RouterLink
                        v-if="b.slug"
                        :href="`/business/${b.slug}`"
                        class="text-base font-semibold text-[#0f172a] hover:underline"
                      >
                        {{ b.bidder }}
                      </RouterLink>
                      <span v-else class="text-base font-semibold text-[#0f172a]">
                        {{ b.bidder }}
                      </span>
                      <span class="rounded bg-[#f1f5f9] px-2 py-0.5 text-2xs text-[#475569]">
                        {{ b.status }}
                      </span>
                      <span v-if="b.submittedAt" class="text-xs text-[#64748b]">
                        {{ b.submittedAt }}
                      </span>
                    </span>
                  </span>
                </label>
              </li>
            </ul>

            <template v-if="props.isOwner && !awarded">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">
                  {{ t('procurement_detail.award_note') }} <span class="font-normal text-[#94a3b8]">{{ t('procurement_detail.optional') }}</span>
                </span>
                <textarea
                  v-model="award.award_note"
                  rows="3"
                  :placeholder="t('procurement_detail.anything_the_awarded_bidder_should_know')"
                  class="resize-y rounded-lg border border-[#e2e8f0] bg-white p-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                />
              </label>

              <p v-if="award.errors.bid_id" class="text-xs text-danger">
                {{ award.errors.bid_id }}
              </p>

              <button
                type="button"
                :disabled="!award.bid_id || award.processing"
                class="inline-flex w-fit items-center gap-2 rounded bg-[#007035] px-6 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                @click="submitAward"
              >
                {{ award.processing ? t('procurement_detail.awarding') : t('procurement_detail.award_this_bid') }}
              </button>
              <p class="text-xs text-[#64748b]">
                {{ t('procurement_detail.awarding_closes_the_request_and_marks_ever') }}
              </p>
            </template>
          </template>
        </section>
      </div>

      <!-- ============================= downloads-content 509:52339 ======== -->
      <div v-else-if="tab === 'documents'" class="flex flex-col items-center gap-6 p-5 sm:p-10">
        <h2 class="text-center font-serif text-2xl font-bold text-[#1a1a1a]">{{ t('procurement_detail.project_documents_download') }}</h2>
        <p class="text-center text-sm text-[#666]">
          {{ t('procurement_detail.select_the_documents_you_would_like_to_dow') }}
        </p>
        <button
          v-if="selectable.length"
          type="button"
          class="text-center text-sm font-semibold text-black underline"
          @click="toggleAll"
        >
          {{ allSelected ? t('procurement_detail.clear_selection') : t('procurement_detail.select_all') }}
        </button>

        <div v-if="docSections.length" class="flex w-full max-w-[800px] flex-col gap-6">
          <section v-for="s in docSections" :key="s.key" class="flex flex-col gap-3">
            <h3 class="text-base font-semibold text-[#1a1a1a]">{{ s.title }}</h3>
            <div class="flex flex-col rounded border border-[#dcdcdc] bg-white">
              <label
                v-for="(d, i) in s.items"
                :key="d.id ?? d.name"
                class="flex items-start gap-3 p-3"
                :class="[i > 0 && 'border-t border-[#dcdcdc]', d.id !== null && 'cursor-pointer']"
              >
                <input
                  v-if="d.id !== null"
                  v-model="selected"
                  :value="d.id"
                  type="checkbox"
                  class="mt-0.5 size-[18px] shrink-0 appearance-none rounded-[9px] border-2 border-[#666] checked:border-[#007035] checked:bg-[#007035]"
                />
                <!-- Placeholder keeps the legacy single upload aligned with the
                     rows that do have a checkbox. -->
                <span v-else class="mt-0.5 size-[18px] shrink-0" aria-hidden="true" />

                <span class="flex min-w-0 flex-col gap-0.5">
                  <!-- Plain text, not a link: clicking the row selects the
                       document (the label toggles its checkbox) rather than
                       opening the file. The selected ones download together. -->
                  <span class="text-sm font-medium break-words text-[#1a1a1a]">
                    {{ d.name }}
                  </span>
                  <span class="text-xs text-[#666]">
                    {{ d.date }}<template v-if="d.size"> · {{ d.size }}</template>
                  </span>
                </span>
              </label>
            </div>
          </section>

          <!--
            A real link, not a button: the response is a file (or a zip of
            several), which an Inertia visit cannot hand to the browser.
          -->
          <component
            :is="selected.length ? 'a' : 'span'"
            :href="selected.length ? downloadUrl : undefined"
            class="mx-auto inline-flex items-center gap-2 rounded bg-[#007035] px-6 py-3 text-sm font-semibold text-white transition-opacity"
            :class="selected.length ? 'hover:opacity-90' : 'cursor-not-allowed opacity-50'"
          >
            <img :src="iconDownload" alt="" class="size-4" aria-hidden="true" />
            Download Selected {{ selected.length ? `(${selected.length})` : t('procurement_detail.items') }}
          </component>
        </div>

        <!-- Reachable whenever the buyer has attached nothing. -->
        <p v-else class="text-sm text-[#666]">{{ t('procurement_detail.no_documents_have_been_attached_to_this_re') }}</p>
      </div>

      <!-- ================= qa-content 509:52383 / Content-Area 509:51662 === -->
      <div v-else-if="tab === 'qa'" class="flex flex-col gap-6 p-5 sm:p-10">
        <header v-if="props.questions.length" class="flex flex-col gap-2 text-center">
          <h2 class="font-serif text-2xl font-bold text-[#1a1a1a]">{{ t('procurement_detail.question_amp_answer') }}</h2>
          <p class="text-sm text-[#666]">{{ t('procurement_detail.questions_and_clarifications_about_the_pro') }}</p>
        </header>

        <div class="flex flex-col gap-8 lg:flex-row">
          <!-- Guidelines-Box 509:52508 -->
          <aside
            class="flex w-full min-w-0 shrink-0 flex-col gap-4 rounded-lg border border-[#e2e8f0] bg-white p-6 lg:w-[360px]"
          >
            <h3 class="text-base font-semibold text-[#0f172a]">{{ t('procurement_detail.question_amp_answer_guidelines') }}</h3>
            <p class="text-sm leading-5 text-[#475569]">
              {{ t('procurement_detail.question_amp_answer_is_open_your_name_and') }}
            </p>
            <div class="flex items-start gap-2 rounded-md bg-[#fff9e6] p-3">
              <img
                :src="iconAlertTriangle"
                alt=""
                class="mt-px size-4 shrink-0"
                aria-hidden="true"
              />
              <p class="text-xs font-semibold text-[#b78103]">{{ t('procurement_detail.questions_are_due_in_19_days') }}</p>
            </div>
            <button
              v-if="props.canAsk"
              type="button"
              class="flex items-center justify-center gap-2 rounded-md bg-[#007035] py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
              @click="asking = !asking"
            >
              <img :src="iconMessageSquare" alt="" class="size-4" aria-hidden="true" />
              {{ asking ? t('action.close') : t('procurement_detail.ask_question') }}
            </button>
            <p v-else class="text-xs text-[#64748b]">
              {{ t('procurement_detail.this_is_your_request_answer_questions_from') }}
            </p>

            <!-- The form the button opens. Not a Figma frame: the design draws
                 the button and the answered thread, never the composer. -->
            <form v-if="asking" class="flex flex-col gap-3" @submit.prevent="submitQuestion">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('procurement_detail.subject_optional') }}</span>
                <input
                  v-model="ask.title"
                  type="text"
                  :placeholder="t('procurement_detail.e_g_format_clarification')"
                  class="rounded-md border border-[#e2e8f0] px-3 py-2 text-sm focus:border-focus focus:outline-none"
                />
              </label>
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('procurement_detail.your_question') }}</span>
                <textarea
                  v-model="ask.body"
                  rows="5"
                  :placeholder="t('procurement_detail.be_specific_the_answer_is_published_to_eve')"
                  class="resize-y rounded-md border border-[#e2e8f0] px-3 py-2 text-sm placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
                />
                <span v-if="ask.errors.body" class="text-xs text-danger">{{ ask.errors.body }}</span>
              </label>
              <button
                type="submit"
                :disabled="!ask.body.trim() || ask.processing"
                class="rounded-md bg-[#007035] py-2.5 text-sm font-semibold text-white disabled:opacity-50"
              >
                {{ ask.processing ? t('forgot_password.sending') : t('procurement_detail.submit_question') }}
              </button>
            </form>
          </aside>

          <!-- QA-Empty-State 509:51674 -->
          <div
            v-if="!props.questions.length"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-[#e2e8f0] bg-white p-20"
          >
            <img :src="iconHelpCircle" alt="" class="size-16" aria-hidden="true" />
            <p class="text-center font-serif text-3xl font-bold text-[#0f172a]">
              {{ t('procurement_detail.no_questions_submitted_yet') }}
            </p>
            <p class="text-center text-base text-[#64748b]">
              {{ t('procurement_detail.be_the_first_to_ask_a_question_about_this') }}
            </p>
          </div>

          <!-- qa-entries 509:52390 -->
          <div v-else class="flex min-w-0 flex-1 flex-col gap-8">
            <article v-for="q in props.questions" :key="q.id" class="flex flex-col gap-3">
              <p class="text-base font-semibold text-[#1a1a1a]">
                {{ q.n }}. {{ q.title }}
                <span class="text-xs font-normal text-[#666]">{{ q.at }}</span>
              </p>

              <div class="overflow-hidden rounded border border-[#e0e0e0]">
                <div class="flex items-center gap-3 bg-[#f8f9fa] p-3">
                  <span class="grid size-10 shrink-0 place-items-center rounded bg-[#e0e0e0]">
                    <img :src="iconUser" alt="" class="size-5" aria-hidden="true" />
                  </span>
                  <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-[#1a1a1a]">
                      {{ q.author }}
                      <span class="font-normal text-[#666]">{{ q.at }}</span>
                    </p>
                    <!-- The disclosure rule the guidelines panel states. -->
                    <p class="text-2xs text-[#666] italic">
                      {{
                        q.author === t('procurement_detail.anonymous')
                          ? t('procurement_detail.user_information_is_private')
                          : t('procurement_detail.visible_to_the_agency_only')
                      }}
                    </p>
                  </div>
                </div>
                <div class="bg-white p-4">
                  <p class="text-xs whitespace-pre-line text-[#1a1a1a]">
                    {{ q.body }}
                  </p>
                </div>

                <div v-if="q.answered" class="border-t border-[#e0e0e0] bg-[#f0f4f1] p-4">
                  <p class="text-2xs font-semibold tracking-wide text-[#2e7d32] uppercase">
                    {{ t('procurement_detail.answered_on', { date: q.answeredAt }) }}
                  </p>
                  <p class="mt-1.5 text-xs whitespace-pre-line text-[#1a1a1a]">
                    {{ q.answer }}
                  </p>
                </div>

                <!-- Only the buyer answers. -->
                <div v-else-if="props.isOwner" class="border-t border-[#e0e0e0] bg-white p-4">
                  <button
                    v-if="answering !== q.id"
                    type="button"
                    class="text-xs font-semibold text-[#007035] underline"
                    @click="answering = q.id"
                  >
                    {{ t('procurement_detail.answer_this_question') }}
                  </button>
                  <form v-else class="flex flex-col gap-2" @submit.prevent="submitAnswer(q)">
                    <textarea
                      v-model="answer.answer"
                      rows="4"
                      class="resize-y rounded-md border border-[#e2e8f0] px-3 py-2 text-sm focus:border-focus focus:outline-none"
                    />
                    <div class="flex items-center gap-2">
                      <button
                        type="submit"
                        :disabled="!answer.answer.trim() || answer.processing"
                        class="rounded bg-[#007035] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                      >
                        {{ answer.processing ? t('procurement_detail.publishing') : t('procurement_detail.publish_answer') }}
                      </button>
                      <button
                        type="button"
                        class="text-xs font-semibold text-[#475569]"
                        @click="answering = null"
                      >
                        {{ t('procurement_detail.cancel') }}
                      </button>
                    </div>
                  </form>
                </div>

                <div v-else class="flex justify-center border-t border-[#e0e0e0] bg-[#f0f4f1] p-3">
                  <p class="text-xs font-semibold text-[#2e7d32] italic">
                    {{ t('procurement_detail.question_is_acknowledged') }}
                  </p>
                </div>
              </div>
            </article>
          </div>
        </div>
      </div>

      <!-- ============================== Table-Section 509:51920 ===========
           The table listed six invented vendors and a dead "View Details".
           It is the real bidders now, and the button opens what they
           submitted — visible to the buyer, and to a bidder for their own. -->
      <div v-else class="p-5 sm:p-10">
        <div class="overflow-x-auto rounded-lg border border-[#e2e8f0] bg-white">
          <table class="w-full min-w-[860px] border-collapse text-left">
            <thead>
              <tr
                class="border-b border-[#e2e8f0] bg-[#f1f5f9] text-xs font-semibold text-[#64748b]"
              >
                <th class="px-6 py-4 font-semibold">{{ t('procurement_detail.vendor_name') }}</th>
                <th class="px-6 py-4 font-semibold">{{ t('procurement_detail.primary_contact') }}</th>
                <th class="px-6 py-4 font-semibold">ADDRESS</th>
                <!--
                  Sized to its content, not to a number. At a fixed 120px the
                  cell's own px-6 leaves about 72px for a button that needs 113
                  — the label is whitespace-nowrap, so it does not wrap, it
                  spills past the right border and looks like lost padding.
                  `w-px` on a table cell is read as min-content, so the column
                  takes exactly the button plus its padding.
                -->
                <th class="w-px px-6 py-4 text-right font-semibold whitespace-nowrap">ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in props.interest" :key="f.id" class="border-b border-[#e2e8f0]">
                <td class="px-6 py-4">
                  <RouterLink
                    v-if="f.slug"
                    :href="`/business/${f.slug}`"
                    class="text-base font-semibold text-[#0f172a] hover:underline"
                  >
                    {{ f.vendor }}
                  </RouterLink>
                  <p v-else class="text-base font-semibold text-[#0f172a]">{{ f.vendor }}</p>
                  <p class="text-xs text-[#64748b]">
                    {{ f.status }}
                    <template v-if="f.submittedAt"> · {{ f.submittedAt }}</template>
                    <template v-if="f.awarded"> {{ t('procurement_detail.awarded_2') }}</template>
                  </p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-medium text-[#0f172a]">{{ f.contact ?? '—' }}</p>
                  <p class="text-xs text-[#64748b]">{{ f.email ?? t('procurement_detail.contact_withheld') }}</p>
                </td>
                <td class="px-6 py-4 text-xs text-[#475569]">{{ f.address ?? '—' }}</td>
                <td class="px-6 py-4">
                  <div class="flex justify-end">
                    <!--
                      A bid is private: only the buyer, and the bidder for their
                      own, can open what was submitted. Everyone else does not see
                      the button at all — they can look at the company instead.
                      shrink-0 guards the label from being squeezed over its border.
                    -->
                    <button
                      v-if="f.canView"
                      type="button"
                      class="inline-flex shrink-0 items-center gap-1.5 rounded border border-[#e2e8f0] px-3 py-2 text-xs font-semibold whitespace-nowrap text-[#475569] hover:bg-surface-secondary"
                      @click="openBidId = openBidId === f.id ? null : f.id"
                    >
                      <img :src="iconMail" alt="" class="size-3.5" aria-hidden="true" />
                      {{ openBidId === f.id ? t('procurement_detail.hide') : t('procurement_list.view_details') }}
                    </button>
                    <RouterLink
                      v-else-if="f.slug"
                      :href="`/business/${f.slug}`"
                      class="inline-flex shrink-0 items-center gap-1.5 rounded border border-[#e2e8f0] px-3 py-2 text-xs font-semibold whitespace-nowrap text-[#475569] hover:bg-surface-secondary"
                    >
                      {{ t('procurement_detail.view_company') }}
                    </RouterLink>
                    <span v-else class="text-xs text-[#94a3b8]">—</span>
                  </div>
                </td>
              </tr>

              <tr v-if="!props.interest.length">
                <td colspan="4" class="px-6 py-8 sm:py-12 text-center text-xs text-[#64748b]">
                  {{ t('procurement_detail.no_bids_have_been_submitted_yet') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- The submitted bid, as the wizard captured it. -->
        <section
          v-if="openBid && openBid.canView"
          class="mt-6 flex flex-col gap-5 rounded-lg border border-[#e2e8f0] bg-white p-6"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-[#1a1a1a]">
              {{ t('procurement_detail.vendor_submitted_bid', { vendor: openBid.vendor }) }}
            </h3>
            <div class="flex flex-wrap gap-3">
              <a
                v-for="(att, ai) in openBid.attachments"
                :key="ai"
                :href="att.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#007035] underline"
              >
                <img :src="iconDownload" alt="" class="size-3.5" aria-hidden="true" />
                {{ att.name }}
              </a>
            </div>
          </div>

          <div v-for="section in sections(openBid.details)" :key="section.label">
            <p class="mb-2 text-2xs font-semibold tracking-wide text-[#64748b] uppercase">
              {{ section.label }}
            </p>
            <dl class="flex flex-col">
              <div
                v-for="row in section.rows"
                :key="row.label"
                class="flex flex-col gap-1 border-b border-[#f1f5f9] py-2 sm:flex-row sm:gap-6"
              >
                <dt class="shrink-0 text-xs text-[#64748b] sm:w-[220px]">{{ row.label }}</dt>
                <dd class="min-w-0 flex-1 text-xs font-medium text-[#0f172a]">
                  {{ row.value }}
                </dd>
              </div>
            </dl>
          </div>

          <!--
            Bids submitted before the wizard stored its answers as fields have
            only the composed document. It is still the whole bid, so it is
            shown rather than hidden.
          -->
          <div v-if="!sections(openBid.details).length && openBid.proposal">
            <p class="mb-2 text-2xs font-semibold tracking-wide text-[#64748b] uppercase">
              {{ t('procurement_detail.proposal') }}
            </p>
            <p class="text-xs leading-5 whitespace-pre-line text-[#475569]">
              {{ openBid.proposal }}
            </p>
          </div>
        </section>
      </div>
    </div>
  </div>

  <!-- The winner's testimonial. Rating, headline, words, and their consent to
       show it publicly — declining keeps it on record but off the pages. -->
  <div
    v-if="testimonialOpen"
    class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="testimonial-title"
    @click.self="testimonialOpen = false"
  >
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-[0px_24px_48px_rgba(15,23,42,0.24)]">
      <h2 id="testimonial-title" class="font-display text-lg font-semibold text-[#0f172a]">
        {{ t('awarded.share_your_experience') }}
      </h2>
      <p class="mt-1 text-xs text-[#475569]">{{ t('awarded.modal_sub') }}</p>

      <form class="mt-5 flex flex-col gap-4" @submit.prevent="submitTestimonial">
        <div class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('awarded.rating') }}</span>
          <div class="flex items-center gap-1">
            <button
              v-for="n in 5"
              :key="n"
              type="button"
              class="transition-transform hover:scale-110"
              :aria-label="`${n} / 5`"
              @click="testimonialForm.rating = n"
            >
              <svg
                viewBox="0 0 24 24"
                class="size-7"
                :class="n <= testimonialForm.rating ? 'text-[#f59e0b]' : 'text-[#e2e8f0]'"
                fill="currentColor"
                aria-hidden="true"
              >
                <path d="M12 2l2.9 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l7.1-1.01z" />
              </svg>
            </button>
          </div>
        </div>

        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('awarded.headline') }}</span>
          <input
            v-model="testimonialForm.headline"
            type="text"
            maxlength="120"
            :placeholder="t('awarded.headline_placeholder')"
            class="rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          />
          <span v-if="testimonialForm.errors.headline" class="text-xs text-danger">{{ testimonialForm.errors.headline }}</span>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('awarded.your_testimonial') }}</span>
          <textarea
            v-model="testimonialForm.body"
            rows="4"
            maxlength="2000"
            :placeholder="t('awarded.testimonial_placeholder')"
            class="resize-y rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          />
          <span v-if="testimonialForm.errors.body" class="text-xs text-danger">{{ testimonialForm.errors.body }}</span>
        </label>

        <label class="flex items-start gap-2.5 rounded-lg bg-[#f8fafc] p-3">
          <input
            v-model="testimonialForm.display_publicly"
            type="checkbox"
            class="mt-0.5 size-4 shrink-0 rounded border-[#cbd5e1] text-[#007035] focus:ring-focus"
          />
          <span class="text-xs text-[#334155]">{{ t('awarded.consent') }}</span>
        </label>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
            @click="testimonialOpen = false"
          >
            {{ t('procurement_detail.cancel') }}
          </button>
          <button
            type="submit"
            :disabled="!testimonialForm.headline.trim() || !testimonialForm.body.trim() || testimonialForm.processing"
            class="rounded-lg bg-[#007035] px-5 py-2.5 text-xs font-semibold text-white disabled:opacity-60"
          >
            {{ t('awarded.submit_testimonial') }}
          </button>
        </div>
      </form>
    </div>
  </div>

</template>
