<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from '@/composables/useI18n'
import { csrfHeaders } from '@/composables/useCsrf'

const { t } = useI18n()

/*
 * Not on the screens that are themselves a chat.
 *
 * The launcher is pinned bottom-right, which on the SBO Network is exactly
 * where the message composer's Send button sits — a second chat bubble
 * covering the send button of the first. Those screens have their own way to
 * reach the registry ("Message admin"), so there is nothing lost by leaving
 * them alone.
 */
const HIDDEN_ON = ['sbo/SboNetworkView']

const page = usePage()
const available = computed(() => !HIDDEN_ON.includes(String(page.component)))

/*
 * Who we are greeting. A signed-in member is greeted by their first name; a
 * visitor by a friendly stand-in. Just the first name — "Hi Ada", not "Hi Ada
 * Lovelace" — because a greeting uses the name you would be called, not the one
 * on your certificate.
 */
const firstName = computed(() => {
  const name = (page.props.auth as { user?: { name?: string } } | undefined)?.user?.name
  if (!name) return t('chatbot.there')

  return name.trim().split(/\s+/)[0]
})

/*
 * The sales assistant a visitor talks to.
 *
 * It does not answer questions so much as hold a conversation: it greets, asks
 * who it is speaking to, offers a few things to ask about, and answers the
 * rest from the documents an administrator uploaded. What it says and in what
 * order is a blueprint drawn in the admin — this screen only shows what comes
 * back and sends what was said or pressed.
 *
 * Open to guests, because somebody deciding whether to register is exactly who
 * it is for.
 */
type Turn = { id: number; from: 'visitor' | 'assistant'; text: string; answered?: boolean }
type Choice = { label: string; pin: string }

type Reply = {
  messages: { text: string; answered: boolean }[]
  choices: Choice[]
  awaiting: string | null
  finished: boolean
  /** Set once the conversation has been handed to a person. */
  handoff?: string | null
}

type Poll = {
  handoff: string | null
  messages: { id: number; text: string }[]
}

const open = ref(false)
const started = ref(false)
const question = ref('')
const sending = ref(false)
const finished = ref(false)
const turns = ref<Turn[]>([])
const choices = ref<Choice[]>([])
const log = ref<HTMLElement | null>(null)

/*
 * The handoff to a person. Null while the bot is answering; then 'requested'
 * (waiting for someone to join), 'live' (talking to them), 'missed' (nobody was
 * online), or 'ended'. While it is requested or live the widget polls for the
 * agent's replies, because there is no socket to push them.
 */
const handoff = ref<string | null>(null)
const lastAdminId = ref(0)
let pollTimer: ReturnType<typeof setInterval> | null = null

let nextId = 1

/*
 * Plain fetch rather than an Inertia visit: this is a conversation on top of
 * whatever page the visitor is reading, and a visit would take them off it.
 *
 * Most "I could not reach the registry" was never the server being down — it
 * was a stale CSRF token (a guest session that lapsed, or a cached page) coming
 * back 419. Simply retrying does not fix that: Laravel rejects the token
 * BEFORE it reissues the cookie, so the stale token stays stale. So on a 419 we
 * fetch a fresh token — a GET passes the CSRF middleware as a read, which does
 * reissue it — and then retry with it. A 5xx or a dropped connection is a plain
 * blip and just retried.
 */
async function post(url: string, body: Record<string, unknown> = {}): Promise<Reply | null> {
  // Four attempts with a growing wait — up to ~5s of trying. A 5xx is usually
  // not the server being down but a brief squeeze (a deploy rebuilding assets,
  // a spike), which clears in a second or two; riding it out is the difference
  // between the assistant looking broken and just being a moment slow.
  const backoff = [700, 1400, 2500]

  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify(body),
      })

      if (response.ok) return (await response.json()) as Reply

      if (response.status === 419) {
        // Reissue the cookie, then retry with the fresh token this call reads.
        await fetch('/assistant/token', { headers: { Accept: 'application/json' } }).catch(() => {})
        continue
      }

      // Any other 4xx will not change on a retry — a bad request stays bad.
      if (response.status < 500) return null
    } catch {
      // Network error — worth another try.
    }

    if (attempt < backoff.length) await new Promise((resolve) => setTimeout(resolve, backoff[attempt]))
  }

  return null
}

function show(reply: Reply | null) {
  if (!reply) {
    // Said in the assistant's own voice: a status code is not something a
    // visitor can act on.
    turns.value.push({
      id: nextId++,
      from: 'assistant',
      text: t('chatbot.could_not_reach'),
      answered: false,
    })

    return
  }

  for (const message of reply.messages) {
    turns.value.push({ id: nextId++, from: 'assistant', ...message })
  }

  choices.value = reply.choices
  finished.value = reply.finished

  // Handed to a person: remember the state and, if someone is coming or here,
  // start watching for their replies.
  if (reply.handoff !== undefined) handoff.value = reply.handoff
  if (handoff.value === 'requested' || handoff.value === 'live') startPolling()
}

/*
 * While a person is handling the conversation, the agent's replies arrive by
 * poll rather than in the response to something the visitor did — so a loop
 * asks for anything new. It stops itself once the conversation ends.
 */
function startPolling() {
  if (pollTimer) return

  pollTimer = setInterval(pollOnce, 5000)
  void pollOnce()
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = null
}

async function pollOnce() {
  let reply: Poll | null = null

  try {
    const response = await fetch('/assistant/poll', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
      body: JSON.stringify({ after: lastAdminId.value }),
    })
    if (response.ok) reply = (await response.json()) as Poll
  } catch {
    return
  }

  if (!reply) return

  for (const message of reply.messages) {
    lastAdminId.value = Math.max(lastAdminId.value, message.id)
    turns.value.push({ id: nextId++, from: 'assistant', text: message.text, answered: true })
  }

  handoff.value = reply.handoff

  if (reply.messages.length) await scrollDown()

  // Nobody is going to reply to an ended or bot-again conversation.
  if (!handoff.value || handoff.value === 'ended') stopPolling()
}

/*
 * Start again.
 *
 * A conversation that has ended, or gone somewhere the visitor did not mean,
 * is otherwise stuck: the flow remembers where they are, so reopening the
 * panel put them back exactly where they gave up.
 *
 * The transcript is cleared here as well as the position on the server —
 * leaving the old exchange above a fresh greeting reads as the assistant
 * having forgotten mid-conversation.
 */
async function restart() {
  if (sending.value) return

  // Back to the home screen, and tell the server to forget where we were —
  // including any handoff, so a restart truly starts over.
  sending.value = true
  await post('/assistant/restart')

  turns.value = []
  choices.value = []
  finished.value = false
  question.value = ''
  handoff.value = null
  lastAdminId.value = 0
  browsing.value = false
  stopPolling()

  sending.value = false
}

// A widget that keeps polling after it is gone is a leak; stop when it unmounts.
onBeforeUnmount(stopPolling)

/* True once a person is involved — the composer stays, but the bot's own
   controls (skip, choices) do not belong in a human conversation. */
const withPerson = computed(() => handoff.value === 'requested' || handoff.value === 'live')

/*
 * The home screen — the greeting, the three actions and the topics — shows
 * until the conversation actually starts. Once anything has been said, or a
 * person is involved, or it has ended, the transcript takes over.
 */
const homeVisible = computed(
  () => started.value && !turns.value.length && !finished.value && handoff.value === null,
)

/** The extra subjects behind "Browse all topics", revealed in place. */
const browsing = ref(false)

const moreTopics = computed(() => [
  t('chatbot.topic_network'),
  t('chatbot.topic_procurement'),
  t('chatbot.topic_seal'),
  t('chatbot.topic_categories'),
  t('chatbot.topic_advertising'),
  t('chatbot.topic_support'),
])

function start() {
  open.value = true
  started.value = true
  // No greeting fetched: the home screen IS the greeting, and the conversation
  // begins the moment they choose something.
}

/*
 * Ask the bank a question directly. The home screen is actions, not a scripted
 * flow, so a card or a typed line goes straight to an answer rather than
 * through the greeting-and-email preamble.
 */
async function say(text: string) {
  // Not while a send is in flight — but a conversation with a person is exactly
  // when the visitor most needs to type. The server routes their line to the
  // human (and stays quiet rather than letting the bot answer over the top), so
  // the same composer, and the same endpoint, carry the message either way.
  if (sending.value) return

  turns.value.push({ id: nextId++, from: 'visitor', text })
  choices.value = []
  browsing.value = false
  sending.value = true
  await scrollDown()

  show(await post('/assistant/direct', { question: text }))

  sending.value = false
  await scrollDown()
}

function submit() {
  const asked = question.value.trim()
  if (!asked) return

  question.value = ''
  void say(asked)
}

/*
 * Ask for a person, from wherever the conversation is. Not a menu option but a
 * standing offer — someone who wants a human should not have to find the button
 * for it — so it posts straight to the handoff rather than walking the flow.
 */
async function talkToPerson() {
  if (sending.value || withPerson.value) return

  turns.value.push({ id: nextId++, from: 'visitor', text: t('chatbot.talk_to_person') })
  choices.value = []
  sending.value = true
  await scrollDown()

  show(await post('/assistant/handoff'))

  sending.value = false
  await scrollDown()
}

/*
 * A face for each choice.
 *
 * The blueprint hands back a label and a pin, nothing more — so the icon is
 * inferred from what the label is about rather than stored against it, which
 * keeps a new topic from needing an artwork decision before it can appear.
 * Everything unrecognised gets the speech-bubble, which reads as "ask about
 * this" and is never wrong.
 */
const ICON_PATHS: Record<string, string> = {
  doc: 'M14 3v4a1 1 0 0 0 1 1h4 M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z',
  card: 'M3 10h18 M5 6h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z',
  shield: 'M12 3l7 3v5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6l7-3z M9.3 12l1.8 1.8 3.6-3.6',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M5 20a7 7 0 0 1 14 0',
  briefcase: 'M4 7h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2',
  help: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .9-1 1.7 M12 17h.01',
  chat: 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z',
}

/*
 * The three things most people open the assistant to do. Fixed, because a home
 * screen is a front door, not a search result — these are the doors, and
 * everything else is behind "browse all topics". Each opens by asking the bank
 * the question it stands for.
 */
const homeActions = computed(() => [
  {
    key: 'register',
    title: t('chatbot.action_register_title'),
    subtitle: t('chatbot.action_register_sub'),
    icon: ICON_PATHS.doc,
    question: t('chatbot.q_register'),
  },
  {
    key: 'fees',
    title: t('chatbot.action_fees_title'),
    subtitle: t('chatbot.action_fees_sub'),
    icon: ICON_PATHS.card,
    question: t('chatbot.q_fees'),
  },
  {
    key: 'verify',
    title: t('chatbot.action_verify_title'),
    subtitle: t('chatbot.action_verify_sub'),
    icon: ICON_PATHS.shield,
    question: t('chatbot.q_verify'),
  },
])

async function scrollDown() {
  await nextTick()
  if (log.value) log.value.scrollTop = log.value.scrollHeight
}
</script>

<template>
  <!-- Launcher. Bottom-right, out of the reading column. -->
  <button
    v-if="available && !open"
    type="button"
    class="fixed right-4 bottom-4 z-40 inline-flex items-center gap-2 rounded-full bg-surface-dark px-5 py-3 text-xs font-semibold text-white shadow-lg transition-opacity hover:opacity-90"
    @click="start"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
      <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
    {{ t('chatbot.ask_us') }}
  </button>

  <!-- Slides up from below when opened, and back down when closed. Slow on the
       way in so it arrives rather than snaps; a touch quicker on the way out. -->
  <Transition
    enter-active-class="transition-all duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]"
    enter-from-class="translate-y-full opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="transition-all duration-[400ms] ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="translate-y-full opacity-0"
  >
  <section
    v-if="available && open"
    class="fixed inset-0 z-40 flex h-dvh w-full flex-col overflow-hidden border-border bg-surface shadow-2xl sm:inset-auto sm:right-4 sm:bottom-4 sm:h-[min(600px,80dvh)] sm:w-[min(380px,calc(100vw-2rem))] sm:rounded-2xl sm:border"
    :aria-label="t('chatbot.ask_us')"
  >
    <header class="flex shrink-0 items-center gap-3 bg-surface-dark px-4 py-3.5">
      <!-- The assistant, given a face: a robot mark in a teal disc with a live
           dot, so the panel reads as somebody to talk to rather than a form. -->
      <span class="relative grid size-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-[#14b8a6] to-[#0d9488]">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.7" class="size-5" aria-hidden="true">
          <rect x="5" y="8" width="14" height="11" rx="2.5" stroke-linejoin="round" />
          <path d="M12 5v3 M9 13h.01 M15 13h.01 M9.5 16h5" stroke-linecap="round" />
          <circle cx="12" cy="4" r="1.3" fill="#fff" stroke="none" />
        </svg>
        <span class="absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-surface-dark bg-[#22c55e]" aria-hidden="true" />
      </span>

      <div class="min-w-0 flex-1">
        <p class="truncate font-display text-sm font-semibold text-white">{{ t('chatbot.title') }}</p>
        <p class="truncate text-2xs text-white/70">{{ t('chatbot.subtitle') }}</p>
      </div>
      <!-- Beside Close, because that is where somebody looks when a
           conversation has gone wrong. -->
      <button
        type="button"
        class="grid size-7 shrink-0 place-items-center rounded-lg text-white/80 transition-colors hover:bg-white/10 disabled:opacity-40"
        :disabled="sending || !started"
        :title="t('chatbot.restart')"
        :aria-label="t('chatbot.restart')"
        @click="restart"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
          <path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>

      <button
        type="button"
        class="grid size-7 shrink-0 place-items-center rounded-lg text-white/80 transition-colors hover:bg-white/10"
        :aria-label="t('common.close')"
        @click="open = false"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
          <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
        </svg>
      </button>
    </header>

    <div ref="log" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-surface-secondary p-4">
      <!-- The home screen: a greeting, three things most people came to do, a
           way to everything else, and the standing shortcuts. Shown until the
           conversation begins. -->
      <div v-if="homeVisible" class="space-y-5 p-1">
        <div class="space-y-2">
          <h2 class="font-display text-2xl font-bold text-ink">{{ t('chatbot.hi_name', { name: firstName }) }} 👋</h2>
          <p class="text-sm leading-relaxed text-ink-secondary">{{ t('chatbot.home_intro') }}</p>
        </div>

        <div class="space-y-3">
          <button
            v-for="action in homeActions"
            :key="action.key"
            type="button"
            class="group flex w-full items-center gap-3.5 rounded-2xl border border-border bg-surface px-4 py-3.5 text-left shadow-sm transition-colors hover:border-primary hover:bg-surface-secondary"
            @click="say(action.question)"
          >
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-[22px]" aria-hidden="true">
                <path :d="action.icon" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-display text-base font-semibold text-ink">{{ action.title }}</span>
              <span class="block text-xs text-ink-tertiary">{{ action.subtitle }}</span>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4 shrink-0 text-ink-tertiary transition-transform group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true">
              <path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
        </div>

        <!-- Everything else lives behind this, revealed in place. -->
        <button
          type="button"
          class="flex w-full items-center justify-center gap-1.5 text-sm font-semibold text-primary transition-opacity hover:opacity-80"
          @click="browsing = !browsing"
        >
          {{ t('chatbot.browse_all_topics') }}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4 transition-transform" :class="browsing && 'rotate-90'" aria-hidden="true">
            <path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>

        <div v-if="browsing" class="flex flex-wrap gap-2">
          <button
            v-for="topic in moreTopics"
            :key="topic"
            type="button"
            class="rounded-full border border-border bg-surface px-3 py-1.5 text-2xs font-medium text-ink transition-colors hover:border-primary hover:text-primary"
            @click="say(topic)"
          >
            {{ topic }}
          </button>
        </div>

        <!-- The standing shortcuts. "Talk to a person" is always here. -->
        <div class="flex flex-wrap gap-2 pt-1">
          <button
            type="button"
            class="rounded-full border border-border bg-surface px-4 py-2 text-2xs font-semibold text-ink transition-colors hover:border-primary hover:text-primary"
            @click="talkToPerson"
          >
            {{ t('chatbot.talk_to_person') }}
          </button>
          <button
            type="button"
            class="rounded-full border border-border bg-surface px-4 py-2 text-2xs font-semibold text-ink transition-colors hover:border-primary hover:text-primary"
            @click="say(t('chatbot.q_account'))"
          >
            {{ t('chatbot.my_account') }}
          </button>
          <button
            type="button"
            class="rounded-full border border-border bg-surface px-4 py-2 text-2xs font-semibold text-ink transition-colors hover:border-primary hover:text-primary"
            @click="say(t('chatbot.q_faqs'))"
          >
            {{ t('chatbot.faqs') }}
          </button>
        </div>
      </div>

      <div v-for="turn in turns" :key="turn.id" class="flex" :class="turn.from === 'visitor' && 'justify-end'">
        <p
          class="max-w-[85%] rounded-2xl px-3.5 py-2.5 text-xs leading-relaxed whitespace-pre-line"
          :class="
            turn.from === 'visitor'
              ? 'rounded-br-sm bg-surface-dark text-white'
              : turn.answered === false
                ? 'rounded-bl-sm border border-border bg-surface text-ink-secondary'
                : 'rounded-bl-sm bg-surface text-ink'
          "
        >
          {{ turn.text }}
        </p>
      </div>

      <p v-if="sending" class="text-2xs text-ink-tertiary">{{ t('chatbot.looking') }}</p>

      <!-- Waiting for a person to pick the conversation up. A quiet pulsing
           line, not a spinner: it reassures without implying the page is busy. -->
      <p
        v-if="handoff === 'requested'"
        class="flex items-center gap-2 text-2xs text-ink-tertiary"
      >
        <span class="size-1.5 animate-pulse rounded-full bg-primary" aria-hidden="true" />
        {{ t('chatbot.waiting_for_agent') }}
      </p>

      <!-- Related questions to tap after an answer — quick replies the visitor
           can pick instead of typing, each answered from the bank. Hidden while
           a send is in flight or a person is handling the conversation. -->
      <div v-if="choices.length && !sending && !withPerson && !finished" class="flex flex-col items-start gap-2">
        <button
          v-for="choice in choices"
          :key="choice.label"
          type="button"
          class="w-fit max-w-[90%] rounded-2xl border border-border bg-surface px-3.5 py-2 text-left text-xs font-medium text-primary transition-colors hover:border-primary hover:bg-surface-secondary"
          @click="say(choice.label)"
        >
          {{ choice.label }}
        </button>
      </div>
    </div>

    <!-- A standing offer to reach a person during a conversation. Not on the
         home screen, which has its own "Talk to a person", nor once a person is
         already involved. -->
    <button
      v-if="started && !withPerson && !finished && !homeVisible"
      type="button"
      class="flex shrink-0 items-center justify-center gap-1.5 border-t border-border py-2 text-2xs font-semibold text-ink-secondary transition-colors hover:bg-surface-secondary hover:text-primary disabled:opacity-50"
      :disabled="sending"
      @click="talkToPerson"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-3.5" aria-hidden="true">
        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M5 20a7 7 0 0 1 14 0" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      {{ t('chatbot.talk_to_person') }}
    </button>

    <div v-if="finished" class="flex shrink-0 items-center justify-between gap-3 border-t border-border px-4 py-3">
      <span class="min-w-0 text-2xs text-ink-tertiary">{{ t('chatbot.conversation_ended') }}</span>
      <!-- The end of a conversation is exactly when somebody wants another. -->
      <button
        type="button"
        class="shrink-0 rounded-lg border border-border px-3 py-1.5 text-2xs font-semibold text-ink transition-colors hover:bg-surface-secondary"
        @click="restart"
      >
        {{ t('chatbot.start_again') }}
      </button>
    </div>

    <form v-else class="flex shrink-0 items-center gap-2 border-t border-border p-3" @submit.prevent="submit">
      <label class="min-w-0 flex-1">
        <span class="sr-only">{{ t('chatbot.your_question') }}</span>
        <input
          v-model="question"
          type="text"
          maxlength="500"
          :placeholder="t('chatbot.type_message')"
          class="w-full rounded-full border border-border-input bg-surface px-4 py-2.5 text-xs text-ink placeholder:text-ink-tertiary focus:border-focus focus:outline-none"
        />
      </label>

      <!-- Circular send, the arrow leaving up and to the right: a send, not a
           form submit. -->
      <button
        type="submit"
        :disabled="sending || !question.trim()"
        class="btn-primary grid size-10 shrink-0 place-items-center rounded-full disabled:opacity-50"
        :aria-label="t('action.send')"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
          <path d="M7 17L17 7M9 7h8v8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </form>
  </section>
  </Transition>
</template>
