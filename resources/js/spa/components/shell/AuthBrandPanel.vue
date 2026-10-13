<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import brandBg from '@/assets/figma/auth/brand-panel.jpg'
import shieldCheck from '@/assets/figma/icons/valueprop-shield.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Auth / registration left panel — Figma 492:47444 (580x1040).
 *
 * Exact spec read from the frame:
 *   panel fill      IMAGE over #0a192f  (an image, not the site video)
 *   logo            249x63 group, 194x49 artwork
 *   badge           "Join the Network" — #14b8a6 pill
 *
 *                   The frame specifies #0d9488 text on that pill, which is
 *                   teal on teal at about 1.3:1 — unreadable at any size. The
 *                   label is white, which is what the pill was drawn to hold.
 *   headline        white serif
 *   body            #94a3b8
 *   ValueProp x3    36px rgba(20,184,166,0.08) circle + 18px shield-check,
 *                   title Plus Jakarta Sans SemiBold 16px white,
 *                   body Geist 14px #94a3b8 at 1.4
 *                   -> the design draws props 2 and 3 at 40% opacity; that is
 *                      replaced here by a hover blur/sharpen on every item
 *   social proof    hairline rule, "3,500+" white, caption #94a3b8
 *
 * All three props use the SAME shield-check icon in the design.
 *
 * Sticky + h-dvh so the panel never scrolls with the form beside it.
 * Hidden below lg.
 */

const valueProps = [
  {
    title: t('auth_brand_panel.verified_credibility_badge'),
    body: t('auth_brand_panel.pass_our_compliant_screening_to_showcase_y'),
  },
  {
    title: t('auth_brand_panel.global_b2b_marketplace'),
    body: t('auth_brand_panel.expose_your_offerings_directly_to_high_int'),
  },
  {
    title: t('auth_brand_panel.protected_transactions'),
    body: t('auth_brand_panel.gain_access_to_smart_business_escrows_and'),
  },
]
</script>

<template>
  <section
    class="sticky top-0 hidden h-dvh w-[580px] shrink-0 flex-col justify-between overflow-hidden bg-[#0a192f] p-14 lg:flex"
  >
    <img :src="brandBg" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
    <div class="absolute inset-0 bg-[#0a192f]/80" aria-hidden="true" />

    <RouterLink href="/" class="relative">
      <img :src="logo" alt="LW Registry" class="h-[49px] w-auto object-contain brightness-0 invert" />
    </RouterLink>

    <div class="relative">
      <span class="inline-flex items-center rounded-md bg-[#14b8a6] px-3 py-1">
        <span class="text-xs font-semibold text-white">{{ t('auth_brand_panel.join_the_network') }}</span>
      </span>

      <h2 class="mt-5 font-serif text-4xl font-bold text-white">
        {{ t('auth_brand_panel.find_trusted') }} <span class="whitespace-nowrap">{{ t('auth_brand_panel.spirit_led') }}</span> {{ t('auth_brand_panel.businesses') }}
      </h2>
      <p class="mt-4 max-w-[452px] text-sm leading-normal text-[#94a3b8]">
        {{ t('auth_brand_panel.showcase_your_business_find_bids_and_rfps') }}
      </p>

      <!--
        Each item rests blurred and dimmed, and sharpens on hover/focus. This
        replaces the design's static 40%-opacity treatment on props 2 and 3 with
        a uniform interactive one.

        Only the text blurs — the icon stays sharp so the column keeps its
        rhythm. `focus-within` mirrors hover for keyboard users, and the whole
        effect is disabled under prefers-reduced-motion.
      -->
      <ul class="mt-8 space-y-6">
        <li
          v-for="v in valueProps"
          :key="v.title"
          class="group flex gap-4 transition-opacity duration-500 ease-out"
        >
          <span
            class="grid size-9 shrink-0 place-items-center rounded-full bg-[rgba(20,184,166,0.08)] opacity-80 transition-opacity duration-500 group-hover:opacity-100 group-focus-within:opacity-100"
          >
            <img :src="shieldCheck" alt="" class="size-[18px]" aria-hidden="true" />
          </span>

          <span
            tabindex="0"
            class="min-w-0 flex-1 opacity-75 blur-[1px] transition-[filter,opacity] duration-300 ease-out outline-none group-hover:opacity-100 group-hover:blur-none group-focus-within:opacity-100 group-focus-within:blur-none motion-reduce:opacity-100 motion-reduce:blur-none motion-reduce:transition-none"
          >
            <span class="block font-display text-base font-semibold text-white">{{ v.title }}</span>
            <span class="mt-1 block text-sm leading-snug text-[#94a3b8]">{{ v.body }}</span>
          </span>
        </li>
      </ul>
    </div>

    <div class="relative">
      <span class="block h-px w-full bg-white/10" aria-hidden="true" />
      <p class="mt-5 font-serif text-3xl font-bold text-white">3,500+</p>
      <p class="mt-0.5 text-sm text-[#94a3b8]">{{ t('auth_brand_panel.verified_active_businesses') }}</p>
    </div>
  </section>
</template>
