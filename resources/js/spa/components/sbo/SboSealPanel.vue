<script setup lang="ts">
import SboCertificatePreview from '@/components/sbo/SboCertificatePreview.vue'
import wsAward from '@/assets/figma/profile/award.svg'
import wsShieldAlert from '@/assets/figma/profile/shield-alert.svg'
import { useI18n } from '@/composables/useI18n'
import { useEntityTerm } from '@/composables/useEntityTerm'

const { t } = useI18n()
const { entityTerm } = useEntityTerm()

/*
 * The Digital SBO Seal, as the application-progress sidebar draws it.
 *
 * This replaces a card that was entirely invented: a static ribbon glyph, a
 * "VETTED" pill shown whatever the real status was, the words "LBN Certified
 * Member 2026", and a "Download Seal Certificate" button with no handler
 * behind it. A member whose seal had genuinely been issued was shown a fake one
 * and could not reach the certificate that existed; a member with no seal was
 * told they were vetted.
 *
 * The DATA is shared with the profile dashboard — both read
 * App\Support\SboSealPanel, so the two can never disagree about whether a seal
 * exists or where its certificate lives. The MARKUP is not shared: the profile
 * panel is a full-width card with an icon checklist and a validation CTA, this
 * one is a narrow sidebar card. Sharing the payload is what matters; forcing
 * one layout onto both columns would not.
 */

const props = defineProps<{
  seal: {
    issued: boolean
    status: 'provisional' | 'verified' | null
    sboName: string | null
    checklist: { label: string; done: boolean }[]
    imageUrl: string | null
    certificateUrl: string | null
    /** The same PDF served inline, for reading without keeping. */
    certificatePreviewUrl: string | null
    number: string | null
    issuedOn: string | null
  }
  /** Compact drops the checklist, for the narrower sidebar column. */
  compact?: boolean
}>()

</script>

<template>
  <section
    class="flex flex-col gap-6 rounded-3xl border border-border bg-white p-5 sm:p-8 drop-shadow-[0px_8px_12px_rgba(0,0,0,0.02)]"
  >
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <span class="grid h-8 w-9 shrink-0 place-items-center rounded-lg bg-warning/12">
          <img :src="wsAward" alt="" class="size-4" aria-hidden="true" />
        </span>
        <div>
          <p class="font-display text-lg font-semibold text-ink">{{ t('sbo_seal_panel.digital_sbo_seal', { entity: entityTerm }) }}</p>
          <p class="text-xs text-ink-secondary">{{ t('sbo_seal_panel.official_validation_personalized_seal') }}</p>
        </div>
      </div>
      <!-- The status is the real one. The old card said "VETTED" regardless. -->
      <span
        class="shrink-0 rounded-full px-3 py-1.5 text-2xs font-semibold"
        :class="
          props.seal.status === 'verified'
            ? 'bg-success/12 text-success'
            : props.seal.status === 'provisional'
              ? 'bg-warning/12 text-warning'
              : 'bg-surface-tertiary text-ink-secondary'
        "
      >
        {{
          props.seal.status === 'verified'
            ? t('sbo_seal_panel.seal_issued')
            : props.seal.status === 'provisional'
              ? t('sbo_seal_panel.provisional_seal')
              : t('sbo_seal_panel.no_seal_issued_yet')
        }}
      </span>
    </div>

    <!-- Issued: the badge itself, and the certificate to download. -->
    <template v-if="props.seal.issued">
      <div
        class="flex flex-col items-center gap-4 rounded-2xl border border-border bg-surface-secondary p-6 sm:flex-row sm:items-center"
      >
        <img
          v-if="props.seal.imageUrl"
          :src="props.seal.imageUrl"
          :alt="t('seal.alt_for', { name: props.seal.sboName })"
          class="size-[120px] shrink-0 object-contain"
        />
        <div class="min-w-0 flex-1 text-center sm:text-left">
          <p class="font-display text-base font-semibold text-ink">{{ props.seal.sboName }}</p>
          <p class="text-xs text-ink-secondary">
            {{ props.seal.number }}
            <template v-if="props.seal.issuedOn"> {{ t('seal.issued_on', { date: props.seal.issuedOn }) }}</template>
          </p>
          <p v-if="props.seal.status === 'provisional'" class="mt-2 text-xs text-warning">
            {{ t('sbo_seal_panel.your_fee_is_settled_so_the_seal_is_yours_i') }}
          </p>
        </div>
      </div>

      <div class="flex flex-wrap gap-3">
        <SboCertificatePreview
          :preview-url="props.seal.certificatePreviewUrl"
          :download-url="props.seal.certificateUrl"
          :title="props.seal.sboName"
        />
        <a
          v-if="props.seal.certificateUrl"
          :href="props.seal.certificateUrl"
          class="btn-primary rounded-lg px-5 py-2.5 text-xs font-semibold"
        >
          {{ t('sbo_seal_panel.download_certificate_pdf') }}
        </a>
      </div>
    </template>

    <!-- Not issued yet: what is outstanding, and where to go. -->
    <template v-else>
      <div class="flex items-center gap-4 rounded-2xl border border-border bg-surface-secondary p-5">
        <span class="grid size-10 shrink-0 place-items-center rounded-[20px] border border-border bg-white">
          <img :src="wsShieldAlert" alt="" class="size-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-ink">{{ t('sbo_seal_panel.pending_validation_completion') }}</p>
          <p class="text-xs text-ink-secondary">
            {{ t('sbo_seal_panel.your_seal_is_issued_as_soon_as_your_regist') }}
          </p>
        </div>
      </div>
    </template>

    <!-- The real progress behind the seal, not four fixed ticks. -->
    <ul v-if="!props.compact && props.seal.checklist.length" class="flex flex-wrap gap-x-6 gap-y-2">
      <li v-for="c in props.seal.checklist" :key="c.label" class="flex items-center gap-2">
        <span
          class="grid size-4 shrink-0 place-items-center rounded-full text-2xs font-bold"
          :class="c.done ? 'bg-success/15 text-success' : 'bg-surface-tertiary text-ink-tertiary'"
          aria-hidden="true"
        >
          {{ c.done ? '✓' : '·' }}
        </span>
        <span class="text-xs" :class="c.done ? 'text-ink' : 'text-ink-tertiary'">
          {{ c.label }}
        </span>
      </li>
    </ul>
  </section>
</template>
