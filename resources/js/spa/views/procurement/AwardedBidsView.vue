<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
// The stock cover for a request whose buyer gave no photo, so every card
// carries an image rather than starting on a blank strip.
import defaultCover from '@/assets/figma/profile/procurement-default.jpg'
import { useI18n } from '@/composables/useI18n'

/*
 * Won & Awarded Bids — the platform's success stories, for signed-in members.
 * Not a Figma frame: the design draws Opportunities but never its outcomes, so
 * this keeps that page's dark hero and the app's card idiom.
 */

const { t } = useI18n()

defineOptions({ layout: AppLayout })

type Award = {
  id: string
  title: string
  image: string | null
  buyer: string | null
  category: string | null
  awardedAt: string | null
  winner: { name: string; slug: string | null } | null
  testimonial: { rating: number; headline: string; body: string } | null
}

defineProps<{ awards: Award[] }>()
</script>

<template>
  <!-- Video hero, the same living backdrop as Opportunities. -->
  <section class="relative overflow-hidden bg-[#001529]">
    <HeroVideo overlay="bg-[#001529]/72" />

    <div class="relative section-x py-10 sm:py-14">
      <nav class="mb-4 flex items-center gap-2 text-sm text-[#94a3b8]">
        <RouterLink href="/procurement" class="hover:text-white">{{ t('procurement_list.procurement_requests') }}</RouterLink>
        <span aria-hidden="true">›</span>
        <span class="text-white">{{ t('procurement_list.awarded_bids') }}</span>
      </nav>
      <h1 class="font-serif text-3xl font-bold text-white xl:text-4xl">{{ t('awarded.title') }}</h1>
      <p class="mt-2 max-w-2xl text-base text-[#94a3b8]">{{ t('awarded.subtitle') }}</p>
    </div>
  </section>

  <section class="container-page py-8 sm:py-10">
    <div v-if="awards.length" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
      <article
        v-for="award in awards"
        :key="award.id"
        class="flex flex-col overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white shadow-[0px_10px_30px_rgba(15,23,42,0.05)]"
      >
        <!-- The request's featured image, with a stock cover as fallback. -->
        <div class="relative aspect-[16/9] w-full overflow-hidden bg-[#f1f5f9]">
          <img :src="award.image ?? defaultCover" :alt="award.title" class="size-full object-cover" />
          <span class="absolute left-3 top-3 rounded-full bg-[#007035] px-2.5 py-1 text-2xs font-semibold text-white shadow-sm">
            {{ t('awarded.won_badge') }}
          </span>
        </div>

        <div class="flex flex-col gap-3 p-5">
          <div class="flex items-center justify-between gap-2">
            <span v-if="award.category" class="rounded-full bg-[#eff6ff] px-2.5 py-1 text-2xs font-semibold text-[#1d4ed8]">
              {{ award.category }}
            </span>
            <span v-if="award.awardedAt" class="text-2xs text-[#94a3b8]">{{ award.awardedAt }}</span>
          </div>

          <h2 class="line-clamp-2 font-serif text-lg font-bold text-[#0f172a]">{{ award.title }}</h2>

          <dl class="flex flex-col gap-1 text-sm">
            <div v-if="award.buyer" class="flex gap-1.5">
              <dt class="text-[#94a3b8]">{{ t('awarded.posted_by') }}</dt>
              <dd class="min-w-0 truncate font-medium text-[#334155]">{{ award.buyer }}</dd>
            </div>
            <div v-if="award.winner" class="flex gap-1.5">
              <dt class="text-[#94a3b8]">{{ t('awarded.won_by') }}</dt>
              <dd class="min-w-0 truncate font-semibold text-[#007035]">
                <RouterLink v-if="award.winner.slug" :href="`/business/${award.winner.slug}`" class="hover:underline">
                  {{ award.winner.name }}
                </RouterLink>
                <span v-else>{{ award.winner.name }}</span>
              </dd>
            </div>
          </dl>
        </div>

        <!-- The winner's testimonial, where they left one and agreed to show it. -->
        <figure
          v-if="award.testimonial"
          class="mt-auto flex flex-col gap-2 border-t border-[#f1f5f9] bg-[#f8fafc] p-5"
        >
          <div class="flex items-center gap-0.5" :aria-label="`${award.testimonial.rating} / 5`">
            <svg
              v-for="n in 5"
              :key="n"
              viewBox="0 0 24 24"
              class="size-4"
              :class="n <= award.testimonial.rating ? 'text-[#f59e0b]' : 'text-[#e2e8f0]'"
              fill="currentColor"
              aria-hidden="true"
            >
              <path d="M12 2l2.9 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l7.1-1.01z" />
            </svg>
          </div>
          <figcaption class="text-sm font-semibold text-[#0f172a]">{{ award.testimonial.headline }}</figcaption>
          <blockquote class="line-clamp-4 text-sm text-[#475569]">“{{ award.testimonial.body }}”</blockquote>
        </figure>
      </article>
    </div>

    <div v-else class="rounded-2xl border border-dashed border-[#e2e8f0] py-16 text-center">
      <p class="font-serif text-lg font-semibold text-[#0f172a]">{{ t('awarded.empty_title') }}</p>
      <p class="mt-1 text-sm text-[#64748b]">{{ t('awarded.empty_body') }}</p>
    </div>
  </section>
</template>
