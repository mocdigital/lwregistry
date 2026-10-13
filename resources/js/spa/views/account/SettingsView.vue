<script setup lang="ts">
import { computed, reactive, ref, toRaw } from 'vue'
import { Link as RouterLink, router, useForm, usePage } from '@inertiajs/vue3'
import { applyAppearance } from '@/composables/useAppearance'
import { useI18n } from '@/composables/useI18n'
import UiAvatar from '@/components/ui/UiAvatar.vue'

import icUser from '@/assets/figma/settings/user-round.svg'
import icBuilding from '@/assets/figma/icons/sbo-building.svg'
import ListingProfileForm from '@/components/sbo/ListingProfileForm.vue'
import icPaintbrush from '@/assets/figma/settings/paintbrush.svg'
import icCircleX from '@/assets/figma/settings/circle-x.svg'
import icBell from '@/assets/figma/settings/bell.svg'
import icEyeOff from '@/assets/figma/settings/eye-off.svg'
import icTrash from '@/assets/figma/settings/trash-2.svg'
import icSun from '@/assets/figma/settings/sun.svg'
import icMoon from '@/assets/figma/settings/moon.svg'
import icMonitor from '@/assets/figma/settings/monitor.svg'
import icCheck from '@/assets/figma/settings/check.svg'
import icLayoutList from '@/assets/figma/settings/layout-list.svg'
import icLayoutListOff from '@/assets/figma/settings/layout-list-off.svg'
import icMonitorOn from '@/assets/figma/settings/monitor-on.svg'
import icMonitorOff from '@/assets/figma/settings/monitor-off.svg'
import AppLayout from '@/layouts/AppLayout.vue'

const { t, tc, locale, locales, currentLocale } = useI18n()

defineOptions({ layout: AppLayout })

/*
 * Settings — four Figma frames, all named "settings-appearance N":
 *   profile       223:5523  left-column 223:5572 + right-column 223:5666
 *   appearance    223:4990  WorkspaceLayout 223:5001
 *   security      223:5224  WorkspaceLayout 223:5235
 *   notifications 223:5378  WorkspaceLayout 223:5389
 *
 * All four share a HeroSection (breadcrumb + title) over a 280px SidebarTabs
 * rail beside a 16px-radius MainContentCard, gap 32, pad 40/80/64.
 *
 * The rail lists seven destinations but only four are designed, so Privacy,
 * Connected Apps and Delete Account render as the design draws them — present
 * but not routed.
 */

/*
 * The active tab comes from the server, not from parsing the URL. This is the
 * per-screen replacement of the useRoute shim that INTEGRATION.md describes:
 * the controller already knows which tab it rendered, so the screen does not
 * need to re-derive it from path segments.
 */
const tab = computed(() => props.tab || 'profile')

const railTabs = [
  { key: 'profile', label: t('settings.profile'), icon: icUser, to: '/settings/profile' },
  // The business's public page, as opposed to the member's own account above.
  { key: 'about', label: t('settings.about'), icon: icBuilding, to: '/settings/about' },
  { key: 'appearance', label: t('settings.appearance'), icon: icPaintbrush, to: '/settings/appearance' },
  { key: 'security', label: t('settings.password_security'), icon: icCircleX, to: '/settings/security' },
  { key: 'notifications', label: t('settings.notifications'), icon: icBell, to: '/settings/notifications' },
  /*
   * Privacy was a dead rail label — no route, no panel, greyed out beside a
   * "Connected apps" item for an integration surface that does not exist. The
   * label is a real destination now; the other one is gone rather than left
   * standing as a promise.
   */
  { key: 'privacy', label: t('settings.privacy'), icon: icEyeOff, to: '/settings/privacy' },
]

const heroes: Record<string, { title: string; body: string }> = {
  profile: {
    title: t('settings.profile_settings'),
    body: t('settings.update_your_personal_identification_linked'),
  },
  appearance: {
    title: t('settings.appearance_display_settings'),
    body: t('settings.customize_your_visual_experience_theme_pre'),
  },
  security: {
    title: t('settings.password_security'),
    body: t('settings.manage_your_credentials_multi_factor_valid'),
  },
  privacy: {
    title: t('settings.privacy.title'),
    body: t('settings.privacy.body'),
  },
  notifications: {
    title: t('settings.notification_settings'),
    body: t('settings.choose_which_alerts_reach_you_how_often_th'),
  },
  about: {
    title: t('settings.about_your_business'),
    body: t('settings.maintain_the_public_profile_visitors_see_y'),
  },
}
const hero = computed(() => heroes[tab.value] ?? heroes.profile)

/* ---------------------------------------------------------- language --- */

/*
 * Saved immediately rather than with the Save Changes button: the whole page
 * re-renders in the new language, so batching it with unrelated profile edits
 * would mean losing them to the reload.
 */

function switchLanguage(event: Event) {
  const next = (event.target as HTMLSelectElement).value

  if (next === locale.value) return

  router.post('/locale', { locale: next }, { preserveScroll: true, preserveState: false })
}

/* ------------------------------------------------------- danger zone --- */

/*
 * Two gates, because they stop two different mistakes. Typing DELETE stops the
 * accidental click; the password stops someone else acting on an unattended
 * session, which a word printed on the screen does nothing to prevent. The
 * server re-checks both — this only keeps the button from being pressable
 * before either is supplied.
 */
const deactivating = ref(false)
const deactivateForm = useForm({ confirmation: '', password: '' })

const canDeactivate = computed(
  () => deactivateForm.confirmation === 'DELETE' && deactivateForm.password.length > 0,
)

function submitDeactivate() {
  if (!canDeactivate.value) return
  deactivateForm.post('/settings/deactivate')
}

function cancelDeactivate() {
  deactivateForm.reset()
  deactivateForm.clearErrors()
  deactivating.value = false
}

/*
 * About tab, multi-listing owners: the selection is a round trip rather than
 * local state, because the editor needs the other listing's services, hours
 * and gallery, none of which are in the current payload.
 */
function switchListing(event: Event) {
  const listing = (event.target as HTMLSelectElement).value

  router.get('/settings/about', { listing }, { preserveScroll: true, preserveState: false })
}

/* ----------------------------------------------------- appearance 223:5001 */
const themes = [
  { key: 'light', label: t('settings.light_mode'), body: t('settings.ideal_for_bright_offices_and_clear_readabi'), icon: icSun },
  { key: 'dark', label: t('settings.dark_mode'), body: t('settings.optimized_to_reduce_eye_strain_in_dimly_li'), icon: icMoon },
  { key: 'system', label: t('settings.system_default'), body: t('settings.synchronizes_registry_display_automaticall'), icon: icMonitor },
]

// ColorSwatchesGroup 223:5117 — the Figma nodes are named by their own hex
const accents = ['#0d9488', '#3b82f6', '#8b5cf6', '#6366f1', '#f97316', '#f59e0b', '#ec4899', '#10b981']
const fontSizes = [t('settings.small'), t('data.status.medium'), t('settings.large')]

const densities = [
  { key: 'comfortable', label: t('settings.comfortable'), body: t('settings.generous_spacing_designed_for_clean_visibi') },
  { key: 'compact', label: t('settings.compact'), body: t('settings.maximum_information_density_ideal_for_powe') },
]

const rails = [
  { key: 'left', label: t('settings.left_side'), body: t('settings.standard_left_hand_navigation_configuratio') },
  { key: 'right', label: t('settings.right_side_default'), body: t('settings.flips_the_primary_sidebar_tabs_to_the_righ') },
]

// Labels only; the state behind each switch is `prefs`, keyed by name.
const appearanceToggles = [
  { key: 'autoCollapseSidebar', label: t('settings.auto_collapse_sidebar_on_smaller_viewports'), body: t('settings.sidebar_automatically_minimizes_when_the_b') },
]
const a11yToggles = [
  { key: 'reduceMotion', label: t('settings.reduce_motion'), body: t('settings.disables_structural_slide_animations_and_z') },
  { key: 'highContrast', label: t('settings.high_contrast_mode'), body: t('settings.fills_ui_elements_with_elevated_color_rati') },
  { key: 'screenReaderOptimizations', label: t('settings.screen_reader_optimizations'), body: t('settings.forces_assistive_reader_hierarchies_to_pro') },
]

/* -------------------------------------------------------- profile 223:5572 */
type Preferences = {
  appearance: {
    theme: string
    accent: string
    fontSize: string
    density: string
    railSide: string
    toggles: Record<string, boolean>
  }
  notifications: Record<string, boolean>
  privacy: Record<string, boolean>
  regional: { timezone: string | null }
}

const props = defineProps<{
  tab: string
  profile: {
    firstname: string
    lastname: string
    email: string
    phone: string | null
    emailVerified: boolean
    photoUrl: string | null
    initials: string
  }
  /**
   * Appearance and notification settings. Both panels were client-side `ref`s
   * that reset on reload — the screen recorded a preference and forgot it.
   */
  preferences: Preferences
  /** The zones the Timezone select offers, and the one in force. */
  timezones: { group: string; value: string; label: string }[]
  activeTimezone: string
  /**
   * The About tab's payload. Null on every other tab — it costs three
   * eager-loaded relations and a media query, so the controller only builds it
   * where it is rendered. `profile` is null for an account with no listing.
   */
  about: {
    listings: { id: number; name: string }[]
    selectedId: number | null
    profile: {
      sbo: {
        id: number
        slug: string
        locked: { label: string; value: string | null }[]
        description: string | null
        website: string | null
        primary_contact_email: string | null
        phone: string | null
        staff_count: number | null
      }
      branding: { logoUrl: string | null; coverUrl: string | null }
    addresses: {
      id: number
      label: string | null
      address: string | null
      city: string | null
      state: string | null
      country: string | null
      country_code: string | null
    }[]
    countries: string[]
    maxAddresses: number
      services: { id: number; title: string; body: string | null }[]
      hours: {
        day_of_week: number
        day: string
        set: boolean
        is_closed: boolean
        opens_at: string | null
        closes_at: string | null
      }[]
      gallery: {
        collection: string
        label: string
        images: { id: number; thumb: string; name: string }[]
      }[]
      maxImagesPerCollection: number
    } | null
  } | null
  /**
   * Account Completion — real conditions, not a fixed 75%. An unfinished step
   * carries the link that finishes it.
   */
  completion: {
    percent: number
    done: number
    total: number
    headline: string
    steps: { key: string; label: string; done: boolean; href: string | null }[]
  }
  /** The member's own listings, replacing the hardcoded "Kenafly LLC" card. */
  linkedBusinesses: {
    name: string
    reference: string | null
    location: string | null
    status: string
    verified: boolean
    href: string
  }[]
  /** Real values for the Security Overview panel; see SecurityOverview. */
  security: {
    overview: { label: string; value: string; tone: 'good' | 'warn' | 'bad' | 'neutral' }[]
    twoFactor: {
      enabled: boolean
      /** Secret minted but never confirmed — enrolment started and abandoned. */
      pending: boolean
      recoveryCodesRemaining: number
      /** Inline SVG, present only while enrolling. */
      qrSvg: string | null
      secret: string | null
    }
  }
}>()

/*
 * Preference state, saved as it changes.
 *
 * These are switches and swatches, not a form: a Save button under a theme
 * picker that already previews the theme reads as a second, redundant step. The
 * patch is per panel, so flipping one notification does not resend the theme.
 */
const prefs = reactive<Preferences>(structuredClone(toRaw(props.preferences)))

const prefsSaved = ref(false)
let prefsTimer: ReturnType<typeof setTimeout> | undefined

function savePrefs(patch: Record<string, unknown>) {
  // Cast: Inertia types the payload as its own RequestPayload union, which a
  // plain object literal does not widen to.
  router.patch('/settings/preferences', patch as never, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      prefsSaved.value = true
      clearTimeout(prefsTimer)
      prefsTimer = setTimeout(() => (prefsSaved.value = false), 2000)
    },
  })
}

/*
 * Applied as it is clicked, not when the save returns.
 *
 * A theme picker that previews the theme only after a round-trip does not feel
 * like a preview at all — and the save is fire-and-forget, so the member would
 * be looking at the old theme while the new one was being written.
 */
function saveAppearance() {
  applyAppearance(prefs.appearance)
  savePrefs({ appearance: prefs.appearance })
}

function saveNotifications() {
  savePrefs({ notifications: prefs.notifications })
}

/*
 * Privacy saves on the same channel as the other panels. Each switch is read
 * where the visibility actually happens — see App\Support\Privacy — so this is
 * not a stored preference that nothing consults.
 */
function savePrivacy() {
  savePrefs({ privacy: prefs.privacy })
}

const privacyToggles = [
  {
    key: 'listedInDirectory',
    label: t('settings.privacy.listed'),
    body: t('settings.privacy.listed_body'),
  },
  {
    key: 'showContactDetails',
    label: t('settings.privacy.contact'),
    body: t('settings.privacy.contact_body'),
  },
  {
    key: 'acceptConnectionRequests',
    label: t('settings.privacy.connections'),
    body: t('settings.privacy.connections_body'),
  },
  {
    key: 'shareViewIdentity',
    label: t('settings.privacy.view_identity'),
    body: t('settings.privacy.view_identity_body'),
  },
]

/* --------------------------------------------------------- profile photo --- */

/*
 * The avatar drew the initials "KJ" for everybody, and Upload New Photo /
 * Remove were bare <button>s — there was no way to set a photo at all.
 */
const photoInput = ref<HTMLInputElement | null>(null)
const photoForm = useForm<{ photo: File | null }>({ photo: null })

function choosePhoto() {
  photoInput.value?.click()
}

function onPhotoPicked(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return

  photoForm.photo = file
  photoForm.post('/settings/photo', {
    preserveScroll: true,
    // Same input can pick the same file twice otherwise.
    onFinish: () => {
      photoForm.reset()
      if (photoInput.value) photoInput.value.value = ''
    },
  })
}

const removePhotoForm = useForm({})

function removePhoto() {
  if (!props.profile.photoUrl) return
  removePhotoForm.delete('/settings/photo', { preserveScroll: true })
}

/*
 * These four fields were hardcoded to a fictional Kenneth James. They are a
 * real form now, PATCHing settings/profile — the Volt page this screen
 * replaced could already save, so it had to keep working.
 */
const profileForm = useForm({
  firstname: props.profile.firstname,
  lastname: props.profile.lastname,
  email: props.profile.email,
  phone: props.profile.phone ?? '',
})

const profileFields = [
  { key: 'firstname', label: t('settings.first_name') },
  { key: 'lastname', label: t('settings.last_name') },
  { key: 'email', label: t('settings.email_address') },
  { key: 'phone', label: t('settings.phone_number') },
] as const

function saveProfile() {
  profileForm.patch('/settings/profile', { preserveScroll: true })
}

// Password change, PUT settings/password. Mirrors the Volt password page.
const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const newPasswordStrength = computed(() => {
  const v = passwordForm.password
  const score =
    (v.length >= 8 ? 1 : 0) +
    (/[a-z]/.test(v) && /[A-Z]/.test(v) ? 1 : 0) +
    (/\d/.test(v) ? 1 : 0) +
    (/[^A-Za-z0-9]/.test(v) ? 1 : 0)
  return { score, label: ['', t('settings.weak'), t('settings.fair'), t('security.good'), t('settings.strong')][score] || '' }
})

const passwordsMatch = computed(
  () => passwordForm.password_confirmation === passwordForm.password,
)

function savePassword() {
  passwordForm.put('/settings/security', {
    preserveScroll: true,
    onFinish: () => passwordForm.reset(),
  })
}
/*
 * Timezone.
 *
 * It was a text box with "East Africa Time (GMT+3)" typed into it, saving
 * nothing — so a member in Lagos read every deadline, message time and posting
 * stamp in UTC while the field told them otherwise. It is a real preference
 * now; App\Support\Timezones converts on the way to the screen.
 */
function saveTimezone(value: string) {
  prefs.regional.timezone = value || null
  savePrefs({ regional: prefs.regional })
}

/** Grouped by continent, which is how somebody looks for their own. */
const timezoneGroups = computed(() => {
  const groups = new Map<string, { value: string; label: string }[]>()

  for (const zone of props.timezones) {
    if (!groups.has(zone.group)) groups.set(zone.group, [])
    groups.get(zone.group)!.push(zone)
  }

  return [...groups].map(([label, zones]) => ({ label, zones }))
})

/*
 * Offered once, when the saved zone is still the app default and the browser
 * disagrees. Setting it silently would be deciding for them; not offering it
 * leaves most members on the wrong clock because they never open this panel.
 */
const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone
const suggestTimezone = computed(
  () =>
    !props.preferences.regional.timezone &&
    browserTimezone &&
    browserTimezone !== props.activeTimezone &&
    props.timezones.some((z) => z.value === browserTimezone)
      ? browserTimezone
      : null,
)
// Labels only; the switch state is `prefs.notifications`, keyed by name.
const digestToggles = [
  { key: 'dailyOpportunityMatches', label: t('settings.receive_daily_transaction_and_lead_opportu') },
  { key: 'bidRequestUpdates', label: t('settings.email_notifications_when_bid_requests_upda') },
  { key: 'weeklyNewsletter', label: t('settings.weekly_newsletter_from_loveworld_regional') },
]
/*
 * Account Completion and Linked Businesses come from the server.
 *
 * Both were fixed arrays: a permanent 75% with a three-quarter bar, and a
 * "Kenafly LLC · MRN-2026-0001 · Nairobi, Kenya" card shown to every member.
 * A member who had verified nothing saw the same 75% as one who had finished
 * everything, so the panel could not tell anyone what to do next.
 */
/*
 * Security Overview and 2FA.
 *
 * All three rows were fixed strings — every account was told "Two-Factor Auth:
 * ACTIVE" while no second factor existed anywhere in the codebase, which is
 * precisely the reassurance that stops someone turning the real one on. They
 * come from the server now; see SecurityOverview.
 */
const page = usePage()

const toneClass: Record<string, string> = {
  good: 'text-[#15803d]',
  warn: 'text-[#b45309]',
  bad: 'text-[#b91c1c]',
  neutral: 'text-[#1e293b]',
}

const twoFactorEnableForm = useForm({})
const twoFactorConfirmForm = useForm({ code: '' })
const twoFactorDisableForm = useForm({ password: '' })
const recoveryForm = useForm({})

/* Shown once, on the response that mints them — never re-displayed. */
const recoveryCodes = computed<string[] | null>(
  () => (page.props.flash?.recoveryCodes as string[] | undefined) ?? null,
)

const showDisable2fa = ref(false)

function startTwoFactor() {
  twoFactorEnableForm.post('/settings/two-factor', { preserveScroll: true })
}

function confirmTwoFactor() {
  twoFactorConfirmForm.post('/settings/two-factor/confirm', {
    preserveScroll: true,
    onSuccess: () => twoFactorConfirmForm.reset(),
  })
}

function disableTwoFactor() {
  twoFactorDisableForm.delete('/settings/two-factor', {
    preserveScroll: true,
    onSuccess: () => {
      twoFactorDisableForm.reset()
      showDisable2fa.value = false
    },
  })
}

function regenerateRecoveryCodes() {
  recoveryForm.post('/settings/two-factor/recovery-codes', { preserveScroll: true })
}

/* ------------------------------------------------------- security 223:5235 */
const sessions = [
  { device: 'Chrome on MacOS (14.2)', meta: t('settings.lagos_nigeria_102_89_23_14_active_now'), current: true },
  { device: 'Safari on iPhone 15 Pro', meta: t('settings.johannesburg_south_africa_197_245_41_9_18'), current: false },
  { device: 'Firefox on Windows 11', meta: t('settings.london_uk_82_165_112_5_3_days_ago'), current: false },
]

/* -------------------------------------------------- notifications 223:5389 */
const emailNotifs = [
  { key: 'applicationUpdates', label: t('settings.application_updates'), body: t('settings.receive_real_time_progress_reports_queries') },
  { key: 'paymentConfirmations', label: t('settings.payment_confirmations'), body: t('settings.get_billing_invoices_receipt_statements_an') },
  { key: 'networkActivity', label: t('settings.network_activity'), body: t('settings.be_notified_when_local_businesses_query_yo') },
  { key: 'marketingResearch', label: t('settings.marketing_research'), body: t('settings.receive_optional_periodic_surveys_business') },
]
const pushNotifs = [
  { key: 'applicationMilestones', label: t('settings.application_milestones'), body: t('settings.critical_alert_status_updates_when_files_a') },
  { key: 'realTimeAlerts', label: t('settings.real_time_alerts'), body: t('settings.get_instant_browser_flags_during_active_li') },
  { key: 'chatMessages', label: t('settings.chat_messages'), body: t('settings.notify_instantly_upon_support_representati') },
]
const frequencies = [
  { key: 'instant', label: t('settings.instant'), body: t('settings.receive_every_alert_the_moment_it_happens') },
  { key: 'daily', label: t('settings.daily_digest'), body: t('settings.consolidated_summary_delivered_once_daily') },
  { key: 'weekly', label: t('settings.weekly_digest'), body: t('settings.complete_summary_package_sent_every_friday') },
]
const frequency = ref('instant')
</script>

<template>
  <!-- ============================================ HeroSection 223:4991 ==== -->
  <section class="bg-[#0b192c] section-x py-10">
    <nav class="flex items-center gap-2 text-xs" :aria-label="t('settings.breadcrumb')">
      <RouterLink href="/" class="text-[#94a3b8] hover:text-white">{{ t('settings.home') }}</RouterLink>
      <span class="text-[#94a3b8]" aria-hidden="true">/</span>
      <RouterLink href="/account" class="text-[#94a3b8] hover:text-white">{{ t('settings.account') }}</RouterLink>
      <span class="text-[#94a3b8]" aria-hidden="true">/</span>
      <span class="text-white">{{ t('settings.settings') }}</span>
    </nav>
    <h1 class="mt-4 font-serif text-3xl font-bold text-white">{{ hero.title }}</h1>
    <p class="mt-2 max-w-3xl text-sm text-[#94a3b8]">{{ hero.body }}</p>
  </section>

  <!-- ======================================= WorkspaceLayout 223:5001 ===== -->
  <div class="flex flex-col gap-8 bg-surface-secondary section-x pt-10 pb-16 lg:flex-row">
    <!-- SidebarTabs 223:5002 -->
    <nav class="flex w-full shrink-0 flex-col gap-1 lg:w-[280px]" :aria-label="t('settings.settings')">
      <RouterLink
        v-for="t in railTabs"
        :key="t.key"
        :href="t.to"
        class="flex items-center gap-3 rounded-lg px-4 py-3"
        :class="
          tab === t.key
            ? 'border-l-4 border-[#0d9488] bg-[#ccfbf1] font-semibold text-[#0d9488]'
            : 'text-[#1e293b] hover:bg-white'
        "
        :aria-current="tab === t.key ? 'page' : undefined"
      >
        <img :src="t.icon" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1 text-sm">{{ t.label }}</span>
      </RouterLink>

      <span class="my-1 h-px w-full bg-[#e2e8f0]" aria-hidden="true" />

      <span class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm text-[#ef4444]">
        <img :src="icTrash" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
        {{ t('settings.delete_account') }}
      </span>
    </nav>

    <!-- ================================== MainContentCard 223:5039 ======== -->
    <div class="flex min-w-0 flex-1 flex-col gap-8 lg:flex-row lg:items-start">
      <!-- ============================================ Profile 223:5572 ==== -->
      <template v-if="tab === 'profile'">
        <div class="flex min-w-0 flex-1 flex-col gap-8">
          <section class="flex flex-col gap-8 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
            <div class="flex flex-col gap-1.5">
              <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.profile_details') }}</h2>
              <p class="text-sm text-[#475569]">
                {{ t('settings.update_your_personal_identification_across') }}
              </p>
            </div>
            <hr class="border-[#e2e8f0]" />

            <div class="flex flex-wrap items-center gap-5">
              <!-- Was the fixed initials "KJ" for every member. -->
              <UiAvatar
                :src="profile.photoUrl"
                :name="`${profile.firstname} ${profile.lastname}`"
                :initials="profile.initials"
                class="size-20 font-serif text-2xl"
              />

              <div class="flex flex-col gap-2">
                <div class="flex flex-wrap gap-3">
                  <!-- The real input; the designed button drives it. -->
                  <input
                    ref="photoInput"
                    type="file"
                    accept="image/jpeg,image/png"
                    class="hidden"
                    @change="onPhotoPicked"
                  />
                  <button
                    type="button"
                    :disabled="photoForm.processing"
                    class="rounded-lg bg-[#0d9488] px-4 py-2 text-xs font-semibold text-white disabled:opacity-60"
                    @click="choosePhoto"
                  >
                    {{ photoForm.processing ? t('documents.uploading') : t('settings.upload_new_photo') }}
                  </button>
                  <button
                    type="button"
                    :disabled="!profile.photoUrl || removePhotoForm.processing"
                    class="rounded-lg border border-[#e2e8f0] px-4 py-2 text-xs font-semibold text-[#475569] disabled:opacity-40"
                    @click="removePhoto"
                  >
                    {{ t('settings.remove') }}
                  </button>
                </div>
                <p v-if="photoForm.errors.photo" class="text-xs text-danger">
                  {{ photoForm.errors.photo }}
                </p>
                <p v-else class="text-xs text-[#94a3b8]">{{ t('settings.jpg_or_png_max_size_2mb') }}</p>
              </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 [&>*]:min-w-0">
              <label v-for="f in profileFields" :key="f.key" class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ f.label }}</span>
                <input
                  v-model="(profileForm as any)[f.key]"
                  :type="f.key === 'email' ? 'email' : 'text'"
                  class="rounded-lg border bg-white px-4 py-3 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
                  :class="(profileForm.errors as any)[f.key] ? 'border-danger' : 'border-[#e2e8f0]'"
                />
                <span v-if="(profileForm.errors as any)[f.key]" class="text-xs text-danger">
                  {{ (profileForm.errors as any)[f.key] }}
                </span>
              </label>
            </div>

            <!-- Interface language. The header carries a selector too, but
                 this is where people look for a durable preference, and it is
                 saved to the account rather than the session so it follows
                 them between devices. -->
            <label class="flex max-w-sm flex-col gap-1.5">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('language.label') }}</span>
              <select
                :value="locale"
                class="max-w-full rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
                @change="switchLanguage($event)"
              >
                <option v-for="l in locales" :key="l.code" :value="l.code">
                  {{ l.flag }} {{ l.native }}
                </option>
              </select>
              <span class="text-xs text-[#94a3b8]">
                {{ t('settings.applies_across_the_site_and_decides_which') }}
              </span>
            </label>

            <div class="flex items-center gap-3">
              <button
                type="button"
                class="w-fit rounded-lg bg-[#0d9488] px-6 py-3 text-sm font-semibold text-white disabled:opacity-60"
                :disabled="profileForm.processing"
                @click="saveProfile"
              >
                {{ profileForm.processing ? t('common.saving') : t('settings.save_changes') }}
              </button>
              <span v-if="profileForm.recentlySuccessful" class="text-sm text-[#0d9488]">{{ t('settings.saved') }}</span>
            </div>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold text-[#475569]">
                {{ t('settings.bio_professional_headline') }}
              </span>
              <textarea
                rows="3"
                class="resize-y rounded-lg border border-[#e2e8f0] bg-white p-4 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
              >{{ t('settings.director_of_operations_at_kenafly_llc_pass') }}</textarea>
            </label>
          </section>

          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
            <div class="flex flex-col gap-1.5">
              <h2 class="font-display text-xl font-semibold text-[#1e293b]">
                {{ t('settings.linked_business_associations') }}
              </h2>
              <p class="text-sm text-[#475569]">
                {{ t('settings.primary_corporate_entity_associated_with_y') }}
              </p>
            </div>
            <!-- This card read "Kenafly LLC · MRN-2026-0001 · Nairobi, Kenya ·
                 Approved Partner" for every member. It lists their own. -->
            <p
              v-if="!props.linkedBusinesses.length"
              class="rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-5 text-xs text-[#475569]"
            >
              {{ t('settings.no_business_linked_to_this_account_yet') }}
              <RouterLink href="/sbo/listings/new" class="font-semibold text-[#0d9488] hover:underline">
                {{ t('settings.register_one') }}
              </RouterLink>
              {{ t('settings.to_appear_in_the_registry') }}
            </p>

            <RouterLink
              v-for="b in props.linkedBusinesses"
              :key="b.name"
              :href="b.href"
              class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-5 transition-colors hover:border-[#0d9488]"
            >
              <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1e293b]">{{ b.name }}</p>
                <p class="text-xs text-[#475569]">
                  <template v-if="b.reference">{{ t('settings.reference_id', { id: b.reference }) }}</template>
                  <template v-if="b.reference && b.location"> • </template>
                  <template v-if="b.location">{{ b.location }}</template>
                  <template v-if="!b.reference && !b.location">{{ t('settings.registration_in_progress') }}</template>
                </p>
              </div>
              <span
                class="rounded-full px-3 py-1 text-2xs font-semibold"
                :class="b.verified
                  ? 'bg-[#ccfbf1] text-[#0d9488]'
                  : b.status === 'Approved'
                    ? 'bg-[#e0f2fe] text-[#0369a1]'
                    : 'bg-[#fef3c7] text-[#92400e]'"
              >
                {{ b.status }}
              </span>
            </RouterLink>
          </section>

          <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
            <div class="flex flex-col gap-1.5">
              <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.account_preferences') }}</h2>
              <p class="text-sm text-[#475569]">
                {{ t('settings.configure_regional_localized_configuration') }}
              </p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 [&>*]:min-w-0">
              <!--
                Language is `users.locale`, which the header selector already
                writes — a second control for it would be two answers to one
                question, so this states it and points at the one that changes.
              -->
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">
                  {{ t('settings.language_preference') }}
                </span>
                <span
                  class="flex items-center justify-between gap-3 rounded-lg border border-[#e2e8f0] bg-surface-secondary px-4 py-3 text-sm text-[#1e293b]"
                >
                  {{ currentLocale?.native ?? currentLocale?.name }}
                  <span class="text-2xs text-ink-tertiary">{{ t('settings.change_in_header') }}</span>
                </span>
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('settings.timezone') }}</span>
                <select
                  :value="props.preferences.regional.timezone ?? props.activeTimezone"
                  class="max-w-full rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
                  @change="saveTimezone(($event.target as HTMLSelectElement).value)"
                >
                  <optgroup v-for="g in timezoneGroups" :key="g.label" :label="g.label">
                    <option v-for="z in g.zones" :key="z.value" :value="z.value">{{ z.label }}</option>
                  </optgroup>
                </select>
                <span class="text-2xs text-ink-secondary">{{ t('settings.timezone_hint') }}</span>

                <!-- Offered, not applied: setting it silently would be deciding
                     for them, and not offering it leaves most members on the
                     wrong clock because they never open this panel. -->
                <button
                  v-if="suggestTimezone"
                  type="button"
                  class="w-fit text-2xs font-semibold text-primary hover:underline"
                  @click="saveTimezone(suggestTimezone)"
                >
                  {{ t('settings.use_device_timezone', { zone: suggestTimezone }) }}
                </button>
              </label>
            </div>

            <p class="text-xs font-semibold text-[#475569]">{{ t('settings.system_alerts_amp_digest') }}</p>
            <div class="flex flex-col gap-3">
              <label
                v-for="d in digestToggles"
                :key="d.key"
                class="flex items-center justify-between gap-4"
              >
                <span class="text-xs text-[#475569]">{{ d.label }}</span>
                <input
                  v-model="prefs.notifications[d.key]"
                  type="checkbox"
                  class="settings-toggle"
                  @change="saveNotifications"
                />
              </label>
            </div>
          </section>
        </div>

        <!-- right-column 223:5666 -->
        <aside class="flex w-full shrink-0 flex-col gap-6 lg:w-[339px]">
          <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6">
            <div class="flex items-center justify-between gap-3">
              <h2 class="font-display text-base font-semibold text-[#1e293b]">{{ t('settings.account_completion') }}</h2>
              <span class="font-display text-xl font-semibold text-[#0d9488]">
                {{ props.completion.percent }}%
              </span>
            </div>
            <div class="h-2 overflow-hidden rounded bg-[#e2e8f0]">
              <div
                class="h-full rounded bg-[#0d9488] transition-[width] duration-500"
                :style="{ width: props.completion.percent + '%' }"
              />
            </div>
            <p class="text-xs font-semibold text-[#1e293b]">{{ props.completion.headline }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.complete_the_checklist_to_unlock_fully_tru') }}
            </p>
            <ul class="flex flex-col gap-2.5">
              <li v-for="c in props.completion.steps" :key="c.key" class="flex items-center gap-2.5">
                <span
                  class="grid size-4 shrink-0 place-items-center rounded-full"
                  :class="c.done ? 'bg-[#0d9488]' : 'border border-[#e2e8f0] bg-white'"
                >
                  <img v-if="c.done" :src="icCheck" alt="" class="size-2.5" aria-hidden="true" />
                </span>
                <!-- An unfinished step links to the screen that finishes it,
                     which is the only reason to show a checklist. -->
                <RouterLink
                  v-if="!c.done && c.href"
                  :href="c.href"
                  class="text-xs text-[#475569] underline decoration-dotted underline-offset-2 hover:text-[#0d9488]"
                >
                  {{ c.label }}
                </RouterLink>
                <span v-else class="text-xs" :class="c.done ? 'text-[#1e293b]' : 'text-[#94a3b8]'">
                  {{ c.label }}
                </span>
              </li>
            </ul>
          </section>

          <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6">
            <h2 class="font-display text-base font-semibold text-[#1e293b]">{{ t('settings.security_overview') }}</h2>
            <dl class="flex flex-col">
              <div
                v-for="s in props.security.overview"
                :key="s.label"
                class="flex items-center justify-between gap-3 border-b border-[#f1f5f9] py-2.5 last:border-0"
              >
                <dt class="text-xs text-[#475569]">{{ s.label }}</dt>
                <dd class="text-xs font-semibold" :class="toneClass[s.tone]">{{ s.value }}</dd>
              </div>
            </dl>
          </section>

          <section class="flex flex-col gap-3 rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-6">
            <h2 class="font-display text-base font-semibold text-[#1e293b]">{{ t('settings.need_assistance') }}</h2>
            <p class="text-xs text-[#475569]">
              {{ t('settings.our_regional_administrators_are_available') }}
            </p>
            <RouterLink href="/support" class="text-xs font-semibold text-[#0d9488]">
              {{ t('settings.contact_local_administrator') }}
            </RouterLink>
          </section>

          <!-- The button here was inert, and the copy promised a permanent
               delete the app cannot safely perform: sbos.created_by is
               nullOnDelete, so removing the user would leave their approved
               listings public and permanently uneditable. Deactivation is
               reversible and says so. -->
          <section class="flex flex-col gap-3 rounded-2xl border border-[#fecaca] bg-[#fff1f2] p-6">
            <h2 class="font-display text-base font-semibold text-[#ef4444]">{{ t('settings.danger_zone') }}</h2>
            <p class="text-xs text-[#475569]">
              {{ t('settings.deactivating_signs_you_out_and_removes_you') }}
            </p>

            <button
              v-if="!deactivating"
              type="button"
              class="w-fit rounded-lg border border-[#ef4444] px-4 py-2 text-xs font-semibold text-[#ef4444] hover:bg-[#fee2e2]"
              @click="deactivating = true"
            >
              {{ t('settings.deactivate_account') }}
            </button>

            <form v-else class="flex flex-col gap-3" @submit.prevent="submitDeactivate">
              <label class="block">
                <span class="text-xs font-semibold text-[#991b1b]">
                  {{ t('settings.type') }} <strong>DELETE</strong> {{ t('settings.to_confirm') }}
                </span>
                <input
                  v-model="deactivateForm.confirmation"
                  type="text"
                  autocomplete="off"
                  spellcheck="false"
                  placeholder="DELETE"
                  class="mt-1 w-full rounded-md border border-[#fecaca] bg-white px-3 py-2 text-xs text-ink focus:border-[#ef4444] focus:outline-none"
                />
                <span v-if="deactivateForm.errors.confirmation" class="mt-1 block text-2xs text-danger">
                  {{ deactivateForm.errors.confirmation }}
                </span>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-[#991b1b]">{{ t('settings.your_password') }}</span>
                <input
                  v-model="deactivateForm.password"
                  type="password"
                  autocomplete="current-password"
                  class="mt-1 w-full rounded-md border border-[#fecaca] bg-white px-3 py-2 text-xs text-ink focus:border-[#ef4444] focus:outline-none"
                />
                <span v-if="deactivateForm.errors.password" class="mt-1 block text-2xs text-danger">
                  {{ deactivateForm.errors.password }}
                </span>
              </label>

              <div class="flex flex-wrap items-center gap-2">
                <button
                  type="submit"
                  class="rounded-lg bg-[#991b1b] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                  :disabled="!canDeactivate || deactivateForm.processing"
                >
                  {{ deactivateForm.processing ? t('settings.deactivating') : t('settings.deactivate_my_account') }}
                </button>
                <button
                  type="button"
                  class="text-xs font-semibold text-[#475569] hover:underline"
                  @click="cancelDeactivate"
                >
                  {{ t('settings.cancel') }}
                </button>
              </div>
            </form>
          </section>
        </aside>
      </template>

      <!-- ========================================= Appearance 223:5039 ==== -->
      <section
        v-else-if="tab === 'appearance'"
        class="flex min-w-0 flex-1 flex-col gap-8 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8"
      >
        <div class="flex flex-col gap-1.5">
          <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.appearance_settings') }}</h2>
          <p class="text-sm text-[#475569]">
            {{ t('settings.personalize_the_presentation_density_theme') }}
          </p>
        </div>
        <hr class="border-[#e2e8f0]" />

        <!-- ThemeOptionsGroup 223:5048 -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.theme_selection') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.toggle_between_light_dark_or_system_matchi') }}
            </p>
          </div>
          <div class="grid gap-4 md:grid-cols-3 [&>*]:min-w-0">
            <button
              v-for="t in themes"
              :key="t.key"
              type="button"
              class="flex flex-col gap-4 rounded-xl bg-white p-5 text-left"
              :class="
                prefs.appearance.theme === t.key
                  ? 'border-2 border-[#0d9488] drop-shadow-[0px_4px_6px_rgba(13,148,136,0.1)]'
                  : 'border border-[#e2e8f0]'
              "
              :aria-pressed="prefs.appearance.theme === t.key"
              @click="prefs.appearance.theme = t.key; saveAppearance()"
            >
              <span class="flex items-center justify-between">
                <span
                  class="grid place-items-center rounded-lg p-2.5"
                  :class="prefs.appearance.theme === t.key ? 'bg-[#ccfbf1]' : 'bg-[#f8fafc]'"
                >
                  <img :src="t.icon" alt="" class="size-5" aria-hidden="true" />
                </span>
                <span
                  v-if="prefs.appearance.theme === t.key"
                  class="grid place-items-center rounded-full bg-[#0d9488] p-1"
                >
                  <img :src="icCheck" alt="" class="size-2.5" aria-hidden="true" />
                </span>
              </span>

              <!-- ThemePreviewMini 223:5059 -->
              <span
                class="flex h-16 w-full flex-col gap-1.5 rounded-md border border-[#e2e8f0] p-2"
                :class="t.key === 'dark' ? 'bg-[#0f172a]' : 'bg-white'"
                aria-hidden="true"
              >
                <span
                  class="flex h-2.5 items-center justify-between px-1.5"
                  :class="t.key === 'dark' && 'bg-[#1e293b]'"
                >
                  <span class="h-1 w-5 rounded-sm bg-[#0d9488]" />
                  <span class="flex gap-1">
                    <span
                      class="h-1 w-2.5 rounded-[1px]"
                      :class="t.key === 'dark' ? 'bg-[#94a3b8]' : 'bg-[#475569]'"
                    />
                    <span
                      class="h-1 w-2.5 rounded-[1px]"
                      :class="t.key === 'dark' ? 'bg-[#94a3b8]' : 'bg-[#475569]'"
                    />
                  </span>
                </span>
                <span class="flex gap-1.5">
                  <span
                    class="h-[34px] w-[18px] rounded-sm"
                    :class="t.key === 'dark' && 'bg-[#1e293b]'"
                  />
                  <span class="flex flex-1 flex-col gap-1">
                    <span
                      class="h-3.5 w-full rounded"
                      :class="t.key === 'dark' ? 'bg-[#1e293b]' : 'bg-[#e2e8f0]'"
                    />
                    <span
                      class="h-3.5 w-full rounded"
                      :class="t.key === 'dark' ? 'bg-[#1e293b]' : 'bg-[#e2e8f0]'"
                    />
                  </span>
                </span>
              </span>

              <span class="flex flex-col gap-1">
                <span class="font-display text-base font-semibold text-[#1e293b]">{{ t.label }}</span>
                <span class="text-xs leading-snug text-[#475569]">{{ t.body }}</span>
              </span>
            </button>
          </div>
        </div>

        <!-- ColorSwatchesGroup 223:5117 -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.accent_color') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.choose_a_primary_accent_color_for_buttons') }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <button
              v-for="c in accents"
              :key="c"
              type="button"
              class="grid size-9 place-items-center rounded-full"
              :style="{ backgroundColor: c }"
              :class="prefs.appearance.accent === c && 'ring-2 ring-[#0f172a] ring-offset-2'"
              :aria-label="t('settings.accent_named', { name: c })"
              :aria-pressed="prefs.appearance.accent === c"
              @click="prefs.appearance.accent = c; saveAppearance()"
            >
              <img v-if="prefs.appearance.accent === c" :src="icCheck" alt="" class="size-3.5" aria-hidden="true" />
            </button>
          </div>
        </div>

        <!-- SegmentedControl 223:5138 -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.font_size') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.adjust_the_base_typography_scale_across_al') }}
            </p>
          </div>
          <div class="flex w-full max-w-[400px] gap-1 rounded-lg bg-[#f8fafc] p-1">
            <button
              v-for="f in fontSizes"
              :key="f"
              type="button"
              class="flex-1 rounded-md px-4 py-2 text-xs"
              :class="
                prefs.appearance.fontSize === f
                  ? 'bg-white font-semibold text-[#1e293b] drop-shadow-[0px_2px_2px_rgba(0,0,0,0.03)]'
                  : 'text-[#475569]'
              "
              :aria-pressed="prefs.appearance.fontSize === f"
              @click="prefs.appearance.fontSize = f; saveAppearance()"
            >
              {{ f }}
            </button>
          </div>
          <div class="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] p-4">
            <p class="text-xs text-[#475569]">
              {{ t('settings.preview_text_the_quick_brown_fox_jumps_ove') }}
            </p>
          </div>
        </div>

        <!-- DensityGroup 223:5151 -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.layout_density') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.set_the_structural_height_and_spacing_of_s') }}
            </p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
            <button
              v-for="d in densities"
              :key="d.key"
              type="button"
              class="flex items-center gap-4 rounded-xl bg-white p-4 text-left"
              :class="prefs.appearance.density === d.key ? 'border-2 border-[#0d9488]' : 'border border-[#e2e8f0]'"
              :aria-pressed="prefs.appearance.density === d.key"
              @click="prefs.appearance.density = d.key; saveAppearance()"
            >
              <span
                class="grid shrink-0 place-items-center rounded-lg p-2.5"
                :class="prefs.appearance.density === d.key ? 'bg-[#ccfbf1]' : 'bg-[#f8fafc]'"
              >
                <img
                  :src="prefs.appearance.density === d.key ? icLayoutList : icLayoutListOff"
                  alt=""
                  class="size-5"
                  aria-hidden="true"
                />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="text-sm font-semibold text-[#1e293b]">{{ d.label }}</span>
                <span class="text-xs text-[#475569]">{{ d.body }}</span>
              </span>
            </button>
          </div>
        </div>

        <!-- NavRailGroup 223:5172 -->
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.navigation_rail_position') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.decide_where_the_primary_sidebar_links_sho') }}
            </p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
            <button
              v-for="r in rails"
              :key="r.key"
              type="button"
              class="flex items-center gap-4 rounded-xl bg-white p-4 text-left"
              :class="prefs.appearance.railSide === r.key ? 'border-2 border-[#0d9488]' : 'border border-[#e2e8f0]'"
              :aria-pressed="prefs.appearance.railSide === r.key"
              @click="prefs.appearance.railSide = r.key; saveAppearance()"
            >
              <span
                class="grid shrink-0 place-items-center rounded-lg p-2.5"
                :class="prefs.appearance.railSide === r.key ? 'bg-[#ccfbf1]' : 'bg-[#f8fafc]'"
              >
                <img
                  :src="prefs.appearance.railSide === r.key ? icMonitorOn : icMonitorOff"
                  alt=""
                  class="size-5"
                  aria-hidden="true"
                />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="text-sm font-semibold text-[#1e293b]">{{ r.label }}</span>
                <span class="text-xs text-[#475569]">{{ r.body }}</span>
              </span>
            </button>
          </div>
        </div>

        <!-- ToggleRow 223:5190 -->
        <label
          v-for="t in appearanceToggles"
          :key="t.key"
          class="flex items-center justify-between gap-6"
        >
          <span class="flex max-w-[500px] min-w-0 flex-1 flex-col gap-1">
            <span class="text-sm font-semibold text-[#1e293b]">{{ t.label }}</span>
            <span class="text-xs text-[#475569]">{{ t.body }}</span>
          </span>
          <input
            v-model="prefs.appearance.toggles[t.key]"
            type="checkbox"
            class="settings-toggle"
            @change="saveAppearance"
          />
        </label>

        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <p class="font-display text-base text-[#1e293b]">{{ t('settings.accessibility') }}</p>
            <p class="text-xs text-[#475569]">
              {{ t('settings.enhance_your_accessibility_features_to_cus') }}
            </p>
          </div>
          <label
            v-for="t in a11yToggles"
            :key="t.key"
            class="flex items-center justify-between gap-6"
          >
            <span class="flex max-w-[500px] min-w-0 flex-1 flex-col gap-1">
              <span class="text-sm font-semibold text-[#1e293b]">{{ t.label }}</span>
              <span class="text-xs text-[#475569]">{{ t.body }}</span>
            </span>
            <input
            v-model="prefs.appearance.toggles[t.key]"
            type="checkbox"
            class="settings-toggle"
            @change="saveAppearance"
          />
          </label>
        </div>

        <hr class="border-[#e2e8f0]" />
        <div class="flex flex-wrap items-center justify-between gap-4">
          <button type="button" class="text-sm font-semibold text-[#475569] underline">
            {{ t('settings.reset_to_defaults') }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-[#0d9488] px-6 py-3 text-sm font-semibold text-white"
          >
            {{ t('settings.save_preferences') }}
          </button>
        </div>
      </section>

      <!-- ========================================== Security 223:5235 ===== -->
      <div v-else-if="tab === 'security'" class="flex min-w-0 flex-1 flex-col gap-8">
        <section class="rounded-2xl border border-[#a7f3d0] bg-[#ecfdf5] p-6">
          <p class="flex flex-wrap items-center gap-3">
            <span class="font-display text-lg font-semibold text-[#1e293b]">
              {{ t('settings.account_security_status_high') }}
            </span>
            <span class="rounded-full bg-[#0d9488] px-3 py-1 text-2xs font-semibold text-white">
              {{ t('settings.protected') }}
            </span>
          </p>
          <p class="mt-2 text-xs text-[#475569]">
            {{ t('settings.your_credentials_are_secure_and_active_mul') }}
          </p>
        </section>

        <section class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <div class="flex flex-col gap-1.5">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.update_password') }}</h2>
            <p class="text-sm text-[#475569]">
              {{ t('settings.ensure_your_account_uses_a_secure_random_p') }}
            </p>
          </div>

          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#475569]">{{ t('settings.current_password') }}</span>
            <input
              v-model="passwordForm.current_password"
              type="password"
              autocomplete="current-password"
              class="rounded-lg border px-4 py-3 text-sm focus:border-focus focus:outline-none"
              :class="passwordForm.errors.current_password ? 'border-danger' : 'border-[#e2e8f0]'"
            />
            <span v-if="passwordForm.errors.current_password" class="text-xs text-danger">
              {{ passwordForm.errors.current_password }}
            </span>
          </label>

          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#475569]">{{ t('settings.new_password') }}</span>
            <input
              v-model="passwordForm.password"
              type="password"
              autocomplete="new-password"
              :placeholder="t('settings.enter_new_password')"
              class="rounded-lg border px-4 py-3 text-sm placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
              :class="passwordForm.errors.password ? 'border-danger' : 'border-[#e2e8f0]'"
            />
            <!-- The meter read a fixed "Strong" at 80% regardless of input. -->
            <span class="flex items-center justify-between gap-3 pt-1">
              <span class="text-xs text-[#475569]">{{ t('settings.password_strength') }}</span>
              <span class="text-xs font-semibold text-[#0d9488]">{{ newPasswordStrength.label }}</span>
            </span>
            <span class="h-1.5 overflow-hidden rounded bg-[#e2e8f0]">
              <span
                class="block h-full rounded bg-[#0d9488] transition-all"
                :style="{ width: `${newPasswordStrength.score * 25}%` }"
              />
            </span>
            <span v-if="passwordForm.errors.password" class="text-xs text-danger">
              {{ passwordForm.errors.password }}
            </span>
            <span class="text-xs text-[#94a3b8]">
              {{ t('settings.include_uppercase_letters_digits_and_speci') }}
            </span>
          </label>

          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold text-[#475569]">{{ t('settings.confirm_new_password') }}</span>
            <input
              v-model="passwordForm.password_confirmation"
              type="password"
              autocomplete="new-password"
              :placeholder="t('settings.retype_new_password')"
              class="rounded-lg border border-[#e2e8f0] px-4 py-3 text-sm placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
            <span
              v-if="passwordForm.password_confirmation"
              class="text-xs font-semibold"
              :class="passwordsMatch ? 'text-[#0d9488]' : 'text-danger'"
            >
              {{ passwordsMatch ? t('settings.validation_cleared') : t('settings.passwords_do_not_match') }}
            </span>
          </label>

          <button
            type="button"
            class="w-fit rounded-lg bg-[#0d9488] px-6 py-3 text-sm font-semibold text-white disabled:opacity-60"
            :disabled="passwordForm.processing"
            @click="savePassword"
          >
            {{ passwordForm.processing ? t('settings.updating') : t('settings.update_password') }}
          </button>
        </section>

        <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <div class="flex flex-col gap-1.5">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">
              {{ t('settings.two_factor_authentication_2fa') }}
            </h2>
            <p class="text-sm text-[#475569]">
              {{ t('settings.add_an_extra_layer_of_structural_security') }}
            </p>
          </div>
          <!-- This panel used to state "Authenticator App is active" on every
               account, with no 2FA anywhere in the app. It reflects the real
               state now, and can change it. -->

          <!-- Recovery codes: shown once, on the response that mints them. -->
          <div v-if="recoveryCodes" class="rounded-xl border border-[#fcd34d] bg-[#fffbeb] p-4">
            <p class="text-xs font-semibold text-[#92400e]">
              {{ t('settings.save_these_recovery_codes_now_they_are_not') }}
            </p>
            <p class="mt-1 text-2xs text-[#92400e]">
              {{ t('settings.each_one_signs_you_in_once_if_you_lose_you') }}
            </p>
            <ul class="mt-3 grid grid-cols-2 gap-1.5 [&>*]:min-w-0">
              <li
                v-for="c in recoveryCodes"
                :key="c"
                class="rounded bg-white px-2 py-1 text-center font-mono text-2xs tracking-wider text-[#1e293b]"
              >
                {{ c }}
              </li>
            </ul>
          </div>

          <!-- ENABLED -->
          <template v-if="props.security.twoFactor.enabled">
            <div class="rounded-xl border border-[#a7f3d0] bg-[#ecfdf5] p-4">
              <p class="text-xs font-semibold text-[#15803d]">
                {{ t('settings.two_factor_authentication_is_on') }}
              </p>
              <p class="mt-1 text-2xs text-[#475569]">
                {{ t('settings.two_factor_on_hint') }} {{ tc('settings.recovery_codes_remaining', props.security.twoFactor.recoveryCodesRemaining) }}
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <button
                type="button"
                class="rounded-lg border border-[#e2e8f0] px-4 py-2 text-xs font-semibold text-[#1e293b] hover:bg-[#f1f5f9] disabled:opacity-60"
                :disabled="recoveryForm.processing"
                @click="regenerateRecoveryCodes"
              >
                {{ recoveryForm.processing ? t('settings.generating') : t('settings.regenerate_recovery_codes') }}
              </button>
              <button
                v-if="!showDisable2fa"
                type="button"
                class="rounded-lg border border-[#ef4444] px-4 py-2 text-xs font-semibold text-[#ef4444] hover:bg-[#fee2e2]"
                @click="showDisable2fa = true"
              >
                {{ t('settings.turn_off') }}
              </button>
            </div>

            <form v-if="showDisable2fa" class="flex flex-col gap-2" @submit.prevent="disableTwoFactor">
              <label class="block">
                <span class="text-xs font-semibold text-[#991b1b]">
                  {{ t('settings.confirm_your_password_to_turn_off_two_fact') }}
                </span>
                <input
                  v-model="twoFactorDisableForm.password"
                  type="password"
                  autocomplete="current-password"
                  class="mt-1 w-full max-w-sm rounded-md border border-[#fecaca] px-3 py-2 text-xs text-ink focus:border-[#ef4444] focus:outline-none"
                />
                <span v-if="twoFactorDisableForm.errors.password" class="mt-1 block text-2xs text-danger">
                  {{ twoFactorDisableForm.errors.password }}
                </span>
              </label>
              <div class="flex items-center gap-2">
                <button
                  type="submit"
                  class="rounded-lg bg-[#991b1b] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                  :disabled="twoFactorDisableForm.processing || !twoFactorDisableForm.password"
                >
                  {{ twoFactorDisableForm.processing ? t('settings.turning_off') : t('settings.turn_off_2fa') }}
                </button>
                <button
                  type="button"
                  class="text-xs font-semibold text-[#475569] hover:underline"
                  @click="showDisable2fa = false"
                >
                  {{ t('settings.cancel') }}
                </button>
              </div>
            </form>
          </template>

          <!-- ENROLLING: secret minted, not yet proven. 2FA is not in force. -->
          <template v-else-if="props.security.twoFactor.pending">
            <div class="rounded-xl border border-[#bfdbfe] bg-[#eff6ff] p-4">
              <p class="text-xs font-semibold text-[#1d4ed8]">{{ t('settings.finish_setting_up') }}</p>
              <p class="mt-1 text-2xs text-[#475569]">
                {{ t('settings.scan_this_with_any_authenticator_app_googl') }}
              </p>
            </div>

            <div class="flex flex-wrap items-start gap-6">
              <!-- Rendered inline by the server; the secret never leaves the app. -->
              <div class="rounded-xl border border-[#e2e8f0] bg-white p-3" v-html="props.security.twoFactor.qrSvg" />

              <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-[#1e293b]">{{ t('settings.can_t_scan_the_code') }}</p>
                <p class="mt-1 text-2xs text-[#475569]">{{ t('settings.enter_this_key_in_your_app_instead') }}</p>
                <code class="mt-2 block rounded bg-[#f1f5f9] px-3 py-2 font-mono text-2xs break-all text-[#1e293b]">
                  {{ props.security.twoFactor.secret }}
                </code>

                <form class="mt-4 flex flex-col gap-2" @submit.prevent="confirmTwoFactor">
                  <label class="block">
                    <span class="text-xs font-semibold text-[#475569]">{{ t('settings.6_digit_code') }}</span>
                    <input
                      v-model="twoFactorConfirmForm.code"
                      type="text"
                      inputmode="numeric"
                      autocomplete="one-time-code"
                      maxlength="6"
                      placeholder="123456"
                      class="mt-1 w-40 rounded-md border border-border-input px-3 py-2 text-center font-mono tracking-[0.25em] text-ink focus:border-focus focus:outline-none"
                    />
                    <span v-if="twoFactorConfirmForm.errors.code" class="mt-1 block text-2xs text-danger">
                      {{ twoFactorConfirmForm.errors.code }}
                    </span>
                  </label>
                  <button
                    type="submit"
                    class="btn-primary w-fit rounded-lg px-5 py-2 text-xs font-semibold disabled:opacity-60"
                    :data-active="twoFactorConfirmForm.code.length === 6 || undefined"
                    :disabled="twoFactorConfirmForm.processing || twoFactorConfirmForm.code.length !== 6"
                  >
                    {{ twoFactorConfirmForm.processing ? t('settings.verifying') : t('settings.verify_and_turn_on') }}
                  </button>
                </form>
              </div>
            </div>
          </template>

          <!-- OFF -->
          <template v-else>
            <div class="rounded-xl border border-[#fecaca] bg-[#fff1f2] p-4">
              <p class="text-xs font-semibold text-[#b91c1c]">
                {{ t('settings.two_factor_authentication_is_not_set_up') }}
              </p>
              <p class="mt-1 text-2xs text-[#475569]">
                {{ t('settings.your_account_is_protected_by_a_password_al') }}
              </p>
            </div>
            <button
              type="button"
              class="btn-primary w-fit rounded-lg px-5 py-2.5 text-xs font-semibold disabled:opacity-60"
              :disabled="twoFactorEnableForm.processing"
              @click="startTwoFactor"
            >
              {{ twoFactorEnableForm.processing ? t('settings.preparing') : t('settings.set_up_two_factor_authentication') }}
            </button>
          </template>
        </section>

        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <div class="flex flex-col gap-1.5">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.active_sessions') }}</h2>
            <p class="text-sm text-[#475569]">
              {{ t('settings.review_and_manage_devices_currently_logged') }}
            </p>
          </div>
          <ul class="flex flex-col">
            <li
              v-for="s in sessions"
              :key="s.device"
              class="flex flex-wrap items-center justify-between gap-4 border-b border-[#f1f5f9] py-4 last:border-0"
            >
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-3 text-sm font-semibold text-[#1e293b]">
                  {{ s.device }}
                  <span
                    v-if="s.current"
                    class="rounded-full bg-[#ccfbf1] px-2.5 py-0.5 text-2xs font-semibold text-[#0d9488]"
                  >
                    {{ t('settings.current_session') }}
                  </span>
                </p>
                <p class="text-xs text-[#475569]">{{ s.meta }}</p>
              </div>
              <button
                v-if="!s.current"
                type="button"
                class="shrink-0 rounded-lg border border-[#e2e8f0] px-4 py-2 text-xs font-semibold text-[#ef4444] hover:bg-[#fff1f2]"
              >
                {{ t('settings.revoke_access') }}
              </button>
            </li>
          </ul>
        </section>
      </div>


      <!-- ===================================== Notifications 223:5389 ===== -->
      <!-- ============================================== About ============
           The listing-profile editor. Same component and same endpoints as
           /sbo/listings/{id}/edit, so the two cannot drift. -->
      <div v-else-if="tab === 'about'" class="flex min-w-0 flex-1 flex-col gap-6">
        <p
          v-if="$page.props.flash?.status?.startsWith('listing-')"
          class="rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-xs font-semibold text-[#15803d]"
          role="status"
        >
          {{ t('settings.saved_your_public_profile_is_up_to_date') }}
        </p>

        <!-- An account with no listing still has to render Settings. -->
        <section
          v-if="!props.about?.profile"
          class="rounded-2xl border border-border bg-white p-5 sm:p-8 text-center"
        >
          <h2 class="font-display text-lg font-semibold text-ink">{{ t('settings.no_listing_yet') }}</h2>
          <p class="mx-auto mt-2 max-w-md text-xs text-ink-secondary">
            {{ t('settings.register_a_business_and_its_public_profile') }}
          </p>
          <RouterLink
            href="/sbo/listings/new"
            class="btn-primary mt-5 inline-block rounded-md px-6 py-2.5 text-xs font-semibold"
          >
            {{ t('settings.register_a_business') }}
          </RouterLink>
        </section>

        <template v-else>
          <!-- Only shown to an owner who actually holds more than one. -->
          <label v-if="props.about.listings.length > 1" class="block">
            <span class="text-xs font-semibold text-ink-secondary">{{ t('settings.editing') }}</span>
            <select
              :value="props.about.selectedId ?? undefined"
              class="mt-1 w-full max-w-sm rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              @change="switchListing($event)"
            >
              <option v-for="l in props.about.listings" :key="l.id" :value="l.id">
                {{ l.name }}
              </option>
            </select>
          </label>

          <!--
            Keyed on the listing id: the forms seed their state from these
            props once, at setup, so switching listings has to remount them or
            the second business would open showing the first one's answers.
          -->
          <ListingProfileForm
            :key="props.about.profile.sbo.id"
            :sbo="props.about.profile.sbo"
            :branding="props.about.profile.branding"
            :addresses="props.about.profile.addresses"
            :countries="props.about.profile.countries"
            :max-addresses="props.about.profile.maxAddresses"
            :services="props.about.profile.services"
            :hours="props.about.profile.hours"
            :gallery="props.about.profile.gallery"
            :max-images-per-collection="props.about.profile.maxImagesPerCollection"
          />
        </template>
      </div>

      <!-- ============================================== Privacy =========== -->
      <div v-else-if="tab === 'privacy'" class="flex min-w-0 flex-1 flex-col gap-8">
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <div class="flex flex-col gap-1.5">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">
              {{ t('settings.privacy.visibility') }}
            </h2>
            <p class="text-xs text-[#475569]">{{ t('settings.privacy.visibility_body') }}</p>
          </div>

          <label
            v-for="p in privacyToggles"
            :key="p.key"
            class="flex items-center justify-between gap-6"
          >
            <span class="flex max-w-[500px] min-w-0 flex-1 flex-col gap-1">
              <span class="text-sm font-semibold text-[#1e293b]">{{ p.label }}</span>
              <span class="text-xs text-[#475569]">{{ p.body }}</span>
            </span>
            <input
              v-model="prefs.privacy[p.key]"
              type="checkbox"
              class="settings-toggle"
              @change="savePrivacy"
            />
          </label>
        </section>

        <!--
          Turning the directory listing off does not unpublish the page. A
          customer who already holds the link would otherwise hit a 404, which
          is a different decision from "stop appearing in search".
        -->
        <section class="flex flex-col gap-2 rounded-2xl border border-[#e2e8f0] bg-[#f8fafc] p-5 sm:p-8">
          <h2 class="font-display text-base font-semibold text-[#1e293b]">
            {{ t('settings.privacy.what_stays_public') }}
          </h2>
          <p class="text-xs text-[#475569]">{{ t('settings.privacy.what_stays_public_body') }}</p>
        </section>
      </div>

      <div v-else class="flex min-w-0 flex-1 flex-col gap-8">
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.email_notifications') }}</h2>
          <label
            v-for="n in emailNotifs"
            :key="n.key"
            class="flex items-center justify-between gap-6"
          >
            <span class="flex max-w-[500px] min-w-0 flex-1 flex-col gap-1">
              <span class="text-sm font-semibold text-[#1e293b]">{{ n.label }}</span>
              <span class="text-xs text-[#475569]">{{ n.body }}</span>
            </span>
            <input
              v-model="prefs.notifications[n.key]"
              type="checkbox"
              class="settings-toggle"
              @change="saveNotifications"
            />
          </label>
        </section>

        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
          <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.push_notifications') }}</h2>
          <label
            v-for="n in pushNotifs"
            :key="n.key"
            class="flex items-center justify-between gap-6"
          >
            <span class="flex max-w-[500px] min-w-0 flex-1 flex-col gap-1">
              <span class="text-sm font-semibold text-[#1e293b]">{{ n.label }}</span>
              <span class="text-xs text-[#475569]">{{ n.body }}</span>
            </span>
            <input
              v-model="prefs.notifications[n.key]"
              type="checkbox"
              class="settings-toggle"
              @change="saveNotifications"
            />
          </label>
        </section>

        <!-- Two-Column-Row 223:5475 -->
        <div class="grid gap-8 lg:grid-cols-2 [&>*]:min-w-0">
          <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.notification_frequency') }}</h2>
            <label
              v-for="f in frequencies"
              :key="f.key"
              class="flex cursor-pointer items-start gap-3 rounded-xl p-3"
              :class="frequency === f.key ? 'bg-[#ccfbf1]' : 'hover:bg-[#f8fafc]'"
            >
              <input
                v-model="frequency"
                type="radio"
                :value="f.key"
                name="frequency"
                class="mt-0.5 size-[18px] shrink-0 appearance-none rounded-full border-2 border-[#e2e8f0] bg-white checked:border-[6px] checked:border-[#0d9488]"
              />
              <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-sm font-semibold text-[#1e293b]">{{ f.label }}</span>
                <span class="text-xs text-[#475569]">{{ f.body }}</span>
              </span>
            </label>
          </section>

          <section class="flex flex-col gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8">
            <h2 class="font-display text-xl font-semibold text-[#1e293b]">{{ t('settings.quiet_hours') }}</h2>
            <p class="text-xs text-[#475569]">
              {{ t('settings.automatically_silence_unnecessary_push_not') }}
            </p>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('settings.quiet_start') }}</span>
                <input
                  type="text"
                  value="10:00 PM"
                  class="rounded-lg border border-[#e2e8f0] px-4 py-3 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
                />
              </label>
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold text-[#475569]">{{ t('settings.quiet_end') }}</span>
                <input
                  type="text"
                  value="07:00 AM"
                  class="rounded-lg border border-[#e2e8f0] px-4 py-3 text-sm text-[#1e293b] focus:border-focus focus:outline-none"
                />
              </label>
            </div>
            <p class="text-xs text-[#94a3b8]">
              {{ t('settings.high_priority_verification_flags_are_exclu') }}
            </p>
          </section>
        </div>

        <!-- Action-Trigger-Panel 223:5518 -->
        <div
          class="flex flex-wrap items-center justify-end gap-4 rounded-2xl border border-[#e2e8f0] bg-white p-6"
        >
          <button
            type="button"
            class="rounded-lg border border-[#e2e8f0] px-6 py-3 text-sm font-semibold text-[#475569] hover:bg-surface-secondary"
          >
            {{ t('settings.cancel') }}
          </button>
          <button
            type="button"
            class="rounded-lg bg-[#0d9488] px-6 py-3 text-sm font-semibold text-white"
          >
            {{ t('settings.save_preferences') }}
          </button>
        </div>
      </div>


    </div>
  </div>
</template>

<style scoped>
/* ToggleSwitch 223:5194 — 44x24 track, #0d9488 when on, #e2e8f0 when off. */
.settings-toggle {
  appearance: none;
  position: relative;
  width: 44px;
  height: 24px;
  flex-shrink: 0;
  border-radius: 9999px;
  background-color: #e2e8f0;
  cursor: pointer;
  transition: background-color 150ms ease;
}

.settings-toggle::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 9999px;
  background-color: #fff;
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.15);
  transition: transform 150ms ease;
}

.settings-toggle:checked {
  background-color: #0d9488;
}

.settings-toggle:checked::after {
  transform: translateX(20px);
}
</style>
