<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import { useRegisterUrl } from '@/composables/useRegisterUrl'
import aboutHero from '@/assets/figma/heroes/about-hero.jpg'
import iconTarget from '@/assets/figma/icons/about-target.svg'
import iconEye from '@/assets/figma/icons/about-eye.svg'
import iconShield from '@/assets/figma/icons/about-shield.svg'
import teamStephanie from '@/assets/figma/about/team-stephanie.jpg'
import teamYemisi from '@/assets/figma/about/team-yemisi.jpg'
import teamOlufemi from '@/assets/figma/about/team-olufemi.jpg'
import teamAdeola from '@/assets/figma/about/team-adeola.jpg'
import connectBg from '@/assets/figma/about/connect-bg.jpg'
import iconMail from '@/assets/figma/icons/contact-mail.svg'
import iconPhone from '@/assets/figma/icons/contact-phone.svg'
import iconMapPin from '@/assets/figma/icons/contact-mappin.svg'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* `/register` is guest-only; a signed-in owner needs sbo/listings/new. */
const registerUrl = useRegisterUrl()

defineOptions({ layout: PublicLayout })

/*
 * About — Figma 113:4 "about-page-redesign" (updated).
 *   hero-section 113:19
 *   mission-section 113:32          — foundation + mission/vision/values
 *   licensed-members-section 111:4112 — Loveworld Members, with a Member
 *                                       Directory card
 *   registered-gbos-section 111:4146  — Registered GBOs, with a registry
 *                                       dashboard card
 *   licensed-dos-section 111:4180     — Loveworld SBOs, with an SBO dashboard
 *   team-section 113:153
 *   connect-section 113:184
 *
 * The membership bands no longer carry a stock photo: each is a small mock of
 * the product it describes — a directory of members, a dashboard of a GBO's
 * registration, a dashboard of an SBO's licensing. Built in markup, not an
 * image, so it stays crisp and on-brand.
 *
 * Colours are the SYSTEM brand — the `primary` token (#007035) and `btn-primary`
 * — rather than the design's near-green, so the page matches the rest of the
 * site exactly.
 */

// value-card 113:38 — icons and copy verbatim
const pillars = [
  { title: t('about.our_mission'), body: t('about.to_empower_global_commerce_by_providing_an'), icon: iconTarget },
  { title: t('about.our_vision'), body: t('about.to_become_the_gold_standard_in_sovereign_d'), icon: iconEye },
  { title: t('about.our_values'), body: t('about.built_firmly_on_absolute_compliance_engine'), icon: iconShield },
]

/*
 * Small stroke icons for the feature cards, so no new image assets are needed.
 * One path each, Lucide-shaped.
 */
const ICONS: Record<string, string> = {
  shield: 'M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z M9 12l2 2 4-4',
  building: 'M6 22V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v18 M3 22h18 M9 7h.01M13 7h.01M9 11h.01M13 11h.01M9 15h.01M13 15h.01',
  briefcase: 'M4 8a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2',
  file: 'M14 3v4a1 1 0 0 0 1 1h4 M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z M9 13h6M9 17h6',
}

type Members = {
  type: 'members'
  header: { title: string; sub: string }
  count: string
  members: { initials: string; name: string; kind: string; kyc: string; status: string }[]
}

type Dashboard = {
  type: 'dashboard'
  header: { title: string; sub: string; pill: string }
  items: { icon: keyof typeof ICONS; status: string; title: string; body: string }[]
}

type Band = {
  kicker: string
  title: string
  body: string
  points: string[]
  cta: string
  reverse: boolean
  card: Members | Dashboard
}

const bands: Band[] = [
  {
    kicker: t('about.community'),
    title: t('about.loveworld_members'),
    body: t('about.verified_professional_entities_and_individ'),
    points: [
      'Execute secure multi-lateral supply contracts',
      'Accelerated onboarding with sovereign KYC sync',
      'Seamless corporate directory publication',
    ],
    cta: t('about.become_a_member'),
    reverse: false,
    card: {
      type: 'members',
      header: { title: 'Member Directory', sub: 'Verified entities • KYC status' },
      count: '128 members',
      members: [
        { initials: 'LM', name: 'Loveworld Logistics', kind: 'Entity • Verified KYC', kyc: 'Active', status: 'Trusted' },
        { initials: 'OP', name: 'Operator Alpha', kind: 'Individual • KYC synced', kyc: 'Active', status: 'Trusted' },
      ],
    },
  },
  {
    kicker: t('about.marketplace'),
    title: t('about.registered_gbos'),
    body: t('about.strategic_global_business_organizations_ba'),
    points: [
      'Priority clearance for government and enterprise bids',
      'Continuous automated compliance checking',
      'Expanded capital-raise visibility profiles',
    ],
    cta: t('about.register_gbo'),
    reverse: true,
    card: {
      type: 'dashboard',
      header: { title: 'Registry dashboard', sub: 'GBO registration overview', pill: 'Verified' },
      items: [
        { icon: 'shield', status: 'Active', title: 'Compliance status', body: 'Verified legal, tax, and regulatory compliance for cross-border operations.' },
        { icon: 'building', status: 'Verified', title: 'Organization profile', body: 'Commercial assets and ownership structure verified.' },
        { icon: 'briefcase', status: 'Eligible', title: 'Bid clearance', body: 'Cleared for government and enterprise tenders.' },
        { icon: 'file', status: 'Registered', title: 'Registry status', body: 'Officially listed in the government business registry.' },
      ],
    },
  },
  {
    kicker: t('about.enterprise'),
    title: t('about.loveworld_sbos'),
    body: t('about.leading_strategic_business_organizations_f'),
    points: [
      'Unrestricted access to digital sandbox integration APIs',
      'Unified regional licensing across multiple states',
      'Custom corporate governance compliance protocols',
    ],
    cta: t('about.partner_with_us'),
    reverse: false,
    card: {
      type: 'dashboard',
      header: { title: 'SBO dashboard', sub: 'Enterprise licensing overview', pill: 'Active' },
      items: [
        { icon: 'shield', status: 'Active', title: 'Secure integration', body: 'Full access to protected digital environments enabled.' },
        { icon: 'building', status: 'Licensed', title: 'Regional licensing', body: 'Unified multi-state licensing coverage verified and active.' },
        { icon: 'briefcase', status: 'Compliant', title: 'Governance protocols', body: 'Custom corporate governance compliance protocols in place.' },
        { icon: 'file', status: 'Partnered', title: 'Financial infrastructure', body: 'Dense financial infrastructure network fully operational.' },
      ],
    },
  },
]

// team-member-card 113:159
const team = [
  { name: t('about.stephanie_oforka'), role: t('about.executive_minister'), group: t('about.ministry_of_commerce'), img: teamStephanie },
  { name: t('about.yemisi_kudehinbu'), role: t('about.prime_minister'), group: t('about.loveworld_kingdom'), img: teamYemisi },
  { name: t('about.adeola_phillips'), role: t('about.chairman_parallex_bank'), group: t('about.board_of_directors'), img: teamAdeola },
  { name: t('about.olufemi_bakre'), role: t('about.md_parallex_bank'), group: t('about.strategic_advisory_council'), img: teamOlufemi },
]

// contact-card 113:190
const contacts = [
  { label: t('about.email_registry_support'), value: 'registry@lwcommerce.org', icon: iconMail },
  { label: t('about.call_global_desk'), value: '+234 000 000 0000', icon: iconPhone },
  { label: t('about.headquarters'), value: t('about.oasis_centre_loveworld_city'), icon: iconMapPin },
]
</script>

<template>
  <!-- ========================================== hero-section 113:19 ======= -->
  <section class="relative overflow-hidden bg-[#0a192f]">
    <img :src="aboutHero" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
    <div class="absolute inset-0 bg-[#0a192f]/75" aria-hidden="true" />

    <div class="relative section-x py-10 sm:py-16 xl:py-20">
      <p class="text-2xs font-semibold tracking-wider text-secondary uppercase">
        {{ t('about.about_lw_commerce_registry') }}
      </p>
      <h1 class="mt-3 max-w-3xl font-serif text-4xl font-bold text-white xl:text-5xl">
        {{ t('about.powering_a_trusted_digital_business_econom') }}
      </h1>
      <p class="mt-4 max-w-2xl text-base leading-relaxed text-[#94a3b8]">
        {{ t('about.a_globally_recognized_centralized_registry') }}
      </p>

      <div class="mt-8 flex flex-wrap gap-4">
        <RouterLink :href="registerUrl" class="btn-primary rounded-md px-5 sm:px-7 py-3.5 text-sm font-semibold">
          {{ t('about.join_the_registry') }}
        </RouterLink>
        <RouterLink
          href="/businesses"
          class="rounded-md border-[1.5px] border-white px-5 sm:px-7 py-3.5 text-sm font-semibold text-white hover:bg-white/10"
        >
          {{ t('about.explore_members') }}
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- ======================================= mission-section 113:32 ======= -->
  <section class="flex flex-col items-center gap-12 bg-[#f0f4f8] section-x pt-10 pb-20 xl:gap-[72px] xl:pb-[120px]">
    <header class="flex w-full max-w-[720px] flex-col items-center gap-5 text-center">
      <span class="rounded-full bg-primary/10 px-3.5 py-2 text-xs font-semibold tracking-wide text-primary uppercase">
        {{ t('about.our_foundation') }}
      </span>
      <h2 class="font-serif text-4xl font-bold text-[#0a192f] xl:text-5xl">
        {{ t('about.building_a_trusted_digital_economy') }}
      </h2>
      <p class="text-base leading-relaxed text-[#475569]">
        {{ t('about.we_operate_as_the_digital_backbone_that_ve') }}
      </p>
    </header>

    <ul class="grid w-full max-w-[1120px] gap-6 md:grid-cols-3 [&>*]:min-w-0">
      <li
        v-for="p in pillars"
        :key="p.title"
        class="flex flex-col gap-6 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 shadow-[0px_10px_14px_rgba(15,23,42,0.03)]"
      >
        <span class="grid size-14 place-items-center rounded-2xl bg-primary/10">
          <img :src="p.icon" alt="" class="size-6" aria-hidden="true" />
        </span>
        <div class="flex flex-col gap-3">
          <h3 class="font-serif text-2xl font-bold text-[#0a192f]">{{ p.title }}</h3>
          <p class="text-base leading-relaxed text-[#475569]">{{ p.body }}</p>
        </div>
      </li>
    </ul>
  </section>

  <!-- ============ membership bands 111:4112 / 111:4146 / 111:4180 ========= -->
  <section
    v-for="(b, i) in bands"
    :key="b.title"
    class="section-x py-9 sm:py-14"
    :class="i % 2 === 0 ? 'bg-surface-secondary' : 'bg-white'"
  >
    <div class="grid items-center gap-10 lg:grid-cols-2 [&>*]:min-w-0">
      <!-- Copy -->
      <div :class="b.reverse && 'lg:order-2'">
        <span class="inline-block rounded-full bg-primary/10 px-3.5 py-2 text-xs font-semibold tracking-wide text-primary uppercase">
          {{ b.kicker }}
        </span>
        <h2 class="mt-4 font-serif text-4xl font-bold text-[#0a192f]">{{ b.title }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-ink-secondary">{{ b.body }}</p>

        <ul class="mt-6 space-y-3">
          <li v-for="pt in b.points" :key="pt" class="flex items-start gap-3">
            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="size-3" aria-hidden="true">
                <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span class="text-base leading-relaxed text-[#475569]">{{ pt }}</span>
          </li>
        </ul>

        <div class="mt-7 flex flex-wrap gap-3">
          <RouterLink :href="registerUrl" class="btn-primary rounded-md px-6 py-3 text-xs font-semibold">
            {{ b.cta }}
          </RouterLink>
          <RouterLink
            href="/businesses"
            class="rounded-md border border-border-input bg-white px-6 py-3 text-xs font-semibold text-ink hover:bg-surface-secondary"
          >
            {{ t('about.learn_more') }}
          </RouterLink>
        </div>
      </div>

      <!-- Feature card: a small mock of the thing described. -->
      <div :class="b.reverse && 'lg:order-1'">
        <!-- Member directory -->
        <div
          v-if="b.card.type === 'members'"
          class="rounded-2xl border border-[#e2e8f0] bg-white p-4 shadow-[0px_18px_40px_rgba(15,23,42,0.08)] sm:p-5"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-sm font-bold text-[#0a192f]">{{ b.card.header.title }}</p>
              <p class="mt-0.5 text-2xs text-[#64748b]">{{ b.card.header.sub }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-primary/10 px-2 py-1 text-2xs font-semibold text-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3" aria-hidden="true">
                <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M8.5 12l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              Verified
            </span>
          </div>

          <div data-stagger="quick" class="mt-4 grid gap-3 sm:grid-cols-2">
            <div
              v-for="m in b.card.members"
              :key="m.name"
              class="rounded-xl border border-[#eef2f6] bg-[#fafcfe] p-3"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="grid size-8 place-items-center rounded-full bg-primary/10 text-2xs font-bold text-primary">{{ m.initials }}</span>
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-2.5" aria-hidden="true">
                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M8.5 12l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  Verified
                </span>
              </div>
              <p class="mt-2 text-xs font-semibold text-[#0a192f]">{{ m.name }}</p>
              <p class="text-2xs text-[#64748b]">{{ m.kind }}</p>

              <div class="mt-3 space-y-1.5">
                <div class="flex items-center justify-between">
                  <span class="text-2xs text-[#94a3b8]">KYC</span>
                  <span class="rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary">{{ m.kyc }}</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-2xs text-[#94a3b8]">Status</span>
                  <span class="rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary">{{ m.status }}</span>
                </div>
              </div>
              <p class="mt-3 inline-flex items-center gap-1 text-2xs font-semibold text-primary">
                View profile
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3" aria-hidden="true">
                  <path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </p>
            </div>
          </div>

          <div class="mt-4 flex items-center justify-between border-t border-[#eef2f6] pt-3">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-[#f1f5f9] px-2.5 py-1 text-2xs font-medium text-[#475569]">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-3" aria-hidden="true">
                <path d="M16 20a4 4 0 0 0-8 0M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              {{ b.card.count }}
            </span>
            <span class="text-2xs text-[#94a3b8]">Updated today</span>
          </div>
        </div>

        <!-- Registry / SBO dashboard -->
        <div
          v-else
          class="rounded-2xl border border-[#e2e8f0] bg-white p-4 shadow-[0px_18px_40px_rgba(15,23,42,0.08)] sm:p-5"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-2xs font-semibold tracking-wide text-[#94a3b8] uppercase">{{ b.card.header.title }}</p>
              <p class="mt-0.5 text-base font-bold text-[#0a192f]">{{ b.card.header.sub }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-2xs font-semibold text-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3" aria-hidden="true">
                <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M8.5 12l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              {{ b.card.header.pill }}
            </span>
          </div>

          <div data-stagger="quick" class="mt-4 grid gap-3 sm:grid-cols-2">
            <div
              v-for="item in b.card.items"
              :key="item.title"
              class="rounded-xl border border-[#eef2f6] bg-[#fafcfe] p-3.5"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="grid size-9 place-items-center rounded-lg bg-primary/10 text-primary">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-[18px]" aria-hidden="true">
                    <path :d="ICONS[item.icon]" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-2.5" aria-hidden="true">
                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M8.5 12l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  {{ item.status }}
                </span>
              </div>
              <p class="mt-3 text-xs font-semibold text-[#0a192f]">{{ item.title }}</p>
              <p class="mt-1 text-2xs leading-relaxed text-[#64748b]">{{ item.body }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ========================================== team-section 113:153 ====== -->
  <section class="flex flex-col items-center gap-10 bg-[#f0f4f8] section-x py-12 sm:py-20 xl:gap-14 xl:py-[100px]">
    <header class="flex w-full max-w-[720px] flex-col items-center gap-4 text-center">
      <span class="rounded-full bg-primary/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-primary uppercase">
        {{ t('about.leadership') }}
      </span>
      <h2 class="font-serif text-4xl font-bold text-[#0a192f]">
        {{ t('about.the_people_behind_lw_commerce') }}
      </h2>
      <p class="text-base leading-relaxed text-[#475569]">
        {{ t('about.bringing_together_cross_disciplinary_exper') }}
      </p>
    </header>

    <!-- data-stagger: the cards rise in one after another as the row arrives. -->
    <ul data-stagger class="grid w-full max-w-[1120px] gap-8 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
      <li
        v-for="m in team"
        :key="m.name"
        class="flex flex-col gap-4 overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white"
      >
        <img :src="m.img" :alt="m.name" class="h-[280px] w-full object-cover object-top" />
        <div class="flex flex-col gap-1.5 px-4 pb-5">
          <h3 class="font-display text-lg font-semibold text-[#0a192f]">{{ m.name }}</h3>
          <p class="text-xs font-semibold text-primary">{{ m.role }}</p>
          <p class="text-xs text-[#475569]">{{ m.group }}</p>
        </div>
      </li>
    </ul>
  </section>

  <!-- ======================================= connect-section 113:184 ====== -->
  <section class="relative overflow-hidden bg-[#0a192f]">
    <img :src="connectBg" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
    <div class="absolute inset-0 bg-[rgba(13,18,36,0.55)]" aria-hidden="true" />

    <div class="relative flex flex-col items-center gap-10 section-x py-12 sm:py-20 xl:gap-12 xl:py-[100px]">
      <header class="flex w-full max-w-[720px] flex-col items-center gap-4 text-center">
        <span class="rounded-full bg-primary/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-primary uppercase">
          {{ t('about.get_in_touch') }}
        </span>
        <h2 class="font-serif text-4xl font-bold text-white">{{ t('about.let_s_connect') }}</h2>
        <p class="text-base leading-relaxed text-[#f0f4f8]">
          {{ t('about.whether_you_have_questions_about_licensing') }}
        </p>
      </header>

      <ul class="grid w-full max-w-[1120px] gap-8 md:grid-cols-3 [&>*]:min-w-0">
        <li
          v-for="c in contacts"
          :key="c.label"
          class="flex flex-col gap-4 rounded-2xl border border-white/10 bg-[#172a45] p-5 sm:p-8"
        >
          <span class="grid size-10 place-items-center rounded-md bg-white/[0.06]">
            <img :src="c.icon" alt="" class="size-5" aria-hidden="true" />
          </span>
          <div class="flex flex-col gap-1">
            <p class="text-xs font-semibold tracking-wide text-[#94a3b8] uppercase">{{ c.label }}</p>
            <p class="text-lg font-medium text-white">{{ c.value }}</p>
          </div>
        </li>
      </ul>
    </div>
  </section>

  <BenefitsStrip />
</template>
