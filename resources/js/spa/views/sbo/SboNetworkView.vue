<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link as RouterLink, router, useForm } from '@inertiajs/vue3'
import { usePrivateChannel } from '@/composables/useEcho'
import { csrfHeaders } from '@/composables/useCsrf'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import EntityLogoRing from '@/components/ui/EntityLogoRing.vue'
import iconPlus from '@/assets/figma/icons/sbo-plus.svg'
import netCross from '@/assets/figma/icons/net-cross.svg'

import tabUser from '@/assets/figma/network/tab-user.svg'
import tabUserPlus from '@/assets/figma/network/tab-user-plus.svg'
import tabEnvelope from '@/assets/figma/network/tab-envelope.svg'

import filterSearch from '@/assets/figma/network/filter-search.svg'
import filterChevron from '@/assets/figma/network/filter-chevron.svg'
import filterArrow from '@/assets/figma/network/filter-arrow.svg'

import verifiedCheck from '@/assets/figma/network/verified-check.svg'
import cardConstruction from '@/assets/figma/network/card-construction.jpg'
import cardAgriculture from '@/assets/figma/network/card-agriculture.jpg'
import cardTechnology from '@/assets/figma/network/card-technology.jpg'
import logoBuildright from '@/assets/figma/network/logo-buildright.svg'
import logoAgriplus from '@/assets/figma/network/logo-agriplus.svg'
import logoTechnova from '@/assets/figma/network/logo-technova.svg'
import flagNg from '@/assets/figma/network/flag-ng.png'
import flagGh from '@/assets/figma/network/flag-gh.png'
import flagKe from '@/assets/figma/network/flag-ke.png'
import flagGb from '@/assets/figma/network/flag-gb.png'
import flagUs from '@/assets/figma/network/flag-us.png'
import flagAe from '@/assets/figma/network/flag-ae.png'

import msgCompose from '@/assets/figma/network/msg-compose.svg'
import msgSearch from '@/assets/figma/network/msg-search.svg'
import msgStarOff from '@/assets/figma/network/msg-star-off.svg'
import msgMenu from '@/assets/figma/network/msg-menu.svg'
import msgUsers from '@/assets/figma/network/msg-users.svg'
import msgSend from '@/assets/figma/network/msg-send.svg'
import tileBuildright from '@/assets/figma/network/tile-buildright-48.svg'
import tileLogo24 from '@/assets/figma/network/msg-tile-logo24.svg'
import stackUser from '@/assets/figma/network/avatar-stack-user.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

defineOptions({ layout: AppLayout })

/*
 * SBO Network — three Figma frames behind one route:
 *   connections 262:11751 · requests 387:20420 · messages 387:20991
 *
 * Connections and requests are the same screen; only the card action label
 * changes (Message -> Accept). Messages replaces the whole content area with
 * a two-pane inbox (387:21075), and drops the filter band entirely.
 *
 *   hero-section 262:11785 (261)           px-80, VIDEO fill
 *   active-user-profile-bar 262:11802 (88) px-80, three icon tabs
 *   Frames 387:21565 (70)                  full-bleed filter band, inset 53
 *   main-content-layout 262:11822 (876)    card grid, ~39px gutter
 *
 * Everything here reads the sbo_connections / sbo_conversations / sbo_messages
 * tables. The card photography, logo tiles and flag stacks stay presentational
 * and cycle by position — `sbos` carries none of them. See INTEGRATION.md.
 */

type Card = {
  id: string
  sboId: number
  name: string
  slug: string
  meta: string
  tags: string[]
  /** 'SBO' | 'COB' — colours the animated logo ring on the card. */
  entityType?: string | null
}

type Conversation = {
  id: string
  name: string
  org: string | null
  slug: string | null
  preview: string
  time: string | null
  unread: number
  category: 'connections' | 'groups'
  /** Real roster size — the "+N" on the stack (509:52716). */
  participants: number
  /** Who else is in the room, for the row menu. Groups only. */
  members?: { sboId: number; name: string; isAdmin: boolean }[]
  /** A room rather than a one-to-one: a category room or a member's group. */
  group: boolean
  /** Which kind of thread, so a member's own group can be told from a room. */
  kind: 'direct' | 'industry' | 'group'
  /** True when the viewer created this group and manages its roster. */
  isOwner: boolean
  /** Pinned above the rest; nobody opened it deliberately. */
  pinned: boolean
  /** Up to three members' photos for the stack. */
  memberPhotos: string[]
  /** This viewer's own bookmark; the header star. */
  starred: boolean
  active: boolean
}

/** A registry search hit from the inbox search box. */
type Person = {
  sboId: number
  name: string
  slug: string
  meta: string
  verified: boolean
  connection: 'self' | 'none' | 'pending' | 'accepted'
  /** Set when a thread with them already exists, so the row opens it. */
  conversationId: string | null
}

type Message = {
  id: string
  body: string
  time: string | null
  author: string | null
  mine: boolean
  /**
   * True only when the message is someone else's AND is in a language the
   * reader is not already reading. A thread already in your language should
   * not carry a "See translation" link on every bubble.
   */
  translatable: boolean
  detectedLocale: string | null
  /** Files sent with the message; `url` is a gated route, not a disk path. */
  attachments: Attachment[]
  /** The message this one answers, flattened to what a quote needs. */
  replyTo: { id: string; author: string | null; body: string } | null
  deleted: boolean
  pinned: boolean
  reactions: { emoji: string; count: number; mine: boolean }[]
}

type Attachment = {
  id: string
  kind: 'image' | 'video' | 'document'
  name: string
  size: number
  url: string
}

type Translation = { body: string; sourceLocale: string | null; sourceName: string | null }

const { t, tc, locale } = useI18n()
const { entityTerm } = useEntityTerm()

const props = defineProps<{
  tab: string
  counts: { connections: number; requests: number; unread: number }
  connections: Card[]
  requests: Card[]
  mySbo: { id: number; name: string } | null
  userId: string
  conversations: Conversation[]
  activeConversationId: string | null
  /**
   * Whether the reader asked for the open conversation, rather than it being
   * the newest one opened for them. Only the phone layout cares: it shows one
   * pane at a time, so an auto-selected thread would mean the inbox never
   * appeared.
   */
  conversationRequested: boolean
  thread: Message[]
  /**
   * The other businesses in the open conversation. Sent only for the one
   * actually open — the roster of every room would be a query per row to draw
   * a menu nobody has clicked.
   */
  roster: { sboId: number; name: string; slug: string | null; isAdmin: boolean }[]
  /** What the Group Info panel shows; null when no conversation is open. */
  groupInfo: {
    name: string
    group: boolean
    /** 'group' is a member-created group the viewer may be able to manage. */
    kind: 'direct' | 'industry' | 'group'
    /** True when the viewer created this group; they manage its roster. */
    isOwner: boolean
    members: number
    muted: boolean
    media: { id: string; kind: string; name: string; url: string }[]
    files: { id: string; name: string; size: number; url: string }[]
    links: { url: string; author: string | null }[]
  } | null
  composeTargets: { sboId: number; name: string }[]
  /** Verified businesses only may report a message. */
  canReport: boolean
  /** False until a translation provider is configured; see config/translation.php. */
  canTranslate: boolean
  reportReasons: { value: string; label: string }[]
  /** The inbox search term, echoed back so the box survives the round trip. */
  q: string
  /** Registry matches for `q` — people you may not have talked to yet. */
  people: Person[]
  categories: string[]
  countries: string[]
}>()

const tab = computed(() => props.tab || 'connections')

// active-user-profile-bar 262:11802 — icon, title, sub-count.
// Counts were fixed at 36 / 16 / 5 for everyone; they come from the tables now.
const tabs = computed(() => [
  {
    key: 'connections',
    title: t('sbo_network.my_connection'),
    sub: tc('sbo_network.count_businesses', props.counts.connections),
    icon: tabUser,
  },
  {
    key: 'requests',
    title: t('sbo_network.connection_request'),
    sub: tc('sbo_network.count_requests', props.counts.requests),
    icon: tabUserPlus,
  },
  {
    key: 'messages',
    title: t('sbo_network.message'),
    sub: tc('sbo_network.count_unread', props.counts.unread),
    icon: tabEnvelope,
  },
])

/* Cards 387:19768 — 221x324. The logo tile hangs over the photo's bottom
 * edge; tile colour and glyph are per-company. */
const art = [
  { photo: cardConstruction, logo: logoBuildright, tile: '#001529', flags: [flagNg, flagGh, flagKe] },
  { photo: cardAgriculture, logo: logoAgriplus, tile: '#15803d', flags: [flagGb, flagUs, flagAe] },
  { photo: cardTechnology, logo: logoTechnova, tile: '#7e22ce', flags: [flagGb, flagUs, flagAe] },
]

const cards = computed(() => {
  const rows = tab.value === 'requests' ? props.requests : props.connections
  return rows.map((r, i) => ({ ...art[i % art.length], ...r }))
})

const selects = [t('directory.all_categories'), t('directory.all_countries'), t('directory.all_industries')]

/* --------------------------------------------- connections / requests --- */

const connectionForm = useForm<{ status: 'accepted' | 'declined' }>({ status: 'accepted' })

/** Accept / Decline on a request card. Recipient-only, which is why the
 *  connections tab must not use it — see `disconnect` below. */
function respond(card: Card, status: 'accepted' | 'declined') {
  connectionForm.status = status
  connectionForm.patch(`/sbo/connections/${card.id}`, { preserveScroll: true })
}

/*
 * Remove on a connection card.
 *
 * This used to PATCH `declined` like the requests tab, which only the original
 * *recipient* may do — so whoever sent the request got a flat 403 when they
 * tried to remove the connection. It is its own verb now, open to either side,
 * and the two can connect again afterwards.
 */
const disconnectForm = useForm({})

function disconnect(card: Card) {
  if (!window.confirm(t('business_profile.disconnect_confirm', { name: card.name }))) return

  disconnectForm.delete(`/sbo/connections/${card.id}`, { preserveScroll: true })
}

/** "Message" on a connection card — opens (or reuses) its conversation. */
const composeForm = useForm<{ sbo_id: number | null; recipient_sbo_id: number | null; body: string }>({
  sbo_id: props.mySbo?.id ?? null,
  recipient_sbo_id: null,
  body: '',
})

function messageCard(card: Card) {
  if (!props.mySbo) return
  composeForm.sbo_id = props.mySbo.id
  composeForm.recipient_sbo_id = card.sboId
  composeForm.body = ''
  composeForm.post('/sbo/conversations')
}

/* ------------------------------------------------- messages 387:21075 --- */

/* ----------------------------------------------------- translation --- */

/*
 * "See translation" on a message.
 *
 * The control only appears when a provider is configured (`canTranslate`) and
 * the message is not already in the reader's language. Fetched over JSON rather
 * than an Inertia visit so the bubble expands in place — a re-render would
 * throw away the reader's scroll position mid-conversation.
 *
 * Translations are cached server-side per (message, language), so a second
 * reader in the same language costs nothing.
 */
const translations = ref<Record<string, Translation>>({})
const translationShown = ref<Record<string, boolean>>({})
const translating = ref<string | null>(null)
const translationError = ref<Record<string, string>>({})

async function toggleTranslation(messageId: string) {
  // Already fetched: this is just a show/hide.
  if (translations.value[messageId]) {
    translationShown.value[messageId] = !translationShown.value[messageId]
    return
  }

  translating.value = messageId
  delete translationError.value[messageId]

  try {
    const response = await fetch(`/sbo/messages/${messageId}/translate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...csrfHeaders(),
      },
      body: JSON.stringify({ locale: locale.value }),
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      translationError.value[messageId] = payload.message ?? t('chat.translation_failed')
      return
    }

    translations.value[messageId] = (await response.json()) as Translation
    translationShown.value[messageId] = true
  } catch {
    translationError.value[messageId] = t('chat.translation_failed')
  } finally {
    translating.value = null
  }
}


/*
 * chips 387:21890. "Unread" reads SboMessage.read_at (the per-conversation
 * unread count); "Connections" and "Groups" read SboConversation.type. There
 * is no archived flag on the table, so that chip is drawn and disabled rather
 * than silently doing nothing — see INTEGRATION.md.
 */
const chips = [
  { key: 'all', label: t('sbo_network.all') },
  { key: 'unread', label: t('sbo_network.unread') },
  { key: 'connections', label: t('sbo_network.connections') },
  { key: 'groups', label: t('sbo_network.groups') },
  // Real, now the header's star writes somewhere.
  { key: 'starred', label: t('sbo_network.starred') },
  // Still nothing behind it: there is no archived state on a conversation.
  { key: 'archived', label: t('sbo_network.archived'), disabled: true },
] as const

const chip = ref<'all' | 'unread' | 'connections' | 'groups' | 'starred' | 'archived'>('all')
const inboxSearch = ref(props.q)

watch(
  () => props.q,
  (v) => {
    if (v !== inboxSearch.value) inboxSearch.value = v
  },
)

/*
 * The box does two jobs at once: it filters the threads already open (locally,
 * instantly) and searches the registry for people you have not talked to yet
 * (on the server, debounced). Searching only the loaded conversations meant
 * you could only find someone you had already messaged, which is the opposite
 * of what a search in an inbox is for.
 *
 * A partial reload rather than a visit: only `people` and `q` change, so the
 * thread on the right and the scroll position stay put.
 */
let searchTimer: ReturnType<typeof setTimeout> | undefined

function onInboxSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    router.get(
      '/sbo/network/messages',
      {
        q: inboxSearch.value.trim(),
        ...(props.activeConversationId ? { conversation: props.activeConversationId } : {}),
      },
      { only: ['people', 'q'], preserveState: true, preserveScroll: true, replace: true },
    )
  }, 300)
}

onBeforeUnmount(() => clearTimeout(searchTimer))

const conversations = computed(() =>
  props.conversations.filter((c) => {
    if (chip.value === 'unread' && c.unread === 0) return false
    if (chip.value === 'connections' && c.category !== 'connections') return false
    if (chip.value === 'groups' && c.category !== 'groups') return false
    if (chip.value === 'starred' && !c.starred) return false

    const term = inboxSearch.value.trim().toLowerCase()
    if (!term) return true
    return `${c.name} ${c.org ?? ''} ${c.preview}`.toLowerCase().includes(term)
  }),
)

/* People already in the thread list are not repeated as search results. */
const openSlugs = computed(() => new Set(props.conversations.map((c) => c.slug).filter(Boolean)))
const people = computed(() => props.people.filter((p) => !openSlugs.value.has(p.slug)))

const searching = computed(() => inboxSearch.value.trim().length >= 2)

/** Connect to a search hit — the same endpoint the directory card posts to. */
const connectForm = useForm<{ requester_sbo_id: number | null; recipient_sbo_id: number | null }>({
  requester_sbo_id: null,
  recipient_sbo_id: null,
})

function connectTo(person: Person) {
  if (!props.mySbo) return
  connectForm.requester_sbo_id = props.mySbo.id
  connectForm.recipient_sbo_id = person.sboId
  connectForm.post('/sbo/connections', { preserveScroll: true })
}

function messagePerson(person: Person) {
  if (person.conversationId) {
    router.get(
      `/sbo/network/messages?conversation=${person.conversationId}`,
      {},
      { preserveState: true, preserveScroll: true },
    )
    return
  }

  if (!props.mySbo) return
  composeForm.sbo_id = props.mySbo.id
  composeForm.recipient_sbo_id = person.sboId
  composeForm.body = ''
  composeForm.post('/sbo/conversations')
}

const active = computed(
  () => props.conversations.find((c) => c.id === props.activeConversationId) ?? null,
)

/* The header menu reads both, and an industry room has no single profile to
   link to — its members are many businesses, not one. */
const activeIsGroup = computed(() => active.value?.group ?? false)
const activeMembers = computed(() => active.value?.participants ?? 0)

/*
 * A colour for a sender's name in a group, the way group chats everywhere give
 * each person a consistent hue: it is how you tell at a glance that three
 * messages came from the same business without reading the name each time.
 *
 * Derived from the name so it is stable — the same business is always the same
 * colour — and drawn from a fixed set chosen to stay legible on the white
 * bubble. Only used in groups; a one-to-one thread has no one to tell apart.
 */
const NAME_COLOURS = [
  '#0d9488', '#2563eb', '#b45309', '#7c3aed', '#be185d',
  '#0369a1', '#15803d', '#c2410c', '#4338ca', '#a21caf',
]

function nameColour(name: string | null | undefined): string {
  const key = name ?? ''
  let hash = 0

  for (let i = 0; i < key.length; i++) {
    hash = (hash * 31 + key.charCodeAt(i)) | 0
  }

  return NAME_COLOURS[Math.abs(hash) % NAME_COLOURS.length]
}

/*
 * The thread is server-rendered and then appended to locally as messages
 * arrive over the socket, so `thread` is a local copy that resets whenever the
 * server sends a new one (a different conversation, or a reload).
 */
const thread = ref<Message[]>([...props.thread])
watch(
  () => props.thread,
  (next) => (thread.value = [...next]),
)

/*
 * The thread scrolls inside its own pane now, so it has to open at the newest
 * message. Left alone it opens at the oldest, which on a long conversation
 * looks like nothing has been said for months.
 */
const threadEl = ref<HTMLElement | null>(null)

function scrollToLatest(smooth = false) {
  const el = threadEl.value
  if (!el) return

  el.scrollTo({
    top: el.scrollHeight,
    behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto',
  })
}

function prefersReducedMotion(): boolean {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
}

// `flush: 'post'` so the rows are in the DOM before the height is read.
watch(
  thread,
  (next, previous) => nextTick(() => scrollToLatest((previous?.length ?? 0) > 0 && next.length > (previous?.length ?? 0))),
  { flush: 'post', deep: false },
)

onMounted(() => scrollToLatest())

/* The header menu closes the way every other menu in the app does. */
function onDocClick(e: MouseEvent) {
  const el = e.target as HTMLElement
  if (!el.closest?.('[aria-haspopup="menu"], [role="menu"]')) menuOpen.value = false
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

// Opening a conversation dismisses the overlays that sit on top of the thread:
// the header menu and the Group Info panel. Without closing Group Info,
// "Message privately" from its roster left the panel covering the very thread
// it had just opened, so the member could not type until they closed it by hand.
watch(() => props.activeConversationId, () => {
  menuOpen.value = false
  infoOpen.value = false
})

/*
 * The three discs in the group stack: a member's photo where one exists, and
 * the frame's user glyph where it does not. Always three slots, so the shape
 * is the same for a room of four and a room of four hundred.
 */
function stackSlots(c: Conversation): (string | null)[] {
  return [0, 1, 2].map((i) => c.memberPhotos[i] ?? null)
}

function openConversation(conversation: Conversation) {
  router.get(
    `/sbo/network/messages?conversation=${conversation.id}`,
    {},
    { preserveState: true, preserveScroll: true },
  )
}

/* Live delivery. The channel follows whichever conversation is open. */
const channel = computed(() =>
  props.activeConversationId ? `sbo.conversation.${props.activeConversationId}` : null,
)

const live = usePrivateChannel(channel, {
  'message.sent': (payload: {
    id: string
    conversationId: string
    userId: string
    author: string | null
    body: string
    at: string | null
    attachments?: Attachment[]
    replyTo?: { id: string; author: string | null; body: string } | null
  }) => {
    if (payload.conversationId !== props.activeConversationId) return
    // The sender already has the message from its own redirect; without this
    // guard their own send would render twice.
    if (thread.value.some((m) => m.id === payload.id)) return

    thread.value.push({
      id: payload.id,
      body: payload.body,
      time: payload.at,
      author: payload.author,
      mine: payload.userId === props.userId,
      // Files arrive with the live message; see SboMessageSent::broadcastWith.
      attachments: payload.attachments ?? [],
      /*
       * A message that has just been sent has nothing on it yet — no
       * reactions, no pin, nobody has starred it — so these are the empty
       * state rather than something the broadcast has to carry.
       */
      replyTo: payload.replyTo ?? null,
      deleted: false,
      pinned: false,
      reactions: [],
      /*
       * A message arriving over the socket has not been through language
       * detection yet, so offer the control for anything that is not mine.
       * A spare link is a smaller cost than a missing one, and the next load
       * of the thread settles it.
       */
      translatable: payload.userId !== props.userId,
      detectedLocale: null,
    })
  },
})

/* chat-input-wrapper 387:22041 — the composer. */
const messageForm = useForm<{ body: string; attachments: File[]; reply_to_message_id: string | null }>({
  body: '',
  attachments: [],
  reply_to_message_id: null,
})

/*
 * Attachments.
 *
 * Chosen files are held until the message is sent rather than uploaded on
 * pick, so removing one before sending actually removes it — an upload that
 * has already happened can only be orphaned. Five is the server's limit too;
 * enforcing it here as well means the sixth is refused where the user can see
 * it rather than after the upload.
 */
const MAX_ATTACHMENTS = 5

/*
 * The composer grows with the message.
 *
 * A textarea does not resize itself, so at rows="1" a message longer than one
 * line scrolled inside a one-line box and appeared to run underneath itself.
 * On a phone, where the line is short, that is almost every message.
 *
 * Height is cleared before it is read: scrollHeight only shrinks back if the
 * element is allowed to be small first, otherwise deleting text leaves the box
 * at whatever height it reached. The cap is on the element, so a very long
 * message scrolls inside a composer that has stopped growing rather than
 * taking over the screen.
 */
const composer = ref<HTMLTextAreaElement | null>(null)

function growComposer() {
  const field = composer.value
  if (!field) return

  field.style.height = 'auto'
  field.style.height = `${field.scrollHeight}px`
}

/*
 * Which of the two panes a phone shows. Desktop shows both and ignores this.
 *
 * Requested, not merely active: the server opens the newest conversation when
 * none is named, which is right for a desktop pane that would otherwise be
 * empty and wrong for a phone, where it would mean the app opens inside
 * somebody's last chat instead of on the inbox.
 */
const showThreadOnPhone = computed(
  () => Boolean(props.activeConversationId) && props.conversationRequested,
)

/*
 * Writing a message on a phone happens in its own screen.
 *
 * Inline, the field is a strip between two buttons at the bottom edge, and the
 * on-screen keyboard then covers most of what is left — you end up composing a
 * paragraph through a slot. Tapping it opens a full screen instead: the draft
 * is the same `messageForm.body`, so nothing is carried across or lost.
 *
 * Which control is shown is CSS, not a width read in JavaScript — the strip
 * below sm is a button that opens this, and the real inline textarea only
 * exists from sm up. A JS breakpoint would disagree with the layout the moment
 * either changed.
 */
const composerOpen = ref(false)
const sheetComposer = ref<HTMLTextAreaElement | null>(null)

function openComposer() {
  composerOpen.value = true

  // Focused after it exists, or the keyboard opens against nothing.
  void nextTick(() => {
    sheetComposer.value?.focus()
    growSheet()
  })
}

/** The sheet's field grows too; see growComposer for why the reset is needed. */
function growSheet() {
  const field = sheetComposer.value
  if (!field) return

  field.style.height = 'auto'
  field.style.height = `${field.scrollHeight}px`
}

/*
 * Back to the list, on a phone. A real visit rather than a local flag: the
 * open conversation is in the URL, so the back button and a shared link keep
 * working, and the server stops sending a thread nobody is reading.
 *
 * The tab is a path segment, not a query parameter — `?tab=messages` is read by
 * nothing and leaves the route's own default in place, which is how this came
 * back to My Connections instead of the inbox it was pointed at.
 */
function backToInbox() {
  router.get('/sbo/network/messages', {}, { preserveState: false })
}

const fileInput = ref<HTMLInputElement | null>(null)

function pickFiles(event: Event) {
  const chosen = Array.from((event.target as HTMLInputElement).files ?? [])

  messageForm.attachments = [...messageForm.attachments, ...chosen].slice(0, MAX_ATTACHMENTS)

  // Cleared so choosing the same file twice in a row still fires a change.
  if (fileInput.value) fileInput.value.value = ''
}

function removeAttachment(index: number) {
  messageForm.attachments = messageForm.attachments.filter((_, i) => i !== index)
}

/** 24 KB, 1.4 MB — a byte count on a chat bubble tells nobody anything. */
function readableSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/*
 * Validation on an attachment comes back keyed by its position
 * (`attachments.0`), so the first such error is surfaced rather than any one
 * key being watched — which would show nothing when the second file is the
 * rejected one.
 */
const attachmentError = computed(() => {
  const key = Object.keys(messageForm.errors).find((k) => k.startsWith('attachments'))

  return key ? (messageForm.errors as Record<string, string>)[key] : null
})

const canSend = computed(
  () => messageForm.body.trim().length > 0 || messageForm.attachments.length > 0,
)

function send() {
  if (!props.activeConversationId || !canSend.value) return

  /*
   * forceFormData: a file cannot travel as JSON, and Inertia only switches to
   * multipart on its own when it sees one. Setting it explicitly keeps the
   * request shape the same whether or not anything is attached.
   */
  messageForm.reply_to_message_id = replyingTo.value?.id ?? null

  messageForm.post(`/sbo/conversations/${props.activeConversationId}/messages`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      messageForm.reset('body', 'attachments', 'reply_to_message_id')
      replyingTo.value = null

      // Sent from the full screen: that screen has done its job.
      composerOpen.value = false

      // The box goes back to one line rather than staying as tall as the
      // message that has just left it.
      void nextTick(growComposer)
    },
  })
}

/* ----------------------------------------------------------- group info ---
 *
 * Who is in the room, and what has been shared in it. A panel rather than a
 * page: it is read alongside the conversation, not instead of it.
 */
const infoOpen = ref(false)
type InfoTab = 'members' | 'media' | 'files' | 'links'
const infoTab = ref<InfoTab>('members')

const muteForm = useForm({})

function toggleMute() {
  if (!props.activeConversationId) return
  muteForm.post(`/sbo/conversations/${props.activeConversationId}/mute`, { preserveScroll: true })
}

/** Two letters from the name, which is what the disc shows when there is no logo. */
const infoInitials = computed(() => {
  const words = (props.groupInfo?.name ?? '').split(/\s+/).filter(Boolean)

  return words.slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')
})

/** Jump to the quoted message, which is the point of showing the quote. */
function scrollToMessage(id: string) {
  document
    .querySelector(`[data-message-id="${id}"]`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

/* ------------------------------------------------------------ a message ---
 *
 * Everything you can do to one that is not writing another. The menu opens on
 * hover (and on focus, so it is reachable from the keyboard) rather than
 * living permanently beside every bubble, which would put nine controls on
 * screen for every line of a conversation.
 */
const messageMenu = ref<string | null>(null)
const reactingTo = ref<string | null>(null)
const copied = ref<string | null>(null)

const REACTIONS = ['👍', '❤️', '😂', '😮', '🙏', '✅']

function toggleMessageMenu(id: string) {
  messageMenu.value = messageMenu.value === id ? null : id
  reactingTo.value = null
}

function closeMessageMenu() {
  messageMenu.value = null
  reactingTo.value = null
}

/*
 * Replying is composing, not a separate action: the target is held here and
 * sent with the next message, so the composer is the one place a message is
 * written from.
 */
const replyingTo = ref<Message | null>(null)

function startReply(m: Message) {
  closeMessageMenu()
  replyingTo.value = m
}

function cancelReply() {
  replyingTo.value = null
}

/*
 * Reply privately: the same private thread as the roster's control, and the
 * quote is not carried across. It belongs to the room the message was said in,
 * and republishing it into a thread its author did not choose to have it in
 * would be quoting them somewhere else.
 */
function replyPrivately(m: Message) {
  closeMessageMenu()

  const sboId = authorSboId(m)
  if (sboId === null || !props.activeConversationId) return

  messagePrivatelyFrom(props.activeConversationId, sboId)
}

/*
 * Taking someone aside needs a room to do it from and somebody else to do it
 * with. In a direct conversation you are already in the private thread, and
 * the author of your own message is you.
 */
function canReplyPrivately(m: Message): boolean {
  return Boolean(active.value?.group) && !m.mine && Boolean(authorSboId(m))
}

function privateReplyReason(m: Message): string | undefined {
  if (m.mine) return t('chat.cannot_reply_privately_own')
  if (!active.value?.group) return t('chat.already_a_private_thread')
  if (!authorSboId(m)) return t('chat.author_not_in_roster')

  return undefined
}

/** The roster entry for whoever wrote a message, matched on the name it carries. */
function authorSboId(m: Message): number | null {
  return props.roster.find((r) => r.name === m.author)?.sboId ?? null
}

const actionForm = useForm({})

/*
 * Translate, from the menu. The same toggle the bubble's "See translation"
 * link uses — one implementation, so the two controls cannot disagree about
 * whether a message is currently showing its translation.
 */
function translateFromMenu(m: Message) {
  closeMessageMenu()
  void toggleTranslation(m.id)
}

function react(m: Message, emoji: string) {
  closeMessageMenu()
  router.post(`/sbo/messages/${m.id}/react`, { emoji }, { preserveScroll: true })
}

function togglePin(m: Message) {
  closeMessageMenu()
  actionForm.post(`/sbo/messages/${m.id}/pin`, { preserveScroll: true })
}

function removeMessage(m: Message) {
  closeMessageMenu()
  actionForm.delete(`/sbo/messages/${m.id}`, { preserveScroll: true })
}

/*
 * Copy uses the async clipboard where it exists and a hidden textarea where it
 * does not — Safari refuses the former outside a secure context, which
 * includes plain-HTTP local development.
 */
async function copyMessage(m: Message) {
  closeMessageMenu()

  try {
    await navigator.clipboard.writeText(m.body)
  } catch {
    const field = document.createElement('textarea')
    field.value = m.body
    field.setAttribute('readonly', '')
    field.style.position = 'fixed'
    field.style.opacity = '0'
    document.body.appendChild(field)
    field.select()
    document.execCommand('copy')
    document.body.removeChild(field)
  }

  copied.value = m.id
  window.setTimeout(() => {
    if (copied.value === m.id) copied.value = null
  }, 1500)
}

/*
 * A private word with someone in a group.
 *
 * One endpoint covers member-to-admin, admin-to-member and member-to-member:
 * sharing the room is what authorises it, so the screen does not need to know
 * who is senior. Lands in the direct thread, opening it if it is new.
 */
const privateForm = useForm<{ recipient_sbo_id: number | null }>({ recipient_sbo_id: null })

/*
 * The conversation id is the room the two people share, which is what
 * authorises the private thread — so this works from the list without the room
 * having to be open first.
 */
function messagePrivatelyFrom(conversationId: string, recipientSboId: number) {
  rowMenu.value = null
  privateForm.recipient_sbo_id = recipientSboId
  privateForm.post(`/sbo/conversations/${conversationId}/private`, {
    preserveState: false,
    preserveScroll: false,
    onSuccess: () => {
      infoOpen.value = false
      menuOpen.value = false
    },
  })
}

/*
 * The registry itself. One thread per member wherever it is started from, so
 * this reopens the existing conversation rather than making another.
 */
const adminForm = useForm({})

function messageAdmin() {
  // The dedicated endpoint does not depend on a group being the active
  // conversation, so this works from the roster in Group Info as well.
  //
  // preserveState:false forces a clean remount on the redirect target rather
  // than reusing this component with the group still in its reactive state —
  // reusing it was landing the reader back on the group instead of the admin
  // thread. Overlays are closed on success, once the new page is in.
  rowMenu.value = null
  adminForm.post('/sbo/conversations/admin', {
    preserveState: false,
    preserveScroll: false,
    onSuccess: () => {
      infoOpen.value = false
      menuOpen.value = false
    },
  })
}

/** Which row's menu is open; only ever one. */
const rowMenu = ref<string | null>(null)

// A click anywhere else closes it, which is what a menu is expected to do.
function closeRowMenu() {
  rowMenu.value = null
}

function closeAllMenus() {
  closeRowMenu()
  closeMessageMenu()
}

onMounted(() => document.addEventListener('click', closeAllMenus))
onBeforeUnmount(() => document.removeEventListener('click', closeAllMenus))

/* Compose — the pencil button on the inbox header. */
const composeOpen = ref(false)
const composeTarget = ref<number | null>(null)

function startConversation() {
  if (!props.mySbo || composeTarget.value === null) return
  composeForm.sbo_id = props.mySbo.id
  composeForm.recipient_sbo_id = composeTarget.value
  composeForm.post('/sbo/conversations', {
    onSuccess: () => {
      composeOpen.value = false
      composeTarget.value = null
    },
  })
}

/* ------------------------------------------------- member-created groups --- */

/* Create a group: a name and the connections to seed it with. */
const groupOpen = ref(false)
const groupForm = useForm<{ name: string; member_sbo_ids: number[] }>({
  name: '',
  member_sbo_ids: [],
})

function toggleGroupSeed(sboId: number) {
  const at = groupForm.member_sbo_ids.indexOf(sboId)
  if (at === -1) groupForm.member_sbo_ids.push(sboId)
  else groupForm.member_sbo_ids.splice(at, 1)
}

function createGroup() {
  if (!groupForm.name.trim() || !groupForm.member_sbo_ids.length) return
  groupForm.post('/sbo/groups', {
    preserveScroll: true,
    onSuccess: () => {
      groupOpen.value = false
      groupForm.reset()
    },
  })
}

/*
 * Managing a group from its Group Info panel. Only the owner sees these, and
 * only their connections not already in the room can be added.
 */
const addMembersOpen = ref(false)
const addMembersForm = useForm<{ member_sbo_ids: number[] }>({ member_sbo_ids: [] })

const addableConnections = computed(() => {
  const inRoom = new Set(props.roster.map((r) => r.sboId))
  return props.connections
    .filter((c) => !inRoom.has(c.sboId))
    .map((c) => ({ sboId: c.sboId, name: c.name }))
})

function toggleAddMember(sboId: number) {
  const at = addMembersForm.member_sbo_ids.indexOf(sboId)
  if (at === -1) addMembersForm.member_sbo_ids.push(sboId)
  else addMembersForm.member_sbo_ids.splice(at, 1)
}

function addGroupMembers() {
  if (!props.activeConversationId || !addMembersForm.member_sbo_ids.length) return
  addMembersForm.post(`/sbo/groups/${props.activeConversationId}/members`, {
    preserveScroll: true,
    onSuccess: () => {
      addMembersOpen.value = false
      addMembersForm.reset()
    },
  })
}

const removeMemberForm = useForm<{ member_sbo_id: number | null }>({ member_sbo_id: null })

function removeGroupMember(sboId: number) {
  if (!props.activeConversationId) return
  removeMemberForm.member_sbo_id = sboId
  removeMemberForm.delete(`/sbo/groups/${props.activeConversationId}/members`, {
    preserveScroll: true,
  })
}

const leaveGroupForm = useForm({})

function leaveGroup() {
  if (!props.activeConversationId) return
  leaveGroupForm.delete(`/sbo/groups/${props.activeConversationId}/leave`)
}

/*
 * The chat header's three icons, all of which were disabled buttons.
 *
 * The star is a real per-viewer bookmark — the Starred chip above the
 * conversation list filters to them, and a starred row shows the mark.
 */
const starForm = useForm({})

function toggleStar() {
  if (!active.value) return

  const url = `/sbo/conversations/${active.value.id}/star`

  if (active.value.starred) starForm.delete(url, { preserveScroll: true })
  else starForm.post(url, { preserveScroll: true })
}

/* --------------------------------------------------------- report a message ---
 *
 * `sbo_network_reports.message_id` has existed all along with nothing writing
 * to it — the admin queue already renders the reported message when there is
 * one. Verified businesses only, matching the bar for reporting a listing.
 */
const reportingId = ref<string | null>(null)
const reportForm = useForm({ reason: '', details: '' })

function openReport(messageId: string) {
  reportingId.value = messageId
  reportForm.reset()
  reportForm.clearErrors()
}

function submitReport() {
  if (!reportingId.value) return

  reportForm.post(`/sbo/messages/${reportingId.value}/report`, {
    preserveScroll: true,
    onSuccess: () => {
      reportForm.reset()
      reportingId.value = null
    },
  })
}

/*
 * Links in a message.
 *
 * The chat header carried a "Share a meeting link" action whose only effect was
 * to post the URL as a message — the same thing typing it does — and it failed
 * silently whenever the pasted link had no scheme, because the endpoint
 * validates `url` and this form rendered no errors. The control is gone. What
 * it was really for is this: a link somebody pastes has to be clickable.
 *
 * Escaped first, then linkified, so a message body can never inject markup.
 * `rel="noopener nofollow"` because the sender is another member, not us.
 */
const URL_PATTERN = /\b((?:https?:\/\/|www\.)[^\s<>"']+)/gi

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function linkify(body: string): string {
  return escapeHtml(body).replace(URL_PATTERN, (match) => {
    // Trailing punctuation belongs to the sentence, not the URL.
    const trimmed = match.replace(/[.,;:!?)]+$/, '')
    const tail = match.slice(trimmed.length)
    const href = trimmed.startsWith('http') ? trimmed : `https://${trimmed}`

    return `<a href="${href}" target="_blank" rel="noopener nofollow" class="underline">${trimmed}</a>${tail}`
  })
}

const menuOpen = ref(false)

function closeMenu() {
  menuOpen.value = false
}

const markReadForm = useForm({})

function markRead() {
  if (!active.value) return
  closeMenu()
  markReadForm.post(`/sbo/conversations/${active.value.id}/read`, { preserveScroll: true })
}
</script>

<template>
  <!-- ======================================= hero-section 262:11785 ======= -->
  <!--
    The hero, and where it is not wanted.

    On a phone the messages tab is a full-height application screen — a list
    that fills the viewport, or a conversation with its composer pinned to the
    bottom edge. A 300px marketing banner above that costs most of the screen
    the conversation needs, and is scrolled past on the way to every message.

    Kept everywhere else: on the other tabs it is the page's own heading, and
    on desktop there is room for it.
  -->
  <section
    class="relative overflow-hidden bg-[#0b192c] section-x py-9 sm:py-14"
    :class="tab === 'messages' ? 'hidden lg:block' : ''"
  >
    <HeroVideo overlay="bg-[#0b192c]/80" />

    <div class="relative flex flex-col gap-6">
      <!-- breadcrumbs 262:11786 -->
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('sbo_network.breadcrumb')">
        <RouterLink href="/sbo/dashboard" class="text-[#94a3b8] hover:text-white">
          {{ t('sbo_network.sbo_workspace', { entity: entityTerm }) }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('sbo_network.gt') }}</span>
        <span class="text-white">{{ t('sbo_network.sbo_network', { entity: entityTerm }) }}</span>
      </nav>

      <!-- hero-split 262:11790 -->
      <div class="flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex max-w-[760px] flex-col gap-3">
          <h1 class="font-serif text-4xl font-bold text-white">{{ t('sbo_network.sbo_network', { entity: entityTerm }) }}</h1>
          <p class="text-base leading-normal text-[#94a3b8]">
            {{ t('sbo_network.connect_with_fully_verified_companies_mana') }}
          </p>
        </div>
        <RouterLink
          href="/procurement/new"
          class="btn-primary inline-flex shrink-0 items-center gap-2 rounded-lg px-7 py-3.5"
        >
          <img :src="iconPlus" alt="" class="size-4 brightness-0 invert" aria-hidden="true" />
          <span class="font-display text-base font-semibold whitespace-nowrap">{{ t('sbo_network.post_rfq') }}</span>
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ============================ active-user-profile-bar 262:11802 =======
       Three tabs spread edge to edge; the active one carries an 8px #1b8a4e
       bar flush with the bottom of the bar. -->
  <div class="border border-[#e2e8f0] bg-white section-x py-5">
    <div class="flex flex-wrap items-center justify-between gap-x-8 gap-y-4">
      <RouterLink
        v-for="t in tabs"
        :key="t.key"
        :href="`/sbo/network/${t.key}`"
        class="relative flex min-w-0 items-center gap-5"
      >
        <span class="grid size-12 shrink-0 place-items-center rounded-3xl">
          <img :src="t.icon" alt="" class="size-6" aria-hidden="true" />
        </span>
        <span class="flex flex-col gap-1">
          <span
            class="font-display text-lg font-semibold lg:whitespace-nowrap"
            :class="tab === t.key ? 'text-[#0f172a]' : 'text-[#0f172a]/60'"
          >
            {{ t.title }}
          </span>
          <span
            class="text-xs lg:whitespace-nowrap"
            :class="tab === t.key ? 'text-[#475569]' : 'text-[#475569]/60'"
          >
            {{ t.sub }}
          </span>
        </span>
        <span
          v-if="tab === t.key"
          class="absolute -bottom-[21px] -left-px -right-[34px] hidden h-2 bg-[#1b8a4e] lg:block"
          aria-hidden="true"
        />
      </RouterLink>
    </div>
  </div>

  <!-- =============================================== connections / requests -->
  <template v-if="tab !== 'messages'">
    <!-- filter band 387:21565 — full-bleed white card, content inset 53px -->
    <div class="rounded-xl border border-[#f3f4f6] bg-white">
      <form
        class="flex flex-wrap items-center gap-4 px-5 py-4 md:px-10 xl:px-[53px]"
        role="search"
        @submit.prevent
      >
        <label class="relative h-[38px] w-full flex-1 sm:min-w-[240px]">
          <span class="sr-only">{{ t('sbo_network.search_businesses_products_or_services') }}</span>
          <img
            :src="filterSearch"
            alt=""
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
            aria-hidden="true"
          />
          <input
            type="search"
            :placeholder="t('sbo_network.search_businesses_products_or_services')"
            class="size-full rounded-md border border-[#e5e7eb] bg-[#f9fafb] pr-3 pl-9 text-2xs font-medium text-[#1f2937] placeholder:text-[#1f2937]/40 focus:border-focus focus:outline-none"
          />
        </label>

        <div v-for="s in selects" :key="s" class="relative h-[37px] w-full sm:w-[124px] sm:shrink-0">
          <select
            class="size-full appearance-none rounded-md border border-[#e5e7eb] bg-[#f9fafb] pr-7 pl-2.5 text-2xs font-medium text-[#1f2937] focus:border-focus focus:outline-none"
            :aria-label="s"
          >
            <option>{{ s }}</option>
          </select>
          <img
            :src="filterChevron"
            alt=""
            class="pointer-events-none absolute top-1/2 right-2.5 h-2.5 w-1.5 -translate-y-1/2 rotate-90"
            aria-hidden="true"
          />
        </div>

        <button
          type="submit"
          class="inline-flex h-9 w-full shrink-0 items-center justify-center gap-2 rounded-md bg-[#001529] text-xs font-medium text-white hover:opacity-90 sm:w-[146px]"
        >
          {{ t('sbo_network.search') }}
          <img :src="filterArrow" alt="" class="h-3 w-2.5" aria-hidden="true" />
        </button>
      </form>
    </div>

    <!-- main-content-layout 262:11822 / messages-card 262:11886 -->
    <div class="bg-surface-secondary px-5 pt-3 pb-20 md:px-8 xl:px-10">
      <section class="rounded-xl border border-[#e2e8f0] bg-white p-6 pl-[21px]">
        <ul
          v-if="cards.length"
          class="grid grid-cols-1 gap-x-[15px] gap-y-9 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 [&>*]:min-w-0"
        >
          <li v-for="c in cards" :key="c.id" class="min-w-0">
            <article
              class="flex h-full flex-col overflow-hidden rounded-xl border border-[#e5e7eb] bg-white"
            >
              <!-- photo 387:19788 -->
              <div class="relative">
                <img :src="c.photo" :alt="c.name" class="h-44 w-full object-cover" />

                <!-- VERIFIED 387:19790 -->
                <span
                  class="absolute top-2 left-2 inline-flex h-[19px] items-center gap-1.5 rounded bg-[#22c55e] px-2 text-2xs text-white"
                >
                  <img :src="verifiedCheck" alt="" class="size-2.5" aria-hidden="true" />
                  VERIFIED
                </span>

                <!-- logo tile 387:19794 — hangs 24px below the photo, wrapped in
                     the entity ring (blue for SBO, gold for COB). -->
                <span class="absolute -bottom-6 left-4" aria-hidden="true">
                  <EntityLogoRing :entity="c.entityType">
                    <span
                      class="grid size-12 place-items-center rounded-full border-2 border-white"
                      :style="{ backgroundColor: c.tile }"
                    >
                      <img :src="c.logo" alt="" class="size-4" />
                    </span>
                  </EntityLogoRing>
                </span>
              </div>

              <div class="flex min-w-0 flex-1 flex-col px-4 pt-9 pb-5">
                <RouterLink
                  :href="`/business/${c.slug}`"
                  class="text-base leading-5 font-semibold text-[#1f2937] hover:underline"
                >
                  {{ c.name }}
                </RouterLink>
                <p class="min-w-0 truncate text-2xs leading-4 text-[#9ca3af]">{{ c.meta }}</p>

                <!-- tags 387:19772 -->
                <div class="mt-2 flex flex-wrap gap-2">
                  <span
                    v-for="t in c.tags"
                    :key="t"
                    class="inline-flex h-[17.5px] items-center rounded bg-[#f3f4f6] px-2 text-2xs text-[#6b7280]"
                  >
                    {{ t }}
                  </span>
                </div>

                <!-- footer 387:19777 — flag row, action, remove -->
                <div class="mt-auto flex items-center gap-3 pt-4">
                  <span class="flex shrink-0 gap-0.5">
                    <img
                      v-for="(f, i) in c.flags"
                      :key="i"
                      :src="f"
                      alt=""
                      class="size-4 rounded-full object-cover"
                      aria-hidden="true"
                    />
                  </span>

                  <button
                    type="button"
                    :disabled="connectionForm.processing || composeForm.processing"
                    class="h-[38px] min-w-0 flex-1 rounded border border-[#e5e7eb] bg-white text-2xs font-semibold text-[#1f2937] transition-colors hover:bg-surface-secondary disabled:opacity-60"
                    @click="tab === 'requests' ? respond(c, 'accepted') : messageCard(c)"
                  >
                    {{ tab === 'requests' ? t('notifications.accept') : t('action.message') }}
                  </button>

                  <button
                    type="button"
                    :disabled="connectionForm.processing || disconnectForm.processing"
                    class="grid h-[38px] w-[26px] shrink-0 place-items-center rounded border border-[#e5e7eb] bg-white transition-colors hover:bg-surface-secondary disabled:opacity-60"
                    :aria-label="tab === 'requests' ? t('sbo_network.decline_request') : t('business_profile.disconnect')"
                    :title="tab === 'requests' ? t('sbo_network.decline_request') : t('business_profile.disconnect')"
                    @click="tab === 'requests' ? respond(c, 'declined') : disconnect(c)"
                  >
                    <img :src="netCross" alt="" class="size-4" aria-hidden="true" />
                  </button>
                </div>
              </div>
            </article>
          </li>
        </ul>

        <!-- Reachable on a new account and after clearing the last request. -->
        <div v-else class="px-4 py-10 sm:py-16 text-center">
          <p class="font-display text-base font-semibold text-[#1f2937]">
            {{ tab === 'requests' ? t('sbo_network.no_connection_requests') : t('sbo_network.no_connections_yet') }}
          </p>
          <p class="mt-1 text-xs text-[#6b7280]">
            {{
              tab === 'requests'
                ? t('sbo_network.requests_from_other_verified_businesses_wi')
                : t('sbo_network.find_verified_businesses_in_the_registry_a')
            }}
          </p>
          <RouterLink
            v-if="tab !== 'requests'"
            href="/businesses"
            class="mt-5 inline-flex rounded-lg bg-[#001529] px-5 py-2.5 text-xs font-semibold text-white hover:opacity-90"
          >
            {{ t('sbo_network.browse_the_registry') }}
          </RouterLink>
        </div>
      </section>
    </div>
  </template>

  <!-- ==================================== messages 387:21075 ==============
       Two panes: a 420px inbox rail and the active conversation.

       Both panes stick to the viewport on desktop and scroll internally, so
       reading down a long inbox does not carry the open conversation off the
       top of the screen — and a long thread does not push the composer below
       the fold. The whole row used to grow with its tallest child, which meant
       the page itself was the only scroller.

       Below lg the two panes do not stack — only ONE is on screen at a time,
       the way every phone messaging app works. Stacked, the inbox pushed the
       thread a full screen down, so opening a conversation meant scrolling
       past every other one to reach it. The list is the screen until you pick
       a conversation; then the conversation is. -->
  <div
    v-else
    class="flex flex-col gap-6 bg-surface-secondary px-5 pt-3 pb-20 md:px-8 lg:flex-row lg:items-start xl:pr-20 xl:pl-10"
  >
    <!-- left-sidebar 387:21880 — the pane that scrolls. -->
    <!--
      `self-start` and `shrink-0` are desktop-only, and this is why: the parent
      is a COLUMN below lg, where align-self:flex-start makes a child
      shrink-wrap its content instead of filling the row. One long business
      name then sized the whole pane, and it ran off the side of the screen.
      Explicit w-full is what holds it to the container.
    -->
    <aside
      class="w-full min-w-0 flex-col overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-2rem)] lg:w-[420px] lg:shrink-0 lg:self-start lg:overflow-y-auto"
      :class="showThreadOnPhone ? 'hidden' : 'flex'"
    >
      <div class="flex flex-col gap-4 p-5">
        <div class="flex items-center justify-between">
          <h2 class="text-xl font-semibold text-[#0f172a]">{{ t('sbo_network.inbox') }}</h2>
          <div class="flex items-center gap-2">
            <!-- Start a group. Your connections are the pool it draws from. -->
            <button
              type="button"
              :disabled="!props.connections.length"
              class="flex items-center gap-1.5 rounded-full bg-[#e6f6f3] px-3 py-2 text-xs font-semibold text-[#0d9488] disabled:opacity-40"
              :aria-expanded="groupOpen"
              @click="groupOpen = !groupOpen; composeOpen = false"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-[18px] shrink-0" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              {{ t('sbo_network.create_group') }}
            </button>
            <button
              type="button"
              :disabled="!props.composeTargets.length"
              class="grid size-9 place-items-center rounded-full bg-[#eff6ff] disabled:opacity-40"
              :aria-label="
                props.composeTargets.length
                  ? t('sbo_network.compose_message')
                  : t('sbo_network.no_connections_left_to_start_a_conversatio')
              "
              :aria-expanded="composeOpen"
              @click="composeOpen = !composeOpen; groupOpen = false"
            >
              <img :src="msgCompose" alt="" class="size-[18px]" aria-hidden="true" />
            </button>
          </div>
        </div>

        <!-- Create a group: a name and the connections to start it with. -->
        <div
          v-if="groupOpen"
          class="flex flex-col gap-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-4"
        >
          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#0f172a]">{{ t('sbo_network.group_name') }}</span>
            <input
              v-model="groupForm.name"
              type="text"
              maxlength="120"
              :placeholder="t('sbo_network.group_name_placeholder')"
              class="rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
            <span v-if="groupForm.errors.name" class="text-2xs text-danger">{{ groupForm.errors.name }}</span>
          </label>

          <div class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#0f172a]">{{ t('sbo_network.add_from_connections') }}</span>
            <div class="max-h-52 overflow-y-auto rounded-lg border border-[#e2e8f0] bg-white">
              <label
                v-for="c in props.connections"
                :key="c.sboId"
                class="flex cursor-pointer items-center gap-2.5 border-b border-[#f1f5f9] px-3 py-2 last:border-b-0 hover:bg-surface-secondary"
              >
                <input
                  type="checkbox"
                  class="size-4 shrink-0 rounded border-[#cbd5e1] text-[#1b8a4e] focus:ring-focus"
                  :checked="groupForm.member_sbo_ids.includes(c.sboId)"
                  @change="toggleGroupSeed(c.sboId)"
                />
                <span class="min-w-0 flex-1 truncate text-sm text-[#0f172a]">{{ c.name }}</span>
              </label>
            </div>
            <span v-if="groupForm.errors.member_sbo_ids" class="text-2xs text-danger">{{ groupForm.errors.member_sbo_ids }}</span>
          </div>

          <div class="flex items-center justify-between gap-2">
            <span class="text-2xs text-[#94a3b8]">{{ tc('network.member_count', groupForm.member_sbo_ids.length + 1) }}</span>
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
                @click="groupOpen = false"
              >
                {{ t('sbo_network.cancel') }}
              </button>
              <button
                type="button"
                :disabled="!groupForm.name.trim() || !groupForm.member_sbo_ids.length || groupForm.processing"
                class="rounded-lg bg-[#1b8a4e] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                @click="createGroup"
              >
                {{ t('sbo_network.create') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Compose. Not a Figma frame — the design draws the button but never
             what it opens — so it is kept to the rail's own idiom. -->
        <div
          v-if="composeOpen"
          class="flex flex-col gap-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-4"
        >
          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#0f172a]">{{ t('sbo_network.start_a_conversation_with') }}</span>
            <select
              v-model="composeTarget"
              class="max-w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            >
              <option :value="null">{{ t('sbo_network.select_a_connection') }}</option>
              <option v-for="t in props.composeTargets" :key="t.sboId" :value="t.sboId">
                {{ t.name }}
              </option>
            </select>
          </label>
          <textarea
            v-model="composeForm.body"
            rows="3"
            :placeholder="t('sbo_network.write_the_first_message_optional')"
            class="resize-y rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="rounded-lg border border-[#e2e8f0] bg-white px-3 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
              @click="composeOpen = false"
            >
              {{ t('sbo_network.cancel') }}
            </button>
            <button
              type="button"
              :disabled="composeTarget === null || composeForm.processing"
              class="rounded-lg bg-[#1b8a4e] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
              @click="startConversation"
            >
              {{ t('sbo_network.start') }}
            </button>
          </div>
        </div>

        <label class="flex h-10 items-center gap-2 rounded-lg border border-[#e2e8f0] px-3">
          <span class="sr-only">{{ t('sbo_network.search_messages') }}</span>
          <img :src="msgSearch" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
          <input
            v-model="inboxSearch"
            type="search"
            :placeholder="t('sbo_network.search_messages_or_the_registry')"
            class="min-w-0 flex-1 bg-transparent text-sm text-ink placeholder:text-[#94a3b8] focus:outline-none"
            @input="onInboxSearch"
          />
        </label>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in chips"
            :key="c.key"
            type="button"
            :disabled="'disabled' in c && c.disabled"
            class="rounded-full px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
            :class="
              chip === c.key
                ? 'border border-[#1b8a4e] bg-[#eff6ff] font-semibold text-[#1b8a4e]'
                : 'bg-[#f1f5f9] font-medium text-[#475569]'
            "
            :aria-pressed="chip === c.key"
            @click="chip = c.key"
          >
            {{ c.label }}
          </button>
        </div>
      </div>

      <!-- conv-list 387:21903 -->
      <ul v-if="conversations.length" class="flex flex-col gap-1 border-t border-[#e2e8f0] p-2">
        <li v-for="c in conversations" :key="c.id" class="relative">
          <!--
            The row is a button and the menu is its sibling, not its child: an
            interactive control inside a button is invalid, and in practice the
            click lands on whichever the browser feels like.
          -->
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl p-3 pr-11 text-left transition-colors sm:p-4"
            :class="c.active ? 'bg-[#f1f5f9]' : 'hover:bg-[#f8fafc]'"
            @click="openConversation(c)"
          >
            <!-- avatar: a group stack for a group thread, the counterpart's
                 initial tile for a direct one. `sbos` carries no logo. -->
            <!-- 48px of avatar and 16px of name left about half the row for
                 the name itself, so almost every business truncated after two
                 words. Both step down below sm. -->
            <span class="relative grid size-10 shrink-0 place-items-center sm:size-12">
              <span
                v-if="c.category === 'connections'"
                class="grid size-10 place-items-center rounded-full bg-[#001529] font-display text-sm font-semibold text-white sm:size-12 sm:text-base"
                aria-hidden="true"
              >
                {{ c.name.slice(0, 1).toUpperCase() }}
              </span>

              <!--
                avatar-stack-container 509:52701 — three 28px discs with 1.5px
                white rings, two up and one down, and the roster count as a pill
                at the lower right (509:52716).

                The discs show members who have uploaded a photo and fall back
                to the frame's own user glyph; `sbos` carries no logo, so there
                is nothing else to put in them.
              -->
              <!--
                The box keeps its 52x43, because the three discs inside it are
                positioned against exactly those numbers. Only the rendering is
                scaled, down to the 40px the single-initial disc occupies on a
                phone, so the two kinds of row line up.
              -->
              <span
                v-else
                class="relative block h-[43px] w-[52px] origin-center scale-[0.77] sm:scale-100"
                aria-hidden="true"
              >
                <span
                  v-for="(slot, i) in stackSlots(c)"
                  :key="i"
                  class="absolute grid size-7 place-items-center overflow-clip rounded-[14px] border-[1.5px] border-white"
                  :class="[
                    i === 0 && 'top-0 left-0',
                    i === 1 && 'top-0 left-6',
                    i === 2 && 'top-[28px] left-0.5',
                    slot ? 'bg-white' : 'bg-[#d1d6e0]',
                  ]"
                >
                  <img v-if="slot" :src="slot" alt="" class="size-full object-cover" />
                  <img v-else :src="stackUser" alt="" class="size-4" />
                </span>

                <!-- stack-label 509:52716 -->
                <span
                  class="absolute top-[31px] left-[25px] rounded-[10px] bg-[#f3f2ef] px-1.5 py-0.5 text-2xs font-semibold text-[#5e5e5e]"
                >
                  +{{ c.participants }}
                </span>
              </span>
            </span>

            <span class="flex min-w-0 flex-1 flex-col gap-1">
              <span class="flex min-w-0 items-center justify-between gap-2">
                <span
                  class="min-w-0 truncate font-display text-sm leading-5 sm:text-base"
                  :class="c.unread ? 'font-semibold text-[#1f2937]' : 'font-semibold text-[#0f172a]'"
                >
                  {{ c.name }}
                </span>
                <span
                  class="shrink-0 text-2xs sm:text-xs"
                  :class="c.unread ? 'text-[#1b8a4e]' : 'text-[#94a3b8]'"
                >
                  {{ c.time }}
                </span>
              </span>
              <span class="min-w-0 truncate text-2xs leading-4 text-[#9ca3af]">{{ c.org }}</span>
              <span
                class="min-w-0 truncate text-xs"
                :class="c.unread ? 'font-medium text-[#0f172a]' : 'text-[#94a3b8]'"
              >
                {{ c.preview }}
              </span>
            </span>

            <!--
              Starred rows carry the mark. Without it the star was a control
              with no visible consequence anywhere except the Starred chip,
              which is the wrong way round: you star something to recognise it
              in the list.
            -->
            <span
              v-if="c.starred"
              class="shrink-0 text-xs text-[#f59e0b]"
              :title="t('sbo_network.starred')"
              aria-hidden="true"
            >★</span>

            <!-- unread-badge 387:21936 -->
            <span
              v-if="c.unread"
              class="size-2 shrink-0 rounded-full bg-[#1b8a4e]"
              :aria-label="tc('sbo_network.count_unread_short', c.unread)"
            />
          </button>

          <!--
            The row menu. Private messaging was only reachable from inside an
            open group, which is not somewhere anyone thinks to look — it is
            an action on a PERSON, so it belongs next to their name.
          -->
          <div class="absolute top-3 right-2">
            <button
              type="button"
              class="grid size-7 place-items-center rounded-lg text-[#94a3b8] transition-colors hover:bg-[#e2e8f0] hover:text-[#0f172a]"
              :aria-label="t('chat.more_actions', { name: c.name })"
              :aria-expanded="rowMenu === c.id"
              @click.stop="rowMenu = rowMenu === c.id ? null : c.id"
            >
              <svg viewBox="0 0 24 24" fill="currentColor" class="size-4" aria-hidden="true">
                <circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" />
              </svg>
            </button>

            <div
              v-if="rowMenu === c.id"
              class="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-[#e2e8f0] bg-white py-1 shadow-lg"
            >
              <!--
                In a room, every member can be taken aside — listed here by
                name so the action is one click from the list rather than
                behind opening the room first.
              -->
              <template v-if="c.group">
                <!--
                  The registry, first: a member in a room who wants a private
                  word usually wants it with whoever runs the place, and the
                  room itself has no owner to ask.
                -->
                <button
                  type="button"
                  class="block w-full px-3 py-2 text-left text-2xs font-semibold text-[#0f172a] transition-colors hover:bg-surface-secondary"
                  @click="messageAdmin"
                >
                  {{ t('chat.message_admin') }}
                </button>
                <span class="my-1 block h-px bg-[#e2e8f0]" aria-hidden="true" />

                <p class="px-3 py-1.5 text-2xs font-semibold tracking-wide text-[#94a3b8] uppercase">
                  {{ t('chat.message_privately') }}
                </p>
                <button
                  v-for="member in (c.members ?? [])"
                  :key="member.sboId"
                  type="button"
                  class="block w-full truncate px-3 py-2 text-left text-2xs text-[#0f172a] transition-colors hover:bg-surface-secondary"
                  @click="messagePrivatelyFrom(c.id, member.sboId)"
                >
                  {{ member.name }}
                  <span v-if="member.isAdmin" class="ml-1 text-[#94a3b8]">· {{ t('chat.admin') }}</span>
                </button>
                <p v-if="!(c.members ?? []).length" class="px-3 py-2 text-2xs text-[#94a3b8]">
                  {{ t('chat.nobody_else_here_yet') }}
                </p>
              </template>

              <!-- A direct thread IS the private one, so the useful action is
                   the business behind it. -->
              <RouterLink
                v-else-if="c.slug"
                :href="`/business/${c.slug}`"
                class="block px-3 py-2 text-2xs text-[#0f172a] transition-colors hover:bg-surface-secondary"
              >
                {{ t('directory.view_profile') }}
              </RouterLink>
            </div>
          </div>
        </li>
      </ul>

      <p
        v-else-if="!searching"
        class="border-t border-[#e2e8f0] px-5 py-10 text-center text-xs text-[#94a3b8]"
      >
        {{
          props.conversations.length
            ? t('sbo_network.no_conversations_match_this_filter')
            : t('sbo_network.no_conversations_yet_accept_a_connection_r')
        }}
      </p>

      <!--
        Registry results. Not a Figma frame — the design's search box only ever
        filtered the list above it — so this keeps the conv-item's own shape and
        adds the one thing a search hit needs that a thread does not: the action
        that is actually available for that person.
      -->
      <section v-if="searching" class="flex flex-col gap-1 border-t border-[#e2e8f0] p-2">
        <h3 class="px-4 pt-3 pb-1 font-sans text-2xs font-semibold tracking-wide text-[#94a3b8] uppercase">
          {{ t('sbo_network.on_the_registry') }}
        </h3>

        <p v-if="!people.length" class="px-4 py-6 text-center text-xs text-[#94a3b8]">
          {{ t('sbo_network.no_match_for', { term: inboxSearch }) }}
        </p>

        <div
          v-for="p in people"
          :key="p.sboId"
          class="flex items-center gap-3 rounded-xl p-4 transition-colors hover:bg-[#f8fafc]"
        >
          <span
            class="grid size-12 shrink-0 place-items-center rounded-full bg-[#001529] font-display text-base font-semibold text-white"
            aria-hidden="true"
          >
            {{ p.name.slice(0, 1).toUpperCase() }}
          </span>

          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <RouterLink
              :href="`/business/${p.slug}`"
              class="flex min-w-0 items-center gap-1.5 truncate text-base leading-5 font-semibold text-[#0f172a] hover:underline"
            >
              {{ p.name }}
              <img
                v-if="p.verified"
                :src="verifiedCheck"
                :alt="t('sbo_network.verified')"
                class="size-3 shrink-0 brightness-0"
              />
            </RouterLink>
            <span class="min-w-0 truncate text-2xs leading-4 text-[#9ca3af]">{{ p.meta }}</span>
          </span>

          <!--
            You can only message an accepted connection — the rule the compose
            endpoint enforces — so an unconnected hit offers Connect instead of
            a Message button that would be refused.
          -->
          <button
            v-if="p.connection === 'accepted'"
            type="button"
            :disabled="composeForm.processing"
            class="shrink-0 rounded-full border border-[#1b8a4e] px-3.5 py-1.5 text-xs font-semibold text-[#1b8a4e] transition-colors hover:bg-[#eff6ff] disabled:opacity-50"
            @click="messagePerson(p)"
          >
            {{ p.conversationId ? t('listing_profile_form.open') : t('action.message') }}
          </button>
          <span
            v-else-if="p.connection === 'pending'"
            class="shrink-0 rounded-full bg-[#f1f5f9] px-3.5 py-1.5 text-xs font-medium text-[#475569]"
            :title="t('sbo_network.connection_request_awaiting_a_response')"
          >
            {{ t('sbo_network.pending') }}
          </span>
          <button
            v-else
            type="button"
            :disabled="!props.mySbo || connectForm.processing"
            class="shrink-0 rounded-full border border-[#e2e8f0] px-3.5 py-1.5 text-xs font-semibold text-[#475569] transition-colors hover:bg-surface-secondary disabled:opacity-50"
            @click="connectTo(p)"
          >
            {{ t('sbo_network.connect') }}
          </button>
        </div>
      </section>
    </aside>

    <!-- active-chat-panel 387:21978 -->
    <!--
      On a phone this is the screen: a fixed height with the thread scrolling
      inside it, so the composer stays on the bottom edge instead of being
      pushed below the fold by a long conversation.
    -->
    <section
      v-if="active"
      class="h-[calc(100dvh-9rem)] w-full min-w-0 flex-col overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white lg:sticky lg:top-4 lg:flex lg:h-[calc(100vh-2rem)] lg:w-auto lg:flex-1"
      :class="showThreadOnPhone ? 'flex' : 'hidden'"
    >
      <!-- chat-header 387:21979 — pinned; the thread beneath it scrolls. -->
      <header class="flex shrink-0 items-center justify-between border-b border-[#e2e8f0] px-4 py-3 sm:px-6 sm:py-4">
        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
          <!-- The way back to the list. Desktop has both panes side by side and
               needs none. -->
          <button
            type="button"
            class="-ml-1 grid size-8 shrink-0 place-items-center rounded-lg text-[#475569] transition-colors hover:bg-surface-secondary lg:hidden"
            :aria-label="t('sbo_network.inbox')"
            @click="backToInbox"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5" aria-hidden="true">
              <path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>

          <img :src="tileBuildright" alt="" class="size-10 shrink-0 sm:size-12" aria-hidden="true" />
          <!-- The name opens the panel, which is where people look for it. -->
          <button
            type="button"
            class="min-w-0 rounded-lg text-left transition-colors hover:opacity-80"
            @click="infoOpen = true"
          >
            <p class="min-w-0 truncate font-display text-[13px] leading-5 font-semibold text-[#1f2937]">
              {{ active.name }}
            </p>
            <p class="min-w-0 truncate text-xs text-[#475569]">{{ active.org }}</p>
          </button>
          <!-- Honest about the transport: the thread only updates itself while
               the socket is up. Otherwise it is correct on the next load. -->
          <span
            v-if="live"
            class="ml-2 hidden shrink-0 items-center gap-1.5 rounded-full bg-[#f0fdf4] px-2.5 py-1 text-2xs font-semibold text-[#15803d] sm:inline-flex"
          >
            <span class="size-1.5 rounded-full bg-[#22c55e]" aria-hidden="true" />
            {{ t('sbo_network.live') }}
          </span>
        </div>
        <div class="relative flex shrink-0 items-center gap-4">
          <button
            type="button"
            :disabled="starForm.processing"
            class="grid size-9 place-items-center rounded-full transition-colors disabled:opacity-50"
            :class="active.starred ? 'bg-[#fef3c7]' : 'bg-[#f1f5f9] hover:bg-[#e2e8f0]'"
            :aria-pressed="active.starred"
            :aria-label="active.starred ? t('sbo_network.remove_star') : t('sbo_network.star_conversation')"
            :title="active.starred ? t('sbo_network.remove_star') : t('sbo_network.star_conversation')"
            @click="toggleStar"
          >
            <img :src="msgStarOff" alt="" class="size-[18px]" aria-hidden="true" />
          </button>

          <button
            type="button"
            class="grid size-9 place-items-center rounded-full bg-[#f1f5f9] transition-colors hover:bg-[#e2e8f0]"
            aria-haspopup="menu"
            :aria-expanded="menuOpen"
            :aria-label="t('sbo_network.more_options')"
            @click="menuOpen = !menuOpen"
          >
            <img :src="msgMenu" alt="" class="size-[18px]" aria-hidden="true" />
          </button>

          <!-- Only actions the thread actually supports. -->
          <div
            v-show="menuOpen"
            role="menu"
            class="absolute top-11 right-0 z-20 w-56 overflow-hidden rounded-xl border border-[#e2e8f0] bg-white py-1 shadow-[0px_12px_24px_rgba(15,23,42,0.12)]"
          >
            <button
              type="button"
              role="menuitem"
              class="block w-full px-4 py-2.5 text-left text-xs text-[#475569] hover:bg-[#f8fafc]"
              @click="markRead"
            >
              {{ t('sbo_network.mark_as_read') }}
            </button>
            <button
              type="button"
              role="menuitem"
              class="block w-full px-4 py-2.5 text-left text-xs text-[#475569] hover:bg-[#f8fafc]"
              @click="closeMenu(); toggleStar()"
            >
              {{ active.starred ? t('sbo_network.remove_star') : t('sbo_network.star_conversation') }}
              <span class="mt-0.5 block text-2xs text-[#94a3b8]">
                {{ t('sbo_network.star_hint') }}
              </span>
            </button>
            <RouterLink
              v-if="active.slug"
              :href="`/business/${active.slug}`"
              role="menuitem"
              class="block px-4 py-2.5 text-xs text-[#475569] hover:bg-[#f8fafc]"
              @click="closeMenu"
            >
              {{ t('sbo_network.view_business_profile') }}
            </RouterLink>
            <!-- Where the roster lives now it is off the top of the thread:
                 the count states what is inside, the item opens it. -->
            <button
              v-if="activeIsGroup"
              type="button"
              role="menuitem"
              class="block w-full border-t border-[#f1f5f9] px-4 py-2.5 text-left text-xs text-[#475569] hover:bg-[#f8fafc]"
              @click="closeMenu(); infoOpen = true"
            >
              {{ t('sbo_network.see_all_participants') }}
              <span class="mt-0.5 block text-2xs text-[#94a3b8]">
                {{ tc('sbo_network.members_in_room', activeMembers) }}
              </span>
            </button>
          </div>
        </div>
      </header>

      <!-- chat-thread-container 387:22001 — the only scrolling part of the
           conversation, so the header and composer stay put. -->
      <div ref="threadEl" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 sm:gap-6 sm:p-6">
        <!-- profile-context-card 387:22002 -->
        <div class="flex flex-col items-center gap-4 rounded-xl bg-[#f1f5f9] p-5">
          <span
            class="grid size-[75px] place-items-center rounded-full border-2 border-white bg-[#001529]"
            aria-hidden="true"
          >
            <img :src="tileLogo24" alt="" class="size-6" />
          </span>
          <div class="flex flex-col items-center gap-1 text-center">
            <p class="font-display text-[13px] leading-5 font-semibold text-[#1f2937]">
              {{ active.name }}
            </p>
            <p class="text-2xs leading-4 text-[#9ca3af]">{{ active.org }}</p>
            <!-- Mutual connections are drawn in the frame; there is no query
                 for them yet, so the row states the participant count instead
                 of inventing a number. -->
            <p class="flex items-center gap-1.5 pt-1 text-xs text-[#94a3b8]">
              <img :src="msgUsers" alt="" class="size-3.5 shrink-0" aria-hidden="true" />
              {{ tc('sbo_network.participant_count', active.participants) }}
            </p>
          </div>
          <RouterLink
            v-if="active.slug"
            :href="`/business/${active.slug}`"
            class="rounded-[20px] border border-[#1b8a4e] px-4 py-2 text-xs font-semibold text-[#1b8a4e]"
          >
            {{ t('sbo_network.view_profile') }}
          </RouterLink>
        </div>

        <!-- The participant list used to sit here, above every conversation.
             It belongs in Group Info now — reached from "See all participants"
             in the header menu — so the thread opens on the conversation, not
             on a roster to scroll past. -->
        <div v-if="thread.length" class="flex items-center gap-3">
          <span class="h-px flex-1 bg-[#e2e8f0]" aria-hidden="true" />
          <span class="text-2xs text-[#94a3b8] uppercase">{{ t('sbo_network.messages') }}</span>
          <span class="h-px flex-1 bg-[#e2e8f0]" aria-hidden="true" />
        </div>

        <p v-else class="py-8 text-center text-xs text-[#94a3b8]">
          {{ t('sbo_network.no_messages_yet_say_hello') }}
        </p>

        <div
          v-for="m in thread"
          :key="m.id"
          :data-message-id="m.id"
          class="flex items-end gap-3"
          :class="m.mine && 'justify-end'"
        >
          <span
            v-if="!m.mine"
            class="hidden size-9 shrink-0 place-items-center rounded-full bg-[#001529] text-xs font-semibold text-white sm:grid"
            aria-hidden="true"
          >
            {{ (m.author ?? '?').slice(0, 1).toUpperCase() }}
          </span>
          <div class="group/msg relative flex min-w-0 max-w-[85%] flex-col gap-1 sm:max-w-[540px]">
            <!--
              Who said it, in a group. The whole point of a room is that several
              businesses are talking, so a bubble without a name is a remark
              from nobody. Coloured per sender, and left off one-to-one threads
              and your own messages, where there is no one to identify.
            -->
            <span
              v-if="activeIsGroup && !m.mine"
              class="truncate px-1 text-2xs font-semibold"
              :style="{ color: nameColour(m.author) }"
            >
              {{ m.author }}
            </span>

            <!--
              The quote. One level, always: replying to a reply quotes the
              reply, which is what the chains people write actually need.
            -->
            <button
              v-if="m.replyTo"
              type="button"
              class="max-w-full rounded-lg border-l-2 border-[#1b8a4e] bg-[#f8fafc] px-3 py-1.5 text-left"
              :class="m.mine && 'self-end'"
              @click="scrollToMessage(m.replyTo.id)"
            >
              <span class="block truncate text-2xs font-semibold text-[#1b8a4e]">
                {{ m.replyTo.author }}
              </span>
              <span class="block truncate text-2xs text-[#64748b]">{{ m.replyTo.body }}</span>
            </button>

            <!--
              Attachments sit above the words, because that is the order they
              are read in: the picture is the message and the caption explains
              it. A message may have several.
            -->
            <div
              v-if="m.attachments?.length"
              class="flex flex-col gap-1.5"
              :class="m.mine && 'items-end'"
            >
              <template v-for="a in m.attachments" :key="a.id">
                <!-- Shown, not described. The route serves images inline. -->
                <a v-if="a.kind === 'image'" :href="a.url" target="_blank" rel="noopener">
                  <img
                    :src="a.url"
                    :alt="a.name"
                    class="max-h-64 w-auto max-w-full rounded-xl border border-[#e2e8f0] object-cover"
                  />
                </a>

                <!-- `preload="metadata"`: a thread of videos should not pull
                     down every one of them to draw the page. -->
                <video
                  v-else-if="a.kind === 'video'"
                  :src="a.url"
                  controls
                  preload="metadata"
                  class="max-h-64 w-full max-w-[320px] rounded-xl border border-[#e2e8f0] bg-black"
                />

                <!-- A document cannot be previewed in a bubble, so the row
                     carries what decides whether to open it: name and size. -->
                <a
                  v-else
                  :href="a.url"
                  class="flex max-w-full items-center gap-2.5 rounded-xl border border-[#e2e8f0] bg-white px-3 py-2.5 transition-colors hover:bg-surface-secondary"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5 shrink-0 text-[#475569]" aria-hidden="true">
                    <path d="M14 3v5h5M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" stroke-linejoin="round" />
                  </svg>
                  <span class="min-w-0">
                    <span class="block truncate text-2xs font-semibold text-[#0f172a]">{{ a.name }}</span>
                    <span class="block text-2xs text-[#94a3b8]">{{ readableSize(a.size) }}</span>
                  </span>
                </a>
              </template>
            </div>

            <!-- A tombstone, not a hole: a thread that silently loses a line
                 reads as though it was never sent. -->
            <p
              v-if="m.deleted"
              class="rounded-2xl border border-dashed border-[#e2e8f0] px-4 py-3 text-sm text-[#94a3b8] italic"
              :class="m.mine && 'self-end'"
            >
              {{ t('chat.message_deleted') }}
            </p>

            <p
              v-else-if="m.body"
              class="px-4 py-3 text-sm leading-normal whitespace-pre-line"
              :class="
                m.mine
                  ? 'rounded-2xl rounded-br-sm bg-[#1b8a4e] text-white'
                  : 'rounded-2xl rounded-bl-sm bg-[#f1f5f9] text-[#0f172a]'
              "
            >
              <!-- The translation replaces the text in place; the original is
                   one click away rather than shown twice, which would double
                   the height of every bubble in a long thread.

                   v-html is safe here because linkify() escapes the body before
                   it inserts any markup of its own. -->
              <span
                v-html="linkify(translationShown[m.id] && translations[m.id] ? translations[m.id].body : m.body)"
              />
            </p>

            <!-- Reactions, under the bubble they belong to. The same chip
                 adds and removes, which is what `mine` is for. -->
            <div
              v-if="m.reactions?.length"
              class="flex flex-wrap gap-1"
              :class="m.mine && 'justify-end'"
            >
              <button
                v-for="r in m.reactions"
                :key="r.emoji"
                type="button"
                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-2xs transition-colors"
                :class="
                  r.mine
                    ? 'border-[#1b8a4e] bg-[#1b8a4e]/10 text-[#1b8a4e]'
                    : 'border-[#e2e8f0] bg-white text-[#475569] hover:bg-surface-secondary'
                "
                @click="react(m, r.emoji)"
              >
                <span aria-hidden="true">{{ r.emoji }}</span>
                {{ r.count }}
              </button>
            </div>

            <!--
              The action menu. Hidden until the bubble is hovered or something
              inside it has focus — nine controls beside every line would be
              more furniture than conversation — but present in the DOM so it
              is reachable by keyboard.
            -->
            <div
              v-if="!m.deleted"
              class="absolute -top-2 opacity-0 transition-opacity group-hover/msg:opacity-100 focus-within:opacity-100"
              :class="m.mine ? 'left-0 -translate-x-full pr-1' : 'right-0 translate-x-full pl-1'"
            >
              <button
                type="button"
                class="grid size-6 place-items-center rounded-full border border-[#e2e8f0] bg-white text-[#64748b] shadow-sm transition-colors hover:text-[#0f172a]"
                :aria-label="t('chat.message_actions')"
                :aria-expanded="messageMenu === m.id"
                @click.stop="toggleMessageMenu(m.id)"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="size-3" aria-hidden="true">
                  <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </button>

              <!--
                Opens UPWARD. Most of the thread is near its own bottom — that
                is where new messages arrive and where the reader is — so a
                menu of eight dropping downward ran off the end of the pane and
                was unreachable on exactly the messages people act on most.
              -->
              <div
                v-if="messageMenu === m.id"
                class="absolute bottom-full z-30 mb-1 w-52 overflow-hidden rounded-xl border border-[#e2e8f0] bg-white py-1 text-left shadow-lg"
                :class="m.mine ? 'right-0' : 'left-0'"
                @click.stop
              >
                <button type="button" class="chat-menu-item" @click="startReply(m)">
                  {{ t('chat.reply') }}
                </button>

                <!--
                  Shown whether or not it applies here, greyed with the reason
                  when it does not. Hiding an item made the menu a different
                  length on every message, so a control someone had used once
                  looked like it had been taken away.
                -->
                <button
                  type="button"
                  class="chat-menu-item disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!canReplyPrivately(m)"
                  :title="privateReplyReason(m)"
                  @click="replyPrivately(m)"
                >
                  {{ t('chat.reply_privately') }}
                </button>

                <!-- Disabled rather than hidden on a message that is only a
                     file: there is nothing to put on the clipboard, and an
                     item that vanishes reads as one that was taken away. -->
                <button
                  type="button"
                  class="chat-menu-item disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!m.body"
                  :title="m.body ? undefined : t('chat.nothing_to_copy')"
                  @click="copyMessage(m)"
                >
                  {{ copied === m.id ? t('chat.copied') : t('chat.copy') }}
                </button>

                <button
                  type="button"
                  class="chat-menu-item"
                  :aria-expanded="reactingTo === m.id"
                  @click.stop="reactingTo = reactingTo === m.id ? null : m.id"
                >
                  {{ t('chat.react') }}
                </button>

                <!-- The picker opens in place, under React. -->
                <div v-if="reactingTo === m.id" class="flex gap-1 px-3 py-1.5">
                  <button
                    v-for="emoji in REACTIONS"
                    :key="emoji"
                    type="button"
                    class="grid size-7 place-items-center rounded-full text-sm transition-colors hover:bg-surface-secondary"
                    @click="react(m, emoji)"
                  >
                    {{ emoji }}
                  </button>
                </div>

                <button type="button" class="chat-menu-item" @click="togglePin(m)">
                  {{ m.pinned ? t('chat.unpin') : t('chat.pin') }}
                </button>

                <!--
                  The same control the bubble already carried as "See
                  translation", reachable from the menu as well. Offered only
                  on someone else's message and only where a provider is
                  configured — translating your own words into your own
                  language is not a thing anyone wants.
                -->
                <button
                  type="button"
                  class="chat-menu-item disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!canTranslate || m.mine"
                  :title="
                    m.mine
                      ? t('chat.cannot_translate_own')
                      : canTranslate
                        ? undefined
                        : t('chat.translation_unavailable')
                  "
                  @click="translateFromMenu(m)"
                >
                  {{ translationShown[m.id] ? t('chat.show_original') : t('chat.translate') }}
                </button>

                <span class="my-1 block h-px bg-[#e2e8f0]" aria-hidden="true" />

                <button
                  type="button"
                  class="chat-menu-item text-danger disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!canReport || m.mine"
                  :title="
                    m.mine
                      ? t('chat.cannot_report_own')
                      : canReport
                        ? undefined
                        : t('chat.reporting_needs_verification')
                  "
                  @click="openReport(m.id)"
                >
                  {{ t('sbo_network.report') }}
                </button>

                <!-- Own messages only. Deleting somebody else's is not a
                     permission this registry gives anyone. -->
                <button
                  type="button"
                  class="chat-menu-item text-danger disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!m.mine"
                  :title="m.mine ? undefined : t('chat.can_only_delete_own')"
                  @click="removeMessage(m)"
                >
                  {{ t('chat.delete') }}
                </button>
              </div>
            </div>

            <p
              v-if="translationShown[m.id] && translations[m.id]?.sourceName"
              class="text-2xs text-[#94a3b8] italic"
              :class="m.mine && 'text-right'"
            >
              {{ t('chat.translated_from', { language: translations[m.id].sourceName ?? '' }) }}
            </p>

            <p
              v-if="translationError[m.id]"
              class="text-2xs text-danger"
              :class="m.mine && 'text-right'"
              role="alert"
            >
              {{ translationError[m.id] }}
            </p>

            <span
              class="flex items-center gap-2 text-2xs text-[#94a3b8]"
              :class="m.mine && 'justify-end'"
            >
              {{ m.time }}

              <!-- Only offered when a provider is configured and the message is
                   someone else's — translating your own words tells you nothing.
                   Hidden rather than disabled when unconfigured: a control that
                   always fails teaches people the feature is broken. -->
              <button
                v-if="m.translatable && canTranslate"
                type="button"
                class="text-2xs font-semibold text-[#0d9488] underline-offset-2 hover:underline disabled:opacity-60"
                :disabled="translating === m.id"
                @click="toggleTranslation(m.id)"
              >
                {{
                  translating === m.id
                    ? t('chat.translating')
                    : translationShown[m.id]
                      ? t('chat.hide_translation')
                      : t('chat.see_translation')
                }}
              </button>

              <!-- Your own message is not something to report. -->
              <button
                v-if="!m.mine && canReport"
                type="button"
                class="text-2xs font-semibold text-[#94a3b8] underline-offset-2 hover:text-danger hover:underline"
                @click="openReport(m.id)"
              >
                {{ t('sbo_network.report') }}
              </button>
            </span>
          </div>
        </div>
      </div>

      <!-- chat-input-wrapper 387:22041 — pinned to the bottom of the pane. -->
      <form
        class="flex shrink-0 flex-col gap-3 border-t border-[#e2e8f0] p-3 sm:p-5"
        @submit.prevent="send"
      >
        <!-- The rest of the toolbar drawn in 387:22041 (bold/italic/link/
             emoji) stays out: `sbo_messages.body` is plain text, and a
             formatting button that formats nothing is worse than none.

             The keyboard hint is desktop-only — a phone has no Shift+Enter,
             and the row costs height the composer wants. -->
        <div class="hidden flex-wrap items-center gap-4 sm:flex">
          <span class="text-xs text-[#94a3b8]">
            {{ t('sbo_network.press_enter_to_send_shift_enter_for_new_li') }}
          </span>
        </div>

        <!--
          What is being answered, above the box you answer in. Held here rather
          than being its own action, so the composer stays the one place a
          message is written from.
        -->
        <div
          v-if="replyingTo"
          class="flex items-start gap-2 rounded-lg border-l-2 border-[#1b8a4e] bg-[#f8fafc] px-3 py-2"
        >
          <span class="min-w-0 flex-1">
            <span class="block text-2xs font-semibold text-[#1b8a4e]">
              {{ t('chat.replying_to', { name: replyingTo.author ?? '' }) }}
            </span>
            <span class="block truncate text-2xs text-[#64748b]">{{ replyingTo.body }}</span>
          </span>
          <button
            type="button"
            class="grid size-5 shrink-0 place-items-center rounded text-[#64748b] transition-colors hover:bg-border"
            :aria-label="t('chat.cancel_reply')"
            @click="cancelReply"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="size-3" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
            </svg>
          </button>
        </div>

        <!--
          Chosen files, held until the message is sent. Uploading on pick would
          mean removing one here could only orphan it on the disk.
        -->
        <ul v-if="messageForm.attachments.length" class="flex flex-wrap gap-2">
          <li
            v-for="(file, i) in messageForm.attachments"
            :key="`${file.name}-${i}`"
            class="inline-flex max-w-full items-center gap-2 rounded-lg border border-[#e2e8f0] bg-surface-secondary py-1.5 pr-1.5 pl-3"
          >
            <span class="min-w-0 truncate text-2xs font-medium text-[#0f172a]">{{ file.name }}</span>
            <span class="shrink-0 text-2xs text-[#94a3b8]">{{ readableSize(file.size) }}</span>
            <button
              type="button"
              class="grid size-5 shrink-0 place-items-center rounded text-[#64748b] transition-colors hover:bg-border"
              :aria-label="t('chat.remove_attachment', { name: file.name })"
              @click="removeAttachment(i)"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="size-3" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
              </svg>
            </button>
          </li>
        </ul>

        <div class="flex items-end gap-2 sm:gap-4">
          <!-- A label rather than a button calling .click(): the native
               control opens the picker on its own, and there is no ref to go
               missing. `accept` is a hint to the picker; the server decides. -->
          <label
            class="grid size-11 shrink-0 cursor-pointer place-items-center rounded-full border border-[#e2e8f0] text-[#475569] transition-colors hover:bg-surface-secondary sm:size-9 sm:rounded-lg"
            :title="t('chat.attach_a_file')"
          >
            <span class="sr-only">{{ t('chat.attach_a_file') }}</span>
            <input
              ref="fileInput"
              type="file"
              multiple
              class="sr-only"
              accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
              @change="pickFiles"
            />
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
              <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </label>

          <!--
            On a phone this is a button, not a field: tapping it opens the
            full-screen composer below. Inline, the strip is squeezed between
            two controls and then half covered by the keyboard, which is no way
            to write a paragraph.

            It shows the draft, so a message in progress is visible from the
            thread rather than hidden behind a tap.
          -->
          <button
            type="button"
            class="min-w-0 flex-1 truncate rounded-2xl border border-[#e2e8f0] px-3 py-3 text-left text-base text-[#0f172a] sm:hidden"
            @click="openComposer"
          >
            <span v-if="messageForm.body" class="block truncate">{{ messageForm.body }}</span>
            <span v-else class="block truncate text-[#94a3b8]">
              {{ t('sbo_network.write_a_message') }}
            </span>
          </button>

          <label class="hidden min-w-0 flex-1 sm:block">
            <span class="sr-only">{{ t('sbo_network.message') }}</span>
            <!--
              One row that grows. It was fixed at one row with the overflow
              hidden, so a message longer than the line ran underneath itself
              — which on a phone, where the line is short, happens on almost
              every message.

              16px below sm, and not for looks: Safari zooms the whole page in
              when a focused field's text is smaller than that, and the zoom
              does not come back out on blur.
            -->
            <textarea
              ref="composer"
              v-model="messageForm.body"
              rows="1"
              :placeholder="t('sbo_network.write_a_message')"
              class="max-h-32 w-full resize-none overflow-y-auto bg-transparent text-sm leading-5 text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none"
              @input="growComposer"
              @keydown.enter.exact.prevent="send"
            />
          </label>
          <button
            type="submit"
            :disabled="!canSend || messageForm.processing"
            :aria-label="t('action.send')"
            class="inline-flex size-11 shrink-0 items-center justify-center gap-2 rounded-full bg-[#1b8a4e] text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50 sm:size-auto sm:rounded-lg sm:px-5 sm:py-2.5"
          >
            <span class="hidden sm:inline">
              {{ messageForm.processing ? t('forgot_password.sending') : t('action.send') }}
            </span>
            <img :src="msgSend" alt="" class="size-4 sm:size-3.5" aria-hidden="true" />
          </button>
        </div>

        <p v-if="messageForm.errors.body" class="text-xs text-danger">
          {{ messageForm.errors.body }}
        </p>
        <!-- A rejected file is named where it was chosen. The server reports
             per index, so `attachments.0` is the first one picked. -->
        <p v-if="attachmentError" class="text-xs text-danger">{{ attachmentError }}</p>
      </form>
    </section>

    <!-- No conversation to open. -->
    <section
      v-else
      class="hidden w-full min-w-0 flex-col items-center justify-center gap-3 rounded-2xl border border-[#e2e8f0] bg-white p-16 text-center lg:sticky lg:top-4 lg:flex lg:h-[calc(100vh-2rem)] lg:w-auto lg:flex-1"
    >
      <p class="font-display text-lg font-semibold text-[#0f172a]">{{ t('sbo_network.no_conversation_selected') }}</p>
      <p class="max-w-[420px] text-xs text-[#475569]">
        {{ t('sbo_network.accepting_a_connection_request_opens_a_con') }}
      </p>
    </section>
  </div>

  <!-- Report a message. Same reason list as reporting a listing, so the admin
       queue can group both together. -->
  <div
    v-if="reportingId"
    class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="report-message-title"
    @click.self="reportingId = null"
  >
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-[0px_24px_48px_rgba(15,23,42,0.24)]">
      <h2 id="report-message-title" class="font-display text-lg font-semibold text-[#0f172a]">
        {{ t('sbo_network.report_this_message') }}
      </h2>
      <p class="mt-1 text-xs text-[#475569]">
        {{ t('sbo_network.the_message_and_the_business_that_sent_it') }}
      </p>

      <form class="mt-5 flex flex-col gap-4" @submit.prevent="submitReport">
        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-[#0f172a]">{{ t('sbo_network.reason') }}</span>
          <select
            v-model="reportForm.reason"
            required
            class="max-w-full rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          >
            <option value="" disabled>{{ t('sbo_network.select_a_reason') }}</option>
            <option v-for="r in reportReasons" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <span v-if="reportForm.errors.reason" class="text-xs text-danger">
            {{ reportForm.errors.reason }}
          </span>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="text-xs font-semibold text-[#0f172a]">
            {{ t('sbo_network.anything_else') }} <span class="font-normal text-[#94a3b8]">{{ t('sbo_network.optional') }}</span>
          </span>
          <textarea
            v-model="reportForm.details"
            rows="3"
            class="resize-y rounded-lg border border-[#e2e8f0] bg-white px-3 py-2.5 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
          />
        </label>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
            @click="reportingId = null"
          >
            {{ t('sbo_network.cancel') }}
          </button>
          <button
            type="submit"
            :disabled="reportForm.processing"
            class="rounded-lg bg-danger px-5 py-2.5 text-xs font-semibold text-white disabled:opacity-60"
          >
            {{ reportForm.processing ? t('forgot_password.sending') : t('sbo_network.report') }}
          </button>
        </div>
      </form>
    </div>
  </div>

  <!--
    ========================================= Full-screen composer =========
    A phone only. The inline strip at the bottom of the thread is a button
    that opens this; the draft is the same messageForm.body, so nothing is
    handed across and nothing is lost by closing it.

    `sm:hidden` rather than a width read in JavaScript: the strip that opens it
    is hidden by the same breakpoint, so the two cannot disagree.
  -->
  <div v-if="composerOpen" class="fixed inset-0 z-50 flex flex-col bg-white sm:hidden">
    <header class="flex shrink-0 items-center justify-between gap-3 border-b border-[#e2e8f0] px-4 py-3">
      <button
        type="button"
        class="rounded-lg px-2 py-1 text-sm font-medium text-[#475569]"
        @click="composerOpen = false"
      >
        {{ t('sbo_network.cancel') }}
      </button>

      <p class="min-w-0 flex-1 truncate text-center font-display text-sm font-semibold text-[#0f172a]">
        {{ active?.name }}
      </p>

      <button
        type="button"
        :disabled="!canSend || messageForm.processing"
        class="rounded-lg bg-[#1b8a4e] px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-40"
        @click="send"
      >
        {{ messageForm.processing ? t('forgot_password.sending') : t('action.send') }}
      </button>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
      <!-- What is being answered, carried in from the thread. -->
      <div
        v-if="replyingTo"
        class="flex items-start gap-2 rounded-lg border-l-2 border-[#1b8a4e] bg-[#f8fafc] px-3 py-2"
      >
        <span class="min-w-0 flex-1">
          <span class="block text-2xs font-semibold text-[#1b8a4e]">
            {{ t('chat.replying_to', { name: replyingTo.author ?? '' }) }}
          </span>
          <span class="block truncate text-2xs text-[#64748b]">{{ replyingTo.body }}</span>
        </span>
        <button
          type="button"
          class="grid size-5 shrink-0 place-items-center rounded text-[#64748b]"
          :aria-label="t('chat.cancel_reply')"
          @click="cancelReply"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="size-3" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <ul v-if="messageForm.attachments.length" class="flex flex-wrap gap-2">
        <li
          v-for="(file, i) in messageForm.attachments"
          :key="`sheet-${file.name}-${i}`"
          class="inline-flex max-w-full items-center gap-2 rounded-lg border border-[#e2e8f0] bg-surface-secondary py-1.5 pr-1.5 pl-3"
        >
          <span class="min-w-0 truncate text-2xs font-medium text-[#0f172a]">{{ file.name }}</span>
          <span class="shrink-0 text-2xs text-[#94a3b8]">{{ readableSize(file.size) }}</span>
          <button
            type="button"
            class="grid size-5 shrink-0 place-items-center rounded text-[#64748b]"
            :aria-label="t('chat.remove_attachment', { name: file.name })"
            @click="removeAttachment(i)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="size-3" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
            </svg>
          </button>
        </li>
      </ul>

      <!--
        The whole point of the screen. No Enter-to-send here: on a phone Enter
        is a new line, and a paragraph written in a full-screen field is
        exactly where somebody wants one.

        16px, or Safari zooms the page in on focus and does not zoom back out.
      -->
      <label class="flex min-h-0 flex-1 flex-col">
        <span class="sr-only">{{ t('sbo_network.message') }}</span>
        <textarea
          ref="sheetComposer"
          v-model="messageForm.body"
          :placeholder="t('sbo_network.write_a_message')"
          class="min-h-[40vh] w-full flex-1 resize-none bg-transparent text-base leading-6 text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none"
          @input="growSheet"
        />
      </label>

      <p v-if="messageForm.errors.body" class="text-xs text-danger">{{ messageForm.errors.body }}</p>
      <p v-if="attachmentError" class="text-xs text-danger">{{ attachmentError }}</p>
    </div>

    <!-- Attaching stays available without leaving the screen. -->
    <footer class="flex shrink-0 items-center gap-3 border-t border-[#e2e8f0] px-4 py-3">
      <label
        class="grid size-11 shrink-0 cursor-pointer place-items-center rounded-full border border-[#e2e8f0] text-[#475569]"
        :title="t('chat.attach_a_file')"
      >
        <span class="sr-only">{{ t('chat.attach_a_file') }}</span>
        <input
          type="file"
          multiple
          class="sr-only"
          accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
          @change="pickFiles"
        />
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
          <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </label>

      <span class="min-w-0 flex-1 truncate text-2xs text-[#94a3b8]">
        {{ tc('chat.attachment_count', messageForm.attachments.length) }}
      </span>
    </footer>
  </div>

  <!--
    ============================================== Group Info ==============
    A slide-over rather than a page: it is read alongside the conversation,
    not instead of it.

    There is no "N online". Nothing in the app tracks presence, and a number
    that is always wrong is worse than no number at all.
  -->
  <div
    v-if="infoOpen && groupInfo"
    class="fixed inset-0 z-50 flex justify-end bg-black/40"
    role="dialog"
    aria-modal="true"
    :aria-label="t('chat.group_info')"
    @click.self="infoOpen = false"
  >
    <aside class="flex h-full w-full max-w-md flex-col overflow-hidden bg-surface-secondary">
      <header class="flex shrink-0 items-center gap-3 px-4 py-4">
        <button
          type="button"
          class="grid size-9 place-items-center rounded-full text-[#0f172a] transition-colors hover:bg-border"
          :aria-label="t('common.close')"
          @click="infoOpen = false"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>
        <h2 class="font-display text-lg font-bold text-ink">{{ t('chat.group_info') }}</h2>
      </header>

      <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-6">
        <div class="flex flex-col items-center gap-3 py-6 text-center">
          <span
            class="grid size-28 place-items-center rounded-full bg-[linear-gradient(135deg,#f59e0b,#f97316)] font-display text-4xl font-bold text-white"
            aria-hidden="true"
          >
            {{ infoInitials }}
          </span>
          <div>
            <p class="font-display text-xl font-bold text-ink">{{ groupInfo.name }}</p>
            <p class="mt-1 text-sm text-ink-secondary">
              {{ tc('network.member_count', groupInfo.members) }}
            </p>
          </div>
        </div>

        <!-- Muting silences the bell; it does not leave the room, and the
             message still arrives and still counts as unread. -->
        <div class="flex items-center gap-3 rounded-2xl bg-surface px-4 py-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-5 shrink-0 text-ink-secondary" aria-hidden="true">
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span class="min-w-0 flex-1 text-sm text-ink">{{ t('chat.notifications') }}</span>
          <button
            type="button"
            role="switch"
            :aria-checked="!groupInfo.muted"
            :disabled="muteForm.processing"
            class="relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-50"
            :class="groupInfo.muted ? 'bg-border' : 'bg-[#1b8a4e]'"
            @click="toggleMute"
          >
            <span
              class="absolute top-0.5 size-5 rounded-full bg-white transition-all"
              :class="groupInfo.muted ? 'left-0.5' : 'left-[22px]'"
            />
          </button>
        </div>

        <div class="mt-4 flex gap-1 rounded-full bg-surface p-1">
          <button
            v-for="tab in (['members', 'media', 'files', 'links'] as const)"
            :key="tab"
            type="button"
            class="flex-1 rounded-full px-3 py-2 text-xs font-semibold transition-colors"
            :class="infoTab === tab ? 'bg-[#e0f2fe] text-[#0369a1]' : 'text-ink-secondary hover:bg-surface-secondary'"
            :aria-pressed="infoTab === tab"
            @click="infoTab = tab"
          >
            {{ t(`chat.${tab}`) }}
          </button>
        </div>

        <div class="mt-3 overflow-hidden rounded-2xl bg-surface">
          <!-- Members. The registry leads the list and says what it is. -->
          <ul v-if="infoTab === 'members'" class="divide-y divide-border">
            <li v-for="member in roster" :key="member.sboId" class="flex items-center gap-3 px-4 py-3">
              <span
                class="grid size-10 shrink-0 place-items-center rounded-full bg-[#001529] text-sm font-semibold text-white"
                aria-hidden="true"
              >
                {{ member.name.slice(0, 1).toUpperCase() }}
              </span>
              <span class="min-w-0 flex-1">
                <RouterLink
                  v-if="member.slug"
                  :href="`/business/${member.slug}`"
                  class="block truncate text-sm font-medium text-ink hover:underline"
                >
                  {{ member.name }}
                </RouterLink>
                <span v-else class="block truncate text-sm font-medium text-ink">{{ member.name }}</span>
              </span>
              <span v-if="member.isAdmin" class="shrink-0 text-2xs text-ink-tertiary">
                {{ t('chat.admin') }}
              </span>
              <!-- Same rule as the panel above the thread: the registry can be
                   reached from the roster, members are reached from what they
                   said. -->
              <button
                v-if="member.isAdmin"
                type="button"
                :disabled="adminForm.processing"
                class="shrink-0 rounded-md border border-border px-2.5 py-1 text-2xs font-semibold text-ink transition-colors hover:bg-surface-secondary disabled:opacity-50"
                @click="messageAdmin()"
              >
                {{ t('chat.message_privately') }}
              </button>
              <!-- The owner of a group can take a member out of it. -->
              <button
                v-if="groupInfo.isOwner && !member.isAdmin"
                type="button"
                :disabled="removeMemberForm.processing"
                class="grid size-7 shrink-0 place-items-center rounded-md text-ink-tertiary transition-colors hover:bg-[#fee2e2] hover:text-danger disabled:opacity-50"
                :aria-label="t('sbo_network.remove')"
                :title="t('sbo_network.remove')"
                @click="removeGroupMember(member.sboId)"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                  <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                </svg>
              </button>
            </li>
            <li v-if="!roster.length" class="px-4 py-6 text-center text-xs text-ink-tertiary">
              {{ t('chat.nobody_else_here_yet') }}
            </li>
          </ul>

          <!-- Media, files and links are read back out of the conversation.
               A second index of the same thing is a second thing to keep
               correct, and it would be wrong the first time somebody deleted
               a message. -->
          <div v-else-if="infoTab === 'media'" class="p-3">
            <div v-if="groupInfo.media.length" class="grid grid-cols-3 gap-2">
              <a
                v-for="item in groupInfo.media"
                :key="item.id"
                :href="item.url"
                target="_blank"
                rel="noopener"
                class="aspect-square overflow-hidden rounded-lg bg-surface-secondary"
              >
                <img
                  v-if="item.kind === 'image'"
                  :src="item.url"
                  :alt="item.name"
                  class="size-full object-cover"
                />
                <span v-else class="grid size-full place-items-center text-2xs text-ink-tertiary">
                  ▶
                </span>
              </a>
            </div>
            <p v-else class="py-6 text-center text-xs text-ink-tertiary">{{ t('chat.nothing_shared_yet') }}</p>
          </div>

          <ul v-else-if="infoTab === 'files'" class="divide-y divide-border">
            <li v-for="file in groupInfo.files" :key="file.id">
              <a :href="file.url" class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-5 shrink-0 text-ink-secondary" aria-hidden="true">
                  <path d="M14 3v5h5M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" stroke-linejoin="round" />
                </svg>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm text-ink">{{ file.name }}</span>
                  <span class="block text-2xs text-ink-tertiary">{{ readableSize(file.size) }}</span>
                </span>
              </a>
            </li>
            <li v-if="!groupInfo.files.length" class="px-4 py-6 text-center text-xs text-ink-tertiary">
              {{ t('chat.nothing_shared_yet') }}
            </li>
          </ul>

          <ul v-else class="divide-y divide-border">
            <li v-for="link in groupInfo.links" :key="link.url">
              <a
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block px-4 py-3 transition-colors hover:bg-surface-secondary"
              >
                <span class="block truncate text-sm text-primary">{{ link.url }}</span>
                <span v-if="link.author" class="block truncate text-2xs text-ink-tertiary">{{ link.author }}</span>
              </a>
            </li>
            <li v-if="!groupInfo.links.length" class="px-4 py-6 text-center text-xs text-ink-tertiary">
              {{ t('chat.nothing_shared_yet') }}
            </li>
          </ul>
        </div>

        <!-- A member's own group: the owner manages the roster, everyone else
             can leave. Only under the Members tab, where the roster is. -->
        <div v-if="infoTab === 'members' && groupInfo.kind === 'group'" class="mt-3">
          <template v-if="groupInfo.isOwner">
            <button
              type="button"
              class="flex w-full items-center justify-center gap-2 rounded-2xl bg-surface px-4 py-3 text-sm font-semibold text-primary transition-colors hover:bg-surface-secondary"
              :aria-expanded="addMembersOpen"
              @click="addMembersOpen = !addMembersOpen"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                <path d="M12 5v14M5 12h14" stroke-linecap="round" />
              </svg>
              {{ t('sbo_network.add_members') }}
            </button>

            <div v-if="addMembersOpen" class="mt-2 overflow-hidden rounded-2xl bg-surface">
              <template v-if="addableConnections.length">
                <label
                  v-for="c in addableConnections"
                  :key="c.sboId"
                  class="flex cursor-pointer items-center gap-2.5 border-b border-border px-4 py-2.5 last:border-b-0 hover:bg-surface-secondary"
                >
                  <input
                    type="checkbox"
                    class="size-4 shrink-0 rounded border-border text-[#1b8a4e] focus:ring-focus"
                    :checked="addMembersForm.member_sbo_ids.includes(c.sboId)"
                    @change="toggleAddMember(c.sboId)"
                  />
                  <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ c.name }}</span>
                </label>
                <div class="flex justify-end p-3">
                  <button
                    type="button"
                    :disabled="!addMembersForm.member_sbo_ids.length || addMembersForm.processing"
                    class="rounded-lg bg-[#1b8a4e] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                    @click="addGroupMembers"
                  >
                    {{ t('sbo_network.add_members') }}
                  </button>
                </div>
              </template>
              <p v-else class="px-4 py-4 text-center text-xs text-ink-tertiary">
                {{ t('sbo_network.no_connections_to_add') }}
              </p>
            </div>
          </template>

          <button
            v-else
            type="button"
            :disabled="leaveGroupForm.processing"
            class="flex w-full items-center justify-center rounded-2xl bg-surface px-4 py-3 text-sm font-semibold text-danger transition-colors hover:bg-[#fee2e2] disabled:opacity-50"
            @click="leaveGroup"
          >
            {{ t('sbo_network.leave_group') }}
          </button>
        </div>
      </div>
    </aside>
  </div>
</template>
