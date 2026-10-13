<script setup lang="ts">
import { Link as RouterLink, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthBrandPanel from '@/components/shell/AuthBrandPanel.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import mailIcon from '@/assets/figma/icons/mail.svg'
import { useI18n } from '@/composables/useI18n'
import { useAppUrl } from '@/composables/useAppUrl'

const { t } = useI18n()
const appUrl = useAppUrl()

/*
 * Forgot password — Figma 279:13010 (1280x900).
 * left-brand-panel 366:17169 (580) + right-form-panel 279:13057 (700).
 *
 * Orphaned in the prototype: nothing links here, and the login screen has no
 * forgot-password wire. It is reachable from /login here because the design
 * draws that link on the sign-in card (64:45).
 */

// POST /forgot-password (PasswordResetController@sendLink) has existed since
// the routes were written; the form had no binding to it.
const form = useForm({ email: '' })

/*
 * The endpoint deliberately reports the same result whether or not the address
 * is registered, so the confirmation is a flash message rather than anything
 * derived from the response.
 */
const page = usePage()
const sent = computed(() => page.props.flash.status)

function submit() {
  form.post(appUrl('/forgot-password'), { onSuccess: () => form.reset() })
}
</script>

<template>
  <div class="flex min-h-dvh items-start">
    <AuthBrandPanel />

    <section class="flex min-h-dvh flex-1 items-center justify-center bg-surface-secondary px-5 py-8 sm:py-12">
      <form class="w-full max-w-[420px]" @submit.prevent="submit">
        <RouterLink :href="appUrl('/')" class="lg:hidden">
          <img :src="logo" alt="LW Registry" class="logo-adaptive mb-8 h-9 w-auto object-contain" />
        </RouterLink>

        <h1 class="font-serif text-3xl font-bold text-[#0f172a]">{{ t('forgot_password.forgot_password') }}</h1>
        <p class="mt-3 text-base text-[#475569]">
          {{ t('forgot_password.enter_your_email_to_receive_a_password_res') }}
        </p>

        <p
          v-if="sent"
          class="mt-6 rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
          role="status"
        >
          {{ sent }}
        </p>

        <label class="mt-8 flex flex-col gap-2">
          <span class="text-sm font-semibold text-[#0f172a]">{{ t('forgot_password.email_address') }}</span>
          <span class="flex items-center gap-2 rounded-md border border-[#e2e8f0] bg-white px-4 py-3 focus-within:border-focus">
            <img :src="mailIcon" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
            <input
              v-model="form.email"
              type="email"
              autocomplete="email"
              :placeholder="t('forgot_password.email_example_com')"
              class="min-w-0 flex-1 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
            />
          </span>
          <span v-if="form.errors.email" class="text-xs text-danger">{{ form.errors.email }}</span>
        </label>

        <button
          type="submit"
          :disabled="form.processing"
          class="mt-6 w-full rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
        >
          {{ form.processing ? t('forgot_password.sending') : t('forgot_password.email_password_reset_link') }}
        </button>

        <p class="mt-5 text-center text-sm">
          <RouterLink :href="appUrl('/login')" class="font-semibold text-[#1b7f4b] hover:underline">
            {{ t('forgot_password.back_to_sign_in') }}
          </RouterLink>
        </p>
      </form>
    </section>
  </div>
</template>
