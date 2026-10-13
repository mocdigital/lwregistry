<script setup lang="ts">
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link as RouterLink, router, usePage } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { useAppUrl } from '@/composables/useAppUrl'
import UiAvatar from '@/components/ui/UiAvatar.vue'
import logo from '@/assets/figma/raw/logo-lw-registry.png'
import LanguageSelector from '@/components/shell/LanguageSelector.vue'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'
import { useUserNotifications } from '@/composables/useEcho'
import { useNotificationSound } from '@/composables/useNotificationSound'

/*
 * Global header — Figma 401:22653 / Group 20 (1280x74).
 * Logo, four centred nav links, a language selector, a "Sign in" text link and
 * a dark "Register Your Business" button.
 *
 * When authenticated the right side swaps to the notification bell
 * (-> 253:8930) and the profile pill (-> 34:4), per the prototype.
 *
 * Mobile bar: Figma HeaderBar 366:16797 (390x56). The drawer is NOT in the
 * designs — no mobile nav was ever drawn, so the pattern is invented.
 */

const props = withDefaults(defineProps<{ authenticated?: boolean }>(), {
  authenticated: false,
})

const page = usePage()

// The pill showed a hardcoded "KJ / James Kenneth / jamesk@james23@yahoo.com"
// to every signed-in user; it reads the shared auth prop now.
const authUser = computed(
  () =>
    page.props.auth?.user as {
      id: number | string
      name: string
      email: string
      initials: string
      adminUrl?: string | null
      ownerWorkspace?: boolean
      /** Uploaded photo; initials stand in when there is none. */
      photoUrl?: string | null
      /** Unread notifications, for the count on the bell. */
      unreadNotifications?: number
      /** False until they have added a business; see hasOwnBusiness. */
      hasBusiness?: boolean
    } | null,
)

/*
 * Dashboard and Network both sit behind the `sbo_manager` middleware, so a
 * signed-in member who has not added a business yet is refused by both. The nav
 * offered them anyway, and clicking Dashboard was a 403. They are replaced by
 * the one destination that does apply: the form that creates the business.
 *
 * Admins are workspace-less by nature and have their own panel link, so they
 * are not sent to add a business either.
 */
const hasOwnBusiness = computed(
  () => authUser.value?.hasBusiness !== false || !!authUser.value?.adminUrl,
)

/*
 * Unread count. Seeded from the server on every page, then nudged up live when
 * a notification arrives over the socket so the badge and the chime stay in
 * step without a reload. The server value is the source of truth — each
 * navigation resets the live nudge back to it.
 */
const liveUnread = ref(0)
const unreadCount = computed(() => (authUser.value?.unreadNotifications ?? 0) + liveUnread.value)
watch(
  () => authUser.value?.unreadNotifications,
  () => {
    liveUnread.value = 0
  },
)

/*
 * A new notification, in real time: sound the chime and bump the badge. Any
 * notification counts as something newly unread; messages are the common one.
 */
const userId = computed(() => authUser.value?.id ?? null)
const { playChime } = useNotificationSound()

useUserNotifications(userId, () => {
  liveUnread.value += 1
  playChime()
})

const route = useRoute()
const appUrl = useAppUrl()
/*
 * The design's header always showed a language control; it had never been
 * built, so the four nav labels and the two CTAs were English literals.
 */
const { t } = useI18n()
const { entityTerm } = useEntityTerm()

const open = ref(false)

/*
 * Nav highlighting. vue-router's `active-class` has no Inertia equivalent —
 * Inertia's Link does not track the current route — so the match is explicit.
 * Prefix matching so `/procurement/12` still lights up "Procurement", with `/`
 * special-cased since every path is prefixed by it.
 */
function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

// Profile dropdown
const menuOpen = ref(false)
const menuRoot = ref<HTMLElement | null>(null)

watch(() => route.fullPath, () => {
  open.value = false
  menuOpen.value = false
})

function onDocClick(e: MouseEvent) {
  if (menuRoot.value && !menuRoot.value.contains(e.target as Node)) menuOpen.value = false
}
function onEsc(e: KeyboardEvent) {
  if (e.key === 'Escape') menuOpen.value = false
}
onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onEsc)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onEsc)
})

/*
 * Sign out. `router.post` rather than a native form submit so the XSRF header
 * goes with it; see the button for why that matters.
 */
const loggingOut = ref(false)

function logout() {
  loggingOut.value = true
  menuOpen.value = false
  router.post(appUrl('/logout'), {}, { onFinish: () => (loggingOut.value = false) })
}

// Quick links in the profile menu
const menuLinks = [
  { label: t('app_header.profile_dashboard'), to: '/profile' },
  { label: t('app_header.my_account'), to: '/account' },
  // Entry point of the registration application flow. Its own breadcrumb reads
  // "Applications > Pending SBO Demo", so the payment screen is where this
  // menu item lands; the rest of the flow chains on from there.
  { label: t('app_header.applications'), to: '/sbo/complete-payment-required' },
  { label: t('app_header.notifications'), to: '/notifications' },
  { label: t('app_header.settings'), to: '/settings/profile' },
  { label: t('app_header.feedback'), to: '/feedback' },
]

/*
 * Nav as drawn in the header 401:22653 / Group 20 — four links only.
 * The wider set (Directory, Categories, Procurement, SBO Network) that the
 * prototype wires from other screens is not in the homepage header, so it is
 * reachable from the page body and the mobile drawer instead.
 */
const guestNav = computed(() => [
  { label: t('nav.home'), to: '/' },
  { label: t('nav.about'), to: '/about' },
  { label: t('nav.support'), to: '/support' },
  { label: t('nav.advertise'), to: '/advertise' },
])

/*
 * Signed-in nav — a genuinely different bar, per the directory header
 * 406:22826 and categories 406:23665: product destinations replace the
 * marketing ones, and it is left-aligned rather than centred.
 */
const authedNav = computed(() => [
  { label: t('nav.directory'), to: '/businesses' },
  { label: t('nav.opportunities'), to: '/procurement' },
  ...(hasOwnBusiness.value
    ? [
        { label: t('nav.network', { entity: entityTerm.value }), to: '/sbo/network' },
        { label: t('nav.dashboard', { entity: entityTerm.value }), to: '/sbo/dashboard' },
      ]
    : [{ label: t('nav.add_your_business'), to: '/sbo/listings/new' }]),
  { label: t('nav.support'), to: '/support' },
  { label: t('nav.advertise'), to: '/advertise' },
])

const primaryNav = computed(() => (props.authenticated ? authedNav.value : guestNav.value))

/*
 * Destinations the drawer adds to whatever the header bar already shows.
 *
 * Filtered against `primaryNav` rather than listed twice: the signed-in bar
 * already carries Opportunities and Network, so an unfiltered list repeated
 * both of them a few rows below themselves.
 */
const drawerExtras = computed(() => {
  const shown = new Set(primaryNav.value.map((item) => item.to))

  return [
    { label: t('nav.categories'), to: '/categories' },
    { label: t('nav.opportunities'), to: '/procurement' },
    // Same rule as the bar: no workspace, no workspace links.
    ...(hasOwnBusiness.value
      ? [
          { label: t('nav.network', { entity: entityTerm.value }), to: '/sbo/network' },
          { label: t('app_header.sbo_dashboard', { entity: entityTerm.value }), to: '/sbo/dashboard' },
        ]
      : [{ label: t('nav.add_your_business'), to: '/sbo/listings/new' }]),
  ].filter((item) => !shown.has(item.to))
})
</script>

<template>
  <header
    class="sticky top-0 z-40 border-b border-border bg-surface/95 backdrop-blur supports-[backdrop-filter]:bg-surface/80"
  >
    <div
      class="container-page flex h-(--header-height-mobile) items-center gap-6 lg:h-(--header-height-desktop)"
    >
      <RouterLink :href="appUrl('/')" class="flex shrink-0 items-center gap-1.5">
        <!-- Smaller on mobile: at h-7 the wordmark pushed the burger off the
             row once the language control and bell were beside it. -->
        <img :src="logo" alt="LW Registry" class="logo-adaptive h-5 w-auto object-contain lg:h-7" />
      </RouterLink>

      <!-- Desktop nav, centred as in the design. -->
      <nav
        class="hidden flex-1 items-center justify-center gap-7 lg:flex"
        :class="!props.authenticated && 'gap-10'"
        :aria-label="t('app_header.primary')"
      >
        <RouterLink
          v-for="item in primaryNav"
          :key="item.to"
          :href="appUrl(item.to)"
          class="text-xs font-medium transition-colors hover:text-ink"
          :class="isActive(item.to) ? 'text-ink' : 'text-ink-secondary'"
        >
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="ml-auto flex items-center gap-2 lg:ml-0 lg:gap-5">
        <!--
          Bell sits in a tinted circle with an unread dot.

          Desktop only. On a phone the row is logo + bell + language + avatar +
          burger, which does not fit; both the bell and the language control
          move into the drawer, where they have room for a real label.
        -->
        <RouterLink
          v-if="props.authenticated"
          :href="appUrl('/notifications')"
          class="relative hidden size-9 place-items-center rounded-full bg-surface-tertiary text-ink-secondary hover:text-ink lg:grid"
          :aria-label="t('app_header.notifications')"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
            <path d="M18 8A6 6 0 1 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <!-- The count, not just a dot. Hidden at zero, capped at 99+. -->
          <span
            v-if="unreadCount"
            class="absolute -top-1 -right-1 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold leading-none text-white ring-2 ring-surface"
          >
            {{ unreadCount > 99 ? '99+' : unreadCount }}
          </span>
        </RouterLink>

        <!-- The language control the design always showed; see LanguageSelector.
             Between the bell and the profile menu, which is the order the header
             reads in. Hidden on mobile — the drawer carries it instead. -->
        <div class="hidden lg:block">
          <LanguageSelector />
        </div>

        <template v-if="props.authenticated">
          <!-- Profile pill + dropdown: initials chip, name over email, chevron. -->
          <div ref="menuRoot" class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-full border border-border py-1 pr-2.5 pl-1 hover:bg-surface-tertiary"
              :aria-expanded="menuOpen"
              aria-haspopup="menu"
              aria-controls="profile-menu"
              @click="menuOpen = !menuOpen"
            >
              <UiAvatar
                :src="authUser?.photoUrl"
                :name="authUser?.name"
                :initials="authUser?.initials"
                class="size-7 text-2xs"
                fallback-class="bg-[#0d5c75] text-white"
              />
              <span class="hidden min-w-0 flex-col text-left leading-tight sm:flex">
                <span class="min-w-0 truncate text-2xs font-semibold text-ink">{{ authUser?.name }}</span>
                <span class="min-w-0 truncate text-2xs text-ink-tertiary">{{ authUser?.email }}</span>
              </span>
              <span
                class="text-2xs text-ink-tertiary transition-transform"
                :class="menuOpen && 'rotate-180'"
                aria-hidden="true"
              >
                ▾
              </span>
            </button>

            <div
              v-show="menuOpen"
              id="profile-menu"
              role="menu"
              class="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-lg border border-border bg-surface shadow-[0px_12px_24px_rgba(15,23,42,0.12)]"
            >
              <div class="border-b border-border-subtle px-4 py-3">
                <p class="min-w-0 truncate text-xs font-semibold text-ink">{{ authUser?.name }}</p>
                <p class="min-w-0 truncate text-2xs text-ink-tertiary">{{ authUser?.email }}</p>
              </div>

              <ul class="py-1">
                <!--
                  Admin panel. A plain <a>, not a RouterLink: /admin is
                  Blade/Livewire, so it needs a full page load rather than an
                  Inertia visit. Only rendered for an admin who also owns an
                  SBO — for anyone else `adminUrl` is null.
                -->
                <li v-if="authUser?.adminUrl">
                  <a
                    :href="authUser.adminUrl"
                    role="menuitem"
                    class="block px-4 py-2.5 text-xs font-semibold text-primary hover:bg-surface-tertiary"
                  >
                    {{ t('app_header.admin_workspace') }}
                  </a>
                </li>
                <li v-if="authUser?.ownerWorkspace">
                  <RouterLink
                    :href="appUrl('/profile')"
                    role="menuitem"
                    class="block px-4 py-2.5 text-xs font-semibold text-primary hover:bg-surface-tertiary"
                  >
                    {{ t('app_header.sbo_owner_workspace', { entity: entityTerm }) }}
                  </RouterLink>
                </li>

                <li v-for="m in menuLinks" :key="m.to">
                  <RouterLink
                    :href="appUrl(m.to)"
                    role="menuitem"
                    class="block px-4 py-2.5 text-xs text-ink-secondary hover:bg-surface-tertiary hover:text-ink"
                  >
                    {{ m.label }}
                  </RouterLink>
                </li>
              </ul>

              <!--
                Laravel owns /logout, so this is a real POST.

                It was a native <form> with no CSRF token, which meant every
                logout was a 419 and the session survived — you stayed signed
                in. (bootstrap/app.php has a TokenMismatchException handler
                meant to cover this, but Laravel converts that exception to a
                generic HttpException before render callbacks are matched, so
                it never fired.)

                Posting through Inertia instead: it sends the X-XSRF-TOKEN
                header from the cookie, which is how every other form on this
                front end already authenticates.
              -->
              <div class="border-t border-border-subtle">
                <button
                  type="button"
                  role="menuitem"
                  :disabled="loggingOut"
                  class="w-full px-4 py-2.5 text-left text-xs font-semibold text-danger hover:bg-surface-tertiary disabled:opacity-60"
                  @click="logout"
                >
                  {{ loggingOut ? t('app_header.signing_out') : t('app_header.log_out') }}
                </button>
              </div>
            </div>
          </div>
        </template>

        <!-- Signed-out: "Sign in" as a plain text link, then the dark
             "Register Your Business" button (header 401:22653). -->
        <template v-else>
          <RouterLink
            :href="appUrl('/login')"
            class="text-xs font-medium text-ink transition-colors hover:text-ink-secondary"
          >
            {{ t('action.sign_in') }}
          </RouterLink>
          <RouterLink
            :href="appUrl('/register')"
            class="hidden rounded-md bg-surface-dark px-4 py-2.5 text-xs font-semibold whitespace-nowrap text-white transition-opacity hover:opacity-90 sm:inline-flex"
          >
            {{ t('action.register') }}
          </RouterLink>
        </template>

        <button
          type="button"
          class="grid size-9 place-items-center rounded-md text-ink-secondary hover:bg-surface-tertiary lg:hidden"
          :aria-expanded="open"
          aria-controls="mobile-nav"
          :aria-label="open ? t('app_header.close_menu') : t('app_header.open_menu')"
          @click="open = !open"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5" aria-hidden="true">
            <path v-if="!open" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
            <path v-else d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Invented: no mobile navigation exists in the Figma file. -->
    <nav
      v-show="open"
      id="mobile-nav"
      class="border-t border-border bg-surface lg:hidden"
      :aria-label="t('app_header.primary_mobile')"
    >
      <ul class="container-page flex flex-col py-2">
        <li v-for="item in primaryNav" :key="item.to">
          <RouterLink
            :href="appUrl(item.to)"
            class="block rounded-md px-2 py-3 text-sm font-medium hover:bg-surface-tertiary hover:text-ink"
            :class="isActive(item.to) ? 'text-ink' : 'text-ink-secondary'"
          >
            {{ item.label }}
          </RouterLink>
        </li>

        <!-- Destinations the prototype reaches from other screens but which the
             designed header bar does not carry. Hidden when the bar already
             carries all of them, so the heading never stands over an empty
             list. -->
        <li v-if="drawerExtras.length" class="mt-1 border-t border-border-subtle pt-1">
          <span class="block px-2 py-2 text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
            {{ t('app_header.browse') }}
          </span>
        </li>
        <li v-for="item in drawerExtras" :key="item.to">
          <RouterLink
            :href="appUrl(item.to)"
            class="block rounded-md px-2 py-3 text-sm font-medium hover:bg-surface-tertiary hover:text-ink"
            :class="isActive(item.to) ? 'text-ink' : 'text-ink-secondary'"
          >
            {{ item.label }}
          </RouterLink>
        </li>
        <li v-if="!props.authenticated" class="px-2 py-3 sm:hidden">
          <RouterLink
            :href="appUrl('/register')"
            class="block rounded-md bg-surface-dark px-4 py-2.5 text-center text-xs font-semibold text-white"
          >
            {{ t('app_header.register_your_business') }}
          </RouterLink>
        </li>

        <!--
          The two controls the header bar gives up on a phone.

          They are not duplicates of the desktop ones — they are the ONLY copies
          below lg, which is why the notification row carries its own unread dot
          and a written label rather than a bare glyph.
        -->
        <li class="mt-1 border-t border-border-subtle pt-1">
          <span class="block px-2 py-2 text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">
            {{ t('app_header.your_account') }}
          </span>
        </li>

        <li v-if="props.authenticated">
          <RouterLink
            :href="appUrl('/notifications')"
            class="flex items-center gap-2.5 rounded-md px-2 py-3 text-sm font-medium hover:bg-surface-tertiary hover:text-ink"
            :class="isActive('/notifications') ? 'text-ink' : 'text-ink-secondary'"
          >
            <span class="relative grid size-8 shrink-0 place-items-center rounded-full bg-surface-tertiary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4" aria-hidden="true">
                <path d="M18 8A6 6 0 1 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              <span
                v-if="unreadCount"
                class="absolute -top-1 -right-1 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold leading-none text-white ring-2 ring-surface"
              >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
              </span>
            </span>
            {{ t('app_header.notifications') }}
          </RouterLink>
        </li>

        <li class="flex items-center justify-between gap-3 px-2 py-3">
          <span class="text-sm font-medium text-ink-secondary">{{ t('language.label') }}</span>
          <!-- Last row in the drawer, so the list opens upward — downward it
               would run past the drawer's edge and be clipped. -->
          <LanguageSelector drop="up" />
        </li>
      </ul>
    </nav>
  </header>
</template>
