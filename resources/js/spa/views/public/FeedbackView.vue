<script setup lang="ts">
import { ref } from 'vue'
import BenefitsStrip from '@/components/shell/BenefitsStrip.vue'

import icChevron from '@/assets/figma/feedback/chevron-down.svg'
import dotMesh from '@/assets/figma/feedback/dot-mesh.jpg'
import avatar1 from '@/assets/figma/feedback/avatar-1.jpg'
import avatar2 from '@/assets/figma/feedback/avatar-2.jpg'
import avatar3 from '@/assets/figma/feedback/avatar-3.jpg'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: PublicLayout })

/*
 * Feedback — Figma 35:5 "lw-registry-feedback-redesign" (1280x2472).
 *   hero 35:25 (436)       #0b192c + gradient under a dot-mesh image. NOT a
 *                          video fill, unlike most other heroes.
 *   workspace 35:198 (867) feedback-form-panel + 380px dark sidebar, gap 32
 *   benefits 52:1850
 *
 * The form is three two-up rows (name/email, handle/type, priority/url) then
 * subject and message. Name and Email sit on #f8fafc as prefilled account
 * data; the rest are plain white inputs.
 */

const props = defineProps<{ user: { name: string; email: string } }>()

const types = [
  t('feedback.business_verification_request'),
  t('feedback.bug_report'),
  t('feedback.feature_suggestion'),
  t('feedback.billing_question'),
  t('feedback.type.other'),
]
const priorities = [t('feedback.priority.normal'), t('data.status.low'), t('data.status.high'), t('data.status.urgent')]

const form = ref({
  name: props.user.name,
  email: 'eventprime@yahoo.com',
  handle: '',
  type: types[0],
  priority: priorities[0],
  url: 'https://registry.lwcommerce.org/join',
  subject: '',
  message: '',
})

// feedback-items-list 35:251 — dark cards on the navy rail
const recent = [
  {
    name: t('feedback.lewis_mark'),
    avatar: avatar1,
    status: t('feedback.resolved'),
    tone: 'bg-[#d1fae5] text-[#007035]',
    body: t('feedback.verification_queue_latency_has_been_comple'),
    when: t('feedback.1_hour_ago'),
  },
  {
    name: t('feedback.deaconess_ruth'),
    avatar: avatar2,
    status: t('feedback.in_progress'),
    tone: 'bg-[#fef3c7] text-[#f59e0b]',
    body: t('feedback.suggestion_for_quick_search_filters_in_dir'),
    when: t('feedback.4_hours_ago'),
  },
  {
    name: t('feedback.brother_joshua'),
    avatar: avatar3,
    status: t('feedback.under_review'),
    tone: 'bg-[#dbeafe] text-[#3b82f6]',
    body: t('feedback.payment_receipt_generation_error_on_sbo_re'),
    when: t('notifications.group.yesterday'),
  },
]
</script>

<template>
  <!-- ================================================ hero 35:25 ========= -->
  <section
    class="relative overflow-hidden bg-linear-to-b from-[#0b192c] to-[#0b192c] section-x py-10 sm:py-16"
  >
    <!-- dot-mesh 35:26 -->
    <img
      :src="dotMesh"
      alt=""
      class="pointer-events-none absolute inset-0 size-full object-cover opacity-30"
      aria-hidden="true"
    />

    <div class="relative flex flex-col gap-4">
      <p class="text-xs font-semibold text-[#f59e0b]">{{ t('application.welcome_back', { name: props.user.name }) }}</p>
      <h1 class="max-w-3xl font-serif text-5xl font-bold text-white">
        {{ t('feedback.help_us_build_a_better_registry') }}
      </h1>
      <p class="max-w-3xl text-lg leading-relaxed text-white">
        {{ t('feedback.your_insights_shape_the_loveworld_business') }}
      </p>
    </div>
  </section>

  <!-- ============================================ workspace 35:198 ======== -->
  <div class="flex flex-col gap-8 bg-surface-secondary section-x py-8 lg:flex-row">
    <!-- feedback-form-panel 35:199 -->
    <form
      class="flex min-w-0 flex-1 flex-col gap-8 rounded-2xl border border-[#e2e8f0] bg-white p-5 sm:p-8 lg:p-10 drop-shadow-[0px_10px_7.5px_rgba(15,23,42,0.02)]"
      @submit.prevent
    >
      <!-- form-header 35:200 -->
      <div class="flex flex-col gap-2">
        <p class="text-xs font-semibold text-[#f59e0b] uppercase">{{ t('feedback.feedback_form') }}</p>
        <p class="font-serif text-2xl font-bold text-[#0f172a]">
          {{ t('feedback.send_feedback_to_the_registry_team') }}
        </p>
      </div>

      <!-- form-inputs 35:203 -->
      <div class="flex flex-col gap-6">
        <!-- row-1 35:204 — prefilled account data on #f8fafc -->
        <div class="flex flex-col gap-5 sm:flex-row">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.name') }}</span>
            <input
              v-model="form.name"
              type="text"
              class="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] p-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
          </label>
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.email') }}</span>
            <input
              v-model="form.email"
              type="email"
              class="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] p-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
          </label>
        </div>

        <!-- row-2 35:213 -->
        <div class="flex flex-col gap-5 sm:flex-row">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">
              {{ t('feedback.kingschat_id_handle') }}
            </span>
            <input
              v-model="form.handle"
              type="text"
              :placeholder="t('feedback.eventprime')"
              class="rounded-lg border border-[#e2e8f0] bg-white p-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
          </label>
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.feedback_type') }}</span>
            <span class="relative">
              <select
                v-model="form.type"
                class="w-full appearance-none rounded-lg border border-[#e2e8f0] bg-white p-3 pr-10 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
              >
                <option v-for="t in types" :key="t">{{ t }}</option>
              </select>
              <img
                :src="icChevron"
                alt=""
                class="pointer-events-none absolute top-1/2 right-3 size-3.5 -translate-y-1/2"
                aria-hidden="true"
              />
            </span>
          </label>
        </div>

        <!-- row-3 35:224 -->
        <div class="flex flex-col gap-5 sm:flex-row">
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.priority_level') }}</span>
            <span class="relative">
              <select
                v-model="form.priority"
                class="w-full appearance-none rounded-lg border border-[#e2e8f0] bg-white p-3 pr-10 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
              >
                <option v-for="p in priorities" :key="p">{{ p }}</option>
              </select>
              <img
                :src="icChevron"
                alt=""
                class="pointer-events-none absolute top-1/2 right-3 size-3.5 -translate-y-1/2"
                aria-hidden="true"
              />
            </span>
          </label>
          <label class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="text-xs font-semibold text-[#475569]">
              {{ t('feedback.target_page_url') }}
            </span>
            <input
              v-model="form.url"
              type="url"
              class="rounded-lg border border-[#e2e8f0] bg-white p-3 text-sm text-[#0f172a] focus:border-focus focus:outline-none"
            />
          </label>
        </div>

        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.subject_line') }}</span>
          <input
            v-model="form.subject"
            type="text"
            :placeholder="t('feedback.enter_subject_of_your_feedback')"
            class="rounded-lg border border-[#e2e8f0] bg-white p-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
        </label>

        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold text-[#475569]">{{ t('feedback.message') }}</span>
          <textarea
            v-model="form.message"
            rows="6"
            :placeholder="t('feedback.provide_a_detailed_explanation_of_your_fee')"
            class="h-[140px] resize-y rounded-lg border border-[#e2e8f0] bg-white p-3 text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
          />
        </label>
      </div>

      <!-- form-footer 35:243 -->
      <div class="flex flex-wrap items-center justify-between gap-4">
        <p class="text-xs text-[#475569]">
          {{ t('feedback.admins_will_respond_directly_to_your_prefi') }}
        </p>
        <button
          type="submit"
          class="rounded-lg bg-[#f59e0b] px-6 py-3 text-sm font-semibold text-[#0b192c] transition-opacity hover:opacity-90"
        >
          {{ t('feedback.send_feedback') }}
        </button>
      </div>
    </form>

    <!-- sidebar-recent-activity 35:247 — dark navy, not a white card -->
    <aside
      class="flex w-full shrink-0 flex-col gap-6 self-start rounded-2xl border border-white/[0.07] bg-[#0b192c] p-5 sm:p-8 lg:w-[380px]"
    >
      <div class="flex flex-col gap-1.5 text-white">
        <!-- base styles colour h2 with --color-ink, so state it here -->
        <h2 class="font-display text-lg font-semibold text-white">{{ t('feedback.recent_community_feedback') }}</h2>
        <p class="text-xs">{{ t('feedback.updates_on_registry_platform_operations') }}</p>
      </div>

      <ul class="flex flex-col gap-5">
        <li
          v-for="r in recent"
          :key="r.name"
          class="flex flex-col gap-3 rounded-xl border border-white/[0.04] bg-[#1e293b] p-4"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="flex items-center gap-2">
              <img :src="r.avatar" alt="" class="size-6 rounded-full object-cover" aria-hidden="true" />
              <span class="text-xs font-semibold text-white">{{ r.name }}</span>
            </span>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.tone">
              {{ r.status }}
            </span>
          </div>
          <p class="text-xs leading-snug text-white">{{ r.body }}</p>
          <p class="text-2xs text-white">{{ r.when }}</p>
        </li>
      </ul>
    </aside>
  </div>

  <BenefitsStrip />
</template>
