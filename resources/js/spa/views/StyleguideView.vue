<script setup lang="ts">
import { ref } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiPasswordField from '@/components/ui/UiPasswordField.vue'
import UiTag from '@/components/ui/UiTag.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiSearchBar from '@/components/ui/UiSearchBar.vue'
import UiAccordion from '@/components/ui/UiAccordion.vue'
import UiProgressBar from '@/components/ui/UiProgressBar.vue'
import UiStepIndicator from '@/components/ui/UiStepIndicator.vue'
import UiBreadcrumbs from '@/components/ui/UiBreadcrumbs.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/* Living reference for the component set built from Figma page 265:2. */

const email = ref('')
const search = ref('')

const swatches = [
  ['primary (active)', 'bg-primary', '#007035'],
  ['primary (inactive)', 'bg-primary-inactive', '#0d9488'],
  ['secondary', 'bg-secondary', '#f59e0b'],
  ['surface-dark', 'bg-surface-dark', '#001529'],
  ['success', 'bg-success', '#22c55e'],
  ['warning', 'bg-warning', '#d97706'],
  ['info', 'bg-info', '#3b82f6'],
  ['danger', 'bg-danger', '#ef4444'],
  ['disabled', 'bg-disabled', '#94a3b8'],
]

const type = [
  ['text-5xl', '48px'],
  ['text-4xl', '36px'],
  ['text-3xl', '30px'],
  ['text-2xl', '24px'],
  ['text-xl', '20px'],
  ['text-lg', '18px'],
  ['text-base', '16px'],
  ['text-sm', '14px'],
  ['text-xs', '13px'],
  ['text-2xs', '12px'],
]
</script>

<template>
  <div class="container-page space-y-14 py-8 sm:py-12">
    <header>
      <p class="text-2xs font-semibold tracking-wide text-ink-tertiary uppercase">{{ t('styleguide.foundation') }}</p>
      <h1 class="mt-2 font-serif text-4xl font-bold">{{ t('styleguide.component_library') }}</h1>
      <p class="mt-3 max-w-prose text-sm text-ink-secondary">
        {{ t('styleguide.built_from_figma_page') }} <code class="rounded-sm bg-surface-tertiary px-1.5 py-0.5 text-xs">265:2</code>{{ t('styleguide.tokens_follow_what_the_components_actually') }}
      </p>
    </header>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.colour') }}</h2>
      <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <div v-for="[name, cls, hexValue] in swatches" :key="name">
          <div class="h-16 rounded-lg border border-border" :class="cls" />
          <p class="mt-2 text-xs font-medium">{{ name }}</p>
          <p class="font-mono text-2xs text-ink-tertiary">{{ hexValue }}</p>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.type_scale') }}</h2>
      <dl class="mt-4 space-y-3">
        <div v-for="[cls, px] in type" :key="cls" class="flex items-baseline gap-4 border-b border-border-subtle pb-3">
          <dt class="w-24 shrink-0 font-mono text-2xs text-ink-tertiary">{{ cls }} · {{ px }}</dt>
          <dd :class="cls" class="min-w-0 truncate">{{ t('styleguide.loveworld_business_registry') }}</dd>
        </div>
      </dl>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.buttons') }}</h2>
      <div class="mt-4 space-y-4">
        <div class="flex flex-wrap items-center gap-4">
          <UiButton active size="lg">{{ t('styleguide.sign_in') }}</UiButton>
          <UiButton size="md">{{ t('styleguide.search') }}</UiButton>
          <UiButton size="sm">{{ t('styleguide.submit') }}</UiButton>
          <UiButton disabled>{{ t('styleguide.disabled') }}</UiButton>
        </div>
        <div class="flex flex-wrap items-center gap-4">
          <UiButton size="lg">{{ t('styleguide.primary_resting_inactive') }}</UiButton>
          <UiButton active size="lg">{{ t('styleguide.primary_active') }}</UiButton>
          <UiButton variant="outline" size="lg">{{ t('styleguide.cancel') }}</UiButton>
          <UiButton variant="dark" size="lg">{{ t('styleguide.continue_registration') }}</UiButton>
          <UiButton variant="ghost">{{ t('styleguide.browse_articles') }}</UiButton>
          <UiButton variant="cta" size="lg">{{ t('styleguide.advertise_with_us') }}</UiButton>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.inputs') }}</h2>
      <div class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <UiInput v-model="email" label="Email Address" :placeholder="t('styleguide.you_example_com')" />
        <UiPasswordField label="Password" />
        <UiInput label="Business Name" error="This field is required" />
        <UiInput label="Country" :placeholder="t('styleguide.select_country')" disabled />
      </div>
      <div class="mt-6 max-w-2xl">
        <UiSearchBar v-model="search" />
      </div>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.tags') }}</h2>
      <div class="mt-4 flex flex-wrap gap-3">
        <UiTag tone="success">{{ t('styleguide.verified') }}</UiTag>
        <UiTag tone="warning">{{ t('styleguide.pending') }}</UiTag>
        <UiTag tone="info">{{ t('styleguide.info') }}</UiTag>
        <UiTag tone="neutral">{{ t('styleguide.neutral') }}</UiTag>
        <UiTag tone="premium">{{ t('styleguide.premium') }}</UiTag>
        <UiTag tone="ad">{{ t('styleguide.sponsored') }}</UiTag>
      </div>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.progress_amp_steps') }}</h2>
      <div class="mt-4 grid gap-6 lg:grid-cols-3">
        <UiProgressBar :value="25" label="Registration Progress" />
        <UiProgressBar :value="60" label="Profile Completion" />
        <UiProgressBar :value="100" label="Verification Complete" />
      </div>
      <div class="mt-8 space-y-6">
        <UiStepIndicator
          :steps="['Business Identity', 'Contact & Representative', 'Location & Operations']"
          :current="1"
        />
        <UiStepIndicator
          :steps="['Overview', 'Pricing', 'Documents', 'Team', 'Review']"
          :current="2"
        />
      </div>
    </section>

    <section>
      <h2 class="text-xl font-semibold">{{ t('styleguide.breadcrumbs_cards_amp_faq') }}</h2>
      <div class="mt-4">
        <UiBreadcrumbs
          :items="[{ label: 'Home', to: '/' }, { label: 'Directory', to: '/businesses' }, { label: 'Ditabol Homes' }]"
        />
      </div>
      <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <UiCard v-for="c in ['Knowledge Base', 'Contact Support', 'Community Forum']" :key="c" interactive>
          <h3 class="text-base font-semibold">{{ c }}</h3>
          <p class="mt-2 text-sm text-ink-secondary">
            {{ t('styleguide.placeholder_copy_the_help_card_content_is') }}
          </p>
          <UiButton variant="ghost" class="mt-4 -ml-6">{{ t('styleguide.browse_articles') }}</UiButton>
        </UiCard>
      </div>
      <UiCard class="mt-6" :padded="false">
        <div class="px-6">
          <UiAccordion question="How do I register my business?" open>
            {{ t('styleguide.complete_the_three_step_registration_wizar') }}
          </UiAccordion>
          <UiAccordion question="What is an SBO verification seal?">
            {{ t('styleguide.a_verified_badge_shown_on_your_public_prof') }}
          </UiAccordion>
          <UiAccordion question="How long does verification take?">
            {{ t('styleguide.typically_a_few_business_days_after_your_d') }}
          </UiAccordion>
        </div>
      </UiCard>
    </section>
  </div>
</template>
