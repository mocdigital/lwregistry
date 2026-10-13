<script setup lang="ts">
import { ref } from 'vue'
import { Link as RouterLink } from '@inertiajs/vue3'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import heroCheckCircle from '@/assets/figma/icons/hero-check-circle.svg'
import heroDotActive from '@/assets/figma/icons/hero-dot-active.svg'
import heroDotIdle from '@/assets/figma/icons/hero-dot-idle.svg'
import stepCheckCircle from '@/assets/figma/icons/step-check-circle.svg'
import stepArrowCircle from '@/assets/figma/icons/step-arrow-circle.svg'
import stepCircleDot from '@/assets/figma/icons/step-circle-dot.svg'
import dashDollar from '@/assets/figma/icons/dash-dollar.svg'
import dashChevron from '@/assets/figma/icons/dash-chevron.svg'
import dashCheck from '@/assets/figma/icons/dash-check.svg'
import AppLayout from '@/layouts/AppLayout.vue'
import AdSlot from '@/components/ads/AdSlot.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: AppLayout })

/*
 * Application dashboard — Figma 33:4 (1280x2128).
 *   hero-section 33:30 (368)      — VIDEO fill, breadcrumb, amber progress tracker
 *   workspace-left 33:61 (500)    — Your Application + Timeline Tracking
 *   workspace-right 33:110 (588)  — Registration Fee Calculator
 *
 * NOTE the accent here is AMBER (#f59e0b), not green: the hero progress bar,
 * the "25% complete" run-in, and the active timeline step all use it. The Pay
 * Fee Now button is #0d9488 teal in this frame, unlike the payment screen's
 * green — consistent with primary resting inactive.
 *
 * 253:7678 is the post-payment state of this same screen.
 */

const shareStructures = [
  'No shares / limited by guarantee',
  'Private company (shares issued privately)',
  'Public company (shares issued publicly)',
  'Sole proprietor / partnership',
]
const structure = ref(shareStructures[0])

// hero step-nodes 33:46
const heroSteps = [
  { label: t('dashboard.step_1_submitted'), icon: heroCheckCircle, cls: 'text-white font-semibold' },
  { label: t('dashboard.step_2_fees_payment'), icon: heroDotActive, cls: 'text-[#f59e0b] font-semibold' },
  { label: t('dashboard.step_3_documents'), icon: heroDotIdle, cls: 'text-[#94a3b8]' },
  { label: t('dashboard.step_4_verification'), icon: heroDotIdle, cls: 'text-[#94a3b8]' },
]

const application = [
  { label: t('dashboard.company_name'), value: t('dashboard.kemefy_llc') },
  { label: t('dashboard.application_id'), value: t('dashboard.mrn_2026_0004') },
  { label: t('dashboard.submitted_date'), value: t('dashboard.july_25_2025') },
  { label: t('dashboard.entity_type'), value: t('dashboard.sbo_spirit_led_business') },
]

// timeline-steps 33:83
const timeline = [
  {
    title: t('dashboard.step_1_application_submitted'),
    body: t('dashboard.provisional_sbo_details_verified'),
    tag: 'Done',
    icon: stepCheckCircle,
    card: 'border-[#e2e8f0] bg-white',
    titleCls: 'font-semibold text-[#64748b]',
    tagCls: 'font-semibold text-[#007035]',
  },
  {
    title: t('dashboard.step_2_registration_fees'),
    body: t('dashboard.pay_the_calculated_50_espees'),
    tag: 'Active',
    icon: stepArrowCircle,
    card: 'border-[#f59e0b] bg-[#fef3c7]',
    titleCls: 'font-semibold text-[#f59e0b]',
    tagCls: 'font-semibold text-[#f59e0b]',
  },
  {
    title: t('dashboard.step_3_required_documents'),
    body: t('dashboard.pending_document_uploads'),
    tag: '',
    icon: stepCircleDot,
    card: 'border-[#e2e8f0] bg-white',
    titleCls: 'font-semibold text-[#1e293b]',
    tagCls: '',
  },
  {
    title: t('dashboard.step_4_verified_status'),
    body: t('dashboard.final_approval_from_loveworld_network'),
    tag: '',
    icon: stepCircleDot,
    card: 'border-[#e2e8f0] bg-white',
    titleCls: 'font-semibold text-[#1e293b]',
    tagCls: '',
  },
]
</script>

<template>
  <!-- ========================================== hero-section 33:30 ======== -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo overlay="bg-[#001529]/72" />

    <div class="relative flex flex-col gap-7 section-x py-9 sm:py-14">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('dashboard.breadcrumb')">
        <RouterLink href="/dashboard" class="text-[#94a3b8] hover:text-white">{{ t('dashboard.applications') }}</RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">{{ t('dashboard.gt') }}</span>
        <span class="text-white">{{ t('dashboard.kemefy_llc') }}</span>
      </nav>

      <div class="max-w-[700px]">
        <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">
          {{ t('dashboard.welcome_back_kemefy_llc') }}
        </h1>
        <p class="mt-2 text-base text-[#94a3b8]">
          {{ t('dashboard.your_loveworld_sbo_business_registration_i') }}
          <strong class="font-display font-semibold text-[#f59e0b]">{{ t('dashboard.25_complete') }}</strong>{{ t('dashboard.complete_step_2_to_move_forward') }}
        </p>
      </div>

      <!-- hero-progress-tracker 33:41 -->
      <div class="flex flex-col gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-5">
        <div class="flex items-center gap-2">
          <div class="h-2 flex-1 overflow-hidden rounded-[4px] bg-white/[0.13]">
            <div class="h-full w-1/4 bg-[#f59e0b]" />
          </div>
          <span class="shrink-0 text-sm font-semibold text-[#f59e0b]">{{ t('dashboard.25_complete_2') }}</span>
        </div>
        <ol class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
          <li v-for="s in heroSteps" :key="s.label" class="flex items-center gap-1.5">
            <img :src="s.icon" alt="" class="size-4 shrink-0" aria-hidden="true" />
            <span class="text-xs" :class="s.cls">{{ s.label }}</span>
          </li>
        </ol>
      </div>
    </div>
  </section>

  <!-- Dashboard – Top Leaderboard. Empty until an ad is assigned. -->
  <AdSlot name="dashboard_top" wrapper-class="section-x pt-8" />

  <!-- =================================== dashboard-workspace 33:60 ======== -->
  <div class="bg-surface-secondary section-x py-8">
    <div class="grid gap-6 lg:grid-cols-[500fr_588fr] [&>*]:min-w-0">
      <!-- workspace-left 33:61 -->
      <div class="flex min-w-0 flex-col gap-6">
        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <div class="flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('dashboard.your_application') }}</h2>
            <span class="rounded-full border border-[#a7f3d0] bg-[#d1fae5] px-2.5 py-1 text-xs font-semibold text-[#007035]">
              {{ t('dashboard.approved_status') }}
            </span>
          </div>
          <hr class="border-[#e2e8f0]" />
          <dl class="flex flex-col gap-3.5">
            <div
              v-for="a in application"
              :key="a.label"
              class="flex flex-col gap-0.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
            >
              <dt class="text-sm text-[#64748b] sm:shrink-0">{{ a.label }}</dt>
              <dd class="min-w-0 text-sm font-semibold text-[#1e293b] sm:text-right">{{ a.value }}</dd>
            </div>
          </dl>
        </section>

        <section class="flex flex-col gap-5 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-7">
          <h2 class="font-display text-lg font-semibold text-[#1e293b]">{{ t('dashboard.timeline_tracking') }}</h2>
          <ol class="flex flex-col gap-3">
            <li
              v-for="t in timeline"
              :key="t.title"
              class="flex items-center gap-4 rounded-xl border p-4"
              :class="t.card"
            >
              <img :src="t.icon" alt="" class="size-6 shrink-0" aria-hidden="true" />
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm" :class="t.titleCls">{{ t.title }}</span>
                <span class="text-xs text-[#94a3b8]">{{ t.body }}</span>
              </span>
              <span v-if="t.tag" class="shrink-0 text-2xs tracking-wide uppercase" :class="t.tagCls">
                {{ t.tag }}
              </span>
            </li>
          </ol>
        </section>
      </div>

      <!-- workspace-right 33:110 -->
      <section class="flex h-fit flex-col gap-7 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-9 shadow-[0px_4px_8px_rgba(0,0,0,0.02)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="grid place-items-center rounded-[10px] bg-[#ccfbf1] p-2.5">
              <img :src="dashDollar" alt="" class="size-5" aria-hidden="true" />
            </span>
            <span class="flex flex-col gap-0.5">
              <span class="font-display text-lg font-semibold text-[#1e293b]">
                {{ t('dashboard.registration_fee_calculator') }}
              </span>
              <span class="text-xs text-[#64748b]">
                {{ t('dashboard.select_company_share_structure_to_compute') }}
              </span>
            </span>
          </div>
          <span class="rounded-full bg-[#fef3c7] px-2.5 py-1 text-xs font-semibold text-[#b45309]">
            {{ t('dashboard.action_required') }}
          </span>
        </div>
        <hr class="border-[#e2e8f0]" />

        <label class="flex flex-col gap-2">
          <span class="text-sm font-semibold text-[#1e293b]">{{ t('dashboard.share_structure') }}</span>
          <span class="relative">
            <select
              v-model="structure"
              class="w-full appearance-none rounded-[10px] border border-[#e2e8f0] bg-[#f8fafc] p-4 pr-11 text-base text-[#1e293b] focus:border-focus focus:outline-none"
            >
              <option v-for="o in shareStructures" :key="o">{{ o }}</option>
            </select>
            <img
              :src="dashChevron"
              alt=""
              class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2"
              aria-hidden="true"
            />
          </span>
        </label>

        <div class="flex items-center justify-between gap-4 rounded-xl border border-[#0d9488] bg-[#f8fafc] p-5">
          <div class="flex flex-col gap-1">
            <span class="text-xs tracking-wide text-[#64748b] uppercase">{{ t('dashboard.calculated_fee') }}</span>
            <span class="font-serif text-3xl font-bold text-[#0b192c]">{{ t('dashboard.50_espees') }}</span>
          </div>
          <span class="flex shrink-0 items-center gap-1.5">
            <img :src="dashCheck" alt="" class="size-[18px]" aria-hidden="true" />
            <span class="text-xs font-semibold text-[#0d9488]">{{ t('dashboard.official_rate_applied') }}</span>
          </span>
        </div>

        <div class="flex flex-wrap gap-4">
          <button
            type="button"
            class="flex-1 rounded-[10px] border border-[#e2e8f0] bg-white px-6 py-3.5 text-base font-semibold text-[#64748b] hover:bg-surface-secondary"
          >
            {{ t('dashboard.save_draft') }}
          </button>
          <RouterLink
            href="/sbo/complete-payment-required"
            class="btn-primary flex flex-1 items-center justify-center rounded-[10px] px-6 py-3.5 text-base font-semibold"
          >
            {{ t('dashboard.pay_fee_now') }}
          </RouterLink>
        </div>
      </section>
    </div>
  </div>
</template>
