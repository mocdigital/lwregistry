<script setup lang="ts">
import benefitVerified from '@/assets/figma/icons/benefit-verified.svg'
import benefitGlobal from '@/assets/figma/icons/benefit-global.svg'
import benefitOpportunities from '@/assets/figma/icons/benefit-opportunities.svg'
import benefitFaith from '@/assets/figma/icons/benefit-faith.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Benefits strip — the same node appears on the homepage (1:5) and the
 * directory (52:1794), so it lives here rather than twice.
 *
 * Card is #f7f7ff with #f3f4f6 hairline dividers between the four columns.
 * Icons mapped exactly: 1:31 -> Verified & Trusted, 1:13 -> Global Reach,
 * 1:20 -> Meaningful Opportunities, 1:26 -> Faith-Driven Impact.
 * NOTE: the design misspells "credibility" as "credibillty"; fixed here.
 */

/*
 * `gutter` follows the host page: the homepage runs a ~20px gutter
 * (`container-page`), while the 80px screens (directory, categories, support,
 * about, profile, business profile) use `section-x`. Without this the strip's
 * edges do not line up with the sections above it.
 */
/*
 * `variant` picks the frame. Most pages draw the strip as a rounded card, but
 * the SBO dashboard (509:49084) runs it as a full-bleed #f7f7ff band with no
 * border or radius — same four columns, same #f3f4f6 dividers.
 */
withDefaults(
  defineProps<{ gutter?: 'page' | 'section'; variant?: 'card' | 'band' }>(),
  { gutter: 'section', variant: 'card' },
)

const benefits = [
  { title: t('benefits_strip.verified_trusted'), body: t('benefits_strip.every_business_is_verified_for_credibility'), icon: benefitVerified },
  { title: t('benefits_strip.global_reach'), body: t('benefits_strip.connect_with_businesses_and_partners_acros'), icon: benefitGlobal },
  { title: t('benefits_strip.meaningful_opportunities'), body: t('benefits_strip.access_procurement_projects_and_partnershi'), icon: benefitOpportunities },
  { title: t('benefits_strip.faith_driven_impact'), body: t('benefits_strip.be_part_of_initiatives_that_create_positiv'), icon: benefitFaith },
]
</script>

<template>
  <section
    :class="
      variant === 'band'
        ? 'bg-[#f7f7ff]'
        : ['mt-5 pb-8', gutter === 'page' ? 'container-page' : 'section-x']
    "
  >
    <div
      class="grid sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0"
      :class="
        variant === 'band'
          ? 'container-page'
          : 'rounded-2xl border border-[#f3f4f6] bg-[#f7f7ff] shadow-[0px_4px_20px_rgba(0,0,0,0.05)]'
      "
    >
      <div
        v-for="(b, i) in benefits"
        :key="b.title"
        class="flex items-start gap-4 p-6"
        :class="i > 0 && 'lg:border-l lg:border-[#f3f4f6]'"
      >
        <img :src="b.icon" alt="" class="size-[52px] shrink-0" aria-hidden="true" />
        <div class="min-w-0">
          <h3 class="font-display text-base leading-6 font-semibold text-[#1e1b4b]">{{ b.title }}</h3>
          <p class="mt-1 text-xs text-[#6b7280]">{{ b.body }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
