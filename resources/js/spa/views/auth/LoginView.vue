<script setup lang="ts">
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import brandBg from '@/assets/figma/auth/brand-panel.jpg'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import mailIcon from '@/assets/figma/icons/mail.svg'
import UiPasswordField from '@/components/ui/UiPasswordField.vue'
import checkIcon from '@/assets/figma/icons/check.svg'
import { useI18n } from '@/composables/useI18n'
import { useAppUrl } from '@/composables/useAppUrl'

const { t } = useI18n()
const appUrl = useAppUrl()

/*
 * Sign in — Figma 64:4 (1280x868). Two 640px panels:
 *   branded-hero-panel 64:5  — logo 64:6, hero text 64:10, trust row 64:15
 *   login-form-panel   64:30 — login-card 64:31 (480x516)
 *
 * The card is exact: 48px padding, 16px radius, #e2e8f0 border,
 * 0 12px 16px rgba(0,0,0,0.03) shadow, 32px Cormorant Garamond heading,
 * #1b7f4b links/checkbox and the #007035 Sign In button.
 *
 * NOTE: #1b7f4b is a third green, distinct from both --color-primary (#007035)
 * and --color-success (#22c55e). It is used for the links and the checkbox
 * fill here, so it is kept literal rather than snapped to a token.
 *
 * Below lg the hero panel is hidden and the card centres — invented, as there
 * is no mobile comp.
 *
 * NO global header: 64:4 is a full-bleed 1280x868 split. The only branding is
 * the logo inside the left panel (64:6). Adding a site header here would be a
 * departure from the design, not a fix.
 *
 * The left panel background is an IMAGE fill (#0a192f underneath), not the
 * site video — confirmed from the frame's fills.
 *
 * Below lg the left panel is hidden, so the logo moves above the card to keep
 * branding present — invented, as there is no mobile comp.
 */

/*
 * `useForm` rather than raw refs: it carries the CSRF token, exposes
 * `form.errors` populated from the ValidationException the controller throws,
 * and flips `form.processing` so the submit button can be disabled against a
 * double post. `onFinish` clears the password so it does not survive a failed
 * attempt in memory or in a browser-restored form.
 */
const form = useForm({
  email: '',
  password: '',
  remember: true,
})

function submit() {
  form.post(appUrl('/login'), {
    onFinish: () => form.reset('password'),
  })
}

const trustBadges = [t('login.iso_27001_certified'), t('login.state_agency_partner'), t('login.256_bit_ssl_secured')]
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-2 [&>*]:min-w-0">
    <!-- =================================== branded-hero-panel 64:5 ======== -->
    <section class="relative hidden flex-col justify-between overflow-hidden bg-[#0a192f] p-16 lg:flex">
      <!-- 64:5 fill is IMAGE over #0a192f — a still, not the site video. -->
      <img :src="brandBg" alt="" class="absolute inset-0 size-full object-cover" aria-hidden="true" />
      <div class="absolute inset-0 bg-[#0a192f]/80" aria-hidden="true" />

      <img
        :src="logo"
        alt="LW Registry"
        class="relative h-[49px] w-auto shrink-0 self-start object-contain brightness-0 invert"
      />

      <div class="relative max-w-[512px]">
        <p class="text-xs font-semibold tracking-wider text-secondary uppercase">{{ t('login.secure_portal') }}</p>
        <h1 class="mt-3 font-serif text-4xl font-bold text-white">
          {{ t('login.the_secure_foundation_for_your_business_re') }}
        </h1>
        <p class="mt-4 text-base leading-relaxed text-[#94a3b8]">
          {{ t('login.manage_incorporation_file_annual_reports_a') }}
        </p>
      </div>

      <ul class="relative flex flex-wrap gap-6">
        <li v-for="b in trustBadges" :key="b" class="flex items-center gap-2 text-2xs text-white/70">
          <span class="grid size-4 place-items-center rounded-full bg-white/15" aria-hidden="true">
            <img :src="checkIcon" alt="" class="size-2.5" />
          </span>
          {{ b }}
        </li>
      </ul>
    </section>

    <!-- =================================== login-form-panel 64:30 ========= -->
    <section class="flex items-center justify-center bg-surface-secondary px-5 py-8 sm:py-12">
      <!-- login-card 64:31 -->
      <form
        class="flex w-full max-w-[480px] flex-col gap-8 rounded-2xl border border-[#e2e8f0] bg-white p-8 shadow-[0px_12px_16px_rgba(0,0,0,0.03)] sm:p-12"
        @submit.prevent="submit"
      >
        <!-- Below lg the branded panel is hidden, so the logo appears here
             instead. Invented — there is no mobile comp. -->
        <RouterLink :href="appUrl('/')" class="lg:hidden">
          <img :src="logo" alt="LW Registry" class="logo-adaptive h-9 w-auto object-contain" />
        </RouterLink>

        <header class="flex flex-col gap-3">
          <h2 class="font-serif text-3xl font-bold text-[#0f172a]">{{ t('login.welcome_back') }}</h2>
          <p class="text-base text-[#475569]">{{ t('login.sign_in_to_your_lw_registry_account') }}</p>
        </header>

        <div class="flex flex-col gap-5">
          <label class="flex flex-col gap-2">
            <span class="text-sm font-semibold text-[#0f172a]">{{ t('login.email_address') }}</span>
            <span
              class="flex items-center gap-2 rounded-md border bg-white px-4 py-3 focus-within:border-focus"
              :class="form.errors.email ? 'border-danger' : 'border-[#e2e8f0]'"
            >
              <img :src="mailIcon" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
              <input
                v-model="form.email"
                type="email"
                autocomplete="email"
                :placeholder="t('login.e_g_sarah_company_com')"
                class="min-w-0 flex-1 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
              />
            </span>
            <!--
              Bad credentials and a suspended account both come back keyed to
              `email`, so this one slot carries the form-level failure too.
            -->
            <span v-if="form.errors.email" class="text-xs text-danger">
              {{ form.errors.email }}
            </span>
          </label>

          <div class="flex flex-col gap-2">
            <span class="flex items-center justify-between">
              <span class="text-sm font-semibold text-[#0f172a]">{{ t('login.password') }}</span>
              <RouterLink :href="appUrl('/forgot-password')" class="text-xs font-medium text-[#1b7f4b] hover:underline">
                {{ t('login.forgot_password') }}
              </RouterLink>
            </span>
            <UiPasswordField v-model="form.password" autocomplete="current-password" />
            <span v-if="form.errors.password" class="text-xs text-danger">
              {{ form.errors.password }}
            </span>
          </div>

          <label class="flex cursor-pointer items-center gap-2">
            <input v-model="form.remember" type="checkbox" class="peer sr-only" />
            <span
              class="grid size-[18px] shrink-0 place-items-center rounded-sm border-[1.5px] transition-colors"
              :class="form.remember ? 'border-[#1b7f4b] bg-[#1b7f4b]' : 'border-[#e2e8f0] bg-white'"
              aria-hidden="true"
            >
              <img v-show="form.remember" :src="checkIcon" alt="" class="size-3" />
            </span>
            <span class="text-sm text-[#475569]">{{ t('login.remember_me_on_this_device') }}</span>
          </label>
        </div>

        <div class="flex flex-col gap-5">
          <button
            type="submit"
            :disabled="form.processing"
            class="w-full rounded-md btn-primary py-3.5 text-base font-semibold disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ form.processing ? t('login.signing_in') : t('styleguide.sign_in') }}
          </button>

          <p class="flex justify-center gap-1.5 text-sm">
            <span class="text-[#475569]">{{ t('login.don_t_have_an_account') }}</span>
            <RouterLink :href="appUrl('/register')" class="font-semibold text-[#1b7f4b] hover:underline">
              {{ t('login.register') }}
            </RouterLink>
          </p>
        </div>
      </form>
    </section>
  </div>
</template>
